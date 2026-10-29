<?php
// lib/lookup.php — finds the same song on other platforms from one pasted link.
//
// I read the pasted link's own platform first to get the song's details: title, artist,
// length, explicit flag and, where the platform gives it, the ISRC (the recording's
// official code, the same on every store). Spotify and Deezer can be searched by ISRC,
// so those matches are exact. Apple Music and YouTube can only be searched by name, so
// every result has to pass the same checks before it's used: same title, same artist,
// about the same length, same explicit/clean version, and not a "Various Artists"
// compilation copy. Anything that doesn't pass is left out; a missing link is better
// than a wrong one.

const LOOKUP_CACHE_DIR = __DIR__ . '/../data/lookup-cache';
const LOOKUP_CACHE_TTL = 7 * 86400;   // a full result is kept a week
const LOOKUP_THIN_TTL  = 3600;        // a result that found almost nothing is retried after an hour (release day)

/* ---------------- plumbing ---------------- */

function lookup_http(string $url, array $headers = [], ?string $post = null): ?array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 4,
    CURLOPT_TIMEOUT        => 6,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_USERAGENT      => 'PlugBio/1.0 (+https://plugbio.me)',
  ]);
  if ($post !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
  }
  $body = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  if ($body === false) error_log('[lookup] ' . parse_url($url, PHP_URL_HOST) . ': ' . curl_error($ch));
  elseif ($code >= 400 && $code !== 404) error_log('[lookup] ' . parse_url($url, PHP_URL_HOST) . " answered $code: " . substr($body, 0, 200));
  curl_close($ch);
  if ($body === false || $code < 200 || $code >= 300) return null;
  $j = json_decode($body, true);
  return is_array($j) ? $j : null;
}

// Results are tiny JSON files (a couple of KB), one per pasted link.
function lookup_cache_get(string $key): ?array {
  $f = LOOKUP_CACHE_DIR . '/' . sha1($key) . '.json';
  if (!is_file($f)) return null;
  $j = json_decode((string)@file_get_contents($f), true);
  return (is_array($j) && ($j['_exp'] ?? 0) > time()) ? $j : null;
}
function lookup_cache_put(string $key, array $value, int $ttl): void {
  if (!is_dir(LOOKUP_CACHE_DIR)) @mkdir(LOOKUP_CACHE_DIR, 0770, true);
  $value['_exp'] = time() + $ttl;
  @file_put_contents(LOOKUP_CACHE_DIR . '/' . sha1($key) . '.json', json_encode($value, JSON_UNESCAPED_SLASHES), LOCK_EX);

  // Every so often I sweep out expired files so the folder never grows without limit.
  if (mt_rand(1, 50) === 1) {
    foreach (glob(LOOKUP_CACHE_DIR . '/*.json') ?: [] as $f) {
      if (filemtime($f) < time() - LOOKUP_CACHE_TTL) @unlink($f);
    }
  }
}

/* ---------------- matching ---------------- */

// Reduce a title or artist to bare letters/digits so "20 Min (feat. X) - Remastered" equals "20 min".
// Featured artists and edition tags differ between stores for the same recording, so they don't count.
// Versions that ARE different recordings (remix, live, sped up…) are kept, so they won't match the original.
function lookup_norm(string $s): string {
  $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES, 'UTF-8'));
  $s = str_replace(['&', '+'], ' and ', $s);
  $s = preg_replace('/[(\[][^)\]]*\b(feat|ft|featuring|with|prod|explicit|clean|remaster(ed)?|album version|original mix|official (audio|video|music video)|music video|audio)\b[^)\]]*[)\]]/u', ' ', $s);
  $s = preg_replace('/\s+-\s+(.*remaster(ed)?.*|explicit|clean|single version|album version)$/u', '', $s);
  $s = preg_replace('/\s(feat|ft|featuring|prod)\.?\s.*$/u', '', $s);
  // Strip accents for Latin titles; for other scripts (Japanese, Cyrillic…) keep the original letters.
  if (function_exists('iconv')) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($t !== false && substr_count($t, '?') === substr_count($s, '?')) $s = $t;
  }
  return preg_replace('/[^\p{L}\p{N}]+/u', '', $s);
}

