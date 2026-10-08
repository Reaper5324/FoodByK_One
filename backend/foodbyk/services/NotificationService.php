<?php


class EmailNotifier implements OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void {
        $recipients = $audience === 'staff'
            ? NotificationService::staffRecipients('email')
            : array_filter([$order->getCustomer()?->email]);

        $subject = $audience === 'staff'
            ? "New order #{$order->id} needs review"
            : "Update on your Food by K order #{$order->id}";
        $body = NotificationService::messageFor($order, $event, $audience);

        foreach ($recipients as $email) {
            $this->send($email, $subject, $body);
        }
    }

    private function send(string $to, string $subject, string $body): void {
        if (!defined('RESEND_API_KEY') || RESEND_API_KEY === '') {
            error_log("EMAIL (no provider configured) to {$to}: {$subject}");
            return;
        }
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Bearer " . RESEND_API_KEY . "\r\nContent-Type: application/json\r\n",
            'content' => json_encode(['from' => RESEND_FROM_EMAIL, 'to' => [$to], 'subject' => $subject, 'text' => $body]),
            'timeout' => 8,
        ]]);
        if (@file_get_contents('https://api.resend.com/emails', false, $context) === false) {
            error_log("EMAIL send failed to {$to}");
        }
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
        $contentSid = $templateSids[$templateKey] ?? '';
        if ($contentSid === '') {
            error_log("WHATSAPP (no approved template configured for '{$templateKey}'): order #{$order->id} -> {$event}");
            return;
        }

        $variables = ['1' => (string) $order->id];
        if ($event === 'submitted' && $audience === 'staff') {
            $variables['2'] = ucfirst($order->fulfilment_type);
        } elseif ($event === 'confirmed') {
            $variables['2'] = (string) ($order->confirmed_window_start ?? '');
        } elseif ($event === 'declined') {
            $variables['2'] = (string) ($order->decline_reason ?? '');
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
        if ($audience === 'staff') {
            return "New order #{$order->id} needs review - " . ucfirst($order->fulfilment_type) . ", total R" . number_format($order->total(), 2);
        }
        return match ($event) {
            'declined'  => "Your Food by K order #{$order->id} could not be accepted: {$order->decline_reason}",
            'confirmed' => "Your Food by K order #{$order->id} is confirmed for {$order->confirmed_window_start}",
            'paid'      => "Payment received for order #{$order->id} - thank you!",
            'payment_failed' => "There was a problem processing payment for order #{$order->id}. Please contact us.",
            default     => "Update on your Food by K order #{$order->id}: {$event}",
        };
    }
}
