<?php
session_start();

// Pastikan request adalah POST dan membawa data cart_data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cart_data'])) {
    
    // Ubah string JSON dari JavaScript menjadi Array PHP
    $cartItems = json_decode($_POST['cart_data'], true);

    if (is_array($cartItems)) {
        // Reset/Kosongkan guest cart sebelumnya agar tidak dobel
        $_SESSION['guest_cart'] = [];

        foreach ($cartItems as $item) {
            // Format array ini disesuaikan persis dengan kebutuhan checkout.php
            $_SESSION['guest_cart'][] = [
                'product_id'      => (int)($item['id'] ?? 0),
                'product_size_id' => (int)($item['size_id'] ?? 0),
                'slug'            => (string)($item['slug'] ?? ''),
                'quantity'        => (int)($item['qty'] ?? 1),
                'currency'        => 'IDR'
            ];
        }

        echo json_encode(['success' => true]);
        exit;
    }
}

echo json_encode(['success' => false]);
