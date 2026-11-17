<?php

/** Canonical quote fingerprint shared by checkout preview and submission. */
final class CheckoutQuote {

    public static function fingerprint(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId,
        array $items,
        array $freeQuantitiesByCartItem,
        float $subtotal,
        float $deliveryFee,
        ?int $promotionId,
        float $discount,
        float $pointsRedeemed,
        float $loyaltyDiscount,
        float $total
    ): string {
        usort($items, static fn(array $left, array $right): int => $left['cart_item_id'] <=> $right['cart_item_id']);
        $canonicalItems = array_map(static function (array $item) use ($freeQuantitiesByCartItem): array {
            $customizations = array_map(static fn(array $option): array => [
                'id' => (int) $option['id'],
                'name' => (string) $option['name'],
                'price_delta' => round((float) $option['price_delta'], 2),
            ], $item['customizations'] ?? []);
            usort($customizations, static fn(array $left, array $right): int => $left['id'] <=> $right['id']);

            return [
                'cart_item_id' => (int) $item['cart_item_id'],
                'product_id' => (int) $item['product_id'],
                'product_name' => (string) $item['product_name'],
                'quantity' => (int) $item['quantity'],
                'free_quantity' => (int) ($freeQuantitiesByCartItem[$item['cart_item_id']] ?? 0),
                'unit_price' => round((float) $item['unit_price'], 2),
                'customizations' => $customizations,
            ];
        }, $items);

        $quote = [
            'customer_id' => $customerId,
            'fulfilment_type' => $fulfilmentType,
            'address_id' => $addressId,
            'items' => $canonicalItems,
            'subtotal' => round($subtotal, 2),
            'delivery_fee' => round($deliveryFee, 2),
            'promotion_id' => $promotionId,
            'discount' => round($discount, 2),
            'points_redeemed' => round($pointsRedeemed, 2),
            'loyalty_discount' => round($loyaltyDiscount, 2),
            'total' => round($total, 2),
        ];

        return hash('sha256', json_encode($quote, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
