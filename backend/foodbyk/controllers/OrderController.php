<?php

class OrderController extends Controller {

    public function index(Request $request): Response {
        return $this->respond((new OrderService())->getCustomerOrders(
            $request->user()->id,
            max(1, (int) ($request->query['limit'] ?? 20)),
            max(0, (int) ($request->query['offset'] ?? 0))
        ));
    }

    public function show(Request $request, array $params): Response {
        return $this->respond(
            (new OrderService())->getOrderById((int) $this->param($params, 'id'), $request->user()->id),
            200,
            404
        );
    }

    public function incoming(Request $request): Response {
        return $this->respond((new OrderService())->getPendingOrdersForStaffDashboard());
    }

    public function staffIndex(Request $request): Response {
        return $this->respond((new OrderService())->getOrdersForStaffDashboard());
    }

    public function confirm(Request $request, array $params): Response {
        $start = $request->input('confirmed_window_start');
        $end = $request->input('confirmed_window_end');
        if (($start !== null && !is_string($start)) || ($end !== null && !is_string($end))) {
            return Response::error('Invalid confirmed time window.', 400);
        }
        return $this->respond((new OrderService())->confirmOrder(
            (int) $this->param($params, 'id'), $request->user()->id,
            $start,
            $end
        ));
    }

    public function decline(Request $request, array $params): Response {
        return $this->respond((new OrderService())->declineOrder((int) $this->param($params, 'id'), $request->user()->id, $request->input('reason')));
    }

    public function adjust(Request $request, array $params): Response {
        $items = $request->input('adjusted_items');
        if ($items !== null && !is_array($items)) return Response::error('Adjusted items must be an array.', 400);
        return $this->respond((new OrderService())->adjustOrder(
            (int) $this->param($params, 'id'),
            $request->user()->id,
            $items,
            is_string($request->input('confirmed_window_start')) ? $request->input('confirmed_window_start') : null,
            is_string($request->input('confirmed_window_end')) ? $request->input('confirmed_window_end') : null
        ));
    }

    public function cancel(Request $request, array $params): Response {
        // Customer-initiated - staffId null. See earlier fix: OrderService::cancelOrder()
        // must verify $order->customer_id against this before allowing it.
        return $this->respond((new OrderService())->cancelOrder((int) $this->param($params, 'id'), $request->user()->id, null, $request->input('reason')));
    }

    public function advance(Request $request, array $params): Response {
        $status = $request->input('status');
        if (!is_string($status)) return Response::error('Invalid order status.', 400);
        return $this->respond((new OrderService())->advanceFulfilment((int) $this->param($params, 'id'), $request->user()->id, $status));
    }
}
