<?php
// New concept form (title, description, links). The video is added on the
// edit page right after creation. Evaluates to concept_add_eval.php.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/SubcategoryManagement.php';
Application::init();
require_login();

$subcategoryId = (int)($_GET['subcategory_id'] ?? 0);
$sub = $subcategoryId > 0 ? SubcategoryManagement::findById($subcategoryId) : null;
if (!$sub) {
    header('Location: /manage/?err=' . urlencode('Subcategory not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)SubcategoryManagement::ownerUserIdOf($subcategoryId);
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$cat = CategoryManagement::findById((int)$sub['category_id']);
$stash = ManageUI::takeForm('concept_add_' . $subcategoryId);
$form = $stash['data'] + ['title' => '', 'slug' => '', 'description_markdown' => '', 'is_published' => 0, 'resources' => []];
$err = $stash['err'];

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('New concept');
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">Manage</a> › <a href="/manage/category_edit.php?id=<?= (int)$cat['id'] ?>"><?=h($cat['name'])?></a> › <a href="/manage/subcategory_edit.php?id=<?= $subcategoryId ?>"><?=h($sub['name'])?></a> › New concept</div>
<h2>New concept in <?=h($sub['name'])?></h2>
<p class="small">A concept is one thing you can teach, like <em>Derivation of e^x</em>. Save it, then record or upload your video on the next screen.</p>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <form method="post" action="/manage/concept_add_eval.php" class="stack">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="subcategory_id" value="<?= $subcategoryId ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Title
        <input type="text" name="title" value="<?=h($form['title'])?>" required maxlength="150" autofocus>
      </label>
      <label>URL name <span class="hint">optional; made from the title if blank</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" maxlength="80" placeholder="derivation-of-e-x">
      </label>
    </div>
    <?= ManageUI::markdownFieldHtml('description_markdown', 'Description', (string)$form['description_markdown'], 'explain the idea in your own words; shown under the video') ?>
    <div>
      <label>Supporting links</label>
      <?= ManageUI::resourcesEditorHtml((array)$form['resources']) ?>
    </div>
    <label class="inline">
      <input type="checkbox" name="is_published" value="1" <?= !empty($form['is_published']) ? 'checked' : '' ?>>
      Publish right away <span class="hint">(you can also publish later, after adding the video)</span>
    </label>
    <div class="actions">
      <button type="submit" class="button primary">Create concept</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?php footer_html(); ?>
