<?php

class OrderItem extends Model {

protected static string $table = 'order_items';
public ?string $image_url = null;
public array $customizations = [];

public function __construct(
    public string $product_name = '',
    public int   $order_id   = 0,
    public int   $product_id = 0,
    public int   $quantity   = 1,
    public int   $free_quantity = 0,
    public float $unit_price = 0.0,
    public ?string $customization_json = null
) {}

public function save(): bool {
    if ($this->quantity < 1 || $this->free_quantity < 0 || $this->free_quantity > $this->quantity) return false;
    return parent::save();
}

public function getLineTotal(): float {
    return round($this->unit_price * $this->quantity, 2);
}

public function getProduct(): ?Product {
    return Product::findById($this->product_id);
}

    protected function toArray(): array {
        return [
            'order_id'     => $this->order_id,
            'product_id'   => $this->product_id,
            'product_name' => $this->product_name,
            'quantity'     => $this->quantity,
            'free_quantity' => $this->free_quantity,
            'unit_price'   => $this->unit_price,
            'customization_json' => $this->customization_json ?? json_encode($this->customizations, JSON_UNESCAPED_UNICODE),
        ];
    }

    protected static function fromRow(array $row): static {
        $i               = new static();
        $i->id           = (int)   $row['id'];
        $i->order_id     = (int)   $row['order_id'];
        $i->product_id   = (int)   $row['product_id'];
        $i->product_name = (string) ($row['product_name'] ?? '');
        $i->quantity     = (int)   $row['quantity'];
        $i->free_quantity = (int) ($row['free_quantity'] ?? 0);
        $i->unit_price   = (float) $row['unit_price'];
        $i->customization_json = (string) ($row['customization_json'] ?? '[]');
        $decoded = json_decode($i->customization_json, true);
        $i->customizations = is_array($decoded) ? $decoded : [];
        return $i;
    }
}

