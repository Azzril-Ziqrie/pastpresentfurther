<?php
session_start();

// Tangkap pilihan currency dari URL (sama seperti index.php)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

$slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';
if ($slug === '') { http_response_code(404); exit('Product not found.'); }

// FIX: $category dan $search sebelumnya tidak pernah didefinisikan di file ini,
// padahal dipakai di form search & currency dropdown menu mobile di bawah.
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$search   = isset($_GET['search']) ? trim($_GET['search']) : '';

/* ── brand constants (disamakan dengan index.php) ────── */
$brandName = 'PASTPRESENTFURTHER';
$brandLogo = 'assets/images/brand/logo.png';

// Default ke IDR jika belum ada session (sama seperti index.php)ac
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

/* ── helpers (mendukung multi-currency) ──────────────── */
function formatCurrency(float $amount, string $currency = 'IDR'): string
{
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.');
}

function tableExistsProd(PDO $pdo, string $table): bool
{
    try { $s = $pdo->prepare("SHOW TABLES LIKE :t"); $s->execute(['t' => $table]); return (bool) $s->fetchColumn(); }
    catch (Throwable $e) { return false; }
}

function columnExistsProd(PDO $pdo, string $table, string $column): bool
{
    try {
        $s = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
        );
        $s->execute(['t' => $table, 'c' => $column]);
        return ((int) $s->fetchColumn()) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/* ── product query ───────────────────────────────────── */
$hasComparePriceIdr = columnExistsProd($pdo, 'products', 'compare_price_idr');
$hasComparePriceUsd = columnExistsProd($pdo, 'products', 'compare_price_usd');
$hasPriceUsd        = columnExistsProd($pdo, 'products', 'price_usd');
$hasColorColumn     = columnExistsProd($pdo, 'products', 'color');
$hasCategorySize    = columnExistsProd($pdo, 'categories', 'has_size');
// Cek kolom size_chart_image agar aman jika migrasi belum dijalankan
$hasSizeChart       = columnExistsProd($pdo, 'products', 'size_chart_image');

// FIX: Deteksi juga kolom flash-sale discount (sama seperti index.php & shop.php),
// sebelumnya tidak pernah di-select di sini sehingga diskon persen tidak pernah kebawa ke product.php
$hasDiscountPercent = columnExistsProd($pdo, 'products', 'discount_percent');
$hasDiscountStart   = columnExistsProd($pdo, 'products', 'discount_start_at');
$hasDiscountEnd     = columnExistsProd($pdo, 'products', 'discount_end_at');
$hasDiscountActive  = columnExistsProd($pdo, 'products', 'is_discount_active');

// Deteksi juga kolom price_usd & compare_price_usd (sama seperti index.php)
$priceUsdSelect     = $hasPriceUsd        ? 'p.price_usd,'              : 'NULL AS price_usd,';
$compareUsdSelect   = $hasComparePriceUsd ? 'p.compare_price_usd,'      : 'NULL AS compare_price_usd,';
$compareSelect      = $hasComparePriceIdr ? 'p.compare_price_idr,'      : 'NULL AS compare_price_idr,';
$colorSelect        = $hasColorColumn     ? 'p.color,'                  : 'NULL AS color,';
$catSizeSelect      = $hasCategorySize    ? 'c.has_size AS category_has_size' : '0 AS category_has_size';
$sizeChartSelect    = $hasSizeChart       ? 'p.size_chart_image,'       : 'NULL AS size_chart_image,';
$discountPercentSelect = $hasDiscountPercent ? 'p.discount_percent,'    : 'NULL AS discount_percent,';
$discountStartSelect   = $hasDiscountStart   ? 'p.discount_start_at,'   : 'NULL AS discount_start_at,';
$discountEndSelect     = $hasDiscountEnd     ? 'p.discount_end_at,'     : 'NULL AS discount_end_at,';
$discountActiveSelect  = $hasDiscountActive  ? 'p.is_discount_active,'  : '0 AS is_discount_active,';

// UPDATE: Ditambahkan p.composition, p.care, dan size_chart_image
$productStmt = $pdo->prepare(
    "SELECT p.id, p.category_id, p.name, p.slug, {$colorSelect} p.description, p.composition, p.care, p.thumbnail, {$sizeChartSelect}
            p.price_idr, {$priceUsdSelect} {$compareSelect} {$compareUsdSelect}
            {$discountPercentSelect} {$discountStartSelect} {$discountEndSelect} {$discountActiveSelect} p.is_active,
            c.name AS category_name, c.slug AS category_slug, {$catSizeSelect}
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.slug = :slug AND p.is_active = 1
     LIMIT 1"
);
$productStmt->execute(['slug' => $slug]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product) { http_response_code(404); exit('Product not found.'); }


$categoryName = $product['category_name'] ?: 'Product';
$categorySlug = strtolower((string)($product['category_slug'] ?: ''));

if ($hasCategorySize) {
    $hasSizeSelector = (int)$product['category_has_size'] === 1;
} else {
    $hasSizeSelector = $categorySlug === 'fashion';
}

/* ── gallery images ──────────────────────────────────── */
$imageStmt = $pdo->prepare(
    'SELECT image_path FROM product_images
     WHERE product_id = :product_id
     ORDER BY sort_order ASC, id ASC'
);
$imageStmt->execute(['product_id' => $product['id']]);
$detailImages = $imageStmt->fetchAll(PDO::FETCH_COLUMN);

$galleryImages = [];
if (!empty($product['thumbnail'])) $galleryImages[] = $product['thumbnail'];
foreach ($detailImages as $p) {
    if ($p !== '' && !in_array($p, $galleryImages, true)) $galleryImages[] = $p;
}
if (empty($galleryImages)) $galleryImages[] = 'assets/images/placeholder.png';

/* ── sizes ───────────────────────────────────────────── */
$sizeStmt = $pdo->prepare(
    'SELECT ps.id AS product_size_id, s.name, s.sort_order, ps.stock, ps.is_active
     FROM product_sizes ps INNER JOIN sizes s ON s.id=ps.size_id
     WHERE ps.product_id=:pid ORDER BY s.sort_order ASC, s.name ASC'
);
$sizeStmt->execute(['pid' => $product['id']]);
$productSizes = $sizeStmt->fetchAll(PDO::FETCH_ASSOC);

// Sort natural (1, 2, 10) di level PHP, supaya konsisten sebelum dikirim ke frontend.
usort($productSizes, function ($a, $b) {
    $nameCmp = strnatcasecmp((string)$a['name'], (string)$b['name']);
    if ($nameCmp !== 0) return $nameCmp;
    return ((int)($a['sort_order'] ?? 0)) <=> ((int)($b['sort_order'] ?? 0));
});

$availableSizes = array_values(array_filter($productSizes, fn($s) => (int)$s['is_active']===1 && (int)$s['stock']>0));
$isSoldOut = count($availableSizes) === 0;
$selectedSize = $isSoldOut ? '' : $availableSizes[0]['name'];
$selectedProductSizeId = $isSoldOut ? null : (int)$availableSizes[0]['product_size_id'];

/* ── categories ──────────────────────────────────────── */
$categories = [];
try {
    if (tableExistsProd($pdo, 'categories')) {
        $catStmt = $pdo->query("SELECT name, slug FROM categories ORDER BY id ASC");
        $categories = $catStmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

/* ── pricing (mendukung IDR & USD seperti index.php) ── */
function getBasePriceProd(array $product, string $currency = 'IDR'): float
{
    return $currency === 'USD'
        ? (float)($product['price_usd'] ?? 0)
        : (float)($product['price_idr'] ?? 0);
}

function getComparePriceProd(array $product, string $currency = 'IDR'): float
{
    if ($currency === 'USD') {
        return (float)($product['compare_price_usd'] ?? 0);
    }
    return (float)($product['compare_price_idr'] ?? 0);
}

function isDiscountCurrentlyActiveProd(array $product): bool
{
    if ((int)($product['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($product['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($product['discount_start_at'])) { $s = strtotime((string)$product['discount_start_at']); if ($s !== false && $now < $s) return false; }
    if (!empty($product['discount_end_at']))   { $e = strtotime((string)$product['discount_end_at']);   if ($e !== false && $now > $e) return false; }
    return true;
}

function getFinalPriceProd(array $product, string $currency = 'IDR'): float
{
    $base = getBasePriceProd($product, $currency);
    if (!isDiscountCurrentlyActiveProd($product)) return $base;
    return max($base - ($base * ((float)($product['discount_percent'] ?? 0) / 100)), 0);
}

/* ── related products ────────────────────────────────── */
const RELATED_PRODUCTS_LIMIT = 4;

/**
 * Produk rekomendasi: kategori yang sama dulu, yang stoknya ada dulu,
 * lalu diisi produk kategori lain sampai $limit.
 *
 * @param string $extraColumns kolom opsional (harga USD, diskon) yang sudah diseleksi aman
 */
function fetchRelatedProducts(PDO $pdo, array $product, string $extraColumns, int $limit = RELATED_PRODUCTS_LIMIT): array
{
    $stmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.thumbnail, p.price_idr, {$extraColumns}
                EXISTS(SELECT 1 FROM product_sizes ps
                       WHERE ps.product_id = p.id AND ps.is_active = 1 AND ps.stock > 0) AS is_in_stock
         FROM products p
         WHERE p.is_active = 1 AND p.id <> :id AND p.thumbnail <> ''
         ORDER BY (p.category_id <=> :category_id) DESC, is_in_stock DESC, p.id DESC
         LIMIT :limit"
    );
    $stmt->bindValue('id', (int) $product['id'], PDO::PARAM_INT);
    $stmt->bindValue('category_id', $product['category_id'], $product['category_id'] === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$originalPrice = getBasePriceProd($product, $currentCurrency);
$basePrice     = getFinalPriceProd($product, $currentCurrency);
$comparePrice  = getComparePriceProd($product, $currentCurrency);

$isFlashSaleActive = isDiscountCurrentlyActiveProd($product);
$hasDiscount        = $isFlashSaleActive || ($comparePrice > 0 && $comparePrice > $basePrice);
$comparePriceToShow = $isFlashSaleActive ? $originalPrice : $comparePrice;

$displayPrice        = formatCurrency($basePrice, $currentCurrency);
$displayComparePrice = $hasDiscount ? formatCurrency($comparePriceToShow, $currentCurrency) : null;

$relatedProducts = fetchRelatedProducts(
    $pdo,
    $product,
    $priceUsdSelect . $discountPercentSelect . $discountStartSelect . $discountEndSelect . $discountActiveSelect
);

$hasColor = $hasColorColumn && trim((string)($product['color'] ?? '')) !== '';

// Supaya pilihan currency di header tetap membawa slug produk ini.
$currencyExtra = '&slug=' . urlencode($slug);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title><?= htmlspecialchars($product['name']) ?> — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/site.css?v=<?= filemtime(__DIR__ . '/assets/css/site.css') ?>">
  <style>
/* ── PRODUCT GALLERY ─────────────────────────────────── */
.pd-gallery-wrap{position:relative}
.pd-gallery{display:flex;gap:2px;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none;background:var(--media)}
.pd-gallery::-webkit-scrollbar{display:none}
.pd-slide{flex:0 0 calc((100% - 4px) / 3);scroll-snap-align:start;display:block;aspect-ratio:3/4;background:var(--media);overflow:hidden}
.pd-slide img{width:100%;height:100%;object-fit:contain;user-select:none;-webkit-user-drag:none;transition:transform .6s var(--ease)}
.pd-slide:hover img{transform:scale(1.02)}
.pd-count{display:none;position:absolute;right:8px;bottom:8px;padding:2px 6px;background:var(--bg);font-weight:700}

/* ── PRODUCT BAR ─────────────────────────────────────── */
.pd-bar{display:flex;justify-content:space-between;align-items:flex-start;gap:20px 40px;flex-wrap:wrap;padding:16px 7px 20px}
.pd-title{font-size:10px;font-weight:700}
.pd-price{display:flex;gap:10px;margin-top:6px;font-weight:500}
.pd-price .was{text-decoration:line-through;color:var(--faint)}
.pd-buy{display:flex;align-items:center;gap:24px;flex-wrap:wrap}
.pd-label{color:var(--muted);font-weight:500}
.pd-color{display:flex;align-items:center;gap:8px}
.pd-color i{width:22px;height:10px;background:var(--ink);display:inline-block}
.pd-sizes{display:flex;align-items:center;gap:12px}
.pd-size-group{display:flex;gap:6px}
.size-btn{min-width:32px;height:26px;padding:0 8px;background:var(--bg);color:var(--ink);border:1px solid var(--ink);font:inherit;font-weight:700;text-transform:uppercase;transition:background .15s,color .15s}
.size-btn:hover{background:var(--ink-hover)}
.size-btn.active{background:var(--ink);color:var(--bg)}
.pd-sold{font-weight:700}
.pd-add{height:34px;padding:0 32px;background:var(--ink);color:var(--bg);border:none;font:inherit;font-weight:700;text-transform:uppercase;transition:background .15s,opacity .15s,transform .1s}
.pd-add:hover:not(:disabled){background:var(--ink-strong)}
.pd-add:active:not(:disabled){transform:scale(.98);transition-duration:0s}
.pd-add:disabled{background:var(--faint);cursor:default}

/* ── PRODUCT DETAILS ─────────────────────────────────── */
.pd-details{max-width:560px;padding:0 7px 56px;font-weight:400;line-height:1.7;text-transform:none;letter-spacing:.02em;color:var(--text-2)}
.pd-desc{margin-bottom:22px}
.pd-rows{display:flex;flex-direction:column;gap:8px}
.pd-row{display:flex;gap:16px;align-items:baseline}
.pd-row dt{flex:0 0 150px;color:var(--ink);font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.pd-row dd{color:var(--muted);margin:0}
.pd-row a,.pd-row button{color:var(--muted);background:none;border:none;padding:0;font:inherit;text-align:left;text-decoration:underline;text-underline-offset:3px}
.pd-row a:hover,.pd-row button:hover{color:var(--ink)}

/* ── RELATED PRODUCTS ────────────────────────────────── */
.pd-related{padding:0 0 56px}
.pd-related__head{display:flex;justify-content:space-between;align-items:center;padding:8px 7px 12px}
.pd-related__head h2{font-size:10px;font-weight:700;text-transform:uppercase;margin:0}
.pd-related__track{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:2px}
.rel-card{display:block;background:var(--bg);color:inherit}
.rel-card__img{position:relative;aspect-ratio:2/3;background:var(--media);overflow:hidden}
.rel-card__img img{width:100%;height:100%;object-fit:contain;transition:transform .6s var(--ease)}
.rel-card:hover .rel-card__img img{transform:scale(1.02)}
.rel-card__sale{position:absolute;top:8px;left:8px;z-index:2;font-size:8px;font-weight:700;background:var(--bg);padding:2px 6px}
.rel-card__info{padding:8px 7px 12px}
.rel-card h3{margin:0 0 6px;font-size:10px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rel-card__price{margin:0;font-weight:500}
.rel-card__price .was{margin-right:8px;text-decoration:line-through;color:var(--faint)}
.rel-card__sold{font-weight:700}

/* ── SIZE CHART (same glass popup as account/currency) ── */
#modal-size-chart .account-popup__dialog{max-width:min(560px,92vw)}
#modal-size-chart img{width:100%;max-height:80vh;object-fit:contain;background:#fff}

/* ── RESPONSIVE ──────────────────────────────────────── */
@media(max-width:760px){
  .pd-slide{flex:0 0 100%;aspect-ratio:4/5}
  .pd-count{display:block}
  .pd-bar{flex-direction:column;gap:18px;padding:14px 8px 18px}
  .pd-buy{width:100%;flex-direction:column;align-items:stretch;gap:14px}
  .pd-sizes{justify-content:space-between}
  .pd-add{width:100%;height:40px}
  .pd-details{padding:0 8px 44px}
  .pd-row{flex-direction:column;gap:2px}
  .pd-row dt{flex:none}
  .pd-related__head{padding:8px 8px 12px}
  .pd-related__track{display:flex;overflow-x:auto;scroll-snap-type:x mandatory;-webkit-overflow-scrolling:touch;scrollbar-width:none}
  .pd-related__track::-webkit-scrollbar{display:none}
  .rel-card{flex:0 0 62%;scroll-snap-align:start}
}
@media(prefers-reduced-motion:reduce){.pd-slide:hover img,.rel-card:hover .rel-card__img img{transform:none}}
  </style>
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>
  <?php include __DIR__ . '/includes/site-header.php'; ?>

  <div class="section-head">
    <?php if ($categorySlug !== ''): ?>
      <a class="chip" href="index.php?category=<?= urlencode($categorySlug) ?>"><?= htmlspecialchars(strtoupper($categoryName)) ?> <i></i></a>
    <?php else: ?>
      <span class="chip"><?= htmlspecialchars(strtoupper($categoryName)) ?> <i></i></span>
    <?php endif; ?>
    <a class="head-link" href="shop.php">Back to shop</a>
  </div>

  <main>
    <div class="pd-gallery-wrap">
      <section class="pd-gallery" id="productGallery" aria-label="Product gallery">
        <?php foreach ($galleryImages as $i => $imagePath): ?>
          <a class="pd-slide" href="<?= htmlspecialchars($imagePath) ?>" target="_blank" rel="noopener">
            <img src="<?= htmlspecialchars($imagePath) ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
          </a>
        <?php endforeach; ?>
      </section>
      <?php if (count($galleryImages) > 1): ?>
        <span class="pd-count" id="galleryCount">1 / <?= count($galleryImages) ?></span>
      <?php endif; ?>
    </div>

    <div class="pd-bar">
      <div>
        <h1 class="pd-title"><?= htmlspecialchars($product['name']) ?></h1>
        <div class="pd-price">
          <?php if ($hasDiscount): ?><span class="was"><?= htmlspecialchars($displayComparePrice) ?></span><?php endif; ?>
          <span><?= htmlspecialchars($displayPrice) ?></span>
        </div>
      </div>

      <div class="pd-buy">
        <?php if ($hasColor): ?>
          <div class="pd-color"><span class="pd-label">Color</span> <?= htmlspecialchars((string)$product['color']) ?> <i></i></div>
        <?php endif; ?>

        <?php if ($hasSizeSelector && !$isSoldOut): ?>
          <div class="pd-sizes">
            <span class="pd-label">Size</span>
            <div class="pd-size-group" id="sizeBtnGroup" role="group" aria-label="Select size">
              <?php foreach ($availableSizes as $size): ?>
                <?php $isSelectedOpt = $selectedProductSizeId === (int)$size['product_size_id']; ?>
                <button type="button" class="size-btn<?= $isSelectedOpt ? ' active' : '' ?>"
                        data-id="<?= (int)$size['product_size_id'] ?>"
                        data-size="<?= htmlspecialchars($size['name']) ?>"
                        data-stock="<?= (int)$size['stock'] ?>"><?= htmlspecialchars($size['name']) ?></button>
              <?php endforeach; ?>
            </div>
          </div>
        <?php elseif ($isSoldOut): ?>
          <span class="pd-sold">Sold Out</span>
        <?php endif; ?>

        <button type="button" class="pd-add" id="addToCartBtn" <?= $isSoldOut ? 'disabled' : '' ?>>
          <span class="cart-btn__label"><?= $isSoldOut ? 'Sold Out' : 'Add to cart' ?></span>
        </button>
      </div>
    </div>

    <div class="pd-details">
      <?php if (trim((string)$product['description']) !== ''): ?>
        <div class="pd-desc"><p><?= nl2br(htmlspecialchars((string)$product['description'])) ?></p></div>
      <?php endif; ?>

      <dl class="pd-rows">
        <?php if (!empty($product['composition']) || !empty($product['care'])): ?>
          <div class="pd-row">
            <dt>Materials and Care</dt>
            <dd><?= htmlspecialchars((string)$product['composition']) ?> <?= htmlspecialchars((string)$product['care']) ?></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($product['size_chart_image'])): ?>
          <div class="pd-row">
            <dt>Size Guide</dt>
            <dd><button type="button" id="btn-size-chart">View guidelines</button></dd>
          </div>
        <?php endif; ?>
        <div class="pd-row">
          <dt>Shipping &amp; Returns</dt>
          <dd><a href="shipping.php">Review policy</a></dd>
        </div>
      </dl>
    </div>

    <?php include __DIR__ . '/includes/related-products.php'; ?>
  </main>

  <?php if (!empty($product['size_chart_image'])): ?>
  <div id="modal-size-chart" class="account-popup" role="dialog" aria-modal="true" aria-label="Size chart">
    <div class="account-popup__backdrop" id="sizeChartBackdrop"></div>
    <div class="account-popup__dialog">
      <button type="button" class="popup-close" id="close-size-chart" aria-label="Close">×</button>
      <img src="<?= htmlspecialchars($product['size_chart_image']) ?>" alt="Size chart <?= htmlspecialchars($product['name']) ?>">
    </div>
  </div>
  <?php endif; ?>

  <?php include __DIR__ . '/includes/site-footer.php'; ?>

  <script src="assets/js/site.js?v=<?= filemtime(__DIR__ . '/assets/js/site.js') ?>"></script>
  <script>
  (function () {
    'use strict';

    const productId       = <?= (int)$product['id'] ?>;
    const hasSizeSelector = <?= $hasSizeSelector ? 'true' : 'false' ?>;
    const isSoldOut       = <?= $isSoldOut ? 'true' : 'false' ?>;
    const CURRENT_CURRENCY = <?= json_encode($currentCurrency) ?>;

    const prodName  = <?= json_encode($product['name']) ?>;
    const prodSlug  = <?= json_encode($product['slug']) ?>;
    const prodThumb = <?= json_encode($product['thumbnail']) ?>;
    const prodPrice = <?= json_encode((float)$basePrice) ?>;

    let selectedSize          = <?= json_encode($selectedSize) ?>;
    let selectedProductSizeId = <?= json_encode($selectedProductSizeId) ?>;
    let selectedStock         = <?= json_encode($selectedProductSizeId ? (int)($availableSizes[0]['stock'] ?? 0) : 0) ?>;
    const quantity            = 1;

    const addToCartBtn = document.getElementById('addToCartBtn');
    const PPF = window.PPF;

    /* ── size buttons ── */
    document.querySelectorAll('.size-btn').forEach(btn => {
      btn.addEventListener('click', function () {
        document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
        this.classList.add('active');
        selectedSize = this.dataset.size;
        selectedProductSizeId = this.dataset.id;
        selectedStock = parseInt(this.dataset.stock || '0', 10) || 0;
      });
    });

    function hasSelection() { return !hasSizeSelector || Boolean(selectedProductSizeId); }

    /* ── add to cart (cart state shared via assets/js/site.js) ── */
    async function doAddToCart() {
      if (!PPF) return;
      if (!hasSelection()) { PPF.showToast('Please select an available option first.'); return; }
      if (selectedStock <= 0) { PPF.showToast('Stok untuk ukuran ini habis.'); return; }

      const label = addToCartBtn.querySelector('.cart-btn__label');
      const origText = label.textContent;
      addToCartBtn.disabled = true;
      label.textContent = 'Adding...';

      try {
        const fd = new FormData();
        fd.append('product_id', productId);
        fd.append('quantity', quantity);
        if (selectedProductSizeId) fd.append('product_size_id', selectedProductSizeId);
        await fetch('add-to-cart.php', { method: 'POST', body: fd, headers: {'X-Requested-With': 'XMLHttpRequest'} });
      } catch (err) { }

      const cartItems = PPF.getCart();
      const itemName = prodName + (selectedSize ? ` - ${selectedSize}` : '');
      const existingIdx = cartItems.findIndex(i => i.id === productId && i.name === itemName);

      if (existingIdx > -1) {
        if (cartItems[existingIdx].qty + quantity > selectedStock) {
          PPF.showToast('Stok tersisa hanya ' + selectedStock);
          addToCartBtn.disabled = false;
          label.textContent = origText;
          return;
        }
        cartItems[existingIdx].qty += quantity;
      } else {
        cartItems.push({
          id: productId, slug: prodSlug, size_id: selectedProductSizeId,
          name: itemName, thumb: prodThumb, price: prodPrice,
          qty: quantity, stock: selectedStock, currency: CURRENT_CURRENCY
        });
      }

      PPF.saveCart();
      PPF.renderCart();

      setTimeout(() => {
        if (!isSoldOut) addToCartBtn.disabled = false;
        label.textContent = origText;
        PPF.showToast('Added to cart ✓');
        PPF.openCart();
      }, 300);
    }
    if (addToCartBtn) addToCartBtn.addEventListener('click', doAddToCart);

    /* ── gallery counter (mobile swipe) ── */
    (function () {
      const g = document.getElementById('productGallery');
      const c = document.getElementById('galleryCount');
      if (!g || !c) return;
      const total = g.children.length;
      g.addEventListener('scroll', () => {
        const slide = g.children[0].getBoundingClientRect().width + 2;
        const i = Math.min(total - 1, Math.max(0, Math.round(g.scrollLeft / slide)));
        c.textContent = (i + 1) + ' / ' + total;
      }, { passive: true });
    })();

    /* ── size chart popup ── */
    (function () {
      const btn = document.getElementById('btn-size-chart');
      const modal = document.getElementById('modal-size-chart');
      if (!btn || !modal) return;
      const open  = () => { modal.classList.add('active'); document.body.classList.add('lock-scroll'); };
      const close = () => { modal.classList.remove('active'); document.body.classList.remove('lock-scroll'); };
      btn.addEventListener('click', e => { e.preventDefault(); open(); });
      document.getElementById('close-size-chart').addEventListener('click', close);
      document.getElementById('sizeChartBackdrop').addEventListener('click', close);
      document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    })();
  })();
  </script>
</body>
</html>
