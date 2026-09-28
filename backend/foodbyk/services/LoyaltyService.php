<?php


class LoyaltyService {

    // Configurable via business_settings (future)
    private const POINTS_PER_RAND = 1.0; // 1 point per R1 spent
    private const RAND_PER_POINT = 1.0;  // 1 point = R1 discount (1:1 ratio)

   
    public function getBalance(int $customerId): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        $points = $customer->loyalty_points ?? 0;
        $estimatedValue = $points * self::RAND_PER_POINT;

        return $this->success([
            'points' => $points,
            'estimated_value' => round($estimatedValue, 2),
            'conversion_rate' => self::RAND_PER_POINT,
        ]);
    }

    /**
     * Award points for a completed order.
     * Called after payment succeeds (Payment::STATUS_SUCCESS).
     * Points awarded = round(order total * POINTS_PER_RAND).
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

        // Calculate points: R100 order = 100 points (at default 1:1)
        $pointsToAward = (int) round($orderTotal * self::POINTS_PER_RAND);
        if ($pointsToAward <= 0) {
            return $this->success(['points_awarded' => 0, 'new_balance' => $customer->loyalty_points]);
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points + ? WHERE id = ?');
        $stmt->execute([$pointsToAward, $customerId]);
        if ($stmt->rowCount() !== 1) {
            return $this->failure('Unable to award loyalty points.');
        }
        $customer = Customer::findCustomerById($customerId);

        error_log("Awarded {$pointsToAward} loyalty points to customer {$customerId} for order {$orderId}");

        return $this->success([
            'points_awarded' => $pointsToAward,
            'new_balance' => $customer->loyalty_points,
        ]);
    }

    //Redeem a specific num of points

    public function redeemPoints(int $customerId, int $pointsToRedeem): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if ($pointsToRedeem <= 0) {
            return $this->failure('Invalid points amount.');
        }

        $discount = $pointsToRedeem * self::RAND_PER_POINT;
        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points - ? WHERE id = ? AND loyalty_points >= ?');
        $stmt->execute([$pointsToRedeem, $customerId, $pointsToRedeem]);
        if ($stmt->rowCount() !== 1) {
            $balance = Customer::findCustomerById($customerId)?->loyalty_points ?? 0;
            return $this->failure("Insufficient points. You have {$balance} points, attempted to redeem {$pointsToRedeem}.");
        }
        $customer = Customer::findCustomerById($customerId);

        error_log("Redeemed {$pointsToRedeem} loyalty points from customer {$customerId} for R{$discount} discount");

        return $this->success([
            'discount' => round($discount, 2),
            'points_redeemed' => $pointsToRedeem,
            'new_balance' => $customer->loyalty_points,
        ]);
    }

    
    public function refundPoints(int $customerId, int $pointsToRefund): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if ($pointsToRefund <= 0) {
            return $this->failure('Invalid refund amount.');
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('UPDATE users SET loyalty_points = loyalty_points + ? WHERE id = ?');
        $stmt->execute([$pointsToRefund, $customerId]);
        if ($stmt->rowCount() !== 1) {
            return $this->failure('Unable to refund loyalty points.');
        }
        $customer = Customer::findCustomerById($customerId);

        error_log("Refunded {$pointsToRefund} loyalty points to customer {$customerId}");

        return $this->success([
            'new_balance' => $customer->loyalty_points,
        ]);
    }

   // get estimated discount 
    public function estimateDiscount(int $points): array {
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

    //calculate earned points
    public function estimateEarnings(float $orderTotal): array {
        if ($orderTotal < 0) {
            return $this->failure('Order total cannot be negative.');
        }

        $points = (int) round($orderTotal * self::POINTS_PER_RAND);

        return $this->success([
            'order_total' => round($orderTotal, 2),
            'points' => $points,
            'earning_rate' => self::POINTS_PER_RAND,
        ]);
    }

    //get top loyalty customer
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

    //Admin
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
