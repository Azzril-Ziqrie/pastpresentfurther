<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error   = '';

function columnExistsCat(PDO $pdo, string $table, string $column): bool {
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
$hasSizeCol = columnExistsCat($pdo, 'categories', 'has_size');
$alterError = '';

if (!$hasSizeCol) {
    try {
        $pdo->exec("ALTER TABLE categories ADD COLUMN has_size TINYINT(1) DEFAULT 0");
        $hasSizeCol = true;
    } catch (Throwable $e) {
        $alterError = $e->getMessage();
    }
}

$catSizeSelect = $hasSizeCol ? 'c.has_size' : '0 AS has_size';

$defaultSizes = [
    ['name' => 'X-Small',  'sort_order' => 1],
    ['name' => 'Small',    'sort_order' => 2],
    ['name' => 'Medium',   'sort_order' => 3],
    ['name' => 'Large',    'sort_order' => 4],
    ['name' => 'X-Large',  'sort_order' => 5],
    ['name' => 'XX-Large', 'sort_order' => 6],
    ['name' => 'One Size', 'sort_order' => 99],
];

foreach ($defaultSizes as $size) {
    $checkSize = $pdo->prepare('SELECT id FROM sizes WHERE name = :name LIMIT 1');
    $checkSize->execute(['name' => $size['name']]);
    if (!$checkSize->fetch()) {
        $insertSize = $pdo->prepare('INSERT INTO sizes (name, sort_order) VALUES (:name, :sort_order)');
        $insertSize->execute(['name' => $size['name'], 'sort_order' => $size['sort_order']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'update_product_sizes';

    if ($action === 'add_custom_size') {
        $newSizeName  = trim($_POST['new_size_name'] ?? '');
        $newSortOrder = (int) ($_POST['new_sort_order'] ?? 10);
        if ($newSizeName !== '') {
            try {
                $insertSize = $pdo->prepare('INSERT INTO sizes (name, sort_order) VALUES (:name, :sort)');
                $insertSize->execute(['name' => $newSizeName, 'sort' => $newSortOrder]);
                $message = "Custom size '{$newSizeName}' has been added to Master Sizes.";
            } catch (Throwable $e) {
                $error = "Failed to add size. It might already exist.";
            }
        } else {
            $error = "Custom size name cannot be empty.";
        }

    } elseif ($action === 'update_product_sizes') {
        $productId   = (int) ($_POST['product_id'] ?? 0);
        $stockInput  = $_POST['stock'] ?? [];
        $activeInput = $_POST['is_active'] ?? [];

        if ($productId <= 0) {
            $error = 'Please select a product first.';
        } else {
            try {
                $pdo->beginTransaction();
                $sizeRows = $pdo->query('SELECT id FROM sizes ORDER BY sort_order ASC, name ASC')->fetchAll();
                $checkProduct = $pdo->prepare('SELECT id FROM products WHERE id = :id LIMIT 1');
                $checkProduct->execute(['id' => $productId]);
                if (!$checkProduct->fetch()) throw new RuntimeException('Selected product was not found.');

                $findRelation   = $pdo->prepare('SELECT id FROM product_sizes WHERE product_id = :product_id AND size_id = :size_id LIMIT 1');
                $insertRelation = $pdo->prepare('INSERT INTO product_sizes (product_id, size_id, stock, is_active) VALUES (:product_id, :size_id, :stock, :is_active)');
                $updateRelation = $pdo->prepare('UPDATE product_sizes SET stock = :stock, is_active = :is_active WHERE id = :id');

                foreach ($sizeRows as $sizeRow) {
                    $sizeId   = (int) $sizeRow['id'];
                    $stock    = isset($stockInput[$sizeId]) ? max(0, (int) $stockInput[$sizeId]) : 0;
                    $isActive = isset($activeInput[$sizeId]) ? 1 : 0;
                    $findRelation->execute(['product_id' => $productId, 'size_id' => $sizeId]);
                    $existing = $findRelation->fetch();
                    if ($existing) {
                        $updateRelation->execute(['stock' => $stock, 'is_active' => $isActive, 'id' => $existing['id']]);
                    } else {
                        $insertRelation->execute(['product_id' => $productId, 'size_id' => $sizeId, 'stock' => $stock, 'is_active' => $isActive]);
                    }
                }
                $pdo->commit();
                $message = 'Product sizes and stock updated successfully.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = 'Failed to update product sizes: ' . $e->getMessage();
            }
        }
    }
}

$products = $pdo->query(
    "SELECT p.id, p.name, p.slug, p.thumbnail, p.is_active,
            COALESCE(c.slug, 'fashion') AS category_slug,
            COALESCE(c.name, 'Fashion') AS category_name,
            {$catSizeSelect}
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.id DESC"
)->fetchAll();

$sizes = $pdo->query('SELECT id, name, sort_order FROM sizes ORDER BY sort_order ASC, name ASC')->fetchAll();

$selectedProductId = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $selectedProductId = (int) $_POST['product_id'];
}

$selectedProduct = null;
$productSizeMap  = [];

if ($selectedProductId > 0) {
    $selectedProductStmt = $pdo->prepare(
        "SELECT p.id, p.name, p.slug, p.thumbnail, p.is_active,
                COALESCE(c.slug, 'fashion') AS category_slug,
                COALESCE(c.name, 'Fashion') AS category_name,
                {$catSizeSelect}
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = :id LIMIT 1"
    );
    $selectedProductStmt->execute(['id' => $selectedProductId]);
    $selectedProduct = $selectedProductStmt->fetch();

    if ($selectedProduct) {
        $productSizeStmt = $pdo->prepare('SELECT size_id, stock, is_active FROM product_sizes WHERE product_id = :product_id');
        $productSizeStmt->execute(['product_id' => $selectedProductId]);
        foreach ($productSizeStmt->fetchAll() as $row) {
            $productSizeMap[(int) $row['size_id']] = ['stock' => (int) $row['stock'], 'is_active' => (int) $row['is_active']];
        }
    }
}

$oneSizeNames = ['one size', 'onesize', 'default'];
$visibleSizes = $sizes;

if ($selectedProduct) {
    $hasMultipleSizes = false;
    if ($hasSizeCol && isset($selectedProduct['has_size'])) {
        $hasMultipleSizes = (int) $selectedProduct['has_size'] === 1;
    } else {
        $hasMultipleSizes = strtolower(trim((string)($selectedProduct['category_slug'] ?? 'fashion'))) === 'fashion';
    }

    if ($hasMultipleSizes) {
        $visibleSizes = array_values(array_filter($sizes, fn($s) => !in_array(strtolower(trim((string)$s['name'])), $oneSizeNames, true)));
    } else {
        $visibleSizes = array_values(array_filter($sizes, fn($s) => in_array(strtolower(trim((string)$s['name'])), $oneSizeNames, true)));
    }
    if (!$visibleSizes) $visibleSizes = $sizes;
}

$sizeOverview = $pdo->query(
    "SELECT p.id AS product_id, p.name AS product_name, p.slug,
            COALESCE(c.slug, 'fashion') AS category_slug,
            COALESCE(c.name, 'Fashion') AS category_name,
            s.name AS size_name, ps.stock, ps.is_active
     FROM product_sizes ps
     INNER JOIN products p ON p.id = ps.product_id
     INNER JOIN sizes s ON s.id = ps.size_id
     LEFT JOIN categories c ON c.id = p.category_id
     ORDER BY p.id DESC, s.sort_order ASC, s.name ASC
     LIMIT 100"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Manage Sizes — pastpresentfurther Admin</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #ffffff;
      color: #111;
    }
    a { color: inherit; text-decoration: none; }
    button, input, select { font: inherit; }

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
    .logo img { width: 100%; height: 36px; object-fit: contain; }
    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    /* ── WRAPPER / LAYOUT ─────────────────────────────── */
    .layout {
      display: grid;
      grid-template-columns: 1.02fr .98fr;
      min-height: calc(100vh - 74px);
      border-left: 1px solid #d8d5ce;
      border-right: 1px solid #d8d5ce;
      max-width: 1440px;
      margin: 0 auto;
    }
    .panel {
      min-width: 0;
      border-right: 1px solid #d8d5ce;
    }
    .panel:last-child { border-right: 0; }
    .panel-head {
      padding: 18px 20px;
      border-bottom: 1px solid #d8d5ce;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
      background: #faf9f5;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .panel-body { padding: 20px; background: #ffffff; }

    /* ── ALERTS ───────────────────────────────────────── */
    .alert {
      margin-bottom: 18px;
      padding: 14px 18px;
      background: #111;
      color: #fff;
      font-size: 12px;
      letter-spacing: .6px;
      text-transform: uppercase;
    }
    .alert.error {
      background: #faf9f5;
      color: #111;
      border: 1px solid #d8d5ce;
    }

    /* ── DIAGNOSTIC ───────────────────────────────────── */
    .diagnostic {
      margin-bottom: 18px;
      padding: 14px 18px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      font-size: 12px;
      line-height: 1.8;
    }
    .diagnostic code {
      display: block;
      margin-top: 6px;
      padding: 8px;
      background: #f4f2ec;
      word-break: break-word;
    }

    /* ── FORMS ────────────────────────────────────────── */
    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 16px;
    }
    label {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: #333;
    }
    select,
    input[type="number"],
    input[type="text"] {
      width: 100%;
      min-height: 44px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      padding: 0 14px;
      outline: none;
      color: #111;
      transition: border-color .2s, background .2s;
    }
    select:focus,
    input[type="number"]:focus,
    input[type="text"]:focus {
      border-color: #111;
      background: #fff;
    }

    /* ── PRODUCT CARD ─────────────────────────────────── */
    .product-card {
      display: grid;
      grid-template-columns: 84px 1fr;
      gap: 14px;
      padding: 14px;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
      margin-bottom: 18px;
      align-items: center;
    }
    .product-thumb {
      width: 84px;
      height: 84px;
      object-fit: cover;
      border: 1px solid #e0ddd5;
      background: #fff;
      display: block;
    }
    .product-name {
      margin: 0 0 6px;
      font-size: 14px;
      text-transform: uppercase;
      letter-spacing: .4px;
      font-weight: 700;
    }
    .product-meta {
      margin: 0;
      font-size: 12px;
      color: #666;
      line-height: 1.7;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    /* ── SIZE TABLE ───────────────────────────────────── */
    .size-table {
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    .size-row {
      display: grid;
      grid-template-columns: 1.2fr .8fr .9fr;
      border-bottom: 1px solid #e8e6df;
    }
    .size-row:last-child { border-bottom: 0; }
    .size-cell {
      padding: 14px 12px;
      border-right: 1px solid #e8e6df;
      min-height: 58px;
      display: flex;
      align-items: center;
    }
    .size-cell:last-child { border-right: 0; }
    .size-head {
      background: #f4f2ec;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }
    .size-name {
      font-size: 13px;
      text-transform: uppercase;
      letter-spacing: .4px;
    }
    .stock-input {
      max-width: 110px;
      min-height: 38px !important;
      padding: 0 10px !important;
      background: #fff !important;
      border-color: #d8d5ce !important;
    }
    .toggle-wrap {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 13px;
    }
    .toggle-wrap input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: #111;
      cursor: pointer;
    }

    /* ── BUTTONS ──────────────────────────────────────── */
    .actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 18px; }
    .btn, .btn-secondary {
      height: 48px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      padding: 0 18px;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn:hover { background: #000; }
    .btn-secondary {
      background: #faf9f5;
      color: #111;
      border-color: #d8d5ce;
    }
    .btn-secondary:hover { background: #f4f2ec; }

    /* ── HELPER TEXT ──────────────────────────────────── */
    .helper {
      margin-top: 12px;
      font-size: 12px;
      line-height: 1.7;
      color: #666;
    }

    /* ── ADD CUSTOM SIZE ──────────────────────────────── */
    .add-size-row {
      display: flex;
      gap: 10px;
      align-items: flex-end;
      flex-wrap: wrap;
    }
    .add-size-row .field { margin-bottom: 0; flex: 1; min-width: 140px; }
    .add-size-row .field-narrow { margin-bottom: 0; width: 90px; }

    /* ── TABLE ────────────────────────────────────────── */
    .table-wrap {
      overflow: auto;
      border: 1px solid #d8d5ce;
      background: #faf9f5;
    }
    table { width: 100%; border-collapse: collapse; min-width: 620px; }
    th, td {
      border-bottom: 1px solid #e8e6df;
      padding: 14px 12px;
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
    @media (max-width: 1040px) {
      .layout { grid-template-columns: 1fr; }
      .panel { border-right: 0; border-bottom: 1px solid #d8d5ce; }
    }
    @media (max-width: 680px) {
      .topbar { padding: 0 16px; }
      .size-row { grid-template-columns: 1fr; }
      .size-cell { border-right: 0; border-bottom: 1px solid #e8e6df; }
      .size-cell:last-child { border-bottom: 0; }
      .actions { flex-direction: column; }
      .product-card { grid-template-columns: 1fr; }
      .product-thumb { width: 100%; height: 200px; }
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">

  <!-- TOPBAR -->
  <?php $adminTitle = 'Sizes & stock'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <!-- MAIN LAYOUT -->
  <main class="layout">

    <!-- LEFT: MANAGE PRODUCT SIZES -->
    <section class="panel">
      <div class="panel-head">
        <span>Manage Product Sizes</span>
      </div>
      <div class="panel-body">

        <div class="diagnostic">
          <strong>Diagnostik kolom has_size:</strong><br>
          Status kolom:
          <?= $hasSizeCol
              ? '<span style="font-weight:bold;">DITEMUKAN / OK</span>'
              : '<span style="color:#888;font-weight:bold;">TIDAK ADA</span>' ?>
          <?php if ($alterError !== ''): ?>
            <br>Pesan error saat mencoba menambahkan kolom otomatis:
            <code><?= htmlspecialchars($alterError) ?></code>
          <?php endif; ?>
        </div>

        <?php if ($message !== ''): ?>
          <div class="alert"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
          <div class="alert error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="GET" action="" style="margin-bottom:18px;">
          <div class="field">
            <label for="product_id">Select Product</label>
            <select name="product_id" id="product_id" onchange="this.form.submit()">
              <option value="">Choose product</option>
              <?php foreach ($products as $product): ?>
                <option value="<?= (int) $product['id'] ?>"
                  <?= $selectedProductId === (int) $product['id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($product['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        </form>

        <?php if ($selectedProduct): ?>
          <div class="product-card">
            <img class="product-thumb"
                 src="../<?= htmlspecialchars($selectedProduct['thumbnail']) ?>"
                 alt="<?= htmlspecialchars($selectedProduct['name']) ?>">
            <div>
              <h2 class="product-name"><?= htmlspecialchars($selectedProduct['name']) ?></h2>
              <p class="product-meta">Slug: <?= htmlspecialchars($selectedProduct['slug']) ?></p>
              <p class="product-meta">Category: <?= htmlspecialchars($selectedProduct['category_name'] ?? 'Fashion') ?></p>
              <p class="product-meta">Has Size?: <?= (int)($selectedProduct['has_size'] ?? 0) === 1 ? 'Yes' : 'No (One Size Only)' ?></p>
            </div>
          </div>

          <form method="POST" action="">
            <input type="hidden" name="action" value="update_product_sizes">
            <input type="hidden" name="product_id" value="<?= (int) $selectedProduct['id'] ?>">

            <div class="size-table">
              <div class="size-row size-head">
                <div class="size-cell">Size</div>
                <div class="size-cell">Stock</div>
                <div class="size-cell">Available</div>
              </div>
              <?php foreach ($visibleSizes as $size): ?>
                <?php
                  $currentStock  = $productSizeMap[$size['id']]['stock']     ?? 0;
                  $currentActive = $productSizeMap[$size['id']]['is_active'] ?? 0;
                ?>
                <div class="size-row">
                  <div class="size-cell size-name"><?= htmlspecialchars($size['name']) ?></div>
                  <div class="size-cell">
                    <input type="number" min="0"
                           name="stock[<?= (int) $size['id'] ?>]"
                           value="<?= (int) $currentStock ?>"
                           class="stock-input">
                  </div>
                  <div class="size-cell">
                    <label class="toggle-wrap">
                      <input type="checkbox"
                             name="is_active[<?= (int) $size['id'] ?>]"
                             <?= (int) $currentActive === 1 ? 'checked' : '' ?>>
                      <span>Enable size</span>
                    </label>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="actions">
              <button type="submit" class="btn">Save Size Setup</button>
              <a href="dashboard.php" class="btn-secondary">Back to Dashboard</a>
            </div>
          </form>

          <div class="helper">
            If the category supports multiple sizes, all sizes are shown (including custom). If not, only <strong>One Size</strong> will appear.
          </div>

        <?php else: ?>
          <div class="alert error">Select a product first to manage its sizes and stock.</div>
        <?php endif; ?>
      </div>
    </section>

    <!-- RIGHT: CUSTOM SIZE + OVERVIEW -->
    <section class="panel">
      <div class="panel-head">Create Master Custom Size</div>
      <div class="panel-body" style="border-bottom: 1px solid #d8d5ce;">
        <form method="POST" action="">
          <input type="hidden" name="action" value="add_custom_size">
          <input type="hidden" name="product_id" value="<?= $selectedProductId ?>">
          <div class="add-size-row">
            <div class="field">
              <label for="new_size_name">Size Name</label>
              <input type="text" id="new_size_name" name="new_size_name"
                     placeholder="e.g. Size 1, Size 2, 42" required>
            </div>
            <div class="field field-narrow">
              <label for="new_sort_order">Sort</label>
              <input type="number" id="new_sort_order" name="new_sort_order"
                     value="10" title="Urutan posisi (1 = Paling Atas)">
            </div>
            <button type="submit" class="btn" style="height:44px;padding:0 20px;margin-bottom:0;">Add</button>
          </div>
          <div class="helper" style="margin-top:8px;">
            Added sizes are immediately available in the left panel to assign to any product.
          </div>
        </form>
      </div>

      <div class="panel-head">Size Overview</div>
      <div class="panel-body">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Product</th>
                <th>Slug</th>
                <th>Category</th>
                <th>Size</th>
                <th>Stock</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$sizeOverview): ?>
                <tr><td colspan="6" style="color:#888;">No product sizes configured yet.</td></tr>
              <?php else: ?>
                <?php foreach ($sizeOverview as $row): ?>
                  <tr>
                    <td><?= htmlspecialchars($row['product_name']) ?></td>
                    <td><?= htmlspecialchars($row['slug']) ?></td>
                    <td><?= htmlspecialchars($row['category_name']) ?></td>
                    <td><?= htmlspecialchars($row['size_name']) ?></td>
                    <td><?= (int) $row['stock'] ?></td>
                    <td>
                      <span class="status <?= (int) $row['is_active'] === 1 ? 'live' : '' ?>">
                        <?= (int) $row['is_active'] === 1 ? 'Enabled' : 'Disabled' ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </section>

  </main>
</body>
</html>
