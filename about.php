<?php
session_start();

if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';
require_once __DIR__ . '/includes/site-images.php';
$contactPhoto    = ppf_site_image($pdo, 'about', 'assets/images/site/about-photo.jpg');
$contactEmail    = 'pastpresentfurther@gmail.com';
$contactInstagram = '@pastpresentfurther';

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
  <title>About — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── ABOUT ───────────────────────────────────────────── */
.page-text{max-width:820px;padding:28px 7px 28px}
.page-text p{margin-bottom:20px;font-size:10px;font-weight:400;line-height:1.9;letter-spacing:.02em;color:#333;text-transform:none}
.page-photo{background:#fff;overflow:hidden;aspect-ratio:16/9}
.page-photo img{width:100%;height:100%;object-fit:cover;object-position:center top}
@media(max-width:760px){.page-text{padding:20px 8px 20px}.page-photo{aspect-ratio:16/9}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <main>
    <section class="page-text">
      <p>PASTPRESENTFURTHER is a constant practice focused on continuous development inspired by the conditions coexist to create pieces that can adapt. With custom developed materials, fabrics and trimmings, entices a high attention to detail throughout every works.</p>
      <p>A Ready–To–Wear collection Enhanced Made in Indonesia, exploring the lines between form and function driven by experimental approaches. Showcase the beauty of form, craftmanship and aesthetics, while seamlessly blending them with contemporary technical design principles.</p>
      <p>Taking an approach to the past, present and further, we aim to define the highest and most meaningful rights of progress.</p>
    </section>
    <div class="page-photo">
      <img src="<?= htmlspecialchars($contactPhoto) ?>" alt="About" loading="lazy">
    </div>
  </main>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
</body>
</html>
