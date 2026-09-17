<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/VideoStorage.php';
require_once __DIR__ . '/ConceptManagement.php';
require_once __DIR__ . '/ActivityLog.php';

/**
 * Moves concept videos between storage providers — in practice from DreamHost
 * DreamObjects to Cloudflare R2. One concept at a time: copy the object under
 * the SAME key into the destination bucket, confirm the copy is there with the
 * same size, then flip concepts.video_storage so playback and deletion address
 * the new bucket. The source object is left in place unless asked otherwise,
 * so a half-finished migration is always safe: every row still points at an
 * object that exists.
 *
 * Bytes stream through this server via a temp file (presigned GET from the
 * source, presigned PUT to the destination), never through PHP memory, so a
 * 2 GB video needs 2 GB of disk in sys_get_temp_dir() and nothing else.
 * Driven by deploy/migrate-videos.php (CLI, the reliable path for many or
 * large videos) and by the "Migrate next video" button on Admin -> Video
 * Storage. The transfer step is injectable so tests can prove the bookkeeping
 * without a network.
 */
final class VideoMigration {

    /** Presigned URLs for the copy stay valid this long; the transfer only
     *  needs them valid when each request starts. */
    private const COPY_URL_TTL = 3600;

    /** Abort a transfer that moves under 1 KB/s for this many seconds. */
    private const STALL_SECONDS = 120;

    /**
     * Concept rows whose video is held somewhere other than $to.
     * @return array<int,array{id:int,title:string,video_object_key:string,video_storage:string,video_size_bytes:int,video_content_type:string}>
     */
    public static function pending(?string $to = null): array {
        $to = VideoStorage::assertProvider($to ?? VideoStorage::activeProvider());
        $st = pdo()->prepare(
            'SELECT id, title, video_object_key, video_storage, video_size_bytes, video_content_type
             FROM concepts
             WHERE video_object_key IS NOT NULL AND (video_storage IS NULL OR video_storage <> ?)
             ORDER BY id'
        );
        $st->execute([$to]);
        $out = [];
        foreach ($st->fetchAll() as $row) {
            $out[] = [
                'id'                 => (int)$row['id'],
                'title'              => (string)$row['title'],
                'video_object_key'   => (string)$row['video_object_key'],
                'video_storage'      => VideoStorage::providerOf($row),
                'video_size_bytes'   => (int)$row['video_size_bytes'],
                'video_content_type' => (string)$row['video_content_type'],
            ];
        }
        return $out;
    }

    /** How many videos are held per provider, e.g. ['r2' => 12, 'dreamobjects' => 3]. */
    public static function countsByProvider(): array {
        $counts = array_fill_keys(array_keys(VideoStorage::PROVIDERS), 0);
        $rows = pdo()->query('SELECT video_storage, COUNT(*) AS n FROM concepts WHERE video_object_key IS NOT NULL GROUP BY video_storage')->fetchAll();
        foreach ($rows as $row) {
            $counts[VideoStorage::providerOf($row)] += (int)$row['n'];
        }
        return $counts;
    }

