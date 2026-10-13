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
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }
    $responseBody = substr($raw, $headerSize);
    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody, 'json' => json_decode($responseBody, true)];
}

$pdo = new PDO(
    'mysql:host=' . (getenv('TEST_DB_HOST') ?: '127.0.0.1') . ';port=' . (getenv('TEST_DB_PORT') ?: '3306') . ';dbname=' . $dbName . ';charset=utf8mb4',
    getenv('TEST_DB_USER') ?: 'root',
    getenv('TEST_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$email = 'api-check-' . bin2hex(random_bytes(8)) . '@example.test';
$cookieFile = tempnam(sys_get_temp_dir(), 'foodbyk-cookie-');

try {
    $health = apiRequest('GET', '/health');
    apiExpect($health['status'] === 200 && ($health['json']['success'] ?? false), 'Health endpoint must return a successful JSON envelope.');

    $products = apiRequest('GET', '/products');
    apiExpect($products['status'] === 200 && ($products['json']['success'] ?? false), 'Public products endpoint must be reachable.');

    $unauthenticatedCart = apiRequest('GET', '/cart');
    apiExpect($unauthenticatedCart['status'] === 401, 'Cart data must require authentication.');
    $unauthenticatedAdmin = apiRequest('GET', '/admin/staff');
    apiExpect($unauthenticatedAdmin['status'] === 401, 'Admin data must require authentication.');

    $invalidRegistration = apiRequest('POST', '/auth/register', ['name' => 'Bad Input', 'email' => 'not-an-email', 'password' => 'weak']);
    apiExpect($invalidRegistration['status'] >= 400 && $invalidRegistration['status'] < 500, 'Invalid registration data must be rejected by the API.');

    $registration = apiRequest('POST', '/auth/register', [
        'name' => 'Integration Customer',
        'email' => $email,
        'password' => 'BlueRiver!9072',
        'phone' => '+27820000000',
    ], ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($registration['status'] === 201 && ($registration['json']['success'] ?? false), 'Valid customer registration must create a signed-in customer.');
    $sessionCookie = $registration['headers']['set-cookie'] ?? '';
    apiExpect(stripos($sessionCookie, 'httponly') !== false, 'Authentication session cookies must be HttpOnly.');
    apiExpect(stripos($sessionCookie, 'samesite=') !== false, 'Authentication session cookies must define SameSite behavior.');

    $me = apiRequest('GET', '/auth/me', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($me['status'] === 200 && ($me['json']['success'] ?? false), 'Registration must establish a usable session.');
    apiExpect(isset($me['headers']['x-csrf-token']), 'Authenticated safe requests must expose a CSRF token.');
    apiExpect(!str_contains(json_encode($me['json'], JSON_THROW_ON_ERROR), 'password_hash'), 'Public account responses must not expose password hashes.');

    $missingCsrf = apiRequest('PUT', '/account/profile', ['full_name' => 'Integration Customer', 'email' => $email], [], $cookieFile);
    apiExpect($missingCsrf['status'] === 419, 'Authenticated writes must reject missing CSRF tokens.');

    $updated = apiRequest('PUT', '/account/profile', ['full_name' => 'Integration Customer Updated', 'email' => $email], [
        'X-CSRF-Token: ' . $me['headers']['x-csrf-token'],
        'Origin: https://foodbyks.netlify.app',
    ], $cookieFile);
    apiExpect($updated['status'] === 200 && ($updated['json']['success'] ?? false), 'Valid authenticated profile writes must succeed with CSRF protection.');

    $customerAdminAttempt = apiRequest('GET', '/admin/staff', null, ['Origin: https://foodbyks.netlify.app'], $cookieFile);
    apiExpect($customerAdminAttempt['status'] === 403, 'Customer sessions must not access admin resources.');

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
    $delete = $pdo->prepare('DELETE FROM users WHERE email = ?');
    $delete->execute([$email]);
    if (is_file($cookieFile)) unlink($cookieFile);
}
