<?php
// routes/page_public.php
require_once __DIR__ . '/../config.php';

// Map a link to service styling (returns classes + 16×16 icon)
if (!function_exists('service_meta')) {
  function service_meta(string $label, string $url): array {
    $svg = [
      'spotify'    => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M12 0a12 12 0 1 0 .001 24.001A12 12 0 0 0 12 0Zm5.47 17.28a.9.9 0 0 1-1.24.31c-3.4-2.07-7.7-2.54-12.75-1.4a.9.9 0 1 1-.39-1.75c5.47-1.22 10.2-.69 14 1.6.43.26.57.82.27 1.24Zm1.66-3.13a1.12 1.12 0 0 1-1.54.39c-3.89-2.38-9.82-3.08-14.4-1.7a1.12 1.12 0 1 1-.64-2.15c5.2-1.56 11.65-.78 16.06 1.93.53.33.7 1.02.33 1.53Zm.15-3.27c-4.43-2.63-11.78-2.87-16.02-1.69a1.33 1.33 0 1 1-.73-2.57c4.88-1.39 12.96-1.1 18 1.86a1.33 1.33 0 0 1-1.25 2.4Z"/></svg>',
      'apple'      => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M9 3v14a3 3 0 1 1-2-2.83V5.5a.5.5 0 0 1 .37-.48l8-2a.5.5 0 0 1 .63.48V16a3 3 0 1 1-2-2.83V5.27L9 6.77V3z"/></svg>',
      'soundcloud' => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M17.5 10a4.5 4.5 0 0 0-4.35-3.5c-2.1 0-3.85 1.5-4.22 3.5H8a3.5 3.5 0 1 0 0 7h9.5A3.5 3.5 0 1 0 17.5 10z"/></svg>',
      'amazon'     => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M5 18.5a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5A.75.75 0 0 1 5 18.5Zm1.5-3.5a5 5 0 1 1 7.9-5.92.5.5 0 0 0 .6.29 3.5 3.5 0 1 1 .25 6.83H6.5Z"/></svg>',
      'youtube'    => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M23 12c0-2.1-.2-3.9-.6-4.7-.3-.7-.9-1.3-1.6-1.6C19.9 5.1 12 5.1 12 5.1s-7.9 0-8.8.6C2.5 6 1.9 6.6 1.6 7.3 1.2 8.1 1 9.9 1 12s.2 3.9.6 4.7c.3.7.9 1.3 1.6 1.6.9.6 8.8.6 8.8.6s7.9 0 8.8-.6c.7-.3 1.3-.9 1.6-1.6.4-.8.6-2.6.6-4.7ZM10 15.5v-7l6 3.5-6 3.5Z"/></svg>',
      'tidal'      => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M6 7 9 10 6 13 3 10 6 7Zm12 0 3 3-3 3-3-3 3-3Zm-6 0 3 3-3 3-3-3 3-3Zm0 6 3 3-3 3-3-3 3-3Z"/></svg>',
      'deezer'     => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M3 16h3v3H3v-3Zm4-2h3v5H7v-5Zm4-3h3v8h-3V11Zm4-3h3v11h-3V8Z"/></svg>',
      'bandcamp'   => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M4 7h8l-4 10H0L4 7Zm10 0h10v10H14V7Z"/></svg>',
      'audiomack'  => '<svg class="icon" width="16" height="16" viewBox="0 0 24 24"><path d="M4 12h2l1 5 2-10 2 10 1-5h2l-2 7h-4L4 12Z"/></svg>',
    ];

    $key = detect_service($url);
    return [
      'class' => 'svc-' . ($key ?? 'default'),
      'name'  => $key ? service_label($key) : ($label ?: (parse_url($url, PHP_URL_HOST) ?: 'Open link')),
      'cta'   => $key ? 'Play' : 'Open',
      'icon'  => $svg[$key ?? ''] ?? '<svg class="icon" viewBox="0 0 24 24"><path d="M10.6 13.4a1 1 0 0 1 0-1.4l3.5-3.5a1 1 0 1 1 1.4 1.4L12 13.4a1 1 0 0 1-1.4 0ZM7.8 19.6a4 4 0 0 1-2.8-6.8l2.1-2.1a1 1 0 1 1 1.4 1.4l-2.1 2.1a2 2 0 0 0 2.8 2.8l2.1-2.1a1 1 0 1 1 1.4 1.4l-2.1 2.1a4 4 0 0 1-2.8 1.2Zm8.5-6.3a1 1 0 0 1-.7-1.7l2.1-2.1a2 2 0 0 0-2.8-2.8l-2.1 2.1a1 1 0 1 1-1.4-1.4l2.1-2.1a4 4 0 0 1 5.6 5.6l-2.1 2.1a1 1 0 0 1-.7.3Z"/></svg>',
    ];
  }
}

/* ---------- load page: /s/{slug|id} or /@handle/{slug|id} ---------- */

$handle = $GLOBALS['handle']   ?? null;
$key    = (string)($GLOBALS['page_key'] ?? '');
if ($key === '') not_found();

