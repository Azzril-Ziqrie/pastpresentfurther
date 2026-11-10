<?php
session_start();

// Tangkap pilihan currency dari URL (sama seperti index.php / product.php)
if (isset($_GET['currency']) && in_array(strtoupper($_GET['currency']), ['IDR', 'USD'])) {
    $_SESSION['currency'] = strtoupper($_GET['currency']);
}

require_once __DIR__ . '/config/database.php';

/* ── brand constants ─────────────────────────────────── */
$brandName = 'PASTPRESENTFURTHER';
$brandLogo = 'assets/images/brand/logo.png';

// Default ke IDR jika belum ada session
$currentCurrency = $_SESSION['currency'] ?? 'IDR';

/* ── helpers ─────────────────────────────────────────── */
function moneyFormatCheckout(float $amount, string $currency): string
{
    if ($currency === 'USD') {
        return '$' . number_format($amount, 2, '.', ',');
    }
    return 'RP ' . number_format($amount, 0, ',', '.');
}

function makeOrderNumber(): string
{
    return 'PPF-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
}

function normalizeCurrency(string $currency): string
{
    $cur = strtoupper(trim($currency));
    return in_array($cur, ['IDR', 'USD'], true) ? $cur : 'IDR';
}

function isDiscountCurrentlyActiveCheckout(array $item): bool
{
    if ((int)($item['is_discount_active'] ?? 0) !== 1) return false;
    if ((float)($item['discount_percent'] ?? 0) <= 0)  return false;
    $now = time();
    if (!empty($item['discount_start_at'])) {
        $s = strtotime((string)$item['discount_start_at']);
        if ($s !== false && $now < $s) return false;
    }
    if (!empty($item['discount_end_at'])) {
        $e = strtotime((string)$item['discount_end_at']);
        if ($e !== false && $now > $e) return false;
    }
    return true;
}

function getProductSizeRow(PDO $pdo, string $slug, int $productSizeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id AS product_id, p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.id AS product_size_id, ps.stock, ps.is_active, s.name AS size_name
         FROM products p
         INNER JOIN product_sizes ps ON ps.product_id = p.id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.slug = :slug AND ps.id = :product_size_id AND p.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['slug' => $slug, 'product_size_id' => $productSizeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && (int)$row['is_active'] === 1) ? $row : null;
}

