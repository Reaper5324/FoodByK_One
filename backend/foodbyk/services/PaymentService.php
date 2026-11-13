<?php

class PaymentService {

    const TOKENIZE_URL_SANDBOX = 'https://sandbox.payfast.co.za/eng/process';
    const TOKENIZE_URL_LIVE    = 'https://www.payfast.co.za/eng/process';
    const API_BASE             = 'https://api.payfast.co.za';

    /** Start order tokenization after the order and payment row have been committed. */
    public function beginTokenSetup(Order $order, Customer $customer): array {
        if (API_BASE_URL === '' || PAYFAST_MERCHANT_ID === '' || PAYFAST_MERCHANT_KEY === '') {
            return ['success' => false, 'error' => 'Secure payment setup is not configured.'];
        }
        $callbackBase = rtrim(API_BASE_URL, '/');
        $fields = [
            'merchant_id' => PAYFAST_MERCHANT_ID,
            'merchant_key' => PAYFAST_MERCHANT_KEY,
            'return_url' => $callbackBase . '/payments/return?order_id=' . $order->id,
            'cancel_url' => $callbackBase . '/payments/cancel?order_id=' . $order->id,
            'notify_url' => $callbackBase . '/payments/token-webhook',
            'name_first' => explode(' ', $customer->full_name)[0],
            'email_address' => $customer->email,
            'm_payment_id' => (string) $order->id,
            'amount' => '0.00',
            'item_name' => 'Food by K - card setup for order #' . $order->id,
            'subscription_type' => 2,
        ];
        $fields['signature'] = $this->generateFormSignature($fields);
        return ['success' => true, 'data' => [
            'redirect_url' => $this->sandboxEnabled() ? self::TOKENIZE_URL_SANDBOX : self::TOKENIZE_URL_LIVE,
            'fields' => $fields,
        ]];
    }

    // Save the token returned by PayFast after the customer authorizes it.
    public function handleTokenSetupWebhook(array $itn, ?string $sourceIp = null): array {
        if (!$this->verifyItn($itn, $sourceIp)) {
            return ['success' => false, 'error' => 'Invalid ITN signature/source.'];
        }

        $paymentReference = (string) ($itn['m_payment_id'] ?? '');
        $token   = $itn['token'] ?? null;
        if (!is_string($token) || $token === '' || ($itn['payment_status'] ?? '') !== 'COMPLETE'
            || (string) ($itn['merchant_id'] ?? '') !== (string) PAYFAST_MERCHANT_ID) {
            error_log('PayFast token ITN rejected: reference, token, completion status, or merchant ID is missing or invalid.');
            return ['success' => false, 'error' => 'Payment token setup data is missing or invalid.'];
        }

        if (ctype_digit($paymentReference) && (int) $paymentReference > 0) {
            return $this->saveOrderToken((int) $paymentReference, $token);
        }

        if (preg_match('/^ct-[a-f0-9]{48}$/', $paymentReference) === 1) {
            $customerResult = $this->saveCustomerToken($paymentReference, $token);
            if (!$customerResult['success']) $this->releaseTokenValue($token);
            return $customerResult;
        }

        return ['success' => false, 'error' => 'Payment token setup reference is invalid.'];
    }

