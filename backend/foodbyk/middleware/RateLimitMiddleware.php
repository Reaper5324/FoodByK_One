<?php

class RateLimitMiddleware implements Middleware {
    public function __construct(
        private string $scope,
        private int $maxAttempts = 5,
        private int $windowSeconds = 300
    ) {
        if (!in_array($this->scope, ['login', 'reset', 'request', 'assistant', 'verification'], true)
            || $this->maxAttempts < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException('Invalid rate-limit configuration.');
        }
    }

    public function handle(Request $request): ?Response {
        $keys = $this->keysFor($request);
        $db = Database::getConnection();
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);
        $db->prepare('DELETE FROM login_attempts WHERE attempted_at <= ?')->execute([$cutoff]);

        foreach ($keys as $key) {
            $stmt = $db->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > ?');
            $stmt->execute([$key, $cutoff]);
            $limit = str_starts_with($key, 'login:ip:') ? max(25, $this->maxAttempts) : $this->maxAttempts;
            if ((int) $stmt->fetchColumn() >= $limit) {
                return Response::error('Too many attempts. Please try again later.', 429);
            }
        }

        // Limit reset requests so the endpoint cannot be used to spam email addresses.
        if (in_array($this->scope, ['request', 'assistant', 'verification'], true)) {
            self::storeKeys($db, $keys);
        }
        return null;
    }

    public static function recordLoginFailure(string $email): void {
        $keys = ['login:ip:' . self::clientIp()];
        $email = strtolower(trim($email));
        if ($email !== '') {
            $keys[] = 'login:email:' . hash('sha256', $email);
        }
        self::storeKeys(Database::getConnection(), $keys);
    }

    public static function recordResetFailure(): void {
        self::storeKeys(Database::getConnection(), ['reset:ip:' . self::clientIp()]);
    }

    private function keysFor(Request $request): array {
        $ip = self::clientIp();
        if ($this->scope === 'reset') return ['reset:ip:' . $ip];
        if ($this->scope === 'request') return ['request:ip:' . $ip];
        if ($this->scope === 'assistant') return ['assistant:ip:' . $ip];
        if ($this->scope === 'verification') {
            $email = strtolower(trim((string) $request->input('email', '')));
            $keys = ['verification:ip:' . $ip];
            if ($email !== '') $keys[] = 'verification:email:' . hash('sha256', $email);
            return $keys;
        }

        $email = strtolower(trim((string) $request->input('email', '')));
        $keys = ['login:ip:' . $ip];
        if ($email !== '') $keys[] = 'login:email:' . hash('sha256', $email);
        return $keys;
    }

    private static function clientIp(): string {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }

    private static function storeKeys(PDO $db, array $keys): void {
        $stmt = $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)');
        foreach (array_unique($keys) as $key) $stmt->execute([$key]);
    }
}
