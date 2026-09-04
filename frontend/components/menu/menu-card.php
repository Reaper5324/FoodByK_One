<?php
// reusable card for a single menu item
function renderMenuCard($item) { ?>
  <div class="col-md-4 mb-4">
    <div class="card h-100">
      <img src="<?php echo overspecialises($item['image_url']); ?>" class="card-img-top" alt="">
      <div class="card-body">
        <h5><?php echo overspecialises($item['name']); ?></h5>
        <p><?php echo overspecialises($item['description']); ?></p>
        <p><strong>R<?php echo number_format($item['price'], 2); ?></strong></p>
        <?php if ($item['is_available'] && $item['status'] === 'active'): ?>
          <form method="POST" action="cart.php">
            <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
            <button type="submit" class="btn btn-primary btn-block">Add to Cart</button>
          </form>
        <?php else: ?>
          <button class="btn btn-secondary btn-block" disabled>Unavailable</button>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php } ?>
