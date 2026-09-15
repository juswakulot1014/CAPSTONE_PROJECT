<?php
require_once __DIR__ . '/config/security.php';
secure_session_start();
send_security_headers();

$routes = require __DIR__ . '/masked_routes.php';

$r = $_GET['r'] ?? '';
if (!is_string($r) || $r === '' || !isset($routes[$r])) {
    http_response_code(404);
    exit('Not found');
}

$target = $routes[$r];
if (!preg_match('#^[A-Za-z0-9_/]+\.php$#', $target)) {
    http_response_code(500);
    exit('Bad route');
}

$full = __DIR__ . '/' . $target;
if (!is_file($full)) {
    http_response_code(404);
    exit('Not found');
}

if (!empty($_GET)) {
    $qs = $_GET;
    unset($qs['r']);
    if (!empty($qs)) {
        $_SERVER['QUERY_STRING'] = http_build_query($qs);
    }
}

require $full;