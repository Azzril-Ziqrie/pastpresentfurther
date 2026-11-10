<?php
session_start();

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

// Ambil instance PDO
$pdo = function_exists('db') ? db() : $pdo;

// Inisialisasi variabel untuk navbar/menu (sama seperti index)
$search   = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';

// Ambil list kategori untuk menu
function tableExists(PDO $pdo, string $table): bool {
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

$categories = [];
if (tableExists($pdo, 'categories')) {
    try { 
        $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC"); 
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); 
    } catch (Throwable $e) {}
}

// ── Mengambil Data Detail Blog ────────────────────────
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = $pdo->prepare("SELECT * FROM blogs WHERE id = ?");
$stmt->execute([$id]);
$blog = $stmt->fetch(PDO::FETCH_ASSOC);

$galleries = [];
if ($blog) {
    // Mengambil data galeri untuk blog ini
    $stmt_gallery = $pdo->prepare("SELECT * FROM blog_gallery WHERE blog_id = ?");
    $stmt_gallery->execute([$id]);
    $galleries = $stmt_gallery->fetchAll(PDO::FETCH_ASSOC);
} else {
    http_response_code(404);
}
$pageTitle = $blog ? $blog['title'] : 'Not found';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── RESEARCH ARTICLE (same single-column layout as before) ── */
.blog-detail-container{max-width:800px;margin:0 auto;padding:60px 28px 100px;animation:pageFadeIn .5s ease both}
.blog-detail-header{margin-bottom:40px}
.blog-detail-header h1{font-size:10px;font-weight:700;line-height:1.5;margin-bottom:8px}
.blog-detail-meta{display:flex;flex-direction:column;gap:2px;color:#777;font-weight:500}
.blog-detail-meta .subtitle{color:var(--ink);font-weight:700}
.blog-detail-main-img{display:block;width:100%;height:60vh;object-fit:cover;background:#fff;margin:0 auto 40px}
.blog-detail-content{font-size:10px;font-weight:400;line-height:1.9;letter-spacing:.02em;color:#333;text-transform:none;margin-bottom:60px}
.blog-detail-extra{margin-top:40px}
.blog-detail-gallery{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:10px;width:100%;margin-bottom:20px;scroll-snap-type:x mandatory;scrollbar-width:none;-ms-overflow-style:none;cursor:grab}
.blog-detail-gallery::-webkit-scrollbar{display:none}
.blog-detail-gallery:has(img:only-child){justify-content:center}
.blog-detail-gallery.is-dragging{cursor:grabbing;scroll-snap-type:none}
.blog-detail-gallery img{flex:0 0 100%;width:100%;height:60vh;object-fit:cover;background:#fff;scroll-snap-align:center;user-select:none;-webkit-user-drag:none;pointer-events:none}
.blog-pagination{text-align:center;color:#aaa;margin-bottom:40px}
.empty-state{padding:120px 14px;text-align:center;color:#999}
@media(max-width:560px){
  .blog-detail-container{padding:40px 18px 80px}
  .blog-detail-main-img{height:auto;max-height:80vh;object-fit:contain}
  .blog-detail-gallery img{height:auto;max-height:80vh;object-fit:contain;flex:0 0 auto;max-width:100%}
}
@media(prefers-reduced-motion:no-preference){.blog-detail-gallery{scroll-behavior:smooth}.blog-detail-gallery.is-dragging{scroll-behavior:auto}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <?php if (!$blog): ?>
    <main><div class="empty-state">Article not found. <a href="research.php" style="text-decoration:underline">Back to research</a></div></main>
  <?php else: ?>
  <main class="blog-detail-container">
    <div class="blog-detail-header">
      <h1><?= htmlspecialchars($blog['title']) ?></h1>
      <div class="blog-detail-meta">
        <?php if (!empty($blog['subtitle'])): ?><span class="subtitle"><?= htmlspecialchars($blog['subtitle']) ?></span><?php endif; ?>
        <?php if (!empty($blog['author'])): ?><span><?= htmlspecialchars($blog['author']) ?></span><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($blog['main_image'])): ?>
      <img class="blog-detail-main-img" src="<?= htmlspecialchars($blog['main_image']) ?>" alt="<?= htmlspecialchars($blog['title']) ?>">
    <?php endif; ?>

    <div class="blog-detail-content"><?= nl2br(htmlspecialchars((string) $blog['content'])) ?></div>

    <?php if (count($galleries) > 0): ?>
      <div class="blog-detail-gallery" id="blogGallery">
        <?php foreach ($galleries as $i => $img): ?>
          <img src="<?= htmlspecialchars($img['image_url']) ?>" alt="Gallery Image <?= $i + 1 ?>" loading="lazy" draggable="false">
        <?php endforeach; ?>
      </div>
      <div class="blog-pagination"><span id="galleryCurrent">1</span>/<?= count($galleries) ?></div>
    <?php endif; ?>

    <?php if (!empty($blog['extra_text'])): ?>
      <div class="blog-detail-content blog-detail-extra"><?= nl2br(htmlspecialchars((string) $blog['extra_text'])) ?></div>
    <?php endif; ?>
  </main>
  <?php endif; ?>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
  <script>
  (function () {
    var s = document.getElementById('blogGallery');
    if (!s) return;
    var cur = document.getElementById('galleryCurrent');

    function sync() {
      if (!cur) return;
      cur.textContent = Math.min(s.children.length, Math.round(s.scrollLeft / s.clientWidth) + 1);
    }
    s.addEventListener('scroll', sync, { passive: true });

    // mouse drag (touch already swipes natively); releases snap to the nearest slide
    var down = false, x0 = 0, left0 = 0, moved = 0;
    s.addEventListener('pointerdown', function (e) {
      if (e.pointerType !== 'mouse') return;
      down = true; moved = 0; x0 = e.clientX; left0 = s.scrollLeft;
      s.classList.add('is-dragging'); s.setPointerCapture(e.pointerId);
    });
    s.addEventListener('pointermove', function (e) {
      if (!down) return;
      moved = e.clientX - x0;
      s.scrollLeft = left0 - moved;
    });
    function end() {
      if (!down) return;
      down = false;
      var w = s.clientWidth, i = Math.round(s.scrollLeft / w);
      // flick: a drag past 15% of the width moves one slide
      var from = Math.round(left0 / w);
      if (Math.abs(moved) > w * 0.15) i = from + (moved < 0 ? 1 : -1);
      i = Math.max(0, Math.min(s.children.length - 1, i));
      s.classList.remove('is-dragging');
      s.scrollTo({ left: i * w, behavior: 'smooth' });
    }
    s.addEventListener('pointerup', end);
    s.addEventListener('pointercancel', end);
    sync();
  })();
  </script>
</body>
</html>
