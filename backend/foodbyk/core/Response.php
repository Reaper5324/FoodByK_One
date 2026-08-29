<?php

class Response {

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

    // Every service returns ['success'=>, 'data'=>, 'error'=>] - this is the
    // one translation point from that convention to an HTTP response.
    // Services don't know about HTTP status codes (correctly - that's not
    // their concern), so the controller supplies both codes per call.
    public static function fromService(array $result, int $successCode = 200, int $failureCode = 400): self {
        if ($result['success'] ?? false) {
            return new self($result, $successCode);
        }
        return new self($result, $failureCode);
    }

    public function send(): void {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        echo json_encode($this->payload);
    }
}