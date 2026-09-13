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

if (current_user()) {
    header('Location: /manage/');
} else {
    header('Location: /login.php');
}
exit;
