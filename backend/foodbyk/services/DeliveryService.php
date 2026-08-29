<?php

// Grouped file, matching the house convention (services/Services.php) —
// these classes are small and only ever used together.

interface DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float;
}

class FlatDeliveryFeeStrategy implements DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float {
        return $settings->delivery_fee;
    }
}

// Stubbed for a future tiered model (e.g. R20 under 3km, R35 up to the
// radius). Not wired up as the default yet - business_settings only has
// a single flat delivery_fee column, so a real tiered strategy needs a
// schema addition first. Left here so the extension point exists without
// overbuilding it now.
class TieredDeliveryFeeStrategy implements DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float {
        return $settings->delivery_fee; // TODO: replace once tier config exists
    }
}

interface Geocoder {
    /** @return array{lat: float, lng: float}|null */
    public function geocode(string $rawAddress): ?array;
}

// Free-tier default. Nominatim's usage policy requires a descriptive
// User-Agent and caps requests at ~1/sec - fine for MVP volume, swap for
// a paid provider (Google Geocoding) later by implementing Geocoder again.
class NominatimGeocoder implements Geocoder {
    public function geocode(string $rawAddress): ?array {
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $rawAddress, 'format' => 'json', 'limit' => 1,
        ]);

        $context = stream_context_create(['http' => [
            'header' => "User-Agent: FoodByK-Backend/1.0 (contact: admin@foodbyk.co.za)\r\n",
            'timeout' => 5,
        ]]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) return null;

        $results = json_decode($response, true);
        if (empty($results)) return null;

        return ['lat' => (float) $results[0]['lat'], 'lng' => (float) $results[0]['lon']];
    }
}

class DeliveryService {

    private DeliveryFeeStrategy $feeStrategy;
    private Geocoder $geocoder;

    public function __construct(?DeliveryFeeStrategy $feeStrategy = null, ?Geocoder $geocoder = null) {
        $this->feeStrategy = $feeStrategy ?? new FlatDeliveryFeeStrategy();
        $this->geocoder     = $geocoder ?? new NominatimGeocoder();
    }

    // One-time geocode + persist. Address.latitude/longitude stay null
    // if this fails - callers must check hasCoordinates() before relying
    // on eligibility results for that address.
    public function geocodeAddress(Address $address): bool {
        $coords = $this->geocoder->geocode($address->raw_address);
        if ($coords === null) return false;

        $address->latitude  = $coords['lat'];
        $address->longitude = $coords['lng'];
        return $address->save();
    }

    // Core FR-05/FR-06 logic: classify + price an order's fulfilment.
    public function checkEligibility(string $fulfilmentType, ?Address $address): array {
        $settings = BusinessSettings::current();

        if ($fulfilmentType === Order::TYPE_COLLECTION) {
            if (!$settings->collection_enabled) {
                return ['success' => false, 'error' => 'Collection is currently unavailable.'];
            }
            return ['success' => true, 'data' => ['fulfilment_type' => 'collection', 'distance_km' => 0, 'fee' => 0.0]];
        }

        if (!$settings->delivery_enabled) {
            return ['success' => false, 'error' => 'Delivery is currently unavailable.'];
        }
        if (!$address || !$address->hasCoordinates()) {
            return ['success' => false, 'error' => 'A geocoded delivery address is required.'];
        }

        $distanceKm = $this->haversineKm(
            $settings->business_lat, $settings->business_long,
            $address->latitude, $address->longitude
        );

        // Inclusive boundary - see DOMAIN.md §5.
        if ($distanceKm <= $settings->delivery_radius_km) {
            $fee = $this->feeStrategy->calculate($distanceKm, $settings);
            return ['success' => true, 'data' => ['fulfilment_type' => 'delivery', 'distance_km' => round($distanceKm, 2), 'fee' => $fee]];
        }

        if ($distanceKm <= $settings->collection_radius_km) {
            return ['success' => false, 'error' => 'Outside the delivery area - collection is available instead.', 'data' => ['fallback' => 'collection']];
        }

        return ['success' => false, 'error' => 'This address is outside our service area.'];
    }

    public function isWithinTradingHours(\DateTimeImmutable $when): bool {
        return BusinessSettings::current()->isWithinTradingHours($when);
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

}