function lookup_title_match(string $a, string $b): bool {
  $a = lookup_norm($a);
  return $a !== '' && $a === lookup_norm($b);
}

// "Lil Uzi Vert" matches "Lil Uzi Vert, Nicki Minaj" and "Nicki Minaj & Lil Uzi Vert", but not "Lil Uzi".
function lookup_artist_match(string $want, string $got): bool {
  $w = lookup_norm($want);
  if ($w === '') return false;
  if ($w === lookup_norm($got)) return true;
  $split = fn($s) => array_map('lookup_norm', preg_split('/\s*(?:,|&|\bx\b|\bfeat\.?|\bft\.?|\bfeaturing\b|\bwith\b)\s*/iu', $s));
  $wantParts = $split($want);
  $gotParts  = $split($got);
  return in_array($wantParts[0], $gotParts, true) || in_array($gotParts[0], $wantParts, true);
}

// Same recording? Title + artist always; length and explicit flag when both sides know them.
function lookup_same(array $song, string $title, string $artist, ?int $secs = null, ?bool $explicit = null): bool {
  if (!lookup_title_match($song['title'], $title))   return false;
  if (!lookup_artist_match($song['artist'], $artist)) return false;
  if ($song['duration'] && $secs && abs($song['duration'] - $secs) > 4) return false;
  if ($song['explicit'] !== null && $explicit !== null && $song['explicit'] !== $explicit) return false;
  return true;
}

function lookup_is_compilation(?string $albumArtist): bool {
  return $albumArtist !== null && preg_match('/^various( artists)?$/i', trim($albumArtist));
}

/* ---------------- reading the pasted link ---------------- */

// Each reader returns the song's details plus its own clean link, or null if it can't read it.
function lookup_read_source(string $url): ?array {
  if (preg_match('#(?:music|itunes)\.apple\.com/([a-z]{2})/(album|song)/(?:[^/?]+/)?(?:id)?(\d+)#i', $url, $m)) {
    parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
    $isTrack = $m[2] === 'song' || !empty($q['i']);
    return lookup_read_apple($isTrack ? (string)($q['i'] ?? $m[3]) : $m[3], strtolower($m[1]), $isTrack);
  }
  if (preg_match('#open\.spotify\.com/(?:intl-[a-z-]+/)?(track|album)/([A-Za-z0-9]{22})#', $url, $m)) {
    return lookup_read_spotify($m[1], $m[2]);
  }
  if (preg_match('#deezer\.com/(?:[a-z]{2}/)?(track|album)/(\d+)#', $url, $m)) {
    return lookup_read_deezer($m[1], $m[2]);
  }
  // The SoundCloud app shares short on.soundcloud.com links; I follow those to the real track first.
  if (preg_match('~^https?://on\.soundcloud\.com/[A-Za-z0-9]+~i', $url, $m)) {
    $url = lookup_follow_redirect($m[0]) ?? '';
  }
  if (preg_match('~^https?://(?:www\.|m\.)?soundcloud\.com/[^/?]+/[^?#]+~i', $url, $m)) {
    return lookup_read_soundcloud(preg_replace('~^https?://(?:www\.|m\.)?~i', 'https://', $m[0]));
  }
  if (preg_match('#(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#', $url, $m)) {
    return lookup_read_youtube($m[1]);
  }
  return null;
}

// Where a short share link ends up, without downloading the page. Only accepted if it lands on soundcloud.com.
function lookup_follow_redirect(string $url): ?string {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_NOBODY          => true,
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_MAXREDIRS       => 4,
    CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT  => 4,
    CURLOPT_TIMEOUT         => 6,
    CURLOPT_USERAGENT       => 'PlugBio/1.0 (+https://plugbio.me)',
  ]);
  curl_exec($ch);
  $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
  curl_close($ch);
  return preg_match('~^https://(?:www\.|m\.)?soundcloud\.com/~i', $final) ? $final : null;
}

function lookup_song_shape(string $kind, string $title, string $artist): array {
  return ['kind' => $kind, 'title' => $title, 'artist' => $artist, 'duration' => null, 'explicit' => null,
          'isrc' => null, 'upc' => null, 'cover' => null, 'links' => []];
}

