<?php

class CartItem extends Model {

protected static string $table = 'cart_items';
public array $customizations = [];
public string $customization_hash = '';

public function __construct(
    public int   $customer_id = 0,
    public int   $product_id  = 0,
    public int   $quantity    = 1,
    public float $unit_price  = 0.0,
    public ?string $customization_json = null
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
    $extra = array_sum(array_map(static fn(array $option): float => (float) ($option['price_delta'] ?? 0), $this->customizations));
    return round((($this->getProduct()?->price ?? 0) + $extra) * $this->quantity, 2);
}

public function getCustomizationTotal(): float {
    return round(array_sum(array_map(static fn(array $option): float => (float) ($option['price_delta'] ?? 0), $this->customizations)), 2);
}

protected function toArray(): array {
    return [
        'customer_id' => $this->customer_id,
        'product_id' => $this->product_id,
        'quantity' => $this->quantity,
        'unit_price' => $this->unit_price,
        'customization_json' => $this->customization_json ?? '[]',
        'customization_hash' => $this->customization_hash,
    ];
}

protected static function fromRow(array $row): static {
    $c              = new static();
    $c->id          = (int)   $row['id'];
    $c->customer_id = (int)   $row['customer_id'];
    $c->product_id  = (int)   $row['product_id'];
    $c->quantity    = (int)   $row['quantity'];
    $c->unit_price  = (float) ($row['unit_price'] ?? 0);
    $c->customization_json = (string) ($row['customization_json'] ?? '[]');
    $c->customization_hash = (string) ($row['customization_hash'] ?? '');
    $decoded = json_decode($c->customization_json, true);
    $c->customizations = is_array($decoded) ? $decoded : [];
    return $c;
}

}
