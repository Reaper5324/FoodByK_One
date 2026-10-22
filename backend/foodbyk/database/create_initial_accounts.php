<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__) . '/bootstrap.php';

$accountDefinitions = [
    'customer' => 'INITIAL_CUSTOMER_PASSWORD',
    'staff' => 'INITIAL_STAFF_PASSWORD',
    'admin' => 'INITIAL_ADMIN_PASSWORD',
];

$passwords = [];
foreach ($accountDefinitions as $role => $environmentKey) {
    $password = getenv($environmentKey);
    if (!is_string($password) || strlen($password) < 12) {
        fwrite(STDERR, "Set {$environmentKey} to a unique password with at least 12 characters before running this script.\n");
        exit(1);
    }
    $passwords[$role] = $password;
}

function prompt(string $label): string {
    if (function_exists('readline')) {
        $value = readline($label);
    } else {
        fwrite(STDOUT, $label);
        $value = fgets(STDIN);
    }
    return trim((string) $value);
}

try {
    $db = Database::getConnection();
    fwrite(STDOUT, "Target database: " . DB_NAME . " on " . DB_HOST . ":" . DB_PORT . "\n");
    if (prompt('Type the database name to confirm this target: ') !== DB_NAME) {
        fwrite(STDERR, "Target confirmation did not match; no accounts were created.\n");
        exit(1);
    }

    $accounts = [];
    foreach ($accountDefinitions as $role => $_environmentKey) {
        $name = prompt(ucfirst($role) . ' full name: ');
        $email = strtolower(prompt(ucfirst($role) . ' email: '));
        if ($name === '' || strlen($name) > 150 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 150) {
            throw new InvalidArgumentException("Invalid name or email for the {$role} account.");
        }
        $accounts[$role] = ['name' => $name, 'email' => $email];
    }

    $emails = array_column($accounts, 'email');
    if (count(array_unique($emails)) !== count($emails)) {
        throw new InvalidArgumentException('Each of the three accounts must use a different email address.');
    }

    $db->beginTransaction();
    $roleQuery = $db->prepare('SELECT id FROM roles WHERE role_name = ? LIMIT 1');
    $userQuery = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $insert = $db->prepare('INSERT INTO users (name, email, password_hash, role_id, is_active) VALUES (?, ?, ?, ?, 1)');
    $hashAlgorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;

    foreach ($accounts as $role => $account) {
        $roleQuery->execute([$role]);
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) throw new RuntimeException("The '{$role}' role is missing. Run migration.sql first.");

        $userQuery->execute([$account['email']]);
        if ($userQuery->fetchColumn() !== false) throw new RuntimeException("An account already uses {$account['email']}; no accounts were created.");

        $passwordHash = password_hash($passwords[$role], $hashAlgorithm);
        if ($passwordHash === false) throw new RuntimeException("Unable to hash the {$role} account password.");
        $insert->execute([$account['name'], $account['email'], $passwordHash, (int) $roleId]);
        fwrite(STDOUT, "Created {$role} account: {$account['email']}\n");
    }

    $db->commit();
    fwrite(STDOUT, "All three accounts were created. The customer must accept the current terms and privacy notice on first sign-in.\n");
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Account setup failed: " . $error->getMessage() . "\n");
    exit(1);
} finally {
    foreach ($accountDefinitions as $environmentKey) putenv($environmentKey);
}
