<?php
session_start();
require_once __DIR__ . '/config/database.php';

if (!isset($_SESSION['user']['id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user']['id'];
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'remove_item') {
        $cartItemId = (int) ($_POST['cart_item_id'] ?? 0);

        if ($cartItemId > 0) {
            try {
                $deleteStmt = $pdo->prepare(
                    'DELETE ci
                     FROM cart_items ci
                     INNER JOIN carts c ON c.id = ci.cart_id
                     WHERE ci.id = :cart_item_id
                       AND c.user_id = :user_id
                       AND c.status = :status'
                );
                $deleteStmt->execute([
                    'cart_item_id' => $cartItemId,
                    'user_id' => $userId,
                    'status' => 'active',
                ]);

                if ($deleteStmt->rowCount() > 0) {
                    $message = 'Item removed from cart.';
                } else {
                    $error = 'Cart item was not found.';
                }
            } catch (Throwable $e) {
                $error = 'Failed to remove item: ' . $e->getMessage();
            }
        } else {
            $error = 'Invalid cart item.';
        }
    }
}

$cartStmt = $pdo->prepare(
    'SELECT id
     FROM carts
     WHERE user_id = :user_id AND status = :status
     LIMIT 1'
);
$cartStmt->execute([
    'user_id' => $userId,
    'status' => 'active',
]);
$cart = $cartStmt->fetch();

$cartItems = [];
$cartTotal = 0;
$cartCount = 0;
$cartCurrency = 'IDR';

