<?php

/* Customer address validation, geocoding, and default address handling. */
class AddressService {

    private const MAX_RAW_ADDRESS_LENGTH = 500;
    private const MAX_STREET_LENGTH = 255;
    private const MAX_CITY_LENGTH = 100;
    private const MAX_POSTAL_CODE_LENGTH = 20;
    private const MAX_LABEL_LENGTH = 50;

    // Keep a customer's default address at the top of the list.
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
            ($b->is_default ?? false) <=> ($a->is_default ?? false)
            ?: $b->id <=> $a->id
        );

        return $this->success(array_values($addresses));
    }

    public function getById(int $addressId, int $customerId): array {
        $address = Address::findById($addressId);
        if (!$address || $address->customer_id !== $customerId) {
            return $this->failure('Address not found.');
        }

        $data = $this->addressToArray($address);

        if ($address->hasCoordinates()) {
            $deliveryService = new DeliveryService();
            $eligibility = $deliveryService->checkEligibility(Order::TYPE_DELIVERY, $address);
            $data['delivery_eligible'] = $eligibility['success'];
            $data['delivery_fee'] = $eligibility['data']['fee'] ?? null;
            $data['distance_km'] = $eligibility['data']['distance_km'] ?? null;
        }

        return $this->success($data);
    }

    public function create(int $customerId, array $input): array {
        $validated = $this->validateInput($input);
        if (!$validated['success']) {
            return $validated;
        }

        $data = $validated['data'];
        $address = new Address(
            customer_id: $customerId,
            raw_address: $data['raw_address'],
            street: $data['street'],
            city: $data['city'],
            province: $data['province'],
            postal_code: $data['postal_code'],
            is_default: $data['is_default']
        );

        if (!$address->save()) {
            return $this->failure('Unable to create address.');
        }

        $deliveryService = new DeliveryService();
        $geocodeSuccess = $deliveryService->geocodeAddress($address);
        if (!$geocodeSuccess) {
            // Keep the address even when geocoding is temporarily unavailable.
            error_log("Failed to geocode address {$address->id}.");
        }

        if ($data['is_default']) {
            $this->clearOtherDefaults($customerId, $address->id);
        }

        return $this->success($address);
    }

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
        $address->street = $data['street'];
        $address->city = $data['city'];
        $address->province = $data['province'];
        $address->postal_code = $data['postal_code'];
        $address->is_default = $data['is_default'];

        if ($addressChanged) {
            $address->latitude = null;
            $address->longitude = null;
        }

        // Retry geocoding when an address was saved previously without a
        // match, even if the customer is now saving the same address again.
        if ($addressChanged || !$address->hasCoordinates()) {
            $deliveryService = new DeliveryService();
            $geocodeSuccess = $deliveryService->geocodeAddress($address);
            if (!$geocodeSuccess) {
                error_log("Failed to re-geocode address {$address->id}.");
            }
        }

        if (!$address->save()) {
            return $this->failure('Unable to update address.');
        }

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
    // Return the saved default address, or null if none has been selected.
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
        $street = trim((string) ($input['street'] ?? $existing?->street ?? $input['raw_address'] ?? $existing?->raw_address ?? ''));
        $city = trim((string) ($input['city'] ?? $existing?->city ?? ''));
        $province = trim((string) ($input['province'] ?? $existing?->province ?? ''));
        $postalCode = trim((string) ($input['postal_code'] ?? $existing?->postal_code ?? ''));
        $isDefault = $input['is_default'] ?? $existing?->is_default ?? false;
        $rawAddress = implode(', ', array_filter([$street, $city, $province, $postalCode], fn(string $part) => $part !== ''));

        if ($street === '' || $city === '' || $province === '') {
            return $this->failure('Enter the street address, city or suburb, and province.');
        }
        if ($this->stringLength($street) > self::MAX_STREET_LENGTH
            || $this->stringLength($city) > self::MAX_CITY_LENGTH
            || $this->stringLength($province) > 100
            || $this->stringLength($postalCode) > self::MAX_POSTAL_CODE_LENGTH
            || $this->stringLength($rawAddress) > self::MAX_RAW_ADDRESS_LENGTH) {
            return $this->failure('One or more address fields are too long.');
        }

        if (!is_bool($isDefault) && !in_array($isDefault, [0, 1, '0', '1'], true)) {
            return $this->failure('Invalid default flag.');
        }

        return $this->success([
            'raw_address' => $rawAddress,
            'street' => $street,
            'city' => $city,
            'province' => $province,
            'postal_code' => $postalCode === '' ? null : $postalCode,
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
            'street' => $address->street,
            'city' => $address->city,
            'province' => $address->province,
            'postal_code' => $address->postal_code,
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
