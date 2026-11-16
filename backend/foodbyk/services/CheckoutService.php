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
        $minimumOrderAmount = $cartValidation['data']['minimum_order_amount'];
        $minimumOrderMet = $cartValidation['data']['minimum_order_met'];
        $minimumOrderEnforced = $cartValidation['data']['enforce_minimum_order'];

        // Use current prices for the preview; submission saves a price snapshot.
        $subtotal = 0.0;
        $lineItems = [];
        foreach ($cartItems as $cartItem) {
            $product = $productsById[$cartItem->product_id];
            $lineTotal = $cartItem->quantity * ($product->price + $cartItem->getCustomizationTotal());
            $subtotal += $lineTotal;
            $lineItems[] = [
                'cart_item_id' => $cartItem->id,
                'product_id' => $cartItem->product_id,
                'product_name' => $product->name,
                'quantity' => $cartItem->quantity,
                'unit_price' => $product->price + $cartItem->getCustomizationTotal(),
                'line_total' => $lineTotal,
                'customizations' => $cartItem->customizations,
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
        $freeDeliveryApplied = false;
        $distanceKm = 0.0;
        if ($fulfilmentType === Order::TYPE_DELIVERY) {
            $deliveryFee = $eligibility['data']['fee'];
            $distanceKm = $eligibility['data']['distance_km'];
            $settings = BusinessSettings::current();
            if ($settings->free_delivery_threshold > 0 && $subtotal >= $settings->free_delivery_threshold) {
                $deliveryFee = 0.0;
                $freeDeliveryApplied = true;
            }
        }

        $discount = 0.0;
        $promotionId = null;
        $promotionDetails = null;
        $freeQuantitiesByCartItem = [];
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
            $promotionDetails = [
                'code' => $promoResult['data']['promotion_code'],
                'discount_type' => $promoResult['data']['discount_type'],
                'discount_value' => $promoResult['data']['discount_value'],
                'target_product_ids' => $promoResult['data']['target_product_ids'],
                'target_product_names' => $promoResult['data']['target_product_names'],
                'eligible_product_names' => $promoResult['data']['eligible_product_names'],
                'applied_product_names' => $promoResult['data']['applied_product_names'],
            ];
            $freeQuantitiesByCartItem = $promoResult['data']['free_quantities_by_cart_item'] ?? [];
        }

        $fulfillmentSubtotal = $subtotal;
        foreach ($lineItems as $item) {
            $freeQuantity = (int) ($freeQuantitiesByCartItem[$item['cart_item_id']] ?? 0);
            $fulfillmentSubtotal += $freeQuantity * $item['unit_price'];
        }
        $fulfillmentSubtotal = round($fulfillmentSubtotal, 2);

        $redemption = (new LoyaltyService())->previewRedemption(
            $customerId,
            $loyaltyPointsToRedeem,
            max(0.0, $fulfillmentSubtotal + $deliveryFee - $discount)
        );
        if (!$redemption['success']) return $redemption;

        $loyaltyDiscount = $redemption['data']['discount'];
        $total = round(max(0.0, $fulfillmentSubtotal + $deliveryFee - $discount - $loyaltyDiscount), 2);

        return $this->success([
            'items_count' => count($cartItems),
            'items' => array_map(static fn(array $item): array => [
                'product_id' => $item['product_id'],
                'name' => $item['product_name'],
                'quantity' => $item['quantity'] + (int) ($freeQuantitiesByCartItem[$item['cart_item_id']] ?? 0),
                'paid_quantity' => $item['quantity'],
                'free_quantity' => (int) ($freeQuantitiesByCartItem[$item['cart_item_id']] ?? 0),
                'unit_price' => round($item['unit_price'], 2),
                'line_total' => round($item['line_total'] + ($freeQuantitiesByCartItem[$item['cart_item_id']] ?? 0) * $item['unit_price'], 2),
                'customizations' => $item['customizations'],
            ], $lineItems),
            'subtotal' => $fulfillmentSubtotal,
            'discount' => $discount,
            'discount_reason' => $promotionDetails
                ? match ($promotionDetails['discount_type']) {
                    Promotion::TYPE_FREE_DELIVERY => 'Free delivery (' . $promotionDetails['code'] . ')',
                    Promotion::TYPE_BUY_ONE_GET_ONE => 'Buy one, get one free (' . $promotionDetails['code'] . ')',
                    default => 'Promo ' . $promotionDetails['code'] . (
                        $promotionDetails['applied_product_names']
                            ? ' on ' . implode(', ', $promotionDetails['applied_product_names'])
                            : ' on eligible items'
                    ),
                }
                : null,
            'promotion' => $promotionDetails,
            'free_delivery_applied' => $freeDeliveryApplied,
            'loyalty_points_redeemed' => $redemption['data']['points_to_redeem'],
            'loyalty_discount' => $loyaltyDiscount,
            'loyalty_points_balance' => $redemption['data']['available_points'],
            'delivery_fee' => $deliveryFee,
            'distance_km' => $distanceKm,
            'fulfillment_type' => $fulfilmentType,
            'total' => $total,
            'minimum_order_amount' => $minimumOrderAmount,
            'minimum_order_subtotal' => $cartValidation['data']['minimum_order_subtotal'],
            'minimum_order_met' => $minimumOrderMet,
            'minimum_order_enforced' => $minimumOrderEnforced,
            'can_proceed' => !$minimumOrderEnforced || $minimumOrderMet,
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
        $settings = BusinessSettings::current();
        $cartQuantity = array_sum(array_map(fn(CartItem $item) => $item->quantity, $cartItems));
        if ($cartQuantity > $settings->max_cart_items) {
            return $this->failure('Your cart can contain up to ' . $settings->max_cart_items . ' items, including quantities. Reduce your cart to continue.');
        }
        $quantitiesByProduct = [];
        foreach ($cartItems as $item) {
            $quantitiesByProduct[$item->product_id] = ($quantitiesByProduct[$item->product_id] ?? 0) + $item->quantity;
        }
        foreach ($quantitiesByProduct as $quantity) {
            if ($quantity > $settings->max_item_quantity) {
                return $this->failure('A menu item exceeds the limit of ' . $settings->max_item_quantity . ' across its customizations. Reduce its quantity to continue.');
            }
        }
        foreach ($cartItems as $item) {
            if ($item->quantity > $settings->max_item_quantity) {
                return $this->failure('A menu item quantity exceeds the limit of ' . $settings->max_item_quantity . '. Reduce the quantity in your cart to continue.');
            }
            $product = $productsById[$item->product_id] ?? null;
            if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                return $this->failure(
                    'One or more items in your cart are no longer available. Please review your cart.'
                );
            }

        }

        // Keep the advisory amount based on the paid cart subtotal before
        // promotions, so the customer sees the same basis as the admin setting.
        $subtotal = 0.0;
        foreach ($cartItems as $item) {
            $product = $productsById[$item->product_id];
            $subtotal += ($product->price + $item->getCustomizationTotal()) * $item->quantity;
        }
        $subtotal = round($subtotal, 2);

        return $this->success([
            'items' => $cartItems,
            'products' => $productsById,
            'minimum_order_amount' => $settings->minimum_order_amount,
            'minimum_order_subtotal' => $subtotal,
            'minimum_order_met' => $subtotal >= $settings->minimum_order_amount,
            'enforce_minimum_order' => $settings->enforce_minimum_order,
        ]);
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
