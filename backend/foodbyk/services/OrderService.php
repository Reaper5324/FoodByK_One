<?php

class OrderService {

    // Small Template-Method-style helper: every state-changing method here
    // follows BEGIN -> lock -> validate -> mutate -> history -> COMMIT/ROLLBACK.
    // This centralises that shape instead of repeating try/catch/rollback
    // in five separate methods.
    private function transactional(callable $work): array {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $result = $work($db);
            $db->commit();
            return ['success' => true, 'data' => $result];
        } catch (\Exception $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function confirmOrder(int $orderId, int $staffId, ?string $confirmedStart = null, ?string $confirmedEnd = null): array {
        return $this->transactional(function () use ($orderId, $staffId, $confirmedStart, $confirmedEnd) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if (!$order->canTransitionTo(Order::STATUS_ACCEPTED)) {
                throw new \Exception("Order {$orderId} cannot be confirmed from status '{$order->status}'.");
            }

            $payment = $order->getPayment();
            if (!$payment || $payment->status !== Payment::STATUS_TOKENIZED) {
                // Confirming without a valid held token would mean charging
                // is impossible later - fail loudly rather than confirming
                // an order that can never actually be paid.
                throw new \Exception("Order {$orderId} has no valid payment token.");
            }

            $fromStatus = $order->status;
            $order->staff_id = $staffId;
            $order->status   = Order::STATUS_ACCEPTED;
            if ($confirmedStart) $order->confirmed_window_start = $confirmedStart;
            if ($confirmedEnd)   $order->confirmed_window_end   = $confirmedEnd;
            $order->save();

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId);

            return $order;
        });
    }

    public function declineOrder(int $orderId, int $staffId, string $reason): array {
        return $this->transactional(function () use ($orderId, $staffId, $reason) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if (!$order->canTransitionTo(Order::STATUS_DECLINED)) {
                throw new \Exception("Order {$orderId} cannot be declined from status '{$order->status}'.");
            }

            $fromStatus = $order->status;
            $order->staff_id       = $staffId;
            $order->status         = Order::STATUS_DECLINED;
            $order->decline_reason = $reason;
            $order->save();

            $order->getPayment()?->voidToken(); // token never charged - FR-08

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId, $reason);

            return $order;
        });
    }

    // Customer-initiated - $userId is null in the history row when it's the
    // customer, not staff, per DOMAIN.md's order_status_history convention.
    public function cancelOrder(int $orderId, ?int $staffId, string $reason): array {
        return $this->transactional(function () use ($orderId, $staffId, $reason) {
            $order = Order::lockById($orderId);
            if (!$order) throw new \Exception("Order {$orderId} not found.");
            if (!$order->canTransitionTo(Order::STATUS_CANCELLED)) {
                // Deliberately includes the "already paid" case - see DOMAIN.md §7.
                throw new \Exception("Order {$orderId} can no longer be cancelled (status: '{$order->status}').");
            }

            $fromStatus = $order->status;
            $order->status        = Order::STATUS_CANCELLED;
            $order->cancel_reason = $reason;
            $order->save();

            $payment = $order->getPayment();
            if ($payment && $payment->status === Payment::STATUS_TOKENIZED) {
                $payment->voidToken();
            }

            $this->logTransition($order->id, $fromStatus, $order->status, $staffId, $reason);

            return $order;
        });
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
            $order->save();

            $this->logTransition($order->id, $fromStatus, $newStatus, $staffId);

            return $order;
        });
    }

    // Avoids the N+1 trap flagged during review - one join, not a loop of
    // Order::findById() + User::findById() per row.
    public function getPendingOrdersForStaffDashboard(): array {
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT o.*, u.full_name AS customer_name, u.email AS customer_email
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             WHERE o.status = '" . Order::STATUS_SUBMITTED . "'
             ORDER BY o.created_at ASC"
        )->fetchAll();
        return ['success' => true, 'data' => $rows];
    }

    private function logTransition(int $orderId, ?string $from, string $to, ?int $actorId, ?string $notes = null): void {
        (new OrderStatusHistory(order_id: $orderId, from_status: $from, to_status: $to, changed_by: $actorId, notes: $notes))->save();
    }

    /**
     * CRITICAL: Submit a new order from cart. Creates Order, OrderItems, Payment, 
     * and initiates PayFast tokenization. Called by CheckoutService/CheckoutController.
     * 
     * @param int $customerId
     * @param string $fulfilmentType (collection|delivery)
     * @param ?int $addressId (required if delivery)
     * @param ?string $requestedWindowStart (preferred fulfillment start time)
     * @param ?string $requestedWindowEnd (preferred fulfillment end time)
     * @param ?string $promotionCode (optional promo/coupon code)
     * @return array ['success' => bool, 'data' => Order, 'error' => ?string]
     */
    public function submitOrder(
        int $customerId,
        string $fulfilmentType,
        ?int $addressId = null,
        ?string $requestedWindowStart = null,
        ?string $requestedWindowEnd = null,
        ?string $promotionCode = null
    ): array {
        return $this->transactional(function () use (
            $customerId, $fulfilmentType, $addressId,
            $requestedWindowStart, $requestedWindowEnd, $promotionCode
        ) {
            // Load cart items
            $cartItems = CartItem::findBy('customer_id', $customerId);
            if (empty($cartItems)) {
                throw new \Exception('Cart is empty.');
            }

            $customer = Customer::findCustomerById($customerId);
            if (!$customer) {
                throw new \Exception('Customer not found.');
            }

            // Create Order row
            $order = new Order(
                customer_id: $customerId,
                fulfilment_type: $fulfilmentType,
                address_id: $addressId,
                requested_window_start: $requestedWindowStart,
                requested_window_end: $requestedWindowEnd,
                status: Order::STATUS_SUBMITTED
            );

            // Calculate subtotal from cart
            $subtotal = 0.0;
            foreach ($cartItems as $cartItem) {
                $product = Product::findById($cartItem->product_id);
                if (!$product || $product->status !== Product::STATUS_ACTIVE || !$product->is_available) {
                    throw new \Exception("Item {$cartItem->product_id} is no longer available.");
                }
                $subtotal += $cartItem->getLineTotal();
            }
            $order->subtotal = round($subtotal, 2);

            // Apply promotion if provided
            $promotionService = new PromotionService();
            $discount = 0.0;
            $promotionId = null;
            if ($promotionCode) {
                $promoResult = $promotionService->validateAndCalculate(
                    $promotionCode,
                    $subtotal,
                    array_map(fn(CartItem $item) => [
                        'product_id' => $item->product_id,
                        'quantity' => $item->quantity,
                        'unit_price' => $item->unit_price,
                    ], $cartItems),
                    0.0 // delivery_fee will be added after
                );
                if (!$promoResult['success']) {
                    throw new \Exception('Invalid promotion: ' . $promoResult['error']);
                }
                $discount = $promoResult['data']['discount'];
                $promotionId = $promoResult['data']['promotion_id'];
            }
            $order->locked_discount = $discount;
            $order->promotion_id = $promotionId;

            // Check delivery eligibility and calculate fee
            $deliveryService = new DeliveryService();
            $address = $addressId ? Address::findById($addressId) : null;
            $eligibility = $deliveryService->checkEligibility($fulfilmentType, $address);
            if (!$eligibility['success']) {
                throw new \Exception($eligibility['error']);
            }

            if ($fulfilmentType === Order::TYPE_DELIVERY) {
                $order->distance_km = $eligibility['data']['distance_km'];
                $order->delivery_fee = $eligibility['data']['fee'];
            }

            // Check trading hours
            $when = $requestedWindowStart ? new \DateTimeImmutable($requestedWindowStart) : new \DateTimeImmutable();
            if (!$deliveryService->isWithinTradingHours($when)) {
                throw new \Exception('Order time is outside trading hours.');
            }

            // Save order
            if (!$order->save()) {
                throw new \Exception('Unable to create order.');
            }

            // Create OrderItem rows from cart
            foreach ($cartItems as $cartItem) {
                $product = Product::findById($cartItem->product_id);
                $orderItem = new OrderItem(
                    order_id: $order->id,
                    product_id: $cartItem->product_id,
                    quantity: $cartItem->quantity,
                    unit_price: $product->price
                );
                if (!$orderItem->save()) {
                    throw new \Exception('Unable to save order items.');
                }
            }

            // Initiate PayFast tokenization
            $paymentService = new PaymentService();
            $setupResult = $paymentService->beginTokenSetup($order, $customer);
            if (!$setupResult['success']) {
                throw new \Exception('Unable to initiate payment.');
            }

            // Clear the cart
            (new CartService())->clear($customerId);

            // Log the submission
            $this->logTransition($order->id, null, Order::STATUS_SUBMITTED, $customerId);

            return $order;
        });
    }

    /**
     * Retrieve a single order by ID with full details (items, payment, history).
     */
    public function getOrderById(int $orderId): array {
        $order = Order::findById($orderId);
        if (!$order) {
            return $this->failure('Order not found.');
        }

        return $this->success([
            'order' => $order,
            'items' => $order->getItems(),
            'payment' => $order->getPayment(),
            'address' => $order->getAddress(),
            'customer' => $order->getCustomer(),
            'history' => OrderStatusHistory::findBy('order_id', $orderId),
        ]);
    }

    /**
     * Retrieve order history for a customer, with pagination.
     * 
     * @param int $customerId
     * @param int $limit (default 20)
     * @param int $offset (default 0)
     * @return array ['success' => bool, 'data' => ['orders' => [...], 'total' => int], 'error' => ?string]
     */
    public function getCustomerOrders(int $customerId, int $limit = 20, int $offset = 0): array {
        $db = Database::getConnection();

        // Validate pagination params
        $limit = max(1, min($limit, 100)); // cap at 100
        $offset = max(0, $offset);

        // Total count
        $countStmt = $db->prepare('SELECT COUNT(*) as total FROM orders WHERE customer_id = ?');
        $countStmt->execute([$customerId]);
        $total = (int) $countStmt->fetch()['total'];

        // Paginated results
        $stmt = $db->prepare(
            'SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$customerId, $limit, $offset]);
        $orders = $stmt->fetchAll();

        return $this->success([
            'orders' => $orders,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    /**
     * Staff adjustment of order: modify items, time windows, and recalculate totals.
     * Must revalidate promotion and recalculate delivery fee if address changed.
     * 
     * @param int $orderId
     * @param int $staffId
     * @param ?array $adjustedItems [['product_id' => int, 'quantity' => int], ...]
     * @param ?string $newWindowStart
     * @param ?string $newWindowEnd
     * @return array ['success' => bool, 'data' => Order, 'error' => ?string]
     */
    public function adjustOrder(
        int $orderId,
        int $staffId,
        ?array $adjustedItems = null,
        ?string $newWindowStart = null,
        ?string $newWindowEnd = null
    ): array {
        return $this->transactional(function () use ($orderId, $staffId, $adjustedItems, $newWindowStart, $newWindowEnd) {
            $order = Order::lockById($orderId);
            if (!$order) {
                throw new \Exception("Order {$orderId} not found.");
            }

            // Can only adjust submitted/accepted/adjusted orders - not after charge_pending
            if (!in_array($order->status, [Order::STATUS_SUBMITTED, Order::STATUS_ACCEPTED, Order::STATUS_ADJUSTED], true)) {
                throw new \Exception("Cannot adjust order in status '{$order->status}'.");
            }

            $oldSubtotal = $order->subtotal;
            $oldDiscount = $order->locked_discount;

            // Update items if provided
            if ($adjustedItems !== null) {
                // Delete existing OrderItems
                foreach ($order->getItems() as $item) {
                    $item->delete();
                }

                // Recalculate subtotal
                $newSubtotal = 0.0;
                foreach ($adjustedItems as $adj) {
                    $product = Product::findById($adj['product_id'] ?? 0);
                    if (!$product || $product->status !== Product::STATUS_ACTIVE) {
                        throw new \Exception("Product {$adj['product_id']} is invalid.");
                    }

                    $quantity = max(1, (int) ($adj['quantity'] ?? 1));
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

                // Revalidate promotion against new subtotal
                if ($order->promotion_id) {
                    $promotionService = new PromotionService();
                    $revalidate = $promotionService->revalidateForOrder($order);
                    if ($revalidate['success']) {
                        $order->locked_discount = $revalidate['data']['discount'];
                    }
                }
            }

            // Update time windows if provided
            if ($newWindowStart !== null) {
                $order->confirmed_window_start = $newWindowStart;
            }
            if ($newWindowEnd !== null) {
                $order->confirmed_window_end = $newWindowEnd;
            }

            // Mark as adjusted if this changed the order
            $fromStatus = $order->status;
            if ($adjustedItems !== null || $newWindowStart !== null || $newWindowEnd !== null) {
                if ($order->status === Order::STATUS_SUBMITTED) {
                    $order->status = Order::STATUS_ADJUSTED;
                } elseif ($order->status === Order::STATUS_ACCEPTED) {
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
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }

}