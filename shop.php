<?php
session_start();

// Tangkap pilihan currency dari URL
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

require_once __DIR__ . '/config/database.php';

$search   = isset($_GET['search'])   ? trim((string) $_GET['search'])   : '';
$category = isset($_GET['category']) ? trim((string) $_GET['category']) : '';

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

/* ── product query ───────────────────────────────────── */
// Menambahkan p.price_usd ke dalam query supaya harga USD ikut terbaca
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
        $params[':search1'] = $like; $params[':search2'] = $like; $params[':search3'] = $like; $params[':search4'] = $like;
    } elseif ($hasCategoryText) {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2 OR p.category LIKE :search3)";
        $params[':search1'] = $like; $params[':search2'] = $like; $params[':search3'] = $like;
    } else {
        $sql .= " AND (p.name LIKE :search1 OR p.slug LIKE :search2)";
        $params[':search1'] = $like; $params[':search2'] = $like;
    }
}
if ($category !== '') {
    if ($joinCategory) { $sql .= " AND c.slug = :category"; $params['category'] = $category; }
    elseif ($hasCategoryText) { $sql .= " AND LOWER(REPLACE(p.category, ' ', '-')) = :category"; $params['category'] = strtolower($category); }
}

// LOGIKA SORTING:
if ($hasSortOrder) {
    $sql .= " ORDER BY p.sort_order ASC, p.id DESC";
} else {
    $sql .= " ORDER BY p.id DESC";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Menyesuaikan getBasePrice untuk mengambil IDR / USD sesuai session (sama seperti index.php)
function getBasePrice(array $product, string $currency = 'IDR'): float {
    return $currency === 'USD' ? (float)($product['price_usd'] ?? 0) : (float)($product['price_idr'] ?? 0);
}

function getFinalPrice(array $product, string $currency = 'IDR'): float
{
    $base = getBasePrice($product, $currency);
    if (!isDiscountCurrentlyActive($product)) return $base;
    return max($base - ($base * ((float)($product['discount_percent'] ?? 0) / 100)), 0);
}

// Ganti formatRupiah -> formatCurrency supaya bisa format USD juga
function formatCurrency(float $amount, string $currency = 'IDR'): string {
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.');
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title><?= htmlspecialchars($brandName) ?> — Shop</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
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

/* ── SHOP FILTER ─────────────────────────────────────── */
.filter-form{display:flex;align-items:center;margin:0}
.category-select{appearance:none;-webkit-appearance:none;background:transparent;border:none;color:var(--ink);font:inherit;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:inherit;cursor:pointer;outline:none;padding:0 14px 0 0;background-image:linear-gradient(45deg,transparent 50%,var(--ink) 50%),linear-gradient(135deg,var(--ink) 50%,transparent 50%);background-position:calc(100% - 5px) 55%,calc(100% - 1px) 55%;background-size:4px 4px,4px 4px;background-repeat:no-repeat}
.chip .clear{margin-left:6px;text-decoration:underline;text-underline-offset:3px}
@media(max-width:760px){
  .filter-form{padding:0 14px;height:100%}
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

  <?php
    $isFiltered = $search !== '' || $category !== '';
    $categoryName = '';
    foreach ($categories as $cat) { if ($category === $cat['slug']) { $categoryName = (string)$cat['name']; break; } }
    $chipLabel = $categoryName !== '' ? $categoryName : ($category !== '' ? str_replace('-', ' ', $category) : 'All products');
    $chipLabel = strtoupper($chipLabel);
  ?>
  <section class="product-section" id="products" aria-label="Products">
    <div class="section-head">
      <span class="chip">
        <?= htmlspecialchars($chipLabel) ?>
        <?php if ($search !== ''): ?>
          / SEARCH: <?= htmlspecialchars(strtoupper($search)) ?>
          <a class="clear" href="shop.php<?= $category !== '' ? '?category=' . urlencode($category) : '' ?>">Clear</a>
        <?php endif; ?>
        <i></i>
      </span>
      <form class="filter-form" method="GET" action="shop.php">
        <?php if ($search !== ''): ?>
          <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
        <?php endif; ?>
        <label for="catSelect" class="sr-only" style="position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)">Category</label>
        <select id="catSelect" name="category" onchange="this.form.submit()" class="category-select" aria-label="Category">
          <option value="">All</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars($cat['slug']) ?>" <?= ($category === $cat['slug']) ? 'selected' : '' ?>>
              <?= htmlspecialchars(strtoupper($cat['name'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if (!$products): ?>
      <div class="empty-text">No products found.</div>
    <?php else: ?>
      <main class="product-grid">
        <?php foreach ($products as $product): ?>
          <?php
            $originalPrice = getBasePrice($product, $currentCurrency);
            $finalPrice    = getFinalPrice($product, $currentCurrency);
            $hasDiscount   = $finalPrice < $originalPrice;
            $isInStock     = (int)($product['is_in_stock'] ?? 1) === 1;
            $name          = htmlspecialchars(strtoupper((string)$product['name']));
            $thumb         = htmlspecialchars((string)$product['thumbnail']);
            $productId     = (int)$product['id'];
          ?>
          <div class="product-card<?= !$isInStock ? ' sold-out' : '' ?>" data-product-id="<?= $productId ?>" data-product-name="<?= $name ?>" data-product-price="<?= $finalPrice ?>" data-product-thumb="<?= $thumb ?>">
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
