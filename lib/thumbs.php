<?php
// lib/thumbs.php — small WebP copies of uploaded images, so phones don't download full-size covers.
//
// A thumbnail lives at /uploads/t/{width}-{original file name}.webp. The first request for one reaches
// index.php, which calls make_thumb() to create the file. After that it is a plain static file: the web
// server (and Cloudflare) serve it without starting PHP.

const THUMB_WIDTHS = [96, 200, 400, 800];
const THUMB_PATTERN = '#^/uploads/t/(\d+)-([A-Za-z0-9._-]+\.(?:jpe?g|jfif|png|webp))\.webp$#i';

// URL of a thumbnail for a stored image address like "/uploads/cover_ab12.jpg".
// Anything that isn't one of our uploads (default avatar, GIFs, which may be animated) is returned unchanged.
function thumb_url(?string $uri, int $width): ?string {
  if (!$uri || !in_array($width, THUMB_WIDTHS, true)) return $uri;
  return preg_replace('~/uploads/([A-Za-z0-9._-]+\.(?:jpe?g|jfif|png|webp))$~i', "/uploads/t/$width-$1.webp", $uri);
}

// Create the thumbnail file. Returns its path, or null when it can't be made
// (unknown size, missing original, no image library); the caller then falls back to the original.
function make_thumb(string $uploadDir, int $width, string $name): ?string {
  $src = "$uploadDir/$name";
  if (!in_array($width, THUMB_WIDTHS, true) || !is_file($src) || !function_exists('imagewebp')) return null;

  $info = @getimagesize($src);
  if (!$info || $info[0] * $info[1] > 9_000_000) return null;      // over ~3000x3000: too large to resize within PHP's memory limit
  $im = @imagecreatefromstring((string)file_get_contents($src));
  if (!$im) return null;

  [$sw, $sh] = [imagesx($im), imagesy($im)];
  $w = min($width, $sw);                                          // never enlarge
  $h = max(1, (int)round($sh * $w / $sw));
  $out = imagecreatetruecolor($w, $h);
  imagealphablending($out, false);
  imagesavealpha($out, true);                                     // keep PNG transparency
  imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $sw, $sh);

  $dir = "$uploadDir/t";
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $dest = "$dir/$width-$name.webp";
  $tmp  = $dest . '.' . bin2hex(random_bytes(4)) . '.tmp';        // write-then-rename, so a half-written file is never served
  $ok = @imagewebp($out, $tmp, 80) && @rename($tmp, $dest);
  imagedestroy($im); imagedestroy($out);
  if (!$ok) { @unlink($tmp); return null; }
  return $dest;
}
