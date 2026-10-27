<?php

class OrderService {

    private const PAYMENT_SETUP_TIMEOUT_MINUTES = 30;

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

    public function cancelOrder(int $orderId, ?int $customerId, ?int $staffId, mixed $reason): array {
        $reason = is_string($reason) ? trim($reason) : '';
        $result = $this->transactional(function () use ($orderId, $customerId, $staffId, $reason) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if ($staffId === null && $customerId !== null && $order->customer_id !== $customerId) {
                throw new \Exception("Order {$orderId} not found.");
            }
            if (!$order->canTransitionTo(Order::STATUS_CANCELLED)) {
                // Paid orders cannot be cancelled through the app.
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
        if ($result['success']) (new PaymentService())->releaseToken($result['data']);
        return $result;
    }

    public function advanceFulfilment(int $orderId, int $staffId, string $newStatus): array {
        return $this->transactional(function () use ($orderId, $staffId, $newStatus) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
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
               AND p.gateway_token IS NOT NULL
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
            $where = ' WHERE p.gateway_token IS NOT NULL
                       AND o.created_at >= :created_start AND o.created_at < :created_end
                       AND COALESCE(o.confirmed_window_start, o.requested_window_start) >= :window_start
                       AND COALESCE(o.confirmed_window_start, o.requested_window_start) < :window_end';
            $bindings = [
                ':created_start' => $createdStart,
                ':created_end' => $createdEnd,
                ':window_start' => $windowStart,
                ':window_end' => $windowEnd,
            ];
        }

        if ($where === '') $where = ' WHERE p.gateway_token IS NOT NULL';
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
        float $loyaltyPointsToRedeem = 0.0
    ): array {
        $result = $this->transactional(function () use (
            $customerId, $fulfilmentType, $addressId,
            $requestedWindowStart, $requestedWindowEnd, $promotionCode, $loyaltyPointsToRedeem
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
                $product = $productsById[$cartItem->product_id] ?? null;
                if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                    throw new \Exception("Item {$cartItem->product_id} is no longer available.");
                }
                $subtotal += $product->price * $cartItem->quantity;
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
            }

            // Price delivery first so a free-delivery promotion uses the real fee.
            if ($promotionCode) {
                $promoResult = (new PromotionService())->validateAndCalculate(
                    $promotionCode,
                    $subtotal,
                    array_map(fn(CartItem $item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                    'unit_price' => $productsById[$item->product_id]->price,
                    ], $cartItems),
                    $order->delivery_fee
                );
                if (!$promoResult['success']) {
                    throw new \Exception('Invalid promotion: ' . $promoResult['error']);
                }
                $order->locked_discount = $promoResult['data']['discount'];
                $order->promotion_id = $promoResult['data']['promotion_id'];
            }

            $loyaltyService = new LoyaltyService();
            $redemption = $loyaltyService->previewRedemption(
                $customerId,
                $loyaltyPointsToRedeem,
                max(0.0, $order->subtotal + $order->delivery_fee - $order->locked_discount)
            );
            if (!$redemption['success']) throw new \Exception($redemption['error']);
            $pointsToRedeem = $redemption['data']['points_to_redeem'];
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
            $payment = new Payment(order_id: $order->id, amount: $order->total());
            if (!$payment->save()) {
                throw new \Exception('Unable to create payment record.');
            }

            foreach ($cartItems as $cartItem) {
                $product = $productsById[$cartItem->product_id];
                $orderItem = new OrderItem(
                    product_name: $product->name,
                    order_id: $order->id,
                    product_id: $cartItem->product_id,
                    quantity: $cartItem->quantity,
                    unit_price: $product->price
                );
                if (!$orderItem->save()) {
                    throw new \Exception('Unable to save order items.');
                }
            }

            $paymentService = new PaymentService();
            $setupResult = $paymentService->beginTokenSetup($order, $customer);
            if (!$setupResult['success']) {
                throw new \Exception('Unable to initiate payment.');
            }

            $clearResult = (new CartService())->clear($customerId);
            if (!$clearResult['success']) throw new \Exception($clearResult['error']);

            // The initial history entry has no previous order status.
            $this->logTransition($order->id, '', Order::STATUS_SUBMITTED, $customerId);

            return ['order' => $order, 'payment_setup' => $setupResult['data']];
        });

        return $result;
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
        $this->attachCurrentProductImages($items);
        $promotion = $order->promotion_id ? Promotion::findById($order->promotion_id) : null;
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
            foreach ($adjustedItems as $item) {
                if (!is_array($item)) {
                    return $this->failure('Each adjusted item must include a product and quantity.');
                }
                $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $quantity = filter_var($item['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 99]]);
                if ($productId === false || $quantity === false) {
                    return $this->failure('Adjusted items need a valid product and a quantity from 1 to 99.');
                }
                $combinedItems[$productId] = ($combinedItems[$productId] ?? 0) + $quantity;
                if ($combinedItems[$productId] > 99) {
                    return $this->failure('The quantity for a menu item cannot exceed 99.');
                }
            }
            $adjustedItems = [];
            foreach ($combinedItems as $productId => $quantity) {
                $adjustedItems[] = ['product_id' => (int) $productId, 'quantity' => $quantity];
            }
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
                    $orderItem = new OrderItem(
                        order_id: $order->id,
                        product_id: $product->id,
                        quantity: $quantity,
                        unit_price: $product->price,
                        product_name: $product->name
                    );
                    if (!$orderItem->save()) {
                        throw new \Exception('Unable to save adjusted items.');
                    }
                    $newSubtotal += $quantity * $product->price;
                }
                $order->subtotal = round($newSubtotal, 2);

                if ($order->promotion_id) {
                    $promotionService = new PromotionService();
                    $revalidate = $promotionService->revalidateForOrder($order);
                    if (!$revalidate['success']) throw new \Exception($revalidate['error']);
                    $order->locked_discount = (float) ($revalidate['data']['discount'] ?? 0);
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
            && (!$payment || $payment->gateway_token === null)) {
            return $this->failure('Order is not available for staff review until card setup is complete.');
        }

        $items = $order->getItems();
        $this->attachCurrentProductImages($items);

        return $this->success([
            'order'    => $order,
            'items'    => $items,
            'payment'  => $payment,
            'refunds'  => $payment ? PaymentRefund::findBy('payment_id', $payment->id) : [],
            'address'  => $order->getAddress(),
            'customer' => $order->getCustomer(),
            'history'  => OrderStatusHistory::findBy('order_id', $orderId),
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
