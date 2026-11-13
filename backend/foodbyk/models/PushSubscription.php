<?php

class PushSubscription extends Model {
    protected static string $table = 'push_subscriptions';

    public function __construct(
        public int $user_id = 0,
        public string $endpoint = '',
        public string $public_key = '',
        public string $auth_token = '',
        public ?string $user_agent = null
    ) {}

    protected function toArray(): array {
        return [
            'user_id' => $this->user_id,
            'endpoint' => $this->endpoint,
            'public_key' => $this->public_key,
            'auth_token' => $this->auth_token,
            'user_agent' => $this->user_agent,
        ];
    }

    protected static function fromRow(array $row): static {
        $subscription = new static(
            user_id: (int) $row['user_id'],
            endpoint: (string) $row['endpoint'],
            public_key: (string) $row['public_key'],
            auth_token: (string) $row['auth_token'],
            user_agent: $row['user_agent'] ?? null
        );
        $subscription->id = (int) $row['id'];
        return $subscription;
    }

    public static function deleteForUser(int $userId, string $endpoint): bool {
        $statement = Database::getConnection()->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?');
        return $statement->execute([$userId, $endpoint]);
    }

    public static function deleteEndpoint(string $endpoint): bool {
        $statement = Database::getConnection()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        return $statement->execute([$endpoint]);
    }
}
