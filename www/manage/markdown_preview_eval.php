<?php
// AJAX (POST): renders Markdown to HTML for the editor's Preview button.
// Returns an HTML fragment. Read-only, but POST because the text can be long.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/Application.php';
require_once __DIR__ . '/../lib/MarkdownRenderer.php';
Application::init();

header('Content-Type: text/html; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !current_user()) {
    http_response_code(403);
    exit;
}
$html = MarkdownRenderer::toHtml((string)($_POST['markdown'] ?? ''));
echo $html === '' ? '<p class="muted"><em>Nothing to preview yet.</em></p>' : $html;
