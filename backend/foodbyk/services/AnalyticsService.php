<?php

class AnalyticsService {


    public function dashboardSummary(): array {
        $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
        $tomorrow = $today->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        $start = $today->setTimezone($utc)->format('Y-m-d H:i:s');
        $end = $tomorrow->setTimezone($utc)->format('Y-m-d H:i:s');

        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT
                (SELECT COUNT(*) FROM orders WHERE status = 'submitted') AS pending_orders,
                (SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ?) AS orders_today,
                (SELECT COUNT(*) FROM orders WHERE status = 'completed' AND updated_at >= ? AND updated_at < ?) AS completed_today,
                (SELECT COUNT(*) FROM payments WHERE status = 'success' AND charged_at >= ? AND charged_at < ?) AS paid_orders_today,
                (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'success' AND charged_at >= ? AND charged_at < ?) AS revenue_today"
        );
        $stmt->execute([$start, $end, $start, $end, $start, $end, $start, $end]);
        $summary = $stmt->fetch();
        $revenue = (float) ($summary['revenue_today'] ?? 0);

        return $this->success([
            'pending_orders' => (int) ($summary['pending_orders'] ?? 0),
            'orders_today' => (int) ($summary['orders_today'] ?? 0),
            'completed_today' => (int) ($summary['completed_today'] ?? 0),
            'revenue_today' => round($revenue, 2),
            'paid_orders_today' => (int) ($summary['paid_orders_today'] ?? 0),
            'average_paid_order_today' => (int) ($summary['paid_orders_today'] ?? 0) > 0
                ? round($revenue / (int) $summary['paid_orders_today'], 2)
                : 0.0,
            'date' => $today->format('Y-m-d'),
        ]);
    }

    public function reportSummary(string $period = 'week'): array {
        $today = new DateTimeImmutable('today', new DateTimeZone('Africa/Johannesburg'));
        $start = match ($period) {
            'today' => $today,
            'week' => $today->modify('-6 days'),
            'month' => $today->modify('first day of this month'),
            'year' => $today->setDate((int) $today->format('Y'), 1, 1),
            default => null,
        };
        if ($start === null) {
            return $this->failure('Invalid report period.');
        }
        $end = $today->modify('+1 day');
        $utc = new DateTimeZone('UTC');
        $from = $start->setTimezone($utc)->format('Y-m-d H:i:s');
        $until = $end->setTimezone($utc)->format('Y-m-d H:i:s');

        $db = Database::getConnection();
        $orders = $db->prepare('SELECT COUNT(*) FROM orders WHERE created_at >= ? AND created_at < ?');
        $orders->execute([$from, $until]);
        $orderCount = (int) $orders->fetchColumn();

        $payments = $db->prepare(
            "SELECT COUNT(*) AS paid_orders, COALESCE(SUM(amount), 0) AS sales
             FROM payments WHERE status = 'success' AND charged_at >= ? AND charged_at < ?"
        );
        $payments->execute([$from, $until]);
        $totals = $payments->fetch();
        $paidCount = (int) ($totals['paid_orders'] ?? 0);
        $sales = (float) ($totals['sales'] ?? 0);

        $daily = $db->prepare(
            "SELECT DATE(CONVERT_TZ(charged_at, '+00:00', '+02:00')) AS date,
                    COUNT(*) AS paid_orders, COALESCE(SUM(amount), 0) AS sales
             FROM payments WHERE status = 'success' AND charged_at >= ? AND charged_at < ?
             GROUP BY DATE(CONVERT_TZ(charged_at, '+00:00', '+02:00'))
             ORDER BY DATE(CONVERT_TZ(charged_at, '+00:00', '+02:00'))"
        );
        $daily->execute([$from, $until]);
        $dailyByDate = [];
        foreach ($daily->fetchAll() as $row) {
            $dailyByDate[$row['date']] = [
                'date' => $row['date'],
                'paid_orders' => (int) $row['paid_orders'],
                'sales' => (float) $row['sales'],
            ];
        }
        $dailySales = [];
        for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
            $key = $date->format('Y-m-d');
            $dailySales[] = $dailyByDate[$key] ?? ['date' => $key, 'paid_orders' => 0, 'sales' => 0.0];
        }

        $topProducts = $db->prepare(
            "SELECT oi.product_name AS name, SUM(oi.quantity) AS units,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS sales
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN payments p ON p.order_id = o.id
             WHERE p.status = 'success' AND p.charged_at >= ? AND p.charged_at < ?
             GROUP BY oi.product_name
             ORDER BY units DESC, sales DESC
             LIMIT 5"
        );
        $topProducts->execute([$from, $until]);

        return $this->success([
            'period' => $period,
            'from' => $start->format('Y-m-d'),
            'to' => $today->format('Y-m-d'),
            'orders' => $orderCount,
            'paid_orders' => $paidCount,
            'sales' => round($sales, 2),
            'average_paid_order' => $paidCount > 0 ? round($sales / $paidCount, 2) : 0.0,
            'daily_sales' => $dailySales,
            'top_products' => $topProducts->fetchAll(),
        ]);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
