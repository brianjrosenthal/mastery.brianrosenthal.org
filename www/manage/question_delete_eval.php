<?php
// Deletes a question (POST from the Q&A section of a public concept page):
// the owner/admin may delete any, the asker only their own unanswered one.
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
    QuestionManagement::delete(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ManageUI::urlWith($next, ['msg' => 'Question deleted.'], 'questions'));
} catch (Throwable $e) {
    header('Location: ' . ManageUI::urlWith($next, ['err' => 'Could not delete the question: ' . $e->getMessage()], 'q' . $id));
}
exit;
