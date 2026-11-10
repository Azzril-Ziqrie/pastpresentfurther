<?php
session_start();
require_once __DIR__ . '/config/database.php';

function buyNowJsonResponse(bool $success, string $message, array $extra = [], int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message,
    ], $extra));
    exit;
}

function buyNowRedirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$isAjax = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    if ($isAjax) {
        buyNowJsonResponse(false, 'Invalid request method.', [], 405);
    }
    buyNowRedirect('index.php');
}

$productId = (int) ($_POST['product_id'] ?? 0);
$productSizeId = (int) ($_POST['product_size_id'] ?? 0);
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));

if ($productId <= 0) {
    if ($isAjax) {
        buyNowJsonResponse(false, 'Invalid product.', [], 422);
    }
    buyNowRedirect('index.php');
}

$productStmt = $pdo->prepare(
    'SELECT
        p.id,
        p.name,
        p.slug,
        p.is_active,
        COALESCE(c.slug, \'fashion\') AS category_slug
     FROM products p
     LEFT JOIN categories c ON c.id = p.category_id
     WHERE p.id = :product_id
     LIMIT 1'
);
$productStmt->execute(['product_id' => $productId]);
$product = $productStmt->fetch(PDO::FETCH_ASSOC);

if (!$product || (int) $product['is_active'] !== 1) {
    if ($isAjax) {
        buyNowJsonResponse(false, 'Product is not available.', [], 404);
    }
    buyNowRedirect('index.php');
}

$categorySlug = strtolower((string) ($product['category_slug'] ?? 'fashion'));
$isFashion = $categorySlug === 'fashion';

if ($productSizeId <= 0) {
    if ($isFashion) {
        if ($isAjax) {
            buyNowJsonResponse(false, 'Please select a size first.', [], 422);
        }
        buyNowRedirect('product.php?slug=' . urlencode($product['slug']));
    }

    $defaultSizeStmt = $pdo->prepare(
        'SELECT ps.id
         FROM product_sizes ps
         INNER JOIN sizes s ON s.id = ps.size_id
         WHERE ps.product_id = :product_id
           AND ps.is_active = 1
           AND ps.stock > 0
         ORDER BY
           CASE
             WHEN LOWER(s.name) IN (\'one size\', \'onesize\', \'default\') THEN 0
             ELSE 1
           END,
           s.sort_order ASC,
           s.name ASC,
           ps.id ASC
         LIMIT 1'
    );
    $defaultSizeStmt->execute(['product_id' => $productId]);
    $productSizeId = (int) $defaultSizeStmt->fetchColumn();
}

if ($productSizeId <= 0) {
    if ($isAjax) {
        buyNowJsonResponse(false, 'This product has no available stock.', [], 422);
    }
    buyNowRedirect('product.php?slug=' . urlencode($product['slug']));
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
     WHERE ps.id = :product_size_id
       AND ps.product_id = :product_id
     LIMIT 1'
);
$sizeStmt->execute([
    'product_size_id' => $productSizeId,
    'product_id' => $productId,
]);
$size = $sizeStmt->fetch(PDO::FETCH_ASSOC);

if (!$size || (int) $size['is_active'] !== 1) {
    if ($isAjax) {
        buyNowJsonResponse(false, 'Selected option is not available.', [], 422);
    }
    buyNowRedirect('product.php?slug=' . urlencode($product['slug']));
}

$stock = (int) $size['stock'];
if ($stock <= 0) {
    if ($isAjax) {
        buyNowJsonResponse(false, 'This product is sold out.', [], 422);
    }
    buyNowRedirect('product.php?slug=' . urlencode($product['slug']));
}

if ($quantity > $stock) {
    $quantity = $stock;
}

$_SESSION['buy_now'] = [
    'product_id' => $productId,
    'product_size_id' => $productSizeId,
    'quantity' => $quantity,
    'currency' => 'IDR',
    'created_at' => time(),
];

$redirect = 'checkout.php?mode=buy_now';

if ($isAjax) {
    buyNowJsonResponse(true, 'Checkout is ready.', [
        'redirect' => $redirect,
        'product_size_id' => $productSizeId,
        'quantity' => $quantity,
    ]);
}

buyNowRedirect($redirect);
