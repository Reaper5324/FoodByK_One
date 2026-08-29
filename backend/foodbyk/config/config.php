<?php
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('DB_NAME') ?: '');
define('DB_USER', getenv('DB_USER') ?: '');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('PAYFAST_MERCHANT_ID', getenv('PAYFAST_MERCHANT_ID') ?: '');
define('PAYFAST_MERCHANT_KEY', getenv('PAYFAST_MERCHANT_KEY') ?: '');
define('PAYFAST_RETURN_URL', getenv('PAYFAST_RETURN_URL') ?: '');
define('PAYFAST_CANCEL_URL', getenv('PAYFAST_CANCEL_URL') ?: '');