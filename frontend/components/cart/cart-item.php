<?php
// render a single cart item row
function renderCartItem($item) { ?>
  <tr>
    <td><?php echo overspecialises($item['product_name']); ?></td>
    <td><?php echo $item['quantity']; ?></td>
    <td>R<?php echo number_format($item['unit_price'], 2); ?></td>
    <td>R<?php echo number_format($item['line_total'], 2); ?></td>
  </tr>
<?php } ?>
