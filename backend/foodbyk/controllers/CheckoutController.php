<?php

class CheckoutController extends Controller {

    public function preview(Request $request): Response {
        return $this->respond((new CheckoutService())->getCheckoutPreview(
            $request->user()->id,
            (string) $request->input('fulfilment_type', ''),
            $request->input('address_id') === null ? null : (int) $request->input('address_id'),
            $request->input('promotion_code') === null ? null : (string) $request->input('promotion_code')
        ));
    }

    public function submit(Request $request): Response {
        return $this->respond((new CheckoutService())->submitOrder(
            $request->user()->id,
            (string) $request->input('fulfilment_type', ''),
            $request->input('address_id') === null ? null : (int) $request->input('address_id'),
            (string) $request->input('requested_window_start', ''),
            (string) $request->input('requested_window_end', ''),
            $request->input('promotion_code') === null ? null : (string) $request->input('promotion_code')
        ), 201);
    }

        public function slots(Request $request): Response {
        $dateParam = (string) ($request->query['date'] ?? '');
        try {
            $date = new \DateTimeImmutable($dateParam !== '' ? $dateParam : 'today');
        } catch (\Exception) {
            return Response::error('Invalid date.', 400);
        }
        return $this->respond((new SlotService())->getAvailableSlots($date));
    }
}
