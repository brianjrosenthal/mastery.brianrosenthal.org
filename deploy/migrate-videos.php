#!/usr/bin/env php
<?php
// Copies every video (concept videos and answer videos) still held by the
// previous storage provider (DreamHost DreamObjects) into the active one
// (Cloudflare R2), one at a time, and switches each row over once its copy is
// verified. Run it from a
// shell on the server, where there is no request time limit:
//
//   php deploy/migrate-videos.php                  # copy everything pending, keep the originals
//   php deploy/migrate-videos.php --dry-run        # list what would be copied
//   php deploy/migrate-videos.php --limit=5        # stop after 5 videos
//   php deploy/migrate-videos.php --concept=42     # just this concept's own video
//   php deploy/migrate-videos.php --delete-source  # delete each original once its copy is verified
//
// Safe to interrupt and re-run: a concept is only switched over after its copy
// exists with the right size, and copies that already exist are not repeated.
// Each video streams through a temp file in sys_get_temp_dir(), so the disk
// needs room for the largest one. Originals left behind show up as orphans on
// Admin -> Video Storage, where they can be deleted in one go.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../www/config.php';
require_once __DIR__ . '/../www/lib/VideoStorage.php';
require_once __DIR__ . '/../www/lib/VideoMigration.php';
require_once __DIR__ . '/../www/lib/ConceptManagement.php';

$options = getopt('', ['dry-run', 'delete-source', 'limit::', 'concept::', 'help']);
if (isset($options['help'])) {
    echo "Usage: php deploy/migrate-videos.php [--dry-run] [--delete-source] [--limit=N] [--concept=ID]\n";
    exit(0);
}
$dryRun = isset($options['dry-run']);
$deleteSource = isset($options['delete-source']);
$limit = isset($options['limit']) ? max(0, (int)$options['limit']) : 0;
$onlyConcept = isset($options['concept']) ? (int)$options['concept'] : 0;

$to = VideoStorage::activeProvider();
$toLabel = VideoStorage::providerLabel($to);
if (!VideoStorage::isProviderConfigured($to)) {
    fwrite(STDERR, "$toLabel is not configured in www/config.local.php.\n");
    exit(1);
}

$pending = VideoMigration::pending($to);
if ($onlyConcept > 0) {
    $pending = array_values(array_filter($pending, static fn(array $r): bool => $r['kind'] === 'concept' && $r['id'] === $onlyConcept));
}
if ($limit > 0) {
    $pending = array_slice($pending, 0, $limit);
}
if ($pending === []) {
    echo "Nothing to migrate: every video is already in $toLabel.\n";
    exit(0);
}

$totalBytes = array_sum(array_column($pending, 'video_size_bytes'));
printf("%d video(s), %s, to copy into %s%s.\n\n", count($pending), VideoStorage::humanBytes((int)$totalBytes), $toLabel, $dryRun ? ' (dry run)' : '');

$done = 0;
$failed = 0;
foreach ($pending as $i => $row) {
    printf("[%d/%d] %s #%d \"%s\" — %s from %s\n", $i + 1, count($pending), $row['kind'], $row['id'], $row['title'],
        VideoStorage::humanBytes($row['video_size_bytes']), VideoStorage::providerLabel($row['video_storage']));
    if ($dryRun) {
        continue;
    }
    $started = microtime(true);
    try {
        $result = VideoMigration::migratePending(null, $row, $to, null, $deleteSource);
        $seconds = microtime(true) - $started;
        printf("        %s in %.0fs%s%s\n",
            $result['skipped'] ? 'already there' : ($result['copied'] ? 'copied and verified' : 'copy already existed, verified'),
            $seconds,
            $result['source_deleted'] ? ', original deleted' : '',
            $result['warning'] !== null ? "\n        WARNING: " . $result['warning'] : '');
        $done++;
    } catch (Throwable $e) {
        $failed++;
        printf("        FAILED: %s\n", $e->getMessage());
    }
}

if ($dryRun) {
    echo "\nDry run — nothing was copied.\n";
    exit(0);
}
$left = count(VideoMigration::pending($to));
printf("\nDone: %d migrated, %d failed, %d still pending.\n", $done, $failed, $left);
if ($left === 0 && !$deleteSource) {
    echo "Every video is now in $toLabel. The originals are still in their old bucket: Admin -> Video Storage -> Delete orphans removes them.\n";
}
exit($failed === 0 ? 0 : 1);
