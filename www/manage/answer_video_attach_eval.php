<?php
// AJAX (POST): after the browser has PUT an answer video to storage, verify
// the object and record it on the question; email the asker if this is the
// first answer. Returns the refreshed answer video panel as an HTML fragment
// or a plain-text error with a 4xx/5xx status. Mirrors
// concept_video_attach_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/QuestionManagement.php';
require_once __DIR__ . '/../lib/QuestionNotifications.php';
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

$questionId = (int)($_POST['question_id'] ?? 0);
$key = (string)($_POST['key'] ?? '');
$next = validate_relative_next_path($_POST['next'] ?? '') ?: '/manage/';
$ctx = UserContext::getLoggedInUserContext();

try {
    if (!VideoStorage::keyBelongsToAnswer($key, $questionId)) {
        attach_fail('That upload does not belong to this question.', 400);
    }
    $verified = VideoStorage::verifyUploadedObject($key);
    $newlyAnswered = QuestionManagement::attachVideo($ctx, $questionId, $key, $verified['content_type'], $verified['size']);
    if ($newlyAnswered) {
        QuestionNotifications::questionAnswered($questionId);
    }
    echo ManageUI::answerVideoPanelHtml(QuestionManagement::findById($questionId), $next);
} catch (Throwable $e) {
    attach_fail($e->getMessage(), 400);
}
