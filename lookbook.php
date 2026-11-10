<?php
session_start();

// Tangkap pilihan currency dari URL
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';

/* ── helpers ─────────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

/* ── schema detection ────────────────────────────────── */
$tableReady = tableExists($pdo, 'lookbook_photos');

/* ── query photos grouped by year ────────────────────── */
$yearGroups = [];
if ($tableReady) {
    try {
        // Get distinct years + counts (descending)
        $yearStmt = $pdo->query(
            "SELECT year, COUNT(*) AS photo_count
             FROM lookbook_photos
             WHERE is_active = 1
             GROUP BY year
             ORDER BY year DESC"
        );
        $yearList = $yearStmt->fetchAll(PDO::FETCH_ASSOC);

        // For each year, fetch photos
        $photoStmt = $pdo->prepare(
            "SELECT id, year, photo_path, caption, sort_order
             FROM lookbook_photos
             WHERE year = :year AND is_active = 1
             ORDER BY sort_order ASC, id ASC"
        );

        // Year titles (one title per year, shown below the year heading)
        $yearTitles = [];
        if (tableExists($pdo, 'lookbook_year_titles')) {
            $titleRows = $pdo->query('SELECT year, title FROM lookbook_year_titles')->fetchAll(PDO::FETCH_ASSOC);
            foreach ($titleRows as $tr) {
                $yearTitles[(int) $tr['year']] = $tr['title'];
            }
        }

        foreach ($yearList as $y) {
            $yearVal = (int) $y['year'];
            $photoStmt->execute(['year' => $yearVal]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
            $yearGroups[] = [
                'year'  => $yearVal,
                'count' => (int) $y['photo_count'],
                'title' => $yearTitles[$yearVal] ?? null,
                'photos' => $photos,
            ];
        }
    } catch (Throwable $e) {
        $yearGroups = [];
    }
}

/* ── categories (for Shop All dropdown) ──────────────── */
$categories = [];
$hasCategoriesTable = tableExists($pdo, 'categories');
$productColumns     = [];
try { $productColumns = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
$hasCategoryId   = false;
$hasCategoryText = false;
foreach ($productColumns as $col) {
    if ($col['Field'] === 'category_id') $hasCategoryId = true;
    if ($col['Field'] === 'category')    $hasCategoryText = true;
}
$joinCategory = $hasCategoriesTable && $hasCategoryId;
if ($joinCategory) {
    try {
        $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try {
        $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}

/* natural size of each photo → exact aspect ratio, no layout jump while loading */
function lookbookDims(string $path): string
{
    $abs = __DIR__ . '/' . ltrim($path, '/');
    $info = is_file($abs) ? @getimagesize($abs) : false;
    return $info ? ' width="' . (int) $info[0] . '" height="' . (int) $info[1] . '"' : '';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title><?= htmlspecialchars($brandName) ?> — Lookbook</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── LOOKBOOK ────────────────────────────────────────── */
.lb-tabs{position:sticky;top:var(--bar-h);z-index:40;display:flex;gap:2px;overflow-x:auto;scrollbar-width:none;background:#fff}
.lb-tabs::-webkit-scrollbar{display:none}
.lb-tab{flex:0 0 auto;min-width:112px;height:30px;padding:0 14px;border:0;background:var(--chip);color:var(--ink);font:inherit;font-weight:700;text-align:left;transition:background .2s,color .2s}
.lb-tab:hover{background:#c6c8c8}
.lb-tab[aria-selected="true"]{background:var(--ink);color:#fff}
.lb-title{padding:8px 7px 0;font-weight:500;color:#777}
.lb-panel{position:relative;--lb-h:calc(100vh - var(--bar-h) - 30px);--lb-h:calc(100svh - var(--bar-h) - 30px);animation:pageFadeIn .4s ease both}
.lb-panel[hidden]{display:none}
.lb-strip{display:flex;gap:2px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;margin-top:2px}
.lb-strip::-webkit-scrollbar{display:none}
.lb-photo{flex:0 0 auto;scroll-snap-align:start;background:#fff}
.lb-photo img{display:block;height:var(--lb-h);width:auto;max-width:100vw;object-fit:contain}
.lb-arrow{position:absolute;top:50%;transform:translateY(-50%);z-index:5;width:44px;height:64px;border:0;background:transparent;color:var(--ink);display:flex;align-items:center;justify-content:center;transition:opacity .2s}
.lb-arrow svg{width:12px;height:12px;filter:drop-shadow(0 0 3px rgba(255,255,255,.7))}
.lb-arrow--prev{left:0}.lb-arrow--next{right:0}
.lb-arrow[disabled]{opacity:0;pointer-events:none}
.empty-state{padding:120px 14px;text-align:center;color:#999}
.empty-state .icon{display:block;font-size:30px;margin-bottom:12px;opacity:.5}
@media(max-width:760px){
  .lb-tab{min-width:30vw}
  .lb-photo{width:100%}
  .lb-photo img{width:100%;height:auto;max-width:none}
}
@media(prefers-reduced-motion:no-preference){.lb-strip{scroll-behavior:smooth}}
@media(prefers-reduced-motion:reduce){.lb-panel{animation:none}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <?php if (!$tableReady): ?>
    <div class="empty-state"><span class="icon">○</span>Lookbook coming soon.</div>
  <?php elseif (!$yearGroups): ?>
    <div class="empty-state"><span class="icon">○</span>No lookbook photos yet.</div>
  <?php else: ?>
    <nav class="lb-tabs" role="tablist" aria-label="Lookbook year">
      <?php foreach ($yearGroups as $i => $group): ?>
        <button type="button" class="lb-tab" role="tab" id="tab-<?= (int) $group['year'] ?>" data-year="<?= (int) $group['year'] ?>" aria-controls="year-<?= (int) $group['year'] ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"><?= (int) $group['year'] ?></button>
      <?php endforeach; ?>
    </nav>

    <?php foreach ($yearGroups as $i => $group): ?>
      <section class="lb-panel" id="year-<?= (int) $group['year'] ?>" role="tabpanel" aria-labelledby="tab-<?= (int) $group['year'] ?>"<?= $i === 0 ? '' : ' hidden' ?>>
        <?php if (!empty($group['title'])): ?><div class="lb-title"><?= htmlspecialchars($group['title']) ?></div><?php endif; ?>
        <button type="button" class="lb-arrow lb-arrow--prev" aria-label="Previous photo" disabled><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 1L3 6l5 5"/></svg></button>
        <div class="lb-strip">
          <?php foreach ($group['photos'] as $photo): $src = (string) $photo['photo_path']; ?>
            <figure class="lb-photo">
              <img src="<?= htmlspecialchars($src) ?>" alt="Lookbook <?= (int) $group['year'] ?>"<?= lookbookDims($src) ?><?= $i === 0 ? '' : ' loading="lazy"' ?> decoding="async">
            </figure>
          <?php endforeach; ?>
        </div>
        <button type="button" class="lb-arrow lb-arrow--next" aria-label="Next photo"><svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 1l5 5-5 5"/></svg></button>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
  <script>
  (function () {
    'use strict';
    var tabs   = document.querySelectorAll('.lb-tab');
    var panels = document.querySelectorAll('.lb-panel');
    if (!tabs.length) return;

    /* arrows per panel: step one photo, hide at the ends */
    panels.forEach(function (panel) {
      var strip = panel.querySelector('.lb-strip');
      var prev  = panel.querySelector('.lb-arrow--prev');
      var next  = panel.querySelector('.lb-arrow--next');
      function step() {
        var f = strip.querySelector('.lb-photo');
        return f ? f.getBoundingClientRect().width + 2 : strip.clientWidth;
      }
      function update() {
        prev.disabled = strip.scrollLeft <= 1;
        next.disabled = strip.scrollLeft + strip.clientWidth >= strip.scrollWidth - 1;
      }
      prev.addEventListener('click', function () { strip.scrollBy({ left: -step() }); });
      next.addEventListener('click', function () { strip.scrollBy({ left:  step() }); });
      strip.addEventListener('scroll', update, { passive: true });
      window.addEventListener('resize', update);
      panel._update = update;
    });

    function show(year, push) {
      var found = false;
      tabs.forEach(function (t) {
        var on = t.dataset.year === String(year);
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        if (on) { found = true; t.scrollIntoView({ inline: 'nearest', block: 'nearest' }); }
      });
      if (!found) return;
      panels.forEach(function (p) {
        var on = p.id === 'year-' + year;
        p.hidden = !on;
        if (on) { p.querySelector('.lb-strip').scrollLeft = 0; requestAnimationFrame(p._update); }
      });
      if (push && history.replaceState) history.replaceState(null, '', '#year-' + year);
    }

    tabs.forEach(function (t) { t.addEventListener('click', function () { show(t.dataset.year, true); }); });

    /* deep link: lookbook.php#year-2025 */
    var m = location.hash.match(/^#year-(\d+)$/);
    if (m) show(m[1], false); else requestAnimationFrame(panels[0]._update);
  })();
  </script>
</body>
</html>
