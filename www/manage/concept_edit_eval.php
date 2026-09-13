<?php
// Evaluates the concept editor's details form (POST from manage/concept_edit.php).
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
$data = [
    'title' => (string)($_POST['title'] ?? ''),
    'slug' => (string)($_POST['slug'] ?? ''),
    'description_markdown' => (string)($_POST['description_markdown'] ?? ''),
    'sort_order' => (int)($_POST['sort_order'] ?? 0),
    'resources' => is_array($_POST['resources'] ?? null) ? array_values($_POST['resources']) : [],
];
$next = validate_relative_next_path($_POST['next'] ?? '');

try {
    $ctx = UserContext::getLoggedInUserContext();
    ConceptManagement::update($ctx, $id, $data);
    if ($next !== '') {
        $c = ConceptManagement::findWithAncestors($id);
        $site = SiteManagement::findByUserId((int)$c['user_id']);
        $isPublicPage = $site && strpos($next, SiteResolver::basePathFor($site) . '/') === 0;
        header('Location: ' . ($isPublicPage
            ? SiteResolver::urlFor(SiteResolver::basePathFor($site), (string)$c['category_slug'], (string)$c['subcategory_slug'], (string)$c['slug'])
            : $next));
    } else {
        header('Location: /manage/concept_edit.php?id=' . $id . '&msg=' . urlencode('Concept saved.'));
    }
    exit;
} catch (Throwable $e) {
    ManageUI::stashForm('concept_edit_' . $id, $data, $e->getMessage());
    header('Location: /manage/concept_edit.php?id=' . $id . ($next !== '' ? '&next=' . urlencode($next) : ''));
    exit;
}
