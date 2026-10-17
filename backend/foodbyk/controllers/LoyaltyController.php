<?php

class LoyaltyController extends Controller {

    public function balance(Request $request): Response {
        return $this->respond((new LoyaltyService())->getBalance($request->user()->id));
    }

    public function history(Request $request): Response {
        return $this->respond((new LoyaltyService())->getOrderHistory(
            $request->user()->id,
            max(1, min(100, (int) ($request->query['limit'] ?? 50))),
            max(0, (int) ($request->query['offset'] ?? 0))
        ));
    }
}
