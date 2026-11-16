<?php

class BusinessSettings extends Model {

protected static string $table = 'business_settings';

public function __construct(
    public float   $business_lat          = 0.0,
    public float   $business_long         = 0.0,
    public float   $delivery_radius_km    = 5.0,
    public float   $collection_radius_km  = 15.0,
    public float   $delivery_fee          = 0.0,
    public string  $trading_hours_start   = '09:00:00',
    public string  $trading_hours_end     = '20:00:00',
    public bool    $delivery_enabled      = true,
    public bool    $collection_enabled    = true,
    public int     $slot_duration_minutes = 30,
    public int     $max_orders_per_slot   = 10,
    public int     $max_item_quantity     = 6,
    public int     $max_cart_items        = 10,
    public float   $minimum_order_amount  = 0.0,
    public bool    $ordering_enabled      = true,
    public string  $business_phone       = '081 447 8365',
    public string  $business_address     = '8513 Goshawk Street, Lenasia South, Gauteng',
    public ?string $updated_at            = null
) {}

public static function current(): static {
    return static::findById(1) ?? new static();
}

public function isWithinTradingHours(\DateTimeImmutable $when): bool {
    $time = $when->format('H:i:s');
    return $time >= $this->trading_hours_start && $time <= $this->trading_hours_end;
}

protected function toArray(): array {
    return [
        'business_lat'          => $this->business_lat,
        'business_long'         => $this->business_long,
        'delivery_radius_km'    => $this->delivery_radius_km,
        'collection_radius_km'  => $this->collection_radius_km,
        'delivery_fee'          => $this->delivery_fee,
        'trading_hours_start'   => $this->trading_hours_start,
        'trading_hours_end'     => $this->trading_hours_end,
        'delivery_enabled'      => (int) $this->delivery_enabled,
        'collection_enabled'    => (int) $this->collection_enabled,
        'slot_duration_minutes' => $this->slot_duration_minutes,
        'max_orders_per_slot'   => $this->max_orders_per_slot,
        'max_item_quantity'     => $this->max_item_quantity,
        'max_cart_items'        => $this->max_cart_items,
        'minimum_order_amount'  => $this->minimum_order_amount,
        'ordering_enabled'      => (int) $this->ordering_enabled,
        'business_phone'        => $this->business_phone,
        'business_address'      => $this->business_address,
    ];
}

protected static function fromRow(array $row): static {
    $s                        = new static();
    $s->id                    = (int)   $row['id'];
    $s->business_lat          = (float) $row['business_lat'];
    $s->business_long         = (float) $row['business_long'];
    $s->delivery_radius_km    = (float) $row['delivery_radius_km'];
    $s->collection_radius_km  = (float) $row['collection_radius_km'];
    $s->delivery_fee          = (float) $row['delivery_fee'];
    $s->trading_hours_start   =         $row['trading_hours_start'];
    $s->trading_hours_end     =         $row['trading_hours_end'];
    $s->delivery_enabled      = (bool)  $row['delivery_enabled'];
    $s->collection_enabled    = (bool)  $row['collection_enabled'];
    $s->slot_duration_minutes = (int) ($row['slot_duration_minutes'] ?? 30);
    $s->max_orders_per_slot   = (int) ($row['max_orders_per_slot'] ?? 10);
    $s->max_item_quantity     = (int) ($row['max_item_quantity'] ?? 6);
    $s->max_cart_items        = (int) ($row['max_cart_items'] ?? 10);
    $s->minimum_order_amount  = (float) ($row['minimum_order_amount'] ?? 0);
    $s->ordering_enabled      = (bool) ($row['ordering_enabled'] ?? true);
    $s->business_phone        = (string) ($row['business_phone'] ?? '081 447 8365');
    $s->business_address      = (string) ($row['business_address'] ?? '8513 Goshawk Street, Lenasia South, Gauteng');
    $s->updated_at            =         $row['updated_at'] ?? null;
    return $s;
}

}
