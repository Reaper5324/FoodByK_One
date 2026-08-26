<?php

class OrderItem extends Model {

protected static string $table = 'order_items';

public function __construct(
    public int   $order_id   = 0,
    public int   $product_id = 0,
    public int   $quantity   = 1,
    public float $unit_price = 0.0
) {}

public function getLineTotal(): float {
    return round($this->unit_price * $this->quantity, 2);
}

public function getProduct(): ?Product {
    return Product::findById($this->product_id);
}

protected function toArray(): array {
    return ['order_id' => $this->order_id, 'product_id' => $this->product_id, 'quantity' => $this->quantity, 'unit_price' => $this->unit_price];
}

protected static function fromRow(array $row): static {
    $i             = new static();
    $i->id         = (int)   $row['id'];
    $i->order_id   = (int)   $row['order_id'];
    $i->product_id = (int)   $row['product_id'];
    $i->quantity   = (int)   $row['quantity'];
    $i->unit_price = (float) $row['unit_price'];
    return $i;
}

}