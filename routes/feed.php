<?php
// routes/feed.php — home page: hero (logged out) + latest published pages

require_once __DIR__ . '/../config.php';

// Public feed; no auth required. Shows FEED_SIZE songs per page: /?page=2, /?page=3, …

// Ways to sort the feed: /?sort=recent (FEED_DEFAULT_SORT needs no parameter).
// Pages with equal views fall back to newest first, and the id at the end keeps the order stable from one page to the next.
const FEED_SORTS = [
  'views'  => ['label' => 'Most views', 'order' => 'views DESC, ts DESC, p.id DESC', 'blurb' => 'The most visited pages on PlugBio'],
  'recent' => ['label' => 'Newest',     'order' => 'ts DESC, p.id DESC',             'blurb' => 'The latest pages from artists on PlugBio'],
];
const FEED_DEFAULT_SORT = 'views';

$sort     = isset(FEED_SORTS[$_GET['sort'] ?? '']) ? $_GET['sort'] : FEED_DEFAULT_SORT;
$total    = (int)db()->query('SELECT count(*) FROM pages WHERE published')->fetchColumn();
$lastPage = max(1, (int)ceil($total / FEED_SIZE));
$pageNum  = max(1, (int)($_GET['page'] ?? 1));

// Address of a feed page in a given sort (works for both "/" and "/feed"); defaults are left out of the URL
$feedUrl = function (int $n, ?string $s = null) use ($sort): string {
  $s ??= $sort;
  $query = array_filter(['sort' => $s === FEED_DEFAULT_SORT ? null : $s, 'page' => $n > 1 ? $n : null]);
  return asset(ltrim(request_path(), '/')) . ($query ? '?' . http_build_query($query) : '');
};

// Past the end (e.g. an old link after songs were removed)? Send them to the last page.
if ($pageNum > $lastPage) { header('Location: ' . $feedUrl($lastPage)); exit; }

