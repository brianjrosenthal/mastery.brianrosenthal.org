<?php
// Removes a question's answer video (POST from the answer video panel on a
// public concept page) and deletes it from storage. Redirects back.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/QuestionManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$next = validate_relative_next_path($_POST['next'] ?? '') ?: '/manage/';
try {
    QuestionManagement::detachVideo(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ManageUI::urlWith($next, ['msg' => 'Video removed.'], 'q' . $id));
} catch (Throwable $e) {
    header('Location: ' . ManageUI::urlWith($next, ['err' => 'Could not remove the video: ' . $e->getMessage()], 'q' . $id));
}
exit;
