<?php

class AssistantController extends Controller {
    public function ask(Request $request): Response {
        $origin = $request->header('Origin') ?? ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '' && !$this->isAllowedOrigin($origin)) {
            return Response::error('This origin cannot use the Food by K assistant.', 403);
        }
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 4096) {
            return Response::error('Question is too large.', 413);
        }
        $question = $request->input('question');
        if (!is_string($question)) {
            return Response::error('A question is required.', 422);
        }

        $result = (new RagAssistantService())->ask($question);
        $failureStatus = (int) ($result['status'] ?? 400);
        unset($result['status']);
        return $this->respond($result, 200, $failureStatus);
    }

    private function isAllowedOrigin(string $origin): bool {
        $allowed = [
            'https://foodbyks.netlify.app',
            'http://localhost:5500',
            'http://127.0.0.1:5500',
            'http://localhost:8080',
            'http://127.0.0.1:8080',
        ];
        if (FRONTEND_URL !== '') {
            $parts = parse_url(FRONTEND_URL);
            if (is_array($parts) && isset($parts['scheme'], $parts['host'])) {
                $configured = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
                if (isset($parts['port'])) $configured .= ':' . $parts['port'];
                $allowed[] = $configured;
            }
        }
        return in_array(strtolower(rtrim($origin, '/')), $allowed, true);
    }
}
