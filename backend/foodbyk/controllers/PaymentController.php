<?php

class PaymentController extends Controller {

    // Server-to-server ITN webhooks - no session/CSRF, verified by signature.
    public function tokenWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleTokenSetupWebhook($request->body));
    }

    public function chargeWebhook(Request $request): Response {
        return $this->respond((new PaymentService())->handleChargeWebhook($request->body));
    }

    // The browser itself lands here after PayFast's tokenization redirect
    // completes - a real navigation, not a fetch() call, so this must
    // issue an HTTP redirect rather than return JSON. The actual token
    // isn't confirmed here (that's the async tokenWebhook above) - this
    // just sends the customer back to a frontend page reflecting that.
    public function returnFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(FRONTEND_URL . "/checkout/pending?order_id={$orderId}");
    }

    public function cancelFromPayFast(Request $request): Response {
        $orderId = (int) ($request->query['order_id'] ?? 0);
        return $this->redirect(FRONTEND_URL . "/checkout/cancelled?order_id={$orderId}");
    }
}