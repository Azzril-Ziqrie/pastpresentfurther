<?php
session_start();


if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';
// Pengunjung baru (belum pernah memilih currency) → popup currency dibuka otomatis.
$currencyChosen  = isset($_SESSION['currency']);

require_once __DIR__ . '/config/database.php';

$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

// Batasi panjang search supaya tidak bikin URL/Query panjang & tidak rentan error
if (strlen($search) > 100) { $search = substr($search, 0, 100); }

$brandName       = 'PASTPRESENTFURTHER';
$brandLogo       = 'assets/images/brand/logo.png';
$customHeroImage = 'assets/images/site/home-hero.jpg';

/* ── helpers ─────────────────────────────────────────── */
function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE '{$table}'");
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) { return false; }
}

function tableColumns(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");
        return array_map(static fn($row) => $row['Field'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/* ── schema detection ────────────────────────────────── */
$productColumns     = tableColumns($pdo, 'products');
$hasCategoriesTable = tableExists($pdo, 'categories');
$hasCategoryId      = in_array('category_id',  $productColumns, true);
$hasCategoryText    = in_array('category',      $productColumns, true);
$hasSortOrder       = in_array('sort_order',    $productColumns, true); 

$joinCategory   = $hasCategoriesTable && $hasCategoryId;
$categorySelect = $joinCategory
    ? "c.name AS category_name, c.slug AS category_slug"
    : ($hasCategoryText
        ? "p.category AS category_name, LOWER(REPLACE(p.category, ' ', '-')) AS category_slug"
        : "NULL AS category_name, NULL AS category_slug");

// Deteksi stok untuk tulisan Sold Out
$hasProductSizes = tableExists($pdo, 'product_sizes');
$hasMainStock    = in_array('stock', $productColumns, true);

$stockSelect = "1 AS is_in_stock"; // fallback
if ($hasProductSizes && $hasMainStock) {
    $stockSelect = "(p.stock > 0 OR IFNULL((SELECT SUM(stock) FROM product_sizes WHERE product_id = p.id AND is_active = 1), 0) > 0) AS is_in_stock";
} elseif ($hasProductSizes) {
    $stockSelect = "(IFNULL((SELECT SUM(stock) FROM product_sizes WHERE product_id = p.id AND is_active = 1), 0) > 0) AS is_in_stock";
} elseif ($hasMainStock) {
    $stockSelect = "(p.stock > 0) AS is_in_stock";
}

/* ── hero slides ─────────────────────────────────────── */
$heroSlides = [];
if (tableExists($pdo, 'hero_slides')) {
    try {
        $heroStmt = $pdo->query(
            "SELECT id, title, subtitle, button_text, button_link, image
               FROM hero_slides
              WHERE is_active = 1
              ORDER BY sort_order ASC, id ASC"
        );
        $heroSlides = $heroStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[index.php] Hero slides query failed: ' . $e->getMessage());
        $heroSlides = [];
    }
}
if (!$heroSlides) {
    // Fallback ke gambar hero statis kalau tabel/slide belum ada
    $heroSlides = [[
        'id'          => 0,
        'title'       => null,
        'subtitle'    => null,
        'button_text' => null,
        'button_link' => null,
        'image'       => $customHeroImage,
    ]];
}

/* ── product query ───────────────────────────────────── */
// Menambahkan p.price_usd ke dalam query
$sql = "
    SELECT p.id, p.name, p.slug, p.price_idr, p.price_usd,
           p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
           p.thumbnail, {$categorySelect}, {$stockSelect}
    FROM products p
";
if ($joinCategory) $sql .= " LEFT JOIN categories c ON p.category_id = c.id";
$sql .= " WHERE p.is_active = 1";

$params = [];
if ($search !== '') {
    $like = '%' . $search . '%';
    if ($joinCategory) {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2 OR c.name LIKE :search3 OR c.slug LIKE :search4)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
        $params[':search3'] = $like;
        $params[':search4'] = $like;
    } elseif ($hasCategoryText) {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2 OR p.category LIKE :search3)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
        $params[':search3'] = $like;
    } else {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2)";
        $params[':search1'] = $like;
        $params[':search2'] = $like;
    }
}
if ($category !== '') {
    if ($joinCategory) {
        $sql .= " AND c.slug = :category";
        $params[':category'] = $category;
    } elseif ($hasCategoryText) {
        $sql .= " AND LOWER(REPLACE(p.category, ' ', '-')) = :category";
        $params[':category'] = strtolower($category);
    }
}

