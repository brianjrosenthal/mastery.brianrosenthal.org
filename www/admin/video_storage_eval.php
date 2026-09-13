<?php
// Admin: Video Storage actions (POST from admin/video_storage.php):
//   create_bucket   — create the configured bucket if missing.
//   apply_cors      — replace the bucket's CORS rule with the current site origins.
//   delete_orphans  — delete objects no concept references.
//   test_upload     — run the browser's presigned-PUT flow from the server
//                     (curl) and report the raw storage response, to diagnose
//                     upload failures.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
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

if (!VideoStorage::isConfigured()) {
    header('Location: ' . $returnTo . '?err=' . urlencode('Video storage is not configured.'));
    exit;
}

$ctx = UserContext::getLoggedInUserContext();
$action = (string)($_POST['action'] ?? '');
$client = VideoStorage::storage();
$bucket = VideoStorage::bucket();

try {
    switch ($action) {
        case 'create_bucket':
            $created = $client->createBucketIfMissing($bucket);
            ActivityLog::log($ctx, 'video_storage.create_bucket', ['bucket' => $bucket, 'created' => $created]);
            $msg = $created ? 'Bucket "' . $bucket . '" created. Now apply the CORS rule.' : 'The bucket already existed.';
            break;

        case 'apply_cors':
            $origins = VideoStorage::corsOrigins(SiteResolver::mainHost(), SiteManagement::listDomains());
            $client->putBucketCors($bucket, $origins);
            ActivityLog::log($ctx, 'video_storage.apply_cors', ['bucket' => $bucket, 'origins' => $origins]);
            $msg = 'CORS rule applied for: ' . implode(', ', $origins);
            break;

        case 'delete_orphans':
            $inBucket = array_column($client->listObjects($bucket), 'key');
            $orphans = array_values(array_diff($inBucket, ConceptManagement::listVideoObjectKeys()));
            $client->deleteObjects($bucket, $orphans);
            ActivityLog::log($ctx, 'video_storage.delete_orphans', ['bucket' => $bucket, 'deleted' => count($orphans)]);
            $msg = count($orphans) . ' orphaned object(s) deleted.';
            break;

        case 'test_upload':
            $msg = VideoStorage::describeTestUpload();
            ActivityLog::log($ctx, 'video_storage.test_upload', ['bucket' => $bucket, 'result' => $msg]);
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }
    header('Location: ' . $returnTo . '?msg=' . urlencode($msg));
} catch (Throwable $e) {
    header('Location: ' . $returnTo . '?err=' . urlencode($e->getMessage()));
}
exit;
