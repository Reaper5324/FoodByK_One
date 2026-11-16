<?php

class AdminSettingsService {

    // Only these settings may be changed through this endpoint.
    private const ALLOWED_FIELDS = [
        'business_lat', 'business_long', 'delivery_radius_km', 'collection_radius_km',
        'delivery_fee', 'free_delivery_threshold', 'trading_hours_start', 'trading_hours_end',
        'delivery_enabled', 'collection_enabled', 'slot_duration_minutes', 'max_orders_per_slot',
        'max_item_quantity', 'max_cart_items', 'minimum_order_amount', 'ordering_enabled',
        'business_phone', 'business_address',
    ];

    public function updateSettings(array $input): array {
        $settings = BusinessSettings::current();
        $schedule = null;

        if (array_key_exists('trading_hours', $input)) {
            $scheduleResult = $this->normalizeTradingHours($input['trading_hours']);
            if (!$scheduleResult['success']) return $this->failure($scheduleResult['error']);
            $schedule = $scheduleResult['data'];
        }

        foreach (self::ALLOWED_FIELDS as $field) {
            if (!array_key_exists($field, $input)) continue;

            $value = $input[$field];
            $error = $this->validateField($field, $value);
            if ($error !== null) {
                return $this->failure($error);
            }

            $settings->$field = $this->castField($field, $value);
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            if (!$settings->save()) throw new RuntimeException('Unable to save business settings.');
            if ($schedule !== null) {
                $db->exec('DELETE FROM business_trading_hours');
                $insert = $db->prepare('INSERT INTO business_trading_hours (weekday, is_open, open_time, close_time) VALUES (?, ?, ?, ?)');
                foreach ($schedule as $day) {
                    $insert->execute([$day['weekday'], (int) $day['is_open'], $day['open_time'], $day['close_time']]);
                }
                $settings->trading_hours = $schedule;
            }
            $db->commit();
            return $this->success($settings);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('Admin settings update failed: ' . $error->getMessage());
            return $this->failure('Unable to update settings. Please try again.');
        }
    }

    public function getSettings(): array {
        $settings = BusinessSettings::current();
        $settings->trading_hours = BusinessSettings::tradingSchedule();
        return $this->success($settings);
    }

    private function normalizeTradingHours(mixed $hours): array {
        if (!is_array($hours) || count($hours) !== 7) {
            return $this->failure('Set trading hours for all seven days.');
        }
        $normalized = [];
        foreach ($hours as $day) {
            if (!is_array($day)) return $this->failure('Trading hours are invalid.');
            $weekday = filter_var($day['weekday'] ?? null, FILTER_VALIDATE_INT);
            $isOpen = filter_var($day['is_open'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            $open = $day['open_time'] ?? null;
            $close = $day['close_time'] ?? null;
            if ($weekday === false || $weekday === null || $weekday < 1 || $weekday > 7 || $isOpen === null
                || !is_string($open) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $open)
                || !is_string($close) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $close)
                || ($isOpen && $close <= $open) || isset($normalized[$weekday])) {
                return $this->failure('Each open day needs valid opening and closing times, and days cannot repeat.');
            }
            $normalized[$weekday] = [
                'weekday' => $weekday,
                'is_open' => $isOpen,
                'open_time' => $open . ':00',
                'close_time' => $close . ':00',
            ];
        }
        ksort($normalized);
        if (array_keys($normalized) !== range(1, 7)) return $this->failure('Set trading hours for every day of the week.');
        return ['success' => true, 'data' => array_values($normalized), 'error' => null];
    }

    public function listPromotions(): array {
        $promotions = Promotion::findAll();
        usort($promotions, fn(Promotion $a, Promotion $b) => $b->id <=> $a->id);
        return $this->success(Promotion::attachTargets($promotions));
    }

