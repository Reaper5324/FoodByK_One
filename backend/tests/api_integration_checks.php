<?php

$baseUrl = rtrim(getenv('API_BASE_URL') ?: 'http://127.0.0.1:8080', '/');
$dbName = getenv('TEST_DB_NAME') ?: '';
if ($dbName !== 'foodbyk_test') {
    fwrite(STDERR, "Refusing API integration checks: TEST_DB_NAME must be exactly foodbyk_test.\n");
    exit(2);
}

function apiExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function apiRequest(string $method, string $path, ?array $body = null, array $headers = [], ?string $cookieFile = null): array {
    global $baseUrl;
    $handle = curl_init($baseUrl . $path);
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    if ($body !== null) $requestHeaders[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    if ($cookieFile !== null) {
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieFile);
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieFile);
    }
    $raw = curl_exec($handle);
    if ($raw === false) {
        $message = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('API request failed: ' . $message);
    }
    $headerSize = curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    $rawHeaders = substr($raw, 0, $headerSize);
    $responseHeaders = [];
    foreach (preg_split('/\r?\n/', trim($rawHeaders)) as $line) {
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            $value = trim($value);
            if ($name === 'set-cookie') {
                // A response can carry several Set-Cookie headers; keep them all.
                $responseHeaders['set-cookie'][] = $value;
            } else {
                $responseHeaders[$name] = $value;
            }
        }
    }
    $responseBody = substr($raw, $headerSize);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'json' => json_decode($responseBody, true)];
}

/**
 * Returns the cookie name from a raw Set-Cookie header value.
 */
function cookieName(string $setCookie): string {
    return trim(explode('=', $setCookie, 2)[0]);
}

