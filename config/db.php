<?php

if (defined('DB_BOOTSTRAPPED')) return;
define('DB_BOOTSTRAPPED', true);

$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim($v, " \t\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function env_or_fail(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') {
        error_log("Missing required env var: $key");
        http_response_code(500);
        exit('Server configuration error.');
    }
    return $v;
}

define('DB_HOST', env_or_fail('DB_HOST'));
define('DB_USER', env_or_fail('DB_USER'));
define('DB_PASS', env_or_fail('DB_PASS'));
define('DB_NAME', env_or_fail('DB_NAME'));

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database unavailable.');
}

// ---------- Helpers (prefer prepared statements; these are fallbacks) ----------
if (!function_exists('db_prepare')) {
    function db_prepare(string $query) {
        global $conn;
        return $conn->prepare($query);
    }
}
if (!function_exists('db_get_last_id')) {
    function db_get_last_id(): int {
        global $conn;
        return (int)$conn->insert_id;
    }
}
