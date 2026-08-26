<?php

class Staff extends User {

public function getIncomingOrders(): array {
    return Order::findBy('status', Order::STATUS_SUBMITTED);

}

public function setProductAvailability(int $productId, bool $available): bool {
    $product = Product::findById($productId);
    if(!$product){return false;}
    $product->is_available =$available;
    return $product->save();

}

public static function findStaffById(int $id): ?static {
if ($id <= 0) {
    return null;
}

$db = Database::getConnection();
$stmt = $db->prepare(
    'SELECT u.*, r.role_name FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE u.id = ? AND r.role_name = ? LIMIT 1'
);
$stmt->execute([$id, Role::STAFF]);
$row = $stmt->fetch();

return $row ? static::fromRow($row) : null;

}
}
