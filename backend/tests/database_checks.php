<?php

$databaseName = getenv('TEST_DB_NAME') ?: '';
if ($databaseName !== 'foodbyk_test') {
    fwrite(STDERR, "Refusing database checks: TEST_DB_NAME must be exactly foodbyk_test.\n");
    exit(2);
}

$host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
$port = getenv('TEST_DB_PORT') ?: '3306';
$user = getenv('TEST_DB_USER') ?: 'root';
$password = getenv('TEST_DB_PASS') ?: '';
$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$databaseName};charset=utf8mb4",
    $user,
    $password,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

function databaseExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

function expectConstraintViolation(PDO $pdo, callable $operation, string $message): void {
    try {
        $operation();
    } catch (PDOException $exception) {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        // MySQL reports CHECK violations as HY000 / 3819, while MariaDB
        // reports them as 23000 / 4025. Other integrity violations use 23xxx.
        if (str_starts_with($sqlState, '23') || in_array($driverCode, [3819, 4025], true)) return;
        throw $exception;
    }
    throw new RuntimeException($message);
}

$email = 'db-check-' . bin2hex(random_bytes(8)) . '@example.test';
$categoryName = 'DB check ' . bin2hex(random_bytes(6));
$userId = null;
$categoryId = null;
$productId = null;
$promotionId = null;

try {
    databaseExpect((int) $pdo->query('SELECT COUNT(*) FROM business_settings')->fetchColumn() === 1, 'Migration must seed exactly one business settings row.');
    databaseExpect((int) $pdo->query('SELECT max_advance_booking_days FROM business_settings WHERE id = 1')->fetchColumn() === 30,
        'Migration must configure a safe default booking window of 30 days.');
    databaseExpect((int) $pdo->query('SELECT admin_email_2fa_enabled FROM business_settings WHERE id = 1')->fetchColumn() === 0,
        'Admin email verification must be disabled by default.');
    databaseExpect((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_login_challenges'")->fetchColumn() === 1,
        'Migration must create the expiring admin login challenge store.');
    $customerRoleId = (int) $pdo->query("SELECT id FROM roles WHERE role_name = 'customer'")->fetchColumn();
    databaseExpect($customerRoleId > 0, 'Migration must seed the customer role.');

    $insertUser = $pdo->prepare('INSERT INTO users (name, email, password_hash, role_id) VALUES (?, ?, ?, ?)');
    $insertUser->execute(['Database Check', $email, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $customerRoleId]);
    $userId = (int) $pdo->lastInsertId();

    expectConstraintViolation($pdo, fn() => $insertUser->execute(['Duplicate', $email, 'hash', $customerRoleId]), 'Database must enforce unique user email.');

    $insertCategory = $pdo->prepare('INSERT INTO categories (name) VALUES (?)');
    $insertCategory->execute([$categoryName]);
    $categoryId = (int) $pdo->lastInsertId();

    $insertProduct = $pdo->prepare('INSERT INTO products (category_id, name, description, price) VALUES (?, ?, ?, ?)');
    expectConstraintViolation($pdo, fn() => $insertProduct->execute([$categoryId, 'Negative price', 'Invalid test row', -1]), 'Database must reject negative product prices.');
    expectConstraintViolation($pdo, fn() => $insertProduct->execute([999999, 'Bad category', 'Invalid test row', 10]), 'Database must enforce product category foreign keys.');
    $insertProduct->execute([$categoryId, 'DB option check', 'Option integration fixture', 25.00]);
    $productId = (int) $pdo->lastInsertId();

    $insertOption = $pdo->prepare('INSERT INTO product_options (product_id, name, price_delta) VALUES (?, ?, ?)');
    $insertOption->execute([$productId, 'Free extra', 0.00]);
    databaseExpect((int) $pdo->lastInsertId() > 0, 'Database must persist a valid zero-price product option.');
    expectConstraintViolation($pdo, fn() => $insertOption->execute([$productId, 'Negative extra', -0.01]), 'Database must reject negative option prices.');
    expectConstraintViolation($pdo, fn() => $insertOption->execute([999999, 'Unknown product extra', 1.00]), 'Database must enforce product-option foreign keys.');

    $insertPromotion = $pdo->prepare('INSERT INTO promotions (code, discount_type, discount_value) VALUES (?, ?, ?)');
    $insertPromotion->execute(['DB' . strtoupper(bin2hex(random_bytes(4))), 'free_delivery', 0.00]);
    $promotionId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO promotion_products (promotion_id, product_id) VALUES (?, ?)')->execute([$promotionId, $productId]);
    databaseExpect((int) $pdo->query('SELECT COUNT(*) FROM promotion_products WHERE promotion_id = ' . $promotionId . ' AND product_id = ' . $productId)->fetchColumn() === 1,
        'Database must persist promotion target relationships.');

    $insertAddress = $pdo->prepare('INSERT INTO addresses (customer_id, raw_address, latitude, longitude) VALUES (?, ?, ?, ?)');
    expectConstraintViolation($pdo, fn() => $insertAddress->execute([$userId, 'Coordinate pair check', -26.2, null]), 'Database must reject half-populated address coordinates.');
    expectConstraintViolation($pdo, fn() => $insertAddress->execute([$userId, 'Latitude range check', 91, 28]), 'Database must reject out-of-range latitude.');

    $pdo->beginTransaction();
    $temporaryName = $categoryName . ' rollback';
    $insertCategory->execute([$temporaryName]);
    $pdo->rollBack();
    $checkRollback = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE name = ?');
    $checkRollback->execute([$temporaryName]);
    databaseExpect((int) $checkRollback->fetchColumn() === 0, 'Database transaction rollback must discard writes.');

    echo "Database checks passed.\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($promotionId !== null) {
        $pdo->prepare('DELETE FROM promotions WHERE id = ?')->execute([$promotionId]);
    }
    if ($productId !== null) {
        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }
    if ($userId !== null) {
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $deleteUser->execute([$userId]);
    }
    if ($categoryId !== null) {
        $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $deleteCategory->execute([$categoryId]);
    }
}
