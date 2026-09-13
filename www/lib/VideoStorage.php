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
 * Objects are uploaded public-read under unguessable keys
 * (videos/{user_id}/{concept_id}/{32 hex}.{ext}), so the public site can use
 * plain cacheable <video src> URLs while drafts stay undiscoverable.
 */
final class VideoStorage {

    /** How long a presigned upload URL stays valid. Long uploads only need the
     *  URL to be valid when the PUT *starts*. */
    public const UPLOAD_URL_TTL = 900;

    /** Default cap when VIDEO_MAX_BYTES is not configured: 2 GB. */
    private const DEFAULT_MAX_BYTES = 2147483648;

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
     * headers it must send (the ACL header is part of the signature).
     * @return array{url:string,headers:array<string,string>,expires_in:int}
     */
    public static function presignUploadFor(string $key, string $contentType): array {
        $type = self::normalizeContentType($contentType);
        if (self::extensionFor($type) === null) {
            throw new InvalidArgumentException('Unsupported video type "' . $contentType . '".');
        }
        $headers = ['x-amz-acl' => 'public-read'];
        $url = self::storage()->presignedPutUrl(self::bucket(), $key, time(), self::UPLOAD_URL_TTL, $headers);
        return [
            'url'        => $url,
            'headers'    => $headers + ['Content-Type' => $type],
            'expires_in' => self::UPLOAD_URL_TTL,
        ];
    }

    /** Plain URL the public site plays the video from. */
    public static function publicUrlFor(string $key): string {
        return self::storage()->publicUrl(self::bucket(), $key);
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
            self::deleteObject($key);
        } catch (\Throwable $e) {
            return 'Presigned PUT succeeded (HTTP ' . $status . ') but verifying/deleting failed: ' . $e->getMessage();
        }
        return 'Test upload succeeded: PUT HTTP ' . $status . ', object seen with ' . (int)($head['size'] ?? 0)
             . ' bytes and type "' . (string)($head['content_type'] ?? '') . '", then deleted. Browser uploads should work if the CORS rule includes the site origin.';
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
