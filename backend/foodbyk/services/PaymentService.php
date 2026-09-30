<?php

class PaymentService {

    const TOKENIZE_URL_SANDBOX = 'https://sandbox.payfast.co.za/eng/process';
    const TOKENIZE_URL_LIVE    = 'https://www.payfast.co.za/eng/process';
    const API_BASE             = 'https://api.payfast.co.za';

    // Step 1: build the redirect that sets up a R0 tokenization agreement.
    // No money moves here - this is PayFast's "ad hoc" agreement setup,
    // not a payment. The token itself arrives later via ITN (step 2).
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
            'subscription_type' => 2, // ad hoc tokenization, not a recurring subscription
        ];
        $fields['signature'] = $this->generateFormSignature($fields);

        return ['success' => true, 'data' => [
            'redirect_url' => $this->sandboxEnabled() ? self::TOKENIZE_URL_SANDBOX : self::TOKENIZE_URL_LIVE,
            'fields'       => $fields,
        ]];
    }

    // Step 2: ITN webhook for the tokenization setup. Extracts the token
    // and creates the order's Payment row - see DOMAIN.md §6.
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
                $db->commit(); // Duplicate token ITN; never replace the bearer token.
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

    // PayFast sends token setup and ad hoc charge ITNs to the notify URL
    // supplied during token setup. The order state distinguishes the two.
    public function handleWebhook(array $itn, ?string $sourceIp = null): array {
        $order = Order::findById((int) ($itn['m_payment_id'] ?? 0));
        if ($order && $order->status === Order::STATUS_CHARGE_PENDING) {
            return $this->handleChargeWebhook($itn, $sourceIp);
        }

        return $this->handleTokenSetupWebhook($itn, $sourceIp);
    }

    // Step 3: called from OrderService::confirmOrder() (or a follow-up
    // job) once staff accept. Sends the actual charge request; the
    // outcome is confirmed asynchronously in handleChargeWebhook().
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
            'amount'    => (int) round($order->total() * 100), // PayFast adhoc API takes cents
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

        // An ambiguous network failure must stay pending: PayFast may have
        // processed the charge before the connection dropped.
        try {
            $response = $this->postJson(
                $this->apiUrl("/subscriptions/{$payment->gateway_token}/adhoc"),
                $body, $headers
            );
        } catch (\Throwable $e) {
            // A timeout may happen after PayFast charged the card. Keep the
            // order pending until the ITN or a manual reconciliation arrives.
            error_log('PayFast charge request outcome is unknown for order ' . $order->id . ': ' . $e->getMessage());
            return ['success' => false, 'error' => 'Payment status is pending confirmation.'];
        }

        // Immediate response only confirms PayFast accepted the request -
        // final success/failure still comes via handleChargeWebhook().
        if (($response['status'] ?? '') !== 'success') {
            $this->markChargeFailed($order->id);
            return ['success' => false, 'error' => 'PayFast declined the charge.'];
        }
        return ['success' => true, 'data' => $response];
    }

    // Step 4: ITN webhook confirming the actual charge outcome.
    // Idempotent via Payment::markSuccessful()'s internal guard.
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

    // Best-effort cleanup on decline/cancel - functionally the token is
    // already dead the moment we stop calling adhoc against it, but
    // cancelling the agreement on PayFast's side keeps their dashboard
    // and ours in sync.
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
        $expectedSignature = $this->generateFormSignature($itn);
        if (!is_string($receivedSignature) || !hash_equals($expectedSignature, $receivedSignature)) {
            error_log('PayFast ITN rejected: signature mismatch.');
            return false;
        }

        $host = $this->sandboxEnabled() ? 'sandbox.payfast.co.za' : 'www.payfast.co.za';
        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(array_diff_key($itn, ['signature' => true])),
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
