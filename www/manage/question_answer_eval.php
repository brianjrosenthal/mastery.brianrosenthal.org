<?php
// Saves the owner's text answer to a question (POST from the Q&A section of
// a public concept page). Emails the asker the first time the question is
// answered. Redirects back to the page.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/QuestionManagement.php';
require_once __DIR__ . '/../lib/QuestionNotifications.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$markdown = (string)($_POST['answer_markdown'] ?? '');
$next = validate_relative_next_path($_POST['next'] ?? '') ?: '/manage/';

try {
    $newlyAnswered = QuestionManagement::answer(UserContext::getLoggedInUserContext(), $id, $markdown);
    if ($newlyAnswered) {
        QuestionNotifications::questionAnswered($id);
    }
    header('Location: ' . ManageUI::urlWith($next, ['msg' => 'Answer saved.'], 'q' . $id));
} catch (Throwable $e) {
    ManageUI::stashForm('question_answer_' . $id, ['answer_markdown' => $markdown], $e->getMessage());
    header('Location: ' . ManageUI::urlWith($next, [], 'q' . $id));
}
exit;
