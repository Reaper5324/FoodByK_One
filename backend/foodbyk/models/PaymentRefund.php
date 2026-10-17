<?php

class PaymentRefund extends Model implements JsonSerializable {
    protected static string $table = 'payment_refunds';

    public function __construct(
        public int $payment_id = 0,
        public int $requested_by = 0,
        public int $amount_cents = 0,
        public string $reason = '',
        public string $status = 'pending',
        public ?string $created_at = null,
        public ?string $updated_at = null
    ) {}

    protected function toArray(): array {
        return [
            'payment_id' => $this->payment_id,
            'requested_by' => $this->requested_by,
            'amount_cents' => $this->amount_cents,
            'reason' => $this->reason,
            'status' => $this->status,
        ];
    }

    protected static function fromRow(array $row): static {
        $refund = new static(
            payment_id: (int) $row['payment_id'],
            requested_by: (int) $row['requested_by'],
            amount_cents: (int) $row['amount_cents'],
            reason: (string) $row['reason'],
            status: (string) $row['status'],
            created_at: $row['created_at'] ?? null,
            updated_at: $row['updated_at'] ?? null
        );
        $refund->id = (int) $row['id'];
        return $refund;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->id,
            'amount' => round($this->amount_cents / 100, 2),
            'reason' => $this->reason,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
