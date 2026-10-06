<?php
// routes/dashboard.php
require_once __DIR__ . '/../config.php';
require_auth();

$user = current_user();
$pdo  = db();

// Fetch pages newest first
$st = $pdo->prepare("
  SELECT id, title, slug, cover_uri, published, updated_at, created_at
  FROM pages
  WHERE user_id = :uid
  ORDER BY updated_at DESC NULLS LAST, id DESC
");
$st->execute([':uid' => $user['id']]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);


$title = 'Dashboard · PlugBio';

ob_start(); ?>
<div class="page-head">
  <div>
    <h1>Your pages</h1>
    <p><?= count($rows) ?> page<?= count($rows) === 1 ? '' : 's' ?> · share a link, track every click</p>
  </div>
  <a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">+ New page</a>
</div>

<?php if (!$rows): ?>
  <div class="empty-state">
    <h3>No pages yet</h3>
    <p>Create a landing page for your latest release in about a minute.</p>
    <a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">Create your first page</a>
  </div>
<?php else: ?>
<div class="table-wrap">
  <table class="tbl">
    <thead>
      <tr>
        <th>Page</th>
        <th>Status</th>
        <th>Updated</th>
        <th class="actions">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
      $isPub   = !empty($r['published']);
      $pubUrl  = page_url($r);
      $editUrl = asset('pages/'.(int)$r['id'].'/edit');
      $delUrl  = asset('pages/'.(int)$r['id'].'/delete');
    ?>
      <tr>
        <td data-label="Page">
          <div class="page-cell">
            <?php if (!empty($r['cover_uri'])): ?>
              <img class="thumb" src="<?= e(thumb_url($r['cover_uri'], 96)) ?>" alt="" loading="lazy" decoding="async">
            <?php else: ?>
              <span class="thumb placeholder">♪</span>
            <?php endif; ?>
            <div>
              <a class="title-cell" href="<?= e($editUrl) ?>"><?= e($r['title'] ?? 'Untitled') ?></a>
              <div><code>/s/<?= e($r['slug'] ?: $r['id']) ?></code></div>
            </div>
          </div>
        </td>
        <td data-label="Status">
          <span class="pill <?= $isPub ? 'published' : 'draft' ?>"><?= $isPub ? 'Published' : 'Draft' ?></span>
        </td>
        <td data-label="Updated" class="muted"><?= e(time_ago($r['updated_at'] ?? $r['created_at'])) ?></td>
        <td class="actions" data-label="Actions">
          <button type="button" class="btn btn-sm copy-btn" data-url="<?= e($pubUrl) ?>"
                  <?= $isPub ? '' : 'disabled title="Publish to share"' ?>>Copy link</button>
          <a class="link" href="<?= e($pubUrl) ?>" target="_blank" rel="noopener">View</a>
          <a class="link" href="<?= e($editUrl) ?>">Edit</a>
          <a class="link danger" href="<?= e($delUrl) ?>">Delete</a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
document.addEventListener('click', function(e){
  const b = e.target.closest('.copy-btn');
  if (!b || b.disabled) return;
  const url = b.dataset.url || '';
  if (!url) return;
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(url).then(() => {
      const old = b.textContent;
      b.textContent = 'Copied!';
      b.disabled = true;
      setTimeout(() => { b.textContent = old; b.disabled = false; }, 1200);
    }).catch(() => { window.prompt('Copy link', url); });
  } else { window.prompt('Copy link', url); }
});
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
