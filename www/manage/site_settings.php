<?php
// Site settings form (title, tagline, homepage Markdown, colour, visibility;
// slug and domain for admins). Evaluates to site_settings_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
Application::init();
require_login();

$siteId = (int)($_GET['site_id'] ?? 0);
$site = $siteId > 0 ? SiteManagement::findById($siteId) : null;
if (!$site) {
    header('Location: /manage/?err=' . urlencode('Site not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
if (!ContentAccess::canEdit($ctx, (int)$site['user_id'])) {
    http_response_code(403);
    die('You can only change your own site.');
}
$isAdmin = $ctx->admin;

$stash = ManageUI::takeForm('site_' . $siteId);
$form = $stash['data'] + $site;
$err = $stash['err'] ?? ($_GET['err'] ?? null);
$msg = $_GET['msg'] ?? null;
$userId = (int)$site['user_id'];

header_html('Site settings');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">Manage</a> › Site settings</div>
<div class="page-head">
  <h2>Site settings</h2>
  <a class="button" href="<?=h(SiteResolver::publicHomeUrl($site))?>">View site</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/site_settings_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="site_id" value="<?= $siteId ?>">
    <?= ManageUI::nextInputHtml() ?>

    <div class="grid form-grid">
      <label>Site title
        <input type="text" name="title" value="<?=h($form['title'])?>" required maxlength="150">
      </label>
      <label>Tagline <span class="hint">one line under the title on the homepage</span>
        <input type="text" name="tagline" value="<?=h($form['tagline'])?>" maxlength="255" placeholder="e.g. Things I've figured out, explained by me">
      </label>
    </div>

    <?= ManageUI::markdownFieldHtml('homepage_markdown', 'Homepage', (string)$form['homepage_markdown'], 'shown above your categories') ?>

    <label>Accent colour
      <div class="actions">
        <?php foreach (SiteManagement::ACCENTS as $key => $accent): ?>
          <label class="inline" style="gap:6px">
            <input type="radio" name="accent_color" value="<?=h($key)?>" <?= $form['accent_color'] === $key ? 'checked' : '' ?>>
            <span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:<?=h($accent['color'])?>"></span>
            <?=h($accent['label'])?>
          </label>
        <?php endforeach; ?>
      </div>
    </label>

    <label class="inline">
      <input type="checkbox" name="is_public" value="1" <?= !empty($form['is_public']) ? 'checked' : '' ?>>
      Site is public <span class="hint">(unchecked: only you and admins can see it)</span>
    </label>

    <?php if ($isAdmin): ?>
      <h3>Routing <span class="small">(admins only)</span></h3>
      <div class="grid form-grid">
        <label>URL name <span class="hint">serves the site at /site/{name}/</span>
          <input type="text" name="slug" value="<?=h($form['slug'])?>" required maxlength="50" pattern="[a-z0-9]+(-[a-z0-9]+)*">
        </label>
        <label>Custom domain <span class="hint">bare hostname; the domain must also be pointed at this directory in the DreamHost panel (see docs/deployment.md)</span>
          <input type="text" name="domain" value="<?=h($form['domain'] ?? '')?>" maxlength="253" placeholder="leave blank for none, e.g. mastery.NAME.org">
        </label>
      </div>
    <?php else: ?>
      <p class="small">Address: <?=h(SiteResolver::canonicalHomeUrl($site))?> (ask an admin to change the URL name or domain).</p>
    <?php endif; ?>

    <div class="actions">
      <button type="submit" class="button primary">Save settings</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
