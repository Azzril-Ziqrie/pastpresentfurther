<?php

function xenditCreatePaymentSession(array $order, array $customer): array
{
    if (!isset($order['order_number'], $order['grand_total'], $order['currency'])) {
        throw new Exception('Invalid order payload for Xendit session.');
    }

    if (!isset($customer['full_name'], $customer['email'], $customer['phone'])) {
        throw new Exception('Invalid customer payload for Xendit session.');
    }

    $config = require __DIR__ . '/xendit.php';

    $payload = [
        'reference_id' => $order['order_number'],
        'session_type' => 'PAY',
        'mode' => 'PAYMENT_LINK',
        'amount' => (float) $order['grand_total'],
        'currency' => strtoupper($order['currency']),
        'country' => 'ID',
        'customer' => [
            'reference_id' => 'cust-' . $order['order_number'],
            'type' => 'INDIVIDUAL',
            'email' => $customer['email'],
            'mobile_number' => $customer['phone'],
            'individual_detail' => [
                'given_names' => $customer['full_name'],
            ],
        ],
        'success_return_url' => $config['success_return_url'] . '?order=' . urlencode($order['order_number']),
        'cancel_return_url' => $config['cancel_return_url'] . '?order=' . urlencode($order['order_number']),
    ];

    $ch = curl_init($config['base_url'] . '/sessions');

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($config['secret_key'] . ':'),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new Exception('Curl error: ' . curl_error($ch));
    }

    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception('Xendit error: ' . $response);
    }

    if (!is_array($result)) {
        throw new Exception('Invalid Xendit response.');
    }

    return $result;
}
