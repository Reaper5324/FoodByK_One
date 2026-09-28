<?php

class Payment extends Model implements JsonSerializable {

protected static string $table = 'payments';

const STATUS_TOKENIZED      = 'tokenized';
const STATUS_CHARGE_PENDING = 'charge_pending';
const STATUS_SUCCESS        = 'success';
const STATUS_FAILED         = 'failed';
const STATUS_VOIDED         = 'voided';

public function __construct(
    public int     $order_id          = 0,
    public string  $gateway           = 'payfast',
    public ?string $gateway_token     = null,
    public ?string $gateway_reference = null,
    public float   $amount            = 0.0,
    public string  $status            = self::STATUS_TOKENIZED,
    public ?string $created_at        = null,
    public ?string $charged_at        = null
) {}

public function beginCharge(): bool {
    if ($this->status !== self::STATUS_TOKENIZED) return false;
    $this->status = self::STATUS_CHARGE_PENDING;
    return $this->save();
}

public function markSuccessful(string $gatewayReference): bool {
    if ($this->status === self::STATUS_SUCCESS) return true; // idempotent - duplicate ITN
    if ($this->status !== self::STATUS_CHARGE_PENDING || $gatewayReference === '') {
        return false;
    }

    $this->status            = self::STATUS_SUCCESS;
    $this->gateway_reference = $gatewayReference;
    $this->charged_at        = date('Y-m-d H:i:s');
    $ok = $this->save();

    return $ok;
}

public function markFailed(): bool {
    if ($this->status !== self::STATUS_CHARGE_PENDING) {
        return false;
    }

    $this->status = self::STATUS_FAILED;
    return $this->save();
}

public function voidToken(): bool {
    if ($this->status !== self::STATUS_TOKENIZED) {
        return false;
    }

    $this->status = self::STATUS_VOIDED;
    return $this->save();
}

protected function toArray(): array {
    return [
        'order_id'          => $this->order_id,
        'gateway'           => $this->gateway,
        'gateway_token'     => self::encryptGatewayToken($this->gateway_token),
        'gateway_reference' => $this->gateway_reference,
        'amount'            => $this->amount,
        'status'            => $this->status,
        'charged_at'        => $this->charged_at,
    ];
}

protected static function fromRow(array $row): static {
    $p                    = new static();
    $p->id                = (int)   $row['id'];
    $p->order_id          = (int)   $row['order_id'];
    $p->gateway           =         $row['gateway'] ?? 'payfast';
    $p->gateway_token     = self::decryptGatewayToken($row['gateway_token'] ?? null);
    $p->gateway_reference =         $row['gateway_reference'] ?? null;
    $p->amount            = (float) $row['amount'];
    $p->status            =         $row['status'];
    $p->created_at        =         $row['created_at'] ?? null;
    $p->charged_at        =         $row['charged_at'] ?? null;
    return $p;
}

public function jsonSerialize(): array {
    return [
        'id' => $this->id,
        'order_id' => $this->order_id,
        'gateway' => $this->gateway,
        'gateway_reference' => $this->gateway_reference,
        'amount' => $this->amount,
        'status' => $this->status,
        'created_at' => $this->created_at,
        'charged_at' => $this->charged_at,
    ];
}

private static function encryptGatewayToken(?string $token): ?string {
    if ($token === null || $token === '') return null;
    if (PAYMENT_TOKEN_ENCRYPTION_KEY === '') throw new RuntimeException('Payment token encryption key is not configured.');
    $key = base64_decode(PAYMENT_TOKEN_ENCRYPTION_KEY, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Payment token encryption key must be a base64-encoded 32-byte key.');
    }
    $nonce = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) throw new RuntimeException('Unable to encrypt payment token.');
    return 'enc:v1:' . base64_encode($nonce . $tag . $ciphertext);
}

private static function decryptGatewayToken(?string $stored): ?string {
    if ($stored === null || $stored === '' || !str_starts_with($stored, 'enc:v1:')) return $stored;
    $key = base64_decode(PAYMENT_TOKEN_ENCRYPTION_KEY, true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('Payment token encryption key is missing or invalid.');
    }
    $payload = base64_decode(substr($stored, 7), true);
    if ($payload === false || strlen($payload) < 29) throw new RuntimeException('Stored payment token is invalid.');
    $token = openssl_decrypt(substr($payload, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($payload, 0, 12), substr($payload, 12, 16));
    if ($token === false) throw new RuntimeException('Unable to decrypt payment token. Verify the encryption key.');
    return $token;
}

}
