<?php
// Serve static files directly if your server rewrites everything to index.php (e.g. `php -S`)
$uri  = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';   // under `php -S` this can be a static file's path, so only trust index.php
$base   = str_ends_with($script, '/index.php') ? rtrim(str_replace('\\', '/', dirname($script)), '/') : '';
if ($base !== '' && str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
if (preg_match('#^/(assets|uploads)/#', $uri, $m)) {
  $types = [
    'css'=>'text/css','js'=>'application/javascript','png'=>'image/png','jpg'=>'image/jpeg','jpeg'=>'image/jpeg',
    'jfif'=>'image/jpeg','webp'=>'image/webp','gif'=>'image/gif','avif'=>'image/avif','svg'=>'image/svg+xml',
  ];
  if ($m[1] === 'uploads') unset($types['css'], $types['js'], $types['svg']);

  $root = realpath(__DIR__ . '/' . $m[1]);
  $path = realpath(__DIR__ . rawurldecode($uri));
  $ct   = $types[strtolower(pathinfo((string)$path, PATHINFO_EXTENSION))] ?? null;
  if ($root && $path && $ct && str_starts_with($path, $root . DIRECTORY_SEPARATOR) && is_file($path)) {
    header('Content-Type: ' . $ct);
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
  }

  // A thumbnail that doesn't exist yet: make it now. From the next request on it's a static file.
  require_once __DIR__ . '/lib/thumbs.php';
  if (preg_match(THUMB_PATTERN, $uri, $t)) {
    $made = make_thumb(__DIR__ . '/uploads', (int)$t[1], $t[2]);
    if ($made) {
      header('Content-Type: image/webp');
      header('Cache-Control: public, max-age=31536000, immutable');
      header('X-Content-Type-Options: nosniff');
      readfile($made);
      exit;
    }
    if (is_file(__DIR__ . '/uploads/' . $t[2])) {   // can't resize here (e.g. no image library): use the original
      header('Location: ' . $base . '/uploads/' . $t[2], true, 302);
      exit;
    }
  }
  http_response_code(404);
  exit;
}

require_once __DIR__ . '/config.php';

// ---- exact routes
route('/', 'routes/feed.php');
route('/feed', 'routes/feed.php');
route('/dashboard', 'routes/dashboard.php');
route('/login', 'routes/login.php');
route('/register', 'routes/register.php');
route('/logout', 'routes/logout.php');
route('/pages/new', 'routes/pages_new.php');
route('/profile', 'routes/profile_edit.php');
route('/settings', 'routes/account_settings.php');
route('/analytics', 'routes/analytics.php');
route('/health', 'routes/health.php');
route('/robots.txt', 'routes/robots.php');
route('/sitemap.xml', 'routes/sitemap.php');
route('/favicon.ico', 'routes/favicon.php');

// ---- pages (slugs or numeric ids) — SPECIFIC FIRST
route_regex('#^/pages/([^/]+)/edit$#',   'routes/pages_edit.php',   ['page_id' => 1]);
route_regex('#^/pages/([^/]+)/delete$#', 'routes/pages_delete.php', ['page_id' => 1]);

// Public page URLs
route_regex('#^/s/([^/]+)$#', 'routes/page_public.php', ['page_key' => 1]);
route_regex('#^/@([^/]+)/([^/]+)$#', 'routes/page_public.php', ['handle' => 1, 'page_key' => 2]);

// Outbound link clicks (tracked) and share images
route_regex('#^/go/(\d+)/(\d+)$#', 'routes/go.php', ['page_id' => 1, 'link_index' => 2]);
route_regex('#^/og/([^/]+?)(?:\.png)?$#', 'routes/og.php', ['og_key' => 1]);

// Public profile
route_regex('#^/u/([^/]+)$#', 'routes/profile_view.php', ['handle' => 1]);

route();
