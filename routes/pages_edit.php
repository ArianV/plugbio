<?php
// routes/pages_edit.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
$err  = null;

/* ---------- which page? (id or slug from router) ---------- */
$key = (string)($GLOBALS['page_id'] ?? '');
if ($key === '') not_found();

$pdo = db();
if (ctype_digit($key)) {
  $st = $pdo->prepare("SELECT * FROM pages WHERE id=:id AND user_id=:uid");
  $st->execute([':id'=>(int)$key, ':uid'=>$user['id']]);
} else {
  $st = $pdo->prepare("SELECT * FROM pages WHERE slug=:slug AND user_id=:uid");
  $st->execute([':slug'=>$key, ':uid'=>$user['id']]);
}
$page = $st->fetch();
if (!$page) not_found();

$artist_val = $page['artist_name'] ?? '';
$cover_val  = page_cover($page);

/* ---------- POST: update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title     = trim($_POST['title']  ?? '');
  $artist    = trim($_POST['artist'] ?? '') ?: 'Unknown Artist';
  $published = (($_POST['published'] ?? ($page['published'] ? '1' : '0')) === '1');

  // Cover: keep unless replaced
  [$new_cover, $err] = save_uploaded_image('cover', 'cover');
  if ($title === '') $err = 'Please enter a title.';
  if (!$err && !$new_cover && !$cover_val) $new_cover = save_found_cover((string)($_POST['cover_found'] ?? ''));

  if (!$err) {
    // Once a page is published its URL is out in the world, so keep the slug stable.
    $slug = $page['published'] && $page['slug']
      ? $page['slug']
      : unique_page_slug($pdo, $title, $artist, (int)$page['id']);

    $pdo->prepare('UPDATE pages
                   SET title=:title, artist_name=:artist, cover_uri=:cover, links_json=:links,
                       slug=:slug, published=:pub, updated_at=NOW()
                   WHERE id=:id AND user_id=:uid')
        ->execute([
          ':title'  => $title,
          ':artist' => $artist,
          ':cover'  => $new_cover ?: $cover_val,
          ':links'  => links_to_json((array)($_POST['links_url'] ?? [])),
          ':slug'   => $slug,
          ':pub'    => $published ? 1 : 0,
          ':id'     => $page['id'],
          ':uid'    => $user['id'],
        ]);

    header('Location: ' . asset('dashboard'));
    exit;
  }
}

/* ---------- view ---------- */
$heading     = 'Edit page';
$submitLabel = 'Save changes';
$publicUrl   = page_url($page);
$form = [
  'title'     => $_POST['title']  ?? $page['title'] ?? '',
  'artist'    => $_POST['artist'] ?? $artist_val,
  'cover'     => $cover_val,
  'published' => ($_POST['published'] ?? ($page['published'] ? '1' : '0')) === '1',
  'links'     => isset($_POST['links_url'])
    ? array_filter(array_map('trim', (array)$_POST['links_url']))
    : array_column(page_links($page), 'url'),
];
require __DIR__ . '/../views/page_form.php';
