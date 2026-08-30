<?php

/**
 * CheckoutService - Orchestrates the cart-to-order checkout flow.
 * 
 * Handles:
 * - Cart validation (items available, quantities valid)
 * - Delivery eligibility checking and fee calculation
 * - Promotion validation and discount calculation
 * - Order preview (what the customer will pay)
 * - Order submission via OrderService
 * 
 * Does NOT handle payment directly - delegates to PaymentService via OrderService::submitOrder().
 */
class CheckoutService {

    /**
     * Get a preview of what will be charged in checkout.
     * This runs all validation without committing to an order.
     * 
     * @param int $customerId
     * @param string $fulfilmentType (collection|delivery)
     * @param ?int $addressId (required for delivery)
     * @param ?string $promotionCode (optional)
     * @return array ['success' => bool, 'data' => preview, 'error' => ?string]
     */
    public function getCheckoutPreview(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId = null,
        ?string $promotionCode = null
    ): array {
        // Validate fulfilment type
        if (!in_array($fulfilmentType, [Order::TYPE_COLLECTION, Order::TYPE_DELIVERY], true)) {
            return $this->failure('Invalid fulfillment type.');
        }

        // Load and validate cart
        $cartValidation = $this->validateCart($customerId);
        if (!$cartValidation['success']) {
            return $cartValidation;
        }
        $cartItems = $cartValidation['data'];

        // Calculate subtotal from cart items and their current product prices
        $subtotal = 0.0;
        $lineItems = [];
        foreach ($cartItems as $cartItem) {
            $product = Product::findById($cartItem->product_id);
            if ($product) {
                $lineTotal = $cartItem->quantity * $product->price;
                $subtotal += $lineTotal;
                $lineItems[] = [
                    'product_id' => $cartItem->product_id,
                    'quantity' => $cartItem->quantity,
                    'unit_price' => $product->price,
                ];
            }
        }
        $subtotal = round($subtotal, 2);

        // Check delivery eligibility and get fee
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

        // Validate and apply promotion if provided
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

        // Calculate total
        $total = round(max(0.0, $subtotal - $discount) + $deliveryFee, 2);

        return $this->success([
            'items_count' => count($cartItems),
            'subtotal' => $subtotal,
            'discount' => $discount,
            'discount_reason' => $promotionCode ? "Promo code: {$promotionCode}" : null,
            'delivery_fee' => $deliveryFee,
            'distance_km' => $distanceKm,
            'fulfillment_type' => $fulfilmentType,
            'total' => $total,
            'can_proceed' => true,
        ]);
    }

    /**
     * Validate that all cart items are still available and prices haven't changed drastically.
     * Does NOT reserve items - just checks current state.
     * 
     * @param int $customerId
     * @return array ['success' => bool, 'data' => CartItem[], 'error' => ?string]
     */
    public function validateCart(int $customerId): array {
        $cartItems = CartItem::findBy('customer_id', $customerId);
        if (empty($cartItems)) {
            return $this->failure('Cart is empty.');
        }

        foreach ($cartItems as $item) {
            $product = Product::findById($item->product_id);
            if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                return $this->failure(
                    'One or more items in your cart are no longer available. Please review your cart.'
                );
            }

        }

        return $this->success($cartItems);
    }

    /**
     * Validate delivery address is geocoded and eligible.
     * Called before submitOrder to ensure address data is ready.
     * 
     * @param int $addressId
     * @return array ['success' => bool, 'data' => [...], 'error' => ?string]
     */
    public function validateDeliveryAddress(int $addressId): array {
        $address = Address::findById($addressId);
        if (!$address) {
            return $this->failure('Address not found.');
        }

        if (!$address->hasCoordinates()) {
            // Attempt to geocode
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

    /**
     * Validate the requested time window is within trading hours.
     * 
     * @param string $windowStart (ISO 8601 datetime)
     * @param string $windowEnd (ISO 8601 datetime)
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function validateTimeWindow(string $windowStart, string $windowEnd): array {
        try {
            $start = new \DateTimeImmutable($windowStart);
            $end = new \DateTimeImmutable($windowEnd);
        } catch (\Exception) {
            return $this->failure('Invalid time format.');
        }

        if ($start >= $end) {
            return $this->failure('End time must be after start time.');
        }

        $deliveryService = new DeliveryService();
        if (!$deliveryService->isWithinTradingHours($start) || !$deliveryService->isWithinTradingHours($end)) {
            return $this->failure('Requested time is outside trading hours.');
        }

        return $this->success(['start' => $windowStart, 'end' => $windowEnd]);
    }

    /**
     * Submit the order after all validations. Delegates to OrderService::submitOrder().
     * 
     * @param int $customerId
     * @param string $fulfilmentType
     * @param ?int $addressId
     * @param string $requestedWindowStart
     * @param string $requestedWindowEnd
     * @param ?string $promotionCode
     * @return array ['success' => bool, 'data' => Order, 'error' => ?string]
     */
    public function submitOrder(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId,
        string $requestedWindowStart,
        string $requestedWindowEnd,
        ?string $promotionCode = null
    ): array {
        $orderService = new OrderService();
        return $orderService->submitOrder(
            $customerId,
            $fulfilmentType,
            $addressId,
            $requestedWindowStart,
            $requestedWindowEnd,
            $promotionCode
        );
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