function lookup_apple_url(string $url): string {
  return preg_replace('/[?&]uo=\d+$/', '', $url);
}

function lookup_read_apple(string $id, string $country, bool $isTrack): ?array {
  $j = lookup_http('https://itunes.apple.com/lookup?id=' . rawurlencode($id) . '&country=' . $country);
  $r = $j['results'][0] ?? null;
  if (!$r) return null;
  $art = !empty($r['artworkUrl100']) ? str_replace('100x100bb', '1000x1000bb', $r['artworkUrl100']) : null;

  if ($isTrack && ($r['wrapperType'] ?? '') === 'track') {
    $s = lookup_song_shape('track', $r['trackName'], $r['artistName']);
    $s['duration'] = isset($r['trackTimeMillis']) ? (int)round($r['trackTimeMillis'] / 1000) : null;
    $s['explicit'] = ($r['trackExplicitness'] ?? '') === 'explicit';
    $s['links']['apple'] = lookup_apple_url($r['trackViewUrl']);
  } elseif (!$isTrack && ($r['wrapperType'] ?? '') === 'collection') {
    $s = lookup_song_shape('album', $r['collectionName'], $r['artistName']);
    $s['explicit'] = ($r['collectionExplicitness'] ?? '') === 'explicit';
    $s['links']['apple'] = lookup_apple_url($r['collectionViewUrl']);
  } else {
    return null;
  }
  $s['cover'] = $art;
  return $s;
}

function lookup_read_spotify(string $type, string $id): ?array {
  $token = spotify_token();
  if (!$token) return null;
  $j = lookup_http("https://api.spotify.com/v1/{$type}s/$id", ["Authorization: Bearer $token"]);
  if (!$j || empty($j['name'])) return null;

  $s = lookup_song_shape($type, $j['name'], implode(', ', array_column($j['artists'] ?? [], 'name')));
  if ($type === 'track') {
    $s['duration'] = (int)round(($j['duration_ms'] ?? 0) / 1000) ?: null;
    $s['explicit'] = (bool)($j['explicit'] ?? false);
    $s['isrc']     = $j['external_ids']['isrc'] ?? null;
    $s['cover']    = $j['album']['images'][0]['url'] ?? null;
  } else {
    $s['upc']   = $j['external_ids']['upc'] ?? null;
    $s['cover'] = $j['images'][0]['url'] ?? null;
  }
  $s['links']['spotify'] = "https://open.spotify.com/$type/$id";
  return $s;
}

function lookup_read_deezer(string $type, string $id): ?array {
  $j = lookup_http("https://api.deezer.com/$type/$id");
  if (!$j || empty($j['title']) || isset($j['error'])) return null;

  $s = lookup_song_shape($type, $j['title'], $j['artist']['name'] ?? '');
  $s['explicit'] = (bool)($j['explicit_lyrics'] ?? false);
  if ($type === 'track') {
    $s['duration'] = (int)($j['duration'] ?? 0) ?: null;
    $s['isrc']     = $j['isrc'] ?? null;
    $s['cover']    = $j['album']['cover_xl'] ?? null;
  } else {
    $s['upc']   = $j['upc'] ?? null;
    $s['cover'] = $j['cover_xl'] ?? null;
  }
  $s['links']['deezer'] = "https://www.deezer.com/$type/$id";
  return $s;
}

// SoundCloud has no open API, but its oEmbed endpoint gives the title, uploader and artwork.
function lookup_read_soundcloud(string $url): ?array {
  $j = lookup_http('https://soundcloud.com/oembed?format=json&url=' . rawurlencode($url));
  if (!$j || empty($j['title'])) return null;

  $artist = trim((string)($j['author_name'] ?? ''));
  $title  = preg_replace('/\s+by\s+' . preg_quote($artist, '/') . '$/iu', '', trim($j['title']));
  // Small artists often upload as "Artist - Song"; if the left side is the uploader, the right side is the title.
  if (str_contains($title, ' - ')) {
    [$left, $right] = array_map('trim', explode(' - ', $title, 2));
    if (lookup_artist_match($artist, $left)) $title = $right;
  }
  $s = lookup_song_shape(str_contains($url, '/sets/') ? 'album' : 'track', $title, $artist);
  $s['cover'] = $j['thumbnail_url'] ?? null;
  $s['links']['soundcloud'] = strtok($url, '?');
  return $s;
}

