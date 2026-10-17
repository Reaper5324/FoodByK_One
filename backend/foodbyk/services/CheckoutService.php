<?php

/** Validates checkout details and passes order creation to OrderService. */
class CheckoutService {

    /** Calculates the current checkout total without creating an order. */
    public function getCheckoutPreview(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId = null,
        ?string $promotionCode = null,
        float $loyaltyPointsToRedeem = 0.0
    ): array {
        // Check that the customer chose collection or delivery.
        if (!in_array($fulfilmentType, [Order::TYPE_COLLECTION, Order::TYPE_DELIVERY], true)) {
            return $this->failure('Invalid fulfillment type.');
        }

        // Load the cart and check that its items are still available.
        $cartValidation = $this->validateCart($customerId);
        if (!$cartValidation['success']) {
            return $cartValidation;
        }
        $cartItems = $cartValidation['data']['items'];
        $productsById = $cartValidation['data']['products'];

        // Use current prices for the preview; submission saves a price snapshot.
        $subtotal = 0.0;
        $lineItems = [];
        foreach ($cartItems as $cartItem) {
            $product = $productsById[$cartItem->product_id];
            $lineTotal = $cartItem->quantity * $product->price;
            $subtotal += $lineTotal;
            $lineItems[] = [
                'product_id' => $cartItem->product_id,
                'quantity' => $cartItem->quantity,
                'unit_price' => $product->price,
            ];
        }
        $subtotal = round($subtotal, 2);

        $deliveryService = new DeliveryService();
        $address = $addressId ? Address::findById($addressId) : null;
        if ($fulfilmentType === Order::TYPE_DELIVERY && (!$address || $address->customer_id !== $customerId)) {
            return $this->failure('Delivery address not found.');
        }
        $eligibility = $deliveryService->checkEligibility($fulfilmentType, $address);
        if (!$eligibility['success']) {
            return $this->failure($eligibility['error']);
        }

        $deliveryFee = 0.0;
        $distanceKm = 0.0;
        if ($fulfilmentType === Order::TYPE_DELIVERY) {
            $deliveryFee = $eligibility['data']['fee'];
            $distanceKm = $eligibility['data']['distance_km'];
        }

        $discount = 0.0;
        $promotionId = null;
        if ($promotionCode) {
            $promotionService = new PromotionService();
            $promoResult = $promotionService->validateAndCalculate(
                $promotionCode,
                $subtotal,
                $lineItems,
                $deliveryFee
            );
            if (!$promoResult['success']) {
                return $this->failure('Invalid promotion code: ' . $promoResult['error']);
            }
            $discount = $promoResult['data']['discount'];
            $promotionId = $promoResult['data']['promotion_id'];
        }

        $redemption = (new LoyaltyService())->previewRedemption(
            $customerId,
            $loyaltyPointsToRedeem,
            max(0.0, $subtotal + $deliveryFee - $discount)
        );
        if (!$redemption['success']) return $redemption;

        $loyaltyDiscount = $redemption['data']['discount'];
        $total = round(max(0.0, $subtotal + $deliveryFee - $discount - $loyaltyDiscount), 2);

        return $this->success([
            'items_count' => count($cartItems),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discount_reason' => $promotionCode ? "Promo code: {$promotionCode}" : null,
            'loyalty_points_redeemed' => $redemption['data']['points_to_redeem'],
            'loyalty_discount' => $loyaltyDiscount,
            'loyalty_points_balance' => $redemption['data']['available_points'],
            'delivery_fee' => $deliveryFee,
            'distance_km' => $distanceKm,
            'fulfillment_type' => $fulfilmentType,
            'total' => $total,
            'can_proceed' => true,
        ]);
    }

    /** Returns the cart only when every product is active and available. */
    public function validateCart(int $customerId): array {
        $cartItems = CartItem::findBy('customer_id', $customerId);
        if (empty($cartItems)) {
            return $this->failure('Cart is empty.');
        }

        $productsById = Product::findByIds(array_map(
            fn(CartItem $item) => $item->product_id,
            $cartItems
        ));
        foreach ($cartItems as $item) {
            $product = $productsById[$item->product_id] ?? null;
            if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                return $this->failure(
                    'One or more items in your cart are no longer available. Please review your cart.'
                );
            }

        }

        return $this->success(['items' => $cartItems, 'products' => $productsById]);
    }

    /** Geocodes the address if needed and checks delivery eligibility. */
    public function validateDeliveryAddress(int $addressId): array {
        $address = Address::findById($addressId);
        if (!$address) {
            return $this->failure('Address not found.');
        }

        if (!$address->hasCoordinates()) {
            $deliveryService = new DeliveryService();
            if (!$deliveryService->geocodeAddress($address)) {
                return $this->failure('Unable to verify delivery address. Please check and try again.');
            }
        }

        return $this->success([
            'address_id' => $address->id,
            'street' => $address->street,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
        ]);
    }

    /** Checks the selected time against trading hours and slot rules. */
       public function validateTimeWindow(string $windowStart, string $windowEnd): array {
        return (new SlotService())->isValidSlot($windowStart, $windowEnd);
    }
    /** Passes the checkout request to OrderService. */
    public function submitOrder(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId,
        string $requestedWindowStart,
        string $requestedWindowEnd,
        ?string $promotionCode = null,
        float $loyaltyPointsToRedeem = 0.0
    ): array {
        $orderService = new OrderService();
        return $orderService->submitOrder(
            $customerId,
            $fulfilmentType,
            $addressId,
            $requestedWindowStart,
            $requestedWindowEnd,
            $promotionCode,
            $loyaltyPointsToRedeem
        );
    }

    

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
