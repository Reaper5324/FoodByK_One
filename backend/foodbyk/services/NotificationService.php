<?php

interface OrderNotifier {
    public function notify(Order $order, string $event): void;
}

class EmailNotifier implements OrderNotifier {
    public function notify(Order $order, string $event): void {
        $customer = $order->getCustomer();
        if (!$customer) return;
        // Send via configured email provider (RESEND_API_KEY etc.)
        // Kept deliberately thin here - real HTTP call belongs in its own
        // small client class once the provider is picked.
        error_log("EMAIL to {$customer->email}: order #{$order->id} -> {$event}");
    }
}

class WhatsAppNotifier implements OrderNotifier {
    public function notify(Order $order, string $event): void {
        // Twilio WhatsApp API call - staff-facing per FR-20, Secondary scope.
        error_log("WHATSAPP: order #{$order->id} -> {$event}");
    }
}


// Subject in the Observer pattern. OrderService calls notifyOrderEvent()
// without knowing or caring which channels are registered - add a new
// channel by writing a class and registering it in bootstrap, not by
// editing this class.
class NotificationService {

    /** @var OrderNotifier[] */
    private array $notifiers = [];

    public function subscribe(OrderNotifier $notifier): void {
        $this->notifiers[] = $notifier;
    }

    public function notifyOrderEvent(Order $order, string $event): void {
        foreach ($this->notifiers as $notifier) {
            try {
                $notifier->notify($order, $event);
            } catch (\Throwable $e) {
                // One channel failing (e.g. Twilio down) must not block
                // the others or roll back the order transition that
                // triggered this - notifications are best-effort.
                error_log("Notifier failed: " . get_class($notifier) . ' - ' . $e->getMessage());
            }
        }
    }
}