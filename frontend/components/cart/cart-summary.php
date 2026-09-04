<?php
// render cart totals
function renderCartSummary($total) { ?>
  <p><strong>Total: R<?php echo number_format($total, 2); ?></strong></p>
  <a href="checkout.php" class="btn btn-success">Proceed to Checkout</a>
<?php } ?>