    private function saveOrderToken(int $orderId, string $token): array {
        (new OrderService())->expireAbandonedPaymentSetups();
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $order = Order::lockById($orderId);
            if ($order && $order->status === Order::STATUS_CANCELLED) {
                $payment = $order->getPayment();
                if ($payment && $payment->status === Payment::STATUS_VOIDED) {
                    $db->commit();
                    $this->releaseTokenValue($token);
                    return ['success' => true];
                }
            }
            if (!$order || $order->status !== Order::STATUS_SUBMITTED) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Order is not awaiting payment setup.'];
            }
            $payment = $order->getPayment();
            if (!$payment) throw new RuntimeException('Payment setup was not recorded for this order.');
            if ($payment->gateway_token !== null && $payment->gateway_token !== '') {
                $db->commit();
                return ['success' => true];
            }
            $payment->gateway_token = $token;
            if (!$payment->save()) throw new RuntimeException('Unable to save payment token.');
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('PayFast order token ITN could not be saved: ' . get_class($error));
            return ['success' => false, 'error' => 'Unable to save payment token.'];
        }

        $readyOrder = Order::findById($orderId);
        if ($readyOrder && $readyOrder->status === Order::STATUS_SUBMITTED) {
            (new NotificationService())->notifyOrderEvent($readyOrder, 'submitted', 'staff');
            (new PushService())->notifyStaffOfNewOrder($readyOrder);
        }
        return ['success' => true];
    }

    private function saveCustomerToken(string $reference, string $token): array {
        try {
            $db = Database::getConnection();
            $db->beginTransaction();
            $setup = $db->prepare('SELECT user_id, token_hash FROM customer_payment_token_setups WHERE reference = ? AND expires_at > NOW() FOR UPDATE');
            $setup->execute([$reference]);
            $setupRow = $setup->fetch(PDO::FETCH_ASSOC);
            if (!$setupRow) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Payment method setup reference is invalid or expired.'];
            }
            if ($setupRow['token_hash'] !== null) {
                $db->rollBack();
                return hash_equals((string) $setupRow['token_hash'], hash('sha256', $token))
                    ? ['success' => true]
                    : ['success' => false, 'error' => 'A different token was already saved for this setup.'];
            }
            $customerId = (int) $setupRow['user_id'];
            $customer = Customer::findCustomerById((int) $customerId);
            if (!$customer) {
                $db->rollBack();
                return ['success' => false, 'error' => 'Customer account not found.'];
            }
            $stmt = $db->prepare(
                'INSERT INTO customer_payment_tokens (user_id, gateway, gateway_token) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE gateway = VALUES(gateway), gateway_token = VALUES(gateway_token)'
            );
            $stmt->execute([(int) $customerId, 'payfast', Payment::encryptGatewayToken($token)]);
            $db->prepare('UPDATE customer_payment_token_setups SET token_hash = ? WHERE reference = ?')
                ->execute([hash('sha256', $token), $reference]);
            $db->commit();
            return ['success' => true];
        } catch (Throwable $error) {
            if (isset($db) && $db->inTransaction()) $db->rollBack();
            error_log('PayFast customer token could not be saved: ' . get_class($error));
            return ['success' => false, 'error' => 'Unable to save payment method.'];
        }
    }

    public function getSavedCustomerToken(int $customerId): ?string {
        $stmt = Database::getConnection()->prepare('SELECT gateway_token FROM customer_payment_tokens WHERE user_id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        $token = $stmt->fetchColumn();
        return is_string($token) && $token !== '' ? Payment::decryptGatewayToken($token) : null;
    }

    public function hasSavedCustomerToken(int $customerId): bool {
        $stmt = Database::getConnection()->prepare('SELECT 1 FROM customer_payment_tokens WHERE user_id = ? LIMIT 1');
        $stmt->execute([$customerId]);
        return $stmt->fetchColumn() !== false;
    }

    // Both token setup and charge results use the same notify URL.
    public function handleWebhook(array $itn, ?string $sourceIp = null): array {
        $paymentReference = (string) ($itn['m_payment_id'] ?? '');
        if (preg_match('/^ct-[a-f0-9]{48}$/', $paymentReference) === 1) {
            return $this->handleTokenSetupWebhook($itn, $sourceIp);
        }
        $order = Order::findById(ctype_digit($paymentReference) ? (int) $paymentReference : 0);
        if ($order && $order->status === Order::STATUS_CHARGE_PENDING) {
            return $this->handleChargeWebhook($itn, $sourceIp);
        }

        return $this->handleTokenSetupWebhook($itn, $sourceIp);
    }

    // Charge the saved token after staff accept; the ITN confirms the result.
    public function chargeToken(Order $order): array {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $lockedOrder = Order::lockById($order->id);
            $payment = $lockedOrder?->getPayment();
            if (!$lockedOrder || !in_array($lockedOrder->status, [Order::STATUS_ACCEPTED, Order::STATUS_ADJUSTED], true)
                || !$payment || $payment->status !== Payment::STATUS_TOKENIZED || !$payment->gateway_token) {
                throw new RuntimeException('Order is not ready to charge.');
            }
            $fromStatus = $lockedOrder->status;
            $payment->amount = $lockedOrder->total();
            if (!$lockedOrder->canTransitionTo(Order::STATUS_CHARGE_PENDING) || !$payment->beginCharge()) {
                throw new RuntimeException('Unable to start payment charge.');
            }
            $lockedOrder->status = Order::STATUS_CHARGE_PENDING;
            if (!$lockedOrder->save() || !(new OrderStatusHistory(
                order_id: $lockedOrder->id,
                from_status: $fromStatus,
                to_status: Order::STATUS_CHARGE_PENDING,
                changed_by: $lockedOrder->staff_id
            ))->save()) {
                throw new RuntimeException('Unable to record payment status.');
            }
            $db->commit();
            $order = $lockedOrder;
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }

        $timestamp = date('c');
        $body = [
            'amount'    => (int) round($order->total() * 100),
            'item_name' => 'Food by K order #' . $order->id,
            'm_payment_id' => (string) $order->id,
            'itn' => true,
        ];

        $headers = [
            'merchant-id' => PAYFAST_MERCHANT_ID,
            'version'     => 'v1',
            'timestamp'   => $timestamp,
        ];
        $headers['signature'] = $this->generateApiSignature(array_merge($headers, $body));

        // The charge may have succeeded even if the connection timed out.
        try {
            $response = $this->postJson(
                $this->apiUrl("/subscriptions/{$payment->gateway_token}/adhoc"),
                $body, $headers
            );
        } catch (\Throwable $e) {
            // Wait for the ITN or manual reconciliation before changing status.
            error_log('PayFast charge request outcome is unknown for order ' . $order->id . ': ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment status is pending confirmation.'];
        }

        // PayFast's response acknowledges the request; the ITN gives the result.
        if (($response['status'] ?? '') !== 'success') {
            $this->markChargeFailed($order->id);
            return ['success' => false, 'error' => 'PayFast declined the charge.'];
        }
        return ['success' => true, 'data' => $response];
    }

    // Record the charge result reported by PayFast.
    public function handleChargeWebhook(array $itn, ?string $sourceIp = null): array {
        if (!$this->verifyItn($itn, $sourceIp)) {
            return ['success' => false, 'error' => 'Invalid ITN signature/source.'];
        }

        $orderId = (int) ($itn['m_payment_id'] ?? 0);
        $db = Database::getConnection();
        $event = null;
        $order = null;
        try {
            $db->beginTransaction();
            $order = Order::lockById($orderId);
            $payment = $order?->getPayment();
            if (!$order || !$payment) throw new RuntimeException('Order or payment not found.');
            if ((string) ($itn['merchant_id'] ?? '') !== (string) PAYFAST_MERCHANT_ID
                || abs((float) ($itn['amount_gross'] ?? -1) - $payment->amount) > 0.01) {
                throw new RuntimeException('Payment details do not match the order.');
            }
            if ($order->status === Order::STATUS_PAID && $payment->status === Payment::STATUS_SUCCESS) {
                $db->commit();
                return ['success' => true];
            }
            if ($order->status !== Order::STATUS_CHARGE_PENDING || $payment->status !== Payment::STATUS_CHARGE_PENDING) {
                throw new RuntimeException('Order is not awaiting a charge result.');
            }

            if (($itn['payment_status'] ?? '') === 'COMPLETE') {
                if (!$payment->markSuccessful((string) ($itn['pf_payment_id'] ?? ''))
                    || !$order->canTransitionTo(Order::STATUS_PAID)) {
                    throw new RuntimeException('Unable to record successful payment.');
                }
                $order->status = Order::STATUS_PAID;
                $event = 'paid';
            } elseif (($itn['payment_status'] ?? '') === 'FAILED') {
                if (!$payment->markFailed() || !$order->canTransitionTo(Order::STATUS_PAYMENT_FAILED)) {
                    throw new RuntimeException('Unable to record failed payment.');
                }
                $order->status = Order::STATUS_PAYMENT_FAILED;
                $event = 'payment_failed';
            } else {
                throw new RuntimeException('Unsupported PayFast payment status.');
            }
            if (!$order->save() || !(new OrderStatusHistory(
                order_id: $order->id,
                from_status: Order::STATUS_CHARGE_PENDING,
                to_status: $order->status,
                changed_by: null
            ))->save()) {
                throw new RuntimeException('Unable to record order payment status.');
            }
            if ($event === 'paid') {
                $loyalty = (new LoyaltyService())->awardPointsForOrder($order->customer_id, $order->id, $order->total());
                if (!$loyalty['success']) throw new RuntimeException('Unable to award order loyalty points.');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }

        (new NotificationService())->notifyOrderEvent($order, $event, 'customer');
        (new PushService())->notifyCustomerOfOrderStatus($order, $event);
        return ['success' => true];
    }

    // Cancel the PayFast agreement after an order is declined or cancelled.
    public function releaseToken(Order $order): void {
        $payment = $order->getPayment();
        if (!$payment || !$payment->gateway_token) return;

        $storedToken = $this->getSavedCustomerToken($order->customer_id);
        if ($storedToken !== null && hash_equals($storedToken, $payment->gateway_token)) {
            return;
        }

        $this->releaseTokenValue($payment->gateway_token);
    }

    private function releaseTokenValue(string $token): void {
        try {
            $this->postJson(
                self::apiUrl('/subscriptions/' . rawurlencode($token) . '/cancel'),
                [], $this->apiHeaders([]), 'PUT'
            );
        } catch (\Throwable $e) {
            error_log('PayFast token release failed (non-fatal).');
        }
    }

    private function generateFormSignature(array $data): string {
        unset($data['signature']);
        $pairs = [];
        foreach ($data as $key => $value) {
            if ((string) $value !== '') $pairs[] = $key . '=' . urlencode(trim((string) $value));
        }
        $paramString = implode('&', $pairs);
        if (PAYFAST_PASSPHRASE) {
            $paramString .= '&passphrase=' . urlencode(trim(PAYFAST_PASSPHRASE));
        }
        return md5($paramString);
    }

    /** Create a guarded refund against the successful charge, never against its token. */
    public function getRefundEligibility(int $orderId, mixed $amount): array {
        if ($this->sandboxEnabled()) {
            return ['success' => false, 'error' => 'Refunds are not available in sandbox mode.'];
        }
        if (!is_numeric($amount) || (float) $amount <= 0 || abs((float) $amount - round((float) $amount, 2)) > 0.00000001) {
            return ['success' => false, 'error' => 'Enter a valid refund amount with up to two decimal places.'];
        }
        if ($this->passphrase() === '') {
            return ['success' => false, 'error' => 'PayFast refunds require PAYFAST_PASSPHRASE.'];
        }
        $order = Order::findById($orderId);
        $payment = $order?->getPayment();
        if (!$order || !$payment || $payment->status !== Payment::STATUS_SUCCESS || !$payment->gateway_reference
            || in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_DECLINED], true)) {
            return ['success' => false, 'error' => 'Only a successfully paid, non-cancelled order can be refunded.'];
        }
        $amountCents = (int) round((float) $amount * 100);
        try {
            $query = $this->postJson(
                $this->apiUrl('/refunds/query/' . rawurlencode($payment->gateway_reference)),
                [], $this->apiHeaders([]), 'GET'
            );
            $details = $query['data']['response'] ?? $query['data'] ?? $query;
            $available = (int) ($details['amount_available_for_refund'] ?? -1);
            if (($query['status'] ?? '') !== 'success' || ($details['status'] ?? '') !== 'REFUNDABLE' || $amountCents > $available) {
                return ['success' => false, 'error' => 'PayFast reports that this payment is not refundable for that amount.'];
            }
            $method = $amountCents === $available
                ? ($details['refund_full']['method'] ?? 'NOT_AVAILABLE')
                : ($details['refund_partial']['method'] ?? 'NOT_AVAILABLE');
            $banks = $details['bank_names'] ?? [];
            return ['success' => true, 'data' => [
                'method' => $method,
                'amount_available_cents' => $available,
                'bank_names' => is_array($banks) ? $banks : [],
            ]];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => 'PayFast could not check refund options: ' . $e->getMessage()];
        }
    }

    public function refundOrder(int $orderId, int $staffId, mixed $amount, mixed $reason, array $bankDetails = []): array {
        if ($this->sandboxEnabled()) {
            return ['success' => false, 'error' => 'Refunds are not available in sandbox mode.'];
        }
        if (!is_numeric($amount) || !is_string($reason)) {
            return ['success' => false, 'error' => 'Enter a valid refund amount and reason.'];
        }
        $amount = (float) $amount;
        $reason = trim($reason);
        if ($amount <= 0 || strlen($reason) < 3 || strlen($reason) > 255) {
            return ['success' => false, 'error' => 'Refund amount must be positive and the reason must be 3 to 255 characters.'];
        }
        if (abs($amount - round($amount, 2)) > 0.00000001) {
            return ['success' => false, 'error' => 'Refund amount can have at most two decimal places.'];
        }
        $amount = round($amount, 2);
        $amountCents = (int) round($amount * 100);

        if ($this->passphrase() === '') {
            return ['success' => false, 'error' => 'PayFast refunds require PAYFAST_PASSPHRASE. Set the same passphrase configured in your PayFast sandbox account, then retry.'];
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $order = Order::lockById($orderId);
            $payment = $order?->getPayment();
            if (!$order || !$payment || $payment->status !== Payment::STATUS_SUCCESS
                || !$payment->gateway_reference || $order->status === Order::STATUS_CANCELLED
                || $order->status === Order::STATUS_DECLINED) {
                throw new RuntimeException('Only a successfully paid, non-cancelled order can be refunded.');
            }
            $inFlight = $db->prepare(
                "SELECT COUNT(*) FROM payment_refunds WHERE payment_id = ? AND status IN ('pending', 'submitted')"
            );
            $inFlight->execute([$payment->id]);
            if ((int) $inFlight->fetchColumn() > 0) {
                throw new RuntimeException('A refund for this payment is already being processed. Check its status before requesting another.');
            }
            $sum = $db->prepare("SELECT COALESCE(SUM(amount_cents), 0) FROM payment_refunds WHERE payment_id = ? AND status IN ('submitted', 'completed')");
            $sum->execute([$payment->id]);
            if ($amountCents + (int) $sum->fetchColumn() > (int) round($payment->amount * 100)) {
                throw new RuntimeException('The refund amount exceeds the remaining amount paid.');
            }
            $refund = new PaymentRefund((int) $payment->id, $staffId, $amountCents, $reason);
            if (!$refund->save()) throw new RuntimeException('Unable to record the refund request.');
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // PayFast requires eligibility details before each refund. If the API
        // call has an uncertain outcome, leave this request pending to prevent
        // an accidental duplicate charge-back.
        try {
            try {
                $query = $this->postJson(
                    $this->apiUrl('/refunds/query/' . rawurlencode($payment->gateway_reference)),
                    [], $this->apiHeaders([]), 'GET'
                );
            } catch (Throwable $e) {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => 'PayFast could not check whether this payment is refundable: ' . $e->getMessage()];
            }
            $details = $query['data']['response'] ?? $query['data'] ?? $query;
            $available = (int) ($details['amount_available_for_refund'] ?? -1);
            $fullMethod = $details['refund_full']['method'] ?? 'NOT_AVAILABLE';
            $partialMethod = $details['refund_partial']['method'] ?? 'NOT_AVAILABLE';
            if (($details['status'] ?? '') !== 'REFUNDABLE' || $available < $amountCents) {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => 'PayFast reports that this payment is not refundable for that amount.'];
            }
            $refundMethod = $amountCents === $available ? $fullMethod : $partialMethod;
            $body = ['amount' => $amountCents, 'reason' => $reason, 'notify_buyer' => true, 'notify_merchant' => true];
            if ($refundMethod === 'BANK_PAYOUT') {
                $holder = trim((string) ($bankDetails['bank_account_holder'] ?? ''));
                $bankName = trim((string) ($bankDetails['bank_name'] ?? ''));
                $branchCode = preg_replace('/\D+/', '', (string) ($bankDetails['bank_branch_code'] ?? ''));
                $accountNumber = preg_replace('/\D+/', '', (string) ($bankDetails['bank_account_number'] ?? ''));
                $accountType = strtolower(trim((string) ($bankDetails['bank_account_type'] ?? '')));
                $bankNames = $details['bank_names'] ?? [];
                $allowedBanks = [];
                foreach (is_array($bankNames) ? $bankNames : [] as $bank) {
                    if (is_string($bank)) $allowedBanks[] = $bank;
                    elseif (is_array($bank)) {
                        $name = $bank['bank_name'] ?? $bank['value'] ?? $bank['code'] ?? null;
                        if (is_string($name)) $allowedBanks[] = $name;
                    }
                }
                if (strlen($holder) < 2 || strlen($holder) > 255 || !in_array($bankName, $allowedBanks, true)
                    || strlen($branchCode) < 1 || strlen($branchCode) > 6
                    || strlen($accountNumber) < 1 || strlen($accountNumber) > 12
                    || !in_array($accountType, ['current', 'savings'], true)) {
                    $this->setRefundStatus((int) $refund->id, 'failed');
                    return ['success' => false, 'error' => 'Enter valid bank payout details and choose a bank offered by PayFast.'];
                }
                $body += [
                    'bank_account_holder' => $holder,
                    'bank_name' => $bankName,
                    'bank_branch_code' => $branchCode,
                    'bank_account_number' => $accountNumber,
                    'bank_account_type' => $accountType,
                ];
            } elseif ($refundMethod !== 'PAYMENT_SOURCE') {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => 'PayFast does not offer an automated refund method for this payment amount.'];
            }

            $response = $this->postJson(
                $this->apiUrl('/refunds/' . rawurlencode($payment->gateway_reference)),
                $body,
                $this->apiHeaders($body)
            );
            $accepted = $response['data']['response'] ?? null;
            if (($response['status'] ?? '') === 'failed'
                || (($response['status'] ?? '') === 'success' && in_array($accepted, [false, 0, '0'], true))) {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => $this->payFastResponseMessage($response, 'PayFast did not accept the refund.')];
            }
            if (($response['status'] ?? '') !== 'success' || !in_array($accepted, [true, 1, '1'], true)) {
                throw new RuntimeException('PayFast returned an unclear refund result.');
            }
            $this->setRefundStatus((int) $refund->id, 'submitted');
            return ['success' => true, 'data' => PaymentRefund::findById((int) $refund->id)];
        } catch (Throwable $e) {
            error_log('PayFast refund outcome is uncertain for order ' . $orderId . ', refund ' . $refund->id . ': ' . $e->getMessage());
            return ['success' => false, 'error' => 'The refund request could not be confirmed. ' . $e->getMessage() . ' Do not submit it again until its status is checked in PayFast.'];
        }
    }

    public function refreshRefundStatus(int $orderId): array {
        if ($this->sandboxEnabled()) {
            return ['success' => false, 'error' => 'Refunds are not available in sandbox mode.'];
        }
        if ($this->passphrase() === '') {
            return ['success' => false, 'error' => 'PayFast refund checks require PAYFAST_PASSPHRASE. Set the same passphrase configured in your PayFast sandbox account, then check again.'];
        }
        $order = Order::findById($orderId);
        $payment = $order?->getPayment();
        if (!$order || !$payment || !$payment->gateway_reference) {
            return ['success' => false, 'error' => 'Payment details are unavailable for refund reconciliation.'];
        }
        $refunds = PaymentRefund::findBy('payment_id', $payment->id);
        $expectedCents = array_sum(array_map(
            fn(PaymentRefund $refund) => in_array($refund->status, ['pending', 'submitted', 'completed'], true) ? $refund->amount_cents : 0,
            $refunds
        ));
        if ($expectedCents === 0) return ['success' => true, 'data' => $refunds];

        try {
            $result = $this->postJson(
                $this->apiUrl('/refunds/' . rawurlencode($payment->gateway_reference)),
                [], $this->apiHeaders([]), 'GET'
            );
            $details = $result['data']['response'] ?? $result['data'] ?? $result;
            $transactions = $details['transactions'] ?? null;
            if (($result['status'] ?? '') !== 'success' || !is_array($transactions)) {
                throw new RuntimeException('PayFast refund history is unavailable.');
            }
            $actualCents = 0;
            foreach ($transactions as $transaction) {
                if (!is_array($transaction) || stripos((string) ($transaction['type'] ?? ''), 'refund') === false) continue;
                $actualCents += (int) round(abs((float) ($transaction['amount'] ?? 0)));
            }
            if ($actualCents === $expectedCents) {
                foreach ($refunds as $refund) {
                    if (in_array($refund->status, ['pending', 'submitted'], true)) {
                        $refund->status = 'completed';
                        if (!$refund->save()) throw new RuntimeException('Unable to update refund reconciliation status.');
                    }
                }
            }
            return ['success' => true, 'data' => PaymentRefund::findBy('payment_id', $payment->id)];
        } catch (Throwable $e) {
            error_log('PayFast refund status could not be reconciled for order ' . $orderId . ': ' . $e->getMessage());
            return ['success' => false, 'error' => 'Could not verify refund settlement with PayFast: ' . $e->getMessage()];
        }
    }

    private function payFastResponseMessage(array $response, string $fallback): string {
        foreach ([$response['data']['message'] ?? null, $response['message'] ?? null, $response['data']['response']['message'] ?? null] as $message) {
            if (is_string($message) && trim($message) !== '') return trim($message);
        }
        return $fallback;
    }

    private function setRefundStatus(int $refundId, string $status): void {
        $refund = PaymentRefund::findById($refundId);
        if (!$refund) throw new RuntimeException('Refund record not found.');
        $refund->status = $status;
        if (!$refund->save()) throw new RuntimeException('Unable to update the refund record.');
    }

    private function generateApiSignature(array $data): string {
        if (PAYFAST_PASSPHRASE !== '') $data['passphrase'] = PAYFAST_PASSPHRASE;
        $data = array_filter($data, fn($value) => $value !== '' && $value !== null);
        ksort($data);
        return md5(http_build_query($data));
    }

    private function verifyItn(array $itn, ?string $sourceIp): bool {
        if (!$sourceIp || !$this->isPayFastIp($sourceIp)) {
            error_log('PayFast ITN rejected: source IP is not in the PayFast allowlist.');
            return false;
        }
        $receivedSignature = $itn['signature'] ?? '';
        // Preserve the field order PayFast used to sign the ITN.
        $paramString = $this->itnParameterString($itn);
        $expectedSignature = md5($paramString . ($this->passphrase() !== ''
            ? '&passphrase=' . urlencode(trim($this->passphrase()))
            : ''));
        if (!is_string($receivedSignature) || !hash_equals($expectedSignature, $receivedSignature)) {
            error_log('PayFast ITN rejected: signature mismatch.');
            return false;
        }

        $host = $this->sandboxEnabled() ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $paramString,
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = @file_get_contents("https://{$host}/eng/query/validate", false, $context);
        if (trim((string) $response) !== 'VALID') {
            error_log('PayFast ITN rejected: PayFast validation endpoint did not return VALID.');
            return false;
        }
        return true;
    }

    private function itnParameterString(array $itn): string {
        $pairs = [];
        foreach ($itn as $key => $value) {
            if ($key === 'signature') break;
            if (!is_scalar($value)) return '';
            $value = stripslashes((string) $value);
            $pairs[] = $key . '=' . urlencode($value);
        }
        return implode('&', $pairs);
    }

    private function passphrase(): string {
        return defined('PAYFAST_PASSPHRASE') ? (string) PAYFAST_PASSPHRASE : '';
    }

    private function isPayFastIp(string $ip): bool {
        $ranges = ['197.97.145.144/28', '41.74.179.192/27', '102.216.36.0/28', '102.216.36.128/28', '144.126.193.139/32'];
        $address = inet_pton($ip);
        if ($address === false || strlen($address) !== 4) return false;
        foreach ($ranges as $range) {
            [$network, $bits] = explode('/', $range);
            $network = inet_pton($network);
            $bits = (int) $bits;
            $wholeBytes = intdiv($bits, 8);
            $remainingBits = $bits % 8;
            if (substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) continue;
            if ($remainingBits === 0 || (ord($address[$wholeBytes]) >> (8 - $remainingBits)) === (ord($network[$wholeBytes]) >> (8 - $remainingBits))) return true;
        }
        return false;
    }

    private function apiUrl(string $path): string {
        return self::API_BASE . $path . ($this->sandboxEnabled() ? '?testing=true' : '');
    }

    private function sandboxEnabled(): bool {
        return filter_var(PAYFAST_SANDBOX, FILTER_VALIDATE_BOOLEAN);
    }

    private function apiHeaders(array $body): array {
        $headers = ['merchant-id' => PAYFAST_MERCHANT_ID, 'version' => 'v1', 'timestamp' => date('c')];
        $headers['signature'] = $this->generateApiSignature(array_merge($headers, $body));
        return $headers;
    }

    private function markChargeFailed(int $orderId): void {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $order = Order::lockById($orderId);
            $payment = $order?->getPayment();
            if ($order && $payment && $order->status === Order::STATUS_CHARGE_PENDING
                && $payment->markFailed() && $order->canTransitionTo(Order::STATUS_PAYMENT_FAILED)) {
                $order->status = Order::STATUS_PAYMENT_FAILED;
                if (!$order->save() || !(new OrderStatusHistory(order_id: $order->id,
                    from_status: Order::STATUS_CHARGE_PENDING, to_status: Order::STATUS_PAYMENT_FAILED,
                    changed_by: null))->save()) throw new RuntimeException('Unable to record failed payment.');
            }
            $db->commit();
        } catch (Throwable) {
            if ($db->inTransaction()) $db->rollBack();
        }
    }

    private function postJson(string $url, array $body, array $headers, string $method = 'POST'): array {
        $headerLines = array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers);
        $method = strtoupper($method);
        $requestHeaders = implode("\r\n", $headerLines);
        $options = [
            'method' => $method,
            'header' => $requestHeaders . ($method === 'GET' ? "\r\n" : "\r\nContent-Type: application/json\r\n"),
            'timeout' => 20,
            'ignore_errors' => true,
        ];
        if ($method !== 'GET') $options['content'] = json_encode($body, JSON_THROW_ON_ERROR);
        $context = stream_context_create(['http' => [
            ...$options,
        ]]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) throw new \Exception('PayFast API request failed.');
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) throw new \Exception('PayFast returned an invalid response.');
        if (($decoded['status'] ?? '') === 'failed') {
            throw new RuntimeException($this->payFastResponseMessage($decoded, 'PayFast rejected the API request.'));
        }
        return $decoded;
    }

}
