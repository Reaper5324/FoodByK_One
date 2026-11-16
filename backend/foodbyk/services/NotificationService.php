<?php


class EmailNotifier implements OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void {
        $recipients = $audience === 'staff'
            ? NotificationService::staffRecipients('email')
            : array_filter([$order->getCustomer()?->email]);

        $subject = NotificationService::subjectFor($order, $event, $audience);
        $body = NotificationService::emailMessageFor($order, $event, $audience);

        foreach ($recipients as $email) {
            $this->send($email, $subject, $body);
        }
    }

    private function send(string $to, string $subject, string $body): void {
        (new EmailService())->send($to, $subject, $body);
    }
}
class WhatsAppNotifier implements OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void {
        if (!defined('TWILIO_SID') || TWILIO_SID === '') {
            error_log("WHATSAPP (not configured): order #{$order->id} [{$audience}] -> {$event}");
            return;
        }

        $sandbox = defined('TWILIO_WHATSAPP_SANDBOX') && TWILIO_WHATSAPP_SANDBOX;
        if ($sandbox) {
            $allowedRecipients = array_filter(array_map(
                fn(string $phone): string => $this->normalizeWhatsAppAddress($phone),
                explode(',', TWILIO_WHATSAPP_SANDBOX_RECIPIENTS)
            ));
            if (!$allowedRecipients) {
                error_log('WHATSAPP sandbox enabled but TWILIO_WHATSAPP_SANDBOX_RECIPIENTS is empty.');
                return;
            }

            // Sandbox staff alerts go to the configured test recipient(s),
            // regardless of whether staff phone numbers are present in users.
            $phones = $audience === 'staff'
                ? $allowedRecipients
                : array_filter([$order->getCustomer()?->phone]);
            $phones = array_values(array_filter($phones, function ($phone) use ($allowedRecipients) {
                return in_array($this->normalizeWhatsAppAddress((string) $phone), $allowedRecipients, true);
            }));
            if (!$phones) {
                error_log("WHATSAPP sandbox skipped order #{$order->id}: recipient has not been allowlisted/joined.");
                return;
            }

            $from = $this->normalizeWhatsAppAddress(TWILIO_WHATSAPP_SANDBOX_FROM);
            $body = NotificationService::messageFor($order, $event, $audience);
            foreach ($phones as $phone) {
                $this->sendMessage($this->normalizeWhatsAppAddress((string) $phone), $from, $body);
            }
            return;
        }

        $phones = $audience === 'staff'
            ? NotificationService::staffRecipients('phone')
            : array_filter([$order->getCustomer()?->phone]);

        $templateKey = $audience === 'staff'
            ? ($event === 'submitted' ? 'staff_new_order' : '')
            : "customer_{$event}";
        $templateSids = [
            'staff_new_order'         => TWILIO_TEMPLATE_STAFF_NEW_ORDER,
            'customer_confirmed'      => TWILIO_TEMPLATE_CUSTOMER_CONFIRMED,
            'customer_declined'       => TWILIO_TEMPLATE_CUSTOMER_DECLINED,
            'customer_paid'           => TWILIO_TEMPLATE_CUSTOMER_PAID,
            'customer_payment_failed' => TWILIO_TEMPLATE_CUSTOMER_PAYMENT_FAILED,
        ];
        $richTemplateSids = [
            'staff_new_order'         => TWILIO_TEMPLATE_STAFF_NEW_ORDER_RICH,
            'customer_confirmed'      => TWILIO_TEMPLATE_CUSTOMER_CONFIRMED_RICH,
            'customer_declined'       => TWILIO_TEMPLATE_CUSTOMER_DECLINED_RICH,
            'customer_paid'           => TWILIO_TEMPLATE_CUSTOMER_PAID_RICH,
            'customer_payment_failed' => TWILIO_TEMPLATE_CUSTOMER_PAYMENT_FAILED_RICH,
        ];
        $richContentSid = $richTemplateSids[$templateKey] ?? '';
        $contentSid = $richContentSid !== '' ? $richContentSid : ($templateSids[$templateKey] ?? '');
        if ($contentSid === '') {
            error_log("WHATSAPP (no approved template configured for '{$templateKey}'): order #{$order->id} -> {$event}");
            return;
        }

