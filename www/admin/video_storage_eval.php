<?php
// Admin: Video Storage actions (POST from admin/video_storage.php). Every
// action names a provider ('r2' or 'dreamobjects'):
//   create_bucket   — create the provider's configured bucket if missing.
//   apply_cors      — replace the bucket's CORS rule with the current site origins.
//   delete_orphans  — delete objects in that bucket no concept (held there) references.
//   test_upload     — run the browser's presigned-PUT flow from the server
//                     (curl) against the active provider and report the raw
//                     storage response, to diagnose upload failures.
//   migrate_next    — copy the next concept video still held elsewhere into
//                     the active provider (streams through this server).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
require_once __DIR__ . '/../lib/VideoMigration.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();
require_admin();

$returnTo = '/admin/video_storage.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $returnTo);
    exit;
}
require_csrf();

$ctx = UserContext::getLoggedInUserContext();
$action = (string)($_POST['action'] ?? '');

try {
    $provider = VideoStorage::assertProvider((string)($_POST['provider'] ?? VideoStorage::activeProvider()));
    if (!VideoStorage::isProviderConfigured($provider)) {
        throw new RuntimeException(VideoStorage::providerLabel($provider) . ' is not configured.');
    }
    $client = VideoStorage::storage($provider);
    $bucket = VideoStorage::bucket($provider);
    $label = VideoStorage::providerLabel($provider);

    switch ($action) {
        case 'create_bucket':
            $created = $client->createBucketIfMissing($bucket);
            ActivityLog::log($ctx, 'video_storage.create_bucket', ['provider' => $provider, 'bucket' => $bucket, 'created' => $created]);
            $msg = $created ? $label . ' bucket "' . $bucket . '" created. Now apply the CORS rule.' : 'The ' . $label . ' bucket already existed.';
            break;

        case 'apply_cors':
            $origins = VideoStorage::corsOrigins(SiteResolver::mainHost(), SiteManagement::listDomains());
            $client->putBucketCors($bucket, $origins);
            ActivityLog::log($ctx, 'video_storage.apply_cors', ['provider' => $provider, 'bucket' => $bucket, 'origins' => $origins]);
            $msg = 'CORS rule applied to ' . $label . ' for: ' . implode(', ', $origins);
            break;

        case 'delete_orphans':
            $inBucket = array_column($client->listObjects($bucket), 'key');
            $orphans = array_values(array_diff($inBucket, ConceptManagement::listVideoObjectKeys($provider)));
            $client->deleteObjects($bucket, $orphans);
            ActivityLog::log($ctx, 'video_storage.delete_orphans', ['provider' => $provider, 'bucket' => $bucket, 'deleted' => count($orphans)]);
            $msg = count($orphans) . ' orphaned object(s) deleted from ' . $label . '.';
            break;

        case 'test_upload':
            $msg = VideoStorage::describeTestUpload();
            ActivityLog::log($ctx, 'video_storage.test_upload', ['provider' => VideoStorage::activeProvider(), 'bucket' => VideoStorage::bucket(), 'result' => $msg]);
            break;

        case 'migrate_next':
            // A large video can take minutes to stream through; do not let the
            // request time limit or an impatient browser abandon it half-way.
            set_time_limit(0);
            ignore_user_abort(true);
            $pending = VideoMigration::pending();
            if ($pending === []) {
                $msg = 'Nothing to migrate: every video is already in ' . VideoStorage::providerLabel(VideoStorage::activeProvider()) . '.';
                break;
            }
            $result = VideoMigration::migrateConcept($ctx, $pending[0]['id']);
            $msg = 'Migrated "' . $pending[0]['title'] . '" (' . VideoStorage::humanBytes($result['size']) . ') from '
                 . VideoStorage::providerLabel($result['from']) . ' to ' . VideoStorage::providerLabel($result['to'])
                 . ($result['copied'] ? '.' : ' (the copy was already there).')
                 . ' ' . (count($pending) - 1) . ' left.';
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
    header('Location: ' . $returnTo . '?msg=' . urlencode($msg));
} catch (Throwable $e) {
    header('Location: ' . $returnTo . '?err=' . urlencode($e->getMessage()));
}
exit;
