<?php
// views/page_form.php — shared form for creating and editing a song page.
// Expects: $heading, $submitLabel, $err, $form = [title, artist, cover, published, links[]],
//          optional $publicUrl (edit only). Renders through views/layout.php.

$title = $heading . ' · PlugBio';
ob_start(); ?>
<div class="narrow" style="max-width:720px">
  <div class="titlebar">
    <a href="<?= e(asset('dashboard')) ?>" class="backlink">
      <svg class="icon" viewBox="0 0 24 24"><path fill="currentColor" d="M15.5 19.5 8 12l7.5-7.5 1.5 1.5L11 12l6 6-1.5 1.5z"/></svg>
      Dashboard
    </a>
    <h1><?= e($heading) ?></h1>
    <?php if (!empty($publicUrl)): ?>
      <a class="btn btn-sm" href="<?= e($publicUrl) ?>" target="_blank" rel="noopener">View page ↗</a>
      <button type="button" class="btn btn-sm copy-btn" data-url="<?= e($publicUrl) ?>">Copy link</button>
    <?php endif; ?>
  </div>

  <form class="card" method="post" enctype="multipart/form-data">
    <?php if ($err): ?><div class="notice err"><?= e($err) ?></div><?php endif; ?>
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="form-grid">
      <div>
        <label for="f-title">Song title</label>
        <input type="text" id="f-title" name="title" value="<?= e($form['title']) ?>" required placeholder="e.g. 20 Min">
      </div>
      <div>
        <label for="f-artist">Artist</label>
        <input type="text" id="f-artist" name="artist" value="<?= e($form['artist']) ?>" required placeholder="Artist name">
      </div>
    </div>

    <div class="editor-section">
      <label>Streaming links</label>
      <div id="links-list"></div>
      <div class="btn-row">
        <button type="button" class="btn btn-sm" id="add-link">+ Add link</button>
        <span class="hint" style="margin:0">Paste any link, the service is detected automatically.</span>
      </div>
    </div>

    <div class="editor-section">
      <label for="f-cover">Cover art</label>
      <?php if (!empty($form['cover'])): ?>
        <img class="cover-preview" src="<?= e(thumb_url($form['cover'], 400)) ?>" alt="Current cover">
      <?php endif; ?>
      <input type="file" id="f-cover" name="cover" accept="image/jpeg,image/png,image/webp,image/gif">
      <div class="hint">Square images look best. JPG, PNG, WebP or GIF, up to <?= UPLOAD_MAX_MB ?> MB.</div>
    </div>

    <div class="editor-section">
      <div class="status-row">
        <label for="published-select">Visibility</label>
        <span id="status-pill" class="pill <?= $form['published'] ? 'published' : 'draft' ?>"><?= $form['published'] ? 'Published' : 'Draft' ?></span>
      </div>
      <select name="published" id="published-select">
        <option value="0" <?= $form['published'] ? '' : 'selected' ?>>Draft (only you can see it)</option>
        <option value="1" <?= $form['published'] ? 'selected' : '' ?>>Published (anyone with the link)</option>
      </select>
    </div>

    <hr>
    <div class="form-actions">
      <button type="submit" class="btn btn-primary"><?= e($submitLabel) ?></button>
      <a class="link" href="<?= e(asset('dashboard')) ?>">Cancel</a>
    </div>
  </form>
</div>

<script type="application/json" id="prefill-links"><?= json_encode(array_values($form['links']), JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script>
(function(){
  const services = [
    ['Spotify', ['spotify']], ['Apple Music', ['music.apple', 'itunes.apple']], ['SoundCloud', ['soundcloud']],
    ['YouTube', ['youtu.be', 'youtube']], ['Amazon Music', ['music.amazon', 'amazon.com/music']], ['TIDAL', ['tidal.com']],
    ['Deezer', ['deezer']], ['Bandcamp', ['bandcamp.com']], ['Audiomack', ['audiomack.com']],
  ];
  const detect = (url) => {
    const u = (url || '').toLowerCase();
    for (const [name, needles] of services) if (needles.some(n => u.includes(n))) return name;
    return u ? 'Link' : '—';
  };
  const list = document.getElementById('links-list');

  function addRow(url, placeholder){
    const row = document.createElement('div');
    row.className = 'link-row';
    row.innerHTML = '<div class="svc"></div><input type="url" name="links_url[]" inputmode="url" spellcheck="false">'
                  + '<button type="button" class="remove" aria-label="Remove link">Remove</button>';
    const input = row.querySelector('input'), svc = row.querySelector('.svc');
    input.value = url || '';
    input.placeholder = placeholder || 'https://…';
    svc.textContent = detect(input.value);
    input.addEventListener('input', () => { svc.textContent = detect(input.value); });
    row.querySelector('.remove').addEventListener('click', () => row.remove());
    list.appendChild(row);
    return input;
  }

  const pre = JSON.parse(document.getElementById('prefill-links').textContent || '[]');
  pre.forEach(u => addRow(u));
  if (!pre.length) addRow('', 'Paste a Spotify, Apple Music or YouTube link');
  document.getElementById('add-link').addEventListener('click', () => addRow('', 'https://…').focus());

  const sel = document.getElementById('published-select'), pill = document.getElementById('status-pill');
  sel.addEventListener('change', () => {
    const pub = sel.value === '1';
    pill.textContent = pub ? 'Published' : 'Draft';
    pill.className = 'pill ' + (pub ? 'published' : 'draft');
  });

  document.querySelectorAll('.copy-btn').forEach(b => b.addEventListener('click', () => {
    navigator.clipboard?.writeText(b.dataset.url).then(() => { b.textContent = 'Copied!'; setTimeout(() => b.textContent = 'Copy link', 1200); });
  }));
})();
</script>
<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