        if ($richContentSid !== '') {
            $variables = [
                '1' => (string) $order->id,
                '2' => NotificationService::templateSummary($order, $event, $audience),
            ];
        } else {
            $variables = ['1' => (string) $order->id];
            if ($event === 'submitted' && $audience === 'staff') {
                $variables['2'] = NotificationService::legacyTemplateSummary($order, $event, $audience);
            } elseif ($event === 'confirmed' || $event === 'declined') {
                $variables['2'] = NotificationService::legacyTemplateSummary($order, $event, $audience);
            }
        }

        foreach ($phones as $phone) {
            $this->sendTemplate(
                $this->normalizeWhatsAppAddress((string) $phone),
                $this->normalizeWhatsAppAddress(TWILIO_WHATSAPP_FROM !== '' ? TWILIO_WHATSAPP_FROM : TWILIO_FROM_NUMBER),
                $contentSid,
                $variables
            );
        }
    }

    private function normalizeWhatsAppAddress(string $phone): string {
        $phone = trim($phone);
        if (str_starts_with($phone, 'whatsapp:')) return $phone;
        return 'whatsapp:' . preg_replace('/[\s().-]+/', '', $phone);
    }

    private function sendMessage(string $to, string $from, string $body): void {
        $this->sendTwilioRequest([
            'To' => $to,
            'From' => $from,
            'Body' => $body,
        ], $to);
    }

    private function sendTemplate(string $to, string $from, string $contentSid, array $variables): void {
        $this->sendTwilioRequest([
            'To' => $to,
            'From' => $from,
            'ContentSid' => $contentSid,
            'ContentVariables' => json_encode($variables),
        ], $to);
    }

    private function sendTwilioRequest(array $params, string $to): void {
        $auth = base64_encode(TWILIO_SID . ':' . TWILIO_AUTH_TOKEN);
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Basic {$auth}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($params),
            'ignore_errors' => true,
            'timeout' => 8,
        ]]);
        $response = @file_get_contents("https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json", false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            $error = json_decode(is_string($response) ? $response : '', true);
            $errorCode = is_array($error) ? (string) ($error['code'] ?? 'unknown') : 'unknown';
            $errorMessage = is_array($error) ? (string) ($error['message'] ?? 'No readable Twilio error message.') : 'Twilio returned no readable response.';
            error_log(sprintf(
                'WHATSAPP send failed to %s (%s; Twilio %s): %s',
                $to,
                $statusLine !== '' ? $statusLine : 'no HTTP status',
                substr($errorCode, 0, 30),
                substr($errorMessage, 0, 300)
            ));
        }
    }
}

class SmsNotifier implements OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void {
        if (!defined('TWILIO_SID') || TWILIO_SID === '') {
            error_log("SMS (not configured): order #{$order->id} [{$audience}] -> {$event}");
            return;
        }

        $phones = $audience === 'staff'
            ? NotificationService::staffRecipients('phone')
            : array_filter([$order->getCustomer()?->phone]);

        $message = NotificationService::messageFor($order, $event, $audience);

        foreach ($phones as $phone) {
            $this->sendTwilio($phone, TWILIO_FROM_NUMBER, $message);
        }
    }

    private function sendTwilio(string $to, string $from, string $body): void {
        $auth = base64_encode(TWILIO_SID . ':' . TWILIO_AUTH_TOKEN);
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Basic {$auth}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query(['To' => $to, 'From' => $from, 'Body' => $body]),
            'timeout' => 8,
        ]]);
        if (@file_get_contents("https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json", false, $context) === false) {
            error_log("SMS send failed to {$to}");
        }
    }
}

/* Notification channel contract used by NotificationService. */

interface OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void;
}

class NotificationService {

    /** @var OrderNotifier[] */
    private array $notifiers;

    // Use the standard channels unless a caller supplies a custom list.
    public function __construct(?array $notifiers = null) {
        $this->notifiers = $notifiers ?? [new EmailNotifier(), new WhatsAppNotifier(), new SmsNotifier()];
    }

    public function subscribe(OrderNotifier $notifier): void {
        $this->notifiers[] = $notifier;
    }

