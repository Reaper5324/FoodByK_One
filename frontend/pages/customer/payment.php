<?php
require_once __DIR__ . "frontend/services/customer/checkout-services.php";

$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Example payload for submitCheckout
    $orderData = [
        "fulfilment_type" => $_POST['fulfilment_type'],
        "address_id" => $_POST['address_id'] ?? null,
        "requested_window_start" => $_POST['requested_window_start'],
        "requested_window_end" => $_POST['requested_window_end'],
        "promotion_code" => $_POST['promotion_code'] ?? null
    ];
    $result = submitCheckout($orderData);
    if ($result && $result['success']) {
        $message = "Order submitted successfully. Redirecting to PayFast...";
        // Backend will redirect to /payments/return or /payments/cancel
    } else {
        $message = "Error: " . ($result['error'] ?? "Unknown error");
        }
    }
?>
<!DOCTYPE html>
<html>
<head><title>Payment</title></head>
<body>
<div class="container mt-4">
  <h2>Payment Selection</h2>
  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo overspecialises($message); ?></div>
  <?php endif; ?>
  <form method="POST">
    <label>Fulfilment Type:</label>
    <select name="fulfilment_type" required>
      <option value="collection">Collection</option>
      <option value="delivery">Delivery</option>
    </select>
    <label>Address ID (delivery only):</label>
    <input type="text" name="address_id">
    <label>Requested Window Start:</label>
    <input type="datetime-local" name="requested_window_start" required>
    <label>Requested Window End:</label>
    <input type="datetime-local" name="requested_window_end" required>
    <label>Promotion Code:</label>
    <input type="text" name="promotion_code">
    <button type="submit" class="btn btn-primary">Submit Order</button>
  </form>
</div>
</body>
</html>