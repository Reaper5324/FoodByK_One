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
$user = User::findById($id);
if($user && $user->isStaff()){
    return static::fromRow((array) $user);

}
return null;

}
}