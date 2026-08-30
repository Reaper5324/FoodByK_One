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
}