if ($cart) {
    $itemsStmt = $pdo->prepare(
        'SELECT
            ci.id,
            ci.quantity,
            ci.price,
            ci.currency,
            p.name,
            p.slug,
            p.thumbnail,
            COALESCE(c.slug, p.category, \'fashion\') AS category_slug,
            COALESCE(c.name, p.category, \'Fashion\') AS category_name,
            s.name AS size_name
         FROM cart_items ci
         INNER JOIN products p ON p.id = ci.product_id
         INNER JOIN product_sizes ps ON ps.id = ci.product_size_id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE ci.cart_id = :cart_id
         ORDER BY ci.id DESC'
    );
    $itemsStmt->execute(['cart_id' => $cart['id']]);
    $cartItems = $itemsStmt->fetchAll();

    foreach ($cartItems as $item) {
        $cartCount += (int) $item['quantity'];
        $cartTotal += ((float) $item['price'] * (int) $item['quantity']);
        $cartCurrency = 'IDR';
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Cart - Albrycia</title>
  <style>
    * { box-sizing: border-box; }
    html, body { margin: 0; padding: 0; }

    body {
      font-family: Arial, Helvetica, sans-serif;
      background: #efefef;
      color: #111;
    }

    a { color: inherit; text-decoration: none; }
    button { font: inherit; }

    .topbar {
      height: 74px;
      display: grid;
      grid-template-columns: 1fr auto 1fr;
      align-items: center;
      padding: 0 22px;
      background: #efefef;
      border-bottom: 1px solid #bdbdbd;
    }

    .topbar__left,
    .topbar__right {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .topbar__right {
      justify-content: flex-end;
    }

    .nav-link {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .6px;
    }

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

    .wrap {
      max-width: 1180px;
      margin: 0 auto;
      border-left: 1px solid #bdbdbd;
      border-right: 1px solid #bdbdbd;
      min-height: calc(100vh - 74px);
      background: #efefef;
    }

    .head {
      padding: 18px 20px;
      border-bottom: 1px solid #bdbdbd;
      text-transform: uppercase;
      letter-spacing: .7px;
      font-size: 13px;
    }

    .body {
      padding: 20px;
    }

    .notice {
      margin-bottom: 18px;
      padding: 14px 16px;
      border: 1px solid #bdbdbd;
      background: #f8f8f8;
      font-size: 13px;
    }

    .notice.success {
      border-color: #111;
    }

    .empty {
      padding: 40px 0;
      font-size: 14px;
      color: #555;
    }

    .cart-item {
      display: grid;
      grid-template-columns: 110px 1fr auto;
      gap: 18px;
      padding: 18px 0;
      border-bottom: 1px solid #d7d7d7;
      align-items: center;
    }

    .cart-thumb {
      width: 110px;
      height: 110px;
      object-fit: cover;
      border: 1px solid #cfcfcf;
      background: #fff;
    }

    .cart-name {
      margin: 0 0 6px;
      font-size: 15px;
      text-transform: uppercase;
      letter-spacing: .4px;
    }

    .cart-meta {
      margin: 0;
      font-size: 13px;
      color: #555;
      line-height: 1.7;
    }

    .cart-side {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 12px;
    }

    .item-total {
      font-size: 13px;
      color: #333;
      text-align: right;
    }

    .remove-form {
      margin: 0;
    }

    .remove-btn {
      min-height: 40px;
      padding: 0 14px;
      border: 1px solid #bdbdbd;
      background: transparent;
      color: #111;
      text-transform: uppercase;
      letter-spacing: .5px;
      cursor: pointer;
      font-size: 12px;
    }

    .remove-btn:hover {
      border-color: #111;
    }

    .summary {
      margin-top: 22px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      padding-top: 18px;
      border-top: 1px solid #bdbdbd;
    }

    .total {
      font-size: 18px;
      text-transform: uppercase;
    }

    .btn {
      min-height: 48px;
      padding: 0 18px;
      border: 1px solid #111;
      background: #111;
      color: #fff;
      text-transform: uppercase;
      letter-spacing: .6px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    @media (max-width: 700px) {
      .topbar {
        padding: 0 16px;
      }

      .cart-item {
        grid-template-columns: 1fr;
      }

      .cart-thumb {
        width: 100%;
        height: 260px;
      }

      .cart-side {
        align-items: flex-start;
      }

      .item-total {
        text-align: left;
      }

      .summary {
        align-items: flex-start;
      }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="topbar__left">
      <a href="index.php" class="nav-link">Storefront</a>
    </div>

    <a href="index.php" class="logo" aria-label="Albrycia home">
      <img src="assets/images/albrycia-logo.png" alt="Albrycia logo">
    </a>

    <div class="topbar__right">
      <a href="logout.php" class="nav-link">Logout</a>
    </div>
  </header>

  <main class="wrap">
    <div class="head">Your Cart (<?= $cartCount ?>)</div>

    <div class="body">
      <?php if ($message !== ''): ?>
        <div class="notice success"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="notice"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <?php if (!$cartItems): ?>
        <div class="empty">Your cart is empty.</div>
      <?php else: ?>
        <?php foreach ($cartItems as $item): ?>
          <div class="cart-item">
            <img class="cart-thumb" src="<?= htmlspecialchars($item['thumbnail']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">

            <div>
              <h2 class="cart-name"><?= htmlspecialchars($item['name']) ?></h2>
              <?php if (strtolower((string) ($item['category_slug'] ?? 'fashion')) === 'fashion'): ?>
                <p class="cart-meta">Size: <?= htmlspecialchars($item['size_name']) ?></p>
              <?php endif; ?>
              <p class="cart-meta">Quantity: <?= (int) $item['quantity'] ?></p>
              <p class="cart-meta">IDR <?= number_format((float) $item['price'], 0, ',', '.') ?></p>
            </div>

            <div class="cart-side">
              <div class="item-total">
                IDR
                <?= number_format(((float) $item['price'] * (int) $item['quantity']), 0, ',', '.') ?>
              </div>

              <form method="POST" action="" class="remove-form" onsubmit="return confirm('Remove this item from cart?');">
                <input type="hidden" name="action" value="remove_item">
                <input type="hidden" name="cart_item_id" value="<?= (int) $item['id'] ?>">
                <button type="submit" class="remove-btn">Remove</button>
              </form>
            </div>
          </div>
        <?php endforeach; ?>

        <div class="summary">
          <div class="total">Total: IDR <?= number_format($cartTotal, 0, ',', '.') ?></div>
          <a href="checkout.php" class="btn">Continue to Checkout</a>
        </div>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