$pdo   = db();
$where = ctype_digit($key) ? 'p.id = :key' : 'lower(p.slug) = lower(:key)';
$par   = [':key' => ctype_digit($key) ? (int)$key : $key];
if ($handle !== null) {
  $where .= ' AND lower(u.handle) = lower(:h)';
  $par[':h'] = $handle;
}
// Slugs are unique for new pages; for older duplicates prefer the published, most recent one.
$st = $pdo->prepare("SELECT p.*, u.handle, u.profile_public FROM pages p JOIN users u ON u.id = p.user_id
                     WHERE $where
                     ORDER BY p.published DESC, p.updated_at DESC NULLS LAST, p.id DESC
                     LIMIT 1");
$st->execute($par);
$page = $st->fetch();
if (!$page) not_found();

/* ---------- visibility ---------- */

$owner_id  = (int)$page['user_id'];
$is_owner  = $owner_id === (int)(current_user()['id'] ?? 0);
$is_public = (bool)$page['published'];

/* ---------- unpublished: non-owner sees “private” screen ---------- */
if (!$is_public && !$is_owner) {
  http_response_code(403);
  $title = 'Private page';
  $head  = '<meta name="robots" content="noindex,noarchive">'."\n";
  ob_start(); ?>
  <div class="card narrow" style="text-align:center;padding:40px 28px;max-width:420px">
    <div style="font-size:40px;margin-bottom:8px" aria-hidden="true">🔒</div>
    <h1 style="font-size:24px">This page is private</h1>
    <p class="muted">The artist hasn’t published this page yet.</p>
    <a class="btn btn-primary" href="<?= e(asset('')) ?>">Discover other music</a>
  </div>
  <?php
  $content = ob_get_clean();
  require __DIR__ . '/../views/layout.php';
  exit;
}

/* ---------- render published (or owner preview) ---------- */

$title  = trim((string)($page['title'] ?? '')) ?: 'Untitled';
$artist = trim((string)($page['artist_name'] ?? ''));
$cover  = page_cover($page);
$links  = page_links($page);
$profileUrl = !empty($page['profile_public']) ? asset('u/' . $page['handle']) : null;

// Search + link-preview metadata
$canonical = page_url($page);
$services  = array_values(array_unique(array_filter(array_map(fn($l) => service_label(detect_service($l['url'])), $links))));
$listenOn  = $services ? ' on ' . implode(', ', array_slice($services, 0, -1)) . (count($services) > 1 ? ' and ' : '') . end($services) : '';
// ?v= changes whenever the page is edited, so platforms fetch a fresh preview image
$ogImage   = asset('og/' . (int)$page['id'] . '.png?v=' . substr(md5((string)$page['updated_at']), 0, 8));
meta_set([
  'description' => 'Listen to “' . $title . '”' . ($artist ? " by $artist" : '') . $listenOn . '.',
  'image'       => $ogImage,
  'url'         => $canonical,
  'type'        => 'music.song',
  // Structured data so search engines understand this is a song, by whom, and where to stream it
  'jsonld'      => array_filter([
    '@context' => 'https://schema.org',
    '@type'    => 'MusicRecording',
    'name'     => $title,
    'url'      => $canonical,
    'image'    => $ogImage,
    'byArtist' => $artist ? ['@type' => 'MusicGroup', 'name' => $artist] : null,
    'sameAs'   => array_column($links, 'url') ?: null,
  ]),
]);
$head = $is_public ? '' : '<meta name="robots" content="noindex">' . "\n";

record_page_view((int)$page['id'], $owner_id);

$full_bleed = true;
ob_start(); ?>
<?php if ($cover): ?><div class="song-bg" style="background-image:url('<?= e(thumb_url($cover, 200)) ?>')"></div><?php endif; ?>
<div class="wrap">
  <article class="song">
    <?php if (!$is_public): ?>
      <div class="notice warn draft-banner">
        Draft — only you can see this. <a href="<?= e(asset('pages/' . (int)$page['id'] . '/edit')) ?>">Edit &amp; publish</a>
      </div>
    <?php endif; ?>

    <?php if ($cover): ?>
      <div class="cover-wrap"><img src="<?= e(thumb_url($cover, 800)) ?>" alt="<?= e($title) ?> cover art" width="800" height="800" fetchpriority="high"></div>
    <?php else: ?>
      <div class="cover-wrap placeholder" aria-hidden="true">♪</div>
    <?php endif; ?>

    <h1><?= e($title) ?></h1>
    <?php if ($artist): ?>
      <p class="artist"><?= $profileUrl ? '<a href="'.e($profileUrl).'">'.e($artist).'</a>' : e($artist) ?></p>
    <?php endif; ?>

    <?php if ($links): ?>
      <div class="svc-list">
        <?php foreach ($links as $i => $link): $meta = service_meta($link['label'], $link['url']); ?>
          <a class="svc-btn <?= e($meta['class']) ?>" href="<?= e(asset('go/' . (int)$page['id'] . '/' . (int)$i)) ?>" target="_blank" rel="noopener">
            <span class="svc-icon"><?= $meta['icon'] ?></span>
            <span class="svc-name"><?= e($meta['name']) ?></span>
            <span class="svc-cta"><?= e($meta['cta']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">No streaming links yet.</p>
    <?php endif; ?>

    <p class="song-foot">Made with <a href="<?= e(asset('')) ?>">PlugBio</a></p>
  </article>
</div>
<?php
$content = ob_get_clean();

$title = $title . ($artist ? " · $artist" : '');
require __DIR__ . '/../views/layout.php';