    public function addPromotion(array $input): array {
        $code = trim((string) ($input['code'] ?? ''));
        $type = (string) ($input['discount_type'] ?? '');
        $value = $input['discount_value'] ?? null;
        $start = $input['start_date'] ?? null;
        $end   = $input['end_date'] ?? null;
        $active = array_key_exists('is_active', $input)
            ? filter_var($input['is_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : true;
        $targetIds = $this->normalizePromotionTargets($input['target_product_ids'] ?? []);

        if ($code === '' || strlen($code) > 40) {
            return $this->failure('Promotion code must be between 1 and 40 characters.');
        }
        if ($active === null) return $this->failure('Invalid promotion status.');
        if ($targetIds === null) return $this->failure('Choose valid active menu items for this promotion.');
        if (!in_array($type, [Promotion::TYPE_PERCENTAGE, Promotion::TYPE_FIXED_AMOUNT, Promotion::TYPE_BUY_ONE_GET_ONE, Promotion::TYPE_FREE_DELIVERY], true)) {
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
        if (Promotion::findByCode($code) !== null) {
            return $this->failure('A promotion with this code already exists.');
        }

        $promo = new Promotion(code: $code, discount_type: $type, discount_value: (float) $value, start_date: $start, end_date: $end, is_active: $active);
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            if (!$promo->save() || !$this->replacePromotionTargets($promo->id, $targetIds)) {
                throw new RuntimeException('Unable to create promotion.');
            }
            $db->commit();
            Promotion::attachTargets([$promo]);
            return $this->success($promo, 201);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            return $this->failure(str_contains($error->getMessage(), 'Duplicate')
                ? 'A promotion with this code already exists.'
                : 'Unable to create promotion.');
        }
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
        $targetIds = array_key_exists('target_product_ids', $input)
            ? $this->normalizePromotionTargets($input['target_product_ids'])
            : $promotion->getTargetProductIds();

        if ($code === '' || strlen($code) > 40) {
            return $this->failure('Promotion code must be between 1 and 40 characters.');
        }
        if ($targetIds === null) return $this->failure('Choose valid active menu items for this promotion.');
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

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            if (!$promotion->save() || !$this->replacePromotionTargets($promotion->id, $targetIds)) {
                throw new RuntimeException('Unable to update promotion.');
            }
            $db->commit();
            Promotion::attachTargets([$promotion]);
            return $this->success($promotion);
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            return $this->failure(str_contains($error->getMessage(), 'Duplicate')
                ? 'A promotion with this code already exists.'
                : 'Unable to update promotion.');
        }
    }

    private function normalizePromotionTargets(mixed $targetIds): ?array {
        if (!is_array($targetIds) || count($targetIds) > 100) return null;
        $ids = [];
        foreach ($targetIds as $targetId) {
            $id = filter_var($targetId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id === false) return null;
            $ids[(int) $id] = (int) $id;
        }
        if ($ids === []) return [];
        $products = Product::findByIds(array_values($ids));
        foreach ($ids as $id) {
            $product = $products[$id] ?? null;
            if (!$product || $product->status !== Product::STATUS_ACTIVE) return null;
        }
        return array_values($ids);
    }

    private function replacePromotionTargets(int $promotionId, array $productIds): bool {
        $db = Database::getConnection();
        $delete = $db->prepare('DELETE FROM promotion_products WHERE promotion_id = ?');
        if (!$delete->execute([$promotionId])) return false;
        if ($productIds === []) return true;
        $insert = $db->prepare('INSERT INTO promotion_products (promotion_id, product_id) VALUES (?, ?)');
        foreach ($productIds as $productId) {
            if (!$insert->execute([$promotionId, $productId])) return false;
        }
        return true;
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
            'delivery_radius_km', 'collection_radius_km', 'delivery_fee', 'free_delivery_threshold' =>
                (is_numeric($value) && $value >= 0) ? null : "Invalid value for {$field}.",
            'slot_duration_minutes' => (filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= 5 && $value <= 240) ? null : 'Slot duration must be a whole number between 5 and 240 minutes.',
            'max_orders_per_slot'   => (filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= 1) ? null : 'Max orders per slot must be a whole number of at least 1.',
            'max_item_quantity'     => (filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= 1 && $value <= 99) ? null : 'Maximum quantity per menu item must be between 1 and 99.',
            'max_cart_items'        => (filter_var($value, FILTER_VALIDATE_INT) !== false && $value >= 1 && $value <= 500) ? null : 'Maximum cart quantity must be between 1 and 500.',
            'minimum_order_amount'  => (is_numeric($value) && is_finite((float) $value) && $value >= 0 && $value <= 100000) ? null : 'Minimum order amount must be between R0 and R100,000.',
            'ordering_enabled'      => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null ? null : 'Invalid order acceptance setting.',
            'business_phone'        => (is_string($value) && strlen(trim($value)) >= 7 && strlen($value) <= 30 && preg_match('/^[0-9+(). -]+$/', $value)) ? null : 'Enter a valid business phone number.',
            'business_address'      => (is_string($value) && trim($value) !== '' && strlen($value) <= 255) ? null : 'Business address must be between 1 and 255 characters.',
            'trading_hours_start', 'trading_hours_end' =>
                (preg_match('/^\d{2}:\d{2}(:\d{2})?$/', (string) $value) === 1) ? null : "Invalid time format for {$field}.",
            'delivery_enabled', 'collection_enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== null
                ? null : 'Invalid boolean value.',
            default => 'Unknown field.',
        };
    }

    private function castField(string $field, mixed $value): mixed {
        return match ($field) {
            'delivery_enabled', 'collection_enabled', 'ordering_enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'slot_duration_minutes', 'max_orders_per_slot', 'max_item_quantity', 'max_cart_items' => (int) $value,
            'trading_hours_start', 'trading_hours_end', 'business_phone', 'business_address' => trim((string) $value),
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
