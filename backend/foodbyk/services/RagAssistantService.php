<?php

class RagAssistantService {
    private const MAX_QUESTION_LENGTH = 500;
    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function ask(string $question): array {
        $question = trim($question);
        if ($question === '' || $this->stringLength($question) > self::MAX_QUESTION_LENGTH) {
            return $this->failure('Ask a question using 1 to 500 characters.', 422);
        }
        if (RAG_SERVICE_URL === '' || RAG_SERVICE_API_KEY === '') {
            error_log('RAG assistant is not configured.');
            return $this->failure('The Food by K assistant is temporarily unavailable.', 503);
        }

        $payload = json_encode(['question' => $question], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return $this->failure('Unable to process that question.', 400);
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . 'X-RAG-Service-Key: ' . RAG_SERVICE_API_KEY . "\r\n",
                'content' => $payload,
                'timeout' => self::REQUEST_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $responseBody = @file_get_contents(RAG_SERVICE_URL . '/ask', false, $context);
        $statusLine = $http_response_header[0] ?? '';
        preg_match('/\s(\d{3})\s/', $statusLine, $statusMatch);
        $upstreamStatus = isset($statusMatch[1]) ? (int) $statusMatch[1] : 0;

        if (!is_string($responseBody) || $upstreamStatus < 200 || $upstreamStatus >= 300) {
            error_log('RAG assistant upstream request failed with HTTP ' . ($upstreamStatus ?: 'unavailable') . '.');
            return $this->failure('The Food by K assistant is temporarily unavailable. Please try again shortly.', 503);
        }

        $result = json_decode($responseBody, true);
        $answer = is_array($result) ? trim((string) ($result['answer'] ?? '')) : '';
        if ($answer === '' || $this->stringLength($answer) > 3000) {
            error_log('RAG assistant returned an invalid answer.');
            return $this->failure('The Food by K assistant could not prepare an answer. Please try again.', 503);
        }

        $sources = [];
        foreach (($result['sources'] ?? []) as $source) {
            if (!is_array($source)) continue;
            $label = trim((string) ($source['source'] ?? ''));
            if ($label === '') continue;
            $sources[] = ['source' => function_exists('mb_substr') ? mb_substr($label, 0, 160) : substr($label, 0, 160)];
            if (count($sources) >= 5) break;
        }

        return [
            'success' => true,
            'data' => ['answer' => $answer, 'sources' => $sources],
            'error' => null,
        ];
    }

    public function failure(string $message, int $status = 400): array {
        return ['success' => false, 'data' => null, 'error' => $message, 'status' => $status];
    }

    private function stringLength(string $value): int {
        return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    }
}