function lookup_read_youtube(string $id): ?array {
  if (YOUTUBE_API_KEY === '') return null;
  $j = lookup_http('https://www.googleapis.com/youtube/v3/videos?part=snippet,contentDetails&id=' . $id . '&key=' . rawurlencode(YOUTUBE_API_KEY));
  $v = $j['items'][0] ?? null;
  if (!$v) return null;

  $channel = (string)$v['snippet']['channelTitle'];
  $title   = (string)$v['snippet']['title'];
  if (str_ends_with($channel, ' - Topic')) {
    // "Topic" channels are YouTube's automatic uploads of the store release: clean title, exact length.
    $artist = substr($channel, 0, -8);
    $s = lookup_song_shape('track', $title, $artist);
    $s['duration'] = lookup_iso_seconds($v['contentDetails']['duration'] ?? '');
  } elseif (str_contains($title, ' - ')) {
    [$artist, $title] = array_map('trim', explode(' - ', $title, 2));
    $title = trim(preg_replace('/[(\[][^)\]]*\b(official|video|audio|lyrics?|visuali[sz]er|hd|4k)\b[^)\]]*[)\]]/iu', '', $title));
    $s = lookup_song_shape('track', $title, $artist);
  } else {
    return null;   // no reliable way to tell the artist from the title
  }
  $s['cover'] = $v['snippet']['thumbnails']['maxres']['url'] ?? $v['snippet']['thumbnails']['high']['url'] ?? null;
  $s['links']['youtube'] = "https://www.youtube.com/watch?v=$id";
  return $s;
}

function lookup_iso_seconds(string $iso): ?int {
  if (!preg_match('/^PT(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/', $iso, $m)) return null;
  return ((int)($m[1] ?? 0)) * 3600 + ((int)($m[2] ?? 0)) * 60 + (int)($m[3] ?? 0);
}

/* ---------------- finding it elsewhere ---------------- */

function spotify_token(): ?string {
  if (SPOTIFY_CLIENT_ID === '' || SPOTIFY_CLIENT_SECRET === '') return null;
  $cached = lookup_cache_get('spotify-token');
  if ($cached) return $cached['token'];

  $j = lookup_http('https://accounts.spotify.com/api/token',
    ['Authorization: Basic ' . base64_encode(SPOTIFY_CLIENT_ID . ':' . SPOTIFY_CLIENT_SECRET),
     'Content-Type: application/x-www-form-urlencoded'],
    'grant_type=client_credentials');
  if (empty($j['access_token'])) return null;
  lookup_cache_put('spotify-token', ['token' => $j['access_token']], max(60, (int)($j['expires_in'] ?? 3600) - 120));
  return $j['access_token'];
}

function lookup_find_spotify(array $song): ?string {
  $token = spotify_token();
  if (!$token) return null;
  $type = $song['kind'];
  if ($type === 'track') {
    $q = $song['isrc'] ? 'isrc:' . $song['isrc'] : 'track:' . $song['title'] . ' artist:' . $song['artist'];
  } else {
    $q = $song['upc'] ? 'upc:' . $song['upc'] : 'album:' . $song['title'] . ' artist:' . $song['artist'];
  }
  $j = lookup_http('https://api.spotify.com/v1/search?type=' . $type . '&limit=10&market=US&q=' . rawurlencode($q),
                   ["Authorization: Bearer $token"]);

  foreach ($j[$type . 's']['items'] ?? [] as $it) {
    if (!$it) continue;
    $album   = $type === 'track' ? ($it['album'] ?? []) : $it;
    $artists = implode(', ', array_column($it['artists'] ?? [], 'name'));
    if (($album['album_type'] ?? '') === 'compilation') continue;
    if (lookup_is_compilation($album['artists'][0]['name'] ?? null)) continue;
    $ok = $type === 'track'
      ? lookup_same($song, $it['name'], $artists, (int)round(($it['duration_ms'] ?? 0) / 1000) ?: null, (bool)($it['explicit'] ?? false))
      : lookup_same($song, $it['name'], $artists);
    if ($ok) return $it['external_urls']['spotify'] ?? null;
  }
  return null;
}

