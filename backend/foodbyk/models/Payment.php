<?php

class Payment extends Model {

protected static string $table = 'payments';

const STATUS_TOKENIZED      = 'tokenized';
const STATUS_CHARGE_PENDING = 'charge_pending';
const STATUS_SUCCESS        = 'success';
const STATUS_FAILED         = 'failed';
const STATUS_VOIDED         = 'voided';

public function __construct(
    public int     $order_id          = 0,
    public string  $gateway           = 'payfast',
    public ?string $gateway_token     = null,
    public ?string $gateway_reference = null,
    public float   $amount            = 0.0,
    public string  $status            = self::STATUS_TOKENIZED,
    public ?string $created_at        = null,
    public ?string $charged_at        = null
) {}

public function beginCharge(): bool {
    if ($this->status !== self::STATUS_TOKENIZED) return false;
    $this->status = self::STATUS_CHARGE_PENDING;
    return $this->save();
}

public function markSuccessful(string $gatewayReference): bool {
    if ($this->status === self::STATUS_SUCCESS) return true; // idempotent - duplicate ITN
    $this->status            = self::STATUS_SUCCESS;
    $this->gateway_reference = $gatewayReference;
    $this->charged_at        = date('Y-m-d H:i:s');
    $ok = $this->save();

    if ($ok) {
        $order = Order::findById($this->order_id);
        if ($order && $order->canTransitionTo(Order::STATUS_PAID)) {
            $order->status = Order::STATUS_PAID;
            $order->save();
        }
    }
    return $ok;
}

public function markFailed(): bool {
    $this->status = self::STATUS_FAILED;
    $ok = $this->save();

    if ($ok) {
        $order = Order::findById($this->order_id);
        if ($order && $order->canTransitionTo(Order::STATUS_PAYMENT_FAILED)) {
            $order->status = Order::STATUS_PAYMENT_FAILED;
            $order->save();
        }
    }
    return $ok;
}

public function voidToken(): bool {
    $this->status = self::STATUS_VOIDED;
    return $this->save();
}

protected function toArray(): array {
    return [
        'order_id'          => $this->order_id,
        'gateway'           => $this->gateway,
        'gateway_token'     => $this->gateway_token,
        'gateway_reference' => $this->gateway_reference,
        'amount'            => $this->amount,
        'status'            => $this->status,
        'charged_at'        => $this->charged_at,
    ];
}

protected static function fromRow(array $row): static {
    $p                    = new static();
    $p->id                = (int)   $row['id'];
    $p->order_id          = (int)   $row['order_id'];
    $p->gateway           =         $row['gateway'] ?? 'payfast';
    $p->gateway_token     =         $row['gateway_token'] ?? null;
    $p->gateway_reference =         $row['gateway_reference'] ?? null;
    $p->amount            = (float) $row['amount'];
    $p->status            =         $row['status'];
    $p->created_at        =         $row['created_at'] ?? null;
    $p->charged_at        =         $row['charged_at'] ?? null;
    return $p;
}

}