$pdo = new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TEST_DB_PORT') ?: '3306') . ';dbname=' . $dbName . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'root',
    getenv('TEST_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$email = 'api-check-' . bin2hex(random_bytes(8)) . '@example.test';
$secondEmail = 'api-check-owner-' . bin2hex(random_bytes(8)) . '@example.test';
$cookieFile = tempnam(sys_get_temp_dir(), 'foodbyk-cookie-');
$secondCookieFile = tempnam(sys_get_temp_dir(), 'foodbyk-cookie-');
$password = 'BlueRiver!9072';
$productId = null;

try {
    $health = apiRequest('GET', '/health');
    apiExpect($health['status'] === 200 && ($health['json']['success'] ?? false), 'Health endpoint must return a successful JSON envelope.');

    $products = apiRequest('GET', '/products');
    apiExpect($products['status'] === 200 && ($products['json']['success'] ?? false), 'Public products endpoint must be reachable.');
    apiExpect(is_array($products['json']['data'] ?? null), 'Public products must return a JSON list.');
    foreach ($products['json']['data'] as $product) {
        apiExpect(isset($product['id'], $product['name'], $product['price']), 'Public product records must include stable identifiers, names, and prices.');
        apiExpect(is_numeric($product['price']) && (float) $product['price'] >= 0, 'Public catalogue prices must be non-negative numeric values.');
        apiExpect(is_array($product['options'] ?? null), 'Public products must return product options as a list.');
    }

    $categories = apiRequest('GET', '/categories');
    apiExpect($categories['status'] === 200 && ($categories['json']['success'] ?? false)
        && is_array($categories['json']['data'] ?? null), 'Public categories must return a successful JSON list.');
    $promotions = apiRequest('GET', '/promotions/active');
    apiExpect($promotions['status'] === 200 && ($promotions['json']['success'] ?? false)
        && is_array($promotions['json']['data'] ?? null), 'Active promotions must return a successful JSON list.');
    $missingProduct = apiRequest('GET', '/products/999999999');
    apiExpect($missingProduct['status'] === 404, 'Unknown product identifiers must return not found.');

    $unauthenticatedCart = apiRequest('GET', '/cart');
    apiExpect($unauthenticatedCart['status'] === 401, 'Cart data must require authentication.');
    $unauthenticatedAdmin = apiRequest('GET', '/admin/staff');
    apiExpect($unauthenticatedAdmin['status'] === 401, 'Admin data must require authentication.');

    $invalidRegistration = apiRequest('POST', '/auth/register', ['name' => 'Bad Input', 'email' => 'not-an-email', 'password' => 'weak']);
    apiExpect($invalidRegistration['status'] >= 400 && $invalidRegistration['status'] < 500, 'Invalid registration data must be rejected by the API.');

    $registration = apiRequest('POST', '/auth/register', [
        'name' => 'Integration Customer',
        'email' => $email,
        'password' => $password,
        'phone' => '+27820000000',
        'terms_accepted' => true,
        'privacy_notice_acknowledged' => true,
    ], ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($registration['status'] === 201 && ($registration['json']['success'] ?? false), 'Valid customer registration must create an account.');
    apiExpect(($registration['json']['data']['verification_required'] ?? false) === true, 'Registration must require email verification.');
    apiExpect(!isset($registration['headers']['set-cookie']), 'Registration must not start a session before the email is verified.');

    // Unverified customers must not be able to sign in.
    $unverifiedLogin = apiRequest('POST', '/auth/login', ['email' => $email, 'password' => $password], ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect(($unverifiedLogin['json']['success'] ?? true) === false, 'Unverified customers must not be able to sign in.');
    apiExpect(($unverifiedLogin['json']['data']['email_verification_required'] ?? false) === true, 'Unverified login must report that email verification is required.');
    apiExpect(!isset($unverifiedLogin['headers']['set-cookie']), 'Unverified login must not set a session cookie.');

    // The real verification token is only emailed, so mark the email verified directly in the test DB.
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE email = ?')->execute([$email]);

    $login = apiRequest('POST', '/auth/login', ['email' => $email, 'password' => $password], ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($login['status'] === 200 && ($login['json']['success'] ?? false), 'Verified customer login must succeed.');

    // Every cookie the API sets, except a JS-readable CSRF cookie (if any), is
    // treated as an authentication cookie and must be HttpOnly with SameSite.
    $cookies = $login['headers']['set-cookie'] ?? [];
    apiExpect($cookies !== [], 'Login must set at least one cookie.');

    $authCookies = array_values(array_filter(
        $cookies,
        static fn (string $cookie): bool => stripos(cookieName($cookie), 'csrf') === false
    ));
    apiExpect($authCookies !== [], 'Login must set an authentication session cookie.');

    foreach ($authCookies as $authCookie) {
        $name = cookieName($authCookie);
        apiExpect(stripos($authCookie, 'httponly') !== false, "Authentication cookie '{$name}' must be HttpOnly.");
        apiExpect(stripos($authCookie, 'samesite=') !== false, "Authentication cookie '{$name}' must define SameSite behavior.");
    }

    $me = apiRequest('GET', '/auth/me', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($me['status'] === 200 && ($me['json']['success'] ?? false), 'Registration must establish a usable session.');
    apiExpect(isset($me['headers']['x-csrf-token']), 'Authenticated safe requests must expose a CSRF token.');
    apiExpect(!str_contains(json_encode($me['json'], JSON_THROW_ON_ERROR), 'password_hash'), 'Public account responses must not expose password hashes.');

    $missingCsrf = apiRequest('PUT', '/account/profile', ['full_name' => 'Integration Customer', 'email' => $email], [], $cookieFile);
    apiExpect($missingCsrf['status'] === 419, 'Authenticated writes must reject missing CSRF tokens.');
    $invalidCsrf = apiRequest('PUT', '/account/profile', ['full_name' => 'Forged Update', 'email' => $email], [
        'X-CSRF-Token: invalid-token',
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect($invalidCsrf['status'] === 419, 'Authenticated writes must reject invalid CSRF tokens.');

    $updated = apiRequest('PUT', '/account/profile', ['full_name' => 'Integration Customer Updated', 'email' => $email], [
        'X-CSRF-Token: ' . $me['headers']['x-csrf-token'],
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect($updated['status'] === 200 && ($updated['json']['success'] ?? false), 'Valid authenticated profile writes must succeed with CSRF protection.');

    $customerAdminAttempt = apiRequest('GET', '/admin/staff', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($customerAdminAttempt['status'] === 403, 'Customer sessions must not access admin resources.');
    $customerProductAdminAttempt = apiRequest('GET', '/admin/products', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($customerProductAdminAttempt['status'] === 403, 'Customer sessions must not access administrative product data.');
    $profileAfterRejectedWrites = apiRequest('GET', '/auth/me', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect(($profileAfterRejectedWrites['json']['data']['full_name'] ?? '') === 'Integration Customer Updated',
        'Rejected CSRF attempts must not mutate customer profile data.');

    // Exercise cart writes through the real router, middleware, controller,
    // service, and test database using an isolated catalogue fixture.
    $categoryId = (int) $pdo->query('SELECT id FROM categories ORDER BY id LIMIT 1')->fetchColumn();
    apiExpect($categoryId > 0, 'Cart integration checks require the seeded catalogue category.');
    $insertProduct = $pdo->prepare('INSERT INTO products (category_id, name, description, price, is_available, status) VALUES (?, ?, ?, ?, 1, ?)');
    $insertProduct->execute([$categoryId, 'Cart API check ' . bin2hex(random_bytes(4)), 'Temporary cart integration fixture.', 10.00, 'active']);
    $productId = (int) $pdo->lastInsertId();

    $cartAdd = apiRequest('POST', '/cart/items', ['product_id' => $productId, 'quantity' => 1], [
        'X-CSRF-Token: ' . $profileAfterRejectedWrites['headers']['x-csrf-token'],
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect($cartAdd['status'] === 201 && ($cartAdd['json']['success'] ?? false), 'A valid positive whole-number cart quantity must succeed.');
    $cartItemId = (int) ($cartAdd['json']['data']['id'] ?? 0);
    apiExpect($cartItemId > 0, 'A successful cart add must return its cart item identifier.');

    $maxItemQuantity = (int) $pdo->query('SELECT max_item_quantity FROM business_settings WHERE id = 1')->fetchColumn();
    apiExpect($maxItemQuantity > 0, 'Business settings must contain a positive per-item cart limit.');
    $overLimitUpdate = apiRequest('PUT', '/cart/items/' . $cartItemId, ['quantity' => $maxItemQuantity + 1], [
        'X-CSRF-Token: ' . $profileAfterRejectedWrites['headers']['x-csrf-token'],
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect(($overLimitUpdate['json']['success'] ?? true) === false, 'The configured per-item cart limit must be enforced server-side.');

    foreach ([0, -1, 1.5, 'abc', '', '1e3', '999999999999999999999999999999'] as $invalidQuantity) {
        $invalidUpdate = apiRequest('PUT', '/cart/items/' . $cartItemId, ['quantity' => $invalidQuantity], [
            'X-CSRF-Token: ' . $profileAfterRejectedWrites['headers']['x-csrf-token'],
            'Origin: https://foodbyks.netlify.app',
        ], $cookieFile);
        apiExpect($invalidUpdate['status'] === 422, 'Malformed cart quantities must be rejected before service integer conversion.');
        $cartAfterFailure = apiRequest('GET', '/cart', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
        apiExpect(($cartAfterFailure['json']['data']['items'][0]['quantity'] ?? null) === 1,
            'A rejected cart quantity update must leave the existing row unchanged.');
    }

    $secondRegistration = apiRequest('POST', '/auth/register', [
        'name' => 'Other Cart Customer',
        'email' => $secondEmail,
        'password' => $password,
        'phone' => '+27820000001',
        'terms_accepted' => true,
        'privacy_notice_acknowledged' => true,
    ], ['Origin: https://foodbyks.netlify.app'], $secondCookieFile);
    apiExpect($secondRegistration['status'] === 201, 'A second customer fixture must register for cart ownership checks.');
    $pdo->prepare('UPDATE users SET email_verified_at = NOW() WHERE email = ?')->execute([$secondEmail]);
    $secondLogin = apiRequest('POST', '/auth/login', ['email' => $secondEmail, 'password' => $password], [
        'Origin: https://foodbyks.netlify.app',
    ], $secondCookieFile);
    apiExpect($secondLogin['status'] === 200 && ($secondLogin['json']['success'] ?? false), 'The second customer fixture must sign in.');
    $secondMe = apiRequest('GET', '/auth/me', null, ['Origin: https://foodbyks.netlify.app'], $secondCookieFile);
    $crossCustomerUpdate = apiRequest('PUT', '/cart/items/' . $cartItemId, ['quantity' => 2], [
        'X-CSRF-Token: ' . ($secondMe['headers']['x-csrf-token'] ?? ''),
        'Origin: https://foodbyks.netlify.app',
    ], $secondCookieFile);
    apiExpect(($crossCustomerUpdate['json']['success'] ?? true) === false,
        'A customer must not modify a cart item owned by another customer.');

    $cartDelete = apiRequest('DELETE', '/cart/items/' . $cartItemId, null, [
        'X-CSRF-Token: ' . $profileAfterRejectedWrites['headers']['x-csrf-token'],
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect($cartDelete['status'] === 200 && ($cartDelete['json']['success'] ?? false), 'Explicit cart deletion must succeed.');
    $emptyCart = apiRequest('GET', '/cart', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect(($emptyCart['json']['data']['items'] ?? null) === [], 'Explicit cart deletion must remove the cart item.');

    $allowedCors = apiRequest('OPTIONS', '/products', null, [
        'Origin: https://foodbyks.netlify.app',
        'Access-Control-Request-Method: GET',
    ]);
    apiExpect(($allowedCors['headers']['access-control-allow-origin'] ?? '') === 'https://foodbyks.netlify.app', 'Configured frontend origin must pass CORS.');

    $blockedCors = apiRequest('OPTIONS', '/products', null, [
        'Origin: https://attacker.example',
        'Access-Control-Request-Method: GET',
    ]);
    apiExpect(!isset($blockedCors['headers']['access-control-allow-origin']), 'Untrusted origins must not receive CORS permission.');

    echo "API integration and security checks passed.\n";
} finally {
    $userId = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $userId->execute([$email]);
    $existingUserId = $userId->fetchColumn();

    if ($existingUserId !== false) {
        $pdo->prepare('DELETE FROM customer_email_verifications WHERE user_id = ?')->execute([$existingUserId]);
        $pdo->prepare('DELETE FROM policy_acceptances WHERE user_id = ?')->execute([$existingUserId]);
        $pdo->prepare('DELETE FROM cart_items WHERE customer_id = ?')->execute([$existingUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$existingUserId]);
    }

    $secondUserId = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $secondUserId->execute([$secondEmail]);
    $existingSecondUserId = $secondUserId->fetchColumn();
    if ($existingSecondUserId !== false) {
        $pdo->prepare('DELETE FROM customer_email_verifications WHERE user_id = ?')->execute([$existingSecondUserId]);
        $pdo->prepare('DELETE FROM policy_acceptances WHERE user_id = ?')->execute([$existingSecondUserId]);
        $pdo->prepare('DELETE FROM cart_items WHERE customer_id = ?')->execute([$existingSecondUserId]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$existingSecondUserId]);
    }
    if ($productId !== null) $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);

    if (is_file($cookieFile)) unlink($cookieFile);
    if (is_file($secondCookieFile)) unlink($secondCookieFile);
}
