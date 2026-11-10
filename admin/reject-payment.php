<?php
require_once __DIR__ . '/../includes/admin-auth.php'; // admin password gate
require_once __DIR__ . '/../config/database.php';

$adminId = (int) ($_SESSION['admin']['id'] ?? 0);
if ($adminId <= 0) {
    $adminId = 1;
}

$orderId = (int) ($_POST['order_id'] ?? 0);
$reason = trim((string) ($_POST['reason'] ?? ''));

if ($orderId <= 0) {
    die('Invalid order id.');
}

try {
    $stmt = $pdo->prepare("
        UPDATE orders
        SET payment_status = 'failed',
            payment_reject_reason = :payment_reject_reason,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([
        'payment_reject_reason' => $reason !== '' ? $reason : 'Payment proof rejected by admin.',
        'id' => $orderId,
    ]);

    $log = $pdo->prepare("
        INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
        VALUES (:order_id, :status_type, :status_value, :notes)
    ");
    $log->execute([
        'order_id' => $orderId,
        'status_type' => 'payment',
        'status_value' => 'failed',
        'notes' => $reason !== '' ? $reason : 'Payment proof rejected by admin.',
    ]);

    header('Location: order-detail.php?id=' . $orderId . '&success=rejected');
    exit;
} catch (Throwable $e) {
    die('Failed to reject payment: ' . $e->getMessage());
}
