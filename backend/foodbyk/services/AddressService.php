<?php

/**
 * AddressService - Manage customer delivery addresses.
 * 
 * Handles:
 * - CRUD operations for customer addresses
 * - Address validation and geocoding
 * - Delivery eligibility checking
 * - Default address management
 */
class AddressService {

    private const MAX_STREET_LENGTH = 255;
    private const MAX_CITY_LENGTH = 100;
    private const MAX_POSTAL_CODE_LENGTH = 20;
    private const MAX_LABEL_LENGTH = 50;

    /**
     * List all addresses for a customer.
     * Optionally filtered to only delivery-eligible addresses.
     * 
     * @param int $customerId
     * @param bool $onlyEligible (check delivery eligibility)
     * @return array ['success' => bool, 'data' => Address[], 'error' => ?string]
     */
    public function listForCustomer(int $customerId, bool $onlyEligible = false): array {
        $addresses = Address::findBy('customer_id', $customerId);

        if ($onlyEligible) {
            $deliveryService = new DeliveryService();
            $addresses = array_filter($addresses, function (Address $addr) use ($deliveryService) {
                if (!$addr->hasCoordinates()) return false;
                $result = $deliveryService->checkEligibility(Order::TYPE_DELIVERY, $addr);
                return $result['success'];
            });
        }

        usort($addresses, fn(Address $a, Address $b) =>
            ($b->is_default ?? false) <=> ($a->is_default ?? false) // default first
            ?: $b->id <=> $a->id // then newest
        );

        return $this->success(array_values($addresses));
    }

    /**
     * Get a single address by ID (with delivery eligibility check).
     * 
     * @param int $addressId
     * @param int $customerId (for ownership verification)
     * @return array ['success' => bool, 'data' => [...], 'error' => ?string]
     */
    public function getById(int $addressId, int $customerId): array {
        $address = Address::findById($addressId);
        if (!$address || $address->customer_id !== $customerId) {
            return $this->failure('Address not found.');
        }

        $data = $this->addressToArray($address);

        // Check delivery eligibility if geocoded
        if ($address->hasCoordinates()) {
            $deliveryService = new DeliveryService();
            $eligibility = $deliveryService->checkEligibility(Order::TYPE_DELIVERY, $address);
            $data['delivery_eligible'] = $eligibility['success'];
            $data['delivery_fee'] = $eligibility['data']['fee'] ?? null;
            $data['distance_km'] = $eligibility['data']['distance_km'] ?? null;
        }

        return $this->success($data);
    }

    /**
     * Create a new address for a customer.
     * Attempts to geocode immediately.
     * 
     * @param int $customerId
     * @param array $input ['raw_address', 'label', 'is_default']
     * @return array ['success' => bool, 'data' => Address, 'error' => ?string]
     */
    public function create(int $customerId, array $input): array {
        $validated = $this->validateInput($input);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        $address = new Address(
            customer_id: $customerId,
            raw_address: $data['raw_address'],
            is_default: $data['is_default']
        );

        if (!$address->save()) {
            return $this->failure('Unable to create address.');
        }

        // Attempt geocoding
        $deliveryService = new DeliveryService();
        $geocodeSuccess = $deliveryService->geocodeAddress($address);
        if (!$geocodeSuccess) {
            // Non-fatal: address created but not yet geocoded
            error_log("Failed to geocode address {$address->id}: {$address->raw_address}");
        }

        // If this is the default, unset all other defaults
        if ($data['is_default']) {
            $this->clearOtherDefaults($customerId, $address->id);
        }

        return $this->success($address);
    }

