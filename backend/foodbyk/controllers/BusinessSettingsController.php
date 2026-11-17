<?php

class BusinessSettingsController extends Controller {
    public function publicSettings(Request $request): Response {
        $settings = BusinessSettings::current();
        return $this->respond([
            'success' => true,
            'data' => [
                'business_phone' => $settings->business_phone,
                'business_address' => $settings->business_address,
                'trading_hours' => BusinessSettings::tradingSchedule(),
                'ordering_enabled' => $settings->ordering_enabled,
                'delivery_enabled' => $settings->delivery_enabled,
                'collection_enabled' => $settings->collection_enabled,
            ],
            'error' => null,
        ]);
    }
}