// Apple's public search only ever returns the clean edit of an explicit song ("cleaned"),
// so when the original is wanted I go through the artist's album list to its explicit edition.
function lookup_find_apple(array $song): ?string {
  $isTrack = $song['kind'] === 'track';
  $j = lookup_http('https://itunes.apple.com/search?country=US&limit=25&entity=' . ($isTrack ? 'song' : 'album')
                 . '&term=' . rawurlencode($song['artist'] . ' ' . $song['title']));

  foreach ($j['results'] ?? [] as $r) {
    if (lookup_is_compilation($r['collectionArtistName'] ?? null)) continue;
    if ($isTrack) {
      if (!empty($r['collectionArtistName']) && !lookup_artist_match($song['artist'], $r['collectionArtistName'])) continue;
      $secs = isset($r['trackTimeMillis']) ? (int)round($r['trackTimeMillis'] / 1000) : null;
      if (!lookup_same($song, $r['trackName'] ?? '', $r['artistName'] ?? '', $secs)) continue;
      $exp = $r['trackExplicitness'] ?? '';
    } else {
      if (!lookup_same($song, $r['collectionName'] ?? '', $r['artistName'] ?? '')) continue;
      $exp = $r['collectionExplicitness'] ?? '';
    }

    // Unknown (e.g. pasted from SoundCloud) counts as wanting the original, not the radio edit.
    if ($exp === 'cleaned' && $song['explicit'] !== false) {
      $url = lookup_apple_explicit_edition($r, $isTrack);
      if ($url) return $url;
      continue;
    }
    if ($song['explicit'] === true  && $exp !== 'explicit') continue;
    if ($song['explicit'] === false && $exp === 'explicit') continue;
    return lookup_apple_url($isTrack ? $r['trackViewUrl'] : $r['collectionViewUrl']);
  }
  return null;
}

// From a clean edit's search result to the same track/album on the explicit edition of its album.
function lookup_apple_explicit_edition(array $clean, bool $isTrack): ?string {
  if (empty($clean['artistId']) || empty($clean['collectionName'])) return null;
  $albums = lookup_http('https://itunes.apple.com/lookup?country=US&entity=album&limit=200&id=' . (int)$clean['artistId']);

  foreach ($albums['results'] ?? [] as $a) {
    if (($a['wrapperType'] ?? '') !== 'collection' || ($a['collectionExplicitness'] ?? '') !== 'explicit') continue;
    if (($a['collectionName'] ?? '') !== $clean['collectionName']) continue;
    if (!$isTrack) return lookup_apple_url($a['collectionViewUrl']);

    $tracks = lookup_http('https://itunes.apple.com/lookup?country=US&entity=song&limit=200&id=' . (int)$a['collectionId']);
    foreach ($tracks['results'] ?? [] as $t) {
      if (($t['wrapperType'] ?? '') === 'track' && ($t['trackName'] ?? '') === $clean['trackName']
          && ($t['trackExplicitness'] ?? '') === 'explicit') {
        return lookup_apple_url($t['trackViewUrl']);
      }
    }
  }
  return null;
}

// Deezer also hands back the ISRC/UPC, which then makes the Spotify match exact.
function lookup_find_deezer(array &$song): ?string {
  $type = $song['kind'];
  $code = $type === 'track' ? $song['isrc'] : $song['upc'];
  $candidates = [];
  if ($code) {
    $j = lookup_http("https://api.deezer.com/$type/" . ($type === 'track' ? 'isrc:' : 'upc:') . rawurlencode($code));
    if ($j && !isset($j['error'])) $candidates[] = $j;
  }
  if (!$candidates) {
    $j = lookup_http("https://api.deezer.com/search/$type?limit=10&q=" . rawurlencode($song['artist'] . ' ' . $song['title']));
    $candidates = $j['data'] ?? [];
  }

  foreach ($candidates as $c) {
    $ok = $type === 'track'
      ? lookup_same($song, $c['title'] ?? '', $c['artist']['name'] ?? '', (int)($c['duration'] ?? 0) ?: null, isset($c['explicit_lyrics']) ? (bool)$c['explicit_lyrics'] : null)
      : lookup_same($song, $c['title'] ?? '', $c['artist']['name'] ?? '', null, isset($c['explicit_lyrics']) ? (bool)$c['explicit_lyrics'] : null);
    if (!$ok) continue;
    if ($type === 'track' && !$song['isrc'] && !empty($c['isrc'])) $song['isrc'] = $c['isrc'];
    if ($song['explicit'] === null && isset($c['explicit_lyrics'])) $song['explicit'] = (bool)$c['explicit_lyrics'];
    if (!$song['duration'] && !empty($c['duration']) && $type === 'track') $song['duration'] = (int)$c['duration'];
    if (!$song['cover']) $song['cover'] = $type === 'track' ? ($c['album']['cover_xl'] ?? null) : ($c['cover_xl'] ?? null);
    return $c['link'] ?? "https://www.deezer.com/$type/" . $c['id'];
  }
  return null;
}

