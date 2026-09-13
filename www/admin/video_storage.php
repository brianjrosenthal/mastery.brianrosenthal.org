<?php
// Admin: diagnostics and setup for the DreamObjects bucket that holds concept
// videos. Checks the credentials, creates the bucket, applies the CORS rule
// browsers need to upload directly, and compares the bucket with the
// database. Every storage call is caught and reported inline — this page
// exists precisely for the case where storage is misconfigured.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$configured = VideoStorage::isConfigured();
$bucket = VideoStorage::bucket();
$wantedOrigins = VideoStorage::corsOrigins(SiteResolver::mainHost(), SiteManagement::listDomains());

$probe = ['exists' => false, 'count' => null, 'bytes' => null, 'cors' => null, 'error' => null, 'keys' => []];
if ($configured) {
    try {
        $client = VideoStorage::storage();
        $probe['exists'] = $client->bucketExists($bucket);
        if ($probe['exists']) {
            $objects = $client->listObjects($bucket);
            $probe['count'] = count($objects);
            $probe['bytes'] = array_sum(array_column($objects, 'size'));
            $probe['keys'] = array_column($objects, 'key');
            $probe['cors'] = $client->getBucketCorsOrigins($bucket);
        }
    } catch (Throwable $e) {
        $probe['error'] = $e->getMessage();
    }
}

$dbKeys = ConceptManagement::listVideoObjectKeys();
$missingInBucket = $probe['exists'] ? array_values(array_diff($dbKeys, $probe['keys'])) : [];
$orphans = $probe['exists'] ? array_values(array_diff($probe['keys'], $dbKeys)) : [];
$corsMissing = $probe['cors'] === null ? $wantedOrigins : array_values(array_diff($wantedOrigins, $probe['cors']));

header_html('Video Storage');
?>
<div class="page-head"><h2>Video Storage</h2></div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<p class="small">Videos are stored in DreamHost DreamObjects, not on this server or in the database. Browsers upload straight to the bucket with a short-lived URL signed here, so the bucket needs a CORS rule that allows each site's origin.</p>

<div class="card">
  <h3>Configuration</h3>
  <table class="list">
    <tr><th style="width:220px">Credentials</th><td>
      <?php if ($configured): ?><span class="status-verified">Configured</span>
      <?php else: ?><span class="status-failed">Not configured</span> — set <code>DREAMOBJECTS_ACCESS_KEY</code>, <code>DREAMOBJECTS_SECRET_KEY</code> and <code>DREAMOBJECTS_VIDEO_BUCKET</code> in <code>config.local.php</code>. Uploads are disabled until then.<?php endif; ?>
    </td></tr>
    <tr><th>Endpoint</th><td><code><?=h(defined('DREAMOBJECTS_ENDPOINT') ? DREAMOBJECTS_ENDPOINT : '(unset)')?></code></td></tr>
    <tr><th>Region</th><td><code><?=h(defined('DREAMOBJECTS_REGION') ? DREAMOBJECTS_REGION : '(unset)')?></code></td></tr>
    <tr><th>Bucket</th><td><code><?=h($bucket !== '' ? $bucket : '(unset)')?></code></td></tr>
    <tr><th>Upload limit</th><td><?=h(VideoStorage::humanBytes(VideoStorage::maxBytes()))?> per video (<code>VIDEO_MAX_BYTES</code>)</td></tr>
    <tr><th>Videos in the database</th><td><?= count($dbKeys) ?></td></tr>
  </table>
</div>

<div class="card">
  <h3>Bucket</h3>
  <?php if (!$configured): ?>
    <p class="small">Configure credentials first.</p>
  <?php elseif ($probe['error'] !== null): ?>
    <p class="error">Storage error: <?=h($probe['error'])?></p>
  <?php else: ?>
    <table class="list">
      <tr><th style="width:220px">Status</th><td><?= $probe['exists'] ? '<span class="status-verified">Ready</span>' : '<span class="status-pending">Missing</span>' ?></td></tr>
      <?php if ($probe['exists']): ?>
        <tr><th>Objects</th><td><?= number_format((int)$probe['count']) ?> (<?=h(VideoStorage::humanBytes((int)$probe['bytes']))?>)</td></tr>
        <tr><th>Missing from bucket</th><td><?= count($missingInBucket) === 0 ? '<span class="status-verified">None</span>' : '<span class="status-failed">' . count($missingInBucket) . '</span> concept video(s) point at objects that are gone' ?></td></tr>
        <tr><th>Orphans in bucket</th><td><?= count($orphans) === 0 ? '<span class="status-verified">None</span>' : count($orphans) . ' object(s) not referenced by any concept' ?></td></tr>
        <tr><th>CORS origins</th><td>
          <?php if ($probe['cors'] === null): ?><span class="status-failed">No CORS rule</span> — browser uploads will fail.
          <?php else: ?><?php foreach ($probe['cors'] as $o): ?><code><?=h($o)?></code> <?php endforeach; ?><?php endif; ?>
          <?php if ($corsMissing !== [] && $probe['cors'] !== null): ?><br><span class="status-pending">Missing:</span> <?php foreach ($corsMissing as $o): ?><code><?=h($o)?></code> <?php endforeach; ?><?php endif; ?>
        </td></tr>
      <?php endif; ?>
    </table>
    <div class="actions" style="margin-top:12px">
      <?php if (!$probe['exists']): ?>
        <form method="post" action="/admin/video_storage_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="create_bucket">
          <button type="submit" class="button primary">Create bucket</button>
        </form>
      <?php else: ?>
        <form method="post" action="/admin/video_storage_eval.php">
          <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
          <input type="hidden" name="action" value="apply_cors">
          <button type="submit" class="button <?= $corsMissing !== [] ? 'primary' : '' ?>">Apply CORS for all site origins</button>
        </form>
        <?php if ($orphans !== []): ?>
          <form method="post" action="/admin/video_storage_eval.php">
            <input type="hidden" name="csrf" value="<?=h(csrf_token())?>">
            <input type="hidden" name="action" value="delete_orphans">
            <button type="submit" class="button danger" data-confirm="Delete <?= count($orphans) ?> orphaned object(s) from the bucket? They are not referenced by any concept.">Delete orphans</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <p class="small" style="margin-top:10px">Origins the CORS rule will allow: <?php foreach ($wantedOrigins as $o): ?><code><?=h($o)?></code> <?php endforeach; ?> (the main host, every site's custom domain, and localhost for development). Re-apply after adding a domain.</p>
  <?php endif; ?>
</div>
<?php footer_html(); ?>
