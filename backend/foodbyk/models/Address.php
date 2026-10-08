<?php

class Address extends Model implements JsonSerializable {

protected static string $table = 'addresses';

public function __construct(
    public int    $customer_id = 0,
    public string $raw_address = '',
    public ?float $latitude    = null,
    public ?string $street = null,
    public ?string $postal_code = null,
    public ?string $city = null,
    public ?string $province = null,
    public ?float $longitude   = null,
    public bool   $is_default  = false
) {}

public function hasCoordinates(): bool {
    return $this->latitude !== null
        && $this->longitude !== null
        && is_finite($this->latitude)
        && is_finite($this->longitude)
        && $this->latitude >= -90.0
        && $this->latitude <= 90.0
        && $this->longitude >= -180.0
        && $this->longitude <= 180.0;
}

public function jsonSerialize(): array {
    return [
        'id' => $this->id,
        'customer_id' => $this->customer_id,
        'raw_address' => $this->raw_address,
        'street' => $this->street,
        'city' => $this->city,
        'province' => $this->province,
        'postal_code' => $this->postal_code,
        'latitude' => $this->latitude,
        'longitude' => $this->longitude,
        'is_default' => $this->is_default,
        'has_coordinates' => $this->hasCoordinates(),
    ];
}

    protected function toArray(): array {
        return [
            'customer_id' => $this->customer_id,
            'raw_address' => $this->raw_address,
            'street'      => $this->street,
            'postal_code' => $this->postal_code,
            'city'        => $this->city,
            'province'    => $this->province,
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
        $a->street      =        $row['street'] ?? null;
        $a->postal_code = $row['postal_code'] ?? null;
        $a->city        =        $row['city'] ?? null;
        $a->province    =        $row['province'] ?? null;
        $a->latitude    = isset($row['latitude'])  ? (float) $row['latitude']  : null;
        $a->longitude   = isset($row['longitude']) ? (float) $row['longitude'] : null;
        $a->is_default  = (bool) $row['is_default'];
        return $a;
    }

}
