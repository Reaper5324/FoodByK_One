<?php

class OrderService {

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
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT o.*, u.name AS customer_name, u.email AS customer_email
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             WHERE o.status = '" . Order::STATUS_SUBMITTED . "'
             ORDER BY o.created_at ASC"
        )->fetchAll();
        return ['success' => true, 'data' => $rows, 'error' => null];
    }

    public function getOrdersForStaffDashboard(): array {
        $db = Database::getConnection();
        $rows = $db->query(
            "SELECT o.*, u.name AS customer_name, u.email AS customer_email,
                    p.status AS payment_status,
                    GREATEST(0, o.subtotal - o.locked_discount) + o.delivery_fee AS total
             FROM orders o
             JOIN users u ON u.id = o.customer_id
             LEFT JOIN payments p ON p.order_id = o.id
             ORDER BY o.created_at DESC
             LIMIT 200"
        )->fetchAll();
        return ['success' => true, 'data' => $rows, 'error' => null];
    }

    private function logTransition(int $orderId, ?string $from, string $to, ?int $actorId, ?string $notes = null): void {
        if (!(new OrderStatusHistory(order_id: $orderId, from_status: $from, to_status: $to, changed_by: $actorId, notes: $notes))->save()) {
            throw new \Exception('Unable to record order status history.');
        }
    }

    /**
     * CRITICAL: Submit a new order from cart. Creates Order, OrderItems, Payment, 
     * and initiates PayFast tokenization. Called by CheckoutService/CheckoutController.
     * 
     * @param int $customerId
     * @param string $fulfilmentType (collection|delivery)
     * @param ?int $addressId (required if delivery)
     * @param string $requestedWindowStart (required preferred fulfilment start time)
     * @param string $requestedWindowEnd (required preferred fulfilment end time)
     * @param ?string $promotionCode (optional promo/coupon code)
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
        $result = $this->transactional(function () use (
            $customerId, $fulfilmentType, $addressId,
            $requestedWindowStart, $requestedWindowEnd, $promotionCode
        ) {
            if (!in_array($fulfilmentType, [Order::TYPE_COLLECTION, Order::TYPE_DELIVERY], true)) {
                throw new \Exception('Invalid fulfilment type.');
            }

            $cartItems = CartItem::findBy('customer_id', $customerId);
            if (empty($cartItems)) {
                throw new \Exception('Cart is empty.');
            }

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
                $product = Product::findById($cartItem->product_id);
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
                        'unit_price' => Product::findById($item->product_id)?->price ?? 0.0,
                    ], $cartItems),
                    $order->delivery_fee
                );
                if (!$promoResult['success']) {
                    throw new \Exception('Invalid promotion: ' . $promoResult['error']);
                }
                $order->locked_discount = $promoResult['data']['discount'];
                $order->promotion_id = $promoResult['data']['promotion_id'];
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
                $product = Product::findById($cartItem->product_id);
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

        if ($result['success']) {
            (new NotificationService())->notifyOrderEvent($result['data']['order'], 'submitted', 'staff');
        }

        return $result;
    }

    /**
     * Retrieve a single order by ID with full details (items, payment, history).
     */
    public function getOrderById(int $orderId, ?int $customerId = null): array {
        $order = Order::findById($orderId);
        if (!$order || ($customerId !== null && $order->customer_id !== $customerId)) {
            return $this->failure('Order not found.');
        }

        $items = $order->getItems();
        $productService = new ProductService();
        foreach ($items as $item) {
            $product = Product::findById($item->product_id);
            if ($product !== null) {
                if (trim($item->product_name) === '') $item->product_name = $product->name;
                $item->image_url = $productService->imageUrlForOrderItem($product);
            }
        }

        return $this->success([
            'order' => $order,
            'items' => $items,
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

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $countStmt = $db->prepare('SELECT COUNT(*) as total FROM orders WHERE customer_id = ?');
        $countStmt->execute([$customerId]);
        $total = (int) $countStmt->fetch()['total'];

        $stmt = $db->prepare(
            'SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?'
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

            $oldSubtotal = $order->subtotal;
            $oldDiscount = $order->locked_discount;

            if ($adjustedItems !== null) {
                if ($adjustedItems === []) {
                    throw new \Exception('Adjusted order must contain at least one item.');
                }
                foreach ($order->getItems() as $item) {
                    $item->delete();
                }

                $newSubtotal = 0.0;
                foreach ($adjustedItems as $adj) {
                    $product = Product::findById($adj['product_id'] ?? 0);
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
                    if ($revalidate['success']) {
                        $order->locked_discount = $revalidate['data']['discount'];
                    }
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

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }

}