if ($hasSortOrder) {
    $sql .= " ORDER BY p.sort_order ASC, p.id DESC";
} else {
    $sql .= " ORDER BY p.id DESC";
}

$products = [];
try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Tambahkan baris ini untuk memfilter produk yang sold out
    $products = array_filter($products, function($product) {
        return (int)($product['is_in_stock'] ?? 1) === 1;
    });
} catch (Throwable $e) {
    // Jangan biarkan halaman crash — tampilkan log dan lanjut dengan $products kosong
    error_log('[index.php] Product query failed: ' . $e->getMessage());
}

/* ── categories ──────────────────────────────────────── */
$categories = [];
if ($joinCategory) {
    try { $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
} elseif ($hasCategoryText) {
    try { $catStmt = $pdo->query("SELECT DISTINCT category AS name, LOWER(REPLACE(category, ' ', '-')) AS slug FROM products WHERE category IS NOT NULL AND category <> '' ORDER BY category ASC"); $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
}

/* ── payment popup ───────────────────────────────────── */
$paymentUploaded    = isset($_GET['payment_uploaded']) && $_GET['payment_uploaded'] === '1';
$orderUploaded      = isset($_GET['order'])         ? trim((string) $_GET['order'])        : '';
$invoiceEmailStatus  = isset($_GET['invoice_email']) ? trim((string) $_GET['invoice_email']) : '';

$uploadedOrder = null;
if ($paymentUploaded && $orderUploaded !== '') {
    $popupStmt = $pdo->prepare("SELECT order_number, payment_status FROM orders WHERE order_number = :order_number LIMIT 1");
    $popupStmt->execute(['order_number' => $orderUploaded]);
    $uploadedOrder = $popupStmt->fetch(PDO::FETCH_ASSOC);
}

/* ── pricing helpers ─────────────────────────────────── */
function isDiscountCurrentlyActive(array $product): bool
{
    if ((int)($product['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($product['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($product['discount_start_at'])) { $s = strtotime((string)$product['discount_start_at']); if ($s !== false && $now < $s) return false; }
    if (!empty($product['discount_end_at']))   { $e = strtotime((string)$product['discount_end_at']);   if ($e !== false && $now > $e) return false; }
    return true;
}

// Menyesuaikan getBasePrice untuk mengambil IDR / USD sesuai session
function getBasePrice(array $product, string $currency = 'IDR'): float { 
    return $currency === 'USD' ? (float)($product['price_usd'] ?? 0) : (float)($product['price_idr'] ?? 0); 
}

function getFinalPrice(array $product, string $currency = 'IDR'): float
{
    $base = getBasePrice($product, $currency);
    if (!isDiscountCurrentlyActive($product)) return $base;
    return max($base - ($base * ((float)($product['discount_percent'] ?? 0) / 100)), 0);
}

// Menyesuaikan format text currency
function formatCurrency(float $amount, string $currency = 'IDR'): string { 
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.'); 
}

/* ── hero copy ───────────────────────────────────────── */
$heroTitle    = $category !== '' ? strtoupper(str_replace('-', ' ', $category)) : $brandName;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <!-- SEO Meta Tags -->
  <title><?= htmlspecialchars($brandName) ?></title>
  <meta name="description" content="PASTPRESENTFURTHER is a constant practice focused on continuous development inspired by coexisting conditions. Explore our premium contemporary fashion collection that blends form, function, and craftsmanship.">
  <meta name="keywords" content="PastPresentFurther, contemporary fashion, premium clothing, Indonesian fashion brand, sustainable fashion, avant-garde clothing">
  <!-- Geo Meta Tags -->
  <meta name="geo.position" content="-6.5950384;106.8166355">
  <meta name="geo.placename" content="Klapanunggal, Bogor, West Java">
  <meta name="geo.region" content="ID-JB">
  <!-- Open Graph / Facebook -->
  <meta property="og:type" content="website">
  <meta property="og:url" content="<?= 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
  <meta property="og:title" content="<?= htmlspecialchars($brandName) ?> - Premium Contemporary Fashion Brand">
  <meta property="og:description" content="PastPresentFurther is a constant practice focused on continuous development inspired by coexisting conditions. Explore our premium contemporary fashion collection that blends form, function, and craftsmanship.">
  <meta property="og:image" content="<?= 'https://' . $_SERVER['HTTP_HOST'] . '/assets/images/site/home-hero.jpg' ?>">
  <meta property="og:site_name" content="<?= htmlspecialchars($brandName) ?>">
  <!-- Twitter -->
  <meta property="twitter:card" content="summary_large_image">
  <meta property="twitter:url" content="<?= 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] ?>">
  <meta property="twitter:title" content="<?= htmlspecialchars($brandName) ?> - Premium Contemporary Fashion Brand">
  <meta property="twitter:description" content="PastPresentFurther is a constant practice focused on continuous development inspired by coexisting conditions. Explore our premium contemporary fashion collection that blends form, function, and craftsmanship.">
  <meta property="twitter:image" content="<?= 'https://' . $_SERVER['HTTP_HOST'] . '/assets/images/site/home-hero.jpg' ?>">
  <!-- Schema.org JSON-LD -->
  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "LocalBusiness",
    "name": "PastPresentFurther",
    "image": "https://<?= $_SERVER['HTTP_HOST'] ?>/assets/images/site/home-hero.jpg",
    "@id": "https://<?= $_SERVER['HTTP_HOST'] ?>",
    "url": "https://<?= $_SERVER['HTTP_HOST'] ?>",
    "telephone": "+62-251-8323xxx",
    "priceRange": "$$",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "S.L. Village Klapanunggal",
      "addressLocality": "Bogor",
      "addressRegion": "West Java",
      "postalCode": "16871",
      "addressCountry": "ID"
    },
    "geo": {
      "@type": "GeoCoordinates",
      "latitude": "-6.5950384",
      "longitude": "106.8166355"
    },
    "openingHoursSpecification": [
      {
        "@type": "OpeningHoursSpecification",
        "dayOfWeek": [
          "Monday",
          "Tuesday",
          "Wednesday",
          "Thursday",
          "Friday",
          "Saturday",
          "Sunday"
        ],
        "opens": "10:00",
        "closes": "19:00"
      }
    ],
    "sameAs": [
      "https://www.instagram.com/pastpresentfurther/",
      "https://www.facebook.com/pastpresentfurther"
    ]
  }
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── HERO / SLIDER ───────────────────────────────────── */
.hero{position:relative;background:#1a1c1a}
.hero-slider{position:relative;overflow:hidden}
.hero-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .7s ease;z-index:1}
.hero-slide.active{position:relative;opacity:1;visibility:visible;z-index:2}
.hero-media{position:relative;overflow:hidden}
.hero-media img{width:100%;height:calc(100vh - var(--bar-h));height:calc(100svh - var(--bar-h));min-height:480px;object-fit:cover;object-position:center;opacity:0;animation:heroFade .5s ease forwards}
.hero-copy{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;color:#fff;z-index:3;text-align:center;pointer-events:none}
.hero-title{font-size:clamp(16px,1.6vw,24px);font-weight:600;letter-spacing:-.01em;line-height:1.1}
.hero-sub{font-size:10px;font-weight:600;opacity:.8}
.hero-cta{pointer-events:auto;font-size:10px;font-weight:700;color:#fff;border-bottom:1px solid rgba(255,255,255,.6);margin-top:8px;transition:opacity .2s}
.hero-cta:hover{opacity:.55}

/* ── PRODUCTS ────────────────────────────────────────── */
.product-section{width:100%;background:#fff}
.product-grid{width:100%;display:grid;grid-template-columns:repeat(4,minmax(0,1fr))}
.product-card{position:relative;display:block;background:#fff;opacity:0;animation:productFadeIn .4s ease forwards}
.product-card:nth-child(1){animation-delay:.03s}
.product-card:nth-child(2){animation-delay:.06s}
.product-card:nth-child(3){animation-delay:.09s}
.product-card:nth-child(4){animation-delay:.12s}
.product-card:nth-child(n+5){animation-delay:.15s}
.product-image-link{position:relative;display:block;border-right:1px solid rgba(255,255,255,.75)}
.discount-label{position:absolute;top:8px;left:8px;z-index:3;font-size:8px;font-weight:700;color:var(--ink);background:#fff;padding:2px 6px}
.product-image{position:relative;background:#fff;aspect-ratio:2/3;overflow:hidden}
.product-image img{width:100%;height:100%;object-fit:contain;object-position:center;transition:transform .6s var(--ease)}
.product-card:hover .product-image img{transform:scale(1.02)}
.product-info-link{display:block}
.product-info{padding:8px 7px 12px;background:#fff}
.product-card h3{font-size:10px;font-weight:700;margin-bottom:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.product-price{font-weight:500}
.price-original{margin-right:8px;text-decoration:line-through;color:#aaa}
.sold-out-text{font-weight:700}
.empty-text{padding:80px 14px;text-align:center;color:#999}

/* ── PAYMENT POPUP (same material as currency) ───────── */
.payment-popup{position:fixed;inset:0;z-index:100;display:flex;align-items:center;justify-content:center;padding:20px}
.payment-popup__backdrop{position:absolute;inset:0;background:rgba(40,40,40,0);-webkit-backdrop-filter:blur(0);backdrop-filter:blur(0);transition:background .35s var(--ease),backdrop-filter .35s var(--ease),-webkit-backdrop-filter .35s var(--ease)}
.payment-popup--open .payment-popup__backdrop{background:rgba(40,40,40,.74);-webkit-backdrop-filter:blur(10px);backdrop-filter:blur(10px)}
.payment-popup__dialog{position:relative;width:100%;max-width:340px;color:#fff;text-align:center;opacity:0;transform:translateY(6px) scale(.98);transition:opacity .25s,transform .45s var(--ease)}
.payment-popup--open .payment-popup__dialog{opacity:1;transform:none}
.payment-popup--closing .payment-popup__backdrop{background:rgba(40,40,40,0);-webkit-backdrop-filter:blur(0);backdrop-filter:blur(0)}
.payment-popup--closing .payment-popup__dialog{opacity:0;transform:translateY(6px) scale(.98)}
.payment-popup__eyebrow{margin-bottom:6px;font-weight:500;opacity:.7}
.payment-popup__title{margin:0 0 12px;font-size:10px;font-weight:700}
.payment-popup__text{margin:0 auto 16px;font-weight:500;line-height:1.7;text-transform:none;opacity:.9}
.payment-popup__meta{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:0 0 12px;padding:0 8px;min-height:24px;border:1px solid #fff;font-weight:700}
.payment-popup__meta span{font-weight:500;opacity:.8}
.payment-popup__btn{width:100%;height:30px;border:none;background:#fff;color:var(--ink);font:inherit;font-weight:700;text-transform:uppercase;transition:background .15s}
.payment-popup__btn:hover{background:rgba(255,255,255,.85)}
.payment-popup .popup-close{position:fixed;top:calc(var(--bar-h) + 6px);right:10px;color:#fff}
@media(prefers-reduced-transparency:reduce){
  .payment-popup--open .payment-popup__backdrop{background:rgba(30,30,30,.95);-webkit-backdrop-filter:none;backdrop-filter:none}
}

/* ── RESPONSIVE (index) ──────────────────────────────── */
@media(max-width:900px){
  .product-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:760px){
  .product-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
  .product-image-link{border-right-width:2px}
  .product-info{padding:14px 8px 28px}
  .product-card h3{font-size:10px;margin-bottom:22px;white-space:normal}
  .product-price{font-size:10px}
  .discount-label{font-size:9px;top:6px;left:6px}
}

/* ── ANIMATIONS ──────────────────────────────────────── */
@keyframes heroFade{from{opacity:0}to{opacity:1}}
@keyframes productFadeIn{from{opacity:0}to{opacity:1}}
@media(prefers-reduced-motion:reduce){
  .product-card:hover .product-image img{transform:none}
}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php if ($paymentUploaded): ?>
  <div id="paymentPopup" class="payment-popup payment-popup--open" role="dialog" aria-modal="true" aria-labelledby="paymentPopupTitle">
    <div class="payment-popup__backdrop"></div>
    <div class="payment-popup__dialog">
      <button type="button" class="popup-close" id="closePaymentPopup" aria-label="Close">×</button>
      <div class="payment-popup__eyebrow"><?= htmlspecialchars($brandName) ?></div>
      <h2 class="payment-popup__title" id="paymentPopupTitle">Invoice Sent to Gmail</h2>
      <p class="payment-popup__text">Your payment proof has been submitted successfully. The invoice has been sent to your email, and your order is now waiting for verification.</p>
      <?php if ($orderUploaded !== ''): ?>
        <div class="payment-popup__meta">
          <span>Order Number</span>
          <strong><?= htmlspecialchars($orderUploaded) ?></strong>
        </div>
      <?php endif; ?>
      <div class="payment-popup__actions">
        <button type="button" id="closePaymentPopupSecondary" class="payment-popup__btn">Continue Shopping</button>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <section class="hero" aria-label="Featured">
    <div class="hero-slider" id="heroSlider">
      <?php foreach ($heroSlides as $i => $slide): ?>
        <div class="hero-slide<?= $i === 0 ? ' active' : '' ?>" data-slide-index="<?= $i ?>">
          <div class="hero-media">
            <img src="<?= htmlspecialchars((string)$slide['image']) ?>" alt="<?= htmlspecialchars((string)($slide['title'] ?? $brandName)) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
          </div>
          <div class="hero-copy">
            <?php if (!empty($slide['subtitle'])): ?>
              <div class="hero-sub"><?= htmlspecialchars((string)$slide['subtitle']) ?></div>
            <?php endif; ?>
            <?php if (!empty($slide['title'])): ?>
              <h1 class="hero-title"><?= htmlspecialchars((string)$slide['title']) ?></h1>
            <?php endif; ?>
            <?php if (!empty($slide['button_text'])): ?>
              <a href="<?= htmlspecialchars((string)($slide['button_link'] ?: '#')) ?>" class="hero-cta"><?= htmlspecialchars((string)$slide['button_text']) ?></a>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <?php
    $isFiltered = $search !== '' || $category !== '';
    $chipParts  = [];
    if ($category !== '') $chipParts[] = strtoupper(str_replace('-', ' ', $category));
    if ($search   !== '') $chipParts[] = 'SEARCH: ' . strtoupper($search);
    $chipLabel  = $chipParts ? implode(' / ', $chipParts) : 'ALL PRODUCTS';
  ?>
  <section class="product-section" id="products" aria-label="Products">
    <div class="section-head">
      <span class="chip"><?= htmlspecialchars($chipLabel) ?> <i></i></span>
      <a class="head-link" href="<?= $isFiltered ? 'index.php' : 'shop.php' ?>"><?= $isFiltered ? 'Clear' : 'View all' ?></a>
    </div>

    <?php if (!$products): ?>
      <?php
        $emptyParts = [];
        if ($search   !== '') $emptyParts[] = 'matching "<strong>' . htmlspecialchars(strtoupper($search)) . '</strong>"';
        if ($category !== '') $emptyParts[] = 'in <strong>' . htmlspecialchars(strtoupper(str_replace('-', ' ', $category))) . '</strong>';
        $emptyMessage = !empty($emptyParts)
          ? 'No products found ' . implode(' ', $emptyParts) . '.'
          : 'No active products yet.';
      ?>
      <div class="empty-text"><?= $emptyMessage ?></div>
    <?php else: ?>
      <main class="product-grid">
        <?php foreach ($products as $product): ?>
          <?php
            $originalPrice = getBasePrice($product, $currentCurrency);
            $finalPrice    = getFinalPrice($product, $currentCurrency);
            $hasDiscount   = $finalPrice < $originalPrice;
            $isInStock     = (int)($product['is_in_stock'] ?? 1) === 1;
            $slug          = htmlspecialchars((string)$product['slug']);
            $name          = htmlspecialchars(strtoupper((string)$product['name']));
            $thumb         = htmlspecialchars((string)$product['thumbnail']);
            $productId     = (int)$product['id'];
          ?>
          <div class="product-card" data-product-id="<?= $productId ?>" data-product-name="<?= $name ?>" data-product-price="<?= $finalPrice ?>" data-product-thumb="<?= $thumb ?>">
            <a class="product-image-link" href="product.php?slug=<?= urlencode((string)$product['slug']) ?>" tabindex="-1">
              <?php if ($hasDiscount && $isInStock): ?>
                <span class="discount-label">Sale</span>
              <?php endif; ?>
              <div class="product-image">
                <img src="<?= $thumb ?>" alt="<?= $name ?>" loading="lazy">
              </div>
            </a>
            <a class="product-info-link" href="product.php?slug=<?= urlencode((string)$product['slug']) ?>" aria-label="<?= $name ?>">
              <div class="product-info">
                <h3><?= $name ?></h3>
                <?php if (!$isInStock): ?>
                  <span class="sold-out-text">SOLD OUT</span>
                <?php else: ?>
                  <p class="product-price">
                    <?php if ($hasDiscount): ?>
                      <span class="price-original"><?= htmlspecialchars(formatCurrency($originalPrice, $currentCurrency)) ?></span>
                    <?php endif; ?>
                    <span class="price-final"><?= htmlspecialchars(formatCurrency($finalPrice, $currentCurrency)) ?></span>
                  </p>
                <?php endif; ?>
              </div>
            </a>
          </div>
        <?php endforeach; ?>
      </main>
    <?php endif; ?>
  </section>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
  <script>
  (function () {
    'use strict';

    function on(id, event, handler) {
      const el = document.getElementById(id);
      if (el) el.addEventListener(event, handler);
      return el;
    }

    /* ── FIRST VISIT: ask for currency once ───────────── */
    (function () {
      const chosen = <?= $currencyChosen ? 'true' : 'false' ?>;
      let seen = false;
      try { seen = localStorage.getItem('ppf_currency_seen') === '1'; } catch (e) {}
      if (chosen || seen || !window.PPF) return;
      try { localStorage.setItem('ppf_currency_seen', '1'); } catch (e) {}
      setTimeout(() => {
        PPF.openCurrency();
        const box = document.getElementById('currencySelect');
        const btn = document.getElementById('currencyToggle');
        if (box) box.classList.add('open');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }, 600);
    })();

    /* ── HERO SLIDER ───────────────────────────────────── */
    (function () {
      const slides = document.querySelectorAll('#heroSlider .hero-slide');
      if (slides.length <= 1) return;

      let current = 0;
      let autoTimer = null;
      const AUTOPLAY_MS = 5000;

      function goTo(index) {
        slides[current].classList.remove('active');
        current = (index + slides.length) % slides.length;
        slides[current].classList.add('active');
      }

      function nextSlide() { goTo(current + 1); }

      function startAutoplay() {
        stopAutoplay();
        autoTimer = setInterval(nextSlide, AUTOPLAY_MS);
      }
      function stopAutoplay() {
        if (autoTimer) { clearInterval(autoTimer); autoTimer = null; }
      }

      startAutoplay();
    })();

    /* ── PAYMENT POPUP ─────────────────────────────────── */
    (function () {
      const popup = document.getElementById('paymentPopup');
      if (!popup) return;
      const backdrop  = popup.querySelector('.payment-popup__backdrop');

      function clearUrlParams() {
        if (!window.history.replaceState) return;
        const url = new URL(window.location.href);
        ['payment_uploaded','order','invoice_email'].forEach(p => url.searchParams.delete(p));
        window.history.replaceState({}, document.title, url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : ''));
      }
      function closePopup() {
        popup.classList.remove('payment-popup--open');
        popup.classList.add('payment-popup--closing');
        setTimeout(() => { popup.remove(); clearUrlParams(); }, 250);
      }
      on('closePaymentPopup', 'click', closePopup);
      on('closePaymentPopupSecondary', 'click', closePopup);
      if (backdrop) backdrop.addEventListener('click', closePopup);
    })();

  })();
  </script>
</body>
</html>