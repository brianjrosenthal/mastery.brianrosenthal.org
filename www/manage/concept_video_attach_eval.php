<?php
// AJAX (POST): after the browser has PUT the video to storage, verify the
// object and record it on the concept. Returns the refreshed video panel as an
// HTML fragment (see docs/php-guidelines.md, "Ajax endpoint html fragments")
// or a plain-text error with a 4xx/5xx status.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
Application::init();

header('Content-Type: text/html; charset=UTF-8');

function attach_fail(string $message, int $status = 400): void {
    http_response_code($status);
    echo h($message);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    attach_fail('POST required.', 405);
}
if (!current_user()) {
    attach_fail('Please sign in again.', 401);
}
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    attach_fail('Your session expired. Reload the page and try again.', 403);
}

$conceptId = (int)($_POST['concept_id'] ?? 0);
$key = (string)($_POST['key'] ?? '');
$ctx = UserContext::getLoggedInUserContext();

try {
    if (!VideoStorage::keyBelongsToConcept($key, $conceptId)) {
        attach_fail('That upload does not belong to this concept.', 400);
    }
    $verified = VideoStorage::verifyUploadedObject($key);
    ConceptManagement::attachVideo($ctx, $conceptId, $key, $verified['content_type'], $verified['size']);
    $concept = ConceptManagement::findById($conceptId);
    echo ManageUI::videoPanelHtml($concept);
} catch (Throwable $e) {
    attach_fail($e->getMessage(), 400);
}
