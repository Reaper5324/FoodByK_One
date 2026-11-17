<?php

require_once __DIR__ . '/../foodbyk/models/model.php';
require_once __DIR__ . '/../foodbyk/models/Address.php';
require_once __DIR__ . '/../foodbyk/models/Order.php';
require_once __DIR__ . '/../foodbyk/models/Product.php';
require_once __DIR__ . '/../foodbyk/models/Promotion.php';
require_once __DIR__ . '/../foodbyk/services/AuthService.php';
require_once __DIR__ . '/../foodbyk/services/ProductService.php';
require_once __DIR__ . '/../foodbyk/services/ProductOptionService.php';
require_once __DIR__ . '/../foodbyk/services/InputValidator.php';
require_once __DIR__ . '/../foodbyk/services/CartService.php';

function expect(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([1 => 1, 6 => 6, '1' => 1, '6' => 6] as $input => $expected) {
    expect(InputValidator::positiveInteger($input) === $expected, 'Positive integer validator rejected a valid whole-number quantity.');
}
foreach ([0, -1, 1.5, 1.0, '1.5', '1e3', 'abc', '', ' ', '+1', '0006', '999999999999999999999999999999'] as $input) {
    expect(InputValidator::positiveInteger($input) === null, 'Positive integer validator accepted malformed or out-of-range input.');
}
$cartService = new CartService();
foreach ([0, -1, 1.5, 1.0, '1.5', 'abc', '', '1e3', '999999999999999999999999999999'] as $invalidQuantity) {
    expect(!$cartService->addItem(1, 1, $invalidQuantity)['success'], 'Cart additions must reject malformed quantities before touching persistence.');
    expect(!$cartService->updateQuantity(1, 1, $invalidQuantity)['success'], 'Cart updates must reject malformed quantities before touching persistence.');
}

$order = new Order(subtotal: 100.0, locked_discount: 15.0, delivery_fee: 10.0);
expect($order->total() === 95.0, 'Order total must apply the locked discount.');
expect($order->canTransitionTo(Order::STATUS_ACCEPTED), 'Submitted order must be accepted.');
expect(!$order->canTransitionTo(Order::STATUS_PAID), 'Submitted order cannot be paid directly.');
$completedOrder = new Order(status: Order::STATUS_COMPLETED);
expect(!$completedOrder->canTransitionTo(Order::STATUS_PREPARING), 'Completed orders must be terminal.');
$paidOrder = new Order(status: Order::STATUS_PAID);
expect(!$paidOrder->canTransitionTo(Order::STATUS_CANCELLED), 'The general state machine must not allow paid-order cancellation without the guarded refund workflow.');
$preparingOrder = new Order(status: Order::STATUS_PREPARING);
expect(!$preparingOrder->canTransitionTo(Order::STATUS_CANCELLED), 'Preparing-order cancellation must also go through the guarded refund workflow.');

$address = new Address(latitude: -26.2041, longitude: 28.0473);
expect($address->hasCoordinates(), 'Valid coordinates must be accepted.');
$address->latitude = 91.0;
expect(!$address->hasCoordinates(), 'Out-of-range coordinates must be rejected.');
$address->latitude = -26.2;
$address->longitude = null;
expect(!$address->hasCoordinates(), 'Incomplete coordinate pairs must be rejected.');

$items = [
    ['product_id' => 1, 'quantity' => 3, 'unit_price' => 20.0],
    ['product_id' => 2, 'quantity' => 2, 'unit_price' => 15.0],
];

$percentage = new Promotion(discount_type: Promotion::TYPE_PERCENTAGE, discount_value: 10.0);
expect($percentage->calculateDiscount(100.0) === 10.0, 'Percentage promotion calculation failed.');
$cappedPercentage = new Promotion(discount_type: Promotion::TYPE_PERCENTAGE, discount_value: 150.0);
expect($cappedPercentage->calculateDiscount(100.0) === 100.0, 'Percentage discounts must not exceed the item subtotal.');

$fixed = new Promotion(discount_type: Promotion::TYPE_FIXED_AMOUNT, discount_value: 25.0);
expect($fixed->calculateDiscount(100.0) === 25.0, 'Fixed promotion calculation failed.');

$bogo = new Promotion(discount_type: Promotion::TYPE_BUY_ONE_GET_ONE);
expect($bogo->calculateDiscount(90.0, $items) === 0.0, 'BOGO without exactly one target item must fail closed.');

$delivery = new Promotion(discount_type: Promotion::TYPE_FREE_DELIVERY);
expect($delivery->calculateDiscount(100.0, [], 15.0) === 15.0, 'Free-delivery promotion calculation failed.');

$targetedItems = [
    ['product_id' => 1, 'product_name' => 'Kota', 'quantity' => 2, 'unit_price' => 30.0],
    ['product_id' => 2, 'product_name' => 'Burger', 'quantity' => 1, 'unit_price' => 20.0],
];
$targetedPercentage = new Promotion(discount_type: Promotion::TYPE_PERCENTAGE, discount_value: 50.0);
$targetedPercentage->target_product_ids = [1];
expect($targetedPercentage->calculateDiscount(80.0, $targetedItems) === 30.0, 'Targeted percentage must discount only selected products.');
expect($targetedPercentage->eligibleProductNames($targetedItems) === ['Kota'], 'Promotion checkout targets must reflect eligible basket items.');
$targetedFixed = new Promotion(discount_type: Promotion::TYPE_FIXED_AMOUNT, discount_value: 50.0);
$targetedFixed->target_product_ids = [2];
expect($targetedFixed->calculateDiscount(80.0, $targetedItems) === 20.0, 'Targeted fixed discount must cap at selected product subtotal.');
$targetedBogo = new Promotion(discount_type: Promotion::TYPE_BUY_ONE_GET_ONE);
$targetedBogo->target_product_ids = [1];
expect($targetedBogo->calculateDiscount(80.0, $targetedItems) === 60.0, 'Targeted BOGO must discount one free unit for every paid target unit.');
expect($targetedBogo->appliedProductNames($targetedItems) === ['Kota'], 'BOGO checkout details must name the qualifying target product.');
$bogoCartLines = [
    ['cart_item_id' => 20, 'product_id' => 1, 'product_name' => 'Kota', 'quantity' => 1, 'unit_price' => 30.0],
    ['cart_item_id' => 22, 'product_id' => 1, 'product_name' => 'Kota', 'quantity' => 3, 'unit_price' => 25.0],
    ['cart_item_id' => 21, 'product_id' => 2, 'product_name' => 'Burger', 'quantity' => 4, 'unit_price' => 20.0],
];
expect($targetedBogo->freeQuantitiesByCartItem($bogoCartLines) === [20 => 1, 22 => 3], 'BOGO must add one matching free unit per paid target unit and never a non-target item.');
$noTargetQuantity = $targetedBogo->freeQuantitiesByCartItem([[
    'cart_item_id' => 22, 'product_id' => 2, 'product_name' => 'Burger', 'quantity' => 1, 'unit_price' => 20.0,
]]);
expect($noTargetQuantity === [], 'BOGO must not add a free item when the targeted item is absent.');
$targetedDelivery = new Promotion(discount_type: Promotion::TYPE_FREE_DELIVERY);
$targetedDelivery->target_product_ids = [1];
expect($targetedDelivery->calculateDiscount(80.0, $targetedItems, 18.0) === 18.0, 'Targeted free delivery must apply when an eligible product is present.');
expect($targetedDelivery->calculateDiscount(80.0, [$targetedItems[1]], 18.0) === 0.0, 'Targeted free delivery must not apply without an eligible product.');

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
$optionService = new ProductOptionService();
$validOptions = $optionService->validateList([
    ['name' => ' Extra cheese ', 'price_delta' => '8.005'],
    ['name' => 'Extra egg', 'price_delta' => 0],
]);
expect($validOptions['success'], 'Valid free and paid product options must be accepted.');
expect($validOptions['data'][0] === ['name' => 'Extra cheese', 'price_delta' => 8.01], 'Option names must be trimmed and prices rounded to cents.');
expect($validOptions['data'][1]['price_delta'] === 0.0, 'Free options must be accepted at zero cost.');
expect(!$optionService->validateList('not an array')['success'], 'Non-array option collections must be rejected.');
expect(!$optionService->validateList([['name' => ['invalid'], 'price_delta' => 1]])['success'], 'Non-text option names must be rejected.');
expect(!$optionService->validateList([['name' => '', 'price_delta' => 1]])['success'], 'Empty option names must be rejected.');
expect(!$optionService->validateList([['name' => 'Cheese', 'price_delta' => -0.01]])['success'], 'Negative option prices must be rejected.');
expect(!$optionService->validateList([['name' => 'Cheese', 'price_delta' => 'free']])['success'], 'Non-numeric option prices must be rejected.');
expect(!$optionService->validateList([['name' => 'Cheese', 'price_delta' => 100000.01]])['success'], 'Option prices over the supported limit must be rejected.');
expect(!$optionService->validateList([['name' => str_repeat('x', 81), 'price_delta' => 1]])['success'], 'Option names over 80 characters must be rejected.');
$duplicateOptions = $optionService->validateList([
    ['name' => 'Cheese', 'price_delta' => 1],
    ['name' => ' cheese ', 'price_delta' => 2],
]);
expect(!$duplicateOptions['success'], 'Duplicate option names must be rejected case-insensitively.');
$tooManyOptions = $optionService->validateList(array_fill(0, 31, ['name' => 'Extra', 'price_delta' => 1]));
expect(!$tooManyOptions['success'], 'More than 30 options must be rejected.');

$validProduct = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => 'A burger',
    'price' => '49.99',
    'is_available' => '1',
]);
expect($validProduct['success'], 'Valid product input must be accepted.');
expect($validProduct['data']['price'] === 49.99, 'Validated prices must be normalized to currency precision.');
$productWithoutDescription = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'price' => '49.99',
]);
expect($productWithoutDescription['success'], 'Product descriptions may be omitted when creating a product.');
expect($productWithoutDescription['data']['description'] === '', 'Omitted product descriptions must default to an empty string.');
$existingProduct = new Product(category_id: 1, name: 'Classic Burger', description: 'Original description', price: 49.99);
$productWithoutDescriptionUpdate = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'price' => '49.99',
], $existingProduct);
expect($productWithoutDescriptionUpdate['success'], 'Product updates may omit the description.');
expect($productWithoutDescriptionUpdate['data']['description'] === 'Original description', 'Omitting a description during update must preserve its current value.');
$updatedProductDescription = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => '  Updated description  ',
    'price' => '49.99',
], $existingProduct);
expect($updatedProductDescription['success'], 'Product descriptions must be editable.');
expect($updatedProductDescription['data']['description'] === 'Updated description', 'Updated product descriptions must be trimmed and preserved.');
$clearedProductDescription = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => null,
    'price' => '49.99',
], $existingProduct);
expect($clearedProductDescription['success'], 'Product descriptions must be clearable with null.');
expect($clearedProductDescription['data']['description'] === '', 'A null product description must clear the current value.');
$invalidProductDescription = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => ['not', 'text'],
    'price' => '49.99',
]);
expect(!$invalidProductDescription['success'], 'Non-text product descriptions must be rejected.');
$invalidProduct = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => '',
    'price' => '-1',
]);
expect(!$invalidProduct['success'], 'Negative product prices must be rejected.');
expect(!$productService->validateProductInput([
    'category_id' => 1,
    'name' => str_repeat('x', 151),
    'price' => '1.00',
])['success'], 'Product names over the supported limit must be rejected.');
expect(!$productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Large description',
    'description' => str_repeat('x', 2001),
    'price' => '1.00',
])['success'], 'Product descriptions over the supported limit must be rejected.');
$invalidImageProduct = $productService->validateProductInput([
    'category_id' => 1,
    'name' => 'Classic Burger',
    'description' => 'A burger',
    'price' => '49.99',
    'image_url' => 'javascript:alert(1)',
]);
expect(!$invalidImageProduct['success'], 'Unsafe product image URL schemes must be rejected.');
$invalidCategoryProduct = $productService->validateProductInput([
    'category_id' => 0,
    'name' => 'Classic Burger',
    'description' => 'A burger',
    'price' => '49.99',
]);
expect(!$invalidCategoryProduct['success'], 'Invalid product category identifiers must be rejected.');

expect($auth->validateRegistrationInput('Customer', 'bad-email', 'Str0ng!Passw0rd', null) !== null, 'Malformed registration email must be rejected.');
expect($auth->validateRegistrationInput('Customer', 'customer@example.com', 'Str0ng!Passw0rd', '12345') !== null, 'Malformed international phone numbers must be rejected.');
expect($auth->validateRegistrationInput('Customer', 'customer@example.com', 'Str0ng!Passw0rd', '+27820000000') === null, 'Valid international registration data must pass validation.');

echo "Model checks passed.\n";
