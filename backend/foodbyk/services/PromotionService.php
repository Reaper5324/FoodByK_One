<?php

class PromotionService {

    public function listActive(): array {
        $promotions = array_values(array_filter(Promotion::findAll(), fn(Promotion $promotion) => $promotion->isActiveAt()));
        Promotion::attachTargets($promotions);
        $promotions = array_values(array_filter($promotions, static fn(Promotion $promotion) =>
            $promotion->discount_type !== Promotion::TYPE_BUY_ONE_GET_ONE
            || count($promotion->target_product_ids) === 1
        ));
        return $this->success($promotions);
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

        // Persisted order-item quantities include any BOGO free unit. An
        // adjusted order only earns BOGO when at least two units of its one
        // target product will be fulfilled.
        if ($promotion->discount_type === Promotion::TYPE_BUY_ONE_GET_ONE) {
            $targetId = (int) $promotion->target_product_ids[0];
            $fulfilledQuantity = array_sum(array_map(
                static fn(array $item): int => $item['product_id'] === $targetId ? $item['quantity'] : 0,
                $lineItems
            ));
            if ($fulfilledQuantity < 2) $lineItems = [];
        }

        $discount = $promotion->calculateDiscount($order->subtotal, $lineItems, $order->delivery_fee);

        return $this->success([
            'discount' => $discount,
        ]);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
