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
        <span class="hint" style="margin:0">Paste a Spotify, Apple Music, Deezer, SoundCloud or YouTube link and I'll find the song on the other platforms.</span>
      </div>
      <div class="hint lookup-status" id="lookup-status" aria-live="polite" hidden></div>
    </div>

    <div class="editor-section">
      <label for="f-cover">Cover art</label>
      <?php if (!empty($form['cover'])): ?>
        <img class="cover-preview" src="<?= e(thumb_url($form['cover'], 400)) ?>" alt="Current cover">
      <?php endif; ?>
      <input type="hidden" name="cover_found" id="f-cover-found" value="<?= e($form['cover_found'] ?? '') ?>">
      <div id="cover-found" hidden>
        <img class="cover-preview" alt="Official cover art">
        <div class="hint" style="margin:-4px 0 10px">Using the official cover art. Upload your own below to replace it, or <button type="button" class="link-btn" id="cover-found-remove">don't use it</button>.</div>
      </div>
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

  /* ---- finding the song on other platforms when a link is pasted ---- */
  const lookupUrl  = <?= json_encode(asset('pages/lookup'), JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) ?>;
  const hasCover   = <?= empty($form['cover']) ? 'false' : 'true' ?>;
  const csrf       = document.querySelector('input[name=csrf]').value;
  const statusEl   = document.getElementById('lookup-status');
  const titleIn    = document.getElementById('f-title'), artistIn = document.getElementById('f-artist');
  const fileIn     = document.getElementById('f-cover'), foundIn  = document.getElementById('f-cover-found');
  const foundBox   = document.getElementById('cover-found');
  const lookupable = ['Spotify', 'Apple Music', 'Deezer', 'SoundCloud', 'YouTube'];
  const tried      = new Set();

  const say = (msg) => { statusEl.textContent = msg; statusEl.hidden = !msg; };
  const showFoundCover = (url) => {
    foundIn.value = url || '';
    foundBox.hidden = !url;
    if (url) foundBox.querySelector('img').src = url;
  };
  document.getElementById('cover-found-remove').addEventListener('click', () => showFoundCover(''));
  fileIn.addEventListener('change', () => { if (fileIn.files.length) showFoundCover(''); });
  if (foundIn.value && !hasCover) showFoundCover(foundIn.value);

  function lookup(input){
    const url = input.value.trim();
    if (!/^https?:\/\//i.test(url) || !lookupable.includes(detect(url)) || tried.has(url)) return;
    tried.add(url);
    say('Looking for this song on other platforms…');

    const body = new FormData();
    body.append('csrf', csrf);
    body.append('url', url);
    fetch(lookupUrl, { method: 'POST', body, credentials: 'same-origin' })
      .then(r => r.json())
      .then(res => {
        if (!res.ok) { say(res.error || "Couldn't look that link up. Add the other platforms by hand."); return; }
        if (!titleIn.value.trim()  && res.title)  titleIn.value  = res.title;
        if (!artistIn.value.trim() && res.artist) artistIn.value = res.artist;
        if (!hasCover && !fileIn.files.length && !foundIn.value && res.cover) showFoundCover(res.cover);

        // Only add platforms the form doesn't have yet; never overwrite a link someone typed.
        const inputs = () => [...list.querySelectorAll('input')];
        const have = new Set(inputs().map(i => detect(i.value)));
        const added = [];
        res.links.forEach(l => {
          if (have.has(l.label)) return;
          const empty = inputs().find(i => !i.value.trim());
          if (empty) { empty.value = l.url; empty.dispatchEvent(new Event('input')); } else addRow(l.url);
          have.add(l.label);
          added.push(l.label);
        });
        say(added.length
          ? 'Found it on ' + added.join(', ') + '. Give the links a quick check before saving.'
          : "Couldn't find it on any other platforms yet. New releases can take a day to show up everywhere.");
      })
      .catch(() => say("Couldn't look that link up right now. Add the other platforms by hand."));
  }
  // Paste fires before the text lands in the box, so wait a tick; "change" covers typing and autofill.
  list.addEventListener('paste', e => { if (e.target.matches('input')) setTimeout(() => lookup(e.target), 0); });
  list.addEventListener('change', e => { if (e.target.matches('input')) lookup(e.target); });

  const pre = JSON.parse(document.getElementById('prefill-links').textContent || '[]');
  pre.forEach(u => addRow(u));
  if (!pre.length) addRow('', 'Paste a Spotify, Apple Music or SoundCloud link');
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
