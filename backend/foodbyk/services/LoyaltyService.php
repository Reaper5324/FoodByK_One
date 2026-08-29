<?php

/**
 * LoyaltyService - Manage customer loyalty points.
 * 
 * Handles:
 * - Accruing points from orders
 * - Redeeming points for discounts
 * - Checking point balance
 * - Loyalty tier calculation (future extensibility)
 * - Point expiry rules (future)
 * 
 * FR-19: Loyalty/rewards tracked against account
 * 
 * NOTE: Current schema only has users.loyalty_points (integer total).
 * A full implementation might add a loyalty_transactions ledger for
 * detailed history, expiry tracking, etc. See DOMAIN.md §9 for scope.
 */
class LoyaltyService {

    // Configurable via business_settings (future)
    private const POINTS_PER_RAND = 1.0; // 1 point per R1 spent
    private const RAND_PER_POINT = 1.0;  // 1 point = R1 discount (1:1 ratio)

    /**
     * Get loyalty balance for a customer.
     * 
     * @param int $customerId
     * @return array ['success' => bool, 'data' => ['points' => int, 'estimated_value' => float], 'error' => ?string]
     */
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

        $customer->loyalty_points = ($customer->loyalty_points ?? 0) + $pointsToAward;

        if (!$customer->save()) {
            return $this->failure('Unable to award loyalty points.');
        }

        error_log("Awarded {$pointsToAward} loyalty points to customer {$customerId} for order {$orderId}");

        return $this->success([
            'points_awarded' => $pointsToAward,
            'new_balance' => $customer->loyalty_points,
        ]);
    }

    /**
     * Redeem a specific amount of points for a discount.
     * Called during checkout if customer wants to use points.
     * 
     * @param int $customerId
     * @param int $pointsToRedeem
     * @return array ['success' => bool, 'data' => ['discount' => float], 'error' => ?string]
     */
    public function redeemPoints(int $customerId, int $pointsToRedeem): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if ($pointsToRedeem <= 0) {
            return $this->failure('Invalid points amount.');
        }

        $balance = $customer->loyalty_points ?? 0;
        if ($balance < $pointsToRedeem) {
            return $this->failure(
                "Insufficient points. You have {$balance} points, attempted to redeem {$pointsToRedeem}."
            );
        }

        $discount = $pointsToRedeem * self::RAND_PER_POINT;

        // Deduct points
        $customer->loyalty_points = $balance - $pointsToRedeem;
        if (!$customer->save()) {
            return $this->failure('Unable to redeem points.');
        }

        error_log("Redeemed {$pointsToRedeem} loyalty points from customer {$customerId} for R{$discount} discount");

        return $this->success([
            'discount' => round($discount, 2),
            'points_redeemed' => $pointsToRedeem,
            'new_balance' => $customer->loyalty_points,
        ]);
    }

    /**
     * Refund points if an order is cancelled/refunded.
     * Reverses the awardPointsForOrder() call.
     * 
     * @param int $customerId
     * @param int $pointsToRefund
     * @return array ['success' => bool, 'data' => ['new_balance' => int], 'error' => ?string]
     */
    public function refundPoints(int $customerId, int $pointsToRefund): array {
        $customer = Customer::findCustomerById($customerId);
        if (!$customer) {
            return $this->failure('Customer not found.');
        }

        if ($pointsToRefund <= 0) {
            return $this->failure('Invalid refund amount.');
        }

        $customer->loyalty_points = ($customer->loyalty_points ?? 0) + $pointsToRefund;

        if (!$customer->save()) {
            return $this->failure('Unable to refund loyalty points.');
        }

        error_log("Refunded {$pointsToRefund} loyalty points to customer {$customerId}");

        return $this->success([
            'new_balance' => $customer->loyalty_points,
        ]);
    }

    /**
     * Get estimated discount for a given number of points.
     * This is a read-only calculation, not a redemption.
     * 
     * @param int $points
     * @return array ['success' => bool, 'data' => ['discount' => float], 'error' => ?string]
     */
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

    /**
     * Calculate earned points for an order amount (estimate).
     * This is a read-only calculation, not an award.
     * 
     * @param float $orderTotal
     * @return array ['success' => bool, 'data' => ['points' => int], 'error' => ?string]
     */
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

    /**
     * Get top loyalty customers (admin reporting).
     * 
     * @param int $limit (default 10)
     * @return array ['success' => bool, 'data' => Customer[], 'error' => ?string]
     */
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

    /**
     * Bulk award points to multiple customers (admin promotion).
     * Use with caution - admin-only operation.
     * 
     * @param array $customerIds
     * @param int $pointsPerCustomer
     * @param string $reason (audit trail)
     * @return array ['success' => bool, 'data' => ['awarded_count' => int, 'total_points' => int], 'error' => ?string]
     */
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
