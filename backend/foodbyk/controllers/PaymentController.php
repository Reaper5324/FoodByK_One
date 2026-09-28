<?php

class PaymentController extends Controller {

    // Server-to-server ITN webhooks - no session/CSRF, validated by PaymentService.
    public function tokenWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleWebhook($request->body, $_SERVER['REMOTE_ADDR'] ?? null));
    }

    public function chargeWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleChargeWebhook($request->body, $_SERVER['REMOTE_ADDR'] ?? null));
    }

    // The browser itself lands here after PayFast's tokenization redirect
    // completes - a real navigation, not a fetch() call, so this must
    // issue an HTTP redirect rather than return JSON. The actual token
    // isn't confirmed here (that's the async tokenWebhook above) - this
    // just sends the customer back to a frontend page reflecting that.
    public function returnFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(rtrim(FRONTEND_URL, '/') . "/src/index.html?payment=pending&order_id={$orderId}");
    }

    public function cancelFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(rtrim(FRONTEND_URL, '/') . "/src/index.html?payment=cancelled&order_id={$orderId}");
    }
}
