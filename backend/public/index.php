<?php

require_once __DIR__ . '/../foodbyk/bootstrap.php';
require_once __DIR__ . '/../foodbyk/routes.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['null', 'http://localhost:5500', 'http://127.0.0.1:5500', 'http://localhost:8080', 'http://127.0.0.1:8080'];
if (defined('FRONTEND_URL') && FRONTEND_URL !== '') {
	$allowedOrigins[] = FRONTEND_URL;
}
if (in_array($origin, $allowedOrigins, true)) {
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
	header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

$router = new Router();
registerRoutes($router);
$router->dispatch(Request::capture())->send();
