<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$adminId = (int) ($_SESSION['admin']['id'] ?? 0);
if ($adminId <= 0) {
    $adminId = 1;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    die('Invalid order id.');
}

try {
    $pdo->beginTransaction();

    $orderStmt = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
    ");
    $orderStmt->execute(['id' => $orderId]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception('Order not found.');
    }

    $paymentStatus = strtolower(trim((string) ($order['payment_status'] ?? '')));
    if ($paymentStatus === '') {
        $paymentStatus = 'pending';
    }

    if ($paymentStatus === 'paid') {
        throw new Exception('Order already approved.');
    }

    if (!in_array($paymentStatus, ['pending', 'waiting_verification'], true)) {
        throw new Exception('Order cannot be approved from current status.');
    }

    $itemStmt = $pdo->prepare("
        SELECT *
        FROM order_items
        WHERE order_id = :order_id
        ORDER BY id ASC
    ");
    $itemStmt->execute(['order_id' => $order['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new Exception('Order items not found.');
    }

    $stockStmt = $pdo->prepare("
        UPDATE product_sizes
        SET stock = stock - :qty_update,
            updated_at = NOW()
        WHERE id = :product_size_id
          AND is_active = 1
          AND stock >= :qty_check
    ");

    foreach ($items as $item) {
        $stockStmt->execute([
            'qty_update' => (int) $item['quantity'],
            'qty_check' => (int) $item['quantity'],
            'product_size_id' => (int) $item['product_size_id'],
        ]);

        if ($stockStmt->rowCount() === 0) {
            throw new Exception(
                'Stock not enough for ' . $item['product_name'] .
                ' size ' . ($item['size_name'] ?? '-')
            );
        }
    }

    $updateOrder = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'paid',
            fulfillment_status = 'processing',
            payment_verified_at = NOW(),
            payment_verified_by = :payment_verified_by,
            updated_at = NOW()
        WHERE id = :id
    ");
    $updateOrder->execute([
        'payment_verified_by' => $adminId,
        'id' => $order['id'],
    ]);

    $insertLog = $pdo->prepare("
        INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
        VALUES (:order_id, :status_type, :status_value, :notes)
    ");
    $insertLog->execute([
        'order_id' => $order['id'],
        'status_type' => 'payment',
        'status_value' => 'paid',
        'notes' => 'Payment approved by admin. Stock deducted.',
    ]);

    $pdo->commit();

    header('Location: order-detail.php?id=' . $order['id'] . '&success=approved');
    exit;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die('Failed to approve payment: ' . $e->getMessage());
}
