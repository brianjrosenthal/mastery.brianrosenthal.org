<?php
// Router for every public page of a user's site. .htaccess (and the dev
// router) rewrite pretty URLs here:
//   /site/{slug}/{category}/{subcategory}/{concept}/  -> ?site={slug}&path=...
//   /{category}/{subcategory}/{concept}/ on a custom domain -> ?path=...
// SiteResolver decides whose site it is; SitePages renders the page.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/Application.php';
require_once __DIR__ . '/lib/SiteResolver.php';
require_once __DIR__ . '/lib/SitePages.php';

Application::init();

$resolved = SiteResolver::resolveFromRequest();
SitePages::render($resolved, (string)($_GET['path'] ?? ''));
