<?php

class CheckoutController extends Controller {

    public function preview(Request $request): Response {
        $fulfilmentType = $request->input('fulfilment_type', '');
        $rawAddressId = $request->input('address_id');
        $addressId = $rawAddressId === null ? null : InputValidator::positiveInteger($rawAddressId);
        if (!is_string($fulfilmentType) || ($rawAddressId !== null && $addressId === null)) {
            return Response::error('Choose a valid fulfilment option and address.', 422);
        }
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
            $fulfilmentType,
            $addressId,
            $promotionCode,
            (float) $points
        ));
    }

    public function submit(Request $request): Response {
        $fulfilmentType = $request->input('fulfilment_type', '');
        $rawAddressId = $request->input('address_id');
        $addressId = $rawAddressId === null ? null : InputValidator::positiveInteger($rawAddressId);
        if (!is_string($fulfilmentType) || ($rawAddressId !== null && $addressId === null)) {
            return Response::error('Choose a valid fulfilment option and address.', 422);
        }
        $points = $request->input('loyalty_points_to_redeem', 0);
        $promotionCode = $request->input('promotion_code');
        $quoteFingerprint = $request->input('quote_fingerprint');
        if ($promotionCode !== null && !is_string($promotionCode)) {
            return Response::error('Enter one valid promotion code.', 400);
        }
        if (!is_numeric($points) || !is_finite((float) $points)) {
            return Response::error('Invalid loyalty points amount.', 400);
        }
        if (!is_string($quoteFingerprint) || preg_match('/^[a-f0-9]{64}$/D', $quoteFingerprint) !== 1) {
            return Response::error('Review the current order total before continuing.', 422);
        }
        return $this->respond((new CheckoutService())->submitOrder(
            $request->user()->id,
            $fulfilmentType,
            $addressId,
            (string) $request->input('requested_window_start', ''),
            (string) $request->input('requested_window_end', ''),
            $promotionCode,
            (float) $points,
            $quoteFingerprint
        ), 201);
    }

    public function slots(Request $request): Response {
        return $this->respond((new SlotService())->getAvailableSlotsForDate(
            (string) ($request->query['date'] ?? '')
        ));
    }
}
