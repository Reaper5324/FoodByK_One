<?php

class Order extends Model {

protected static string $table = 'orders';

const TYPE_COLLECTION = 'collection';
const TYPE_DELIVERY   = 'delivery';

const STATUS_SUBMITTED      = 'submitted';
const STATUS_ACCEPTED       = 'accepted';
const STATUS_ADJUSTED       = 'adjusted';
const STATUS_DECLINED       = 'declined';
const STATUS_CHARGE_PENDING = 'charge_pending';
const STATUS_PAID           = 'paid';
const STATUS_PAYMENT_FAILED = 'payment_failed';
const STATUS_CANCELLED      = 'cancelled';
const STATUS_PREPARING      = 'preparing';
const STATUS_READY          = 'ready';
const STATUS_COMPLETED      = 'completed';

private const ALLOWED_TRANSITIONS = [
    self::STATUS_SUBMITTED      => [self::STATUS_ACCEPTED, self::STATUS_ADJUSTED, self::STATUS_DECLINED, self::STATUS_CANCELLED],
    self::STATUS_ACCEPTED       => [self::STATUS_CHARGE_PENDING, self::STATUS_CANCELLED],
    self::STATUS_ADJUSTED       => [self::STATUS_CHARGE_PENDING, self::STATUS_CANCELLED],
    self::STATUS_CHARGE_PENDING => [self::STATUS_PAID, self::STATUS_PAYMENT_FAILED],
    self::STATUS_PAYMENT_FAILED => [self::STATUS_CHARGE_PENDING, self::STATUS_CANCELLED],
    // PAID is intentionally a dead end except forward to PREPARING - once
    // charged, cancellation is a manual staff/refund process, not an
    // in-app transition, for MVP.
    self::STATUS_PAID           => [self::STATUS_PREPARING],
    self::STATUS_PREPARING      => [self::STATUS_READY],
    self::STATUS_READY          => [self::STATUS_COMPLETED],
];

public function __construct(
    public int     $customer_id             = 0,
    public ?int    $staff_id                = null,
    public string  $fulfilment_type         = self::TYPE_COLLECTION,
    public string  $status                  = self::STATUS_SUBMITTED,
    public ?string $requested_window_start  = null,
    public ?string $requested_window_end    = null,
    public ?string $confirmed_window_start  = null,
    public ?string $confirmed_window_end    = null,
    public ?string $decline_reason          = null,
    public ?string $cancel_reason           = null,
    public ?int    $promotion_id            = null,
    public float   $locked_discount         = 0.0,
    public float   $subtotal                = 0.0,
    public ?int    $address_id              = null,
    public float   $distance_km             = 0.0,
    public float   $delivery_fee            = 0.0,
    public ?string $created_at              = null,
    public ?string $updated_at              = null
) {}

public function total(): float {
    return round($this->subtotal + $this->delivery_fee, 2);
}

public function canTransitionTo(string $newStatus): bool {
    return in_array($newStatus, self::ALLOWED_TRANSITIONS[$this->status] ?? [], true);
}

public function getItems(): array {
    return OrderItem::findBy('order_id', $this->id);
}

public function getPayment(): ?Payment {
    return Payment::findOneBy('order_id', $this->id);
}

public function getCustomer(): ?Customer {
    return Customer::findCustomerById($this->customer_id);
}

public function getAddress(): ?Address {
    return $this->address_id ? Address::findById($this->address_id) : null;
}

public static function findById(int $id): ?static {
    return parent::findById($id);
}

public static function lockById(int $id): ?static {
    $db   = Database::getConnection();
    $stmt = $db->prepare("SELECT * FROM `orders` WHERE id = ? FOR UPDATE");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ? static::fromRow($row) : null;
}

protected function toArray(): array {
    return [
        'customer_id'             => $this->customer_id,
        'staff_id'                => $this->staff_id,
        'fulfilment_type'         => $this->fulfilment_type,
        'status'                  => $this->status,
        'requested_window_start'  => $this->requested_window_start,
        'requested_window_end'    => $this->requested_window_end,
        'confirmed_window_start'  => $this->confirmed_window_start,
        'confirmed_window_end'    => $this->confirmed_window_end,
        'decline_reason'          => $this->decline_reason,
        'cancel_reason'           => $this->cancel_reason,
        'promotion_id'            => $this->promotion_id,
        'locked_discount'         => $this->locked_discount,
        'subtotal'                => $this->subtotal,
        'address_id'              => $this->address_id,
        'distance_km'             => $this->distance_km,
        'delivery_fee'            => $this->delivery_fee,
    ];
}

protected static function fromRow(array $row): static {
    $o                         = new static();
    $o->id                     = (int) $row['id'];
    $o->customer_id            = (int) $row['customer_id'];
    $o->staff_id               = isset($row['staff_id']) ? (int) $row['staff_id'] : null;
    $o->fulfilment_type        = $row['fulfilment_type'];
    $o->status                 = $row['status'];
    $o->requested_window_start = $row['requested_window_start'] ?? null;
    $o->requested_window_end   = $row['requested_window_end'] ?? null;
    $o->confirmed_window_start = $row['confirmed_window_start'] ?? null;
    $o->confirmed_window_end   = $row['confirmed_window_end'] ?? null;
    $o->decline_reason         = $row['decline_reason'] ?? null;
    $o->cancel_reason          = $row['cancel_reason'] ?? null;
    $o->promotion_id           = isset($row['promotion_id']) ? (int) $row['promotion_id'] : null;
    $o->locked_discount        = (float) ($row['locked_discount'] ?? 0);
    $o->subtotal               = (float) ($row['subtotal'] ?? 0);
    $o->address_id             = isset($row['address_id']) ? (int) $row['address_id'] : null;
    $o->distance_km            = (float) ($row['distance_km'] ?? 0);
    $o->delivery_fee           = (float) ($row['delivery_fee'] ?? 0);
    $o->created_at             = $row['created_at'] ?? null;
    $o->updated_at             = $row['updated_at'] ?? null;
    return $o;
}

}