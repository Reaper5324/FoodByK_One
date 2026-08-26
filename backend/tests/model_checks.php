<?php

require_once __DIR__ . '/../foodbyk/models/model.php';
require_once __DIR__ . '/../foodbyk/models/Address.php';
require_once __DIR__ . '/../foodbyk/models/Order.php';
require_once __DIR__ . '/../foodbyk/models/Product.php';
require_once __DIR__ . '/../foodbyk/models/Promotion.php';
require_once __DIR__ . '/../foodbyk/services/AuthService.php';
require_once __DIR__ . '/../foodbyk/services/ProductService.php';

function expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$order = new Order(subtotal: 100.0, locked_discount: 15.0, delivery_fee: 10.0);
expect($order->total() === 95.0, 'Order total must apply the locked discount.');
expect($order->canTransitionTo(Order::STATUS_ACCEPTED), 'Submitted order must be accepted.');
expect(!$order->canTransitionTo(Order::STATUS_PAID), 'Submitted order cannot be paid directly.');

$address = new Address(latitude: -26.2041, longitude: 28.0473);
expect($address->hasCoordinates(), 'Valid coordinates must be accepted.');
$address->latitude = 91.0;
expect(!$address->hasCoordinates(), 'Out-of-range coordinates must be rejected.');

$items = [
    ['product_id' => 1, 'quantity' => 3, 'unit_price' => 20.0],
    ['product_id' => 2, 'quantity' => 2, 'unit_price' => 15.0],
];

$percentage = new Promotion(discount_type: Promotion::TYPE_PERCENTAGE, discount_value: 10.0);
expect($percentage->calculateDiscount(100.0) === 10.0, 'Percentage promotion calculation failed.');

$fixed = new Promotion(discount_type: Promotion::TYPE_FIXED_AMOUNT, discount_value: 25.0);
expect($fixed->calculateDiscount(100.0) === 25.0, 'Fixed promotion calculation failed.');

$bogo = new Promotion(discount_type: Promotion::TYPE_BUY_ONE_GET_ONE);
expect($bogo->calculateDiscount(90.0, $items) === 35.0, 'BOGO promotion calculation failed.');

$delivery = new Promotion(discount_type: Promotion::TYPE_FREE_DELIVERY);
expect($delivery->calculateDiscount(100.0, [], 15.0) === 15.0, 'Free-delivery promotion calculation failed.');

$expired = new Promotion(
    discount_type: Promotion::TYPE_PERCENTAGE,
    discount_value: 10.0,
    end_date: '2020-01-01 00:00:00'
);
expect($expired->calculateDiscount(100.0) === 0.0, 'Expired promotion must not apply.');

$auth = new AuthService();
expect($auth->validatePassword('Str0ng!Passw0rd') === null, 'Strong password must be accepted.');
expect($auth->validatePassword('weak') !== null, 'Weak password must be rejected.');
expect(
    $auth->validatePassword('Customer!1234', 'customer@example.com') !== null,
    'Password containing the email local-part must be rejected.'
);

$productService = new ProductService();
$validProduct = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => 'A burger',
    'price' => '49.99',
    'is_available' => '1',
]);
expect($validProduct['success'], 'Valid product input must be accepted.');
$invalidProduct = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => '',
    'price' => '-1',
]);
expect(!$invalidProduct['success'], 'Negative product prices must be rejected.');

echo "Model checks passed.\n";
