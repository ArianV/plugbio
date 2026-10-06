<?php
// ====== config.php

// =====================================================================
//  SETTINGS: the values you might want to change
// =====================================================================

// Public address of the site, used for absolute links (share previews, canonical URLs).
// On localhost the address is detected automatically, so this only matters in production.
const SITE_URL = 'https://plugbio.me';

// Where the SQLite database file lives (created automatically on first visit).
const DB_PATH = __DIR__ . '/data/plugbio.sqlite';

// Largest cover/avatar image people can upload, in MB.
// (The server's PHP upload limit must allow it too.)
const UPLOAD_MAX_MB = 8;

// Failed logins allowed per account before it's locked for a while.
const LOGIN_MAX_ATTEMPTS    = 10;
const LOGIN_LOCKOUT_MINUTES = 15;

// How often someone can change their username.
const USERNAME_CHANGES_ALLOWED = 2;
const USERNAME_CHANGE_DAYS     = 14;

// How many songs the home feed shows per page (more than this and it splits into page 2, 3, …).
const FEED_SIZE = 24;

// =====================================================================
//  Everything below is plumbing; you shouldn't need to edit it.
// =====================================================================

date_default_timezone_set('UTC'); // the database stores UTC

$__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

// ---------- Session ----------
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $__https,
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

// ---------- BASE_PATH / BASE_URL ----------
// BASE_PATH is the folder the app is served from: '' at a domain root, '/music' under XAMPP's htdocs/music.
// (Under `php -S` SCRIPT_NAME can be a static file's path, so it's only trusted when it ends in index.php.)
$__script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
define('BASE_PATH', str_ends_with($__script, '/index.php') ? rtrim(str_replace('\\', '/', dirname($__script)), '/') : '');

// The request path relative to the app, e.g. '/dashboard' for http://localhost/music/dashboard
function request_path(): string {
  $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
  if (BASE_PATH !== '' && str_starts_with($path, BASE_PATH)) $path = substr($path, strlen(BASE_PATH));
  return $path === '' ? '/' : $path;
}

// Absolute links use SITE_URL, except on localhost. The Host header is never trusted elsewhere.
$__host = $_SERVER['HTTP_HOST'] ?? '';
define('BASE_URL', preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $__host)
  ? ($__https ? 'https' : 'http') . '://' . $__host . BASE_PATH . '/'
  : rtrim(SITE_URL, '/') . '/');

// Small HTML escape
function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Public URL builder for assets/paths
function asset(string $path = ''): string {
  return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

// ---------- Database (SQLite) ----------
// One file on disk, no database server. Tables are created on first connect.
function db(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) return $pdo;

  if (!is_dir(dirname(DB_PATH))) @mkdir(dirname(DB_PATH), 0770, true);
  $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  // WAL lets readers and the writer work at the same time; busy_timeout waits instead of failing on a lock.
  $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 5000;');
  // NOW() in queries = current UTC time, same format as CURRENT_TIMESTAMP
  $pdo->sqliteCreateFunction('now', fn() => gmdate('Y-m-d H:i:s'), 0);

  if ((int)$pdo->query('PRAGMA user_version')->fetchColumn() < 1) {
    $pdo->exec(file_get_contents(__DIR__ . '/schema.sql'));
  }
  return $pdo;
}

// ---------- CSRF ----------
function csrf_token(): string {
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function csrf_check(): void {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', (string)$_POST['csrf']);
    if (!$ok) { http_response_code(422); exit('Your session expired. Please go back, refresh the page and try again.'); }
  }
}

