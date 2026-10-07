<?php

function registerRoutes(Router $router): void {
    $router->post('/auth/register', [AuthController::class, 'register']);
    $router->post('/auth/login', [AuthController::class, 'login'], [new RateLimitMiddleware('login')]);
    $router->post('/auth/forgot-password', [AuthController::class, 'requestPasswordReset'], [new RateLimitMiddleware('request')]);
    $router->post('/auth/reset-password', [AuthController::class, 'resetPassword'], [new RateLimitMiddleware('reset')]);
    

    $router->get('/health', [HealthController::class, 'check']);
    $router->get('/products', [ProductController::class, 'index']);
    $router->get('/products/search', [ProductController::class, 'search']);
    $router->get('/products/{id}', [ProductController::class, 'show']);
    $router->get('/categories', [CategoryController::class, 'index']);
    $router->get('/categories/{id}', [CategoryController::class, 'show']);
    $router->get('/categories/{id}/products', [CategoryController::class, 'products']);
    $router->get('/products/category/{id}', [ProductController::class, 'byCategory']);
    $router->get('/promotions/active', [PromotionController::class, 'active']);

    $customer = [new AuthMiddleware(), RoleMiddleware::customer(), new CsrfMiddleware()];
    $staff = [new AuthMiddleware(), RoleMiddleware::staffOrAdmin(), new CsrfMiddleware()];
    $admin = [new AuthMiddleware(), RoleMiddleware::admin(), new CsrfMiddleware()];

    $authenticated = [new AuthMiddleware(), new CsrfMiddleware()];
    $router->post('/auth/logout', [AuthController::class, 'logout'], $authenticated);
    $router->get('/auth/me', [AuthController::class, 'me'], [new AuthMiddleware(), new CsrfMiddleware()]);
    $router->post('/auth/change-password', [AuthController::class, 'changePassword'], $authenticated);

    $router->post('/cart/items', [CartController::class, 'add'], $customer);
    $router->get('/cart', [CartController::class, 'view'], [new AuthMiddleware(), RoleMiddleware::customer(), new CsrfMiddleware()]);
    $router->put('/cart/items/{id}', [CartController::class, 'updateQuantity'], $customer);
    $router->delete('/cart/items/{id}', [CartController::class, 'removeItem'], $customer);
    $router->delete('/cart', [CartController::class, 'clear'], $customer);

    $router->post('/checkout/preview', [CheckoutController::class, 'preview'], $customer);
    $router->get('/checkout/slots', [CheckoutController::class, 'slots'], [new AuthMiddleware(), RoleMiddleware::customer()]);
    $router->post('/checkout/submit', [CheckoutController::class, 'submit'], $customer);

    $router->get('/addresses', [AddressController::class, 'index'], [new AuthMiddleware(), RoleMiddleware::customer()]);
    $router->post('/addresses', [AddressController::class, 'create'], $customer);
    $router->put('/addresses/{id}', [AddressController::class, 'update'], $customer);
    $router->delete('/addresses/{id}', [AddressController::class, 'remove'], $customer);
    $router->post('/addresses/{id}/default', [AddressController::class, 'setDefault'], $customer);

    $router->post('/payments/token-webhook', [PaymentController::class, 'tokenWebhook']);
    $router->post('/payments/charge-webhook', [PaymentController::class, 'chargeWebhook']);
    $router->get('/payments/return', [PaymentController::class, 'returnFromPayFast']);
    $router->get('/payments/cancel', [PaymentController::class, 'cancelFromPayFast']);

    $router->get('/staff/orders/incoming', [OrderController::class, 'incoming'], [new AuthMiddleware(), RoleMiddleware::staffOrAdmin()]);
    $router->get('/staff/orders', [OrderController::class, 'staffIndex'], [new AuthMiddleware(), RoleMiddleware::staffOrAdmin()]);
    $router->get('/staff/products', [ProductController::class, 'staffIndex'], [new AuthMiddleware(), RoleMiddleware::staffOrAdmin()]);
    $router->put('/staff/products/{id}/availability', [ProductController::class, 'setStaffAvailability'], $staff);
    $router->get('/staff/analytics', [AnalyticsController::class, 'staffSummary'], [new AuthMiddleware(), RoleMiddleware::staffOrAdmin()]);
    $router->post('/staff/orders/{id}/adjust', [OrderController::class, 'adjust'], $staff);
    $router->post('/staff/orders/{id}/confirm', [OrderController::class, 'confirm'], $staff);
    $router->post('/staff/orders/{id}/decline', [OrderController::class, 'decline'], $staff);
    $router->post('/staff/orders/{id}/advance', [OrderController::class, 'advance'], $staff);
    $router->get('/orders', [OrderController::class, 'index'], [new AuthMiddleware(), RoleMiddleware::customer()]);
    $router->get('/orders/{id}', [OrderController::class, 'show'], [new AuthMiddleware(), RoleMiddleware::customer()]);
    $router->post('/orders/{id}/cancel', [OrderController::class, 'cancel'], $customer);

    $router->get('/admin/analytics', [AnalyticsController::class, 'adminSummary'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->get('/admin/staff', [AdminController::class, 'staff'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->get('/admin/products', [AdminController::class, 'products'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->get('/admin/promotions', [AdminController::class, 'promotions'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->get('/admin/settings', [AdminController::class, 'settings'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->get('/admin/customers', [AdminController::class, 'customers'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->put('/admin/customers/{id}', [AdminController::class, 'updateCustomer'], [new AuthMiddleware(), RoleMiddleware::admin(), new CsrfMiddleware()]);
    $router->post('/admin/staff', [AdminController::class, 'addStaff'], $admin);
    $router->put('/admin/staff/{id}', [AdminController::class, 'updateStaff'], $admin);
    $router->delete('/admin/staff/{id}', [AdminController::class, 'removeStaff'], $admin);
    $router->post('/admin/products', [AdminController::class, 'addProduct'], $admin);
    $router->put('/admin/products/{id}', [AdminController::class, 'updateProduct'], $admin);
    $router->delete('/admin/products/{id}', [AdminController::class, 'removeProduct'], $admin);
    $router->post('/admin/promotions', [AdminController::class, 'addPromotion'], $admin);
    $router->put('/admin/promotions/{id}', [AdminController::class, 'updatePromotion'], $admin);
    $router->delete('/admin/promotions/{id}', [AdminController::class, 'removePromotion'], $admin);
    $router->put('/admin/settings', [AdminController::class, 'updateSettings'], $admin);
    $router->get('/admin/categories', [AdminController::class, 'categories'], [new AuthMiddleware(), RoleMiddleware::admin()]);
    $router->post('/admin/categories', [AdminController::class, 'addCategory'], $admin);
    $router->put('/admin/categories/{id}', [AdminController::class, 'updateCategory'], $admin);
    $router->delete('/admin/categories/{id}', [AdminController::class, 'removeCategory'], $admin);
        
}
