<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

$productStmt = $pdo->query("
    SELECT id, name, slug, price_idr, price_myr, price_sgd, discount_percent, discount_start_at, discount_end_at, is_discount_active
    FROM products
    ORDER BY name ASC
");
$products = $productStmt->fetchAll(PDO::FETCH_ASSOC);

$selectedProductId = (int) ($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
$selectedProduct = null;

foreach ($products as $product) {
    if ((int) $product['id'] === $selectedProductId) {
        $selectedProduct = $product;
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId = (int) ($_POST['product_id'] ?? 0);
    $discountPercent = trim((string) ($_POST['discount_percent'] ?? ''));
    $discountStartAt = trim((string) ($_POST['discount_start_at'] ?? ''));
    $discountEndAt = trim((string) ($_POST['discount_end_at'] ?? ''));
    $isDiscountActive = isset($_POST['is_discount_active']) ? 1 : 0;

    if ($productId <= 0) {
        $error = 'Please select a product.';
    } else {
        $discountValue = null;

        if ($discountPercent !== '') {
            if (!is_numeric($discountPercent)) {
                $error = 'Discount percent must be a number.';
            } else {
                $discountValue = (float) $discountPercent;

                if ($discountValue < 0 || $discountValue > 100) {
                    $error = 'Discount percent must be between 0 and 100.';
                }
            }
        }

        if ($error === '' && $discountStartAt !== '' && $discountEndAt !== '') {
            if (strtotime($discountEndAt) < strtotime($discountStartAt)) {
                $error = 'Discount end date must be after start date.';
            }
        }

        if ($error === '') {
            $update = $pdo->prepare("
                UPDATE products
                SET discount_percent = :discount_percent,
                    discount_start_at = :discount_start_at,
                    discount_end_at = :discount_end_at,
                    is_discount_active = :is_discount_active,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $update->execute([
                'discount_percent' => $discountValue,
                'discount_start_at' => $discountStartAt !== '' ? date('Y-m-d H:i:s', strtotime($discountStartAt)) : null,
                'discount_end_at' => $discountEndAt !== '' ? date('Y-m-d H:i:s', strtotime($discountEndAt)) : null,
                'is_discount_active' => $isDiscountActive,
                'id' => $productId,
            ]);

            header('Location: set-diskon.php?product_id=' . $productId . '&success=1');
            exit;
        }
    }
}

if (isset($_GET['success']) && $_GET['success'] === '1') {
    $message = 'Discount updated successfully.';
    $selectedProductId = (int) ($_GET['product_id'] ?? 0);

    foreach ($products as $product) {
        if ((int) $product['id'] === $selectedProductId) {
            $selectedProduct = $product;
            break;
        }
    }

    $refreshStmt = $pdo->prepare("
        SELECT id, name, slug, price_idr, price_myr, price_sgd, discount_percent, discount_start_at, discount_end_at, is_discount_active
        FROM products
        WHERE id = :id
        LIMIT 1
    ");
    $refreshStmt->execute(['id' => $selectedProductId]);
    $selectedProduct = $refreshStmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Set Discount</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }
    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #efefef;
      color: #111;
    }
    a { color: inherit; text-decoration: none; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #efefef;
      border-bottom: 1px solid #bdbdbd;
    }
    .topbar__left, .topbar__right {
      display: flex;
      align-items: center;
      gap: 18px;
    }
    .topbar__right { justify-content: flex-end; }
    .logo {
      width: 92px;
      justify-self: center;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .logo img {
      width: 100%;
      height: 24px;
      object-fit: contain;
    }
    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    .wrap {
      max-width: 1000px;
      margin: 0 auto;
      border-left: 1px solid #bdbdbd;
      border-right: 1px solid #bdbdbd;
      min-height: calc(100vh - 74px);
      background: #efefef;
    }

    .head {
      padding: 20px;
      border-bottom: 1px solid #bdbdbd;
    }
    .title {
      margin: 0 0 8px;
      font-size: 30px;
      text-transform: uppercase;
      font-weight: 400;
    }
    .sub {
      margin: 0;
      color: #555;
      line-height: 1.7;
      font-size: 14px;
    }

    .notice {
      margin: 20px;
      padding: 14px 16px;
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      font-size: 13px;
    }
    .notice.success {
      border-color: #111;
    }

    .panel {
      padding: 20px;
    }

    .form-box, .info-box {
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      padding: 20px;
      margin-bottom: 20px;
    }

    .field {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 16px;
    }

    label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    input, select {
      width: 100%;
      min-height: 48px;
      border: 1px solid #bdbdbd;
      background: #fff;
      padding: 12px 14px;
      outline: none;
      font: inherit;
      color: #111;
    }

    .check-row {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-bottom: 18px;
    }
    .check-row input {
      width: auto;
      min-height: auto;
    }

    .btn, .btn-secondary {
      min-height: 48px;
      padding: 0 18px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      text-transform: uppercase;
      font-size: 12px;
      letter-spacing: .6px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }
    .btn-secondary {
      background: transparent;
      color: #111;
      border-color: #999;
    }

    .actions {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
    }

    .info-row {
      margin-bottom: 10px;
      line-height: 1.7;
      font-size: 14px;
    }
  </style>
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
</head>
<body class="adm">
  <?php $adminTitle = 'Discounts'; include __DIR__ . '/../includes/admin-header.php'; ?>

  <main class="wrap">
    <section class="head">
      <h1 class="title">Set Discount</h1>
      <p class="sub">Choose a product, set a discount percentage, and schedule when the discount is active.</p>
    </section>

    <?php if ($message !== ''): ?>
      <div class="notice success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="notice"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <section class="panel">
      <form method="POST" class="form-box">
        <div class="field">
          <label for="product_id">Select Product</label>
          <select name="product_id" id="product_id" onchange="this.form.submit()">
            <option value="">Choose product</option>
            <?php foreach ($products as $product): ?>
              <option value="<?= (int) $product['id'] ?>" <?= (int) $selectedProductId === (int) $product['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($product['name']) ?> (<?= htmlspecialchars($product['slug']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <?php if ($selectedProduct): ?>
          <div class="info-box">
            <div class="info-row"><strong>Product:</strong> <?= htmlspecialchars($selectedProduct['name']) ?></div>
            <div class="info-row"><strong>Slug:</strong> <?= htmlspecialchars($selectedProduct['slug']) ?></div>
            <div class="info-row"><strong>Price IDR:</strong> IDR <?= number_format((float) $selectedProduct['price_idr'], 2, '.', ',') ?></div>
            <div class="info-row"><strong>Price MYR:</strong> MYR <?= number_format((float) $selectedProduct['price_myr'], 2, '.', ',') ?></div>
            <div class="info-row"><strong>Price SGD:</strong> SGD <?= number_format((float) $selectedProduct['price_sgd'], 2, '.', ',') ?></div>
            <div class="info-row"><strong>Current Discount:</strong> <?= $selectedProduct['discount_percent'] !== null ? number_format((float) $selectedProduct['discount_percent'], 2) . '%' : 'No discount' ?></div>
          </div>

          <div class="field">
            <label for="discount_percent">Discount Percent</label>
            <input
              type="number"
              step="0.01"
              min="0"
              max="100"
              name="discount_percent"
              id="discount_percent"
              value="<?= htmlspecialchars((string) ($selectedProduct['discount_percent'] ?? '')) ?>"
              placeholder="Example: 20"
            >
          </div>

          <div class="field">
            <label for="discount_start_at">Discount Start</label>
            <input
              type="datetime-local"
              name="discount_start_at"
              id="discount_start_at"
              value="<?= !empty($selectedProduct['discount_start_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($selectedProduct['discount_start_at']))) : '' ?>"
            >
          </div>

          <div class="field">
            <label for="discount_end_at">Discount End</label>
            <input
              type="datetime-local"
              name="discount_end_at"
              id="discount_end_at"
              value="<?= !empty($selectedProduct['discount_end_at']) ? htmlspecialchars(date('Y-m-d\TH:i', strtotime($selectedProduct['discount_end_at']))) : '' ?>"
            >
          </div>

          <div class="check-row">
            <input
              type="checkbox"
              name="is_discount_active"
              id="is_discount_active"
              value="1"
              <?= (int) ($selectedProduct['is_discount_active'] ?? 0) === 1 ? 'checked' : '' ?>
            >
            <label for="is_discount_active" style="margin:0;">Enable discount</label>
          </div>

          <div class="actions">
            <button type="submit" class="btn">Save Discount</button>
            <a href="set-diskon.php" class="btn-secondary">Reset</a>
          </div>
        <?php endif; ?>
      </form>
    </section>
  </main>
</body>
</html>
