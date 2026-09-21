<?php
// Posts a question under a concept (POST from the Q&A section of a public
// concept page) and emails the concept's owner. Redirects back to the page.
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

$conceptId = (int)($_POST['concept_id'] ?? 0);
$text = (string)($_POST['question_text'] ?? '');
$next = validate_relative_next_path($_POST['next'] ?? '') ?: '/manage/';

try {
    $id = QuestionManagement::ask(UserContext::getLoggedInUserContext(), $conceptId, $text);
    QuestionNotifications::questionAsked($id);
    header('Location: ' . ManageUI::urlWith($next, ['msg' => 'Your question was posted.'], 'q' . $id));
} catch (Throwable $e) {
    ManageUI::stashForm('question_ask_' . $conceptId, ['question_text' => $text], $e->getMessage());
    header('Location: ' . ManageUI::urlWith($next, [], 'ask'));
}
exit;
