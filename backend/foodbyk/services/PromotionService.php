<?php

class PromotionService {

    public function listActive(): array {
        $promotions = array_values(array_filter(Promotion::findAll(), fn(Promotion $promotion) => $promotion->isActiveAt()));
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

        $discount = $promotion->calculateDiscount($subtotal, $lineItems, $deliveryFee);

        return $this->success([
            'promotion_id' => $promotion->id,
            'discount'     => $discount,
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
