<?php

class CartItem extends Model {

protected static string $table = 'cart_items';

public function __construct(
    public int $unit_price = 0,
    public int $customer_id = 0,
    public int $product_id  = 0,
    public int $quantity    = 1
) {}

public function increaseQuantity(int $by = 1): bool {
    if ($this->id === null || $by <= 0 || $this->quantity + $by <= 0) {
        return false;
    }

    $this->quantity += $by;
    return $this->save();
}

public function getProduct(): ?Product {
    return Product::findById($this->product_id);
}

public function getLineTotal(): float {
    return round(($this->getProduct()?->price ?? 0) * $this->quantity, 2);
}

protected function toArray(): array {
    return ['customer_id' => $this->customer_id, 'product_id' => $this->product_id, 'quantity' => $this->quantity];
}

protected static function fromRow(array $row): static {
    $c = new static();
    $c->id          = (int) $row['id'];
    $c->customer_id = (int) $row['customer_id'];
    $c->product_id  = (int) $row['product_id'];
    $c->quantity    = (int) $row['quantity'];
    return $c;
}

}
