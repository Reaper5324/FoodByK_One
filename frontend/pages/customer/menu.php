<?php
require_once __DIR__ . "frontend/services/customer/menu-services.php";
require_once __DIR__ . "frontend/components/menu/menu-card.php";
require_once __DIR__ . "frontend/components/menu/menu-filter.php";

$q = $_GET['q'] ?? null;
$items = getMenuItems($q);
?>
<!DOCTYPE html>
<html>
<head><title>Menu</title></head>
<body>
<div class="container mt-4">
  <?php include __DIR__ . "frontend/components/menu/menu-filter.php"; ?>
  <div class="row">
    <?php foreach ($items as $item) renderMenuCard($item); ?>
  </div>
</div>
</body>
</html>
