<?php

class SlotService {

    public function getAvailableSlots(\DateTimeImmutable $date): array {
        $settings = BusinessSettings::current();
        $duration = $settings->slot_duration_minutes;
        $interval = new \DateInterval("PT{$duration}M");

        [$startH, $startM, $startS] = array_map('intval', explode(':', $settings->trading_hours_start));
        [$endH, $endM, $endS]       = array_map('intval', explode(':', $settings->trading_hours_end));

        $slotStart = $date->setTime($startH, $startM, $startS);
        $dayEnd    = $date->setTime($endH, $endM, $endS);

        $slots = [];
        while (true) {
            $slotEnd = $slotStart->add($interval);
            if ($slotEnd > $dayEnd) break;

            $taken = Order::countActiveForSlot($slotStart->format('Y-m-d H:i:s'));
            $slots[] = [
                'start'     => $slotStart->format('Y-m-d\TH:i:s'),
                'end'       => $slotEnd->format('Y-m-d\TH:i:s'),
                'remaining' => max(0, $settings->max_orders_per_slot - $taken),
                'available' => $taken < $settings->max_orders_per_slot,
            ];
            $slotStart = $slotEnd;
        }

        return ['success' => true, 'data' => $slots];
    }

    public function isValidSlot(string $windowStart, string $windowEnd): array {
        try {
            $start = new \DateTimeImmutable($windowStart);
            $end   = new \DateTimeImmutable($windowEnd);
        } catch (\Exception) {
            return ['success' => false, 'error' => 'Invalid time format.'];
        }

        $settings = BusinessSettings::current();
        $duration = $settings->slot_duration_minutes;

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

    // Must be called inside an existing DB transaction.
    public function reserveCapacityLocked(string $windowStart): array {
        $settings = BusinessSettings::current();
        $taken = Order::countActiveForSlotLocked($windowStart);

        if ($taken >= $settings->max_orders_per_slot) {
            return ['success' => false, 'error' => 'This time slot is fully booked. Please choose another.'];
        }

        return ['success' => true];
    }
}