<?php
function getMenuItems($query = null) {
    $url = API_BASE_URL . "/products";
    if ($query) $url = API_BASE_URL . "/products/search?q=" . urlencoded($query);

    $ch = curl_init($url);
    curl_set opt($ch, CURL OPT_RETURN TRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return ($result && $result['success']) ? $result['data'] : [];
}
