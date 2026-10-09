<?php

require_once __DIR__ . '/../foodbyk/bootstrap.php';
require_once __DIR__ . '/../foodbyk/routes.php';

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedOrigins = ['null', 'http://localhost:5500', 'http://127.0.0.1:5500', 'http://localhost:8080', 'http://127.0.0.1:8080,'https://foodbyks.netlify.app'];
if (defined('FRONTEND_URL') && FRONTEND_URL !== '') {
	$frontendUrl = parse_url(FRONTEND_URL);
	if (is_array($frontendUrl) && isset($frontendUrl['scheme'], $frontendUrl['host'])) {
		$frontendOrigin = strtolower($frontendUrl['scheme']) . '://' . strtolower($frontendUrl['host']);
		if (isset($frontendUrl['port'])) {
			$frontendOrigin .= ':' . $frontendUrl['port'];
		}
		$allowedOrigins[] = $frontendOrigin;
	}
}
header('Vary: Origin');
if (in_array($origin, $allowedOrigins, true)) {
	header('Access-Control-Allow-Origin: ' . $origin);
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Session-Token');
	header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
	header('Access-Control-Expose-Headers: X-CSRF-Token');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
	http_response_code(204);
	exit;
}

$router = new Router();
registerRoutes($router);
$router->dispatch(Request::capture())->send();
