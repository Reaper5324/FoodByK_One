<?php

class RateLimitMiddleware implements Middleware{

public function __construct(
    private int $maxAttempts = 8,
    private int $windowSeconds = 350
)
{
    throw new \Exception('Not implemented');
}



public function handle(Request $request): ?Response{
$identifier = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$db = Database::getConnection();

$stmt = $db->prepare(
    "SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL ? SECOND)"
);

$stmt->execute([$identifier, $this->windowSeconds]);

if((int) $stmt->fetchColumn() >= $this->maxAttempts){
    return Response::error('Too many Attempts. Please try again later.', 429);

}

$db->prepare("INSERT INTO login_attempts (identifier) VALUES (?)")->execute([$identifier]);
        return null;

}
}