<?php

abstract class Controller {

    protected function respond(array $serviceResult, int $successCode = 200, int $failureCode = 400): Response {
        return Response::fromService($serviceResult, $successCode, $failureCode);
    }

    protected function redirect(string $url, int $status = 302): Response {
        return Response::redirect($url, $status);
    }

    // Router passes route parameters separately from the request body.
    protected function param(array $params, string $key): ?string {
        return $params[$key] ?? null;
    }
}