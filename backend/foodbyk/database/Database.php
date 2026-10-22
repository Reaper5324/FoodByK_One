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

            $sslMode = strtolower(trim(DB_SSL));
            if ($sslMode === 'required' || $sslMode === 'require') {
                // Aiven's MySQL PDO connection format uses sslmode=require to enforce encryption.
                $dsn .= ';sslmode=required';
            } elseif ($sslMode === 'disabled' || $sslMode === 'disable') {
                $dsn .= ';sslmode=disabled';
            } elseif ($sslMode === 'verify-ca') {
                if (DB_SSL_CA === '') {
                    throw new RuntimeException('DB_SSL_CA is required when DB_SSL=Verify-CA.');
                }
                $caPath = str_replace("'", "\\'", DB_SSL_CA);
                $dsn .= ";sslmode=verify-ca;sslrootcert='{$caPath}'";
            } elseif ($sslMode !== '') {
                throw new RuntimeException('DB_SSL must be empty, Disabled, Required, or Verify-CA.');
            }

            try {
                $options = [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_TIMEOUT            => self::CONNECT_TIMEOUT_SECONDS,
                ];

                if (DB_SSL_CA !== '' && !in_array($sslMode, ['verify-ca', 'disabled', 'disable'], true)) {
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
