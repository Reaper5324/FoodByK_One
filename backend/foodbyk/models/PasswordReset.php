<?php

class PasswordReset extends Model {
    protected static string $table = 'password_resets';

    public function __construct(
        public int      $user_id = 0,
        public string   $token = '',
        public string   $token_hash = '',
        public ?string  $expires_at = null,
        public ?string  $created_at = null
    ) {}

    /**
     * Create a new password reset token
     */
    public static function create(int $userId, int $expiryHours = 24): array {
        // Generate random token
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        
        $reset = new static();
        $reset->user_id = $userId;
        $reset->token = $token;
        $reset->token_hash = $tokenHash;
        $reset->expires_at = date('Y-m-d H:i:s', time() + ($expiryHours * 3600));

        if ($reset->save()) {
            return ['success' => true, 'token' => $token];
        }

        return ['success' => false, 'error' => 'Failed to create reset token'];
    }

    /**
     * Find and validate a reset token
     */
    public static function findByToken(string $token): ?static {
        $tokenHash = hash('sha256', $token);
        $db = Database::getConnection();
        $stmt = $db->prepare(
            'SELECT * FROM password_resets 
             WHERE token_hash = ? 
             AND expires_at > NOW()
             LIMIT 1'
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch();

        return $row ? static::fromRow($row) : null;
    }

    /**
     * Delete expired tokens
     */
    public static function deleteExpired(): void {
        $db = Database::getConnection();
        $db->prepare('DELETE FROM password_resets WHERE expires_at <= NOW()')->execute();
    }

    /**
     * Delete all tokens for a user (cleanup after password reset)
     */
    public static function deleteForUser(int $userId): void {
        $db = Database::getConnection();
        $db->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
    }

    protected function toArray(): array {
        return [
            'user_id' => $this->user_id,
            'token_hash' => $this->token_hash,
            'expires_at' => $this->expires_at,
        ];
    }

    protected static function fromRow(array $row): static {
        $reset = new static();
        $reset->id = (int) $row['id'];
        $reset->user_id = (int) $row['user_id'];
        $reset->token = $row['token'] ?? '';
        $reset->token_hash = $row['token_hash'] ?? '';
        $reset->expires_at = $row['expires_at'] ?? null;
        $reset->created_at = $row['created_at'] ?? null;
        return $reset;
    }
}
