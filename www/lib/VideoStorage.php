<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/S3Client.php';

/**
 * App-level policy for concept videos in object storage: which provider holds
 * them, where objects live, what may be uploaded, how the browser gets
 * permission to upload, and how the public site plays them back.
 *
 * Two providers exist. New uploads go to the ACTIVE provider
 * (VIDEO_STORAGE_PROVIDER, normally 'r2'); each concept row records which
 * provider holds its video in concepts.video_storage, so playback and deletion
 * always address the right bucket while older videos are still in
 * DreamObjects. deploy/migrate-videos.php (or Admin -> Video Storage) moves
 * them across; once nothing is left in DreamObjects its credentials can go.
 *
 * Videos never pass through this server. concept_edit.php asks
 * video_presign_eval.php for a presigned PUT URL (signed here with the secret
 * key, which never leaves the server), the browser PUTs the file straight to
 * the bucket, and concept_video_attach_eval.php then calls
 * verifyUploadedObject() before the key is recorded on the concept.
 *
 * Objects stay PRIVATE (R2 objects are private by default; DreamObjects
 * rejects canned ACLs such as public-read) and are played back through
 * presigned GET URLs. The signature timestamp is quantized to a window so
 * every visitor in that window gets a byte-identical URL and the browser can
 * cache the video; the TTL is always at least twice the window so a URL
 * minted at the start of a window outlives its end.
 */
final class VideoStorage {

    /** How long a presigned upload URL stays valid. Long uploads only need the
     *  URL to be valid when the PUT *starts*. */
    public const UPLOAD_URL_TTL = 900;

    /**
     * The storage providers this app knows. Each reads its own config
     * constants, {prefix}_ENDPOINT / _REGION / _ACCESS_KEY / _SECRET_KEY /
     * _VIDEO_BUCKET; the region falls back to the default given here.
     */
    public const PROVIDERS = [
        'r2'           => ['label' => 'Cloudflare R2',          'prefix' => 'R2',           'region' => 'auto'],
        'dreamobjects' => ['label' => 'DreamHost DreamObjects', 'prefix' => 'DREAMOBJECTS', 'region' => 'us-east-1'],
    ];

    /** Where uploads go when VIDEO_STORAGE_PROVIDER is not set. */
    public const DEFAULT_PROVIDER = 'r2';

    /** Provider assumed for a concept row whose video_storage is NULL but
     *  which has a video: it was uploaded before the column existed. */
    public const LEGACY_PROVIDER = 'dreamobjects';

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

    /** @var array<string,S3Client> one client per provider */
    private static array $clients = [];

    // -------------------------------------------------------------------------
    // Providers
    // -------------------------------------------------------------------------

    /** @return string[] provider ids, active one first */
    public static function providers(): array {
        $ids = array_keys(self::PROVIDERS);
        usort($ids, static fn(string $a, string $b): int => ($a === self::activeProvider() ? 0 : 1) <=> ($b === self::activeProvider() ? 0 : 1));
        return $ids;
    }

    /** The provider new uploads go to. */
    public static function activeProvider(): string {
        $p = defined('VIDEO_STORAGE_PROVIDER') ? strtolower(trim((string)VIDEO_STORAGE_PROVIDER)) : '';
        return isset(self::PROVIDERS[$p]) ? $p : self::DEFAULT_PROVIDER;
    }

    /** Validate a provider id, returning it. */
    public static function assertProvider(string $provider): string {
        if (!isset(self::PROVIDERS[$provider])) {
            throw new InvalidArgumentException('Unknown video storage provider "' . $provider . '".');
        }
        return $provider;
    }

    public static function providerLabel(string $provider): string {
        return self::PROVIDERS[self::assertProvider($provider)]['label'];
    }

    /** The provider holding a concept row's video (see LEGACY_PROVIDER). */
    public static function providerOf(array $concept): string {
        $p = (string)($concept['video_storage'] ?? '');
        return isset(self::PROVIDERS[$p]) ? $p : self::LEGACY_PROVIDER;
    }

    private static function config(string $provider, string $suffix, string $default = ''): string {
        $name = self::PROVIDERS[self::assertProvider($provider)]['prefix'] . '_' . $suffix;
        return defined($name) ? trim((string)constant($name)) : $default;
    }

