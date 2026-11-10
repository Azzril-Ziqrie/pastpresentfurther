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
        "SELECT
            ci.id,
            ci.quantity,
            ci.price,
            ci.currency,
            p.name,
            p.slug,
            p.thumbnail,
            s.name AS size_name,
            COALESCE(cat.name, 'Fashion') AS category_name,
            COALESCE(cat.slug, 'fashion') AS category_slug
         FROM cart_items ci
         INNER JOIN products p ON p.id = ci.product_id
         INNER JOIN product_sizes ps ON ps.id = ci.product_size_id
         INNER JOIN sizes s ON s.id = ps.size_id
         LEFT JOIN categories cat ON cat.id = p.category_id
         WHERE ci.cart_id = :cart_id
         ORDER BY ci.id DESC"
    );
    $itemsStmt->execute(['cart_id' => $cart['id']]);
    $cartItems = $itemsStmt->fetchAll();

    foreach ($cartItems as $item) {
        $cartCount += (int) $item['quantity'];
        $cartTotal += ((float) $item['price'] * (int) $item['quantity']);
        $cartCurrency = $item['currency'] ?: 'IDR';
    }
}

function formatRupiah($amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
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
      min-height: 100vh;
      background: #ffffff;
      color: #000;
      font-family: Arial, Helvetica, sans-serif;
      font-size: 14px;
      -webkit-font-smoothing: antialiased;
      text-rendering: geometricPrecision;
    }

    a { color: inherit; }
    button { font: inherit; }

    .site-bar {
      height: 45px;
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 0 17px;
      background: #fff;
      border-bottom: 1px solid #e1e1e1;
      box-shadow: 0 1px 9px rgba(0,0,0,.10);
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .back-link {
      width: 18px;
      height: 18px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-size: 27px;
      line-height: 1;
      transform: translateY(-1px);
    }

    .bar-brand {
      text-decoration: none;
      font-size: 16px;
      letter-spacing: -.2px;
      font-weight: 400;
    }

    .cart-page {
      max-width: 980px;
      margin: 0 auto;
      padding: 29px 24px 64px;
    }

    .page-title {
      margin: 0 0 51px;
      text-align: center;
      font-size: 25px;
      line-height: 1;
      font-weight: 700;
      letter-spacing: -.4px;
    }

    .notice {
      max-width: 720px;
      margin: 0 auto 24px;
      padding: 12px 14px;
      border: 1px solid #dedede;
      background: #fafafa;
      font-size: 13px;
      line-height: 1.45;
    }

    .empty {
      max-width: 720px;
      margin: 0 auto;
      padding: 42px 0;
      text-align: center;
      color: #555;
    }

    .cart-list {
      max-width: 930px;
      margin: 0 auto;
    }

    .cart-row {
      display: grid;
      grid-template-columns: 120px minmax(240px, 1fr) minmax(250px, auto);
      align-items: end;
      column-gap: 8px;
      min-height: 186px;
      padding: 0 0 24px;
      margin-bottom: 20px;
    }

    .product-media {
      width: 120px;
      height: 180px;
      background: #f6f6f6;
      border-radius: 4px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .product-media img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      display: block;
    }

    .product-info {
      align-self: start;
      padding-top: 2px;
    }

    .product-name {
      margin: 0 0 9px;
      font-size: 16px;
      line-height: 1.22;
      font-weight: 400;
      text-transform: uppercase;
      letter-spacing: -.15px;
      max-width: 310px;
    }

    .product-price {
      margin: 0;
      font-size: 14px;
      line-height: 1.25;
      font-weight: 700;
    }

    .product-meta {
      margin-top: 9px;
      font-size: 12px;
      color: #777;
      letter-spacing: .2px;
      text-transform: uppercase;
    }

    .row-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 18px;
      padding-bottom: 3px;
      white-space: nowrap;
    }

    .action-link,
    .remove-button {
      border: 0;
      background: transparent;
      padding: 0;
      cursor: pointer;
      color: #000;
      font-size: 14px;
      line-height: 1;
      text-decoration: underline;
      text-underline-offset: 2px;
    }

    .remove-form { margin: 0; }

    .quantity-pill {
      height: 34px;
      min-width: 82px;
      padding: 0 12px;
      border: 1px solid #d9dde3;
      border-radius: 14px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 14px;
      color: #151515;
      line-height: 1;
    }

    .qty-sign {
      color: #777;
      font-size: 18px;
      line-height: 1;
      transform: translateY(-1px);
    }

    .checkout-footer {
      max-width: 930px;
      margin: 35px auto 0;
      border-top: 1px solid #efefef;
      padding-top: 22px;
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 24px;
    }

    .total-text {
      font-size: 15px;
      font-weight: 700;
    }

    .checkout-button {
      min-height: 44px;
      min-width: 190px;
      padding: 0 22px;
      border: 1px solid #000;
      background: #000;
      color: #fff;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .3px;
    }

    @media (max-width: 820px) {
      .cart-page { padding: 28px 18px 56px; }
      .page-title { margin-bottom: 36px; }
      .cart-row {
        grid-template-columns: 104px 1fr;
        row-gap: 18px;
        column-gap: 16px;
        align-items: start;
      }
      .product-media { width: 104px; height: 150px; }
      .row-actions {
        grid-column: 1 / -1;
        justify-content: flex-end;
        padding-bottom: 0;
      }
    }

    @media (max-width: 560px) {
      .site-bar { padding: 0 14px; }
      .bar-brand { font-size: 15px; }
      .cart-page { padding-left: 14px; padding-right: 14px; }
      .cart-row {
        grid-template-columns: 92px 1fr;
        min-height: auto;
      }
      .product-media { width: 92px; height: 132px; }
      .product-name { font-size: 14px; }
      .row-actions {
        justify-content: space-between;
        gap: 10px;
        white-space: normal;
      }
      .action-link,
      .remove-button { font-size: 13px; }
      .checkout-footer {
        align-items: stretch;
        flex-direction: column;
      }
      .checkout-button { width: 100%; }
    }
  </style>
