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
$contactPhoto    = ppf_site_image($pdo, 'contact', 'assets/images/site/contact-photo.jpg');
$contactEmail    = 'studio@pastpresentfurther.com';
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
  <title>Contact — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── CONTACT ─────────────────────────────────────────── */
.contact-layout{display:flex;flex-direction:column}
.contact-copy{order:1;padding:28px 7px 32px;max-width:520px}
.contact-copy p{margin-bottom:22px;font-size:10px;font-weight:400;line-height:1.9;letter-spacing:.02em;color:#333;text-transform:none}
.contact-meta{display:flex;flex-direction:column;gap:10px}
.contact-meta .label{display:inline-block;min-width:90px;color:#777;font-weight:500}
.contact-meta a{text-decoration:underline;text-underline-offset:3px;transition:opacity .15s}
.contact-meta a:hover{opacity:.5}
.page-photo{order:2;background:#fff;overflow:hidden}
.page-photo img{display:block;width:100%;height:auto}
@media(max-width:760px){.contact-copy{padding:20px 8px 28px}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <main class="contact-layout">
    <div class="page-photo">
      <img src="<?= htmlspecialchars($contactPhoto) ?>" alt="Contact" loading="lazy">
    </div>
    <section class="contact-copy">
      <p>For all communications including customer service, retail partnerships and press inquiries, please contact us at the below email. We kindly ask that you allow up to 2-4 business days for a response.</p>
      <div class="contact-meta">
        <div><span class="label">Email</span><a href="mailto:<?= htmlspecialchars($contactEmail) ?>"><?= htmlspecialchars($contactEmail) ?></a></div>
        <div><span class="label">Instagram</span><a href="https://instagram.com/<?= ltrim(htmlspecialchars($contactInstagram), '@') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($contactInstagram) ?></a></div>
      </div>
    </section>
  </main>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
</body>
</html>
