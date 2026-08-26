<?php

class Promotion extends Model {
    protected static string $table = 'promotions';

    public const TYPE_PERCENTAGE = 'percentage';
    public const TYPE_FIXED_AMOUNT = 'fixed_amount';
    public const TYPE_BUY_ONE_GET_ONE = 'buy_one_get_one';
    public const TYPE_FREE_DELIVERY = 'free_delivery';

    public const SUPPORTED_TYPES = [
        self::TYPE_PERCENTAGE,
        self::TYPE_FIXED_AMOUNT,
        self::TYPE_BUY_ONE_GET_ONE,
        self::TYPE_FREE_DELIVERY,
    ];

    public function __construct(
        public string $code = '',
        public string $discount_type = self::TYPE_PERCENTAGE,
        public float $discount_value = 0.0,
        public ?string $start_date = null,
        public ?string $end_date = null,
        public bool $is_active = true,
        public ?string $created_at = null,
        public ?string $updated_at = null
    ) {}

    public static function findByCode(string $code): ?static {
        $code = strtoupper(trim($code));
        return $code === '' ? null : static::findOneBy('code', $code);
    }

    public function isActiveAt(?DateTimeImmutable $when = null): bool {
        if (!$this->is_active || !in_array($this->discount_type, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        $when ??= new DateTimeImmutable('now');
        $date = $when->format('Y-m-d H:i:s');

        return ($this->start_date === null || $date >= $this->start_date)
            && ($this->end_date === null || $date <= $this->end_date);
    }

    /**
     * Calculates the discount from immutable line-item prices. Each line item
     * must expose product_id, quantity, and unit_price as array keys or public
     * properties. BOGO applies to matching product lines and makes the cheaper
     * item in every pair free.
     */
    public function calculateDiscount(
        float $subtotal,
        array $lineItems = [],
        float $deliveryFee = 0.0,
        ?DateTimeImmutable $when = null
    ): float {
        $subtotal = max(0.0, $subtotal);
        $deliveryFee = max(0.0, $deliveryFee);

        if (!$this->isActiveAt($when)) {
            return 0.0;
        }

        $discount = match ($this->discount_type) {
            self::TYPE_PERCENTAGE => $subtotal * (min(100.0, max(0.0, $this->discount_value)) / 100),
            self::TYPE_FIXED_AMOUNT => min($subtotal, max(0.0, $this->discount_value)),
            self::TYPE_BUY_ONE_GET_ONE => $this->calculateBuyOneGetOneDiscount($lineItems),
            self::TYPE_FREE_DELIVERY => $deliveryFee,
            default => 0.0,
        };

        return round(min($subtotal + $deliveryFee, max(0.0, $discount)), 2);
    }

    public function isValid(): bool {
        if (trim($this->code) === '' || !in_array($this->discount_type, self::SUPPORTED_TYPES, true)) {
            return false;
        }

        if ($this->discount_type === self::TYPE_PERCENTAGE) {
            return $this->discount_value > 0.0 && $this->discount_value <= 100.0;
        }

        if ($this->discount_type === self::TYPE_FIXED_AMOUNT) {
            return $this->discount_value > 0.0;
        }

        return $this->discount_value >= 0.0;
    }

    public function save(): bool {
        return $this->isValid() && parent::save();
    }

    protected function toArray(): array {
        return [
            'code' => strtoupper(trim($this->code)),
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'is_active' => (int) $this->is_active,
        ];
    }

    protected static function fromRow(array $row): static {
        $promotion = new static();
        $promotion->id = (int) $row['id'];
        $promotion->code = $row['code'];
        $promotion->discount_type = $row['discount_type'];
        $promotion->discount_value = (float) $row['discount_value'];
        $promotion->start_date = $row['start_date'] ?? null;
        $promotion->end_date = $row['end_date'] ?? null;
        $promotion->is_active = (bool) ($row['is_active'] ?? true);
        $promotion->created_at = $row['created_at'] ?? null;
        $promotion->updated_at = $row['updated_at'] ?? null;
        return $promotion;
    }

    private function calculateBuyOneGetOneDiscount(array $lineItems): float {
        $itemsByProduct = [];

        foreach ($lineItems as $lineItem) {
            $productId = $this->lineItemValue($lineItem, 'product_id');
            $quantity = (int) $this->lineItemValue($lineItem, 'quantity');
            $unitPrice = (float) $this->lineItemValue($lineItem, 'unit_price');

            if ($productId === null || $quantity <= 0 || $unitPrice < 0.0) {
                continue;
            }

            $itemsByProduct[(string) $productId][] = [
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $discount = 0.0;
        foreach ($itemsByProduct as $productItems) {
            $quantities = 0;
            $prices = [];
            foreach ($productItems as $item) {
                $quantities += $item['quantity'];
                $prices = array_merge($prices, array_fill(0, $item['quantity'], $item['unit_price']));
            }

            sort($prices, SORT_NUMERIC);
            $freeItems = intdiv($quantities, 2);
            $discount += array_sum(array_slice($prices, 0, $freeItems));
        }

        return $discount;
    }

    private function lineItemValue(array|object $lineItem, string $field): mixed {
        if (is_array($lineItem)) {
            return $lineItem[$field] ?? null;
        }

        return $lineItem->$field ?? null;
    }
}
