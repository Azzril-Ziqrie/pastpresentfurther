<?php
session_start();

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

// Inisialisasi variabel untuk navbar/menu (sama seperti index)
$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';

// Ambil instance PDO
$pdo = function_exists('db') ? db() : $pdo;

/* ── helpers untuk menu categories ───────────────────── */
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

/* ── Mengambil Data Blog ─────────────────────────────── */
$query = "SELECT * FROM blogs ORDER BY created_at DESC";
$stmt = $pdo->query($query);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title>FURTHER RESEARCH - <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── RESEARCH / JOURNAL ──────────────────────────────── */
.blog-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:2px}
.blog-card{display:block;opacity:0;animation:pageFadeIn .5s ease forwards}
.blog-card:nth-child(2){animation-delay:.05s}
.blog-card:nth-child(3){animation-delay:.1s}
.blog-card:nth-child(n+4){animation-delay:.15s}
.blog-image{aspect-ratio:3/2;background:#fff;overflow:hidden}
.blog-image img{width:100%;height:100%;object-fit:cover;transition:transform .6s var(--ease)}
.blog-card:hover .blog-image img{transform:scale(1.02)}
.blog-meta{display:flex;flex-direction:column;gap:6px;padding:10px 7px 28px}
.blog-title{font-size:10px;font-weight:700;line-height:1.4}
.blog-author{font-weight:500;color:#777}
.empty-state{padding:120px 14px;text-align:center;color:#999}
@media(max-width:900px){.blog-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:760px){
  .blog-grid{grid-template-columns:minmax(0,1fr);gap:0}
  .blog-meta{padding:12px 8px 32px}
}
@media(prefers-reduced-motion:reduce){.blog-card:hover .blog-image img{transform:none}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <main>
    <div class="blog-grid">
      <?php $hasPosts = false; while($row = $stmt->fetch(PDO::FETCH_ASSOC)): $hasPosts = true; ?>
        <a href="detail.php?id=<?= (int) $row['id'] ?>" class="blog-card">
          <div class="blog-image">
            <img src="<?= htmlspecialchars($row['main_image']) ?>" alt="<?= htmlspecialchars($row['title']) ?>" loading="lazy">
          </div>
          <div class="blog-meta">
            <h2 class="blog-title"><?= htmlspecialchars($row['title']) ?></h2>
            <?php if(!empty($row['author'])): ?>
              <div class="blog-author"><?= htmlspecialchars($row['author']) ?></div>
            <?php endif; ?>
          </div>
        </a>
      <?php endwhile; ?>
    </div>
    <?php if (!$hasPosts): ?>
      <div class="empty-state">No research yet.</div>
    <?php endif; ?>
  </main>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
</body>
</html>
