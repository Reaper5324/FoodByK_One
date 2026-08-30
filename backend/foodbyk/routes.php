<?php

function registerRoutes(Router $router): void {
    $router->post('/auth/register', [AuthController::class, 'register'], [new RateLimitMiddleware()]);
    $router->post('/auth/login', [AuthController::class, 'login'], [new RateLimitMiddleware()]);
    $router->post('/auth/forgot-password', [AuthController::class, 'requestPasswordReset'], [new RateLimitMiddleware()]);
    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword']);

    $router->get('/health', [HealthController::class, 'check']);
    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/search', [ProductController::class, 'search']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->get('/categories', [CategoryController::class, 'index']);
    $router->get('/categories/{id}/products', [CategoryController::class, 'products']);
    $router->get('/promotions/active', [PromotionController::class, 'active']);

    $customer = [new AuthMiddleware(), RoleMiddleware::customer(), new CsrfMiddleware()];
    $staff = [new AuthMiddleware(), RoleMiddleware::staffOrAdmin(), new CsrfMiddleware()];
    $admin = [new AuthMiddleware(), RoleMiddleware::admin(), new CsrfMiddleware()];

    $router->post('/auth/logout', [AuthController::class, 'logout'], $customer);
    $router->get('/auth/me', [AuthController::class, 'me'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/auth/change-password', [AuthController::class, 'changePassword'], $customer);

    $router->post('/cart/items', [CartController::class, 'add'], $customer);
    $router->get('/cart', [CartController::class, 'view'], [new AuthMiddleware(), RoleMiddleware::customer()]);
    $router->put('/cart/items/{id}', [CartController::class, 'updateQuantity'], $customer);
    $router->delete('/cart/items/{id}', [CartController::class, 'removeItem'], $customer);
    $router->delete('/cart', [CartController::class, 'clear'], $customer);

    $router->post('/checkout/preview', [CheckoutController::class, 'preview'], $customer);
    $router->post('/checkout/submit', [CheckoutController::class, 'submit'], $customer);

    $router->post('/payments/token-webhook', [PaymentController::class, 'tokenWebhook']);
    $router->post('/payments/charge-webhook', [PaymentController::class, 'chargeWebhook']);
    $router->get('/payments/return', [PaymentController::class, 'returnFromPayFast']);
    $router->get('/payments/cancel', [PaymentController::class, 'cancelFromPayFast']);

    $router->get('/staff/orders/incoming', [OrderController::class, 'incoming'], [new AuthMiddleware(), RoleMiddleware::staffOrAdmin()]);
    $router->post('/staff/orders/{id}/confirm', [OrderController::class, 'confirm'], $staff);
    $router->post('/staff/orders/{id}/decline', [OrderController::class, 'decline'], $staff);
    $router->post('/staff/orders/{id}/advance', [OrderController::class, 'advance'], $staff);
    $router->post('/orders/{id}/cancel', [OrderController::class, 'cancel'], $customer);

    $router->post('/admin/staff', [AdminController::class, 'addStaff'], $admin);
    $router->post('/admin/products', [AdminController::class, 'addProduct'], $admin);
    $router->delete('/admin/products/{id}', [AdminController::class, 'removeProduct'], $admin);
}
