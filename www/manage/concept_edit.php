<?php
// Concept editor: details, links, publish state and the video panel.
// Evaluates to concept_edit_eval.php; the video panel talks to
// video_presign_eval.php / concept_video_attach_eval.php via video.js.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/ManageUI.php';
require_once __DIR__ . '/../lib/ContentAccess.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
require_once __DIR__ . '/../lib/QuestionManagement.php';
Application::init();
require_login();

$id = (int)($_GET['id'] ?? 0);
$concept = $id > 0 ? ConceptManagement::findWithAncestors($id) : null;
if (!$concept) {
    header('Location: /manage/?err=' . urlencode('Concept not found.'));
    exit;
}
$ctx = UserContext::getLoggedInUserContext();
$userId = (int)$concept['user_id'];
if (!ContentAccess::canEdit($ctx, $userId)) {
    http_response_code(403);
    die('You can only change your own content.');
}
$site = SiteManagement::findByUserId($userId);
$publicUrl = $site ? SiteResolver::urlFor(SiteResolver::basePathFor($site), (string)$concept['category_slug'], (string)$concept['subcategory_slug'], (string)$concept['slug']) : null;

$stash = ManageUI::takeForm('concept_edit_' . $id);
$form = $stash['data'] + $concept;
$resources = array_key_exists('resources', $stash['data']) ? (array)$stash['data']['resources'] : ConceptManagement::listResources($id);
$err = $stash['err'] ?? ($_GET['err'] ?? null);
$msg = $_GET['msg'] ?? null;
$published = !empty($concept['is_published']);
$questions = QuestionManagement::listForConcept($id, $ctx, true);
$unanswered = count(array_filter($questions, static fn(array $q): bool => $q['answered_at'] === null));
$selfUrl = '/manage/concept_edit.php?id=' . $id;

ApplicationUI::useSiteTheme(SiteManagement::findByUserId($userId));
header_html('Edit ' . $concept['title']);
?>
<div class="crumbs"><a href="<?=h(ManageUI::dashboardUrl($userId))?>">Manage</a> › <a href="/manage/category_edit.php?id=<?= (int)$concept['category_id'] ?>"><?=h($concept['category_name'])?></a> › <a href="/manage/subcategory_edit.php?id=<?= (int)$concept['subcategory_id'] ?>"><?=h($concept['subcategory_name'])?></a> › <?=h($concept['title'])?></div>
<div class="page-head">
  <h2><?=h($concept['title'])?> <?= $published ? '<span class="badge published">Published</span>' : '<span class="badge draft">Draft</span>' ?></h2>
  <div class="actions">
    <?php if ($publicUrl): ?><a class="button" href="<?=h($publicUrl)?>">View page</a><?php endif; ?>
    <form method="post" action="/manage/concept_publish_eval.php">
      <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="publish" value="<?= $published ? 0 : 1 ?>">
      <button type="submit" class="button <?= $published ? '' : 'primary' ?>"><?= $published ? 'Unpublish' : 'Publish' ?></button>
    </form>
  </div>
</div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>

<div class="card">
  <?= ManageUI::videoPanelHtml($concept) ?>
</div>

<div class="card" id="questions">
  <h3>Questions (<?= count($questions) ?><?= $unanswered > 0 ? ', ' . $unanswered . ' waiting' : '' ?>)</h3>
  <?= ManageUI::questionsEditorHtml($questions, $selfUrl) ?>
</div>

<div class="card">
  <h3>Details</h3>
  <form method="post" action="/manage/concept_edit_eval.php" class="stack" id="concept-form">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <?= ManageUI::nextInputHtml() ?>
    <div class="grid form-grid">
      <label>Title
        <input type="text" name="title" value="<?=h($form['title'])?>" required maxlength="150">
      </label>
      <label>URL name <span class="hint">changing it changes the page's address</span>
        <input type="text" name="slug" value="<?=h($form['slug'])?>" required maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*">
      </label>
      <label>Order <span class="hint">lower numbers first</span>
        <input type="number" name="sort_order" value="<?= (int)$form['sort_order'] ?>" min="0" max="9999">
      </label>
    </div>
    <?= ManageUI::markdownFieldHtml('description_markdown', 'Description', (string)$form['description_markdown'], 'explain the idea in your own words; shown under the video') ?>
    <div>
      <label>Supporting links</label>
      <?= ManageUI::resourcesEditorHtml($resources) ?>
    </div>
    <div class="actions">
      <button type="submit" class="button primary">Save</button>
      <a class="button" href="<?=h(ManageUI::nextOr(ManageUI::dashboardUrl($userId)))?>">Cancel</a>
    </div>
  </form>
</div>

<div class="card">
  <h3>Delete</h3>
  <form method="post" action="/manage/concept_delete_eval.php" class="actions">
    <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="next" value="<?=h(ManageUI::dashboardUrl($userId))?>">
    <button type="submit" class="button danger" data-confirm="Delete the concept &quot;<?=h($concept['title'])?>&quot; and its video? This cannot be undone.">Delete concept</button>
  </form>
</div>

<script>window.MASTERY_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<?= ApplicationUI::jsScript('/manage/manage.js') ?>
<?= ApplicationUI::jsScript('/manage/video.js') ?>
<?php footer_html(); ?>
