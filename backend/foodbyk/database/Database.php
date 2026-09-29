<?php

class Database {
    private static ?PDO $instance = null;
    private const CONNECT_TIMEOUT_SECONDS = 5;

    private function __construct() {}
    private function __clone() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            if (APP_ENV === 'production' && (DB_NAME === '' || DB_USER === '' || DB_PASS === '')) {
                throw new RuntimeException('Production requires explicit database credentials.');
            }
            if (APP_ENV === 'production' && PAYMENT_TOKEN_ENCRYPTION_KEY === '') {
                throw new RuntimeException('Production payment token encryption is not configured.');
            }

            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            try {
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => self::CONNECT_TIMEOUT_SECONDS,
                ];

                if (DB_SSL_CA !== '') {
                    $options[PDO::MYSQL_ATTR_SSL_CA] = DB_SSL_CA;
                    if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
                    }
                }

                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                error_log(sprintf(
                    'Database connection failed (%s:%s/%s): %s',
                    DB_HOST,
                    DB_PORT,
                    DB_NAME,
                    $e->getMessage()
                ));
                throw new RuntimeException('Database connection failed.', 0, $e);
            }
        }

        return self::$instance;
    }
}
