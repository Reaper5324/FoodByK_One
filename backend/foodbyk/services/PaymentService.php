<?php

class PaymentService {

    const TOKENIZE_URL_SANDBOX = 'https://sandbox.payfast.co.za/eng/process';
    const TOKENIZE_URL_LIVE    = 'https://www.payfast.co.za/eng/process';
    const API_BASE             = 'https://api.payfast.co.za';

    // Start card tokenization; PayFast sends the token later in an ITN.
    public function beginTokenSetup(Order $order, Customer $customer): array {
        $fields = [
            'merchant_id'       => PAYFAST_MERCHANT_ID,
            'merchant_key'      => PAYFAST_MERCHANT_KEY,
            'return_url'        => PAYFAST_RETURN_URL . '?order_id=' . $order->id,
            'cancel_url'        => PAYFAST_CANCEL_URL . '?order_id=' . $order->id,
            'notify_url'        => rtrim(PAYFAST_NOTIFY_URL, '/') . '/payments/token-webhook',
            'name_first'        => explode(' ', $customer->full_name)[0],
            'email_address'     => $customer->email,
            'm_payment_id'      => (string) $order->id,
            'amount'            => '0.00',
            'item_name'         => 'Food by K - card setup for order #' . $order->id,
            'subscription_type' => 2,
        ];
        $fields['signature'] = $this->generateFormSignature($fields);

        return ['success' => true, 'data' => [
            'redirect_url' => $this->sandboxEnabled() ? self::TOKENIZE_URL_SANDBOX : self::TOKENIZE_URL_LIVE,
            'fields'       => $fields,
        ]];
    }

    // Save the token returned by PayFast after the customer authorizes it.
    public function handleTokenSetupWebhook(array $itn, ?string $sourceIp = null): array {
        if (!$this->verifyItn($itn, $sourceIp)) {
            return ['success' => false, 'error' => 'Invalid ITN signature/source.'];
        }

        $orderId = (int) ($itn['m_payment_id'] ?? 0);
        $token   = $itn['token'] ?? null;
        $order   = Order::findById($orderId);

        if (!$order || !is_string($token) || $token === '' || ($itn['payment_status'] ?? '') !== 'COMPLETE'
            || (string) ($itn['merchant_id'] ?? '') !== (string) PAYFAST_MERCHANT_ID) {
            error_log('PayFast token ITN rejected: order, token, completion status, or merchant ID is missing or invalid.');
            return ['success' => false, 'error' => 'Order or token missing from ITN.'];
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();
            $order = Order::lockById($orderId);
            if (!$order || $order->status !== Order::STATUS_SUBMITTED) {
                throw new RuntimeException('Order is not awaiting token setup.');
            }
            $payment = $order->getPayment();
            if (!$payment) {
                throw new RuntimeException('Payment setup was not recorded for this order.');
            }
            if ($payment->gateway_token !== null) {
                // Do not replace the saved token when PayFast retries an ITN.
                $db->commit();
                return ['success' => true];
            }
            $payment->gateway_token = $token;
            if (!$payment->save()) {
                throw new RuntimeException('Unable to save payment token.');
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            error_log('PayFast token ITN could not be saved: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true];
    }

    // Both token setup and charge results use the same notify URL.
    public function handleWebhook(array $itn, ?string $sourceIp = null): array {
        $order = Order::findById((int) ($itn['m_payment_id'] ?? 0));
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
        return ['success' => true];
    }

    // Cancel the PayFast agreement after an order is declined or cancelled.
    public function releaseToken(Order $order): void {
        $payment = $order->getPayment();
        if (!$payment || !$payment->gateway_token) return;

        try {
            $this->postJson(
                self::apiUrl("/subscriptions/{$payment->gateway_token}/cancel"),
                [], $this->apiHeaders([]), 'PUT'
            );
        } catch (\Throwable $e) {
            error_log('PayFast token release failed (non-fatal): ' . $e->getMessage());
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
    public function refundOrder(int $orderId, int $staffId, mixed $amount, mixed $reason): array {
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
            $query = $this->postJson(
                $this->apiUrl('/refunds/query/' . rawurlencode($payment->gateway_reference)),
                [], $this->apiHeaders([]), 'GET'
            );
            $details = $query['data']['response'] ?? $query['data'] ?? $query;
            $available = (int) ($details['amount_available_for_refund'] ?? -1);
            $fullMethod = $details['refund_full']['method'] ?? 'NOT_AVAILABLE';
            $partialMethod = $details['refund_partial']['method'] ?? 'NOT_AVAILABLE';
            if (($details['status'] ?? '') !== 'REFUNDABLE' || $available < $amountCents) {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => 'PayFast reports that this payment is not refundable for that amount.'];
            }
            $refundMethod = $amountCents === $available ? $fullMethod : $partialMethod;
            if ($refundMethod !== 'PAYMENT_SOURCE') {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => 'PayFast requires a bank payout for this payment. Process it in PayFast and record it there; bank details are not collected by this platform.'];
            }

            $response = $this->postJson(
                $this->apiUrl('/refunds/' . rawurlencode($payment->gateway_reference)),
                ['amount' => $amountCents, 'reason' => $reason, 'notify_buyer' => true, 'notify_merchant' => true],
                $this->apiHeaders(['amount' => $amountCents, 'reason' => $reason, 'notify_buyer' => true, 'notify_merchant' => true])
            );
            $accepted = $response['data']['response'] ?? null;
            if (($response['status'] ?? '') === 'failed'
                || (($response['status'] ?? '') === 'success' && in_array($accepted, [false, 0, '0'], true))) {
                $this->setRefundStatus((int) $refund->id, 'failed');
                return ['success' => false, 'error' => $response['data']['message'] ?? 'PayFast did not accept the refund.'];
            }
            if (($response['status'] ?? '') !== 'success' || !in_array($accepted, [true, 1, '1'], true)) {
                throw new RuntimeException('PayFast returned an unclear refund result.');
            }
            $this->setRefundStatus((int) $refund->id, 'submitted');
            return ['success' => true, 'data' => PaymentRefund::findById((int) $refund->id)];
        } catch (Throwable $e) {
            error_log('PayFast refund outcome is uncertain for order ' . $orderId . ', refund ' . $refund->id . ': ' . $e->getMessage());
            return ['success' => false, 'error' => 'The refund request could not be confirmed. Do not submit it again until its status is checked in PayFast.'];
        }
    }

    public function refreshRefundStatus(int $orderId): array {
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
            return ['success' => false, 'error' => 'Could not verify refund settlement with PayFast. Check the transaction in PayFast.'];
        }
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
        $context = stream_context_create(['http' => [
            'method'  => $method,
            'header'  => implode("\r\n", $headerLines) . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) throw new \Exception('PayFast API request failed.');
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) throw new \Exception('PayFast returned an invalid response.');
        return $decoded;
    }

}
