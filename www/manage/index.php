<?php
// Authoring dashboard: the whole content tree for one user's site, with links
// to the public pages and the editors. Admins can switch between users.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/CategoryManagement.php';
Application::init();
require_login();

$target = ManageUI::targetUser($_GET['user_id'] ?? null);
$userId = (int)$target['id'];
$site = SiteManagement::findByUserId($userId);
$me = current_user();
$isMe = ((int)$me['id'] === $userId);

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

ApplicationUI::useSiteTheme($site);
header_html($isMe ? 'My Site' : 'Manage ' . $target['first_name']);
?>

<?= ManageUI::siteSwitcherHtml($userId) ?>

<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<?php if (!$site): ?>
  <div class="card">
    <h2><?= $isMe ? 'Welcome!' : h($target['first_name']) . ' has no site yet' ?></h2>
    <p><?= $isMe ? 'You don\'t have a site yet. Create one to start publishing what you\'ve mastered.' : 'Create their site so they can start publishing.' ?></p>
    <form method="post" action="/manage/site_create_eval.php" class="stack">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="user_id" value="<?= $userId ?>">
      <label>Site title
        <input type="text" name="title" value="<?=h($target['first_name'] . ' Teaches')?>" required maxlength="150">
      </label>
      <div class="actions"><button type="submit" class="button primary">Create site</button></div>
    </form>
  </div>
<?php else: ?>
  <div class="page-head">
    <h2><?=h($site['title'])?></h2>
    <div class="actions">
      <a class="button" href="<?=h(SiteResolver::publicHomeUrl($site))?>">View site</a>
      <a class="button" href="/manage/site_settings.php?site_id=<?= (int)$site['id'] ?>">Site settings</a>
    </div>
  </div>
  <p class="small">
    <?php $canonical = SiteResolver::canonicalHomeUrl($site); $pathForm = '/site/' . $site['slug'] . '/'; ?>
    Live at <a href="<?=h($canonical)?>"><?=h($canonical)?></a>
    <?php if (!empty($site['domain']) && SiteResolver::subdomainHostFor($site) !== ''): ?>
      · also at <a href="https://<?=h(SiteResolver::subdomainHostFor($site))?>/">https://<?=h(SiteResolver::subdomainHostFor($site))?>/</a>
    <?php endif; ?>
    <?php if (strpos($canonical, $pathForm) === false): ?>
      · also at <a href="<?=h($pathForm)?>"><?=h($pathForm)?></a>
    <?php endif; ?>
    <?php if (empty($site['is_public'])): ?> · <span class="status-pending">Not public</span><?php endif; ?>
  </p>

  <div class="card">
    <h3>Content</h3>
    <?= ManageUI::treeHtml(CategoryManagement::treeForUser($userId), $site, $userId) ?>
  </div>
<?php endif; ?>

<?php footer_html(); ?>
