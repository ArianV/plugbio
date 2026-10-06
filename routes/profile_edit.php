<?php
// routes/profile_edit.php
require_once __DIR__ . '/../config.php';

require_auth();
csrf_check();

$pdo  = db();
$user = current_user();

// normalize POST → bool or null
function bool_from_post($v): ?bool {
  if ($v === null || $v === '') return null;
  $v = strtolower((string)$v);
  if (in_array($v, ['1','true','t','yes','on','public'], true))  return true;
  if (in_array($v, ['0','false','f','no','off','private'], true)) return false;
  return null;
}
function socials_to_json(array $in): string {
  $out = [];
  foreach ($in as $k=>$v) {
    $v = trim((string)$v);
    if ($v !== '') $out[$k] = $v;
  }
  return json_encode($out, JSON_UNESCAPED_SLASHES);
}

$notice = null; $upload_err = null;

// ---------- handle POST ----------
// (The username/handle is changed in Settings, which enforces the rules and rate limit.)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  [$new_avatar, $upload_err] = save_uploaded_image('avatar', 'avatar');

  $sets = [
    'display_name = :d',
    'bio = :b',
    'socials_json = :sj',
    'updated_at = NOW()',
  ];
  $par = [
    ':d'  => trim($_POST['display_name'] ?? ''),
    ':b'  => trim($_POST['bio'] ?? ''),
    ':sj' => socials_to_json([
      'website'    => $_POST['website']    ?? '',
      'instagram'  => $_POST['instagram']  ?? '',
      'twitter'    => $_POST['twitter']    ?? '',
      'tiktok'     => $_POST['tiktok']     ?? '',
      'soundcloud' => $_POST['soundcloud'] ?? '',
      'spotify'    => $_POST['spotify']    ?? '',
    ]),
    ':id' => (int)$user['id'],
  ];
  if ($new_avatar) {
    $sets[] = 'avatar_uri = :a';
    $par[':a'] = $new_avatar;
  }
  $public = bool_from_post($_POST['profile_public'] ?? null);
  if ($public !== null) {
    $sets[] = 'profile_public = :pub';
    $par[':pub'] = $public ? 1 : 0;
  }

  $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($par);

  $notice = $upload_err ? 'Profile saved, but the new avatar was not.' : 'Profile saved';
  $user = current_user(true);
}

// ---------- view ----------
$title   = 'Edit profile · PlugBio';
$avatar  = $user['avatar_uri'] ?: asset('assets/avatar-default.svg');
$public  = !empty($user['profile_public']);
$sj      = json_decode($user['socials_json'] ?? '{}', true) ?: [];
$socialFields = [
  'website'    => ['Website',        'https://yoursite.com'],
  'instagram'  => ['Instagram',      'https://instagram.com/you'],
  'twitter'    => ['Twitter / X',    'https://x.com/you'],
  'tiktok'     => ['TikTok',         'https://tiktok.com/@you'],
  'soundcloud' => ['SoundCloud',     'https://soundcloud.com/you'],
  'spotify'    => ['Spotify artist', 'https://open.spotify.com/artist/…'],
];

ob_start(); ?>
<div class="narrow" style="max-width:760px">
  <div class="page-head">
    <div>
      <h1>Edit profile</h1>
      <p>This is what fans see at <a href="<?= e(asset('u/' . $user['handle'])) ?>">/u/<?= e($user['handle']) ?></a></p>
    </div>
  </div>

  <?php if ($notice): ?><div class="notice ok"><?= e($notice) ?></div><?php endif; ?>
  <?php if ($upload_err): ?><div class="notice err"><?= e($upload_err) ?></div><?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <div class="card">
      <h3>Basics</h3>
      <div class="row pfp-edit">
        <img src="<?= e(thumb_url($avatar, 200)) ?>" alt="Current avatar">
        <div style="flex:1">
          <label for="avatar">Avatar</label>
          <input type="file" id="avatar" name="avatar" accept="image/jpeg,image/png,image/webp,image/gif">
        </div>
      </div>
      <div class="form-grid">
        <div class="row">
          <label for="display_name">Display name</label>
          <input type="text" id="display_name" name="display_name" value="<?= e($user['display_name'] ?? '') ?>" placeholder="Lil Foaf" required>
        </div>
        <div class="row">
          <label>Username</label>
          <div class="input-prefix">
            <span class="inline-pill">@</span>
            <input type="text" value="<?= e($user['handle'] ?? '') ?>" readonly>
          </div>
          <div class="hint"><a href="<?= e(asset('settings')) ?>">Change it in Settings</a></div>
        </div>
      </div>
      <div class="row">
        <label for="bio">Bio</label>
        <textarea id="bio" name="bio" placeholder="Tell listeners who you are…"><?= e($user['bio'] ?? '') ?></textarea>
      </div>
      <div class="row" style="margin:0">
        <div class="status-row">
          <label for="profile_public">Profile visibility</label>
          <span class="pill <?= $public ? 'pill-public' : 'pill-private' ?>"><?= $public ? 'Public' : 'Private' ?></span>
        </div>
        <select id="profile_public" name="profile_public">
          <option value="1" <?= $public ? 'selected' : '' ?>>Public: anyone can see your profile</option>
          <option value="0" <?= !$public ? 'selected' : '' ?>>Private: only you</option>
        </select>
      </div>
    </div>

    <div class="card">
      <h3>Links</h3>
      <div class="form-grid">
        <?php foreach ($socialFields as $key => [$label, $ph]): ?>
          <div class="row">
            <label for="s-<?= $key ?>"><?= e($label) ?></label>
            <input type="text" id="s-<?= $key ?>" name="<?= $key ?>" value="<?= e($sj[$key] ?? '') ?>" placeholder="<?= e($ph) ?>">
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="form-actions" style="margin-top:18px">
      <button type="submit" class="btn btn-primary">Save profile</button>
      <a class="link" href="<?= e(asset('u/' . $user['handle'])) ?>">View profile</a>
    </div>
  </form>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
