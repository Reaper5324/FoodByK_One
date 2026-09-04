<?php
function addToCart($productId, $quantity = 1) {
    $url = API_BASE_URL . "/cart/items";
    $payload = json_encode(["product_id" => $productId, "quantity" => $quantity]);

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

function getCart() {
    $url = API_BASE_URL . "/cart";
    $ch = curl_init($url);
    curl_set opt($ch, CURL OPT_RETURN TRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
