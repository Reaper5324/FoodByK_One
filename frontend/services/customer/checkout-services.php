<?php
if (!defined('API_BASE_URL')) {
    define('API_BASE_URL', rtrim(getenv('API_BASE_URL') ?: 'http://localhost:8000', '/'));
}

function checkoutApiRequest($path, $payload = null) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $headers = [
        'Content-Type: application/json',
        'Cookie: ' . session_name() . '=' . session_id(),
    ];
    if (!empty($_SESSION['csrf_token'])) {
        $headers[] = 'X-CSRF-Token: ' . $_SESSION['csrf_token'];
    }

    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload);
    }

    $ch = curl_init(API_BASE_URL . $path);
    curl_setopt_array($ch, $options);
    $response = curl_exec($ch);
    curl_close($ch);

    return is_string($response) ? json_decode($response, true) : null;
}

function previewCheckout($type, $addressId = null, $promo = null) {
    return checkoutApiRequest('/checkout/preview', [
        "fulfilment_type" => $type,
        "address_id" => $addressId,
        "promotion_code" => $promo
    ]);
}

function submitCheckout($data) {
    return checkoutApiRequest('/checkout/submit', $data);
}
