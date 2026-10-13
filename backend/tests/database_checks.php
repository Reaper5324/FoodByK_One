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
        if (str_starts_with((string) $exception->getCode(), '23')) return;
        throw $exception;
    }
    throw new RuntimeException($message);
}

$email = 'db-check-' . bin2hex(random_bytes(8)) . '@example.test';
$categoryName = 'DB check ' . bin2hex(random_bytes(6));
$userId = null;
$categoryId = null;

try {
    databaseExpect((int) $pdo->query('SELECT COUNT(*) FROM business_settings')->fetchColumn() === 1, 'Migration must seed exactly one business settings row.');
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
    if ($userId !== null) {
        $deleteUser = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $deleteUser->execute([$userId]);
    }
    if ($categoryId !== null) {
        $deleteCategory = $pdo->prepare('DELETE FROM categories WHERE id = ?');
        $deleteCategory->execute([$categoryId]);
    }
}
