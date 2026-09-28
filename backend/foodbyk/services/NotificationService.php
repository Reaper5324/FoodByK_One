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
            'content' => json_encode(['from' => 'Food by K <orders@foodbyk.co.za>', 'to' => [$to], 'subject' => $subject, 'text' => $body]),
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

        $phones = $audience === 'staff'
            ? NotificationService::staffRecipients('phone')
            : array_filter([$order->getCustomer()?->phone]);

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
                "whatsapp:{$phone}",
                "whatsapp:" . TWILIO_FROM_NUMBER,
                $contentSid,
                $variables
            );
        }
    }

    private function sendTemplate(string $to, string $from, string $contentSid, array $variables): void {
        $auth = base64_encode(TWILIO_SID . ':' . TWILIO_AUTH_TOKEN);
        $context = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => "Authorization: Basic {$auth}\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'To' => $to,
                'From' => $from,
                'ContentSid' => $contentSid,
                'ContentVariables' => json_encode($variables),
            ]),
            'ignore_errors' => true,
            'timeout' => 8,
        ]]);
        $response = @file_get_contents("https://api.twilio.com/2010-04-01/Accounts/" . TWILIO_SID . "/Messages.json", false, $context);
        $statusLine = $http_response_header[0] ?? '';
        if ($response === false || !preg_match('/\s2\d\d\s/', $statusLine)) {
            error_log("WHATSAPP template send failed to {$to} ({$statusLine})");
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

/* Subject in the Observer pattern. OrderService calls notifyOrderEvent()
 without knowing or caring which channels are registered - add a new
 channel by writing a class and registering it in bootstrap, not by editing this class.
*/ 

interface OrderNotifier {
    public function notify(Order $order, string $event, string $audience): void;
}

class NotificationService {

    /** @var OrderNotifier[] */
    private array $notifiers;

    // Auto-subscribes the standard channels by default so every call site
    // doesn't need to remember to wire three notifiers manually. Still
    // overridable (e.g. for tests) by passing an explicit array.
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
                // One channel failing (e.g. Twilio down) must never roll
                // back or block the order transition that triggered this.
                error_log("Notifier failed: " . get_class($notifier) . ' - ' . $e->getMessage());
            }
        }
    }

    // Shared by WhatsAppNotifier/SmsNotifier/EmailNotifier - every active
    // staff or admin's contact info, since any of them might review orders.
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