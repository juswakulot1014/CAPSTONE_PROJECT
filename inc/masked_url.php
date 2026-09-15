<?php
declare(strict_types=1);

function masked_routes(): array {
    static $routes = null;
    if ($routes === null) {
        $routes = require __DIR__ . '/../config/masked_routes.php';
    }
    return $routes;
}

function masked_url(string $scriptPath): string {
    static $reverse = null;
    if ($reverse === null) {
        $reverse = array_flip(masked_routes());
    }
    $scriptPath = ltrim($scriptPath, '/');
    return isset($reverse[$scriptPath])
        ? '/' . $reverse[$scriptPath]
        : '/' . $scriptPath;
}