    public function notifyOrderEvent(Order $order, string $event, string $audience = 'customer'): void {
        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->notify($order, $event, $audience);
            } catch (\Throwable $e) {
                // A notification failure must not undo the order change.
                error_log("Notifier failed: " . get_class($notifier) . ' - ' . $e->getMessage());
            }
        }
    }

    // Notify every active staff member and admin who can review orders.
    public static function staffRecipients(string $column): array {
        $db = Database::getConnection();
        $stmt = $db->prepare(
            "SELECT {$column} FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE r.role_name IN (?, ?) AND u.is_active = 1 AND u.{$column} IS NOT NULL AND u.{$column} != ''"
        );
        $stmt->execute([Role::STAFF, Role::ADMIN]);
        return array_column($stmt->fetchAll(), $column);
    }

    public static function messageFor(Order $order, string $event, string $audience): string {
        return self::formatOrderMessage($order, $event, $audience, 1350);
    }

    public static function emailMessageFor(Order $order, string $event, string $audience): string {
        return self::formatOrderMessage($order, $event, $audience, null);
    }

    public static function subjectFor(Order $order, string $event, string $audience): string {
        if ($audience === 'staff' && in_array($event, ['cancelled', 'cancellation_requested'], true)) {
            return "Customer cancellation for order #{$order->id}";
        }
        if ($audience === 'staff') return "New order #{$order->id} needs review · R" . number_format($order->total(), 2);
        $status = match ($event) {
            'confirmed' => 'confirmed',
            'declined' => 'update',
            'paid' => 'payment received',
            'payment_failed' => 'payment needs attention',
            default => 'status update',
        };
        return "Food by K order #{$order->id}: {$status}";
    }

    /** Compact order details for an approved Twilio template's {{2}} variable. */
    public static function templateSummary(Order $order, string $event, string $audience): string {
        $summary = self::orderSummary($order, $audience, 850);
        $intro = self::eventIntro($order, $event, $audience);
        return self::limitText(trim($intro . "\n" . $summary), 1000);
    }

    /** Keep useful details flowing through existing templates while richer SIDs are configured. */
    public static function legacyTemplateSummary(Order $order, string $event, string $audience): string {
        $intro = match (true) {
            $audience === 'staff' => ucfirst($order->fulfilment_type),
            $event === 'confirmed' => 'Confirmed for ' . ($order->confirmed_window_start ?: 'the requested time'),
            $event === 'declined' => 'Reason: ' . ($order->decline_reason ?: 'Please contact the store'),
            default => '',
        };
        $parts = array_filter([$intro, self::orderSummary($order, $audience, 700)]);
        return self::limitText(implode("\n", $parts), 850);
    }

    private static function formatOrderMessage(Order $order, string $event, string $audience, ?int $maxLength): string {
        $intro = self::eventIntro($order, $event, $audience);
        if ($maxLength !== null) $intro = self::limitText($intro, 300);
        $summary = self::orderSummary($order, $audience, $maxLength === null ? null : 950);
        $body = "{$intro}\n\n{$summary}";
        return $maxLength === null ? $body : self::limitText($body, $maxLength);
    }

    private static function eventIntro(Order $order, string $event, string $audience): string {
        if ($audience === 'staff') {
            if ($event === 'cancellation_requested') return "Customer cancellation requested for order #{$order->id}; check the PayFast refund status. Reason: " . substr($order->cancel_reason ?: 'Customer request', 0, 180);
            if ($event === 'cancelled') return "Customer cancelled Food by K order #{$order->id}. Reason: " . substr($order->cancel_reason ?: 'Customer request', 0, 180);
            return "New Food by K order #{$order->id} needs review.";
        }
        return match ($event) {
            'cancelled' => $order->getPayment()?->status === Payment::STATUS_SUCCESS
                ? "Food by K order #{$order->id} has been cancelled. Its PayFast refund request is being processed and may take time to settle."
                : "Food by K order #{$order->id} has been cancelled. No payment was taken.",
            'declined' => "Food by K order #{$order->id} could not be accepted. Reason: " . ($order->decline_reason ?: 'Please contact us for details.'),
            'confirmed' => "Food by K order #{$order->id} is confirmed for " . ($order->confirmed_window_start ?: 'the selected time') . '.',
            'paid' => "Payment received for Food by K order #{$order->id}. Thank you!",
            'payment_failed' => "Payment for Food by K order #{$order->id} needs attention. Please contact us to arrange payment.",
            default => "Update on Food by K order #{$order->id}: " . ucfirst(str_replace('_', ' ', $event)) . '.',
        };
    }

    private static function orderSummary(Order $order, string $audience, ?int $maxLength): string {
        $lines = [];
        $customer = $order->getCustomer();
        if ($audience === 'staff' && $customer) {
            $lines[] = 'Customer: ' . $customer->full_name;
            if ($customer->phone) $lines[] = 'Phone: ' . $customer->phone;
            if ($customer->email) $lines[] = 'Email: ' . $customer->email;
        }

        $lines[] = 'Fulfilment: ' . ucfirst($order->fulfilment_type);
        $window = $order->confirmed_window_start ?: $order->requested_window_start;
        if ($window) $lines[] = ($order->confirmed_window_start ? 'Time: ' : 'Requested time: ') . $window;
        if ($order->isDelivery() && $order->distance_km > 0) {
            $lines[] = 'Delivery distance: ' . number_format($order->distance_km, 1) . ' km';
        }

        $lines[] = 'Items:';
        $itemLines = [];
        foreach ($order->getItems() as $item) {
            $itemLine = sprintf('• %d × %s — R%s each (R%s)', $item->quantity, $item->product_name,
                number_format($item->unit_price, 2), number_format($item->getLineTotal(), 2));
            $optionNames = array_values(array_filter(array_map(
                static fn(mixed $option): string => is_array($option) ? trim((string) ($option['name'] ?? '')) : '',
                $item->customizations
            )));
            if ($optionNames) $itemLine .= ' · ' . implode(', ', $optionNames);
            $itemLines[] = $itemLine;
        }
        if ($itemLines === []) $itemLines[] = 'Order items are being prepared.';

        $lines = array_merge($lines, $itemLines, [
            'Subtotal: R' . number_format($order->subtotal, 2),
        ]);
        if ($order->delivery_fee > 0 || $order->isDelivery()) {
            $lines[] = 'Delivery fee: R' . number_format($order->delivery_fee, 2);
        }
        if ($order->locked_discount > 0) $lines[] = 'Promotion discount: −R' . number_format($order->locked_discount, 2);
        $loyaltyDiscount = max(0.0, $order->loyalty_points_redeemed - $order->loyalty_points_refunded);
        if ($loyaltyDiscount > 0) $lines[] = 'Loyalty points used: −R' . number_format($loyaltyDiscount, 2);
        $lines[] = 'Order total: R' . number_format($order->total(), 2);
        $payment = $order->getPayment();
        if ($payment) $lines[] = 'Payment status: ' . ucfirst(str_replace('_', ' ', $payment->status));
        if ($audience === 'staff') $lines[] = 'Order status: ' . ucfirst(str_replace('_', ' ', $order->status));

        if ($maxLength === null) return implode("\n", $lines);
        $summary = implode("\n", $lines);
        if (strlen($summary) <= $maxLength) return $summary;

        $itemsHeaderIndex = array_search('Items:', $lines, true);
        $itemsHeaderIndex = $itemsHeaderIndex === false ? 0 : $itemsHeaderIndex;
        $fixedLines = array_slice($lines, 0, $itemsHeaderIndex + 1);
        $tailLines = array_slice($lines, $itemsHeaderIndex + 1 + count($itemLines));
        $available = max(80, $maxLength - strlen(implode("\n", array_merge($fixedLines, $tailLines))) - 45);
        $included = [];
        foreach ($itemLines as $line) {
            if (strlen(implode("\n", $included)) + strlen($line) + 1 > $available) break;
            $included[] = $line;
        }
        $omitted = count($itemLines) - count($included);
        if ($omitted > 0) $included[] = "…plus {$omitted} more item line(s); see the order details.";
        $compact = implode("\n", array_merge($fixedLines, $included, $tailLines));
        return strlen($compact) <= $maxLength ? $compact : self::limitText($compact, $maxLength);
    }

    private static function limitText(string $text, int $maxLength): string {
        if (strlen($text) <= $maxLength) return $text;
        return rtrim(substr($text, 0, max(0, $maxLength - 28))) . "\nSee order details for more.";
    }
}
