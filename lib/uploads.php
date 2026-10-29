<?php
// lib/uploads.php — the one place user images get written to disk

const UPLOAD_IMAGE_TYPES = [
  IMAGETYPE_JPEG => 'jpg',
  IMAGETYPE_PNG  => 'png',
  IMAGETYPE_GIF  => 'gif',
  IMAGETYPE_WEBP => 'webp',
];

/**
 * Save an uploaded image from $_FILES[$field].
 * The file type is detected from its contents (never from the client's name),
 * and the stored name is random, so nothing executable can land in /uploads.
 *
 * @return array{0:?string,1:?string} [public URL or null, error message or null]
 *         [null, null] means no file was submitted.
 */
function save_uploaded_image(string $field, string $prefix): array {
  $f = $_FILES[$field] ?? null;
  if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return [null, null];
  if ($f['error'] !== UPLOAD_ERR_OK) {
    return [null, in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
      ? 'That image is too large.' : 'The upload failed, please try again.'];
  }
  if (!is_uploaded_file($f['tmp_name'])) return [null, 'The upload failed, please try again.'];
  if ($f['size'] > UPLOAD_MAX_MB * 1024 * 1024) return [null, 'That image is too large (max ' . UPLOAD_MAX_MB . ' MB).'];

  $info = @getimagesize($f['tmp_name']);
  $ext  = $info ? (UPLOAD_IMAGE_TYPES[$info[2]] ?? null) : null;
  if (!$ext) return [null, 'Please upload a JPG, PNG, GIF or WebP image.'];

  if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
  $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
  if (!move_uploaded_file($f['tmp_name'], rtrim(UPLOAD_DIR, '/\\') . '/' . $name)) {
    error_log('[upload] could not write to ' . UPLOAD_DIR);
    return [null, 'The server could not save the image.'];
  }
  return [rtrim(UPLOAD_URI, '/') . '/' . $name, null];
}

/**
 * Download an image (e.g. official cover art found by the link lookup) and store it like an upload.
 * Same rules as above: type from the contents, random name. HTTPS only, size-capped.
 *
 * @return array{0:?string,1:?string} [public URL or null, error message or null]
 */
function save_remote_image(string $url, string $prefix): array {
  if (!str_starts_with($url, 'https://')) return [null, 'Could not fetch that image.'];
  $max = UPLOAD_MAX_MB * 1024 * 1024;

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER  => true,
    CURLOPT_FOLLOWLOCATION  => true,
    CURLOPT_MAXREDIRS       => 2,
    CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_CONNECTTIMEOUT  => 4,
    CURLOPT_TIMEOUT         => 10,
    CURLOPT_MAXFILESIZE     => $max,
    CURLOPT_USERAGENT       => 'PlugBio/1.0 (+https://plugbio.me)',
  ]);
  $data = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if (!is_string($data) || $code !== 200 || $data === '' || strlen($data) > $max) return [null, 'Could not fetch that image.'];

  $info = @getimagesizefromstring($data);
  $ext  = $info ? (UPLOAD_IMAGE_TYPES[$info[2]] ?? null) : null;
  if (!$ext) return [null, 'That image is not a JPG, PNG, GIF or WebP.'];

  if (!is_dir(UPLOAD_DIR)) @mkdir(UPLOAD_DIR, 0775, true);
  $name = $prefix . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
  if (@file_put_contents(rtrim(UPLOAD_DIR, '/\\') . '/' . $name, $data) === false) {
    error_log('[upload] could not write to ' . UPLOAD_DIR);
    return [null, 'The server could not save the image.'];
  }
  return [rtrim(UPLOAD_URI, '/') . '/' . $name, null];
}