    /**
     * Update an existing address.
     * Re-geocodes if raw_address changed.
     * 
     * @param int $addressId
     * @param int $customerId
     * @param array $input
     * @return array ['success' => bool, 'data' => Address, 'error' => ?string]
     */
    public function update(int $addressId, int $customerId, array $input): array {
        $address = Address::findById($addressId);
        if (!$address || $address->customer_id !== $customerId) {
            return $this->failure('Address not found.');
        }

        $validated = $this->validateInput($input, $address);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        $addressChanged = ($data['raw_address'] !== $address->raw_address);

        $address->raw_address = $data['raw_address'];
        $address->is_default = $data['is_default'];

        if ($addressChanged) {
            // Re-geocode if address changed
            $address->latitude = null;
            $address->longitude = null;

            $deliveryService = new DeliveryService();
            $geocodeSuccess = $deliveryService->geocodeAddress($address);
            if (!$geocodeSuccess) {
                error_log("Failed to re-geocode address {$address->id}: {$address->raw_address}");
            }
        }

        if (!$address->save()) {
            return $this->failure('Unable to update address.');
        }

        // If this is the default, unset all other defaults
        if ($data['is_default']) {
            $this->clearOtherDefaults($customerId, $address->id);
        }

        return $this->success($address);
    }

    /**
     * Delete an address.
     * 
     * @param int $addressId
     * @param int $customerId
     * @return array ['success' => bool, 'error' => ?string]
     */
    public function delete(int $addressId, int $customerId): array {
        $address = Address::findById($addressId);
        if (!$address || $address->customer_id !== $customerId) {
            return $this->failure('Address not found.');
        }

        return $address->delete()
            ? $this->success(null)
            : $this->failure('Unable to delete address.');
    }

    /**
     * Set an address as the customer's default.
     * 
     * @param int $addressId
     * @param int $customerId
     * @return array ['success' => bool, 'data' => Address, 'error' => ?string]
     */
    public function setDefault(int $addressId, int $customerId): array {
        $address = Address::findById($addressId);
        if (!$address || $address->customer_id !== $customerId) {
            return $this->failure('Address not found.');
        }

        $address->is_default = true;
        if (!$address->save()) {
            return $this->failure('Unable to set default address.');
        }

        $this->clearOtherDefaults($customerId, $addressId);

        return $this->success($address);
    }

    /**
     * Get the customer's default address (if set).
     * 
     * @param int $customerId
     * @return array ['success' => bool, 'data' => Address|null, 'error' => ?string]
     */
    public function getDefault(int $customerId): array {
        $addresses = Address::findBy('customer_id', $customerId);
        $default = array_values(array_filter(
            $addresses,
            fn(Address $a) => $a->is_default === true
        ))[0] ?? null;

        return $this->success($default);
    }

    /**
     * Validate address input.
     * 
     * @param array $input
     * @param ?Address $existing
     * @return array ['success' => bool, 'data' => validated_input, 'error' => ?string]
     */
    public function validateInput(array $input, ?Address $existing = null): array {
        $rawAddress = trim((string) ($input['raw_address'] ?? $existing?->raw_address ?? ''));
        $isDefault = $input['is_default'] ?? $existing?->is_default ?? false;

        if ($rawAddress === '' || $this->stringLength($rawAddress) > 500) {
            return $this->failure('Address must be between 1 and 500 characters.');
        }

        if (!is_bool($isDefault) && !in_array($isDefault, [0, 1, '0', '1'], true)) {
            return $this->failure('Invalid default flag.');
        }

        return $this->success([
            'raw_address' => $rawAddress,
            'is_default' => (bool) $isDefault,
        ]);
    }

    /**
     * Clear the default flag from all other addresses for this customer.
     * Called after setting a new default.
     * 
     * @param int $customerId
     * @param int $exceptAddressId
     */
    private function clearOtherDefaults(int $customerId, int $exceptAddressId): void {
        $addresses = Address::findBy('customer_id', $customerId);
        foreach ($addresses as $addr) {
            if ($addr->id !== $exceptAddressId && $addr->is_default) {
                $addr->is_default = false;
                $addr->save();
            }
        }
    }

    private function addressToArray(Address $address): array {
        return [
            'id' => $address->id,
            'customer_id' => $address->customer_id,
            'raw_address' => $address->raw_address,
            'is_default' => $address->is_default,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'has_coordinates' => $address->hasCoordinates(),
        ];
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }

    private function success(mixed $data): array {
        return ['success' => true, 'data' => $data, 'error' => null];
    }

    private function failure(string $error): array {
        return ['success' => false, 'data' => null, 'error' => $error];
    }
}
