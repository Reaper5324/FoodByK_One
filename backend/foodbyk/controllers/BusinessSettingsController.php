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
            ],
            'error' => null,
        ]);
    }
}