// ---------- Auth ----------
// Cached per request; pass true to re-read after updating the users row.
function current_user(bool $refresh = false): ?array {
  static $user = false;
  if (!isset($_SESSION['user_id'])) return null;
  if ($user === false || $refresh) {
    $st = db()->prepare('SELECT * FROM users WHERE id=:id LIMIT 1');
    $st->execute([':id' => (int)$_SESSION['user_id']]);
    $user = $st->fetch() ?: null;
  }
  return $user;
}
function require_auth(): void {
  if (!current_user()) {
    $here = request_path();
    header('Location: ' . asset('login?next=' . rawurlencode($here)));
    exit;
  }
}
function login_user(int $id): void {
  session_regenerate_id(true);
  $_SESSION['user_id'] = $id;
}
// Only allow redirects back into this site (e.g. "/pages/new"), never "//evil.com".
function safe_next(?string $next, string $fallback): string {
  $next = (string)$next;
  if ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, '\\')) {
    return asset($next);
  }
  return asset($fallback);
}

// ---------- Uploads ----------
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URI', BASE_PATH . '/uploads');
if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);

require_once __DIR__ . '/lib/pages.php';
require_once __DIR__ . '/lib/uploads.php';
require_once __DIR__ . '/lib/thumbs.php';

// ---------- Helpers ----------
function page_cover(array $row): ?string {
  return $row['cover_uri'] ?? null;
}
function slugify(string $s, int $maxLen = 80): string {
  $s = html_entity_decode($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
  $s = str_ireplace(['&','@','+'], [' and ',' at ',' plus '], $s);
  if (function_exists('iconv')) { $t = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s); if ($t !== false) $s = $t; }
  $s = strtolower($s);
  $s = preg_replace('/[^a-z0-9]+/i', '-', $s);
  $s = preg_replace('/-+/', '-', $s);
  $s = trim($s, '-');
  if ($maxLen > 0 && strlen($s) > $maxLen) $s = rtrim(substr($s, 0, $maxLen), '-');
  return $s ?: 'page';
}
function time_ago($ts): string {
  if (!$ts) return '';
  $t = is_numeric($ts) ? (int)$ts : (int)strtotime((string)$ts);
  $d = max(0, time() - $t);
  foreach (['year'=>31536000,'month'=>2592000,'week'=>604800,'day'=>86400,'hour'=>3600,'min'=>60] as $name=>$secs) {
    if ($d >= $secs) { $n = (int)floor($d/$secs); return "$n {$name}".($n>1?'s':'')." ago"; }
  }
  return 'just now';
}

// --- META TAG HELPER (SEO/OG/Twitter) ---
$GLOBALS['__meta'] = $GLOBALS['__meta'] ?? [];
function meta_set(array $pairs): void {
  $GLOBALS['__meta'] = array_merge($GLOBALS['__meta'], $pairs);
}
function meta_get(string $k, string $default = ''): string {
  return $GLOBALS['__meta'][$k] ?? $default;
}

// --- BASIC BOT CHECK ---
function is_bot_ua(?string $ua): bool {
  if (!$ua) return true;
  $ua = strtolower($ua);
  foreach (['bot','crawl','spider','slurp','curl','fetch','httpclient','headless','phantom','monitor','preview'] as $needle) {
    if (str_contains($ua, $needle)) return true;
  }
  return false;
}

function session_key(): string {
  return substr(session_id() ?: bin2hex(random_bytes(8)), 0, 32);
}
function external_referrer_host(): ?string {
  $ref = parse_url($_SERVER['HTTP_REFERER'] ?? '', PHP_URL_HOST);
  return ($ref && $ref !== ($_SERVER['HTTP_HOST'] ?? '')) ? $ref : null;
}

