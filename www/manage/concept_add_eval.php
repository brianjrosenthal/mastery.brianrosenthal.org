<?php
// Evaluates the new concept form (POST from manage/concept_add.php), then
// sends the author to the editor so they can add the video.
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

$subcategoryId = (int)($_POST['subcategory_id'] ?? 0);
$data = [
    'title' => (string)($_POST['title'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description_markdown' => (string)($_POST['description_markdown'] ?? ''),
    'is_published' => !empty($_POST['is_published']),
    'resources' => is_array($_POST['resources'] ?? null) ? array_values($_POST['resources']) : [],
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    $id = ConceptManagement::create($ctx, $subcategoryId, $data);
    header('Location: /manage/concept_edit.php?id=' . $id . '&msg=' . urlencode('Concept created. Now add your video.') . ($next !== '' ? '&next=' . urlencode($next) : '') . '#video');
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('concept_add_' . $subcategoryId, $data, $e->getMessage());
    header('Location: /manage/concept_add.php?subcategory_id=' . $subcategoryId . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
