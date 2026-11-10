<?php
session_start();
require_once __DIR__ . '/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed.'
    ]);
    exit;
}

/* ── identitas: user login ATAU guest (NEW) ──────────────
   Guest tidak punya user_id, jadi kita pakai token unik yang
   disimpan di session supaya cart-nya konsisten antar request. */
$isLoggedIn = isset($_SESSION['user']['id']);
$userId = $isLoggedIn ? (int) $_SESSION['user']['id'] : null;

$guestToken = null;
if (!$isLoggedIn) {
    if (empty($_SESSION['guest_token'])) {
        $_SESSION['guest_token'] = bin2hex(random_bytes(16));
    }
    $guestToken = $_SESSION['guest_token'];
}

$productId = (int) ($_POST['product_id'] ?? 0);
$productSizeId = (int) ($_POST['product_size_id'] ?? 0);
$quantity = (int) ($_POST['quantity'] ?? 1);
$currency = 'IDR';

if ($productId <= 0 || $quantity <= 0) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid cart request.'
    ]);
    exit;
}

try {
    // FIX: p.category dihapus — kolom itu tidak ada di tabel products.
    // Kategori didapat dari LEFT JOIN ke categories (c.slug), fallback "fashion".
    $productStmt = $pdo->prepare(
        'SELECT
            p.id,
            p.name,
            p.price_idr,
            p.is_active,
            COALESCE(c.slug, "fashion") AS category_slug
         FROM products p
         LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id = :id
         LIMIT 1'
    );
    $productStmt->execute(['id' => $productId]);
    $product = $productStmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || (int) $product['is_active'] !== 1) {
        throw new RuntimeException('Product is not available.');
    }

    $categorySlug = strtolower(trim((string) ($product['category_slug'] ?? 'fashion')));

    if ($productSizeId <= 0 && $categorySlug !== 'fashion') {
        $autoSizeStmt = $pdo->prepare(
            'SELECT ps.id
             FROM product_sizes ps
             INNER JOIN sizes s ON s.id = ps.size_id
             WHERE ps.product_id = :product_id
               AND ps.is_active = 1
               AND ps.stock > 0
               AND LOWER(TRIM(s.name)) IN ("one size", "onesize", "default")
             ORDER BY ps.id ASC
             LIMIT 1'
        );
        $autoSizeStmt->execute(['product_id' => $productId]);
        $productSizeId = (int) $autoSizeStmt->fetchColumn();
    }

    if ($productSizeId <= 0) {
        throw new RuntimeException($categorySlug === 'fashion' ? 'Please select a size.' : 'Product stock is not configured yet.');
    }

    $sizeStmt = $pdo->prepare(
        'SELECT
            ps.id,
            ps.product_id,
            ps.stock,
            ps.is_active,
            s.name AS size_name
         FROM product_sizes ps
         INNER JOIN sizes s ON s.id = ps.size_id
         WHERE ps.id = :id
           AND ps.product_id = :product_id
         LIMIT 1'
    );
    $sizeStmt->execute([
        'id' => $productSizeId,
        'product_id' => $productId,
    ]);
    $productSize = $sizeStmt->fetch(PDO::FETCH_ASSOC);

    if (!$productSize || (int) $productSize['is_active'] !== 1) {
        throw new RuntimeException($categorySlug === 'fashion' ? 'Selected size is not available.' : 'Product is not available.');
    }

    if ((int) $productSize['stock'] < $quantity) {
        throw new RuntimeException('Not enough stock available.');
    }

    $price = (float) $product['price_idr'];

    $pdo->beginTransaction();

    /* ── cari / buat cart aktif (NEW: berdasarkan user_id atau guest_token) ── */
    if ($isLoggedIn) {
        $cartStmt = $pdo->prepare(
            'SELECT id FROM carts WHERE user_id = :user_id AND status = :status LIMIT 1'
        );
        $cartStmt->execute(['user_id' => $userId, 'status' => 'active']);
    } else {
        $cartStmt = $pdo->prepare(
            'SELECT id FROM carts WHERE guest_token = :guest_token AND status = :status LIMIT 1'
        );
        $cartStmt->execute(['guest_token' => $guestToken, 'status' => 'active']);
    }
    $cart = $cartStmt->fetch(PDO::FETCH_ASSOC);

    if ($cart) {
        $cartId = (int) $cart['id'];
    } else {
        if ($isLoggedIn) {
            $insertCart = $pdo->prepare(
                'INSERT INTO carts (user_id, status) VALUES (:user_id, :status)'
            );
            $insertCart->execute(['user_id' => $userId, 'status' => 'active']);
        } else {
            $insertCart = $pdo->prepare(
                'INSERT INTO carts (guest_token, status) VALUES (:guest_token, :status)'
            );
            $insertCart->execute(['guest_token' => $guestToken, 'status' => 'active']);
        }
        $cartId = (int) $pdo->lastInsertId();
    }

    $itemStmt = $pdo->prepare(
        'SELECT id, quantity
         FROM cart_items
         WHERE cart_id = :cart_id
           AND product_size_id = :product_size_id
           AND currency = :currency
         LIMIT 1'
    );
    $itemStmt->execute([
        'cart_id' => $cartId,
        'product_size_id' => $productSizeId,
        'currency' => $currency,
    ]);
    $existingItem = $itemStmt->fetch(PDO::FETCH_ASSOC);

    if ($existingItem) {
        $newQuantity = (int) $existingItem['quantity'] + $quantity;

        if ($newQuantity > (int) $productSize['stock']) {
            throw new RuntimeException('Cart quantity exceeds available stock.');
        }

        $updateItem = $pdo->prepare(
            'UPDATE cart_items
             SET quantity = :quantity,
                 price = :price,
                 currency = :currency
             WHERE id = :id'
        );
        $updateItem->execute([
            'quantity' => $newQuantity,
            'price' => $price,
            'currency' => $currency,
            'id' => $existingItem['id'],
        ]);
    } else {
        $insertItem = $pdo->prepare(
            'INSERT INTO cart_items (
                cart_id,
                product_id,
                product_size_id,
                quantity,
                price,
                currency
             ) VALUES (
                :cart_id,
                :product_id,
                :product_size_id,
                :quantity,
                :price,
                :currency
             )'
        );
        $insertItem->execute([
            'cart_id' => $cartId,
            'product_id' => $productId,
            'product_size_id' => $productSizeId,
            'quantity' => $quantity,
            'price' => $price,
            'currency' => $currency,
        ]);
    }

    $countStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(quantity), 0)
         FROM cart_items
         WHERE cart_id = :cart_id'
    );
    $countStmt->execute(['cart_id' => $cartId]);
    $cartCount = (int) $countStmt->fetchColumn();

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Product added to cart.',
        'cart_count' => $cartCount
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
