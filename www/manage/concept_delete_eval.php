<?php
// Deletes a concept and its video (POST from the editor or the owner bar).
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
$userId = (int)(ConceptManagement::ownerUserIdOf($id) ?? current_user()['id']);
$dash = ManageUI::dashboardUrl($userId);
$sep = strpos($dash, '?') === false ? '?' : '&';
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    ConceptManagement::delete(UserContext::getLoggedInUserContext(), $id);
    header('Location: ' . ($next !== '' ? $next : $dash . $sep . 'msg=' . urlencode('Concept deleted.')));
} catch (Throwable $e) {
    header('Location: /manage/concept_edit.php?id=' . $id . '&err=' . urlencode('Could not delete the concept: ' . $e->getMessage()));
}
exit;
