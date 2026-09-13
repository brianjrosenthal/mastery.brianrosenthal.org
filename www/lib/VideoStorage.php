<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/DreamObjects.php';

/**
 * App-level policy for concept videos in DreamObjects: where objects live,
 * what may be uploaded, how the browser gets permission to upload, and how the
 * public site plays them back.
 *
 * Videos never pass through this server. concept_edit.php asks
 * video_presign_eval.php for a presigned PUT URL (signed here with the secret
 * key, which never leaves the server), the browser PUTs the file straight to
 * the bucket, and concept_video_attach_eval.php then calls
 * verifyUploadedObject() before the key is recorded on the concept.
 *
 * Objects stay PRIVATE (DreamObjects rejects canned ACLs such as public-read)
 * and are played back through presigned GET URLs. The signature timestamp is
 * quantized to a window so every visitor in that window gets a byte-identical
 * URL and the browser can cache the video; the TTL is always at least twice
 * the window so a URL minted at the start of a window outlives its end.
 */
final class VideoStorage {

    /** How long a presigned upload URL stays valid. Long uploads only need the
     *  URL to be valid when the PUT *starts*. */
    public const UPLOAD_URL_TTL = 900;

    /** Default cap when VIDEO_MAX_BYTES is not configured: 2 GB. */
    private const DEFAULT_MAX_BYTES = 2147483648;

    /** Playback URL quantization window (6 h) and lifetime (24 h) defaults;
     *  override with VIDEO_URL_WINDOW_SECONDS / VIDEO_URL_TTL_SECONDS. */
    private const DEFAULT_URL_WINDOW = 21600;
    private const DEFAULT_URL_TTL = 86400;
    private const MAX_PRESIGN_TTL = 604800;

    /** MIME type => object key extension. Browsers record webm (Chrome/Firefox)
     *  or mp4 (Safari); phones upload mp4/mov. */
    private const CONTENT_TYPES = [
        'video/mp4'       => 'mp4',
        'video/webm'      => 'webm',
        'video/quicktime' => 'mov',
    ];

    private static ?DreamObjects $storage = null;

    /** The storage client, with an injection seam for tests. */
    public static function storage(?DreamObjects $inject = null): DreamObjects {
        if ($inject !== null) {
            self::$storage = $inject;
        }
        if (self::$storage === null) {
            self::$storage = new DreamObjects();
        }
        return self::$storage;
    }

    public static function resetStorage(): void {
        self::$storage = null;
    }

    public static function isConfigured(): bool {
        return DreamObjects::isConfigured();
    }

    public static function bucket(): string {
        return DreamObjects::videoBucket();
    }

    public static function maxBytes(): int {
        $v = defined('VIDEO_MAX_BYTES') ? (int)VIDEO_MAX_BYTES : self::DEFAULT_MAX_BYTES;
        return max(1024 * 1024, $v);
    }

    /** @return string[] accepted MIME types */
    public static function allowedContentTypes(): array {
        return array_keys(self::CONTENT_TYPES);
    }

    /** "video/webm;codecs=vp9,opus" -> "video/webm" (lowercase, no parameters). */
    public static function normalizeContentType(string $contentType): string {
        $base = strtolower(trim(explode(';', $contentType, 2)[0]));
        if ($base === 'video/x-m4v') {
            $base = 'video/mp4';
        }
        return $base;
    }

    public static function extensionFor(string $contentType): ?string {
        return self::CONTENT_TYPES[self::normalizeContentType($contentType)] ?? null;
    }

