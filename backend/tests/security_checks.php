<?php

require_once __DIR__ . '/../foodbyk/models/model.php';
require_once __DIR__ . '/../foodbyk/models/Payment.php';

function securityExpect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

if (!defined('PAYMENT_TOKEN_ENCRYPTION_KEY')) {
    define('PAYMENT_TOKEN_ENCRYPTION_KEY', base64_encode(str_repeat('k', 32)));
}

class InspectablePayment extends Payment {
    public function persistenceValues(): array { return $this->toArray(); }
    public static function hydrateValues(array $row): static { return parent::fromRow($row); }
}

$rawToken = 'payfast-test-token-do-not-serialize';
$payment = new InspectablePayment(order_id: 7, gateway_token: $rawToken);
$serialized = json_encode($payment, JSON_THROW_ON_ERROR);
securityExpect(!str_contains($serialized, $rawToken), 'Payment JSON must never expose the gateway token.');
securityExpect(!str_contains($serialized, 'gateway_token'), 'Payment JSON must omit the gateway_token field.');

$stored = $payment->persistenceValues()['gateway_token'];
securityExpect(is_string($stored) && str_starts_with($stored, 'enc:v1:'), 'Payment tokens must be encrypted before persistence.');
securityExpect(!str_contains($stored, $rawToken), 'Persisted payment token must not contain plaintext.');
$secondEncryption = $payment->persistenceValues()['gateway_token'];
securityExpect($secondEncryption !== $stored, 'Payment token encryption must use a fresh nonce.');
$hydrated = InspectablePayment::hydrateValues([
    'id' => 7,
    'order_id' => 7,
    'gateway' => 'payfast',
    'gateway_token' => $stored,
    'gateway_reference' => null,
    'amount' => 10,
    'status' => Payment::STATUS_TOKENIZED,
]);
securityExpect($hydrated->gateway_token === $rawToken, 'Encrypted payment token must decrypt after loading.');
$tamperedPayload = base64_decode(substr($stored, 7), true);
$tamperedPayload[strlen($tamperedPayload) - 1] = chr(ord($tamperedPayload[strlen($tamperedPayload) - 1]) ^ 1);
$tampered = false;
try {
    InspectablePayment::hydrateValues([
        'id' => 7,
        'order_id' => 7,
        'gateway' => 'payfast',
        'gateway_token' => 'enc:v1:' . base64_encode($tamperedPayload),
        'gateway_reference' => null,
        'amount' => 10,
        'status' => Payment::STATUS_TOKENIZED,
    ]);
} catch (RuntimeException) {
    $tampered = true;
}
securityExpect($tampered, 'Tampered payment-token ciphertext must fail authentication.');

echo "Security checks passed.\n";
