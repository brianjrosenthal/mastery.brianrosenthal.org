<?php
// Removes a concept's video (POST from the video panel) and deletes it from storage.
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
try {
    ConceptManagement::detachVideo(UserContext::getLoggedInUserContext(), $id);
    header('Location: /manage/concept_edit.php?id=' . $id . '&msg=' . urlencode('Video removed.') . '#video');
} catch (Throwable $e) {
    header('Location: /manage/concept_edit.php?id=' . $id . '&err=' . urlencode('Could not remove the video: ' . $e->getMessage()) . '#video');
}
exit;
