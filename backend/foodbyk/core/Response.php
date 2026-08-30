<?php

class Response {

    private ?string $redirectUrl = null;

    private function __construct(
        private array $payload,
        private int $statusCode
    ) {}

    public static function json(array $payload, int $statusCode = 200): self {
        return new self($payload, $statusCode);
    }

    public static function success(mixed $data = null, int $statusCode = 200): self {
        return new self(['success' => true, 'data' => $data, 'error' => null], $statusCode);
    }

    public static function error(string $message, int $statusCode = 400): self {
        return new self(['success' => false, 'data' => null, 'error' => $message], $statusCode);
    }

    public static function fromService(array $result, int $successCode = 200, int $failureCode = 400): self {
        if ($result['success'] ?? false) {
            return new self($result, $successCode);
        }
        return new self($result, $failureCode);
    }

    // For real browser navigations only (e.g. the PayFast return/cancel
    // trip) - controllers still return this like any other Response, the
    // Router still sends it. No exit() here or anywhere else in this class.
    public static function redirect(string $url, int $statusCode = 302): self {
        $response = new self([], $statusCode);
        $response->redirectUrl = $url;
        return $response;
    }

    public function send(): void {
        http_response_code($this->statusCode);

        if ($this->redirectUrl !== null) {
            header("Location: {$this->redirectUrl}");
            return;
        }

        header('Content-Type: application/json');
        echo json_encode($this->payload);
    }
}