function getProductSizeById(PDO $pdo, int $productSizeId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT p.id AS product_id, p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.id AS product_size_id, ps.stock, ps.is_active, s.name AS size_name
         FROM product_sizes ps
         INNER JOIN products p ON p.id = ps.product_id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE ps.id = :product_size_id AND p.is_active = 1
         LIMIT 1'
    );
    $stmt->execute(['product_size_id' => $productSizeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return ($row && (int)$row['is_active'] === 1) ? $row : null;
}

function getPriceByCurrency(array $item, string $currency): float
{
    if ($currency === 'USD') {
        $usd = (float)($item['price_usd'] ?? 0);
        if ($usd > 0) {
            $base = $usd;
        } else {
            $usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000;
            $base = (float)($item['price_idr'] ?? 0) / $usdRateFromIdr;
        }
    } else {
        $base = (float)($item['price_idr'] ?? 0);
    }

    if (isDiscountCurrentlyActiveCheckout($item)) {
        $base = max($base - ($base * ((float)($item['discount_percent'] ?? 0) / 100)), 0);
    }

    return $base;
}

function seedGuestCartFromQuery(PDO $pdo): void
{
    if (!isset($_GET['guest']) || $_GET['guest'] !== '1') return;
    $productSlug = trim((string)($_GET['product'] ?? ''));
    $sizeId      = (int)($_GET['size_id'] ?? 0);
    $qty         = (int)($_GET['qty'] ?? 1);
    
    $currency    = normalizeCurrency((string)($_GET['currency'] ?? ($_SESSION['currency'] ?? 'IDR')));
    if ($productSlug === '' || $sizeId <= 0 || $qty <= 0) return;
    $row = getProductSizeRow($pdo, $productSlug, $sizeId);
    if (!$row || (int)$row['stock'] <= 0) return;
    $qty = min($qty, (int)$row['stock']);
    if (!isset($_SESSION['guest_cart']) || !is_array($_SESSION['guest_cart'])) $_SESSION['guest_cart'] = [];
    $found = false;
    foreach ($_SESSION['guest_cart'] as &$ci) {
        if ((int)($ci['product_id'] ?? 0) === (int)$row['product_id'] &&
            (int)($ci['product_size_id'] ?? 0) === (int)$row['product_size_id'] &&
            strtoupper((string)($ci['currency'] ?? 'IDR')) === $currency) {
            $ci['quantity'] = min((int)$ci['quantity'] + $qty, (int)$row['stock']);
            $found = true; break;
        }
    }
    unset($ci);
    if (!$found) $_SESSION['guest_cart'][] = [
        'product_id' => (int)$row['product_id'], 'product_size_id' => (int)$row['product_size_id'],
        'slug' => $row['slug'], 'quantity' => $qty, 'currency' => $currency,
    ];
}

function getGuestCartItemsFromSession(PDO $pdo): array
{
    global $currentCurrency;
    $activeCurrency = normalizeCurrency($currentCurrency);
    $sessionCart = $_SESSION['guest_cart'] ?? [];
    if (!is_array($sessionCart) || !$sessionCart) return [];
    $items = [];
    foreach ($sessionCart as $si) {
        $slug = trim((string)($si['slug'] ?? '')); $psid = (int)($si['product_size_id'] ?? 0);
        $qty  = (int)($si['quantity'] ?? 0); $cur = $activeCurrency;
        if ($slug === '' || $psid <= 0 || $qty <= 0) continue;
        $row = getProductSizeRow($pdo, $slug, $psid);
        if (!$row || (int)$row['stock'] <= 0) continue;
        $qty   = min($qty, (int)$row['stock']);
        $price = getPriceByCurrency($row, $cur);
        $items[] = ['cart_item_id' => null, 'product_id' => (int)$row['product_id'],
            'product_size_id' => (int)$row['product_size_id'], 'name' => $row['name'],
            'slug' => $row['slug'], 'thumbnail' => $row['thumbnail'], 'size_name' => $row['size_name'],
            'category_slug' => $row['category_slug'] ?? 'fashion', 'quantity' => $qty,
            'price' => $price, 'currency' => $cur, 'line_total' => $price * $qty, 'stock' => (int)$row['stock']];
    }
    return $items;
}

function getBuyNowItemsFromSession(PDO $pdo): array
{
    global $currentCurrency;
    $activeCurrency = normalizeCurrency($currentCurrency);

    $bn = $_SESSION['buy_now'] ?? [];
    if (!is_array($bn) || !$bn) return [];
    $pid = (int)($bn['product_id'] ?? 0); $psid = (int)($bn['product_size_id'] ?? 0);
    $qty = (int)($bn['quantity'] ?? 0); $cur = $activeCurrency;
    if ($pid <= 0 || $psid <= 0 || $qty <= 0) return [];
    $row = getProductSizeById($pdo, $psid);
    if (!$row || (int)$row['product_id'] !== $pid || (int)$row['stock'] <= 0) return [];
    $qty = min($qty, (int)$row['stock']); $_SESSION['buy_now']['quantity'] = $qty;
    $price = getPriceByCurrency($row, $cur);
    return [['cart_item_id' => null, 'product_id' => (int)$row['product_id'],
        'product_size_id' => (int)$row['product_size_id'], 'name' => $row['name'],
        'slug' => $row['slug'], 'thumbnail' => $row['thumbnail'], 'size_name' => $row['size_name'],
        'category_slug' => $row['category_slug'] ?? 'fashion', 'quantity' => $qty,
        'price' => $price, 'currency' => $cur, 'line_total' => $price * $qty, 'stock' => (int)$row['stock']]];
}

function getMemberCartItems(PDO $pdo, int $userId): array
{
    global $currentCurrency;
    $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
    $cartStmt->execute(['uid' => $userId, 's' => 'active']);
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
    if (!$cart) return [];

    $stmt = $pdo->prepare(
        'SELECT ci.id AS cart_item_id, ci.product_id, ci.product_size_id, ci.quantity,
                p.name, p.slug, p.thumbnail, p.price_idr, p.price_usd,
                p.discount_percent, p.discount_start_at, p.discount_end_at, p.is_discount_active,
                s.name AS size_name, COALESCE(c.slug,\'fashion\') AS category_slug,
                ps.stock
         FROM cart_items ci
         INNER JOIN products p ON p.id=ci.product_id
         LEFT JOIN product_sizes ps ON ps.id=ci.product_size_id
         LEFT JOIN sizes s ON s.id=ps.size_id
         LEFT JOIN categories c ON c.id=p.category_id
         WHERE ci.cart_id=:cid ORDER BY ci.id DESC'
    );
    $stmt->execute(['cid' => $cart['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as &$item) {
        $price = getPriceByCurrency($item, $currentCurrency);
        $item['line_total'] = (float)$price * (int)$item['quantity'];
        $item['currency'] = $currentCurrency;
        $item['price'] = $price;
        $item['stock'] = (int)$item['stock'];
    }
    unset($item);
    return $items;
}

function validateCheckoutStock(PDO $pdo, array $cartItems): void
{
    foreach ($cartItems as $item) {
        $psid = (int)($item['product_size_id'] ?? 0); $qty = (int)($item['quantity'] ?? 0);
        if ($psid <= 0 || $qty <= 0) throw new Exception('Invalid checkout item detected.');
        $row = getProductSizeById($pdo, $psid);
        if (!$row) throw new Exception('Selected size no longer available for ' . $item['name'] . '.');
        if ((int)$row['stock'] < $qty) throw new Exception('Stock not enough for ' . $item['name'] . '.');
    }
}

/* ── state ───────────────────────────────────────────── */
$isLoggedIn = isset($_SESSION['user']['id']);
$userId     = $isLoggedIn ? (int)$_SESSION['user']['id'] : null;
$userData   = $_SESSION['user'] ?? [];

$checkoutMode = strtolower(trim((string)($_POST['checkout_mode'] ?? $_GET['mode'] ?? 'cart')));
if ($checkoutMode !== 'buy_now') $checkoutMode = 'cart';

/* ════════════════════════════════════════════════════════════
   AJAX HANDLER: UPDATE QUANTITY LANGSUNG DI HALAMAN CHECKOUT
═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_update_qty'])) {
    header('Content-Type: application/json');
    $psid = (int)($_POST['product_size_id'] ?? 0);
    $newQty = (int)($_POST['qty'] ?? 0);

    if ($psid <= 0 || $newQty < 1) {
        echo json_encode(['success' => false, 'message' => 'Invalid quantity.']);
        exit;
    }

    $row = getProductSizeById($pdo, $psid);
    if (!$row || $row['stock'] < $newQty) {
        echo json_encode(['success' => false, 'message' => 'Max stock is ' . ($row['stock'] ?? 0)]);
        exit;
    }

    if ($checkoutMode === 'buy_now') {
        if (isset($_SESSION['buy_now']) && (int)$_SESSION['buy_now']['product_size_id'] === $psid) {
            $_SESSION['buy_now']['quantity'] = $newQty;
        }
    } else {
        if ($isLoggedIn) {
            $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
            $cartStmt->execute(['uid' => $userId, 's' => 'active']);
            $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
            if ($cart) {
                $upd = $pdo->prepare('UPDATE cart_items SET quantity=:qty WHERE cart_id=:cid AND product_size_id=:psid');
                $upd->execute(['qty' => $newQty, 'cid' => $cart['id'], 'psid' => $psid]);
            }
        } else {
            if (isset($_SESSION['guest_cart'])) {
                foreach ($_SESSION['guest_cart'] as &$ci) {
                    if ((int)($ci['product_size_id'] ?? 0) === $psid) {
                        $ci['quantity'] = $newQty;
                        break;
                    }
                }
                unset($ci);
            }
        }
    }

    $cartItemsUpdated = $checkoutMode === 'buy_now'
        ? getBuyNowItemsFromSession($pdo)
        : ($isLoggedIn ? getMemberCartItems($pdo, $userId) : getGuestCartItemsFromSession($pdo));

    $newSubtotal = 0.0;
    $newLineTotal = 0.0;
    foreach ($cartItemsUpdated as $it) {
        $newSubtotal += (float)$it['line_total'];
        if ((int)$it['product_size_id'] === $psid) {
            $newLineTotal = (float)$it['line_total'];
        }
    }

    echo json_encode([
        'success' => true,
        'new_qty' => $newQty,
        'new_line_total' => $newLineTotal,
        'new_subtotal' => $newSubtotal
    ]);
    exit;
}
/* ════════════════════════════════════════════════════════════ */

/* ════════════════════════════════════════════════════════════
   AJAX HANDLER: HAPUS ITEM DARI CHECKOUT
═══════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_remove_item'])) {
    header('Content-Type: application/json');
    $psid = (int)($_POST['product_size_id'] ?? 0);

    if ($psid > 0) {
        if ($checkoutMode === 'buy_now') {
            unset($_SESSION['buy_now']);
        } else {
            if ($isLoggedIn) {
                $cartStmt = $pdo->prepare('SELECT id FROM carts WHERE user_id=:uid AND status=:s LIMIT 1');
                $cartStmt->execute(['uid' => $userId, 's' => 'active']);
                $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);
                if ($cart) {
                    $del = $pdo->prepare('DELETE FROM cart_items WHERE cart_id=:cid AND product_size_id=:psid');
                    $del->execute(['cid' => $cart['id'], 'psid' => $psid]);
                }
            } else {
                if (isset($_SESSION['guest_cart'])) {
                    foreach ($_SESSION['guest_cart'] as $k => $ci) {
                        if ((int)($ci['product_size_id'] ?? 0) === $psid) {
                            unset($_SESSION['guest_cart'][$k]);
                        }
                    }
                    $_SESSION['guest_cart'] = array_values($_SESSION['guest_cart']);
                }
            }
        }
    }

    echo json_encode(['success' => true]);
    exit;
}
/* ════════════════════════════════════════════════════════════ */

if (!$isLoggedIn && $checkoutMode === 'cart') seedGuestCartFromQuery($pdo);

$cartItems = $checkoutMode === 'buy_now'
    ? getBuyNowItemsFromSession($pdo)
    : ($isLoggedIn ? getMemberCartItems($pdo, $userId) : getGuestCartItemsFromSession($pdo));

$subtotal = 0.0; $currency = $currentCurrency;
foreach ($cartItems as $item) { $subtotal += (float)$item['line_total']; }

// Rate konversi USD (1 USD = ... IDR) — sinkron dengan JS
$usdRateFromIdr = defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000;

$message = ''; $error = '';
$selectedShippingCost = 0.0;
$grandTotal = $subtotal;

$form = [
    'full_name' => $userData['full_name'] ?? '', 'email' => $userData['email'] ?? '',
    'phone' => '', 'shipping_mode' => 'domestic', 'country_code' => 'ID',
    'country_name' => 'Indonesia', 'address_line_1' => '', 'address_line_2' => '',
    'city' => '', 'state' => '', 'postal_code' => '',
    'courier_code' => '', 'courier_service' => '', 'shipping_cost' => '0', 'notes' => '',
];

// Pastikan proses checkout hanya jalan jika POST biasa (bukan request AJAX qty / hapus)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['ajax_update_qty']) && !isset($_POST['ajax_remove_item'])) {
    foreach ($form as $key => $value) $form[$key] = trim((string)($_POST[$key] ?? ''));
    $selectedShippingCost = (float)$form['shipping_cost'];
    $grandTotal = $subtotal + $selectedShippingCost;
    $shippingCostIdr = (float)($_POST['shipping_cost_idr'] ?? 0);

    if (!$cartItems) {
        $error = 'No items available for checkout.';
    } elseif ($form['full_name'] === '' || $form['email'] === '' || $form['phone'] === '' ||
              $form['country_code'] === '' || $form['address_line_1'] === '' ||
              $form['city'] === '' || $form['postal_code'] === '') {
        $error = 'Please complete all required fields.';
    } elseif ($form['courier_code'] === '' || $form['courier_service'] === '') {
        $error = 'Please calculate shipping and choose a courier service.';
    } elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif ($selectedShippingCost < 0) {
        $error = 'Invalid shipping amount.';
    } else {
        try {
            validateCheckoutStock($pdo, $cartItems);
            $pdo->beginTransaction();
            $orderNumber = makeOrderNumber();

            $pdo->prepare(
                'INSERT INTO orders (order_number,user_id,customer_type,full_name,email,phone,
                 country_code,country_name,address_line_1,address_line_2,city,state,postal_code,
                 courier_code,courier_service,currency,subtotal,shipping_cost,grand_total,
                 payment_provider,payment_status,fulfillment_status,notes)
                 VALUES (:on,:uid,:ct,:fn,:em,:ph,:cc,:cn,:a1,:a2,:ci,:st,:pc,:coc,:cs,:cur,
                         :sub,:sc,:gt,:pp,:ps,:fs,:no)'
            )->execute([
                'on' => $orderNumber, 'uid' => $userId,
                'ct' => $isLoggedIn ? 'member' : 'guest',
                'fn' => $form['full_name'], 'em' => $form['email'], 'ph' => $form['phone'],
                'cc' => $form['country_code'], 'cn' => $form['country_name'],
                'a1' => $form['address_line_1'],
                'a2' => $form['address_line_2'] !== '' ? $form['address_line_2'] : null,
                'ci' => $form['city'],
                'st' => $form['state'] !== '' ? $form['state'] : null,
                'pc' => $form['postal_code'], 'coc' => $form['courier_code'],
                'cs' => $form['courier_service'], 'cur' => $currency,
                'sub' => $subtotal, 'sc' => $selectedShippingCost, 'gt' => $grandTotal,
                'pp' => 'manual_transfer', 'ps' => 'pending', 'fs' => 'pending',
                'no' => $form['notes'] !== '' ? $form['notes'] : null,
            ]);
            $orderId = (int)$pdo->lastInsertId();

            $insertItem = $pdo->prepare(
                'INSERT INTO order_items (order_id,product_id,product_size_id,product_name,
                 product_slug,size_name,quantity,price,line_total,thumbnail)
                 VALUES (:oid,:pid,:psid,:pn,:ps,:sn,:qty,:pr,:lt,:th)'
            );
            foreach ($cartItems as $item) {
                $insertItem->execute([
                    'oid' => $orderId, 'pid' => $item['product_id'],
                    'psid' => $item['product_size_id'], 'pn' => $item['name'],
                    'ps' => $item['slug'],
                    'sn' => $item['size_name'] !== '' ? $item['size_name'] : null,
                    'qty' => $item['quantity'], 'pr' => $item['price'],
                    'lt' => $item['line_total'], 'th' => $item['thumbnail'],
                ]);
            }
            $pdo->prepare(
                'INSERT INTO order_status_logs (order_id,status_type,status_value,notes)
                 VALUES (:oid,:st,:sv,:n)'
            )->execute(['oid' => $orderId, 'st' => 'payment', 'sv' => 'pending',
                        'n' => 'Order created. Waiting for manual transfer.']);
            $pdo->commit();

            if ($checkoutMode === 'buy_now') unset($_SESSION['buy_now']);
            elseif (!$isLoggedIn) $_SESSION['guest_cart'] = [];

            header('Location: payment.php?order=' . urlencode($orderNumber));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = 'Failed to create order: ' . $e->getMessage();
        }
    }
}

$domesticCouriers      = ['jne' => 'JNE', 'jnt' => 'JNT'];
$internationalCouriers = ['pos' => 'POS Indonesia'];
$isBuyNow  = $checkoutMode === 'buy_now';
$pageTitle = $isBuyNow ? 'Checkout Now' : 'Checkout';

$backToProductUrl = 'shop.php';
if (!empty($cartItems)) {
    $first = $cartItems[0];
    $firstSlug = (string)($first['slug'] ?? '');
    if ($firstSlug !== '') {
        $backToProductUrl = 'product.php?slug=' . urlencode($firstSlug)
            . ($currentCurrency === 'USD' ? '&currency=USD' : '');
    }
} elseif ($currentCurrency === 'USD') {
    $backToProductUrl = 'shop.php?currency=USD';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($brandLogo) ?>">
  <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars($brandName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@100..800&display=swap">
  <link rel="stylesheet" href="assets/css/checkout.css?v=<?= filemtime(__DIR__ . '/assets/css/checkout.css') ?>">
</head>
<body>
  <?php if (file_exists(__DIR__ . '/includes/loader.php')) include_once __DIR__ . '/includes/loader.php'; ?>

  <div class="toast-container" id="toastContainer" aria-live="polite"></div>

  <header class="co-head">
    <a href="<?= htmlspecialchars($backToProductUrl) ?>" class="co-back">← Back</a>
    <a href="index.php" class="co-head__logo" aria-label="<?= htmlspecialchars($brandName) ?>">
      <img src="<?= htmlspecialchars($brandLogo) ?>" alt="<?= htmlspecialchars($brandName) ?>">
    </a>
    <span class="co-bag" aria-label="Items in cart">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8h12l-1 12H7z"/><path d="M9 8V6a3 3 0 0 1 6 0v2"/></svg>
      <span id="cartCountHeader"><?= count($cartItems) ?></span>
    </span>
  </header>

  <div class="co-layout">

    <!-- ═══ LEFT: FORM ═══ -->
    <div class="co-left">
      <div class="co-left__in">

        <?php if ($error !== ''): ?>
          <div class="co-notice co-notice--error" role="alert"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
          <div class="co-notice" role="status"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <form method="POST" action="checkout.php<?= $isBuyNow ? '?mode=buy_now' : '' ?><?= $currentCurrency === 'USD' ? ($isBuyNow ? '&' : '?').'currency=USD' : '' ?>" id="checkoutForm">
          <input type="hidden" name="checkout_mode" value="<?= htmlspecialchars($checkoutMode) ?>">
          <input type="hidden" name="currency" value="<?= htmlspecialchars($currentCurrency) ?>">

          <!-- Contact -->
          <section class="co-sec" aria-label="Contact">
            <div class="co-sec__head"><h2 class="co-sec__title">Contact</h2></div>
            <div class="co-stack">
              <div class="fld">
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($form['email']) ?>" placeholder=" " autocomplete="email" required>
                <label for="email">Email <span class="req">*</span></label>
              </div>
              <div class="fld">
                <input type="text" id="phone" name="phone" value="<?= htmlspecialchars($form['phone']) ?>" placeholder=" " autocomplete="tel" inputmode="tel" required>
                <label for="phone">Phone <span class="req">*</span></label>
              </div>
            </div>
          </section>

          <!-- Delivery -->
          <section class="co-sec" aria-label="Delivery">
            <div class="co-sec__head"><h2 class="co-sec__title">Delivery</h2></div>
            <div class="co-stack">
              <div class="fld fld--select">
                <select id="shipping_mode" name="shipping_mode">
                  <option value="domestic" <?= $form['shipping_mode'] === 'domestic' ? 'selected' : '' ?>>Indonesia (Domestic)</option>
                  <option value="international" <?= $form['shipping_mode'] === 'international' ? 'selected' : '' ?>>International</option>
                </select>
                <label for="shipping_mode">Country / Region</label>
              </div>

              <div class="fld">
                <input type="text" id="full_name" name="full_name" value="<?= htmlspecialchars($form['full_name']) ?>" placeholder=" " autocomplete="name" required>
                <label for="full_name">Full name <span class="req">*</span></label>
              </div>

              <div class="fld">
                <input type="text" id="destination_search" placeholder=" " autocomplete="off">
                <label for="destination_search" id="destinationSearchLabel">City / District *</label>
              </div>
              <div>
                <div class="fld fld--select">
                  <select id="destination_select"><option value="">Choose destination</option></select>
                  <label for="destination_select">Select destination <span class="req">*</span></label>
                </div>
                <p class="co-hint">Type at least 2 letters above, then pick the exact destination. Incorrect addresses may cause delivery failure.</p>
              </div>

              <div class="fld">
                <input type="text" id="address_line_1" name="address_line_1" value="<?= htmlspecialchars($form['address_line_1']) ?>" placeholder=" " autocomplete="address-line1" required>
                <label for="address_line_1">Address <span class="req">*</span></label>
              </div>

              <div class="co-row2 co-row2--stay">
                <div class="fld">
                  <input type="text" id="city" name="city" value="<?= htmlspecialchars($form['city']) ?>" placeholder=" " autocomplete="address-level2" required>
                  <label for="city">City <span class="req">*</span></label>
                </div>
                <div class="fld">
                  <input type="text" id="postal_code" name="postal_code" value="<?= htmlspecialchars($form['postal_code']) ?>" placeholder=" " autocomplete="postal-code" required>
                  <label for="postal_code">Postal code <span class="req">*</span></label>
                </div>
              </div>

              <!-- Optional details live in a dropdown to keep the form short -->
              <details class="co-more"<?= ($form['address_line_2'] !== '' || $form['state'] !== '' || $form['notes'] !== '') ? ' open' : '' ?>>
                <summary><span>More details <small>(optional)</small></span></summary>
                <div class="co-more__body">
                  <div class="fld">
                    <input type="text" id="address_line_2" name="address_line_2" value="<?= htmlspecialchars($form['address_line_2']) ?>" placeholder=" " autocomplete="address-line2">
                    <label for="address_line_2">Apartment, building, floor</label>
                  </div>
                  <div class="fld">
                    <input type="text" id="state" name="state" value="<?= htmlspecialchars($form['state']) ?>" placeholder=" " autocomplete="address-level1">
                    <label for="state">Province / State</label>
                  </div>
                  <div class="fld">
                    <textarea id="notes" name="notes" placeholder=" "><?= htmlspecialchars($form['notes']) ?></textarea>
                    <label for="notes">Notes for the seller</label>
                  </div>
                </div>
              </details>
            </div>
          </section>

          <!-- Shipping method -->
          <section class="co-sec" aria-label="Shipping method">
            <div class="co-sec__head"><h2 class="co-sec__title">Shipping method</h2></div>
            <div class="co-stack">
              <div class="fld fld--select">
                <select id="courier_picker"></select>
                <label for="courier_picker">Courier <span class="req">*</span></label>
              </div>
              <div id="shippingOptions" class="shipping-options-box">Select destination and courier to see shipping options.</div>
            </div>
          </section>

          <!-- Payment (chosen on the next step) -->
          <section class="co-sec" aria-label="Payment">
            <div class="co-sec__head"><h2 class="co-sec__title">Payment</h2></div>
            <p class="co-sec__sub">All transactions are secure and encrypted.</p>
            <div class="shipping-options-box">Bank transfer, QRIS or PayPal — you will choose on the next step.</div>
          </section>

          <!-- Hidden fields -->
          <input type="hidden" name="country_code"      id="country_code"      value="<?= htmlspecialchars($form['country_code']) ?>">
          <input type="hidden" name="country_name"      id="country_name"      value="<?= htmlspecialchars($form['country_name']) ?>">
          <input type="hidden" name="courier_code"      id="courier_code"      value="<?= htmlspecialchars($form['courier_code']) ?>">
          <input type="hidden" name="courier_service"   id="courier_service"   value="<?= htmlspecialchars($form['courier_service']) ?>">
          <input type="hidden" name="shipping_cost"     id="shipping_cost"     value="<?= htmlspecialchars($form['shipping_cost']) ?>">
          <input type="hidden" name="shipping_cost_idr" id="shipping_cost_idr" value="0">

          <button type="submit" class="btn-primary" id="submitBtn">Continue to payment →</button>
          <p class="co-note">By continuing you agree to our <a class="co-link" href="legal.php">terms</a>. We never store your payment details.</p>
        </form>

        <nav class="co-foot" aria-label="Policies">
          <a href="shipping.php">Shipping &amp; returns</a>
          <a href="legal.php">Legal</a>
          <a href="contact.php">Contact</a>
        </nav>
      </div>
    </div>

    <!-- ═══ RIGHT: SUMMARY ═══ -->
    <aside class="co-right" aria-label="Order summary">
      <button type="button" class="co-sum-toggle" id="sumToggle" aria-expanded="false" aria-controls="sumBody">
        <span class="lbl">Show order summary</span><i class="chev"></i>
        <strong id="heroGrandTotal"><?= htmlspecialchars(moneyFormatCheckout($grandTotal, $currency)) ?></strong>
      </button>

      <div class="co-right__in">
        <div class="co-sum-body" id="sumBody">
          <?php if (!$cartItems): ?>
            <div class="co-empty">No items in cart.</div>
          <?php else: ?>
            <?php foreach ($cartItems as $item): $psid = (int)$item['product_size_id']; ?>
              <div class="co-item" id="row_psid_<?= $psid ?>">
                <div class="co-thumb">
                  <img src="<?= htmlspecialchars($item['thumbnail']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" loading="lazy">
                  <span class="co-badge" id="qty_badge_<?= $psid ?>"><?= (int)$item['quantity'] ?></span>
                </div>
                <div>
                  <div class="co-item__name"><?= htmlspecialchars($item['name']) ?></div>
                  <?php if (strtolower((string)($item['category_slug'] ?? 'fashion')) === 'fashion'): ?>
                    <div class="co-item__meta">Size: <?= htmlspecialchars((string)$item['size_name']) ?></div>
                  <?php endif; ?>
                  <div class="co-qty">
                    <button type="button" aria-label="Decrease" onclick="updateCheckoutQty(<?= $psid ?>, -1, <?= (int)$item['stock'] ?>)">−</button>
                    <span id="qty_display_<?= $psid ?>"><?= (int)$item['quantity'] ?></span>
                    <button type="button" aria-label="Increase" onclick="updateCheckoutQty(<?= $psid ?>, 1, <?= (int)$item['stock'] ?>)">+</button>
                    <button type="button" class="co-remove" aria-label="Remove item" onclick="removeCheckoutItem(<?= $psid ?>)">Remove</button>
                  </div>
                </div>
                <div class="co-item__price" id="line_total_display_<?= $psid ?>"><?= htmlspecialchars(moneyFormatCheckout((float)$item['line_total'], $item['currency'])) ?></div>
              </div>
            <?php endforeach; ?>

            <div class="co-totals">
              <div class="co-total-row"><span>Subtotal</span><span id="subtotalDisplay"><?= htmlspecialchars(moneyFormatCheckout($subtotal, $currency)) ?></span></div>
              <div class="co-total-row"><span>Shipping</span><span id="shippingDisplay"><?= htmlspecialchars(moneyFormatCheckout($selectedShippingCost, $currency)) ?></span></div>
              <div class="co-total-row co-total-row--grand"><span>Total</span><span><small><?= htmlspecialchars($currency) ?></small><span id="grandTotalDisplay"><?= htmlspecialchars(moneyFormatCheckout($grandTotal, $currency)) ?></span></span></div>
            </div>
            <p class="co-note">Shipping updates after you select a courier service.</p>
          <?php endif; ?>
        </div>
      </div>
    </aside>

  </div>

  <script>
  (function () {
    'use strict';

    /* ── toast ──────────────────────────────────────── */
    function showToast(msg) {
      const container = document.getElementById('toastContainer');
      const t = document.createElement('div');
      t.className = 'toast';
      t.textContent = msg;
      container.appendChild(t);
      requestAnimationFrame(() => { requestAnimationFrame(() => { t.classList.add('show'); }); });
      setTimeout(() => { t.classList.remove('show'); setTimeout(() => t.remove(), 250); }, 2600);
    }

    /* ── PHP vars ───────────────────────────────────── */
    let subtotal                = <?= json_encode((float)$subtotal) ?>;
    const currency              = <?= json_encode($currency) ?>;
    const USD_RATE_FROM_IDR     = <?= defined('USD_RATE_FROM_IDR') ? (float)constant('USD_RATE_FROM_IDR') : 16000 ?>;
    const domesticCouriers      = <?= json_encode($domesticCouriers) ?>;
    const internationalCouriers = <?= json_encode($internationalCouriers) ?>;

    /* ── DOM refs ───────────────────────────────────── */
    const shippingModeEl       = document.getElementById('shipping_mode');
    const destSearch           = document.getElementById('destination_search');
    const destSelect           = document.getElementById('destination_select');
    const courierPicker        = document.getElementById('courier_picker');
    const shippingOptionsEl    = document.getElementById('shippingOptions');
    const countryCodeInput     = document.getElementById('country_code');
    const countryNameInput     = document.getElementById('country_name');
    const courierCodeInput     = document.getElementById('courier_code');
    const courierServiceInput  = document.getElementById('courier_service');
    const shippingCostInput    = document.getElementById('shipping_cost');
    const shippingCostIdrInput = document.getElementById('shipping_cost_idr');
    const shippingDisplay      = document.getElementById('shippingDisplay');
    const grandTotalDisplay    = document.getElementById('grandTotalDisplay');
    const heroGrandTotal       = document.getElementById('heroGrandTotal');
    const heroGrandTotalTop    = document.getElementById('heroGrandTotalTop');
    const submitBtn            = document.getElementById('submitBtn');

    function formatCurrencyJS(amount) {
      if (currency === 'USD') {
        return '$' + Number(amount).toFixed(2);
      }
      return 'RP ' + Math.round(amount).toLocaleString('id-ID');
    }

    function convertFromIdr(amountIdr) {
      if (currency === 'USD') return Number(amountIdr) / USD_RATE_FROM_IDR;
      return Number(amountIdr);
    }

    function updateGrandTotal(shippingCostIdr) {
      const shippingConverted = convertFromIdr(shippingCostIdr);
      const subtotalConverted = subtotal; 
      const total = subtotalConverted + shippingConverted;
      shippingDisplay.textContent   = formatCurrencyJS(shippingConverted);
      grandTotalDisplay.textContent = formatCurrencyJS(total);
      heroGrandTotal.textContent    = formatCurrencyJS(total);
      if (heroGrandTotalTop) heroGrandTotalTop.textContent = formatCurrencyJS(total);
    }

    /* ── AJAX QTY UPDATE LOGIC ────────────────────────── */
    window.updateCheckoutQty = async function(psid, change, maxStock) {
        const displayEl = document.getElementById('qty_display_' + psid);
        if (!displayEl) return;
        
        const currentQty = parseInt(displayEl.textContent, 10) || 1;
        const newQty = currentQty + change;

        if (newQty < 1) return;
        if (newQty > maxStock) {
            showToast('Sisa stok hanya ' + maxStock);
            return;
        }

        const btnCtrl = displayEl.parentElement;
        const btns = btnCtrl.querySelectorAll('button');
        btns.forEach(b => b.disabled = true);
        displayEl.style.opacity = '0.5';

        const fd = new FormData();
        fd.append('ajax_update_qty', '1');
        fd.append('product_size_id', psid);
        fd.append('qty', newQty);
        fd.append('checkout_mode', document.querySelector('input[name="checkout_mode"]').value);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.success) {
                displayEl.textContent = data.new_qty;
                const badge = document.getElementById('qty_badge_' + psid);
                if (badge) badge.textContent = data.new_qty;
                document.getElementById('line_total_display_' + psid).textContent = formatCurrencyJS(data.new_line_total);

                subtotal = parseFloat(data.new_subtotal);
                document.getElementById('subtotalDisplay').textContent = formatCurrencyJS(subtotal);
                
                updateGrandTotal(Number(shippingCostIdrInput.value || 0));

                if (document.querySelector('input[name="checkout_mode"]').value !== 'buy_now') {
                    let lsCart = JSON.parse(localStorage.getItem('ppf_cart') || '[]');
                    let updated = false;
                    for (let i = 0; i < lsCart.length; i++) {
                        if (parseInt(lsCart[i].size_id) === parseInt(psid)) {
                            lsCart[i].qty = newQty;
                            updated = true;
                            break;
                        }
                    }
                    if (updated) {
                        localStorage.setItem('ppf_cart', JSON.stringify(lsCart));
                        const totalCount = lsCart.reduce((sum, item) => sum + item.qty, 0);
                        const hdrEl = document.getElementById('cartCountHeader');
                        if(hdrEl) hdrEl.textContent = totalCount;
                    }
                }
            } else {
                showToast(data.message || 'Update failed');
            }
        } catch (err) {
            showToast('Error connecting to server');
        } finally {
            btns.forEach(b => b.disabled = false);
            displayEl.style.opacity = '1';
        }
    };
    /* ─────────────────────────────────────────────────── */

    /* ── AJAX REMOVE ITEM LOGIC ───────────────────────── */
    window.removeCheckoutItem = async function(psid) {
        if (!confirm('Apakah Anda yakin ingin menghapus produk ini dari keranjang?')) return;
        
        const fd = new FormData();
        fd.append('ajax_remove_item', '1');
        fd.append('product_size_id', psid);
        fd.append('checkout_mode', document.querySelector('input[name="checkout_mode"]').value);

        try {
            const res = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            
            if (data.success) {
                if (document.querySelector('input[name="checkout_mode"]').value !== 'buy_now') {
                    let lsCart = JSON.parse(localStorage.getItem('ppf_cart') || '[]');
                    lsCart = lsCart.filter(item => parseInt(item.size_id) !== parseInt(psid));
                    localStorage.setItem('ppf_cart', JSON.stringify(lsCart));
                }
                
                window.location.reload();
            } else {
                showToast('Gagal menghapus produk.');
            }
        } catch (err) {
            showToast('Terjadi kesalahan server saat menghapus.');
        }
    };
    /* ─────────────────────────────────────────────────── */

    function renderCourierOptions() {
      const map = shippingModeEl.value === 'domestic' ? domesticCouriers : internationalCouriers;
      courierPicker.innerHTML = '<option value="">Select courier</option>';
      Object.entries(map).forEach(([code, label]) => {
        const o = document.createElement('option');
        o.value = code; o.textContent = label;
        courierPicker.appendChild(o);
      });
    }

    function resetShipping() {
      shippingOptionsEl.textContent = 'Select destination and courier to see shipping options.';
      courierCodeInput.value = ''; courierServiceInput.value = '';
      shippingCostInput.value = '0'; shippingCostIdrInput.value = '0';
      updateGrandTotal(0);
    }

    function updateModeUI() {
      const isDom = shippingModeEl.value === 'domestic';
      document.getElementById('destinationSearchLabel').textContent =
        (isDom ? 'City / District' : 'Country') + ' *';
      if (isDom) { countryCodeInput.value = 'ID'; countryNameInput.value = 'Indonesia'; }
      else if (countryNameInput.value === 'Indonesia') { countryCodeInput.value = ''; countryNameInput.value = ''; }
      destSearch.value = '';
      destSelect.innerHTML = '<option value="">Choose destination</option>';
      renderCourierOptions();
      resetShipping();
    }

    async function loadDestinations() {
      if (destSearch.value.trim().length < 2) {
        destSelect.innerHTML = '<option value="">Type at least 2 characters</option>';
        return;
      }
      const endpoint = shippingModeEl.value === 'domestic'
        ? 'config/get-domestic-destinations.php'
        : 'config/get-international-destinations.php';
      try {
        const res    = await fetch(endpoint + '?search=' + encodeURIComponent(destSearch.value.trim()) + '&limit=10');
        const result = await res.json();
        if (!result.success) { destSelect.innerHTML = '<option value="">Error loading destination</option>'; return; }
        const rows = Array.isArray(result.raw?.data) ? result.raw.data : [];
        destSelect.innerHTML = '<option value="">Choose destination</option>';
        if (!rows.length) { destSelect.innerHTML = '<option value="">No result found</option>'; return; }
        rows.forEach(item => {
          const o = document.createElement('option');
          if (shippingModeEl.value === 'domestic') {
            const id    = item.id || item.subdistrict_id || item.destination_id || item.destination || '';
            const label = item.label || [item.subdistrict_name, item.district_name, item.city_name, item.province_name].filter(Boolean).join(', ') || 'Unknown';
            o.value = id; o.textContent = label; o.dataset.name = label;
          } else {
            o.value = item.country_id || '';
            o.textContent = item.country_name || 'Unknown';
            o.dataset.name = item.country_name || '';
          }
          destSelect.appendChild(o);
        });
      } catch {
        destSelect.innerHTML = '<option value="">Error loading destination</option>';
      }
    }

    destSelect.addEventListener('change', function () {
      const sel = this.options[this.selectedIndex];
      if (shippingModeEl.value === 'domestic') {
        countryCodeInput.value = 'ID'; countryNameInput.value = 'Indonesia';
      } else {
        countryCodeInput.value = sel.value || '';
        countryNameInput.value = sel.dataset.name || '';
      }
      calculateShipping();
    });

    courierPicker.addEventListener('change', calculateShipping);

    async function calculateShipping() {
      const destination = destSelect.value;
      const courier     = courierPicker.value;

      courierCodeInput.value = ''; courierServiceInput.value = '';
      shippingCostInput.value = '0'; shippingCostIdrInput.value = '0';
      updateGrandTotal(0);

      if (!destination || !courier) {
        shippingOptionsEl.textContent = 'Select destination and courier to see shipping options.';
        return;
      }

      shippingOptionsEl.innerHTML = '<span style="color:var(--faint);font-size:9px;text-transform:none;letter-spacing:.04em">Loading shipping options...</span>';

      const endpoint = shippingModeEl.value === 'domestic'
        ? 'config/calculate-domestic-shipping.php'
        : 'config/calculate-shipping.php';

      const fd = new FormData();
      fd.append('destination', destination);
      fd.append('courier', courier);
      fd.append('weight', '1000');
      fd.append('price', 'lowest');

      try {
        const res     = await fetch(endpoint, { method: 'POST', body: fd });
        const rawText = await res.text();
        let result;
        try { result = JSON.parse(rawText); }
        catch { throw new Error('Server error — check shipping endpoint for PHP errors.'); }
        if (!result.success) throw new Error(result.message || 'Failed to calculate shipping.');

        const rows = Array.isArray(result.services) ? result.services : [];
        if (!rows.length) { shippingOptionsEl.textContent = 'No shipping services found.'; return; }

        shippingOptionsEl.innerHTML = '';
        rows.forEach((row, idx) => {
          const costIdr  = Number(row.cost || 0);
          const costShown = convertFromIdr(costIdr);
          const service  = row.service || 'Service';
          const etdText  = row.etd ? `<div class="shipping-service-etd">ETD: ${row.etd}</div>` : '';
          const wrapper  = document.createElement('label');
          wrapper.className = 'shipping-option';
          wrapper.innerHTML = `
            <div class="shipping-option-left">
              <input type="radio" class="shipping-radio" name="shipping_service_option" value="${idx}">
              <div>
                <div class="shipping-service-name">${service}</div>
                <div class="shipping-service-price">${formatCurrencyJS(costShown)}</div>
                ${etdText}
              </div>
            </div>
            <div class="shipping-courier-badge">${courier.toUpperCase()}</div>`;
          wrapper.querySelector('input').addEventListener('change', () => {
            courierCodeInput.value     = courier;
            courierServiceInput.value  = service;
            shippingCostIdrInput.value = costIdr;
            shippingCostInput.value    = currency === 'USD' ? costShown.toFixed(2) : costIdr;
            updateGrandTotal(costIdr);
            showToast(service + ' selected');
          });
          shippingOptionsEl.appendChild(wrapper);
        });
      } catch (err) {
        shippingOptionsEl.innerHTML = `<span style="color:#c0392b;font-size:10px;text-transform:none;letter-spacing:.04em">${err.message}</span>`;
        showToast(err.message);
      }
    }

    document.getElementById('checkoutForm').addEventListener('submit', function () {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Processing...';
    });

    /* ── mobile order-summary toggle ────────────────── */
    const sumToggle = document.getElementById('sumToggle');
    const sumBody   = document.getElementById('sumBody');
    if (sumToggle && sumBody) {
      sumToggle.addEventListener('click', () => {
        const open = sumBody.classList.toggle('is-open');
        sumToggle.setAttribute('aria-expanded', open);
        const lbl = sumToggle.querySelector('.lbl');
        if (lbl) lbl.textContent = open ? 'Hide order summary' : 'Show order summary';
      });
    }

    /* ── init ───────────────────────────────────────── */
    shippingModeEl.addEventListener('change', updateModeUI);
    destSearch.addEventListener('input', loadDestinations);
    updateModeUI();
    updateGrandTotal(Number(shippingCostInput.value || 0));

  })();
  </script>
</body>
</html>
