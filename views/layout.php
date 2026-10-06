<?php
// views/layout.php
require_once __DIR__ . '/../config.php';

$me = current_user();

$brand   = 'PlugBio';
$title   = $title   ?? $brand;
$head    = $head    ?? '';
$content = $content ?? '';
// Search + link-preview metadata. Pages override these with meta_set([...]).
$desc   = meta_get('description', 'Free smart links for musicians: one beautiful page per release with Spotify, Apple Music, YouTube and more, plus click analytics.');
$image  = meta_get('image') ?: asset('assets/og-default.png?v=' . filemtime(__DIR__ . '/../assets/og-default.png'));
$url    = meta_get('url', asset(request_path()));
$ogType = meta_get('type', 'website');
$jsonld = $GLOBALS['__meta']['jsonld'] ?? null;

$handle = $me['handle'] ?? '';
$avatar = $me['avatar_uri'] ?? '';
$myProfileUrl = $handle ? asset('u/' . $handle) : asset('profile');

// simple avatar fallback (1st letter)
$initial = strtoupper(substr(trim($me['display_name'] ?? $handle ?? 'U'), 0, 1));

// highlight the current section in the nav
$here = request_path();
$navClass = fn(string $p) => 'link' . (($here === "/$p" || ($p === 'feed' && $here === '/')) ? ' active' : '');

// allow pages to opt out of the centered wrapper (e.g. the public song page)
$full_bleed = $full_bleed ?? false;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= e($title) ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap">
  <?php /* ?v=<modified time> makes browsers fetch the stylesheet again whenever it changes */ ?>
  <link rel="stylesheet" href="<?= e(asset('assets/styles.css?v=' . filemtime(__DIR__ . '/../assets/styles.css'))) ?>">

  <meta name="description" content="<?= e($desc) ?>">
  <link rel="canonical" href="<?= e($url) ?>">

  <!-- Open Graph (Facebook, iMessage, Discord, LinkedIn, Slack, WhatsApp…) -->
  <meta property="og:site_name" content="<?= e($brand) ?>">
  <meta property="og:locale" content="en_US">
  <meta property="og:type" content="<?= e($ogType) ?>">
  <meta property="og:title" content="<?= e($title) ?>">
  <meta property="og:description" content="<?= e($desc) ?>">
  <meta property="og:url" content="<?= e($url) ?>">
  <meta property="og:image" content="<?= e($image) ?>">
  <meta property="og:image:type" content="image/png">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="<?= e($title) ?>">

  <!-- X / Twitter -->
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:title" content="<?= e($title) ?>">
  <meta name="twitter:description" content="<?= e($desc) ?>">
  <meta name="twitter:image" content="<?= e($image) ?>">

  <link rel="icon" href="<?= e(asset('favicon.ico')) ?>" sizes="any">
  <link rel="icon" type="image/svg+xml" href="<?= e(asset('assets/favicon.svg')) ?>">
  <link rel="apple-touch-icon" href="<?= e(asset('assets/apple-touch-icon.png')) ?>">
  <meta name="theme-color" content="#07070b">
  <?php if ($jsonld): ?>
  <script type="application/ld+json"><?= json_encode($jsonld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?></script>
  <?php endif; ?>
  <?= $head ?>
</head>
<body>
  <header class="nav">
    <a class="brand" href="<?= e(asset('')) ?>">
      <svg class="logo" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="currentColor" d="M8 2h2v4h4V2h2v4h1.5A2.5 2.5 0 0 1 20 8.5V12a8 8 0 0 1-6 7.75V22h-4v-2.25A8 8 0 0 1 4 12V8.5A2.5 2.5 0 0 1 6.5 6H8V2Zm-1 8.5a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Zm8 0a.75.75 0 0 0 1.5 0 .75.75 0 0 0-1.5 0Z"/>
      </svg>
      <span>PlugBio</span>
    </a>

    <nav class="nav-right">
      <?php if ($me): ?>
        <a class="<?= $navClass('feed') ?> hide-sm" href="<?= e(asset('feed')) ?>">Discover</a>
        <a class="<?= $navClass('dashboard') ?>" href="<?= e(asset('dashboard')) ?>">Dashboard</a>
        <a class="<?= $navClass('analytics') ?> hide-sm" href="<?= e(asset('analytics')) ?>">Analytics</a>

        <div class="nav-user">
          <button class="avatar-btn" id="navAvatarBtn" aria-haspopup="menu" aria-expanded="false" aria-label="Open user menu">
            <?php if ($avatar): ?>
              <img class="avatar-img" src="<?= e(thumb_url($avatar, 96)) ?>" alt="">
            <?php else: ?>
              <span class="avatar-img" aria-hidden="true"><?= e($initial) ?></span>
            <?php endif; ?>
          </button>
          <div class="menu" id="navMenu" hidden>
            <a href="<?= e(asset('pages/new')) ?>">New page</a>
            <a href="<?= e($myProfileUrl) ?>">My profile</a>
            <a href="<?= e(asset('profile')) ?>">Edit profile</a>
            <a href="<?= e(asset('settings')) ?>">Settings</a>
            <hr>
            <form method="post" action="<?= e(asset('logout')) ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <button type="submit">Log out</button>
            </form>
          </div>
        </div>
      <?php else: ?>
        <a class="<?= $navClass('login') ?>" href="<?= e(asset('login')) ?>">Log in</a>
        <a class="btn btn-primary btn-sm" href="<?= e(asset('register')) ?>" style="margin-left:6px">Get started</a>
      <?php endif; ?>
    </nav>
  </header>

  <main class="site-main">
    <?php if (!$full_bleed): ?><div class="wrap"><?php endif; ?>
      <?= $content ?>
    <?php if (!$full_bleed): ?></div><?php endif; ?>
  </main>

  <?php if (!$full_bleed): ?>
  <footer class="site-footer">
    <span>© <?= date('Y') ?> PlugBio · Smart links for musicians</span>
  </footer>
  <?php endif; ?>

  <script>
  (function(){
    const btn = document.getElementById('navAvatarBtn');
    const menu = document.getElementById('navMenu');
    if (!btn || !menu) return;
    const open = (val) => { menu.hidden = !val; btn.setAttribute('aria-expanded', String(val)); };
    btn.addEventListener('click', (e)=>{ e.stopPropagation(); open(menu.hidden); });
    document.addEventListener('click', (e)=>{ if (!menu.hidden && !menu.contains(e.target)) open(false); });
    document.addEventListener('keydown', (e)=>{ if (e.key==='Escape') open(false); });
  })();
  </script>
</body>
</html>
