<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error   = '';

$uploadDir      = __DIR__ . '/../assets/images/products/';
$uploadBasePath = 'assets/images/products/';

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

function old(string $key, $default = '')
{
    return $_POST[$key] ?? $default;
}

function formatRupiah($amount): string
{
    return 'RP ' . number_format((float) $amount, 0, ',', '.');
}

function formatUsd($amount): string
{
    return '$' . number_format((float) $amount, 2, '.', ',');
}

function uploadImage(array $file, string $uploadDir, string $uploadBasePath): ?string
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Image upload failed.');
    }
    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!isset($allowedMime[$mime])) {
        throw new RuntimeException('Only JPG, PNG, WEBP, and GIF files are allowed.');
    }
    require_once __DIR__ . '/../includes/image-helper.php';
    $filename = ppf_save_image_webp($file['tmp_name'], $mime, $uploadDir, 'product_'); // WebP when possible
    return $uploadBasePath . $filename;
}

try {
    $categories = $pdo->query(
        'SELECT id, name, slug
         FROM categories
         ORDER BY FIELD(slug, "fashion", "perfume", "experimental-works"), name ASC'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $categories = [];
    $error = 'Categories table is not ready. Please run the category migration SQL first.';
}

// Fetch sizes for the size dropdown
try {
    $sizes = $pdo->query('SELECT id, name, sort_order FROM sizes ORDER BY sort_order ASC, name ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sizes = [];
    if ($error === '') {
        $error = 'Sizes table is not ready. Please run the sizes migration SQL first.';
    }
}

// Determine edit mode: via GET ?edit=ID, ?delete=ID, or hidden product_id on POST
$editProductId  = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$deleteProductId = isset($_GET['delete']) ? (int) $_GET['delete'] : 0;

$editProduct    = null;
$editProductSizes = [];
$editProductImages = [];

if ($editProductId > 0) {
    try {
        $stmtEdit = $pdo->prepare('SELECT * FROM products WHERE id = :id');
        $stmtEdit->execute(['id' => $editProductId]);
        $editProduct = $stmtEdit->fetch(PDO::FETCH_ASSOC);

        if ($editProduct) {
            $stmtSizes = $pdo->prepare('SELECT size_id, stock, is_active FROM product_sizes WHERE product_id = :pid ORDER BY size_id ASC');
            $stmtSizes->execute(['pid' => $editProductId]);
            $editProductSizes = $stmtSizes->fetchAll(PDO::FETCH_ASSOC);

            $stmtImgs = $pdo->prepare('SELECT id, image_path, sort_order FROM product_images WHERE product_id = :pid ORDER BY sort_order ASC');
            $stmtImgs->execute(['pid' => $editProductId]);
            $editProductImages = $stmtImgs->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $error = 'Product not found.';
            $editProductId = 0;
        }
    } catch (Throwable $e) {
        $error = 'Failed to load product: ' . $e->getMessage();
        $editProductId = 0;
    }
}

// Handle delete action
if ($deleteProductId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    try {
        $pdo->beginTransaction();

        // Delete product images records
        $pdo->prepare('DELETE FROM product_images WHERE product_id = :id')->execute(['id' => $deleteProductId]);
        // Delete product sizes records
        $pdo->prepare('DELETE FROM product_sizes WHERE product_id = :id')->execute(['id' => $deleteProductId]);
        // Delete the product
        $pdo->prepare('DELETE FROM products WHERE id = :id')->execute(['id' => $deleteProductId]);

        $pdo->commit();
        $message = 'Product deleted successfully.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = 'Failed to delete product: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId    = (int) ($_POST['product_id'] ?? 0); // Hidden field — if > 0 we're updating
    $name        = trim($_POST['name'] ?? '');
    $slug        = trim($_POST['slug'] ?? '');
    $categoryId  = (int) ($_POST['category_id'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    $composition = trim($_POST['composition'] ?? '');
    $care        = trim($_POST['care'] ?? '');

    $priceIdr    = (float) ($_POST['price_idr'] ?? 0);
    $priceUsd    = (float) ($_POST['price_usd'] ?? 0);
    $isActive    = isset($_POST['product_is_active']) ? 1 : 0;

    if ($name === '' || $slug === '') {
        $error = 'Product name and slug are required.';
    } elseif ($categoryId <= 0) {
        $error = 'Please choose a product category.';
    } elseif ($priceIdr <= 0) {
        $error = 'Price IDR must be greater than 0.';
    } elseif ($priceUsd <= 0) {
        $error = 'Price USD must be greater than 0.';
    } else {
        try {
            // Check slug uniqueness (allow same slug for the same product being edited)
            $check = $pdo->prepare('SELECT id FROM products WHERE slug = :slug AND id != :id');
            $check->execute(['slug' => $slug, 'id' => $productId]);

            if ($check->fetch()) {
                $error = 'Slug already exists. Please use a different slug.';
            } else {
                $isUpdate = $productId > 0;

                // Get existing data if updating
                $existingProduct = null;
                if ($isUpdate) {
                    $stmtExisting = $pdo->prepare('SELECT thumbnail, size_chart_image FROM products WHERE id = :id');
                    $stmtExisting->execute(['id' => $productId]);
                    $existingProduct = $stmtExisting->fetch(PDO::FETCH_ASSOC);
                    if (!$existingProduct) {
                        throw new RuntimeException('Product not found for update.');
                    }
                }

                // Handle thumbnail upload (optional on update)
                $thumbnailPath = null;
                if (isset($_FILES['thumbnail_file']) && $_FILES['thumbnail_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $thumbnailPath = uploadImage($_FILES['thumbnail_file'], $uploadDir, $uploadBasePath);
                } elseif (!$isUpdate) {
                    throw new RuntimeException('Thumbnail image is required.');
                }

                // Handle size chart upload
                $sizeChartPath = $isUpdate ? $existingProduct['size_chart_image'] : null;
                if (isset($_FILES['size_chart_file']) && $_FILES['size_chart_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                    $sizeChartPath = uploadImage($_FILES['size_chart_file'], $uploadDir, $uploadBasePath);
                }

                // Handle detail images (only add new ones, don't replace existing)
                $detailImages = [];
                if (isset($_FILES['detail_images']['name']) && is_array($_FILES['detail_images']['name'])) {
                    $totalFiles = count($_FILES['detail_images']['name']);
                    for ($i = 0; $i < $totalFiles; $i++) {
                        if ($_FILES['detail_images']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                        $singleFile = [
                            'name'     => $_FILES['detail_images']['name'][$i],
                            'type'     => $_FILES['detail_images']['type'][$i],
                            'tmp_name' => $_FILES['detail_images']['tmp_name'][$i],
                            'error'    => $_FILES['detail_images']['error'][$i],
                            'size'     => $_FILES['detail_images']['size'][$i],
                        ];
                        $uploadedPath = uploadImage($singleFile, $uploadDir, $uploadBasePath);
                        if ($uploadedPath !== null) {
                            $detailImages[] = $uploadedPath;
                        }
                    }
                }

                // Handle images to delete (for update)
                $imagesToDelete = $_POST['delete_images'] ?? [];
                $imagesToDelete = array_map('intval', $imagesToDelete);

                $pdo->beginTransaction();

                if ($isUpdate) {
                    // Build dynamic UPDATE query
                    $updateFields = [
                        'category_id = :category_id',
                        'name = :name',
                        'slug = :slug',
                        'description = :description',
                        'composition = :composition',
                        'care = :care',
                        'size_chart_image = :size_chart_image',
                        'price_idr = :price_idr',
                        'price_usd = :price_usd',
                        'is_active = :is_active',
                    ];
                    $updateParams = [
                        'category_id'      => $categoryId,
                        'name'             => $name,
                        'slug'             => $slug,
                        'description'      => $description,
                        'composition'      => $composition,
                        'care'             => $care,
                        'size_chart_image' => $sizeChartPath,
                        'price_idr'        => $priceIdr,
                        'price_usd'        => $priceUsd,
                        'is_active'        => $isActive,
                    ];

                    if ($thumbnailPath !== null) {
                        $updateFields[] = 'thumbnail = :thumbnail';
                        $updateParams['thumbnail'] = $thumbnailPath;
                    }

                    $updateParams['id'] = $productId;

                    $sqlUpdate = 'UPDATE products SET ' . implode(', ', $updateFields) . ' WHERE id = :id';
                    $pdo->prepare($sqlUpdate)->execute($updateParams);
                } else {
                    $insertProduct = $pdo->prepare(
                        'INSERT INTO products (
                            category_id, name, slug, description, composition, care,
                            thumbnail, size_chart_image, price_idr, price_usd, is_active
                        ) VALUES (
                            :category_id, :name, :slug, :description, :composition, :care,
                            :thumbnail, :size_chart_image, :price_idr, :price_usd, :is_active
                        )'
                    );
                    $insertProduct->execute([
                        'category_id'      => $categoryId,
                        'name'             => $name,
                        'slug'             => $slug,
                        'description'      => $description,
                        'composition'      => $composition,
                        'care'             => $care,
                        'thumbnail'        => $thumbnailPath,
                        'size_chart_image' => $sizeChartPath,
                        'price_idr'        => $priceIdr,
                        'price_usd'        => $priceUsd,
                        'is_active'        => $isActive,
                    ]);
                    $productId = (int) $pdo->lastInsertId();
                }

                // Delete selected detail images
                if (!empty($imagesToDelete)) {
                    $placeholders = implode(',', array_fill(0, count($imagesToDelete), '?'));
                    $params = array_merge($imagesToDelete, [$productId]);
                    $pdo->prepare("DELETE FROM product_images WHERE id IN ($placeholders) AND product_id = ?")
                        ->execute($params);
                }

                // Insert new detail images
                if (!empty($detailImages)) {
                    // Get current max sort_order
                    $stmtMaxOrder = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM product_images WHERE product_id = :pid');
                    $stmtMaxOrder->execute(['pid' => $productId]);
                    $startOrder = (int) $stmtMaxOrder->fetchColumn();

                    $insertImage = $pdo->prepare(
                        'INSERT INTO product_images (product_id, image_path, sort_order)
                         VALUES (:product_id, :image_path, :sort_order)'
                    );
                    foreach ($detailImages as $index => $imagePath) {
                        $insertImage->execute([
                            'product_id' => $productId,
                            'image_path' => $imagePath,
                            'sort_order' => $startOrder + $index + 1,
                        ]);
                    }
                }

                // Replace product sizes (delete all then re-insert)
                $pdo->prepare('DELETE FROM product_sizes WHERE product_id = :id')->execute(['id' => $productId]);

                $sizeIds = $_POST['size_id'] ?? [];
                $stocks  = $_POST['stock'] ?? [];
                $isActives = $_POST['is_active'] ?? [];

                foreach ($sizeIds as $index => $sizeId) {
                    $sizeId = (int) $sizeId;
                    $stock  = (int) ($stocks[$index] ?? 0);
                    $sizeActive = isset($isActives[$index]) ? 1 : 0;

                    if ($sizeId <= 0 || $stock < 0) {
                        continue;
                    }

                    $insertSize = $pdo->prepare(
                        'INSERT INTO product_sizes (product_id, size_id, stock, is_active)
                         VALUES (:product_id, :size_id, :stock, :is_active)'
                    );
                    $insertSize->execute([
                        'product_id' => $productId,
                        'size_id'    => $sizeId,
                        'stock'      => $stock,
                        'is_active'  => $sizeActive,
                    ]);
                }

                $pdo->commit();
                $message = $isUpdate ? 'Product updated successfully.' : 'Product created successfully.';

                if (!$isUpdate) {
                    $_POST = [];
                } else {
                    // Reload product data after update
                    header('Location: dashboard-product.php?edit=' . $productId);
                    exit;
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to save product: ' . $e->getMessage();
        }
    }
}

try {
    // UPDATE: Ambil products.price_usd dari database
    $recentProducts = $pdo->query(
        'SELECT
            products.id,
            products.name,
            products.slug,
            products.thumbnail,
            products.price_idr,
            products.price_usd,
            products.is_active,
            categories.name AS category_name
         FROM products
         LEFT JOIN categories ON categories.id = products.category_id
         ORDER BY products.id DESC
         LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $recentProducts = [];
    if ($error === '') {
        $error = 'Unable to load recent products: ' . $e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Product Manager — pastpresentfurther Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #ffffff;
      color: #111;
    }
    a { color: inherit; text-decoration: none; }

    /* ── TOPBAR ───────────────────────────────────────── */
    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #ffffff;
      border-bottom: 1px solid #d8d5ce;
      position: sticky;
      top: 0;
      z-index: 20;
    }
    .topbar__left, .topbar__right {
      display: flex;
      align-items: center;
      gap: 18px;
    }
    .topbar__right { justify-content: flex-end; }
    .logo {
      width: 140px;
      justify-self: center;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .logo img {
      width: 100%;
      height: 36px;
      object-fit: contain;
    }
    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    /* ── WRAPPER ──────────────────────────────────────── */
    .wrap {
      max-width: 1440px;
      margin: 0 auto;
      border-left: 1px solid #d8d5ce;
      border-right: 1px solid #d8d5ce;
      min-height: calc(100vh - 74px);
    }

    /* ── HERO ─────────────────────────────────────────── */
    .hero {
      padding: 22px 20px;
      border-bottom: 1px solid #d8d5ce;
      display: grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 0;
    }
    .hero-left { min-width: 0; }
    .hero-right {
      border-left: 1px solid #d8d5ce;
      padding-left: 20px;
      display: flex;
      align-items: flex-end;
    }
    .eyebrow {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .7px;
      margin-bottom: 14px;
    }
    .headline {
      margin: 0;
      font-size: 36px;
      line-height: 1.08;
      text-transform: uppercase;
      font-weight: 400;
      max-width: 620px;
    }
    .subcopy {
      margin: 18px 0 0;
      max-width: 480px;
      font-size: 14px;
      line-height: 1.7;
      color: #555;
    }
    .hero-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 24px;
    }

    /* ── BUTTONS ──────────────────────────────────────── */
    .btn, .btn-secondary {
      height: 48px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      outline: none;
      transition: border-color .2s, background .2s;
    }
    .btn:hover { background: #000; }
    .btn-secondary {
      background: #faf9f5;
      color: #111;
      border-color: #d8d5ce;
    }
    .btn-secondary:hover { background: #f4f2ec; }

    /* ── SECTION GRID ─────────────────────────────────── */
    .section-grid {
      display: grid;
      grid-template-columns: 1.1fr .9fr;
    }
    .section {
      min-width: 0;
      border-right: 1px solid #d8d5ce;
      border-bottom: 1px solid #d8d5ce;
    }
    .section:nth-child(2n) { border-right: 0; }
    .section-head {
      padding: 18px 20px;
      border-bottom: 1px solid #d8d5ce;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: #faf9f5;
    }
    .section-head span:last-child { color: #888; font-size: 12px; }
    .section-body {
      padding: 20px;
      background: #ffffff;
    }

    /* ── ALERT ────────────────────────────────────────── */
    .alert {
      padding: 14px 18px;
      background: #111;
      color: #fff;
      font-size: 12px;
      letter-spacing: .6px;
      text-transform: uppercase;
      margin-bottom: 20px;
    }
    .alert.error {
      background: #faf9f5;
      color: #111;
      border: 1px solid #d8d5ce;
    }

    /* ── FORM ─────────────────────────────────────────── */
    .form-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 14px;
    }
    .form-group { margin-bottom: 14px; }
    .form-group-full { grid-column: 1 / -1; margin-bottom: 14px; }
    .form-label {
      display: block;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-bottom: 8px;
      color: #333;
    }
    .form-input,
    .form-select,
    .form-textarea {
      width: 100%;
      height: 44px;
      padding: 0 14px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      font-size: 13px;
      font-family: inherit;
      outline: none;
      transition: border-color .2s, background .2s;
      color: #111;
    }
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
      border-color: #111;
      background: #fff;
    }
    .form-textarea {
      height: auto;
      min-height: 120px;
      padding: 12px 14px;
      resize: vertical;
      line-height: 1.6;
    }
    input[type="file"].form-input {
      padding-top: 11px;
      cursor: pointer;
    }
    .form-hint {
      font-size: 11px;
      color: #666;
      margin-top: 6px;
      display: block;
      text-transform: none;
    }
    .checkbox-row {
      display: flex;
      align-items: center;
      gap: 10px;
      min-height: 44px;
      padding: 0 14px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    .checkbox-row input[type="checkbox"] {
      width: 16px;
      height: 16px;
      cursor: pointer;
      accent-color: #111;
    }
    .checkbox-row label {
      font-size: 13px;
      cursor: pointer;
      text-transform: none;
      letter-spacing: 0;
    }
    .subhead {
      margin: 18px 0 14px;
      padding-top: 16px;
      border-top: 1px solid #d8d5ce;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .7px;
      color: #888;
    }
    .form-actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 20px;
    }

    /* ── TABLE ────────────────────────────────────────── */
    .table-wrap {
      overflow: auto;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    table { width: 100%; border-collapse: collapse; min-width: 680px; }
    th, td {
      border-bottom: 1px solid #e8e6df;
      padding: 12px 10px;
      text-align: left;
      font-size: 13px;
      vertical-align: middle;
    }
    th {
      text-transform: uppercase;
      letter-spacing: .6px;
      font-size: 11px;
      background: #f4f2ec;
    }
    .thumb-mini {
      width: 48px;
      height: 62px;
      border: 1px solid #e0ddd5;
      background: #fff;
      object-fit: cover;
      display: block;
    }
    .status {
      display: inline-block;
      padding: 5px 10px;
      border: 1px solid #d8d5ce;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .5px;
      background: #faf9f5;
      color: #888;
    }
    .status.live { border-color: #111; background: #f4f2ec; color: #111; }

    /* ── RESPONSIVE ───────────────────────────────────── */
    @media (max-width: 1080px) {
      .section-grid { grid-template-columns: 1fr; }
      .section { border-right: 0; }
      .hero { grid-template-columns: 1fr; }
      .hero-right { border-left: 0; padding-left: 0; margin-top: 16px; }
    }
    @media (max-width: 640px) {
      .topbar { padding: 0 16px; }
      .headline { font-size: 28px; }
      .form-grid { grid-template-columns: 1fr; }
      .form-actions { flex-direction: column; }
      .btn, .btn-secondary { width: 100%; }
    }

    /* Size-specific styles */
    .size-entry {
      border: 1px solid #e0e0e0;
      border-radius: 4px;
      padding: 16px;
      margin-bottom: 12px;
      background: #faf9f5;
    }
    .size-entry-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    .size-entry-header h3 {
      margin: 0;
      font-size: 14px;
      color: #111;
    }
    .remove-size-btn {
      background: transparent;
      border: none;
      color: #e53935;
      font-size: 12px;
      cursor: pointer;
      padding: 0;
    }
    .remove-size-btn:hover {
      color: #b71c1c;
    }
    .size-fields {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }
    .size-field {
      display: flex;
      flex-direction: column;
    }
    .size-field label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
      margin-bottom: 4px;
      color: #333;
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Products'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">

    <section class="hero">
      <div class="hero-left">
        <div class="eyebrow">pastpresentfurther Admin / Products</div>
        <h1 class="headline"><?= $editProduct ? 'Edit Product' : 'Create New Product' ?></h1>
        <p class="subcopy">
          <?php if ($editProduct): ?>
            Update product details, prices, images, and size inventory. Changes apply to the storefront immediately after saving.
          <?php else: ?>
            Upload products with dynamic IDR &amp; USD price configurations, category structures, active statuses, thumbnail, and detail images.
            Now with size management!
          <?php endif; ?>
        </p>
        <div class="hero-actions">
          <a href="dashboard.php" class="btn-secondary">← Back to Dashboard</a>
          <?php if ($editProduct): ?>
            <a href="dashboard-product.php" class="btn-secondary">+ Create New</a>
          <?php endif; ?>
        </div>
      </div>
      <div class="hero-right">
      </div>
    </section>

    <section class="section-grid">

      <div class="section">
        <div class="section-head">
          <span>Product Details</span>
          <span>Multi-Currency Setup</span>
        </div>
        <div class="section-body">

          <?php if ($message !== ''): ?>
            <div class="alert"><?= htmlspecialchars($message) ?></div>
          <?php endif; ?>
          <?php if ($error !== ''): ?>
            <div class="alert error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <form method="POST" action="" enctype="multipart/form-data">
            <?php if ($editProduct): ?>
              <input type="hidden" name="product_id" value="<?= (int) $editProduct['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">

              <div class="form-group">
                <label class="form-label" for="name">Product Name</label>
                <input type="text" id="name" name="name" class="form-input"
                       value="<?= htmlspecialchars(old('name', $editProduct['name'] ?? '')) ?>" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="slug">Slug</label>
                <input type="text" id="slug" name="slug" class="form-input"
                       value="<?= htmlspecialchars(old('slug', $editProduct['slug'] ?? '')) ?>"
                       placeholder="product-name" required>
              </div>

              <div class="form-group-full">
                <label class="form-label" for="category_id">Category</label>
                <select id="category_id" name="category_id" class="form-select" required>
                  <option value="">Select Category</option>
                  <?php
                    $selectedCategory = (string) old('category_id', $editProduct['category_id'] ?? '');
                    foreach ($categories as $category):
                  ?>
                    <option value="<?= (int) $category['id'] ?>"
                      <?= $selectedCategory === (string) $category['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($category['name']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="form-group">
                <label class="form-label" for="price_idr">Price IDR</label>
                <input type="number" step="1" min="0" id="price_idr" name="price_idr"
                       class="form-input"
                       value="<?= htmlspecialchars(old('price_idr', $editProduct['price_idr'] ?? '0')) ?>" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="price_usd">Price USD</label>
                <input type="number" step="0.01" min="0" id="price_usd" name="price_usd"
                       class="form-input"
                       value="<?= htmlspecialchars(old('price_usd', $editProduct['price_usd'] ?? '0.00')) ?>" required>
              </div>

              <div class="form-group">
                <label class="form-label" for="thumbnail_file">Thumbnail Image</label>
                <?php if ($editProduct && !empty($editProduct['thumbnail'])): ?>
                  <div class="form-hint" style="margin-bottom: 8px;">
                    Current: <img src="../<?= htmlspecialchars($editProduct['thumbnail']) ?>" alt="thumb" style="width:48px;height:62px;object-fit:cover;vertical-align:middle;border:1px solid #d8d5ce;">
                  </div>
                <?php endif; ?>
                <input type="file" id="thumbnail_file" name="thumbnail_file"
                       class="form-input" accept="image/*" <?= $editProduct ? '' : 'required' ?>>
                <?php if ($editProduct): ?>
                  <span class="form-hint">Leave empty to keep the current thumbnail.</span>
                <?php endif; ?>
              </div>

              <div class="form-group">
                <label class="form-label" for="size_chart_file">Size Chart Image (Optional)</label>
                <?php if ($editProduct && !empty($editProduct['size_chart_image'])): ?>
                  <div class="form-hint" style="margin-bottom: 8px;">
                    Current: <img src="../<?= htmlspecialchars($editProduct['size_chart_image']) ?>" alt="size chart" style="width:48px;height:62px;object-fit:cover;vertical-align:middle;border:1px solid #d8d5ce;">
                  </div>
                <?php endif; ?>
                <input type="file" id="size_chart_file" name="size_chart_file"
                       class="form-input" accept="image/*">
                <span class="form-hint">Leave empty to keep the current size chart.</span>
              </div>

              <div class="form-group-full">
                <label class="form-label" for="description">Description</label>
                <textarea id="description" name="description" class="form-textarea"
                          placeholder="Write product description..."><?= htmlspecialchars(old('description', $editProduct['description'] ?? '')) ?></textarea>
              </div>

              <div class="form-group-full">
                <label class="form-label" for="composition">Composition</label>
                <textarea id="composition" name="composition" class="form-textarea"
                          placeholder="e.g. 100% Cotton, Heavyweight..."><?= htmlspecialchars(old('composition', $editProduct['composition'] ?? '')) ?></textarea>
              </div>

              <div class="form-group-full">
                <label class="form-label" for="care">Care Instructions</label>
                <textarea id="care" name="care" class="form-textarea"
                          placeholder="e.g. Machine wash cold, do not tumble dry..."><?= htmlspecialchars(old('care', $editProduct['care'] ?? '')) ?></textarea>
              </div>

              <div class="form-group-full">
                <label class="form-label">Publish Status</label>
                <div class="checkbox-row">
                  <input type="checkbox" id="is_active" name="product_is_active"
                         <?= old('product_is_active', $editProduct['is_active'] ?? '1') ? 'checked' : '' ?>>
                  <label for="is_active">Set product as active (visible on storefront)</label>
                </div>
              </div>

              <!-- Product Sizes Section -->
              <div class="form-group-full">
                <div class="section-head">
                  <span>Product Sizes</span>
                  <span>Stock & Availability</span>
                </div>
                <div id="sizeEntriesContainer">
                  <?php
                    // If editing, show existing sizes; otherwise show one empty entry
                    $initialSizeEntries = !empty($editProductSizes) ? $editProductSizes : [[
                        'size_id'   => 0,
                        'stock'     => 0,
                        'is_active' => 1,
                    ]];
                    foreach ($initialSizeEntries as $idx => $sizeEntry):
                  ?>
                    <div class="size-entry">
                      <div class="size-entry-header">
                        <h3>Size Entry</h3>
                        <button type="button" class="remove-size-btn">Remove</button>
                      </div>
                      <div class="size-fields">
                        <div class="size-field">
                          <label>Size</label>
                          <select name="size_id[]" class="form-input" required>
                            <option value="">Select Size</option>
                            <?php foreach ($sizes as $size): ?>
                              <option value="<?= (int) $size['id'] ?>"
                                <?= (int) $sizeEntry['size_id'] === (int) $size['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($size['name']) ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="size-field">
                          <label>Stock</label>
                          <input type="number" name="stock[]" min="0" value="<?= (int) $sizeEntry['stock'] ?>" class="form-input" required>
                        </div>
                        <div class="size-field">
                          <label>Active</label>
                          <input type="checkbox" name="is_active[]" value="1"
                                 class="form-input" <?= (int) $sizeEntry['is_active'] === 1 ? 'checked' : '' ?>>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="form-actions" style="margin-top: 0; justify-content: flex-start;">
                  <button type="button" id="addSizeBtn" class="btn-secondary">Add Size</button>
                </div>
              </div>

            </div>

            <div class="subhead">Detail Images</div>

            <?php if (!empty($editProductImages)): ?>
              <div class="form-group">
                <label class="form-label">Existing Detail Images</label>
                <div style="display:flex;flex-wrap:wrap;gap:12px;">
                  <?php foreach ($editProductImages as $img): ?>
                    <label style="display:flex;flex-direction:column;align-items:center;gap:6px;font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#333;">
                      <img src="../<?= htmlspecialchars($img['image_path']) ?>" alt="detail" style="width:80px;height:104px;object-fit:cover;border:1px solid #d8d5ce;">
                      <span>
                        <input type="checkbox" name="delete_images[]" value="<?= (int) $img['id'] ?>">
                        Delete
                      </span>
                    </label>
                  <?php endforeach; ?>
                </div>
                <span class="form-hint">Tick the checkbox to remove an image when you save.</span>
              </div>
            <?php endif; ?>

            <div class="form-group">
              <label class="form-label" for="detail_images"><?= $editProduct ? 'Add More Detail Images' : 'Upload Detail Images' ?></label>
              <input type="file" id="detail_images" name="detail_images[]"
                     class="form-input" accept="image/*" multiple>
              <span class="form-hint">
                <?= $editProduct ? 'New images will be appended to existing ones.' : 'You can select multiple images.' ?>
                Files are saved to <strong>assets/images/products/</strong> and linked in the database.
              </span>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn"><?= $editProduct ? 'Update Product' : 'Save Product' ?></button>
              <?php if ($editProduct): ?>
                <a href="dashboard-product.php" class="btn-secondary">Cancel</a>
              <?php else: ?>
                <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      </div>

      <div class="section">
        <div class="section-head">
          <span>Recent Products</span>
          <span>Latest 12</span>
        </div>
        <div class="section-body">
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Thumb</th>
                  <th>Name</th>
                  <th>Category</th>
                  <th>Price IDR</th>
                  <th>Price USD</th> <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$recentProducts): ?>
                  <tr><td colspan="7" style="color:#888;">No products yet.</td></tr>
                <?php else: ?>
                  <?php foreach ($recentProducts as $item): ?>
                    <tr>
                      <td>
                        <?php if (!empty($item['thumbnail'])): ?>
                          <img class="thumb-mini"
                               src="../<?= htmlspecialchars($item['thumbnail']) ?>"
                               alt="<?= htmlspecialchars($item['name']) ?>">
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($item['name']) ?></td>
                      <td><?= htmlspecialchars($item['category_name'] ?? 'Uncategorized') ?></td>
                      <td><?= formatRupiah($item['price_idr']) ?></td>
                      <td><?= formatUsd($item['price_usd']) ?></td> <td>
                        <span class="status <?= (int) $item['is_active'] === 1 ? 'live' : '' ?>">
                          <?= (int) $item['is_active'] === 1 ? 'Active' : 'Draft' ?>
                        </span>
                      </td>
                      <td>
                        <a href="dashboard-product.php?edit=<?= (int) $item['id'] ?>"
                           class="btn-secondary"
                           style="height:32px;padding:0 12px;font-size:11px;margin-right:6px;">Edit</a>
                        <a href="dashboard-product.php?delete=<?= (int) $item['id'] ?>"
                           class="btn-secondary"
                           style="height:32px;padding:0 12px;font-size:11px;border-color:#e53935;color:#e53935;"
                           onclick="return confirm('Delete this product? This cannot be undone.');">Delete</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

    </section>
  </main>

  <script>
    const nameInput = document.getElementById('name');
    const slugInput = document.getElementById('slug');

    if (nameInput && slugInput) {
      nameInput.addEventListener('input', function () {
        if (slugInput.dataset.edited === 'true') return;
        slugInput.value = nameInput.value
          .toLowerCase().trim()
          .replace(/[^a-z0-9\s-]/g, '')
          .replace(/\s+/g, '-')
          .replace(/-+/g, '-');
      });
      slugInput.addEventListener('input', function () {
        slugInput.dataset.edited = slugInput.value.trim() !== '' ? 'true' : 'false';
      });
    }

    // Size management
    document.addEventListener('DOMContentLoaded', function () {
      const sizeEntriesContainer = document.getElementById('sizeEntriesContainer');
      const addSizeBtn = document.getElementById('addSizeBtn');

      // Get the first size entry to use as template
      const firstSizeEntry = sizeEntriesContainer.querySelector('.size-entry');

      // Start index based on existing entries (handles edit mode with multiple sizes)
      let sizeIndex = sizeEntriesContainer.querySelectorAll('.size-entry').length;

      function addSizeEntry() {
        // Clone the first entry
        const newEntry = firstSizeEntry.cloneNode(true);

        // Reset form values in the clone
        const selects = newEntry.querySelectorAll('select[name="size_id[]"]');
        const inputs = newEntry.querySelectorAll('input[name="stock[]"]');
        const checkboxes = newEntry.querySelectorAll('input[name="is_active[]"]');

        selects.forEach(select => {
          select.name = 'size_id[]';
          select.selectedIndex = 0;
        });

        inputs.forEach(input => {
          input.name = 'stock[]';
          input.value = '0';
        });

        checkboxes.forEach(checkbox => {
          checkbox.name = 'is_active[]';
          checkbox.checked = true;
        });

        // Add remove button functionality
        const removeBtn = newEntry.querySelector('.remove-size-btn');
        removeBtn.addEventListener('click', function () {
          if (sizeEntriesContainer.children.length > 1) {
            newEntry.remove();
          }
        });

        sizeEntriesContainer.appendChild(newEntry);
        sizeIndex++;
      }

      // Add remove button functionality to existing entries
      const removeButtons = sizeEntriesContainer.querySelectorAll('.remove-size-btn');
      removeButtons.forEach(button => {
        button.addEventListener('click', function () {
          if (sizeEntriesContainer.children.length > 1) {
            button.closest('.size-entry').remove();
          }
        });
      });

      addSizeBtn.addEventListener('click', addSizeEntry);
    });
  </script>

</body>
</html>