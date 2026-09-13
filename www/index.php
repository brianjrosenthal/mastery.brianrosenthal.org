<?php
// Front door. On a user's custom domain (sites.domain matches the host) this
// is their public homepage. On the main host, signed-in users go to the
// authoring dashboard and everyone else to the login page.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/SiteResolver.php';

Application::init();

$resolved = SiteResolver::resolveFromRequest();
if ($resolved['site'] !== null && $resolved['is_custom_domain']) {
    require_once __DIR__ . '/lib/SitePages.php';
    SitePages::render($resolved, '');
    exit;
}

// A hostname that is neither the main site nor any site's custom domain: say
// so instead of bouncing visitors to a login page they don't need.
if (!SiteResolver::requestIsOnMainHost()) {
    require_once __DIR__ . '/lib/SiteUI.php';
    $host = SiteResolver::normalizeHost((string)($_SERVER['HTTP_HOST'] ?? ''));
    $me = current_user();
    $hint = ($me && !empty($me['is_admin']))
        ? ' As an admin, set this hostname as the site\'s custom domain under Admin → Sites → Settings → Routing.'
        : '';
    SiteUI::notFoundPage(null, '', 'No site is set up for ' . $host . ' yet.' . $hint);
    exit;
}

if (current_user()) {
    header('Location: /manage/');
} else {
    header('Location: /login.php');
}
exit;
