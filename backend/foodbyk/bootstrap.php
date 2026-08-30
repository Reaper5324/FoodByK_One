<?php

date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/config/config.php';

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
