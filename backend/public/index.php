<?php

require_once __DIR__ . '/../foodbyk/bootstrap.php';
require_once __DIR__ . '/../foodbyk/routes.php';

$router = new Router();
registerRoutes($router);
$router->dispatch(Request::capture())->send();
