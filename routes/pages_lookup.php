<?php
// routes/pages_lookup.php — the page form calls this when a link is pasted; returns the song's
// details and its links on other platforms as JSON.
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

function lookup_reply(int $code, array $body): never {
  http_response_code($code);
  echo json_encode($body, JSON_UNESCAPED_SLASHES);
  exit;
}

if (!current_user()) lookup_reply(401, ['ok' => false, 'error' => 'Please log in again.']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') lookup_reply(405, ['ok' => false, 'error' => 'POST only.']);
if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
  lookup_reply(422, ['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.']);
}

// Each lookup can hit several outside APIs, so one person gets 30 per 10 minutes.
$now = time();
$_SESSION['lookups'] = array_values(array_filter($_SESSION['lookups'] ?? [], fn($t) => $t > $now - 600));
if (count($_SESSION['lookups']) >= 30) {
  lookup_reply(429, ['ok' => false, 'error' => 'Too many lookups for now. Try again in a few minutes, or add links by hand.']);
}
$_SESSION['lookups'][] = $now;

$url = safe_http_url((string)($_POST['url'] ?? ''));
if (!$url) lookup_reply(400, ['ok' => false, 'error' => 'That doesn\'t look like a link.']);

// The lookup takes a few seconds; I release the session lock so the rest of the site isn't blocked meanwhile.
session_write_close();
$found = lookup_song($url);
if (!$found) lookup_reply(200, ['ok' => false, 'error' => 'Couldn\'t read that link, so add the other platforms by hand.']);

if ($found['cover']) {
  session_start();
  $covers = array_slice($_SESSION['lookup_covers'] ?? [], -19, null, true);
  $covers[$found['cover']] = true;
  $_SESSION['lookup_covers'] = $covers;
  session_write_close();
}

lookup_reply(200, ['ok' => true] + $found);
