<?php
function previewCheckout($type, $addressId = null, $promo = null) {
    $url = API_BASE_URL . "/checkout/preview";
    $payload = json_encode([
        "fulfilment_type" => $type,
        "address_id" => $addressId,
        "promotion_code" => $promo
    ]);

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

function submitCheckout($data) {
    $url = API_BASE_URL . "/checkout/submit";
    $ch = curl_init($url);
    curl_set opt_array($ch, [
        CURL OPT_RETURN TRANSFER => true,
        CURL OPT_POST => true,
        CURL OPT_OUTFIELDS => json_encode($data),
        CURL OPT_HTTPHEADER => ["Content-Type: application/json"]
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}
