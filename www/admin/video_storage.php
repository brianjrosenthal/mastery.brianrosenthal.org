<?php
// Admin: diagnostics and setup for the object storage that holds concept
// videos and answer videos. New uploads go to the ACTIVE provider (Cloudflare R2); videos that
// predate the move are still in DreamHost DreamObjects until migrated. For
// each provider: check the credentials, create the bucket, apply the CORS rule
// browsers need to upload directly, and compare the bucket with the database.
// Every storage call is caught and reported inline — this page exists
// precisely for the case where storage is misconfigured.
require_once __DIR__ . '/../partials.php';
require_once __DIR__ . '/../lib/VideoStorage.php';
require_once __DIR__ . '/../lib/VideoMigration.php';
require_once __DIR__ . '/../lib/SiteManagement.php';
require_once __DIR__ . '/../lib/ConceptManagement.php';
Application::init();
require_admin();

$msg = $_GET['msg'] ?? null;
$err = $_GET['err'] ?? null;

$active = VideoStorage::activeProvider();
$wantedOrigins = VideoStorage::wantedCorsOrigins();
$counts = VideoMigration::countsByProvider();
$pending = VideoMigration::pending($active);
$pendingBytes = array_sum(array_column($pending, 'video_size_bytes'));

// Probe every provider that is configured or still referenced by a concept.
$probes = [];
foreach (VideoStorage::providers() as $provider) {
    $configured = VideoStorage::isProviderConfigured($provider);
    $bucket = VideoStorage::bucket($provider);
    $dbKeys = VideoMigration::listRecordedObjectKeys($provider);
    $probe = [
        'configured' => $configured, 'bucket' => $bucket, 'db_keys' => $dbKeys,
        'exists' => false, 'count' => null, 'bytes' => null, 'cors' => null, 'error' => null, 'keys' => [],
    ];
    if ($configured) {
        try {
            $client = VideoStorage::storage($provider);
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
    $probe['missing'] = $probe['exists'] ? array_values(array_diff($dbKeys, $probe['keys'])) : [];
    $probe['orphans'] = $probe['exists'] ? array_values(array_diff($probe['keys'], $dbKeys)) : [];
    $probe['cors_missing'] = $probe['cors'] === null ? $wantedOrigins : array_values(array_diff($wantedOrigins, $probe['cors']));
    $probes[$provider] = $probe;
}

function video_storage_action_form(string $action, string $provider, string $label, string $class = '', string $confirm = ''): string {
    return '<form method="post" action="/admin/video_storage_eval.php">'
         . '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'
         . '<input type="hidden" name="action" value="' . h($action) . '">'
         . '<input type="hidden" name="provider" value="' . h($provider) . '">'
         . '<button type="submit" class="button ' . h($class) . '"' . ($confirm !== '' ? ' data-confirm="' . h($confirm) . '"' : '') . '>' . h($label) . '</button>'
         . '</form>';
}

header_html('Video Storage');
?>
<div class="page-head"><h2>Video Storage</h2></div>
<?php if ($msg): ?><p class="flash"><?=h($msg)?></p><?php endif; ?>
<?php if ($err): ?><p class="error"><?=h($err)?></p><?php endif; ?>
<p class="small">Videos are stored in object storage, not on this server or in the database. New uploads go to <strong><?=h(VideoStorage::providerLabel($active))?></strong> (<code>VIDEO_STORAGE_PROVIDER</code>); each concept remembers which provider holds its video, so older videos keep playing from DreamObjects until they are migrated. Browsers upload straight to the bucket with a short-lived URL signed here, so the active bucket needs a CORS rule that allows each site's origin.</p>

<div class="card">
  <h3>Overview</h3>
  <table class="list">
    <tr><th style="width:220px">Uploads go to</th><td><?=h(VideoStorage::providerLabel($active))?></td></tr>
    <tr><th>Upload limit</th><td><?=h(VideoStorage::humanBytes(VideoStorage::maxBytes()))?> per video (<code>VIDEO_MAX_BYTES</code>)</td></tr>
    <tr><th>Videos in the database</th><td>
      <?= array_sum($counts) ?>
      (<?php $parts = []; foreach ($counts as $p => $n) { $parts[] = h(VideoStorage::providerLabel($p)) . ': ' . (int)$n; } echo implode(', ', $parts); ?>)
    </td></tr>
  </table>
</div>

<?php foreach ($probes as $provider => $probe): $isActive = $provider === $active; ?>
<div class="card">
  <h3><?=h(VideoStorage::providerLabel($provider))?> <?php if ($isActive): ?><span class="badge video">Active — new uploads</span><?php else: ?><span class="badge novideo">Previous provider</span><?php endif; ?></h3>
  <?php if (!$probe['configured'] && $probe['db_keys'] === [] && !$isActive): ?>
    <p class="small">Not configured, and no concept video is recorded here. Nothing to do.</p>
  <?php else: ?>
    <table class="list">
      <tr><th style="width:220px">Credentials</th><td>
        <?php $prefix = VideoStorage::PROVIDERS[$provider]['prefix']; ?>
        <?php if ($probe['configured']): ?><span class="status-verified">Configured</span>
        <?php else: ?><span class="status-failed">Not configured</span> — set <code><?=h($prefix)?>_ENDPOINT</code>, <code><?=h($prefix)?>_ACCESS_KEY</code>, <code><?=h($prefix)?>_SECRET_KEY</code> and <code><?=h($prefix)?>_VIDEO_BUCKET</code> in <code>config.local.php</code>.<?php if ($isActive): ?> Uploads are disabled until then.<?php endif; ?><?php if ($probe['db_keys'] !== []): ?> <strong><?= count($probe['db_keys']) ?> concept video(s) are recorded here and cannot play until it is configured.</strong><?php endif; ?><?php endif; ?>
      </td></tr>
      <tr><th>Endpoint</th><td><code><?=h(VideoStorage::endpoint($provider) !== '' ? VideoStorage::endpoint($provider) : '(unset)')?></code></td></tr>
      <tr><th>Region</th><td><code><?=h(VideoStorage::region($provider))?></code></td></tr>
      <tr><th>Bucket</th><td><code><?=h($probe['bucket'] !== '' ? $probe['bucket'] : '(unset)')?></code></td></tr>
      <tr><th>Videos recorded here</th><td><?= count($probe['db_keys']) ?></td></tr>
      <?php if ($probe['configured']): ?>
        <?php if ($probe['error'] !== null): ?>
          <tr><th>Status</th><td><span class="status-failed">Storage error:</span> <?=h($probe['error'])?></td></tr>
        <?php else: ?>
          <tr><th>Status</th><td><?= $probe['exists'] ? '<span class="status-verified">Ready</span>' : '<span class="status-pending">Bucket missing</span>' ?></td></tr>
          <?php if ($probe['exists']): ?>
            <tr><th>Objects</th><td><?= number_format((int)$probe['count']) ?> (<?=h(VideoStorage::humanBytes((int)$probe['bytes']))?>)</td></tr>
            <tr><th>Missing from bucket</th><td><?= count($probe['missing']) === 0 ? '<span class="status-verified">None</span>' : '<span class="status-failed">' . count($probe['missing']) . '</span> concept video(s) recorded here point at objects that are gone' ?></td></tr>
            <tr><th>Orphans in bucket</th><td><?= count($probe['orphans']) === 0 ? '<span class="status-verified">None</span>' : count($probe['orphans']) . ' object(s) not referenced by any concept or answer' . (!$isActive && $pending === [] ? ' — after a migration these are the copies left behind' : '') ?></td></tr>
            <tr><th>CORS origins</th><td>
              <?php if ($probe['cors'] === null): ?><span class="<?= $isActive ? 'status-failed' : 'status-pending' ?>">No CORS rule</span><?php if ($isActive): ?> — browser uploads will fail.<?php endif; ?>
              <?php else: ?><?php foreach ($probe['cors'] as $o): ?><code><?=h($o)?></code> <?php endforeach; ?><?php endif; ?>
              <?php if ($isActive && $probe['cors_missing'] !== [] && $probe['cors'] !== null): ?><br><span class="status-pending">Missing:</span> <?php foreach ($probe['cors_missing'] as $o): ?><code><?=h($o)?></code> <?php endforeach; ?><?php endif; ?>
            </td></tr>
          <?php endif; ?>
        <?php endif; ?>
      <?php endif; ?>
    </table>
    <?php if ($probe['configured'] && $probe['error'] === null): ?>
      <div class="actions" style="margin-top:12px">
        <?php if (!$probe['exists']): ?>
          <?= video_storage_action_form('create_bucket', $provider, 'Create bucket', 'primary') ?>
        <?php else: ?>
          <?php if ($isActive): ?>
            <?= video_storage_action_form('apply_cors', $provider, 'Apply CORS for all site origins', $probe['cors_missing'] !== [] ? 'primary' : '') ?>
            <?= video_storage_action_form('test_upload', $provider, 'Test upload') ?>
          <?php endif; ?>
          <?php if ($probe['orphans'] !== []): ?>
            <?= video_storage_action_form('delete_orphans', $provider, 'Delete orphans', 'danger', 'Delete ' . count($probe['orphans']) . ' orphaned object(s) from the ' . VideoStorage::providerLabel($provider) . ' bucket? They are not referenced by any concept.') ?>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<div class="card">
  <h3>Migration to <?=h(VideoStorage::providerLabel($active))?></h3>
  <?php if ($pending === []): ?>
    <p class="small"><span class="status-verified">Complete</span> — every concept video is in <?=h(VideoStorage::providerLabel($active))?>.
    <?php foreach ($probes as $provider => $probe): if ($provider !== $active && $probe['orphans'] !== []): ?>
      The <?=h(VideoStorage::providerLabel($provider))?> bucket still holds <?= count($probe['orphans']) ?> object(s) nobody references; use <strong>Delete orphans</strong> above to remove them, then its credentials can be dropped from <code>config.local.php</code>.
    <?php endif; endforeach; ?></p>
  <?php else: ?>
    <p><strong><?= count($pending) ?></strong> video(s), <?=h(VideoStorage::humanBytes((int)$pendingBytes))?> in total, are still held elsewhere and will be copied into <?=h(VideoStorage::providerLabel($active))?> under the same key. Each copy is verified by size before the concept is switched over; the original is left in place (it shows up as an orphan afterwards).</p>
    <?php if (!VideoStorage::isConfigured()): ?>
      <p class="error">Configure <?=h(VideoStorage::providerLabel($active))?> first.</p>
    <?php else: ?>
      <div class="actions">
        <?= video_storage_action_form('migrate_next', $active, 'Migrate next video (' . VideoStorage::humanBytes((int)$pending[0]['video_size_bytes']) . ')', 'primary') ?>
      </div>
      <p class="small" style="margin-top:10px">The button streams one video through this server per click, which suits a handful of small videos. For many or large videos run it from a shell on the server instead, which has no request time limit:</p>
      <pre style="white-space:pre-wrap">php deploy/migrate-videos.php            # copy every pending video, keep the originals
php deploy/migrate-videos.php --dry-run  # list what would be copied
php deploy/migrate-videos.php --delete-source   # also delete each original once its copy is verified</pre>
    <?php endif; ?>
    <table class="list" style="margin-top:12px">
      <tr><th>Concept</th><th>Held in</th><th>Size</th><th>Key</th></tr>
      <?php foreach (array_slice($pending, 0, 50) as $row): ?>
        <tr><td><a href="/manage/concept_edit.php?id=<?= (int)$row['concept_id'] ?>"><?=h($row['title'])?></a></td><td><?=h(VideoStorage::providerLabel($row['video_storage']))?></td><td><?=h(VideoStorage::humanBytes((int)$row['video_size_bytes']))?></td><td><code><?=h($row['video_object_key'])?></code></td></tr>
      <?php endforeach; ?>
      <?php if (count($pending) > 50): ?><tr><td colspan="4" class="small">… and <?= count($pending) - 50 ?> more</td></tr><?php endif; ?>
    </table>
  <?php endif; ?>
</div>
<p class="small">Origins the CORS rule allows: <?php foreach ($wantedOrigins as $o): ?><code><?=h($o)?></code> <?php endforeach; ?> (the main host, every site's subdomain and custom domain, and localhost for development). The rule is re-applied automatically when a site is created or its routing changes; re-apply here if that failed. <strong>Test upload</strong> performs a presigned PUT from this server exactly as a browser would and shows the raw response, so an upload failure can be diagnosed here.</p>
<?php footer_html(); ?>
