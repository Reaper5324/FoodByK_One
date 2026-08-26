<?php

class OrderStatusHistory extends Model {

protected static string $table = 'order_status_history';

public function __construct(
    public int     $order_id    = 0,
    public string  $from_status = '',
    public string  $to_status   = '',
    public ?int    $changed_by  = null,
    public ?string $notes       = null,
    public ?string $created_at  = null
) {}

protected function toArray(): array {
    return ['order_id' => $this->order_id, 'from_status' => $this->from_status, 'to_status' => $this->to_status, 'changed_by' => $this->changed_by, 'notes' => $this->notes];
}

protected static function fromRow(array $row): static {
    $h              = new static();
    $h->id          = (int) $row['id'];
    $h->order_id    = (int) $row['order_id'];
    $h->from_status = $row['from_status'];
    $h->to_status   = $row['to_status'];
    $h->changed_by  = isset($row['changed_by']) ? (int) $row['changed_by'] : null;
    $h->notes       = $row['notes'] ?? null;
    $h->created_at  = $row['created_at'] ?? null;
    return $h;
}

}