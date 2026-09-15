<?php
declare(strict_types=1);

$routes = require __DIR__ . '/config/masked_routes.php';

$uri = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/', '/');

if (!isset($routes[$uri])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Not found');
}

$target = __DIR__ . '/' . $routes[$uri];

$real = realpath($target);
if ($real === false || !is_file($real) || strpos($real, __DIR__) !== 0) {
    http_response_code(404);
    exit('Not found');
}

chdir(dirname($real));
require $real;