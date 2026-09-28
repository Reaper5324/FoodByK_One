<?php

class AdminSettingsService {

    // Explicit allowlist - the fix for the mass-assignment bug. Only
    // these keys can ever be written, with real validation per field.
    private const ALLOWED_FIELDS = [
        'business_lat', 'business_long', 'delivery_radius_km', 'collection_radius_km',
        'delivery_fee', 'trading_hours_start', 'trading_hours_end',
        'delivery_enabled', 'collection_enabled', 'slot_duration_minutes', 'max_orders_per_slot',
    ];

    public function updateSettings(array $input): array {
        $settings = BusinessSettings::current();

        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) continue;

            $value = $input[$field];
            $error = $this->validateField($field, $value);
            if ($error !== null) {
                return $this->failure($error);
            }

            $settings->$field = $this->castField($field, $value);
        }

        return $settings->save() ? $this->success($settings) : $this->failure('Unable to update settings.');
    }

    public function addPromotion(array $input): array {
        $code = trim((string) ($input['code'] ?? ''));
        $type = (string) ($input['discount_type'] ?? '');
        $value = (float) ($input['discount_value'] ?? 0);
        $start = (string) ($input['start_date'] ?? '');
        $end   = (string) ($input['end_date'] ?? '');

        if ($code === '' || strlen($code) > 40) {
            return $this->failure('Promotion code must be between 1 and 40 characters.');
        }
        if (!in_array($type, [Promotion::TYPE_PERCENTAGE, Promotion::TYPE_FIXED_AMOUNT, Promotion::TYPE_BUY_ONE_GET_ONE, Promotion::TYPE_FREE_DELIVERY], true)) {
            return $this->failure('Invalid discount type.');
        }
        if ($value < 0) {
            return $this->failure('Discount value cannot be negative.');
        }
        if (Promotion::findByCode($code) !== null) {
            return $this->failure('A promotion with this code already exists.');
        }

        $promo = new Promotion(code: $code, discount_type: $type, discount_value: $value, start_date: $start, end_date: $end);
        return $promo->save() ? $this->success($promo, 201) : $this->failure('Unable to create promotion.');
    }

    public function updatePromotion(int $promotionId, array $input): array {
        $promotion = Promotion::findById($promotionId);
        if ($promotion === null) {
            return $this->failure('Promotion not found.');
        }

        $code = strtoupper(trim((string) ($input['code'] ?? $promotion->code)));
        $type = (string) ($input['discount_type'] ?? $promotion->discount_type);
        $value = $input['discount_value'] ?? $promotion->discount_value;
        $start = array_key_exists('start_date', $input) ? $input['start_date'] : $promotion->start_date;
        $end = array_key_exists('end_date', $input) ? $input['end_date'] : $promotion->end_date;
        $active = $promotion->is_active;

        if ($code === '' || strlen($code) > 40) {
            return $this->failure('Promotion code must be between 1 and 40 characters.');
        }
        $duplicate = Promotion::findByCode($code);
        if ($duplicate !== null && $duplicate->id !== $promotion->id) {
            return $this->failure('A promotion with this code already exists.');
        }
        if (!in_array($type, Promotion::SUPPORTED_TYPES, true)) {
            return $this->failure('Invalid discount type.');
        }
        if (!is_numeric($value) || (float) $value < 0 || ($type === Promotion::TYPE_PERCENTAGE && (float) $value > 100)) {
            return $this->failure('Invalid discount value.');
        }
        if ($type === Promotion::TYPE_FIXED_AMOUNT && (float) $value <= 0) {
            return $this->failure('Fixed discount value must be greater than zero.');
        }
        if (($start !== null && !$this->isValidDate($start)) || ($end !== null && !$this->isValidDate($end))) {
            return $this->failure('Promotion dates must use YYYY-MM-DD format.');
        }
        if ($start !== null && $end !== null && $start > $end) {
            return $this->failure('Promotion end date must be on or after its start date.');
        }
        if (array_key_exists('is_active', $input)) {
            $active = filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($active === null) {
                return $this->failure('Invalid promotion status.');
            }
        }

        $promotion->code = $code;
        $promotion->discount_type = $type;
        $promotion->discount_value = (float) $value;
        $promotion->start_date = $start;
        $promotion->end_date = $end;
        $promotion->is_active = $active;

        return $promotion->save() ? $this->success($promotion) : $this->failure('Unable to update promotion.');
    }

    public function deactivatePromotion(int $promotionId): array {
        $promotion = Promotion::findById($promotionId);
        if ($promotion === null) {
            return $this->failure('Promotion not found.');
        }

        $promotion->is_active = false;
        return $promotion->save() ? $this->success($promotion) : $this->failure('Unable to deactivate promotion.');
    }

    private function isValidDate(mixed $value): bool {
        if (!is_string($value)) {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private function validateField(string $field, mixed $value): ?string {
        return match ($field) {
            'business_lat'          => (is_numeric($value) && $value >= -90 && $value <= 90) ? null : 'Invalid latitude.',
            'business_long'         => (is_numeric($value) && $value >= -180 && $value <= 180) ? null : 'Invalid longitude.',
            'delivery_radius_km', 'collection_radius_km', 'delivery_fee' =>
                (is_numeric($value) && $value >= 0) ? null : "Invalid value for {$field}.",
            'slot_duration_minutes' => (is_numeric($value) && $value >= 5 && $value <= 240) ? null : 'Slot duration must be between 5 and 240 minutes.',
            'max_orders_per_slot'   => (is_numeric($value) && $value >= 1) ? null : 'Max orders per slot must be at least 1.',
            'trading_hours_start', 'trading_hours_end' =>
                (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $value) === 1) ? null : "Invalid time format for {$field}.",
            'delivery_enabled', 'collection_enabled' => null, // any truthy/falsy value is fine, cast below
            default => 'Unknown field.',
        };
    }

    private function castField(string $field, mixed $value): mixed {
        return match ($field) {
            'delivery_enabled', 'collection_enabled' => (bool) $value,
            'slot_duration_minutes', 'max_orders_per_slot' => (int) $value,
            'trading_hours_start', 'trading_hours_end' => (string) $value,
            default => (float) $value,
        };
    }

    private function success(mixed $data, int $code = 200): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}