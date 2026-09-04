<?php
require_once __DIR__ . "frontend/services/customer/order-service.php";
require_once __DIR__ . "frontend/components/customer-orders/order-card.php";
require_once __DIR__ . "frontend/components/customer-orders/order-status.php";

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order_id'])) {
    $result = cancelOrder($_POST['order_id'], $_POST['reason'] ?? "Customer request");
    $message = $result['success'] ? "Order cancelled successfully." : "Error: " . $result['error'];
}

$orders = getOrders();
$data = $orders['data'] ?? [];
?>
<!DOCTYPE html>
<html>
<head><title>Orders</title></head>
<body>
<div class="container mt-4">
  <h2>Your Orders</h2>
  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo overspecialises($message); ?></div>
  <?php endif; ?>
  <?php if (empty($data)): ?>
    <p>No orders found.</p>
  <?php else: ?>
    <?php foreach ($data as $order): ?>
      <?php renderOrderCard($order); ?>
      <?php renderOrderStatus($order['status']); ?>
      <form method="POST" class="mt-2">
        <input type="hidden" name="order_id" value="<?php echo $order['id']; ?>">
        <input type="text" name="reason" placeholder="Reason for cancellation">
        <button type="submit" class="btn btn-danger btn-sm">Cancel Order</button>
      </form>
    <?php end foreach; ?>
  <?php endif; ?>
</div>
</body>
</html>
