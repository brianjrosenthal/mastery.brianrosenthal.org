<?php
// Edit subcategory form. Evaluates to subcategory_edit_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
Application::init();
require_login();

$id = (int)($_GET['id'] ?? 0);
$sub = $id > 0 ? SubcategoryManagement::findById($id) : null;
if (!$sub) {
    header('Location: /manage/?err=' . urlencode('Subcategory not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)SubcategoryManagement::ownerUserIdOf($id);
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$cat = CategoryManagement::findById((int)$sub['category_id']);
$stash = ManageUI::takeForm('subcategory_edit_' . $id);
$form = $stash['data'] + $sub;
$err = $stash['err'];
$msg = $_GET['msg'] ?? null;
$concepts = ConceptManagement::listForSubcategory($id, true);
$conceptCount = count($concepts);
$site = SiteManagement::findByUserId($userId);
$base = $site ? SiteResolver::basePathFor($site) : '';
$selfUrl = '/manage/subcategory_edit.php?id=' . $id;

ApplicationUI::useSiteTheme($site);
header_html('Edit ' . $sub['name']);
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">Manage</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <?=h($sub['name'])?></div>
<div class="page-head">
  <h2>Edit subcategory</h2>
  <a class="button" href="/manage/concept_add.php?subcategory_id=<?= $id ?>">+ Add concept</a>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/subcategory_edit_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Name
        <input type="text" name="name" value="<?=h($form['name'])?>" required maxlength="150">
      </label>
      <label>URL name <span class="hint">changing it changes the page's address</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" required maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*">
      </label>
      <label>Order <span class="hint">lower numbers first</span>
        <input type="number" name="sort_order" value="<?= (int)$form['sort_order'] ?>" min="0" max="9999">
      </label>
    </div>
    <?= ManageUI::markdownFieldHtml('description_markdown', 'Description', (string)$form['description_markdown'], 'shown at the top of the subcategory page') ?>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Concepts</h3>
  <?php if ($concepts === []): ?>
    <p class="muted">No concepts yet. <a href="/manage/concept_add.php?subcategory_id=<?= $id ?>&next=<?= urlencode($selfUrl) ?>">Add the first concept</a>.</p>
  <?php else: ?>
    <ul class="tree">
      <?php foreach ($concepts as $c): ?>
        <li><div class="tree-node">
          <a class="title" href="<?=h(SiteResolver::urlFor($base, (string)$cat['slug'], (string)$sub['slug'], (string)$c['slug']))?>"><?=h($c['title'])?></a>
          <?= !empty($c['is_published']) ? '<span class="badge published">Published</span>' : '<span class="badge draft">Draft</span>' ?>
          <?= !empty($c['video_object_key']) ? '<span class="badge video">&#9654; Video</span>' : '<span class="badge novideo">No video</span>' ?>
          <span class="tools"><a href="/manage/concept_edit.php?id=<?= (int)$c['id'] ?>&next=<?= urlencode($selfUrl) ?>">Edit</a></span>
        </div></li>
      <?php endforeach; ?>
    </ul>
    <div class="tree-add level-1"><a class="button small" href="/manage/concept_add.php?subcategory_id=<?= $id ?>&next=<?= urlencode($selfUrl) ?>">+ Add concept</a></div>
  <?php endif; ?>
</div>

<div class="card">
  <h3>Delete</h3>
  <?php if ($conceptCount === 0): ?>
    <form method="post" action="/manage/subcategory_delete_eval.php" class="actions">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="next" value="<?=h(ManageUI::dashboardUrl($userId))?>">
      <button type="submit" class="button danger" data-confirm="Delete the subcategory &quot;<?=h($sub['name'])?>&quot;? This cannot be undone.">Delete subcategory</button>
    </form>
  <?php else: ?>
    <p class="small">This subcategory has <?= $conceptCount ?> concept<?= $conceptCount === 1 ? '' : 's' ?>. Delete them first; only an empty subcategory can be deleted.</p>
  <?php endif; ?>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
