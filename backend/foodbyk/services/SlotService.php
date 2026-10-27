<?php

class SlotService {

    public function getAvailableSlotsForDate(string $dateValue = ''): array {
        $timezone = new DateTimeZone('Africa/Johannesburg');
        if ($dateValue === '') {
            $date = new DateTimeImmutable('today', $timezone);
        } else {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $dateValue)) {
                return ['success' => false, 'data' => null, 'error' => 'Invalid date.'];
            }
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $timezone);
            $errors = DateTimeImmutable::getLastErrors();
            if (!$date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
                || $date->format('Y-m-d') !== $dateValue) {
                return ['success' => false, 'data' => null, 'error' => 'Invalid date.'];
            }
        }

        $today = new DateTimeImmutable('today', $timezone);
        if ($date < $today) {
            return ['success' => false, 'data' => null, 'error' => 'Past dates cannot be scheduled.'];
        }
        (new OrderService())->expireAbandonedPaymentSetups();
        if ($date->format('N') === '7') {
            return ['success' => true, 'data' => [], 'error' => null];
        }

        return $this->getAvailableSlots($date);
    }

    public function getAvailableSlots(\DateTimeImmutable $date): array {
        if ($date->format('N') === '7') {
            return ['success' => true, 'data' => [], 'error' => null];
        }
        $settings = BusinessSettings::current();
        $duration = $settings->slot_duration_minutes;
        $interval = new \DateInterval("PT{$duration}M");

        [$startH, $startM, $startS] = array_map('intval', explode(':', $settings->trading_hours_start));
        [$endH, $endM, $endS]       = array_map('intval', explode(':', $settings->trading_hours_end));

        $slotStart = $date->setTime($startH, $startM, $startS);
        $dayEnd    = $date->setTime($endH, $endM, $endS);
        $now = new \DateTimeImmutable('now', $date->getTimezone());

        $slots = [];
        while (true) {
            $slotEnd = $slotStart->add($interval);
            if ($slotEnd > $dayEnd) break;

            $taken = Order::countActiveForSlot($slotStart->format('Y-m-d H:i:s'));
            if ($slotStart > $now) $slots[] = [
                'start'     => $slotStart->format('Y-m-d\TH:i:s'),
                'end'       => $slotEnd->format('Y-m-d\TH:i:s'),
                'remaining' => max(0, $settings->max_orders_per_slot - $taken),
                'available' => $taken < $settings->max_orders_per_slot,
            ];
            $slotStart = $slotEnd;
        }

        return ['success' => true, 'data' => $slots, 'error' => null];
    }

    public function isValidSlot(string $windowStart, string $windowEnd): array {
        $slotPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D';
        if (!preg_match($slotPattern, $windowStart) || !preg_match($slotPattern, $windowEnd)) {
            return ['success' => false, 'error' => 'Invalid time format.'];
        }
        $timezone = new DateTimeZone('Africa/Johannesburg');
        $start = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $windowStart, $timezone);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s', $windowEnd, $timezone);
        $startErrors = DateTimeImmutable::getLastErrors();
        $endErrors = DateTimeImmutable::getLastErrors();
        if (!$start || !$end
            || ($startErrors !== false && ($startErrors['warning_count'] > 0 || $startErrors['error_count'] > 0))
            || ($endErrors !== false && ($endErrors['warning_count'] > 0 || $endErrors['error_count'] > 0))
            || $start->format('Y-m-d\TH:i:s') !== $windowStart
            || $end->format('Y-m-d\TH:i:s') !== $windowEnd) {
            return ['success' => false, 'error' => 'Invalid time format.'];
        }

        $settings = BusinessSettings::current();
        $duration = $settings->slot_duration_minutes;

        if ($start <= new \DateTimeImmutable('now', $start->getTimezone())) {
            return ['success' => false, 'error' => 'Selected slot is in the past.'];
        }
        if ($start->format('N') === '7') {
            return ['success' => false, 'error' => 'Orders cannot be scheduled on Sundays.'];
        }

        if ($end->getTimestamp() - $start->getTimestamp() !== $duration * 60) {
            return ['success' => false, 'error' => 'Selected window does not match a valid slot length.'];
        }
        if (!$settings->isWithinTradingHours($start) || !$settings->isWithinTradingHours($end)) {
            return ['success' => false, 'error' => 'Selected slot is outside trading hours.'];
        }

        [$startH, $startM, $startS] = array_map('intval', explode(':', $settings->trading_hours_start));
        $gridStart = $start->setTime($startH, $startM, $startS);
        $minutesSinceOpen = ($start->getTimestamp() - $gridStart->getTimestamp()) / 60;
        if ($minutesSinceOpen < 0 || fmod($minutesSinceOpen, $duration) !== 0.0) {
            return ['success' => false, 'error' => 'Selected time does not align to an available slot.'];
        }

        return ['success' => true];
    }

    // Keep the capacity check inside the order submission transaction.
    public function reserveCapacityLocked(string $windowStart, ?int $excludeOrderId = null): array {
        // Serialize slot reservations so two staff members or a customer and staff member
        // cannot both pass the final capacity check for the same slot.
        $db = Database::getConnection();
        $lock = $db->query('SELECT id FROM business_settings WHERE id = 1 FOR UPDATE');
        if (!$lock->fetchColumn()) {
            return ['success' => false, 'error' => 'Ordering settings are unavailable.'];
        }
        $settings = BusinessSettings::current();
        $taken = Order::countActiveForSlotLocked($windowStart, $excludeOrderId);

        if ($taken >= $settings->max_orders_per_slot) {
            return ['success' => false, 'error' => 'This time slot is fully booked. Please choose another.'];
        }

        return ['success' => true];
    }
}
