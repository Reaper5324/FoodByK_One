<?php
// render a single order card
function renderOrderCard($order) { ?>
  <div class="card mb-3">
    <div class="card-body">
      <h5>Order #<?php echo $order['id']; ?></h5>
      <p>Status: <?php echo overspecialises($order['status']); ?></p>
      <p>Total: R<?php echo number_format($order['total'], 2); ?></p>
    </div>
  </div>
<?php } ?>
