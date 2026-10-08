<?php

interface DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float;
}

class FlatDeliveryFeeStrategy implements DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float {
        return $settings->delivery_fee;
    }
}

// Use the configured flat fee until the settings support distance tiers.
class TieredDeliveryFeeStrategy implements DeliveryFeeStrategy {
    public function calculate(float $distanceKm, BusinessSettings $settings): float {
        return $settings->delivery_fee;
    }
}

interface Geocoder {
    /** @return array{lat: float, lng: float}|null */
    public function geocode(string $rawAddress): ?array;
}

// Geocode addresses with OpenStreetMap's Nominatim service.
class NominatimGeocoder implements Geocoder {

    // Nominatim allows at most one request per second.
    private static ?float $lastRequestTime = null;

    public function geocode(string $rawAddress): ?array {
        $this->throttle();

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q'              => $rawAddress,
            'format'         => 'json',
            'limit'          => 1,
            'countrycodes'   => 'za',
            'addressdetails' => 0,
        ]);

        $context = stream_context_create(['http' => [
            'header'  => "User-Agent: FoodByK-Backend/1.0 (contact: admin@foodbyk.co.za)\r\n",
            'timeout' => 5,
        ]]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) return null;

        $results = json_decode($response, true);
        if (empty($results)) return null;

        // Nominatim's `importance` measures how likely a place is to be
        // searched for, not the confidence that an address match is correct.
        // Do not reject valid street or house results based on that ranking.
        $latitude = $results[0]['lat'] ?? null;
        $longitude = $results[0]['lon'] ?? null;
        if (!is_numeric($latitude) || !is_numeric($longitude)) return null;

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;
        if (!is_finite($latitude) || !is_finite($longitude)
            || $latitude < -90 || $latitude > 90
            || $longitude < -180 || $longitude > 180) {
            return null;
        }

        return ['lat' => $latitude, 'lng' => $longitude];
    }

    private function throttle(): void {
        if (self::$lastRequestTime !== null) {
            $elapsed = microtime(true) - self::$lastRequestTime;
            if ($elapsed < 1.0) usleep((int) ((1.0 - $elapsed) * 1_000_000));
        }
        self::$lastRequestTime = microtime(true);
    }
}

class DeliveryService {

    private DeliveryFeeStrategy $feeStrategy;
    private Geocoder $geocoder;

    public function __construct(?DeliveryFeeStrategy $feeStrategy = null, ?Geocoder $geocoder = null) {
        $this->feeStrategy = $feeStrategy ?? new FlatDeliveryFeeStrategy();
        $this->geocoder     = $geocoder ?? new NominatimGeocoder();
    }

    // Save coordinates once; callers can check hasCoordinates() if lookup fails.
    public function geocodeAddress(Address $address): bool {
        $coords = $this->geocoder->geocode($address->raw_address);
        if ($coords === null) return false;

        $address->latitude  = $coords['lat'];
        $address->longitude = $coords['lng'];
        return $address->save();
    }

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
