<?php

class OrderController extends Controller {

    public function incoming(Request $request): Response {
        return $this->respond((new OrderService())->getPendingOrdersForStaffDashboard());
    }

    public function confirm(Request $request, array $params): Response {
        return $this->respond((new OrderService())->confirmOrder(
            (int) $this->param($params, 'id'), $request->user()->id,
            $request->input('confirmed_window_start'), $request->input('confirmed_window_end')
        ));
    }

    public function decline(Request $request, array $params): Response {
        return $this->respond((new OrderService())->declineOrder((int) $this->param($params, 'id'), $request->user()->id, $request->input('reason')));
    }

    public function cancel(Request $request, array $params): Response {
        // Customer-initiated - staffId null. See earlier fix: OrderService::cancelOrder()
        // must verify $order->customer_id against this before allowing it.
        return $this->respond((new OrderService())->cancelOrder((int) $this->param($params, 'id'), $request->user()->id, null, $request->input('reason')));
    }

    public function advance(Request $request, array $params): Response {
        return $this->respond((new OrderService())->advanceFulfilment((int) $this->param($params, 'id'), $request->user()->id, $request->input('status')));
    }
}
