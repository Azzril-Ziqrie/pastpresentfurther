<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/bootstrap-paypal.php';

use PaypalServerSdkLib\Models\AmountWithBreakdown;
use PaypalServerSdkLib\Models\CheckoutPaymentIntent;
use PaypalServerSdkLib\Models\OrderApplicationContext;
use PaypalServerSdkLib\Models\OrderRequest;
use PaypalServerSdkLib\Models\PurchaseUnitRequest;

try {
    $orderNumber = trim((string)($_GET['order'] ?? $_POST['order'] ?? ''));
    if ($orderNumber === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Order number is required.']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_number = :order_number LIMIT 1");
    $stmt->execute(['order_number' => $orderNumber]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Order not found.']);
        exit;
    }

    $grandTotal = (float)($order['grand_total'] ?? 0);
    if ($grandTotal <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Invalid grand total.']);
        exit;
    }

    $currencyCode = 'USD';
    $formattedTotal = number_format($grandTotal, 2, '.', '');

    $amount = new AmountWithBreakdown($currencyCode, $formattedTotal);

    $purchaseUnit = new PurchaseUnitRequest($amount);
    $purchaseUnit->setReferenceId((string)$order['order_number']);
    $purchaseUnit->setDescription('Order #' . (string)$order['order_number']);

    $applicationContext = new OrderApplicationContext();
    $applicationContext->setBrandName('PASTPRESENTFURTHER');
    $applicationContext->setUserAction('PAY_NOW');

    $orderRequest = new OrderRequest(
        CheckoutPaymentIntent::CAPTURE,
        [$purchaseUnit]
    );
    $orderRequest->setApplicationContext($applicationContext);

    $ordersController = $paypalClient->getOrdersController();
    $apiResponse = $ordersController->createOrder([
        'body' => $orderRequest
    ]);

    $paypalOrder = $apiResponse->getResult();

    echo json_encode([
        'id' => $paypalOrder->getId(),
        'orderID' => $paypalOrder->getId(),
        'status' => $paypalOrder->getStatus()
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to create PayPal order.',
        'message' => $e->getMessage()
    ]);
}
