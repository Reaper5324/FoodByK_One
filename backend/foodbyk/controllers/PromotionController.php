<?php

class PromotionController extends Controller {

    public function active(Request $request): Response {
        return $this->respond((new PromotionService())->listActive());
    }
}