// Published pages with their view counts. The ORDER BY comes from the fixed list above, never from user input.
$st = db()->prepare("
  SELECT
    p.id,
    p.title,
    p.artist_name,
    p.slug,
    p.cover_uri,
    COALESCE(p.updated_at, p.created_at) AS ts,
    u.handle,
    COALESCE(v.n, 0) AS views
  FROM pages p
  JOIN users u ON u.id = p.user_id
  LEFT JOIN (SELECT page_id, count(*) AS n FROM page_views GROUP BY page_id) v ON v.page_id = p.id
  WHERE p.published
  ORDER BY " . FEED_SORTS[$sort]['order'] . "
  LIMIT :n OFFSET :skip
");
$st->bindValue(':n', FEED_SIZE, PDO::PARAM_INT);
$st->bindValue(':skip', ($pageNum - 1) * FEED_SIZE, PDO::PARAM_INT);
$st->execute();
$pages = $st->fetchAll(PDO::FETCH_ASSOC);

// Page numbers to show in the pager: first, last, and the ones around the current page (null = a gap "…")
$pagerItems = [];
foreach (array_unique(array_filter([1, $pageNum - 1, $pageNum, $pageNum + 1, $lastPage], fn($n) => $n >= 1 && $n <= $lastPage)) as $n) {
  if ($pagerItems && $n - end($pagerItems) > 1) $pagerItems[] = null;
  $pagerItems[] = $n;
}

// helper: initial for placeholder
function initial_for(?string $s): string {
  $s = trim((string)$s);
  return $s !== '' ? strtoupper(mb_substr($s, 0, 1)) : '♪';
}

$me    = current_user();
$title = $me ? 'Discover · PlugBio' : 'PlugBio · Smart links for your music';
if ($pageNum > 1) $title = "Discover · Page $pageNum · PlugBio";
// The non-default sort shows the same songs in a different order, so keep it out of search results
$head = $sort === FEED_DEFAULT_SORT ? '' : '<meta name="robots" content="noindex,follow">' . "\n";
$query = array_filter(['sort' => $sort === FEED_DEFAULT_SORT ? null : $sort, 'page' => $pageNum > 1 ? $pageNum : null]);
meta_set([
  // "/" and "/feed" are the same page; tell search engines which address to use (each feed page has its own)
  'url'    => asset($query ? '?' . http_build_query($query) : ''),
  'jsonld' => [
    '@context'    => 'https://schema.org',
    '@type'       => 'WebSite',
    'name'        => 'PlugBio',
    'url'         => asset(''),
    'description' => 'Free smart links for musicians: one page per release with every streaming platform, plus click analytics.',
  ],
]);
$play  = '<svg viewBox="0 0 24 24"><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z"/></svg>';

ob_start();
?>
<?php if (!$me && $pageNum === 1): ?>
  <section class="hero">
    <div class="eyebrow"><b>●</b> One link for every platform</div>
    <h1>Share your music <span class="gradient-text">everywhere</span>, with one link.</h1>
    <p>Build a beautiful landing page for every release. Fans pick their favorite streaming service, and you see what they click.</p>
    <div class="cta-row">
      <a class="btn btn-primary btn-lg" href="<?= e(asset('register')) ?>">Create your first page</a>
      <a class="btn btn-lg" href="#discover">Discover Songs</a>
    </div>
    <div class="services" aria-label="Supported services">
      <span>Spotify</span><span>Apple Music</span><span>YouTube</span><span>SoundCloud</span><span>TIDAL</span><span>Deezer</span><span>Bandcamp</span>
    </div>
  </section>
<?php endif; ?>

<section id="discover">
  <div class="section-head">
    <div>
      <h2>Discover</h2>
      <div class="muted small">
        <?= e(FEED_SORTS[$sort]['blurb']) ?><?= $lastPage > 1 ? ' · page ' . $pageNum . ' of ' . $lastPage : '' ?>
      </div>
    </div>
    <div class="section-tools">
      <div class="sort-tabs" role="group" aria-label="Sort songs by">
        <?php foreach (FEED_SORTS as $key => $opt): ?>
          <a class="sort-tab<?= $key === $sort ? ' current' : '' ?>" href="<?= e($feedUrl(1, $key)) ?>#discover"
             <?= $key === $sort ? 'aria-current="true"' : '' ?>><?= e($opt['label']) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if ($me): ?><a class="btn btn-primary" href="<?= e(asset('pages/new')) ?>">+ New page</a><?php endif; ?>
    </div>
  </div>

  <?php if (!$pages): ?>
    <div class="empty-state">
      <h3>No releases yet</h3>
      <p>Be the first to share a song.</p>
      <a class="btn btn-primary" href="<?= e(asset($me ? 'pages/new' : 'register')) ?>">Create a page</a>
    </div>
  <?php else: ?>
    <div class="grid-cards">
      <?php foreach ($pages as $p): ?>
        <a class="card-link" href="<?= e(page_url($p)) ?>">
          <?php if (!empty($p['cover_uri'])): ?>
            <div class="card-media"><img src="<?= e($p['cover_uri']) ?>" alt="" loading="lazy"><span class="play"><?= $play ?></span></div>
          <?php else: ?>
            <div class="card-media placeholder"><?= e(initial_for($p['artist_name'] ?? $p['title'] ?? '')) ?><span class="play"><?= $play ?></span></div>
          <?php endif; ?>
          <div class="card-body">
            <div class="card-title"><?= e($p['title'] ?: 'Untitled') ?></div>
            <div class="card-meta"><?= e($p['artist_name'] ?: '@' . $p['handle']) ?></div>
          </div>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($lastPage > 1): ?>
      <nav class="pager" aria-label="Pages">
        <?php if ($pageNum > 1): ?>
          <a class="btn" rel="prev" href="<?= e($feedUrl($pageNum - 1)) ?>#discover">← Previous</a>
        <?php else: ?>
          <span class="btn" aria-disabled="true">← Previous</span>
        <?php endif; ?>

        <div class="pager-pages">
          <?php foreach ($pagerItems as $n): ?>
            <?php if ($n === null): ?>
              <span class="pager-gap">…</span>
            <?php elseif ($n === $pageNum): ?>
              <span class="pager-num current" aria-current="page"><?= $n ?></span>
            <?php else: ?>
              <a class="pager-num" href="<?= e($feedUrl($n)) ?>#discover" aria-label="Page <?= $n ?>"><?= $n ?></a>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>

        <?php if ($pageNum < $lastPage): ?>
          <a class="btn btn-primary" rel="next" href="<?= e($feedUrl($pageNum + 1)) ?>#discover">Next →</a>
        <?php else: ?>
          <span class="btn" aria-disabled="true">Next →</span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layout.php';
