<?php

class PushController extends Controller {
    public function publicKey(Request $request): Response {
        $service = new PushService();
        return Response::success([
            'enabled' => $service->available(),
            'public_key' => $service->available() ? $service->publicKey() : null,
        ]);
    }

    public function subscribe(Request $request): Response {
        $subscription = $request->input('subscription');
        if (!is_array($subscription)) return Response::error('A browser notification subscription is required.', 400);
        return $this->respond((new PushService())->saveSubscription((int) $request->user()->id, $subscription));
    }

    public function unsubscribe(Request $request): Response {
        return $this->respond((new PushService())->removeSubscription(
            (int) $request->user()->id,
            (string) $request->input('endpoint', '')
        ));
    }

    public function unacknowledgedOrders(Request $request): Response {
        return $this->respond((new PushService())->unacknowledgedOrdersForUser((int) $request->user()->id));
    }

    public function acknowledgeOrder(Request $request, array $params): Response {
        return $this->respond((new PushService())->acknowledge((int) $this->param($params, 'id'), (int) $request->user()->id));
    }
}
