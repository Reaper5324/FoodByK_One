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
            'notify_url'        => PAYFAST_NOTIFY_URL . '/token-setup',
            'name_first'        => explode(' ', $customer->full_name)[0],
            'email_address'     => $customer->email,
            'm_payment_id'      => (string) $order->id,
            'amount'            => '0.00',
            'item_name'         => 'Food by K - card setup for order #' . $order->id,
            'subscription_type' => 2, // ad hoc tokenization, not a recurring subscription
        ];
        $fields['signature'] = $this->generateSignature($fields);

        return ['success' => true, 'data' => [
            'redirect_url' => PAYFAST_SANDBOX ? self::TOKENIZE_URL_SANDBOX : self::TOKENIZE_URL_LIVE,
            'fields'       => $fields,
        ]];
    }

    // Step 2: ITN webhook for the tokenization setup. Extracts the token
    // and creates the order's Payment row - see DOMAIN.md §6.
    public function handleTokenSetupWebhook(array $itn): array {
        if (!$this->verifyItn($itn)) {
            return ['success' => false, 'error' => 'Invalid ITN signature/source.'];
        }

        $orderId = (int) ($itn['m_payment_id'] ?? 0);
        $token   = $itn['token'] ?? null;
        $order   = Order::findById($orderId);

        if (!$order || !$token) {
            return ['success' => false, 'error' => 'Order or token missing from ITN.'];
        }

        $payment = $order->getPayment();
        if (!$payment) {
            $payment = new Payment(order_id: $order->id, amount: $order->total());
        }
        $payment->gateway_token = $token; // encrypt-at-rest handled at the DB/column layer
        $payment->status        = Payment::STATUS_TOKENIZED;
        $payment->save();

        return ['success' => true];
    }

    // Step 3: called from OrderService::confirmOrder() (or a follow-up
    // job) once staff accept. Sends the actual charge request; the
    // outcome is confirmed asynchronously in handleChargeWebhook().
    public function chargeToken(Order $order): array {
        $payment = $order->getPayment();
        if (!$payment || $payment->status !== Payment::STATUS_TOKENIZED) {
            return ['success' => false, 'error' => 'No tokenized payment available to charge.'];
        }

        $payment->beginCharge(); // guards against double-firing this call

        $timestamp = date('c');
        $body = [
            'amount'    => (int) round($order->total() * 100), // PayFast adhoc API takes cents
            'item_name' => 'Food by K order #' . $order->id,
        ];

        $headers = [
            'merchant-id' => PAYFAST_MERCHANT_ID,
            'version'     => 'v1',
            'timestamp'   => $timestamp,
        ];
        $headers['signature'] = $this->generateSignature(array_merge($headers, $body));

        // Actual HTTP call - wrapped so a network failure doesn't leave the
        // payment stuck in charge_pending indefinitely without a record of why.
        try {
            $response = $this->postJson(
                self::API_BASE . "/subscriptions/{$payment->gateway_token}/adhoc",
                $body, $headers
            );
        } catch (\Throwable $e) {
            $payment->markFailed();
            return ['success' => false, 'error' => 'Could not reach PayFast: ' . $e->getMessage()];
        }

        // Immediate response only confirms PayFast accepted the request -
        // final success/failure still comes via handleChargeWebhook().
        return ['success' => true, 'data' => $response];
    }

    // Step 4: ITN webhook confirming the actual charge outcome.
    // Idempotent via Payment::markSuccessful()'s internal guard.
    public function handleChargeWebhook(array $itn): array {
        if (!$this->verifyItn($itn)) {
            return ['success' => false, 'error' => 'Invalid ITN signature/source.'];
        }

        $orderId = (int) ($itn['m_payment_id'] ?? 0);
        $order   = Order::findById($orderId);
        if (!$order) return ['success' => false, 'error' => 'Order not found.'];

        $payment = $order->getPayment();
        if (!$payment) return ['success' => false, 'error' => 'No payment record for this order.'];

        if (($itn['payment_status'] ?? '') === 'COMPLETE') {
            $payment->markSuccessful($itn['pf_payment_id'] ?? '');
        } else {
            $payment->markFailed();
        }

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
                self::API_BASE . "/subscriptions/{$payment->gateway_token}/cancel",
                [], ['merchant-id' => PAYFAST_MERCHANT_ID, 'version' => 'v1', 'timestamp' => date('c')]
            );
        } catch (\Throwable $e) {
            error_log('PayFast token release failed (non-fatal): ' . $e->getMessage());
        }
    }

    // NOTE: field order/encoding here must be verified against PayFast's
    // current API docs before going live - this is the single most
    // common integration bug in PayFast implementations. Treat this as a
    // draft to confirm against a real sandbox call, not copy-paste-safe.
    private function generateSignature(array $data): string {
        unset($data['signature']);
        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = $key . '=' . urlencode((string) $value);
        }
        $paramString = implode('&', $pairs);
        if (PAYFAST_PASSPHRASE) {
            $paramString .= '&passphrase=' . urlencode(PAYFAST_PASSPHRASE);
        }
        return md5($paramString);
    }

    // Real ITN verification needs: (1) signature check, (2) source IP is
    // a PayFast IP, (3) a server-to-server validate call back to PayFast.
    // Only (1) is implemented here - (2) and (3) are required before
    // production, flagged rather than silently skipped.
    private function verifyItn(array $itn): bool {
        $receivedSignature = $itn['signature'] ?? '';
        $expectedSignature = $this->generateSignature($itn);
        return hash_equals($expectedSignature, $receivedSignature);
        // TODO before production: verify source IP against PayFast's
        // published IP ranges, and call the /eng/query/validate endpoint.
    }

    private function postJson(string $url, array $body, array $headers): array {
        $headerLines = array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers);
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headerLines) . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode($body),
            'timeout' => 10,
        ]]);
        $response = file_get_contents($url, false, $context);
        if ($response === false) throw new \Exception('PayFast API request failed.');
        return json_decode($response, true) ?? [];
    }

}