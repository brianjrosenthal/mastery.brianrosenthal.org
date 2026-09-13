<?php
// AJAX (POST, JSON): hands the browser a presigned URL to PUT one video
// straight into DreamObjects for a concept the caller may edit. The secret
// key never leaves the server; the URL authorizes exactly one object key for
// VideoStorage::UPLOAD_URL_TTL seconds.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
require_once __DIR__ . '/../lib/ActivityLog.php';
Application::init();

header('Content-Type: application/json');

function presign_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    presign_fail('POST required.', 405);
}
if (!current_user()) {
    presign_fail('Please sign in again.', 401);
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    presign_fail('Your session expired. Reload the page and try again.', 403);
}
if (!VideoStorage::isConfigured()) {
    presign_fail('Video storage is not configured.', 503);
}

$conceptId = (int)($_POST['concept_id'] ?? 0);
$contentType = VideoStorage::normalizeContentType((string)($_POST['content_type'] ?? ''));
$size = (int)($_POST['size'] ?? 0);

$ctx = UserContext::getLoggedInUserContext();
$ownerId = ConceptManagement::ownerUserIdOf($conceptId);
if ($ownerId === null) {
    presign_fail('Concept not found.', 404);
}
if (!ContentAccess::canEdit($ctx, $ownerId)) {
    presign_fail('You can only add videos to your own concepts.', 403);
}
if (VideoStorage::extensionFor($contentType) === null) {
    presign_fail('Unsupported video type "' . $contentType . '". Please use an MP4, MOV or WebM file.');
}
if ($size <= 0) {
    presign_fail('The video file is empty.');
}
if ($size > VideoStorage::maxBytes()) {
    presign_fail('That video is larger than the ' . VideoStorage::humanBytes(VideoStorage::maxBytes()) . ' limit.', 413);
}

try {
    $key = VideoStorage::newObjectKeyFor($ownerId, $conceptId, $contentType);
    $upload = VideoStorage::presignUploadFor($key, $contentType);
    ActivityLog::log($ctx, 'concept.video_presign', ['concept_id' => $conceptId, 'object_key' => $key, 'size_bytes' => $size]);
    echo json_encode(['ok' => true, 'key' => $key, 'url' => $upload['url'], 'headers' => $upload['headers'], 'expires_in' => $upload['expires_in']]);
} catch (Throwable $e) {
    presign_fail('Could not prepare the upload: ' . $e->getMessage(), 500);
}