    /**
     * The provider's S3 endpoint with no bucket in it. Cloudflare's bucket
     * settings page shows the "S3 API" value with the bucket appended
     * (https://{account}.r2.cloudflarestorage.com/mastery-videos); pasting
     * that as R2_ENDPOINT is fine — the bucket suffix is stripped here.
     */
    public static function endpoint(string $provider): string {
        $endpoint = rtrim(self::config($provider, 'ENDPOINT'), '/');
        $bucket = self::bucket($provider);
        if ($bucket !== '' && str_ends_with($endpoint, '/' . $bucket)) {
            $endpoint = substr($endpoint, 0, -strlen('/' . $bucket));
        }
        return $endpoint;
    }

    public static function region(string $provider): string {
        return self::config($provider, 'REGION', self::PROVIDERS[self::assertProvider($provider)]['region']);
    }

    /** Bucket holding concept videos at a provider (the active one by default). */
    public static function bucket(?string $provider = null): string {
        return self::config($provider ?? self::activeProvider(), 'VIDEO_BUCKET');
    }

    /** Whether a provider has endpoint, keys and bucket set. */
    public static function isProviderConfigured(string $provider): bool {
        return self::endpoint($provider) !== ''
            && self::config($provider, 'ACCESS_KEY') !== ''
            && self::config($provider, 'SECRET_KEY') !== ''
            && self::bucket($provider) !== '';
    }

    /**
     * Whether uploads can work: the active provider is configured. Video
     * upload UI hides itself when this is false, so an environment with no
     * storage credentials degrades quietly instead of erroring on every page.
     */
    public static function isConfigured(): bool {
        return self::isProviderConfigured(self::activeProvider());
    }

    /**
     * The storage client for a provider (the active one by default), with an
     * injection seam for tests: pass $inject to replace that provider's client.
     */
    public static function storage(?string $provider = null, ?S3Client $inject = null): S3Client {
        $provider = self::assertProvider($provider ?? self::activeProvider());
        if ($inject !== null) {
            self::$clients[$provider] = $inject;
        }
        if (!isset(self::$clients[$provider])) {
            self::$clients[$provider] = new S3Client(
                self::endpoint($provider),
                self::region($provider),
                self::config($provider, 'ACCESS_KEY'),
                self::config($provider, 'SECRET_KEY')
            );
        }
        return self::$clients[$provider];
    }

    public static function resetStorage(): void {
        self::$clients = [];
    }

