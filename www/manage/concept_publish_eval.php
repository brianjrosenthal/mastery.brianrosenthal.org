<?php
// Publishes or unpublishes a concept (POST from the editor or the owner bar).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$id = (int)($_POST['id'] ?? 0);
$publish = !empty($_POST['publish']);
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    ConceptManagement::setPublished(UserContext::getLoggedInUserContext(), $id, $publish);
    header('Location: ' . ($next !== '' ? $next : '/manage/concept_edit.php?id=' . $id . '&msg=' . urlencode($publish ? 'Published! It is now on your site.' : 'Unpublished. Only you can see it now.')));
} catch (Throwable $e) {
    header('Location: /manage/concept_edit.php?id=' . $id . '&err=' . urlencode('Could not change publish state: ' . $e->getMessage()));
}
exit;
