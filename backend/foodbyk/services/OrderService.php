<?php

class OrderService {

    private const PAYMENT_SETUP_TIMEOUT_MINUTES = 30;
    private const CUSTOMER_REFUND_PENDING = 'Customer cancellation refund pending';

    // Keep each order change and its status history in the same transaction.
    private function transactional(callable $work): array {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $result = $work($db);
            $db->commit();
            return ['success' => true, 'data' => $result, 'error' => null];
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            return ['success' => false, 'data' => null, 'error' => $e->getMessage()];
        }
    }

    /** Mark abandoned zero-charge PayFast setup attempts as cancelled. */
    public function expireAbandonedPaymentSetups(): int {
        $db = Database::getConnection();
        $stmt = $db->query(
            "SELECT o.id FROM orders o
             JOIN payments p ON p.order_id = o.id
             WHERE o.status = 'submitted' AND p.status = 'tokenized'
               AND p.gateway_token IS NULL
               AND o.created_at <= CURRENT_TIMESTAMP() - INTERVAL " . self::PAYMENT_SETUP_TIMEOUT_MINUTES . " MINUTE
             ORDER BY o.id LIMIT 100"
        );
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $expiredCount = 0;

        foreach ($ids as $orderId) {
            try {
                $db->beginTransaction();
                $order = Order::lockById($orderId);
                $payment = $order?->getPayment();
                if (!$order || $order->status !== Order::STATUS_SUBMITTED || !$payment
                    || $payment->status !== Payment::STATUS_TOKENIZED || $payment->gateway_token !== null) {
                    $db->commit();
                    continue;
                }

                $expired = $db->prepare(
                    'SELECT created_at <= CURRENT_TIMESTAMP() - INTERVAL ' . self::PAYMENT_SETUP_TIMEOUT_MINUTES . ' MINUTE
                     FROM orders WHERE id = ?'
                );
                $expired->execute([$orderId]);
                if (!(bool) $expired->fetchColumn()) {
                    $db->commit();
                    continue;
                }

                $order->status = Order::STATUS_CANCELLED;
                $order->cancel_reason = 'Card setup was not completed.';
                $loyaltyRefund = (new LoyaltyService())->refundOrderRedemption($order);
                if (!$loyaltyRefund['success']) {
                    throw new RuntimeException('Unable to return reserved loyalty points for abandoned payment setup.');
                }
                if (!$payment->voidToken() || !$order->save()) {
                    throw new RuntimeException('Unable to expire abandoned payment setup.');
                }
                $this->logTransition(
                    $order->id,
                    Order::STATUS_SUBMITTED,
                    Order::STATUS_CANCELLED,
                    null,
                    'PayFast card setup was not completed within ' . self::PAYMENT_SETUP_TIMEOUT_MINUTES . ' minutes.'
                );
                $db->commit();
                $expiredCount++;
            } catch (Throwable $error) {
                if ($db->inTransaction()) $db->rollBack();
                error_log('Could not expire abandoned card setup for order ' . $orderId . ': ' . $error->getMessage());
            }
        }

        return $expiredCount;
    }

    public function confirmOrder(int $orderId, int $staffId, ?string $confirmedStart = null, ?string $confirmedEnd = null): array {
        if (($confirmedStart === null) !== ($confirmedEnd === null)) {
            return $this->failure('Both confirmed window start and end are required.');
        }
        if ($confirmedStart !== null) {
            $slot = (new SlotService())->isValidSlot($confirmedStart, $confirmedEnd);
            if (!$slot['success']) return $this->failure($slot['error']);
        }
        $result = $this->transactional(function () use ($orderId, $staffId, $confirmedStart, $confirmedEnd) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if (!$order->canTransitionTo(Order::STATUS_ACCEPTED)) {
                throw new \Exception("Order {$orderId} cannot be confirmed from status '{$order->status}'.");
            }

            $payment = $order->getPayment();
            if (!$payment || $payment->status !== Payment::STATUS_TOKENIZED || !$payment->gateway_token) {
                // An accepted order must have a token available for charging.
                throw new \Exception("Order {$orderId} has no valid payment token.");
            }

            if ($confirmedStart !== null) {
                $slotService = new SlotService();
                $slotValidation = $slotService->isValidSlot($confirmedStart, $confirmedEnd);
                if (!$slotValidation['success']) throw new \Exception($slotValidation['error']);
                $capacity = $slotService->reserveCapacityLocked($confirmedStart, $order->id);
                if (!$capacity['success']) throw new \Exception($capacity['error']);
            }

            $fromStatus = $order->status;
            $order->staff_id = $staffId;
            $order->status   = Order::STATUS_ACCEPTED;
            if ($confirmedStart) $order->confirmed_window_start = $confirmedStart;
            if ($confirmedEnd)   $order->confirmed_window_end   = $confirmedEnd;
            if (!$order->save()) {
                throw new \Exception('Unable to confirm order.');
            }

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId);

            return $order;
        });

        if (!$result['success']) return $result;
        (new NotificationService())->notifyOrderEvent($result['data'], 'confirmed', 'customer');
        (new PushService())->notifyCustomerOfOrderStatus($result['data'], 'confirmed');
        return (new PaymentService())->chargeToken($result['data']);
    }

    public function declineOrder(int $orderId, int $staffId, mixed $reason): array {
        if (!is_string($reason) || trim($reason) === '' || strlen(trim($reason)) > 255) {
            return $this->failure('A decline reason of 1 to 255 characters is required.');
        }
        $reason = trim($reason);
        $result = $this->transactional(function () use ($orderId, $staffId, $reason) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if (!$order->canTransitionTo(Order::STATUS_DECLINED)) {
                throw new \Exception("Order {$orderId} cannot be declined from status '{$order->status}'.");
            }

            $fromStatus = $order->status;
            $order->staff_id       = $staffId;
            $order->status         = Order::STATUS_DECLINED;
            $order->decline_reason = $reason;
            $loyaltyRefund = (new LoyaltyService())->refundOrderRedemption($order);
            if (!$loyaltyRefund['success']) throw new \Exception($loyaltyRefund['error']);
            if (!$order->save()) {
                throw new \Exception('Unable to decline order.');
            }

            $order->getPayment()?->voidToken(); // token never charged - FR-08

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId, $reason);

            return $order;
        });

        if ($result['success']) {
            (new PaymentService())->releaseToken($result['data']);
            (new NotificationService())->notifyOrderEvent($result['data'], 'declined', 'customer');
        }

        return $result;
    }

    public function cancelOrder(int $orderId, ?int $customerId, ?int $staffId, mixed $reason, array $bankDetails = [], bool $notify = true): array {
        $reason = is_string($reason) ? trim($reason) : '';
        if ($reason === '' || strlen($reason) > 255) return $this->failure('A cancellation reason of 1 to 255 characters is required.');

        // A confirmed payment must be refunded through PayFast before the order
        // is marked cancelled. Mark the request first so staff cannot advance
        // the order while the external refund is being processed.
        if ($customerId !== null) {
            $existing = Order::findById($orderId);
            if (!$existing || $existing->customer_id !== $customerId) return $this->failure("Order {$orderId} not found.");
            $payment = $existing->getPayment();
            if ($payment?->status === Payment::STATUS_SUCCESS) {
                if (!in_array($existing->status, [Order::STATUS_PAID, Order::STATUS_PREPARING], true)) {
                    return $this->failure('This order can no longer be cancelled online. Please contact the Food by K team.');
                }
                $marked = $this->transactional(function () use ($orderId, $customerId) {
                    $order = Order::lockById($orderId);
                    $payment = $order?->getPayment();
                    if (!$order || $order->customer_id !== $customerId) throw new RuntimeException('Order not found.');
                    if (!in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PREPARING], true)
                        || !$payment || $payment->status !== Payment::STATUS_SUCCESS) {
                        throw new RuntimeException('This order can no longer be cancelled online.');
                    }
                    if ($order->cancel_reason === self::CUSTOMER_REFUND_PENDING) {
                        throw new RuntimeException('A cancellation refund request is already being processed.');
                    }
                    $order->cancel_reason = self::CUSTOMER_REFUND_PENDING;
                    if (!$order->save()) throw new RuntimeException('Unable to record the cancellation request.');
                    return $order;
                });
                if (!$marked['success']) return $marked;

                $refund = (new PaymentService())->refundCustomerCancellation($orderId, $customerId, $bankDetails);
                if (!$refund['success']) {
                    $this->transactional(function () use ($orderId) {
                        $order = Order::lockById($orderId);
                        if ($order && $order->cancel_reason === self::CUSTOMER_REFUND_PENDING) {
                            $order->cancel_reason = null;
                            $order->save();
                        }
                        return true;
                    });
                    return $refund;
                }

                $refundStatus = (string) ($refund['data']['status'] ?? 'pending');
                $result = $this->transactional(function () use ($orderId, $customerId, $reason) {
                    $order = Order::lockById($orderId);
                    if (!$order || $order->customer_id !== $customerId
                        || $order->cancel_reason !== self::CUSTOMER_REFUND_PENDING
                        || !in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PREPARING], true)) {
                        throw new RuntimeException('The refund request is recorded, but the order status could not be updated. Contact Food by K before retrying.');
                    }
                    $fromStatus = $order->status;
                    $order->status = Order::STATUS_CANCELLED;
                    $order->cancel_reason = $reason;
                    $loyaltyRefund = (new LoyaltyService())->refundOrderRedemption($order);
                    if (!$loyaltyRefund['success']) throw new RuntimeException($loyaltyRefund['error']);
                    if (!$order->save()) throw new RuntimeException('Unable to cancel order after recording the refund request.');
                    $this->logTransition($order->id, $fromStatus, $order->status, $customerId, 'Customer cancellation; PayFast refund ' . $refundStatus);
                    return $order;
                });

                $noticeOrder = $result['success'] ? $result['data'] : Order::findById($orderId);
                if ($noticeOrder) {
                    (new NotificationService())->notifyOrderEvent($noticeOrder, 'cancellation_requested', 'staff');
                    (new PushService())->notifyStaffOfCancellation($noticeOrder, $refundStatus);
                }
                if (!$result['success']) return $result;
                (new PaymentService())->releaseToken($result['data']);
                (new NotificationService())->notifyOrderEvent($result['data'], 'cancelled', 'customer');
                (new PushService())->notifyCustomerOfOrderStatus($result['data'], 'cancelled');
                return $this->success(['order' => $result['data'], 'refund_status' => $refundStatus]);
            }
        }

        $result = $this->transactional(function () use ($orderId, $customerId, $staffId, $reason) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if ($staffId === null && $customerId !== null && $order->customer_id !== $customerId) {
                throw new \Exception("Order {$orderId} not found.");
            }
            if (!$order->canTransitionTo(Order::STATUS_CANCELLED)) {
                throw new \Exception("Order {$orderId} can no longer be cancelled (status: '{$order->status}').");
            }

              $fromStatus = $order->status;
              $order->status        = Order::STATUS_CANCELLED;
              $order->cancel_reason = $reason;
              $loyaltyRefund = (new LoyaltyService())->refundOrderRedemption($order);
              if (!$loyaltyRefund['success']) throw new \Exception($loyaltyRefund['error']);
              if (!$order->save()) {
                throw new \Exception('Unable to cancel order.');
            }

            $payment = $order->getPayment();
            if ($payment && $payment->status === Payment::STATUS_TOKENIZED) {
                $payment->voidToken();
            }

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId, $reason);

            return $order;
        });
        if ($result['success']) {
            (new PaymentService())->releaseToken($result['data']);
            if ($notify) {
                $audience = $staffId === null ? 'staff' : 'customer';
                (new NotificationService())->notifyOrderEvent($result['data'], 'cancelled', $audience);
                if ($staffId === null) (new PushService())->notifyStaffOfCancellation($result['data'], 'not_applicable');
                else (new PushService())->notifyCustomerOfOrderStatus($result['data'], 'cancelled');
            }
        }
        return $result;
    }

    public function advanceFulfilment(int $orderId, int $staffId, string $newStatus): array {
        $result = $this->transactional(function () use ($orderId, $staffId, $newStatus) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if ($order->cancel_reason === self::CUSTOMER_REFUND_PENDING) {
                throw new \Exception('This order has a customer cancellation refund in progress and cannot be advanced.');
            }
            if (!$order->canTransitionTo($newStatus)) {
                throw new \Exception("Cannot move order {$orderId} from '{$order->status}' to '{$newStatus}'.");
            }

            $fromStatus = $order->status;
            $order->status = $newStatus;
            if (!$order->save()) {
                throw new \Exception('Unable to update order status.');
            }

            $this->logTransition($order->id, $fromStatus, $newStatus, $staffId);

            return $order;
        });
        if ($result['success']) {
            (new PushService())->notifyCustomerOfOrderStatus($result['data'], $newStatus);
        }
        return $result;
    }

    // Load customer details with the orders in one query.
    public function getPendingOrdersForStaffDashboard(): array {
        $this->expireAbandonedPaymentSetups();
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT o.*, u.name AS customer_name, u.email AS customer_email
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             JOIN payments p ON p.order_id = o.id
             WHERE o.status = '" . Order::STATUS_SUBMITTED . "'
               AND p.gateway_token IS NOT NULL AND p.gateway_token <> ''
               AND p.status = '" . Payment::STATUS_TOKENIZED . "'
             ORDER BY o.created_at ASC"
        )->fetchAll();
        return ['success' => true, 'data' => $rows, 'error' => null];
    }

    public function getOrdersForStaffDashboard(int $limit = 50, int $offset = 0, bool $todayOnly = false): array {
        $this->expireAbandonedPaymentSetups();
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $db = Database::getConnection();
        $where = '';
        $bindings = [];
        if ($todayOnly) {
            $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
            $tomorrow = $today->modify('+1 day');
            $utc = new DateTimeZone('UTC');
            $createdStart = $today->setTimezone($utc)->format('Y-m-d H:i:s');
            $createdEnd = $tomorrow->setTimezone($utc)->format('Y-m-d H:i:s');
            // Requested and confirmed windows are stored as local wall-clock DATETIME values.
            $windowStart = $today->format('Y-m-d H:i:s');
            $windowEnd = $tomorrow->format('Y-m-d H:i:s');
            $where = ' WHERE ((p.gateway_token IS NOT NULL AND p.gateway_token <> :usable_token AND p.status = :payment_status)
                       OR o.status IN (\'paid\', \'preparing\', \'ready\', \'completed\'))
                       AND o.created_at >= :created_start AND o.created_at < :created_end
                       AND COALESCE(o.confirmed_window_start, o.requested_window_start) >= :window_start
                       AND COALESCE(o.confirmed_window_start, o.requested_window_start) < :window_end';
            $bindings = [
                ':created_start' => $createdStart,
                ':created_end' => $createdEnd,
                ':window_start' => $windowStart,
                ':window_end' => $windowEnd,
            ];
            $bindings = [
                ':usable_token' => '',
                ':payment_status' => Payment::STATUS_TOKENIZED,
                ...$bindings,
            ];
        }

        if ($where === '') {
            $where = ' WHERE ((p.gateway_token IS NOT NULL AND p.gateway_token <> :usable_token AND p.status = :payment_status)
                       OR o.status IN (\'paid\', \'preparing\', \'ready\', \'completed\'))';
            $bindings = [
                ':usable_token' => '',
                ':payment_status' => Payment::STATUS_TOKENIZED,
            ];
        }
        $count = $db->prepare('SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id' . $where);
        foreach ($bindings as $name => $value) $count->bindValue($name, $value);
        $count->execute();
        $total = (int) $count->fetchColumn();

        $stmt = $db->prepare(
            "SELECT o.*, u.name AS customer_name, u.email AS customer_email,
                    p.status AS payment_status,
                    CONVERT_TZ(o.created_at, '+00:00', '+02:00') AS placed_at_local,
                    GREATEST(0, o.subtotal + o.delivery_fee - o.locked_discount
                        - o.loyalty_points_redeemed + o.loyalty_points_refunded) AS total
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             JOIN payments p ON p.order_id = o.id" . $where . "
             ORDER BY o.created_at DESC, o.id DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($bindings as $name => $value) $stmt->bindValue($name, $value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['success' => true, 'data' => [
            'orders' => $stmt->fetchAll(),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ], 'error' => null];
    }

    private function logTransition(int $orderId, ?string $from, string $to, ?int $actorId, ?string $notes = null): void {
        if (!(new OrderStatusHistory(order_id: $orderId, from_status: $from, to_status: $to, changed_by: $actorId, notes: $notes))->save()) {
            throw new \Exception('Unable to record order status history.');
        }
    }

    /** Creates the order, saves its price snapshot, and starts PayFast token setup. */
    public function submitOrder(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId,
        string $requestedWindowStart,
        string $requestedWindowEnd,
        ?string $promotionCode = null,
        float $loyaltyPointsToRedeem = 0.0,
        string $quoteFingerprint = ''
    ): array {
        $result = $this->transactional(function () use (
            $customerId, $fulfilmentType, $addressId,
            $requestedWindowStart, $requestedWindowEnd, $promotionCode, $loyaltyPointsToRedeem, $quoteFingerprint
        ) {
            // Serialize order creation against account erasure. The erasure
            // service locks this same user row, then either waits for this
            // transaction (and sees the new order) or disables the account
            // before this transaction proceeds.
            $customerLock = Database::getConnection()->prepare('SELECT is_active FROM users WHERE id = ? FOR UPDATE');
            $customerLock->execute([$customerId]);
            if ((int) $customerLock->fetchColumn() !== 1) {
                throw new \Exception('This customer account is no longer active.');
            }

            if (!in_array($fulfilmentType, [Order::TYPE_COLLECTION, Order::TYPE_DELIVERY], true)) {
                throw new \Exception('Invalid fulfilment type.');
            }

            $cartItems = CartItem::findBy('customer_id', $customerId);
            if (empty($cartItems)) {
                throw new \Exception('Cart is empty.');
            }

            $cartSettings = BusinessSettings::current();
            $cartQuantity = array_sum(array_map(fn(CartItem $item) => $item->quantity, $cartItems));
            if ($cartQuantity > $cartSettings->max_cart_items) {
                throw new \Exception('Your cart can contain up to ' . $cartSettings->max_cart_items . ' items, including quantities. Reduce your cart to continue.');
            }

            $quantitiesByProduct = [];
            foreach ($cartItems as $cartItem) {
                $quantitiesByProduct[$cartItem->product_id] = ($quantitiesByProduct[$cartItem->product_id] ?? 0) + $cartItem->quantity;
            }
            foreach ($quantitiesByProduct as $quantity) {
                if ($quantity > $cartSettings->max_item_quantity) {
                    throw new \Exception('A menu item exceeds the limit of ' . $cartSettings->max_item_quantity . ' across its customizations. Reduce its quantity to continue.');
                }
            }

            $productsById = Product::findByIds(array_map(
                fn(CartItem $item) => $item->product_id,
                $cartItems
            ), true);

            $customer = Customer::findCustomerById($customerId);
            if (!$customer) {
                throw new \Exception('Customer not found.');
            }
            $order = new Order(
                customer_id: $customerId,
                fulfilment_type: $fulfilmentType,
                address_id: $addressId,
                requested_window_start: $requestedWindowStart,
                requested_window_end: $requestedWindowEnd,
                status: Order::STATUS_SUBMITTED
            );

            $subtotal = 0.0;
            foreach ($cartItems as $cartItem) {
                if ($cartItem->quantity > $cartSettings->max_item_quantity) {
                    throw new \Exception('A menu item quantity exceeds the limit of ' . $cartSettings->max_item_quantity . '. Reduce the quantity in your cart to continue.');
                }
                $product = $productsById[$cartItem->product_id] ?? null;
                if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                    throw new \Exception("Item {$cartItem->product_id} is no longer available.");
                }
                $customizationValidation = (new ProductOptionService())->validateCartSelection($cartItem, true);
                if (!$customizationValidation['success']) throw new \Exception($customizationValidation['error']);
                $subtotal += ($product->price + $cartItem->getCustomizationTotal()) * $cartItem->quantity;
            }
            $subtotal = round($subtotal, 2);
            if ($cartSettings->enforce_minimum_order && $subtotal < $cartSettings->minimum_order_amount) {
                throw new \Exception('The minimum order is R' . number_format($cartSettings->minimum_order_amount, 2) . '. Add more items before placing your order.');
            }
            $order->subtotal = round($subtotal, 2);
            $deliveryService = new DeliveryService();
            $address = $addressId ? Address::findById($addressId) : null;
            if ($fulfilmentType === Order::TYPE_DELIVERY && (!$address || $address->customer_id !== $customerId)) {
                throw new \Exception('Delivery address not found.');
            }
            $eligibility = $deliveryService->checkEligibility($fulfilmentType, $address);
            if (!$eligibility['success']) {
                throw new \Exception($eligibility['error']);
            }

            if ($fulfilmentType === Order::TYPE_DELIVERY) {
                $order->distance_km = $eligibility['data']['distance_km'];
                $order->delivery_fee = $eligibility['data']['fee'];
                if ($cartSettings->free_delivery_threshold > 0 && $subtotal >= $cartSettings->free_delivery_threshold) {
                    $order->delivery_fee = 0.0;
                }
            }

            // Price delivery first so a free-delivery promotion uses the real fee.
            $freeQuantitiesByCartItem = [];
            if ($promotionCode) {
                $promoResult = (new PromotionService())->validateAndCalculate(
                    $promotionCode,
                    $subtotal,
                    array_map(fn(CartItem $item) => [
                        'cart_item_id' => $item->id,
                        'product_id' => $item->product_id,
                        'product_name' => $productsById[$item->product_id]->name,
                        'quantity' => $item->quantity,
                    'unit_price' => $productsById[$item->product_id]->price + $item->getCustomizationTotal(),
                    ], $cartItems),
                    $order->delivery_fee
                );
                if (!$promoResult['success']) {
                    throw new \Exception('Invalid promotion: ' . $promoResult['error']);
                }
                $order->locked_discount = $promoResult['data']['discount'];
                $order->promotion_id = $promoResult['data']['promotion_id'];
                $freeQuantitiesByCartItem = $promoResult['data']['free_quantities_by_cart_item'] ?? [];
                foreach ($cartItems as $cartItem) {
                    $freeQuantity = (int) ($freeQuantitiesByCartItem[$cartItem->id] ?? 0);
                    if ($freeQuantity > 0) {
                        $unitPrice = $productsById[$cartItem->product_id]->price + $cartItem->getCustomizationTotal();
                        $order->subtotal += $freeQuantity * $unitPrice;
                    }
                }
            }

            $loyaltyService = new LoyaltyService();
            $redemption = $loyaltyService->previewRedemption(
                $customerId,
                $loyaltyPointsToRedeem,
                max(0.0, $order->subtotal + $order->delivery_fee - $order->locked_discount)
            );
            if (!$redemption['success']) throw new \Exception($redemption['error']);
            $pointsToRedeem = $redemption['data']['points_to_redeem'];
            $loyaltyDiscount = $redemption['data']['discount'];
            $quoteItems = array_map(fn(CartItem $item) => [
                'cart_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $productsById[$item->product_id]->name,
                'quantity' => $item->quantity,
                'unit_price' => $productsById[$item->product_id]->price + $item->getCustomizationTotal(),
                'customizations' => $item->customizations,
            ], $cartItems);
            $currentQuoteFingerprint = CheckoutQuote::fingerprint(
                $customerId,
                $fulfilmentType,
                $fulfilmentType === Order::TYPE_DELIVERY ? $addressId : null,
                $quoteItems,
                $freeQuantitiesByCartItem,
                $order->subtotal,
                $order->delivery_fee,
                $order->promotion_id,
                $order->locked_discount,
                $pointsToRedeem,
                $loyaltyDiscount,
                max(0.0, $order->subtotal + $order->delivery_fee - $order->locked_discount - $loyaltyDiscount)
            );
            if (preg_match('/^[a-f0-9]{64}$/D', $quoteFingerprint) !== 1
                || !hash_equals($currentQuoteFingerprint, $quoteFingerprint)) {
                throw new \Exception('Checkout details changed after your review. Please review the updated total and continue.');
            }
            if ($pointsToRedeem > 0) {
                $redeemResult = $loyaltyService->redeemPoints($customerId, $pointsToRedeem);
                if (!$redeemResult['success']) throw new \Exception($redeemResult['error']);
                $order->loyalty_points_redeemed = $pointsToRedeem;
            }

            // Validate and reserve the slot in this transaction to avoid overbooking.
            $slotService = new SlotService();
            $slotValidation = $slotService->isValidSlot($requestedWindowStart, $requestedWindowEnd);
            if (!$slotValidation['success']) {
                throw new \Exception($slotValidation['error']);
            }
            $capacity = $slotService->reserveCapacityLocked($requestedWindowStart);
            if (!$capacity['success']) {
                throw new \Exception($capacity['error']);
            }

            if (!$order->save()) {
                throw new \Exception('Unable to create order.');
            }
            $payment = new Payment(
                order_id: $order->id,
                amount: $order->total()
            );
            if (!$payment->save()) {
                throw new \Exception('Unable to create payment record.');
            }

            foreach ($cartItems as $cartItem) {
                $product = $productsById[$cartItem->product_id];
                $orderItem = new OrderItem(
                    product_name: $product->name,
                    order_id: $order->id,
                    product_id: $cartItem->product_id,
                    quantity: $cartItem->quantity + (int) ($freeQuantitiesByCartItem[$cartItem->id] ?? 0),
                    free_quantity: (int) ($freeQuantitiesByCartItem[$cartItem->id] ?? 0),
                    unit_price: $product->price + $cartItem->getCustomizationTotal(),
                    customization_json: $cartItem->customization_json
                );
                if (!$orderItem->save()) {
                    throw new \Exception('Unable to save order items.');
                }
            }

            // The initial history entry has no previous order status.
            $this->logTransition($order->id, '', Order::STATUS_SUBMITTED, $customerId);

            return ['order' => $order, 'customer' => $customer];
        });

        if (!$result['success']) return $result;
        $order = $result['data']['order'];
        $setup = (new PaymentService())->beginTokenSetup($order, $result['data']['customer']);
        if (!$setup['success']) {
            $this->cancelOrder($order->id, $customerId, null, 'Payment setup could not be started.', [], false);
            return $this->failure($setup['error'] ?? 'Unable to start secure payment setup.');
        }
        $clearResult = (new CartService())->clear($customerId);
        if (!$clearResult['success']) {
            $this->cancelOrder($order->id, $customerId, null, 'Checkout could not be completed.', [], false);
            return $this->failure($clearResult['error']);
        }
        return $this->success(['order' => $order, 'payment_setup' => $setup['data']]);
    }

    /** Loads one order with its related details. */
    public function getOrderById(int $orderId, ?int $customerId = null): array {
        $this->expireAbandonedPaymentSetups();
        $order = Order::findById($orderId);
        if (!$order || ($customerId !== null && $order->customer_id !== $customerId)) {
            return $this->failure('Order not found.');
        }

        $payment = $order->getPayment();
        if ($customerId !== null && $order->status === Order::STATUS_SUBMITTED
            && (!$payment || $payment->gateway_token === null)) {
            return $this->failure('Order payment setup is not complete yet.');
        }

        $items = $order->getItems();
        $promotion = $order->promotion_id ? Promotion::findById($order->promotion_id) : null;
        // BOGO fulfillment quantities are persisted in order_items at checkout.
        // Do not append a second free unit while serializing the customer's order.
        $this->attachCurrentProductImages($items);
        if ($promotion) Promotion::attachTargets([$promotion]);

        return $this->success([
            'order' => $order,
            'items' => $items,
            'promotion' => $promotion,
            'payment' => $order->getPayment(),
            'address' => $order->getAddress(),
            'customer' => $order->getCustomer(),
            'history' => OrderStatusHistory::findBy('order_id', $orderId),
        ]);
    }

    /** Returns a customer's orders a page at a time. */
    public function getCustomerOrders(int $customerId, int $limit = 20, int $offset = 0): array {
        $this->expireAbandonedPaymentSetups();
        $db = Database::getConnection();

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $visiblePayment = '(p.gateway_token IS NOT NULL OR p.status = ?)';
        $countStmt = $db->prepare(
            'SELECT COUNT(*) as total FROM orders o JOIN payments p ON p.order_id = o.id
             WHERE o.customer_id = ? AND ' . $visiblePayment
        );
        $countStmt->execute([$customerId, Payment::STATUS_SUCCESS]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $db->prepare(
            'SELECT o.*, pr.code AS promotion_code, pr.discount_type AS promotion_type
             FROM orders o JOIN payments p ON p.order_id = o.id
             LEFT JOIN promotions pr ON pr.id = o.promotion_id
             WHERE o.customer_id = ? AND ' . $visiblePayment . '
             ORDER BY o.created_at DESC, o.id DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$customerId, Payment::STATUS_SUCCESS, $limit, $offset]);
        $orders = $stmt->fetchAll();

        return $this->success([
            'orders' => $orders,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /** Updates order items or the fulfilment time before charging. */
    public function adjustOrder(
        int $orderId,
        int $staffId,
        ?array $adjustedItems = null,
        ?string $newWindowStart = null,
        ?string $newWindowEnd = null
    ): array {
        if ($adjustedItems !== null) {
            if (count($adjustedItems) > 50) {
                return $this->failure('An adjusted order can contain at most 50 menu items.');
            }
            $combinedItems = [];
            $totalsByProduct = [];
            foreach ($adjustedItems as $item) {
                if (!is_array($item)) {
                    return $this->failure('Each adjusted item must include a product and quantity.');
                }
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
                if ($productId === false || $quantity === false) {
                    return $this->failure('Adjusted items need a valid product and quantities from 1 to 99.');
                }
                $totalsByProduct[(int) $productId] = ($totalsByProduct[(int) $productId] ?? 0) + (int) $quantity;
                if ($totalsByProduct[(int) $productId] > 99) {
                    return $this->failure('The combined quantity for a menu item cannot exceed 99.');
                }
                $optionIds = $item['option_ids'] ?? [];
                if (!is_array($optionIds)) return $this->failure('Adjusted item customizations are invalid.');
                $selection = (new ProductOptionService())->resolveSelection((int) $productId, $optionIds);
                if (!$selection['success']) return $selection;
                $signature = implode(',', array_column($selection['data'], 'id'));
                $key = (int) $productId . ':' . $signature;
                $combinedItems[$key] ??= [
                    'product_id' => (int) $productId,
                    'quantity' => 0,
                    'customizations' => $selection['data'],
                ];
                $combinedItems[$key]['quantity'] += $quantity;
                if ($combinedItems[$key]['quantity'] > 99) {
                    return $this->failure('The quantity for a menu item cannot exceed 99.');
                }
            }
            $adjustedItems = array_values($combinedItems);
        }
        if (($newWindowStart === null) !== ($newWindowEnd === null)) {
            return $this->failure('Both confirmed window start and end are required.');
        }
        if ($newWindowStart !== null) {
            $slot = (new SlotService())->isValidSlot($newWindowStart, $newWindowEnd);
            if (!$slot['success']) return $this->failure($slot['error']);
        }
        if ($adjustedItems === null && $newWindowStart === null) {
            return $this->failure('Provide adjusted items or a confirmed time window.');
        }
        $result = $this->transactional(function () use ($orderId, $staffId, $adjustedItems, $newWindowStart, $newWindowEnd) {
            $order = Order::lockById($orderId);
            if (!$order) {
                throw new \Exception("Order {$orderId} not found.");
            }
            if ($order->cancel_reason === self::CUSTOMER_REFUND_PENDING) {
                throw new \Exception('This order has a customer cancellation refund in progress and cannot be adjusted.');
            }

            if ($adjustedItems !== null) {
                $settings = BusinessSettings::current();
                $totalQuantity = 0;
                $quantitiesByProduct = [];
                $previousQuantity = 0;
                $previousByProduct = [];
                foreach ($order->getItems() as $oldItem) {
                    $previousQuantity += $oldItem->quantity;
                    $previousByProduct[$oldItem->product_id] = ($previousByProduct[$oldItem->product_id] ?? 0) + $oldItem->quantity;
                }
                foreach ($adjustedItems as $item) {
                    $quantity = (int) $item['quantity'];
                    $productId = (int) $item['product_id'];
                    $totalQuantity += $quantity;
                    $quantitiesByProduct[$productId] = ($quantitiesByProduct[$productId] ?? 0) + $quantity;
                }
                if ($totalQuantity > $settings->max_cart_items && $totalQuantity > $previousQuantity) {
                    throw new \Exception('An adjusted order cannot exceed the current cart limit of ' . $settings->max_cart_items . ' total items.');
                }
                foreach ($quantitiesByProduct as $productId => $quantity) {
                    if ($quantity > $settings->max_item_quantity && $quantity > ($previousByProduct[$productId] ?? 0)) {
                        throw new \Exception('An adjusted menu item cannot exceed the current limit of ' . $settings->max_item_quantity . '.');
                    }
                }
            }

            if (!in_array($order->status, [Order::STATUS_SUBMITTED, Order::STATUS_ADJUSTED], true)) {
                throw new \Exception("Cannot adjust order in status '{$order->status}'.");
            }
            $payment = $order->getPayment();
            if (!$payment || $payment->status !== Payment::STATUS_TOKENIZED || !$payment->gateway_token) {
                throw new \Exception('Order cannot be adjusted until its payment token is ready.');
            }

            if ($newWindowStart !== null) {
                $slotService = new SlotService();
                $slotValidation = $slotService->isValidSlot($newWindowStart, $newWindowEnd);
                if (!$slotValidation['success']) throw new \Exception($slotValidation['error']);
                $capacity = $slotService->reserveCapacityLocked($newWindowStart, $order->id);
                if (!$capacity['success']) throw new \Exception($capacity['error']);
            }

            if ($adjustedItems !== null) {
                if ($adjustedItems === []) {
                    throw new \Exception('Adjusted order must contain at least one item.');
                }
                $adjustedProductsById = Product::findByIds(array_map(
                    fn(array $item) => $item['product_id'] ?? 0,
                    $adjustedItems
                ), true);
                foreach ($order->getItems() as $item) {
                    $item->delete();
                }

                $newSubtotal = 0.0;
                $bogoPromotion = $order->promotion_id ? Promotion::findById($order->promotion_id) : null;
                if ($bogoPromotion) Promotion::attachTargets([$bogoPromotion]);
                foreach ($adjustedItems as $adj) {
                    $productId = filter_var($adj['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    $product = $productId === false ? null : ($adjustedProductsById[(int) $productId] ?? null);
                    if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                        throw new \Exception('Adjusted product is unavailable.');
                    }

                    if (filter_var($adj['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
                        throw new \Exception('Adjusted item quantity must be at least one.');
                    }
                    $quantity = (int) $adj['quantity'];
                    $customizations = $adj['customizations'] ?? [];
                    $customizationTotal = array_sum(array_map(static fn(array $option): float => (float) $option['price_delta'], $customizations));
                    $freeQuantity = $bogoPromotion
                        && $bogoPromotion->discount_type === Promotion::TYPE_BUY_ONE_GET_ONE
                        && count($bogoPromotion->target_product_ids) === 1
                        && (int) $bogoPromotion->target_product_ids[0] === $product->id
                            ? intdiv($quantity, 2)
                            : 0;
                    $orderItem = new OrderItem(
                        order_id: $order->id,
                        product_id: $product->id,
                        quantity: $quantity,
                        free_quantity: $freeQuantity,
                        unit_price: $product->price + $customizationTotal,
                        product_name: $product->name,
                        customization_json: json_encode($customizations, JSON_UNESCAPED_UNICODE)
                    );
                    if (!$orderItem->save()) {
                        throw new \Exception('Unable to save adjusted items.');
                    }
                    $newSubtotal += $quantity * ($product->price + $customizationTotal);
                }
                $order->subtotal = round($newSubtotal, 2);

                if ($order->promotion_id) {
                    $promotionService = new PromotionService();
                    $revalidate = $promotionService->revalidateForOrder($order);
                    if (!$revalidate['success']) throw new \Exception($revalidate['error']);
                    $order->locked_discount = (float) ($revalidate['data']['discount'] ?? 0);
                    // The adjusted quantities are the staff-approved fulfillment
                    // quantities. Revalidation recalculates the discount only;
                    // never append free units again to these quantities.
                }

                $redemptionRefund = (new LoyaltyService())->refundExcessOrderRedemption($order);
                if (!$redemptionRefund['success']) {
                    throw new \Exception('Unable to update the rewards discount for the adjusted order.');
                }
            }

            if ($newWindowStart !== null) {
                $order->confirmed_window_start = $newWindowStart;
            }
            if ($newWindowEnd !== null) {
                $order->confirmed_window_end = $newWindowEnd;
            }

            $fromStatus = $order->status;
            if ($adjustedItems !== null || $newWindowStart !== null || $newWindowEnd !== null) {
                if ($order->status === Order::STATUS_SUBMITTED) {
                    if (!$order->canTransitionTo(Order::STATUS_ADJUSTED)) {
                        throw new \Exception("Order {$orderId} cannot be adjusted from status '{$order->status}'.");
                    }
                    $order->status = Order::STATUS_ADJUSTED;
                }
            }

            if (!$order->save()) {
                throw new \Exception('Unable to save adjusted order.');
            }

            if ($fromStatus !== $order->status) {
                $this->logTransition($order->id, $fromStatus, $order->status, $staffId, 'Staff adjustment');
            }

            return $order;
        });
        if ($result['success'] && $result['data']->status === Order::STATUS_ADJUSTED) {
            return (new PaymentService())->chargeToken($result['data']);
        }
        return $result;
    }

public function getOrderForStaff(int $orderId): array {
        $this->expireAbandonedPaymentSetups();
        $order = Order::findById($orderId);
        if (!$order) {
            return $this->failure('Order not found.');
        }
$payment = $order->getPayment();
        if ($order->status === Order::STATUS_SUBMITTED
            && (!$payment || $payment->status !== Payment::STATUS_TOKENIZED
                || $payment->gateway_token === null || $payment->gateway_token === '')) {
            return $this->failure('Order is not available for staff review until card setup is complete.');
        }

        $items = $order->getItems();
        $this->attachCurrentProductImages($items);
        $promotion = $order->promotion_id ? Promotion::findById($order->promotion_id) : null;
        if ($promotion) Promotion::attachTargets([$promotion]);

        return $this->success([
            'order'    => $order,
            'items'    => $items,
            'promotion' => $promotion,
            'payment'  => $payment,
            'refunds'  => $payment ? PaymentRefund::findBy('payment_id', $payment->id) : [],
            'address'  => $order->getAddress(),
            'customer' => $order->getCustomer(),
            'history'  => OrderStatusHistory::findBy('order_id', $orderId),
            'limits'   => [
                'max_item_quantity' => BusinessSettings::current()->max_item_quantity,
                'max_cart_items' => BusinessSettings::current()->max_cart_items,
            ],
            'total'    => $order->total(), // computed - won't serialize on its own, Order::total() is a method not a property
        ]);
    }

    public function getRefundEligibility(int $orderId, mixed $amount): array {
        return (new PaymentService())->getRefundEligibility($orderId, $amount);
    }

    public function refundOrder(int $orderId, int $staffId, mixed $amount, mixed $reason, array $bankDetails = []): array {
        return (new PaymentService())->refundOrder($orderId, $staffId, $amount, $reason, $bankDetails);
    }

    public function refreshRefundStatus(int $orderId): array {
        return (new PaymentService())->refreshRefundStatus($orderId);
    }

    /** Attach current menu images without replacing the saved order-item snapshot. */
    private function attachCurrentProductImages(array $items): void {
        if ($items === []) return;

        $productsById = Product::findByIds(array_map(
            fn(OrderItem $item) => $item->product_id,
            $items
        ));
        $productService = new ProductService();
        foreach ($items as $item) {
            $product = $productsById[$item->product_id] ?? null;
            if ($product === null) continue;

            if (trim($item->product_name) === '') $item->product_name = $product->name;
            $item->image_url = $productService->imageUrlForOrderItem($product);
        }
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }

}
