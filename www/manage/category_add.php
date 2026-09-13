<?php
// New category form. Evaluates to category_add_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
Application::init();
require_login();

$target = ManageUI::targetUser($_GET['user_id'] ?? null);
$userId = (int)$target['id'];
$stash = ManageUI::takeForm('category_add_' . $userId);
$form = $stash['data'] + ['name' => '', 'slug' => '', 'description_markdown' => ''];
$err = $stash['err'];

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('New category');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">Manage</a> › New category</div>
<h2>New category</h2>
<p class="small">A category is a big subject, like <em>Algebra II</em> or <em>Chess Openings</em>. Subcategories and concepts go inside it.</p>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/category_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="user_id" value="<?= $userId ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Name
        <input type="text" name="name" value="<?=h($form['name'])?>" required maxlength="150" autofocus>
      </label>
      <label>URL name <span class="hint">optional; made from the name if blank</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" maxlength="80" placeholder="algebra-ii">
      </label>
    </div>
    <?= ManageUI::markdownFieldHtml('description_markdown', 'Description', (string)$form['description_markdown'], 'shown at the top of the category page') ?>
    <div class="actions">
      <button type="submit" class="button primary">Create category</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