</head>
<body>
  <header class="site-bar">
    <a href="javascript:history.back()" class="back-link" aria-label="Back">‹</a>
    <a href="index.php" class="bar-brand">Albrycia</a>
  </header>

  <main class="cart-page">
    <h1 class="page-title">Cart</h1>

    <?php if ($message !== ''): ?>
      <div class="notice"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
      <div class="notice"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!$cartItems): ?>
      <div class="empty">Your cart is empty.</div>
    <?php else: ?>
      <section class="cart-list" aria-label="Cart items">
        <?php foreach ($cartItems as $item): ?>
          <article class="cart-row">
            <a class="product-media" href="product.php?slug=<?= urlencode($item['slug']) ?>" aria-label="View <?= htmlspecialchars($item['name']) ?>">
              <img src="<?= htmlspecialchars($item['thumbnail']) ?>" alt="<?= htmlspecialchars($item['name']) ?>">
            </a>

            <div class="product-info">
              <h2 class="product-name"><?= htmlspecialchars($item['name']) ?></h2>
              <p class="product-price"><?= formatRupiah($item['price']) ?></p>
              <?php if (($item['category_slug'] ?? 'fashion') === 'fashion'): ?>
                <div class="product-meta">Size <?= htmlspecialchars($item['size_name']) ?></div>
              <?php endif; ?>
            </div>

            <div class="row-actions">
              <a href="#" class="action-link" onclick="return false;">Move to wishlist</a>
              <form method="POST" action="" class="remove-form" onsubmit="return confirm('Remove this item from cart?');">
                <input type="hidden" name="action" value="remove_item">
                <input type="hidden" name="cart_item_id" value="<?= (int) $item['id'] ?>">
                <button type="submit" class="remove-button">Remove</button>
              </form>
              <div class="quantity-pill" aria-label="Quantity <?= (int) $item['quantity'] ?>">
                <span class="qty-sign">−</span>
                <span><?= (int) $item['quantity'] ?></span>
                <span class="qty-sign">＋</span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </section>

      <div class="checkout-footer">
        <div class="total-text">Total <?= formatRupiah($cartTotal) ?></div>
        <a href="checkout.php" class="checkout-button">Continue to Checkout</a>
      </div>
    <?php endif; ?>
  </main>
</body>
</html>
