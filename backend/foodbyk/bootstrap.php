<?php

date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
$isProduction = getenv('APP_ENV') === 'production';
ini_set('display_errors', $isProduction ? '0' : '1');
error_reporting($isProduction ? 0 : E_ALL);

// PHP's built-in server does not load .env files. Load the local backend
// settings before config.php defines the constants used by Database.
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }

        // Accept simple quoted values while keeping .env parsing dependency-free.
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

require_once __DIR__ . '/config/config.php';

$composerAutoload = __DIR__ . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    $isSecureRequest = APP_ENV === 'production'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isSecureRequest,
        'httponly' => true,
        'samesite' => $isSecureRequest ? 'None' : 'Lax',
    ]);
}

spl_autoload_register(function (string $class): void {
    $specialCases = [
        'Model' => __DIR__ . '/models/model.php',
        'Database' => __DIR__ . '/database/Database.php',
    ];
    if (isset($specialCases[$class])) {
        require_once $specialCases[$class];
        return;
    }

    foreach (['core', 'middleware', 'models', 'services', 'controllers'] as $directory) {
        $path = __DIR__ . '/' . $directory . '/' . $class . '.php';
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }
});
