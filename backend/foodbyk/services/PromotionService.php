<?php

class PromotionService {

    public function listActive(): array {
        $promotions = array_values(array_filter(Promotion::findAll(), fn(Promotion $promotion) => $promotion->isActiveAt()));
        Promotion::attachTargets($promotions);
        $promotions = array_values(array_filter($promotions, static fn(Promotion $promotion) =>
            $promotion->discount_type !== Promotion::TYPE_BUY_ONE_GET_ONE
            || count($promotion->target_product_ids) === 1
        ));
        // Older data may contain overlapping offers created before the admin
        // guard existed. Hide every conflicting offer rather than showing
        // multiple prices/labels for one item or arbitrarily choosing a winner.
        $conflicted = [];
        for ($i = 0; $i < count($promotions); $i++) {
            for ($j = $i + 1; $j < count($promotions); $j++) {
                if ($this->targetsOverlap($promotions[$i], $promotions[$j])) {
                    $conflicted[$promotions[$i]->id] = true;
                    $conflicted[$promotions[$j]->id] = true;
                }
            }
        }
        return $this->success(array_values(array_filter($promotions, static fn(Promotion $promotion) => !isset($conflicted[$promotion->id]))));
    }

    // Pass the calculated discount to the order so its submitted price is retained.
    public function validateAndCalculate(string $code, float $subtotal, array $lineItems = [], float $deliveryFee = 0.0): array {
        $promotion = Promotion::findByCode($code);

        if ($promotion === null) {
            return $this->failure('Invalid promotion code.');
        }
        if (!$promotion->isActiveAt()) {
            return $this->failure('This promotion is not currently active.');
        }

        Promotion::attachTargets([$promotion]);
        if ($promotion->discount_type === Promotion::TYPE_BUY_ONE_GET_ONE
            && count($promotion->target_product_ids) !== 1) {
            return $this->failure('This buy-one-get-one offer is not set up for one menu item. Please contact the business before applying it.');
        }
        if ($this->hasActiveTargetConflict($promotion)) {
            return $this->failure('This promotion overlaps another active offer on a menu item. Please contact the business before applying it.');
        }

        $discount = $promotion->calculateDiscount($subtotal, $lineItems, $deliveryFee);

        return $this->success([
            'promotion_id' => $promotion->id,
            'discount'     => $discount,
            'promotion_code' => $promotion->code,
            'discount_type' => $promotion->discount_type,
            'discount_value' => $promotion->discount_value,
            'target_product_ids' => $promotion->target_product_ids,
            'target_product_names' => $promotion->target_product_names,
            'eligible_product_names' => $promotion->eligibleProductNames($lineItems),
            'applied_product_names' => $promotion->appliedProductNames($lineItems),
            'free_quantities_by_cart_item' => $promotion->freeQuantitiesByCartItem($lineItems),
        ]);
    }

    // Recalculate against the order's current items when staff adjust it.
    public function revalidateForOrder(Order $order): array {
        if ($order->promotion_id === null) {
            return $this->success(['discount' => 0.0]);
        }

        $promotion = Promotion::findById($order->promotion_id);
        if ($promotion === null || !$promotion->isActiveAt()) {
            // An expired promotion does not prevent staff from confirming the order.
            return $this->success(['discount' => 0.0, 'note' => 'Promotion expired before confirmation.']);
        }
        Promotion::attachTargets([$promotion]);
        if ($promotion->discount_type === Promotion::TYPE_BUY_ONE_GET_ONE
            && count($promotion->target_product_ids) !== 1) {
            return $this->failure('This buy-one-get-one offer must target exactly one menu item before the order can be adjusted.');
        }

        $lineItems = array_map(fn(OrderItem $item) => [
            'product_id' => $item->product_id,
            'quantity'   => $item->quantity,
            'unit_price' => $item->unit_price,
        ], $order->getItems());

        // Checkout snapshots contain paid and free units together. On staff
        // adjustment, each complete paid/free pair remains a qualifying pair;
        // an unmatched odd unit is charged at full price.
        if ($promotion->discount_type === Promotion::TYPE_BUY_ONE_GET_ONE) {
            $targetId = (int) $promotion->target_product_ids[0];
            $lineItems = array_map(static function (array $item) use ($targetId): array {
                if ($item['product_id'] === $targetId) $item['quantity'] = intdiv(max(0, $item['quantity']), 2);
                else $item['quantity'] = 0;
                return $item;
            }, $lineItems);
        }

        $discount = $promotion->calculateDiscount($order->subtotal, $lineItems, $order->delivery_fee);

        return $this->success([
            'discount' => $discount,
        ]);
    }

    private function hasActiveTargetConflict(Promotion $promotion): bool {
        $others = array_values(array_filter(Promotion::findAll(), static fn(Promotion $other) =>
            $other->id !== $promotion->id && $other->isActiveAt()
        ));
        if ($others === []) return false;
        Promotion::attachTargets($others);
        foreach ($others as $other) {
            if ($this->targetsOverlap($promotion, $other)) return true;
        }
        return false;
    }

    private function targetsOverlap(Promotion $left, Promotion $right): bool {
        if ($left->target_product_ids === [] || $right->target_product_ids === []) return true;
        return array_intersect($left->target_product_ids, $right->target_product_ids) !== [];
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
