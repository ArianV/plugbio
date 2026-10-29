<?php
// routes/pages_new.php
require_once __DIR__ . '/../config.php';
require_auth();
csrf_check();

$user = current_user();
$err  = null;

/* ----------------- POST: create ----------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $title     = trim($_POST['title']  ?? '');
  $artist    = trim($_POST['artist'] ?? '') ?: 'Unknown Artist';
  $published = (($_POST['published'] ?? '0') === '1');

  [$cover_uri, $err] = save_uploaded_image('cover', 'cover');
  if ($title === '') $err = 'Please enter a title.';
  // No upload: use the official artwork the link lookup found, if any.
  if (!$err && !$cover_uri) $cover_uri = save_found_cover((string)($_POST['cover_found'] ?? ''));

  if (!$err) {
    $pdo = db();
    $pdo->prepare('INSERT INTO pages (user_id, title, artist_name, cover_uri, links_json, slug, published)
                   VALUES (:uid, :title, :artist, :cover, :links, :slug, :pub)')
        ->execute([
          ':uid'    => $user['id'],
          ':title'  => $title,
          ':artist' => $artist,
          ':cover'  => $cover_uri,
          ':links'  => links_to_json((array)($_POST['links_url'] ?? [])),
          ':slug'   => unique_page_slug($pdo, $title, $artist),
          ':pub'    => $published ? 1 : 0,
        ]);
    header('Location: ' . asset('dashboard'));
    exit;
  }
}

/* ----------------- view ----------------- */
$heading     = 'New page';
$submitLabel = 'Create page';
$form = [
  'title'     => $_POST['title']  ?? '',
  'artist'    => $_POST['artist'] ?? '',
  'cover'     => null,
  'cover_found' => (string)($_POST['cover_found'] ?? ''),
  'published' => ($_POST['published'] ?? '0') === '1',
  'links'     => array_filter(array_map('trim', (array)($_POST['links_url'] ?? []))),
];
require __DIR__ . '/../views/page_form.php';
