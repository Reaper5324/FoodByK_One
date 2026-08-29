<?php

class PromotionService {

    // Called once at order submission (FR-18). lineItems is required now -
    // BOGO can't be calculated from a subtotal alone. The returned discount
    // is meant to be written onto Order.locked_discount and never
    // recalculated later from Promotion directly.
    public function validateAndCalculate(string $code, float $subtotal, array $lineItems = [], float $deliveryFee = 0.0): array {
        $promotion = Promotion::findByCode($code);

        if ($promotion === null) {
            return $this->failure('Invalid promotion code.');
        }
        if (!$promotion->isActiveAt()) {
            return $this->failure('This promotion is not currently active.');
        }

        $discount = $promotion->calculateDiscount($subtotal, $lineItems, $deliveryFee);

        return $this->success([
            'promotion_id' => $promotion->id,
            'discount'     => $discount,
        ]);
    }

    // Re-check at staff confirmation time in case the promo expired
    // between submission and review (order was adjusted). Rebuilds
    // lineItems from the order's actual items since BOGO needs them.
    public function revalidateForOrder(Order $order): array {
        if ($order->promotion_id === null) {
            return $this->success(['discount' => 0.0]);
        }

        $promotion = Promotion::findById($order->promotion_id);
        if ($promotion === null || !$promotion->isActiveAt()) {
            // Promo lapsed - order proceeds at full price rather than blocking confirmation.
            return $this->success(['discount' => 0.0, 'note' => 'Promotion expired before confirmation.']);
        }

        $lineItems = array_map(fn(OrderItem $item) => [
            'product_id' => $item->product_id,
            'quantity'   => $item->quantity,
            'unit_price' => $item->unit_price,
        ], $order->getItems());

        $discount = $promotion->calculateDiscount($order->subtotal, $lineItems, $order->delivery_fee);

        return $this->success(['discount' => $discount]);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}