<?php
require_once __DIR__ . "frontend/services/customer/checkout-services.php";

$preview = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $preview = previewCheckout($_POST['fulfilment_type'], $_POST['address_id'] ?? null, $_POST['promotion_code'] ?? null);
}
?>
<!DOCTYPE html>
<html>
<head><title>Checkout</title></head>
<body>
<div class="container mt-4">
  <