<?php

abstract class Controller {

    protected function respond(array $serviceResult, int $successCode = 200, int $failureCode = 400): Response {
        return Response::fromService($serviceResult, $successCode, $failureCode);
    }

    protected function redirect(string $url, int $status = 302): Response {
        return Response::redirect($url, $status);
    }

    // Route params come from the Router as an explicit array argument to
    // each controller method - not $_REQUEST, which the router never
    // populates. This is a thin readability wrapper around that array.
    protected function param(array $params, string $key): ?string {
        return $params[$key] ?? null;
    }
}