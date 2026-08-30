<?php

class RateLimitMiddleware implements Middleware {
    public function __construct(
        private int $maxAttempts = 5,
        private int $windowSeconds = 300
    ) {
        if ($this->maxAttempts < 1 || $this->windowSeconds < 1) {
            throw new InvalidArgumentException('Rate-limit values must be positive.');
        }
    }

    public function handle(Request $request): ?Response {
        $identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $db = Database::getConnection();
        $cutoff = date('Y-m-d H:i:s', time() - $this->windowSeconds);

        $cleanup = $db->prepare('DELETE FROM login_attempts WHERE attempted_at <= ?');
        $cleanup->execute([$cutoff]);

        $stmt = $db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > ?'
        );
        $stmt->execute([$identifier, $cutoff]);

        if ((int) $stmt->fetchColumn() >= $this->maxAttempts) {
            return Response::error('Too many attempts. Please try again later.', 429);
        }

        $db->prepare('INSERT INTO login_attempts (identifier) VALUES (?)')->execute([$identifier]);
        return null;
    }
}