    /**
     * Copy one concept's video into $to (the active provider by default) and
     * record it. Idempotent: a concept already at $to is reported as skipped,
     * and a copy that already exists with the right size (e.g. made with
     * rclone) is not re-transferred.
     *
     * @param callable|null $transfer fn(string $key, string $from, string $to, string $contentType): void —
     *                                 defaults to transferObject(). Tests inject a fake.
     * @return array{concept_id:int,key:string,from:string,to:string,size:int,skipped:bool,copied:bool,source_deleted:bool,warning:?string}
     * @throws RuntimeException when the source object is missing, the copy cannot be verified,
     *                          or the concept's video changed during the transfer.
     */
    public static function migrateConcept(?UserContext $ctx, int $conceptId, ?string $to = null, ?callable $transfer = null, bool $deleteSource = false): array {
        $to = VideoStorage::assertProvider($to ?? VideoStorage::activeProvider());
        $concept = ConceptManagement::findById($conceptId);
        if (!$concept) {
            throw new RuntimeException('Concept not found.');
        }
        $key = (string)($concept['video_object_key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('Concept #' . $conceptId . ' has no video.');
        }
        $from = VideoStorage::providerOf($concept);
        $result = ['concept_id' => $conceptId, 'key' => $key, 'from' => $from, 'to' => $to, 'size' => 0,
                   'skipped' => false, 'copied' => false, 'source_deleted' => false, 'warning' => null];
        if ($from === $to) {
            $result['skipped'] = true;
            return $result;
        }
        $source = VideoStorage::storage($from)->headObject(VideoStorage::bucket($from), $key);
        if ($source === null) {
            throw new RuntimeException('The video for concept #' . $conceptId . ' is missing from ' . VideoStorage::providerLabel($from) . ' (' . $key . ').');
        }
        $size = (int)$source['size'];
        $contentType = (string)($concept['video_content_type'] ?: $source['content_type']);
        $result['size'] = $size;

        $destination = VideoStorage::storage($to);
        $existing = $destination->headObject(VideoStorage::bucket($to), $key);
        if ($existing === null || (int)$existing['size'] !== $size) {
            ($transfer ?? [self::class, 'transferObject'])($key, $from, $to, $contentType);
            $result['copied'] = true;
            $existing = $destination->headObject(VideoStorage::bucket($to), $key);
        }
        if ($existing === null) {
            throw new RuntimeException('The copy of ' . $key . ' did not appear in ' . VideoStorage::providerLabel($to) . '.');
        }
        if ((int)$existing['size'] !== $size) {
            throw new RuntimeException('The copy of ' . $key . ' in ' . VideoStorage::providerLabel($to) . ' is '
                . (int)$existing['size'] . ' bytes but the original is ' . $size . '. Not recording it.');
        }

        // Only flip the row if it still points at the object we copied: a long
        // transfer could overlap the owner replacing the video.
        $st = pdo()->prepare('UPDATE concepts SET video_storage = ? WHERE id = ? AND video_object_key = ?');
        $st->execute([$to, $conceptId, $key]);
        if ($st->rowCount() !== 1) {
            throw new RuntimeException('The video for concept #' . $conceptId . ' changed during the copy; run the migration again.');
        }
        ActivityLog::log($ctx, 'concept.video_migrate', ['concept_id' => $conceptId, 'object_key' => $key, 'from' => $from, 'to' => $to, 'size_bytes' => $size]);

        if ($deleteSource) {
            try {
                VideoStorage::deleteObject($key, $from);
                $result['source_deleted'] = true;
            } catch (\Throwable $e) {
                $result['warning'] = 'Copied and recorded, but deleting the original from ' . VideoStorage::providerLabel($from) . ' failed: ' . $e->getMessage();
                ActivityLog::log($ctx, 'concept.video_delete_failed', ['concept_id' => $conceptId, 'object_key' => $key, 'provider' => $from, 'error' => $e->getMessage()]);
            }
        }
        return $result;
    }

    /**
     * The default transfer: stream the object from $from to $to through a temp
     * file with curl, using presigned URLs so no request body is ever signed
     * or held in memory.
     * @throws RuntimeException on any transport or HTTP failure.
     */
    public static function transferObject(string $key, string $from, string $to, string $contentType): void {
        $tmp = tempnam(sys_get_temp_dir(), 'mastery-video-');
        if ($tmp === false) {
            throw new RuntimeException('Could not create a temporary file in ' . sys_get_temp_dir() . '.');
        }
        try {
            // Download.
            $getUrl = VideoStorage::storage($from)->presignedGetUrl(VideoStorage::bucket($from), $key, time(), self::COPY_URL_TTL);
            $fh = fopen($tmp, 'wb');
            if ($fh === false) {
                throw new RuntimeException('Could not open the temporary file for writing.');
            }
            $ch = curl_init($getUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok === false) {
                throw new RuntimeException('Download from ' . VideoStorage::providerLabel($from) . ' failed: ' . $error);
            }
            if ($status !== 200) {
                throw new RuntimeException('Download from ' . VideoStorage::providerLabel($from) . ' returned HTTP ' . $status
                    . ': ' . substr(trim(strip_tags((string)file_get_contents($tmp, false, null, 0, 2000))), 0, 300));
            }
            $size = filesize($tmp);
            if ($size === false || $size <= 0) {
                throw new RuntimeException('Downloaded file is empty.');
            }

            // Upload — the same presigned PUT the browser uses.
            $putUrl = VideoStorage::storage($to)->presignedPutUrl(VideoStorage::bucket($to), $key, time(), self::COPY_URL_TTL);
            $fh = fopen($tmp, 'rb');
            if ($fh === false) {
                throw new RuntimeException('Could not open the temporary file for reading.');
            }
            $ch = curl_init($putUrl);
            curl_setopt_array($ch, [
                CURLOPT_UPLOAD         => true,
                CURLOPT_INFILE         => $fh,
                CURLOPT_INFILESIZE     => $size,
                CURLOPT_HTTPHEADER     => ['Content-Type: ' . $contentType, 'Expect:'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_LOW_SPEED_LIMIT => 1024,
                CURLOPT_LOW_SPEED_TIME => self::STALL_SECONDS,
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($body === false) {
                throw new RuntimeException('Upload to ' . VideoStorage::providerLabel($to) . ' failed: ' . $error);
            }
            if ($status < 200 || $status >= 300) {
                throw new RuntimeException('Upload to ' . VideoStorage::providerLabel($to) . ' returned HTTP ' . $status
                    . ': ' . substr(trim(strip_tags((string)$body)), 0, 300));
            }
        } finally {
            @unlink($tmp);
        }
    }
}
