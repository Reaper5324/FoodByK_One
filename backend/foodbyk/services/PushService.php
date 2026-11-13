<?php

class PushService {
    private const STAFF_ROLES = [Role::STAFF, Role::ADMIN];

    public function publicKey(): string {
        return trim(WEB_PUSH_VAPID_PUBLIC_KEY);
    }

    public function available(): bool {
        return $this->publicKey() !== '' && trim(WEB_PUSH_VAPID_PRIVATE_KEY) !== ''
            && trim(WEB_PUSH_VAPID_SUBJECT) !== '' && class_exists('Minishlink\\WebPush\\WebPush');
    }

    public function saveSubscription(int $userId, array $subscription): array {
        if (!$this->available()) return $this->failure('Push notifications are not configured.');
        $endpoint = trim((string) ($subscription['endpoint'] ?? ''));
        $keys = $subscription['keys'] ?? null;
        $publicKey = is_array($keys) ? trim((string) ($keys['p256dh'] ?? '')) : '';
        $authToken = is_array($keys) ? trim((string) ($keys['auth'] ?? '')) : '';
        if (!filter_var($endpoint, FILTER_VALIDATE_URL) || !in_array(parse_url($endpoint, PHP_URL_SCHEME), ['https'], true)
            || $publicKey === '' || $authToken === '') {
            return $this->failure('The browser returned an invalid notification subscription.');
        }

        $db = Database::getConnection();
        $statement = $db->prepare(
            'INSERT INTO push_subscriptions (user_id, endpoint, public_key, auth_token, user_agent)
             VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), public_key = VALUES(public_key), auth_token = VALUES(auth_token), user_agent = VALUES(user_agent)'
        );
        $statement->execute([$userId, $endpoint, $publicKey, $authToken, substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500)]);
        return $this->success(['subscribed' => true]);
    }

    public function removeSubscription(int $userId, string $endpoint): array {
        if ($endpoint === '') return $this->failure('A notification endpoint is required.');
        PushSubscription::deleteForUser($userId, $endpoint);
        return $this->success(['subscribed' => false]);
    }

    public function unacknowledgedOrdersForUser(int $userId): array {
        $db = Database::getConnection();
        $statement = $db->prepare(
            "SELECT o.id, o.fulfilment_type, o.subtotal, o.delivery_fee, o.locked_discount,
                    o.loyalty_points_redeemed, o.loyalty_points_refunded, o.created_at, u.name AS customer_name
             FROM orders o JOIN users u ON u.id = o.customer_id JOIN payments p ON p.order_id = o.id
             WHERE o.status = 'submitted' AND p.status = 'tokenized'
               AND p.gateway_token IS NOT NULL AND p.gateway_token <> ''
               AND NOT EXISTS (SELECT 1 FROM staff_order_acknowledgements a WHERE a.order_id = o.id)
             ORDER BY o.created_at ASC"
        );
        $statement->execute();
        return $this->success($statement->fetchAll());
    }

    public function acknowledge(int $orderId, int $userId): array {
        $db = Database::getConnection();
        $check = $db->prepare(
            "SELECT COUNT(*) FROM orders o JOIN payments p ON p.order_id = o.id
             WHERE o.id = ? AND o.status = 'submitted' AND p.status = 'tokenized'
               AND p.gateway_token IS NOT NULL AND p.gateway_token <> ''"
        );
        $check->execute([$orderId]);
        if ((int) $check->fetchColumn() === 0) return $this->failure('This order is no longer awaiting staff review.');

        $insert = $db->prepare('INSERT IGNORE INTO staff_order_acknowledgements (order_id, acknowledged_by) VALUES (?, ?)');
        $db->beginTransaction();
        try {
            $check = $db->prepare('SELECT COUNT(*) FROM orders WHERE id = ? AND status = \'submitted\' FOR UPDATE');
            $check->execute([$orderId]);
            if ((int) $check->fetchColumn() === 0) {
                $db->rollBack();
                return $this->success(['acknowledged' => false]);
            }
            $insert->execute([$orderId, $userId]);
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        return $this->success(['acknowledged' => true]);
    }

    public function notifyStaffOfNewOrder(Order $order): void {
        if (!$this->available()) return;
        $db = Database::getConnection();
        $users = $db->prepare(
            'SELECT DISTINCT ps.user_id, ps.endpoint, ps.public_key, ps.auth_token
             FROM push_subscriptions ps JOIN users u ON u.id = ps.user_id
             JOIN roles r ON r.id = u.role_id
             WHERE u.is_active = 1 AND r.role_name IN (?, ?)'
        );
        $users->execute(self::STAFF_ROLES);
        $rows = $users->fetchAll();
        if (!$rows) return;

        $payload = [
            'title' => 'Food by K: New order',
            'body' => 'Order #' . $order->id . ' needs review. Tap to open staff orders.',
            'tag' => 'foodbyk-order-' . $order->id,
            'requireInteraction' => true,
            'renotify' => true,
            'orderId' => $order->id,
            'url' => '/admin/orders.html?order=' . $order->id,
        ];
        try { $this->deliver($rows, $payload); }
        catch (Throwable $error) { error_log('Staff push notification failed: ' . $error->getMessage()); }
    }

    public function notifyCustomerOfOrderStatus(Order $order, string $status): void {
        if (!$this->available()) return;
        $messages = [
            'confirmed' => ['Your order was confirmed', 'The Food by K team has confirmed order #' . $order->id . '.'],
            'paid' => ['Payment received', 'Payment for order #' . $order->id . ' was received.'],
            'payment_failed' => ['Payment needs attention', 'We could not confirm payment for order #' . $order->id . '.'],
            'preparing' => ['Your order is being prepared', 'The kitchen is preparing order #' . $order->id . '.'],
            'ready' => [$order->fulfilment_type === 'delivery' ? 'Your order is out for delivery' : 'Your order is ready', 'Order #' . $order->id . ' is ready for its next step.'],
            'completed' => ['Your order is complete', 'Order #' . $order->id . ' has been completed.'],
            'declined' => ['Your order could not be accepted', 'Order #' . $order->id . ' could not be accepted.'],
        ];
        if (!isset($messages[$status])) return;
        $db = Database::getConnection();
        $statement = $db->prepare('SELECT endpoint, public_key, auth_token FROM push_subscriptions WHERE user_id = ?');
        $statement->execute([$order->customer_id]);
        try { $this->deliver($statement->fetchAll(), [
            'title' => $messages[$status][0], 'body' => $messages[$status][1],
            'tag' => 'foodbyk-order-' . $order->id . '-' . $status,
            'orderId' => $order->id, 'url' => '/pages/customer/orders.html?order_id=' . $order->id,
        ]); } catch (Throwable $error) { error_log('Customer push notification failed: ' . $error->getMessage()); }
    }

    private function deliver(array $rows, array $payload): void {
        if (!$rows || !$this->available()) return;
        $webPush = new Minishlink\WebPush\WebPush(['VAPID' => [
            'subject' => WEB_PUSH_VAPID_SUBJECT,
            'publicKey' => WEB_PUSH_VAPID_PUBLIC_KEY,
            'privateKey' => WEB_PUSH_VAPID_PRIVATE_KEY,
        ]]);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        foreach ($rows as $row) {
            try {
                $subscription = Minishlink\WebPush\Subscription::create([
                    'endpoint' => $row['endpoint'],
                    'keys' => ['p256dh' => $row['public_key'], 'auth' => $row['auth_token']],
                ]);
                $webPush->queueNotification($subscription, $json, ['TTL' => 86400, 'urgency' => 'high']);
            } catch (Throwable $error) {
                error_log('Unable to queue order status push: ' . $error->getMessage());
            }
        }
        foreach ($webPush->flush() as $report) {
            if ($report->isSubscriptionExpired()) PushSubscription::deleteEndpoint((string) $report->getEndpoint());
            elseif (!$report->isSuccess()) error_log('Order status push delivery failed: ' . $report->getReason());
        }
    }

    private function success(mixed $data): array { return ['success' => true, 'data' => $data, 'error' => null]; }
    private function failure(string $error): array { return ['success' => false, 'data' => null, 'error' => $error]; }
}