// --- RECORD A PAGE VIEW (once per session per page per day; skips bots and the owner) ---
function record_page_view(int $page_id, int $owner_id): void {
  $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
  if (is_bot_ua($ua)) return;

  $viewer_id = current_user()['id'] ?? null;
  if ($viewer_id && (int)$viewer_id === $owner_id) return;

  $today = date('Y-m-d');
  if (($_SESSION['viewed'][$page_id] ?? null) === $today) return;
  $_SESSION['viewed'][$page_id] = $today;

  try {
    db()->prepare('INSERT INTO page_views (page_id, user_id, session_key, user_agent, ref_host)
                   VALUES (:pid, :uid, :sk, :ua, :rh)')
        ->execute([
          ':pid' => $page_id,
          ':uid' => $viewer_id,
          ':sk'  => session_key(),
          ':ua'  => mb_substr($ua, 0, 500),
          ':rh'  => external_referrer_host(),
        ]);
  } catch (Throwable $e) {
    error_log('[views] insert failed: '.$e->getMessage());
  }
}

// Render a friendly 404 inside the site layout and stop.
function not_found(string $msg = 'Page not found'): never {
  http_response_code(404);
  $title = 'Not found · PlugBio';
  $content = '<div class="narrow" style="max-width:480px;margin-top:56px;text-align:center">'
           . '<div class="gradient-text" style="font:700 88px/1 var(--display);letter-spacing:-.04em">404</div>'
           . '<h1 style="font-size:24px;margin-top:14px">' . e($msg) . '</h1>'
           . '<p class="muted">The link may be broken, or the page may have been removed.</p>'
           . '<a class="btn btn-primary" href="' . e(asset('')) . '">Back to home</a></div>';
  require __DIR__ . '/views/layout.php';
  exit;
}

// ---------- Router ----------
$GLOBALS['__ROUTES'] = $GLOBALS['__ROUTES'] ?? [];
$GLOBALS['__RREG']   = $GLOBALS['__RREG']   ?? [];

function route(?string $path = null, ?string $file = null) {
  if ($path === null) return route_dispatch(); // dispatch now
  $GLOBALS['__ROUTES'][$path] = $file;
}
function route_regex(string $pattern, string $file, array $groups = []): void {
  $GLOBALS['__RREG'][] = [$pattern, $file, $groups];
}
function route_dispatch(): void {
  $uri  = request_path();
  $path = rtrim($uri, '/') ?: '/';

  // exact match
  if (isset($GLOBALS['__ROUTES'][$path])) {
    require __DIR__ . '/' . ltrim($GLOBALS['__ROUTES'][$path], '/');
    return;
  }

  // regex match
  foreach ($GLOBALS['__RREG'] as [$pat, $file, $groups]) {
    if (preg_match($pat, $path, $m)) {
      foreach ($groups as $name => $idx) $GLOBALS[$name] = isset($m[$idx]) ? rawurldecode($m[$idx]) : null;
      require __DIR__ . '/' . ltrim($file, '/');
      return;
    }
  }

  not_found();
}

// ---------- Usernames ----------
function normalize_handle(string $h): string {
  return preg_replace('/[^a-z0-9_]/', '', strtolower(trim($h)));
}
function handle_is_valid(?string $h): bool {
  return $h !== null && (bool)preg_match('/^[a-z0-9_]{3,20}$/', $h);
}

function can_change_username(int $user_id): array {
  $q = db()->prepare("
    SELECT count(*) AS n, datetime(min(changed_at), :plus) AS next_at
    FROM username_changes
    WHERE user_id = :uid
      AND changed_at >= datetime('now', :minus)
  ");
  $q->execute([':uid' => $user_id, ':plus' => '+' . USERNAME_CHANGE_DAYS . ' days', ':minus' => '-' . USERNAME_CHANGE_DAYS . ' days']);
  $r = $q->fetch();
  $allowed = (int)$r['n'] < USERNAME_CHANGES_ALLOWED;
  return ['allowed' => $allowed, 'next_at' => $allowed ? null : $r['next_at']];
}

function record_username_change(int $user_id, ?string $old, string $new): void {
  db()->prepare("
    INSERT INTO username_changes (user_id, old_username, new_username, changed_ip)
    VALUES (:uid, :old, :new, :ip)
  ")->execute([
    ':uid' => $user_id,
    ':old' => $old,
    ':new' => $new,
    ':ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
  ]);
}