    // -------------------------------------------------------------------------
    // Uploads
    // -------------------------------------------------------------------------

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
     * Everything the browser needs to PUT one object into the active provider:
     * the presigned URL and the headers it must send. Only the host is signed:
     * no ACL header, because DreamObjects rejects canned ACLs ("Unsupported
     * value for canned acl") and R2 objects are private regardless.
     * @return array{provider:string,url:string,headers:array<string,string>,expires_in:int}
     */
    public static function presignUploadFor(string $key, string $contentType): array {
        $type = self::normalizeContentType($contentType);
        if (self::extensionFor($type) === null) {
            throw new InvalidArgumentException('Unsupported video type "' . $contentType . '".');
        }
        $provider = self::activeProvider();
        $url = self::storage($provider)->presignedPutUrl(self::bucket($provider), $key, time(), self::UPLOAD_URL_TTL);
        return [
            'provider'   => $provider,
            'url'        => $url,
            'headers'    => ['Content-Type' => $type],
            'expires_in' => self::UPLOAD_URL_TTL,
        ];
    }

    /**
     * Confirm an object the browser claims to have uploaded to the active
     * provider: it must exist, be a supported type and be within the size
     * cap. Oversize or wrong-type objects are deleted so a tampered client
     * cannot park junk in the bucket.
     * @return array{content_type:string,size:int}
     */
    public static function verifyUploadedObject(string $key): array {
        $provider = self::activeProvider();
        $head = self::storage($provider)->headObject(self::bucket($provider), $key);
        if ($head === null) {
            throw new RuntimeException('The upload did not reach storage. Please try again.');
        }
        $type = self::normalizeContentType((string)$head['content_type']);
        if (self::extensionFor($type) === null) {
            self::deleteObject($key, $provider);
            throw new RuntimeException('That file is not a supported video type.');
        }
        if ((int)$head['size'] > self::maxBytes()) {
            self::deleteObject($key, $provider);
            throw new RuntimeException('That video is larger than the ' . self::humanBytes(self::maxBytes()) . ' limit.');
        }
        if ((int)$head['size'] <= 0) {
            self::deleteObject($key, $provider);
            throw new RuntimeException('The uploaded video is empty.');
        }
        return ['content_type' => $type, 'size' => (int)$head['size']];
    }

    public static function deleteObject(string $key, string $provider): void {
        if ($key === '') {
            return;
        }
        self::storage($provider)->deleteObjects(self::bucket($provider), [$key]);
    }

    // -------------------------------------------------------------------------
    // Playback
    // -------------------------------------------------------------------------

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
     * The URL a <video> tag plays the object from: a presigned GET against the
     * provider that holds it, identical for every viewer within the current
     * window (cacheable), valid for the TTL. Pure local computation — no
     * storage round trip per page view.
     */
    public static function playbackUrlFor(string $key, string $provider, ?int $now = null): string {
        return self::storage($provider)->presignedGetUrl(self::bucket($provider), $key, self::urlIssuedAt($now), self::urlTtlSeconds());
    }

    /** playbackUrlFor() for a concept row (its key and provider). */
    public static function playbackUrlForConcept(array $concept, ?int $now = null): string {
        return self::playbackUrlFor((string)$concept['video_object_key'], self::providerOf($concept), $now);
    }

    // -------------------------------------------------------------------------
    // Setup / diagnostics
    // -------------------------------------------------------------------------

    /**
     * Origins the bucket's CORS rule must allow so browsers on every site can
     * PUT uploads: the main host, each site's own hostname (subdomain and
     * custom domain, see SiteManagement::listPublicHosts()), and local
     * development.
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

    /** The origins the active bucket's CORS rule should allow right now. */
    public static function wantedCorsOrigins(): array {
        require_once __DIR__ . '/SiteResolver.php';
        require_once __DIR__ . '/SiteManagement.php';
        return self::corsOrigins(SiteResolver::mainHost(), SiteManagement::listPublicHosts());
    }

    /**
     * Re-apply the CORS rule to the active bucket after a site gained a new
     * hostname (created, or its slug/domain changed), so uploads from that
     * origin work without an admin visiting Video Storage. Best effort: a
     * missing configuration or a storage error is swallowed (the admin page
     * shows the rule as missing and offers to apply it).
     */
    public static function refreshCorsBestEffort(?UserContext $ctx): void {
        if (!self::isConfigured()) {
            return;
        }
        try {
            $origins = self::wantedCorsOrigins();
            self::storage()->putBucketCors(self::bucket(), $origins);
            require_once __DIR__ . '/ActivityLog.php';
            ActivityLog::log($ctx, 'video_storage.apply_cors', ['provider' => self::activeProvider(), 'bucket' => self::bucket(), 'origins' => $origins, 'automatic' => true]);
        } catch (\Throwable $e) {
            error_log('CORS refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * Diagnostic for Admin -> Video Storage: perform the presigned PUT exactly
     * as the browser does against the active provider (same URL, same
     * headers, a tiny body), then HEAD, play back and delete the object.
     * Returns a one-line human-readable result including the raw storage
     * error when the PUT fails. Never throws.
     */
    public static function describeTestUpload(): string {
        $provider = self::activeProvider();
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
            $head = self::storage($provider)->headObject(self::bucket($provider), $key);
        } catch (\Throwable $e) {
            return 'Presigned PUT succeeded (HTTP ' . $status . ') but verifying failed: ' . $e->getMessage();
        }

        // Playback: an unauthenticated GET of the presigned playback URL.
        $ch = curl_init(self::playbackUrlFor($key, $provider));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30]);
        $played = curl_exec($ch);
        $playStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        try {
            self::deleteObject($key, $provider);
        } catch (\Throwable $e) {
            return 'Upload and playback worked but deleting the test object failed: ' . $e->getMessage();
        }
        if ($played !== 'mastery test upload' || $playStatus !== 200) {
            return 'Upload worked (HTTP ' . $status . ') but playback via a presigned GET returned HTTP ' . $playStatus
                 . ($played === false || $played === '' ? '' : ': ' . substr(trim(strip_tags((string)$played)), 0, 200)) . '.';
        }
        return 'Test upload to ' . self::providerLabel($provider) . ' succeeded: PUT HTTP ' . $status . ', object seen with '
             . (int)($head['size'] ?? 0) . ' bytes and type "' . (string)($head['content_type'] ?? '') . '", playback GET HTTP 200, then deleted.'
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
