<?php

function registerRoutes(Router $router): void {
    $router->post('/auth/register', [AuthController::class, 'register'], [new RateLimitMiddleware()]);
    $router->post('/auth/login', [AuthController::class, 'login'], [new RateLimitMiddleware()]);
    $router->post('/auth/forgot-password', [AuthController::class, 'forgotPassword'], [new RateLimitMiddleware()]);
}
