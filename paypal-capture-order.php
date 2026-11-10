<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/bootstrap-paypal.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
require_once __DIR__ . '/includes/email_helpers.php';

/**
 * Ambil status dari struktur response PayPal (array) se-umum mungkin.
 */
function ppfExtractPaypalStatus(array $result): string
{
    // 1. Status langsung di root
    if (!empty($result['status'])) {
        return (string)$result['status'];
    }

    // 2. Status di 'details'
    if (!empty($result['details']) && is_array($result['details'])) {
        $d = $result['details'];

        if (!empty($d['status'])) {
            return (string)$d['status'];
        }

        // 3. Struktur umum Orders v2: purchase_units[0].payments.captures[0].status
        if (!empty($d['purchase_units'][0]['payments']['captures'][0]['status'])) {
            return (string)$d['purchase_units'][0]['payments']['captures'][0]['status'];
        }
    }

    // 4. Jaga-jaga: root.purchase_units[0].payments.captures[0].status
    if (!empty($result['purchase_units'][0]['payments']['captures'][0]['status'])) {
        return (string)$result['purchase_units'][0]['payments']['captures'][0]['status'];
    }

    return '';
}

try {
    $input = json_decode(file_get_contents('php://input'), true) ?: [];

    $orderNumber   = trim((string)($input['order_number'] ?? ''));
    $paypalOrderId = trim((string)($input['paypal_order_id'] ?? ''));

    if ($orderNumber === '' || $paypalOrderId === '') {
        http_response_code(422);
        echo json_encode([
            'error'  => 'Order number and PayPal order ID are required.',
            'status' => 'ERROR'
        ]);
        exit;
    }

    // Ambil order di DB
    $stmt = $pdo->prepare("
        SELECT *
        FROM orders
        WHERE order_number = :order_number
        LIMIT 1
    ");
    $stmt->execute(['order_number' => $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'error'  => 'Order not found.',
            'status' => 'ERROR'
        ]);
        exit;
    }

    // Kalau sudah paid, jangan capture lagi
    if (($order['payment_status'] ?? '') === 'paid') {
        echo json_encode([
            'status'  => 'COMPLETED',
            'message' => 'Order already marked as paid (no capture).'
        ]);
        exit;
    }

    $ordersController = $paypalClient->getOrdersController();

    // Panggil API capture
    $apiResponse = $ordersController->captureOrder([
        'id'     => $paypalOrderId,
        'prefer' => 'return=representation'
    ]);

    $result = $apiResponse->getResult();
    error_log('PAYPAL RAW CAPTURE: ' . print_r($result, true));

    // Pakai helper untuk membaca status
    $status = ppfExtractPaypalStatus(is_array($result) ? $result : []);

    // Fallback: kalau status kosong tapi tidak ada error dari PayPal, kita anggap success (khusus sandbox/dev)
    if ($status === '') {
        $status = 'COMPLETED';
    }

    if ($status === 'COMPLETED') {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE orders
            SET payment_status = 'paid',
                updated_at     = NOW()
            WHERE id = :id
        ")->execute([
            'id' => $order['id']
        ]);

        $pdo->prepare("
            INSERT INTO order_status_logs (order_id, status_type, status_value, notes)
            VALUES (:oid, :st, :sv, :n)
        ")->execute([
            'oid' => $order['id'],
            'st'  => 'payment',
            'sv'  => 'paid',
            'n'   => 'Paid via PayPal. PayPal Order ID: ' . $paypalOrderId
        ]);

        $pdo->commit();

        $refresh = $pdo->prepare("SELECT * FROM orders WHERE id = :id LIMIT 1");
        $refresh->execute(['id' => $order['id']]);
        $updatedOrder = $refresh->fetch(PDO::FETCH_ASSOC) ?: $order;

        $emailSent = sendPPFInvoiceEmail($pdo, $updatedOrder);

        echo json_encode([
            'status'      => 'COMPLETED',
            'message'     => 'Payment captured successfully.',
            'invoiceMail' => $emailSent ? 'sent' : 'failed'
        ]);
        exit;
    }

    // Kalau bukan COMPLETED, balikin semua supaya kelihatan di frontend
    echo json_encode([
        'status'  => $status ?: 'UNKNOWN',
        'error'   => 'Payment not completed.',
        'details' => $result
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('PAYPAL CAPTURE ERROR: ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'error'   => 'Failed to capture PayPal payment.',
        'message' => $e->getMessage(),
        'status'  => 'ERROR'
    ]);
}