// A YouTube search costs 100 of the 10,000 free daily quota units, so this runs last and only for single songs.
// Only the artist's own channel (official, VEVO or the auto-generated "Topic" one) is accepted.
function lookup_find_youtube(array $song): ?string {
  if (YOUTUBE_API_KEY === '' || $song['kind'] !== 'track') return null;
  $j = lookup_http('https://www.googleapis.com/youtube/v3/search?part=snippet&type=video&videoCategoryId=10&maxResults=8'
                 . '&q=' . rawurlencode($song['artist'] . ' ' . $song['title']) . '&key=' . rawurlencode(YOUTUBE_API_KEY));

  $artist  = lookup_norm(preg_split('/\s*(?:,|&|\bfeat\.?|\bft\.?)\s*/iu', $song['artist'])[0]);
  $title   = lookup_norm($song['title']);
  $version = '/\b(remix|live|sped|slowed|reverb|nightcore|8d|cover|instrumental|karaoke|acoustic|lyrics?)\b/iu';
  foreach ($j['items'] ?? [] as $it) {
    $vTitle  = html_entity_decode((string)($it['snippet']['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $channel = lookup_norm(preg_replace('/(\s-\s?topic|vevo|official)$/iu', '', (string)($it['snippet']['channelTitle'] ?? '')));
    if ($channel !== $artist) continue;
    if (!str_contains(lookup_norm($vTitle), $title)) continue;
    // A remix/live/lyric upload isn't the same recording unless the song's own title says so.
    if (preg_match($version, $vTitle) && !preg_match($version, $song['title'])) continue;
    return 'https://www.youtube.com/watch?v=' . $it['id']['videoId'];
  }
  return null;
}

/* ---------------- the whole lookup ---------------- */

/**
 * Look a pasted link up everywhere we can.
 * @return array{title:string,artist:string,cover:?string,links:list<array{label:string,url:string}>}|null
 *         null when the link itself couldn't be read.
 */
function lookup_song(string $url): ?array {
  $key = 'song:' . $url;
  $cached = lookup_cache_get($key);
  if ($cached) { unset($cached['_exp']); return $cached; }

  $song = lookup_read_source($url);
  if (!$song) return null;

  // Deezer goes first because it can fill in the ISRC that makes the Spotify match exact.
  $song['links']['deezer']  ??= lookup_find_deezer($song);
  $song['links']['spotify'] ??= lookup_find_spotify($song);
  $song['links']['apple']   ??= lookup_find_apple($song);
  $song['links']['youtube'] ??= lookup_find_youtube($song);

  $links = [];
  foreach (array_filter($song['links']) as $svc => $link) {
    $links[] = ['label' => service_label($svc), 'url' => $link];
  }
  $out = ['title' => $song['title'], 'artist' => $song['artist'], 'cover' => $song['cover'], 'links' => $links];
  lookup_cache_put($key, $out, count($links) > 2 ? LOOKUP_CACHE_TTL : LOOKUP_THIN_TTL);
  return $out;
}

// Covers are only downloaded if this session's own lookup found them, so the form can't be
// used to make the server fetch arbitrary URLs.
function save_found_cover(string $url): ?string {
  if ($url === '' || empty($_SESSION['lookup_covers'][$url])) return null;
  [$uri] = save_remote_image($url, 'cover');
  return $uri;
}
