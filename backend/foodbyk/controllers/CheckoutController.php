<?php

class CheckoutController extends Controller {

    public function preview(Request $request): Response {
        $points = $request->input('loyalty_points_to_redeem', 0);
        $promotionCode = $request->input('promotion_code');
        if ($promotionCode !== null && !is_string($promotionCode)) {
            return Response::error('Enter one valid promotion code.', 400);
        }
        if (!is_numeric($points) || !is_finite((float) $points)) {
            return Response::error('Invalid loyalty points amount.', 400);
        }
        return $this->respond((new CheckoutService())->getCheckoutPreview(
            $request->user()->id,
            (string) $request->input('fulfilment_type', ''),
            $request->input('address_id') === null ? null : (int) $request->input('address_id'),
            $promotionCode,
            (float) $points
        ));
    }

    public function submit(Request $request): Response {
        $points = $request->input('loyalty_points_to_redeem', 0);
        $promotionCode = $request->input('promotion_code');
        if ($promotionCode !== null && !is_string($promotionCode)) {
            return Response::error('Enter one valid promotion code.', 400);
        }
        if (!is_numeric($points) || !is_finite((float) $points)) {
            return Response::error('Invalid loyalty points amount.', 400);
        }
        return $this->respond((new CheckoutService())->submitOrder(
            $request->user()->id,
            (string) $request->input('fulfilment_type', ''),
            $request->input('address_id') === null ? null : (int) $request->input('address_id'),
            (string) $request->input('requested_window_start', ''),
            (string) $request->input('requested_window_end', ''),
            $promotionCode,
            (float) $points
        ), 201);
    }

    public function slots(Request $request): Response {
        return $this->respond((new SlotService())->getAvailableSlotsForDate(
            (string) ($request->query['date'] ?? '')
        ));
    }
}
