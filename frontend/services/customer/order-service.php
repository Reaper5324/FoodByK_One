<?php
function getOrders() {
    $url = API_BASE_URL . "/orders"; // adjust if backend exposes /orders/history
    $ch = curl_init($url);
    curl_set opt($ch, CURL OPT_RETURN TRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
function cancelOrder($orderId, $reason) {
    $url = API_BASE_URL . "/orders/" . intval($orderId) . "/cancel";
    $payload = json_encode(["reason" => $reason]);

    $ch = curl_init($url);
    curl_set opt_array($ch, [
        CURL OPT_RETURN TRANSFER => true,
        CURL OPT_POST => true,
        CURL OPT_OUTFIELDS => $payload,
        CURL OPT_HTTPHEADER => ["Content-Type: application/json"]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}