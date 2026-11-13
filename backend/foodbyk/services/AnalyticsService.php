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
            'week' => $today->modify('monday this week'),
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

        $bucketExpression = match ($period) {
            'today' => "DATE_FORMAT(CONVERT_TZ(charged_at, '+00:00', '+02:00'), '%Y-%m-%d %H:00')",
            'year' => "DATE_FORMAT(CONVERT_TZ(charged_at, '+00:00', '+02:00'), '%Y-%m')",
            default => "DATE(CONVERT_TZ(charged_at, '+00:00', '+02:00'))",
        };
        $bucketQuery = $db->prepare(
            "SELECT {$bucketExpression} AS bucket, COUNT(*) AS paid_orders, COALESCE(SUM(amount), 0) AS sales
             FROM payments WHERE status = 'success' AND charged_at >= ? AND charged_at < ?
             GROUP BY bucket ORDER BY bucket"
        );
        $bucketQuery->execute([$from, $until]);
        $salesByBucket = [];
        foreach ($bucketQuery->fetchAll() as $row) {
            $salesByBucket[(string) $row['bucket']] = [
                'bucket' => (string) $row['bucket'],
                'paid_orders' => (int) $row['paid_orders'],
                'sales' => (float) $row['sales'],
            ];
        }
        $salesBuckets = [];
        if ($period === 'today') {
            for ($hour = 0; $hour < 24; $hour++) {
                $key = $today->format('Y-m-d') . ' ' . sprintf('%02d:00', $hour);
                $salesBuckets[] = $salesByBucket[$key] ?? ['bucket' => $key, 'paid_orders' => 0, 'sales' => 0.0];
            }
        } elseif ($period === 'year') {
            for ($month = 1; $month <= 12; $month++) {
                $key = $today->format('Y') . '-' . sprintf('%02d', $month);
                $salesBuckets[] = $salesByBucket[$key] ?? ['bucket' => $key, 'paid_orders' => 0, 'sales' => 0.0];
            }
        } else {
            for ($date = $start; $date < $end; $date = $date->modify('+1 day')) {
                $key = $date->format('Y-m-d');
                $salesBuckets[] = $salesByBucket[$key] ?? ['bucket' => $key, 'paid_orders' => 0, 'sales' => 0.0];
            }
        }

        $statusQuery = $db->prepare(
            'SELECT status, COUNT(*) AS orders FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY status ORDER BY status'
        );
        $statusQuery->execute([$from, $until]);
        $statuses = array_map(static fn(array $row): array => [
            'status' => (string) $row['status'], 'orders' => (int) $row['orders'],
        ], $statusQuery->fetchAll());

        $fulfilmentQuery = $db->prepare(
            "SELECT o.fulfilment_type, COUNT(DISTINCT o.id) AS orders,
                    COUNT(DISTINCT CASE WHEN p.status = 'success' AND p.charged_at >= ? AND p.charged_at < ? THEN o.id END) AS paid_orders,
                    COALESCE(SUM(CASE WHEN p.status = 'success' AND p.charged_at >= ? AND p.charged_at < ? THEN p.amount ELSE 0 END), 0) AS sales
             FROM orders o LEFT JOIN payments p ON p.order_id = o.id
             WHERE o.created_at >= ? AND o.created_at < ?
             GROUP BY o.fulfilment_type ORDER BY o.fulfilment_type"
        );
        $fulfilmentQuery->execute([$from, $until, $from, $until, $from, $until]);
        $fulfilment = array_map(static fn(array $row): array => [
            'type' => (string) $row['fulfilment_type'], 'orders' => (int) $row['orders'],
            'paid_orders' => (int) $row['paid_orders'], 'sales' => (float) $row['sales'],
        ], $fulfilmentQuery->fetchAll());

        $busyQuery = $db->prepare(
            "SELECT HOUR(CONVERT_TZ(created_at, '+00:00', '+02:00')) AS hour, COUNT(*) AS orders
             FROM orders WHERE created_at >= ? AND created_at < ? GROUP BY hour ORDER BY orders DESC, hour ASC LIMIT 5"
        );
        $busyQuery->execute([$from, $until]);
        $busyTimes = array_map(static fn(array $row): array => [
            'hour' => (int) $row['hour'], 'orders' => (int) $row['orders'],
        ], $busyQuery->fetchAll());

        $customerBucketExpression = match ($period) {
            'today' => "CASE WHEN HOUR(CONVERT_TZ(p.charged_at, '+00:00', '+02:00')) < 12 THEN 'Morning' WHEN HOUR(CONVERT_TZ(p.charged_at, '+00:00', '+02:00')) < 17 THEN 'Afternoon' ELSE 'Evening' END",
            'month' => "CONCAT('Week ', FLOOR((DAY(CONVERT_TZ(p.charged_at, '+00:00', '+02:00')) - 1) / 7) + 1)",
            'year' => "DATE_FORMAT(CONVERT_TZ(p.charged_at, '+00:00', '+02:00'), '%Y-%m')",
            default => "DATE(CONVERT_TZ(p.charged_at, '+00:00', '+02:00'))",
        };
        $customerBucketsQuery = $db->prepare(
            "SELECT {$customerBucketExpression} AS bucket, o.fulfilment_type,
                    COUNT(DISTINCT o.customer_id) AS customers
             FROM orders o JOIN payments p ON p.order_id = o.id
             WHERE p.status = 'success' AND p.charged_at >= ? AND p.charged_at < ?
             GROUP BY bucket, fulfilment_type ORDER BY bucket"
        );
        $customerBucketsQuery->execute([$from, $until]);
        $customersByBucket = [];
        foreach ($customerBucketsQuery->fetchAll() as $row) {
            $key = (string) $row['bucket'];
            $customersByBucket[$key] ??= ['bucket' => $key, 'delivery' => 0, 'collection' => 0];
            $customersByBucket[$key][(string) $row['fulfilment_type']] = (int) $row['customers'];
        }
        $customerBuckets = [];
        $customerBucketLabels = match ($period) {
            'today' => ['Morning', 'Afternoon', 'Evening'],
            'month' => ['Week 1', 'Week 2', 'Week 3', 'Week 4', 'Week 5'],
            default => array_map(static fn(array $bucket): string => (string) $bucket['bucket'], $salesBuckets),
        };
        foreach ($customerBucketLabels as $key) {
            $customerBuckets[] = $customersByBucket[$key] ?? ['bucket' => $key, 'delivery' => 0, 'collection' => 0];
        }

        $customerQuery = $db->prepare(
            "SELECT COUNT(DISTINCT o.customer_id) AS customers,
                    COUNT(DISTINCT CASE WHEN first_orders.customer_id IS NOT NULL THEN o.customer_id END) AS new_customers
             FROM orders o JOIN payments p ON p.order_id = o.id
             LEFT JOIN (
                 SELECT o.customer_id, MIN(p.charged_at) AS first_order
                 FROM orders o JOIN payments p ON p.order_id = o.id
                 WHERE p.status = 'success' GROUP BY o.customer_id
             ) first_orders ON first_orders.customer_id = o.customer_id AND first_orders.first_order >= ? AND first_orders.first_order < ?
             WHERE p.status = 'success' AND p.charged_at >= ? AND p.charged_at < ?"
        );
        $customerQuery->execute([$from, $until, $from, $until]);
        $customerSummary = $customerQuery->fetch() ?: ['customers' => 0, 'new_customers' => 0];
        $uniqueCustomers = (int) $customerSummary['customers'];
        $newCustomers = (int) $customerSummary['new_customers'];

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
            'daily_sales' => $salesBuckets,
            'sales_buckets' => $salesBuckets,
            'statuses' => $statuses,
            'fulfilment' => $fulfilment,
            'busy_times' => $busyTimes,
            'customer_buckets' => $customerBuckets,
            'customer_insights' => [
                'customers' => $uniqueCustomers,
                'new_customers' => $newCustomers,
                'returning_customers' => max(0, $uniqueCustomers - $newCustomers),
            ],
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
