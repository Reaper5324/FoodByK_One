<?php

class AnalyticsController extends Controller {
    public function staffSummary(Request $request): Response {
        return $this->respond((new AnalyticsService())->dashboardSummary());
    }

    public function adminSummary(Request $request): Response {
        $service = new AnalyticsService();
        $period = (string) ($request->query['period'] ?? '');
        return $this->respond($period === ''
            ? $service->dashboardSummary()
            : $service->reportSummary($period));
    }
}
