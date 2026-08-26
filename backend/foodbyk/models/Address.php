<?php

class Address extends Model {

protected static string $table = 'addresses';

public function __construct(
    public int    $customer_id = 0,
    public string $raw_address = '',
    public ?float $latitude    = null,
    public ?float $longitude   = null,
    public bool   $is_default  = false
) {}

public function hasCoordinates(): bool {
    return $this->latitude !== null && $this->longitude !== null;
}

protected function toArray(): array {
    return [
        'customer_id' => $this->customer_id,
        'raw_address' => $this->raw_address,
        'latitude'    => $this->latitude,
        'longitude'   => $this->longitude,
        'is_default'  => (int) $this->is_default,
    ];
}

protected static function fromRow(array $row): static {
    $a              = new static();
    $a->id          = (int)  $row['id'];
    $a->customer_id = (int)  $row['customer_id'];
    $a->raw_address =        $row['raw_address'];
    $a->latitude    = isset($row['latitude'])  ? (float) $row['latitude']  : null;
    $a->longitude   = isset($row['longitude']) ? (float) $row['longitude'] : null;
    $a->is_default  = (bool) $row['is_default'];
    return $a;
}

}