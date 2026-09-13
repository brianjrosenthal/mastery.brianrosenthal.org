<?php
// Creates a user's site (POST from the dashboard when none exists yet).
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
Application::init();
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /manage/');
    exit;
}
require_csrf();

$target = ManageUI::targetUser($_POST['user_id'] ?? null);
$userId = (int)$target['id'];
$dash = ManageUI::dashboardUrl($userId);

try {
    $ctx = UserContext::getLoggedInUserContext();
    SiteManagement::createForUser($ctx, $userId, (string)$target['first_name'], (string)($_POST['title'] ?? ''));
    header('Location: ' . $dash . (strpos($dash, '?') === false ? '?' : '&') . 'msg=' . urlencode('Your site is ready. Add a category to get started.'));
} catch (Throwable $e) {
    header('Location: ' . $dash . (strpos($dash, '?') === false ? '?' : '&') . 'err=' . urlencode('Could not create the site: ' . $e->getMessage()));
}
exit;
