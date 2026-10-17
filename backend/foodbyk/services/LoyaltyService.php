<?php


class LoyaltyService {

    private const POINTS_PER_RAND = 0.01; // 1 point for every R100 actually paid
    private const RAND_PER_POINT = 1.0;   // 1 point = R1 checkout discount

   
    public function getBalance(int $customerId): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        $points = $customer->loyalty_points ?? 0;
        $estimatedValue = $points * self::RAND_PER_POINT;

        return $this->success([
            'points' => round((float) $points, 2),
            'estimated_value' => round($estimatedValue, 2),
            'conversion_rate' => self::RAND_PER_POINT,
            'earning_rate_per_rand' => self::POINTS_PER_RAND,
        ]);
    }

    public function getOrderHistory(int $customerId, int $limit = 50, int $offset = 0): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) return $this->failure('Customer not found.');

        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);
        $db = Database::getConnection();
        // Only paid orders earn points. Pending, failed, declined and cancelled
        // checkouts are not presented as reward history.
        $eligibleStatuses = [
            Order::STATUS_PAID,
            Order::STATUS_PREPARING,
            Order::STATUS_READY,
            Order::STATUS_COMPLETED,
        ];
        $statusPlaceholders = implode(',', array_fill(0, count($eligibleStatuses), '?'));
        $count = $db->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND status IN ({$statusPlaceholders})");
        $count->execute(array_merge([$customerId], $eligibleStatuses));
        $total = (int) $count->fetchColumn();
        $stmt = $db->prepare(
            "SELECT id, status, fulfilment_type, created_at, subtotal, locked_discount,
                    delivery_fee, loyalty_points_redeemed, loyalty_points_refunded,
                    loyalty_points_earned,
                    ROUND(GREATEST(0, subtotal + delivery_fee - locked_discount
                        - loyalty_points_redeemed + loyalty_points_refunded), 2) AS total
             FROM orders
             WHERE customer_id = ? AND status IN ({$statusPlaceholders})
             ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute(array_merge([$customerId], $eligibleStatuses, [$limit, $offset]));
        $orders = $stmt->fetchAll();
        foreach ($orders as &$order) {
            foreach (['subtotal', 'locked_discount', 'delivery_fee', 'loyalty_points_redeemed',
                'loyalty_points_refunded', 'loyalty_points_earned', 'total'] as $field) {
                $order[$field] = round((float) ($order[$field] ?? 0), 2);
            }
        }
        unset($order);

        return $this->success([
            'points' => round($customer->loyalty_points, 2),
            'estimated_value' => round($customer->loyalty_points * self::RAND_PER_POINT, 2),
            'conversion_rate' => self::RAND_PER_POINT,
            'earning_rate_per_rand' => self::POINTS_PER_RAND,
            'orders' => $orders,
            'total_orders' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    public function previewRedemption(int $customerId, float $pointsToRedeem, float $availableOrderValue): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) return $this->failure('Customer not found.');
        if (!is_finite($pointsToRedeem) || $pointsToRedeem < 0
            || abs($pointsToRedeem - round($pointsToRedeem, 2)) > 0.00001) {
            return $this->failure('Enter a valid points amount with up to two decimal places.');
        }
        if ($pointsToRedeem > $customer->loyalty_points + 0.00001) {
            return $this->failure('You do not have enough points for this redemption.');
        }
        $maximum = round(min($customer->loyalty_points, max(0.0, $availableOrderValue) / self::RAND_PER_POINT), 2);
        if ($pointsToRedeem > $maximum + 0.00001) {
            return $this->failure('You can redeem points up to the remaining order total.');
        }

        return $this->success([
            'points_to_redeem' => round($pointsToRedeem, 2),
            'discount' => round($pointsToRedeem * self::RAND_PER_POINT, 2),
            'available_points' => round($customer->loyalty_points, 2),
        ]);
    }

    /**
     * Award points for a completed order.
     * Called after payment succeeds (Payment::STATUS_SUCCESS).
     * Points awarded = 1 point for each R100 actually charged, to two decimals.
     * 
     * @param int $customerId
     * @param int $orderId
     * @param float $orderTotal
     * @return array ['success' => bool, 'data' => ['points_awarded' => int, 'new_balance' => int], 'error' => ?string]
     */
    public function awardPointsForOrder(int $customerId, int $orderId, float $orderTotal): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if (!is_finite($orderTotal) || $orderTotal < 0) {
            return $this->failure('Invalid paid order total.');
        }
        $pointsToAward = round($orderTotal * self::POINTS_PER_RAND, 2);
        if ($pointsToAward <= 0) {
            return $this->success(['points_awarded' => 0.0, 'new_balance' => round($customer->loyalty_points, 2)]);
        }

        $db = Database::getConnection();
        $orderUpdate = $db->prepare(
            'UPDATE orders SET loyalty_points_earned = ? WHERE id = ? AND customer_id = ? AND loyalty_points_earned = 0'
        );
        $orderUpdate->execute([$pointsToAward, $orderId, $customerId]);
        if ($orderUpdate->rowCount() !== 1) {
            $existing = $db->prepare('SELECT loyalty_points_earned FROM orders WHERE id = ? AND customer_id = ?');
            $existing->execute([$orderId, $customerId]);
            if ($existing->fetchColumn() !== false) {
                return $this->success(['points_awarded' => 0.0, 'new_balance' => round($customer->loyalty_points, 2)]);
            }
            return $this->failure('Unable to record loyalty points for this order.');
        }

        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points + ? WHERE id = ?');
        $stmt->execute([$pointsToAward, $customerId]);
        if ($stmt->rowCount() !== 1) {
            return $this->failure('Unable to award loyalty points.');
        }
        $customer = Customer::findCustomerById($customerId);

        return $this->success([
            'points_awarded' => $pointsToAward,
            'new_balance' => round($customer->loyalty_points, 2),
        ]);
    }

    public function redeemPoints(int $customerId, float $pointsToRedeem): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if (!is_finite($pointsToRedeem) || $pointsToRedeem <= 0
            || abs($pointsToRedeem - round($pointsToRedeem, 2)) > 0.00001) {
            return $this->failure('Invalid points amount.');
        }

        $pointsToRedeem = round($pointsToRedeem, 2);
        $discount = $pointsToRedeem * self::RAND_PER_POINT;
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points - ? WHERE id = ? AND loyalty_points >= ?');
        $stmt->execute([$pointsToRedeem, $customerId, $pointsToRedeem]);
        if ($stmt->rowCount() !== 1) {
            return $this->failure('Insufficient points.');
        }
        $customer = Customer::findCustomerById($customerId);

        return $this->success([
            'discount' => round($discount, 2),
            'points_redeemed' => $pointsToRedeem,
            'new_balance' => round($customer->loyalty_points, 2),
        ]);
    }

    
    public function refundPoints(int $customerId, float $pointsToRefund): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if (!is_finite($pointsToRefund) || $pointsToRefund <= 0
            || abs($pointsToRefund - round($pointsToRefund, 2)) > 0.00001) {
            return $this->failure('Invalid refund amount.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points + ? WHERE id = ?');
        $stmt->execute([$pointsToRefund, $customerId]);
        if ($stmt->rowCount() !== 1) {
            return $this->failure('Unable to refund loyalty points.');
        }
        $customer = Customer::findCustomerById($customerId);

        return $this->success([
            'new_balance' => round($customer->loyalty_points, 2),
        ]);
    }

    // Call inside the order transaction before saving a decline/cancellation.
    public function refundOrderRedemption(Order $order): array {
        $remaining = round(max(0.0, $order->loyalty_points_redeemed - $order->loyalty_points_refunded), 2);
        if ($remaining <= 0) return $this->success(['points_refunded' => 0.0]);

        $result = $this->refundPoints($order->customer_id, $remaining);
        if (!$result['success']) return $result;

        $order->loyalty_points_refunded = round($order->loyalty_points_refunded + $remaining, 2);
        return $this->success(['points_refunded' => $remaining]);
    }

    // Staff may reduce the order after points were reserved at checkout.
    // Return any portion that can no longer reduce the updated order total.
    public function refundExcessOrderRedemption(Order $order): array {
        $currentlyApplied = round(max(0.0, $order->loyalty_points_redeemed - $order->loyalty_points_refunded), 2);
        $maximumAfterAdjustment = round(max(
            0.0,
            $order->subtotal + $order->delivery_fee - $order->locked_discount
        ) / self::RAND_PER_POINT, 2);
        $excess = round(max(0.0, $currentlyApplied - $maximumAfterAdjustment), 2);
        if ($excess <= 0) return $this->success(['points_refunded' => 0.0]);

        $result = $this->refundPoints($order->customer_id, $excess);
        if (!$result['success']) return $result;

        $order->loyalty_points_refunded = round($order->loyalty_points_refunded + $excess, 2);
        return $this->success(['points_refunded' => $excess]);
    }

    public function estimateDiscount(float $points): array {
        if ($points < 0) {
            return $this->failure('Points cannot be negative.');
        }

        $discount = $points * self::RAND_PER_POINT;

        return $this->success([
            'points' => $points,
            'discount' => round($discount, 2),
            'conversion_rate' => self::RAND_PER_POINT,
        ]);
    }

    public function estimateEarnings(float $orderTotal): array {
        if ($orderTotal < 0) {
            return $this->failure('Order total cannot be negative.');
        }

        $points = round($orderTotal * self::POINTS_PER_RAND, 2);

        return $this->success([
            'order_total' => round($orderTotal, 2),
            'points' => $points,
            'earning_rate' => self::POINTS_PER_RAND,
        ]);
    }

    public function getTopCustomers(int $limit = 10): array {
        $limit = max(1, min($limit, 100));

        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT u.*, COUNT(o.id) as order_count
             FROM users u
             LEFT JOIN orders o ON o.customer_id = u.id
             WHERE u.role_id = (SELECT id FROM roles WHERE role_name = ?)
             AND u.is_active = 1
             ORDER BY u.loyalty_points DESC
             LIMIT ?'
        );
        $stmt->execute([Role::CUSTOMER, $limit]);
        $rows = $stmt->fetchAll();

        return $this->success($rows);
    }

    public function bulkAwardPoints(array $customerIds, int $pointsPerCustomer, string $reason): array {
        if ($pointsPerCustomer <= 0) {
            return $this->failure('Points per customer must be positive.');
        }

        if (empty($customerIds)) {
            return $this->failure('No customers provided.');
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();

            $awardedCount = 0;
            $totalPoints = 0;

            foreach ($customerIds as $customerId) {
                $customer = Customer::findCustomerById((int) $customerId);
                if (!$customer) continue;

                $customer->loyalty_points = ($customer->loyalty_points ?? 0) + $pointsPerCustomer;
                if ($customer->save()) {
                    $awardedCount++;
                    $totalPoints += $pointsPerCustomer;
                }
            }

            $db->commit();

            error_log("Bulk award: {$awardedCount} customers awarded {$pointsPerCustomer} points each. Reason: {$reason}");

            return $this->success([
                'awarded_count' => $awardedCount,
                'total_points' => $totalPoints,
            ]);
        } catch (\Exception $e) {
            $db->rollBack();
            return $this->failure('Bulk award operation failed: ' . $e->getMessage());
        }
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
