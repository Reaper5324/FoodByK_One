<?php

class PaymentController extends Controller {

    // Server-to-server ITN webhooks - no session/CSRF, validated by PaymentService.
    public function tokenWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleWebhook($request->body, $this->clientIp($request)));
    }

    public function chargeWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleChargeWebhook($request->body, $this->clientIp($request)));
    }

    private function clientIp(Request $request): ?string {
        // Railway's HTTP edge forwards the original client IP in X-Real-IP;
        // REMOTE_ADDR is the internal proxy address in the deployed container.
        $ip = $request->header('X-Real-IP') ?? ($_SERVER['HTTP_X_REAL_IP'] ?? null);
        if (is_string($ip) && filter_var(trim($ip), FILTER_VALIDATE_IP)) {
            return trim($ip);
        }

        $remoteIp = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($remoteIp) && filter_var($remoteIp, FILTER_VALIDATE_IP) ? $remoteIp : null;
    }

    // The browser itself lands here after PayFast's tokenization redirect
    // completes - a real navigation, not a fetch() call, so this must
    // issue an HTTP redirect rather than return JSON. The actual token
    // isn't confirmed here (that's the async tokenWebhook above) - this
    // just sends the customer back to a frontend page reflecting that.
    public function returnFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(rtrim(FRONTEND_URL, '/') . "/pages/customer/orders.html?payment=pending&order_id={$orderId}");
    }

    public function cancelFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(rtrim(FRONTEND_URL, '/') . "/pages/customer/orders.html?payment=cancelled&order_id={$orderId}");
    }
}
