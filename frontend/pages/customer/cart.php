<?php
require_once __DIR__ . "frontend/services/customer/cart-services.php";
require_once __DIR__ . "frontend/components/cart/cart-item.php";
require_once __DIR__ . "frontend/components/cart/cart-summary.php";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    addToCart($_POST['product_id']);
}
$cart = getCart();
$items = $cart['data']['items'] ?? [];
$total = $cart['data']['total'] ?? 0;
?>
<!DOCTYPE html>
<html>
<head><title>Cart</title></head>
<body>
<div class="container mt-4">
  <h2>Your Cart</h2>
  <?php if (empty($items)): ?>
    <p>Your cart is empty.</p>
  <?php else: ?>
    <table class="table">
      <tbody>
        <?php foreach ($items as $item) renderCartItem($item); ?>
      </tbody>
    </table>
    <?php renderCartSummary($total); ?>
  <?php endif; ?>
</div>
</body>
</html>
