<?php
require_once __DIR__ . '/../../services/customer/checkout-services.php';

$message = null;
$paymentSetup = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderData = [
        'fulfilment_type' => $_POST['fulfilment_type'] ?? '',
        'address_id' => ($_POST['address_id'] ?? '') !== '' ? (int) $_POST['address_id'] : null,
        'requested_window_start' => $_POST['requested_window_start'] ?? '',
        'requested_window_end' => $_POST['requested_window_end'] ?? '',
        'promotion_code' => $_POST['promotion_code'] ?? null,
    ];

    $result = submitCheckout($orderData);
    if ($result && ($result['success'] ?? false)) {
        $paymentSetup = $result['data']['payment_setup'] ?? null;
        if (!$paymentSetup || empty($paymentSetup['redirect_url']) || empty($paymentSetup['fields'])) {
            $message = 'Order submitted, but payment setup could not be started. Please contact the restaurant.';
        }
    } else {
        $message = 'Error: ' . ($result['error'] ?? 'Unknown error');
    }
}
?>
<!DOCTYPE html>
<html>
<head><title>Payment</title></head>
<body>
<?php if ($paymentSetup): ?>
  <p>Redirecting to PayFast to securely save your card details...</p>
  <form id="payfast-token-setup" method="post" action="<?php echo htmlspecialchars($paymentSetup['redirect_url'], ENT_QUOTES, 'UTF-8'); ?>">
    <?php foreach ($paymentSetup['fields'] as $name => $value): ?>
      <input type="hidden" name="<?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?>" value="<?php echo htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <button type="submit">Continue to PayFast</button>
  </form>
  <script>document.getElementById('payfast-token-setup').submit();</script>
<?php else: ?>
<div class="container mt-4">
  <h2>Payment Selection</h2>
  <?php if ($message): ?>
    <div class="alert alert-info"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
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
<?php endif; ?>
</body>
</html>
