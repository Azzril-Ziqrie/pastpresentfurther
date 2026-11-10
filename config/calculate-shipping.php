<?php
header('Content-Type: application/json; charset=UTF-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function ($e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ], JSON_PRETTY_PRINT);
    exit;
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line'],
        ], JSON_PRETTY_PRINT);
        exit;
    }
});

$config = require __DIR__ . '/rajaongkir.php';

function normalizeRajaOngkirServices(array $raw): array
{
    $rows = [];

    if (isset($raw['data']) && is_array($raw['data'])) {
        $rows = $raw['data'];
    } elseif (isset($raw['results']) && is_array($raw['results'])) {
        $rows = $raw['results'];
    } elseif (isset($raw['rajaongkir']['results']) && is_array($raw['rajaongkir']['results'])) {
        $rows = $raw['rajaongkir']['results'];
    } elseif (isset($raw['rajaongkir']['result']) && is_array($raw['rajaongkir']['result'])) {
        $rows = $raw['rajaongkir']['result'];
    } elseif (isset($raw['rajaongkir']['data']) && is_array($raw['rajaongkir']['data'])) {
        $rows = $raw['rajaongkir']['data'];
    }

    $normalized = [];

    foreach ($rows as $row) {
        $cost = 0.0;

        if (isset($row['cost']) && !is_array($row['cost'])) {
            $cost = (float) $row['cost'];
        } elseif (isset($row['cost'][0]['value'])) {
            $cost = (float) $row['cost'][0]['value'];
        } elseif (isset($row['value'])) {
            $cost = (float) $row['value'];
        }

        $normalized[] = [
            'service' => $row['service'] ?? ($row['description'] ?? ($row['name'] ?? ($row['code'] ?? 'Service'))),
            'code' => $row['code'] ?? null,
            'description' => $row['description'] ?? null,
            'cost' => $cost,
            'currency' => 'IDR',
            'etd' => $row['etd'] ?? ($row['estimated'] ?? null),
            'raw_row' => $row,
        ];
    }

    return $normalized;
}

$destination = trim((string) ($_POST['destination'] ?? ''));
$courier = trim((string) ($_POST['courier'] ?? ''));
$weight = (int) ($_POST['weight'] ?? 0);
$price = trim((string) ($_POST['price'] ?? 'lowest'));

if ($destination === '' || $courier === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Destination and courier are required.',
        'debug_input' => [
            'destination' => $destination,
            'courier' => $courier,
            'weight' => $weight,
            'price' => $price,
        ],
    ], JSON_PRETTY_PRINT);
    exit;
}

if ($weight <= 0) {
    $weight = (int) ($config['default_weight_gram'] ?? 1000);
}

$allowedPriceSort = ['lowest', 'highest'];
if (!in_array($price, $allowedPriceSort, true)) {
    $price = 'lowest';
}

$requestPayload = [
    'origin' => (int) $config['origin_city_id'],
    'destination' => $destination,
    'weight' => $weight,
    'courier' => $courier,
    'price' => $price,
];

$postFields = http_build_query($requestPayload);
$url = rtrim((string) $config['base_url'], '/') . '/calculate/international-cost';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_HTTPHEADER => [
        'key: ' . $config['api_key'],
        'Content-Type: application/x-www-form-urlencoded',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT => 30,
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to connect to RajaOngkir.',
        'curl_error' => $curlError,
        'request_payload' => $requestPayload,
    ], JSON_PRETTY_PRINT);
    exit;
}

$data = json_decode($response, true);

if (!is_array($data)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid JSON response from RajaOngkir.',
        'request_payload' => $requestPayload,
        'raw_text' => $response,
    ], JSON_PRETTY_PRINT);
    exit;
}

$success = $httpCode >= 200 && $httpCode < 300;
$normalizedServices = normalizeRajaOngkirServices($data);

echo json_encode([
    'success' => $success,
    'http_code' => $httpCode,
    'message' => $data['meta']['message'] ?? ($data['message'] ?? 'Response received'),
    'base_currency' => 'IDR',
    'request_payload' => $requestPayload,
    'services' => $normalizedServices,
    'raw' => $data,
], JSON_PRETTY_PRINT);
