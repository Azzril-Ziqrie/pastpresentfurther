<?php
session_start();

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/page-content.php';

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';
require_once __DIR__ . '/includes/site-images.php';
$contactPhoto    = ppf_site_image($pdo, 'contact', 'assets/images/site/contact-photo.jpg');
$pageContent     = ppf_page_load($pdo, 'contact');

/* ── categories (untuk menu navbar) ──────────────────── */
$categories = [];
try {
    $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC");
    $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title>Contact — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <link rel="stylesheet" href="assets/css/static-pages.css?v=<?= filemtime(__DIR__ . '/assets/css/static-pages.css') ?>">
  <style>
/* ── CONTACT: foto (gaya teks ada di static-pages.css) ───── */
.page-photo{order:2;background:var(--media);overflow:hidden}
.page-photo img{display:block;width:100%;height:auto}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <main class="contact-layout">
    <div class="page-photo">
      <img src="<?= htmlspecialchars($contactPhoto) ?>" alt="Contact" loading="lazy">
    </div>
    <?= ppf_page_render('contact', $pageContent) ?>
  </main>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
</body>
</html>
