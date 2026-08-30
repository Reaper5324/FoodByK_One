<?php

class CsrfMiddleware implements Middleware {
    public function handle(Request $request): ?Response {
        $sessionToken = $this->ensureToken();

        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            header('X-CSRF-Token: ' . $sessionToken);
            return null;
        }

        $token = $request->header('X-CSRF-Token')
            ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
            ?? $request->input('csrf_token');

        if (!is_string($token) || !hash_equals($sessionToken, $token)) {
            return Response::error('Invalid or missing CSRF token.', 419);
        }

        return null;
    }

    private function ensureToken(): string {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }
}