    /**
     * The object key a new upload for this concept will use. Random so the
     * URL cannot be guessed and so replacing a video never overwrites in place
     * (browsers may still be caching the old URL).
     */
    public static function newObjectKeyFor(int $userId, int $conceptId, string $contentType): string {
        $ext = self::extensionFor($contentType);
        if ($ext === null) {
            throw new InvalidArgumentException('Unsupported video type "' . $contentType . '". Please use an MP4, WebM or MOV file.');
        }
        return 'videos/' . $userId . '/' . $conceptId . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Whether a key has the shape newObjectKeyFor() produces for this concept. */
    public static function keyBelongsToConcept(string $key, int $conceptId): bool {
        return preg_match('#^videos/\d+/' . $conceptId . '/[0-9a-f]{32}\.(mp4|webm|mov)$#', $key) === 1;
    }

    /**
     * Everything the browser needs to PUT one object: the presigned URL and the
     * headers it must send. Only the host is signed: no ACL header, because
     * DreamObjects rejects canned ACLs ("Unsupported value for canned acl").
     * @return array{url:string,headers:array<string,string>,expires_in:int}
     */
    public static function presignUploadFor(string $key, string $contentType): array {
        $type = self::normalizeContentType($contentType);
        if (self::extensionFor($type) === null) {
            throw new InvalidArgumentException('Unsupported video type "' . $contentType . '".');
        }
        $url = self::storage()->presignedPutUrl(self::bucket(), $key, time(), self::UPLOAD_URL_TTL);
        return [
            'url'        => $url,
            'headers'    => ['Content-Type' => $type],
            'expires_in' => self::UPLOAD_URL_TTL,
        ];
    }

    public static function urlWindowSeconds(): int {
        $w = defined('VIDEO_URL_WINDOW_SECONDS') ? (int)VIDEO_URL_WINDOW_SECONDS : self::DEFAULT_URL_WINDOW;
        return min(max(60, $w), intdiv(self::MAX_PRESIGN_TTL, 2));
    }

    public static function urlTtlSeconds(): int {
        $ttl = defined('VIDEO_URL_TTL_SECONDS') ? (int)VIDEO_URL_TTL_SECONDS : self::DEFAULT_URL_TTL;
        return min(max($ttl, self::urlWindowSeconds() * 2), self::MAX_PRESIGN_TTL);
    }

    /** The signature timestamp for playback URLs, rounded down to the window. */
    public static function urlIssuedAt(?int $now = null): int {
        $window = self::urlWindowSeconds();
        return intdiv($now ?? time(), $window) * $window;
    }

    /**
     * The URL a <video> tag plays the object from: a presigned GET, identical
     * for every viewer within the current window (cacheable), valid for the
     * TTL. Pure local computation — no storage round trip per page view.
     */
    public static function playbackUrlFor(string $key, ?int $now = null): string {
        return self::storage()->presignedGetUrl(self::bucket(), $key, self::urlIssuedAt($now), self::urlTtlSeconds());
    }

    /**
     * Confirm an object the browser claims to have uploaded: it must exist,
     * be a supported type and be within the size cap. Oversize or wrong-type
     * objects are deleted so a tampered client cannot park junk in the bucket.
     * @return array{content_type:string,size:int}
     */
    public static function verifyUploadedObject(string $key): array {
        $head = self::storage()->headObject(self::bucket(), $key);
        if ($head === null) {
            throw new RuntimeException('The upload did not reach storage. Please try again.');
        }
        $type = self::normalizeContentType((string)$head['content_type']);
        if (self::extensionFor($type) === null) {
            self::deleteObject($key);
            throw new RuntimeException('That file is not a supported video type.');
        }
        if ((int)$head['size'] > self::maxBytes()) {
            self::deleteObject($key);
            throw new RuntimeException('That video is larger than the ' . self::humanBytes(self::maxBytes()) . ' limit.');
        }
        if ((int)$head['size'] <= 0) {
            self::deleteObject($key);
            throw new RuntimeException('The uploaded video is empty.');
        }
        return ['content_type' => $type, 'size' => (int)$head['size']];
    }

    public static function deleteObject(string $key): void {
        if ($key === '') {
            return;
        }
        self::storage()->deleteObjects(self::bucket(), [$key]);
    }

    /**
     * Origins the bucket's CORS rule must allow so browsers on every site can
     * PUT uploads: the main host, each custom domain, and local development.
     * @param string[] $domains
     * @return string[]
     */
    public static function corsOrigins(string $mainHost, array $domains, bool $includeLocalDev = true): array {
        $origins = [];
        foreach (array_merge([$mainHost], $domains) as $host) {
            $host = strtolower(trim((string)$host));
            if ($host === '') {
                continue;
            }
            $origins[] = 'https://' . $host;
        }
        if ($includeLocalDev) {
            $origins[] = 'http://localhost:8080';
        }
        return array_values(array_unique($origins));
    }

    /**
     * Diagnostic for Admin -> Video Storage: perform the presigned PUT exactly
     * as the browser does (same URL, same headers, a tiny body), then HEAD and
     * delete the object. Returns a one-line human-readable result including
     * the raw storage error when the PUT fails. Never throws.
     */
    public static function describeTestUpload(): string {
        $key = 'videos/0/0/' . bin2hex(random_bytes(16)) . '.mp4';
        try {
            $grant = self::presignUploadFor($key, 'video/mp4');
        } catch (\Throwable $e) {
            return 'Could not presign: ' . $e->getMessage();
        }
        $ch = curl_init($grant['url']);
        $headers = [];
        foreach ($grant['headers'] as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => 'mastery test upload',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return 'Presigned PUT failed before a response: ' . $curlError;
        }
        if ($status < 200 || $status >= 300) {
            $detail = trim(strip_tags(preg_replace('/<RequestId>.*?<\/RequestId>|<HostId>.*?<\/HostId>/s', '', (string)$body) ?? ''));
            return 'Presigned PUT returned HTTP ' . $status . ($detail !== '' ? ': ' . substr($detail, 0, 300) : '')
                 . ' (signed headers: ' . implode(', ', array_keys($grant['headers'])) . ')';
        }
        try {
            $head = self::storage()->headObject(self::bucket(), $key);
        } catch (\Throwable $e) {
            return 'Presigned PUT succeeded (HTTP ' . $status . ') but verifying failed: ' . $e->getMessage();
        }

        // Playback: an unauthenticated GET of the presigned playback URL.
        $ch = curl_init(self::playbackUrlFor($key));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
        $played = curl_exec($ch);
        $playStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        try {
            self::deleteObject($key);
        } catch (\Throwable $e) {
            return 'Upload and playback worked but deleting the test object failed: ' . $e->getMessage();
        }
        if ($played !== 'mastery test upload' || $playStatus !== 200) {
            return 'Upload worked (HTTP ' . $status . ') but playback via a presigned GET returned HTTP ' . $playStatus
                 . ($played === false || $played === '' ? '' : ': ' . substr(trim(strip_tags((string)$played)), 0, 200)) . '.';
        }
        return 'Test upload succeeded: PUT HTTP ' . $status . ', object seen with ' . (int)($head['size'] ?? 0)
             . ' bytes and type "' . (string)($head['content_type'] ?? '') . '", playback GET HTTP 200, then deleted.'
             . ' Browser uploads should work if the CORS rule includes the site origin.';
    }

    public static function humanBytes(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        $value = (float)$bytes;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }
        return ($i === 0 ? (string)(int)$value : rtrim(rtrim(number_format($value, 1), '0'), '.')) . ' ' . $units[$i];
    }
}
