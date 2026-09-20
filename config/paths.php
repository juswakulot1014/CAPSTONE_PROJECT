<?php
// config/paths.php

// ────────────────────────────────────────────────
// Load .env from project root (simple parser, no library)
// Only sets a var if it isn't already set in the environment.
// ────────────────────────────────────────────────
$envFile = dirname(__DIR__) . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[0] === substr($v, -1)) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k]    = $v;
            $_SERVER[$k] = $v;
        }
    }
}

// ────────────────────────────────────────────────
// Storage layout
// ────────────────────────────────────────────────
define('STORAGE_DIR',        dirname(__DIR__, 2) . '/storage');
define('DOCUMENTS_DIR',      STORAGE_DIR . '/documents/');
define('STUDENTS_PHOTO_DIR', STORAGE_DIR . '/students/');
define('STAGING_DIR',        STORAGE_DIR . '/staging/');

foreach ([DOCUMENTS_DIR, STUDENTS_PHOTO_DIR, STAGING_DIR] as $d) {
    if (!is_dir($d) && !mkdir($d, 0700, true) && !is_dir($d)) {
        throw new RuntimeException("Cannot create {$d}");
    }
}

// ────────────────────────────────────────────────
// Encryption key (must be 64 hex chars = 32 bytes)
// ────────────────────────────────────────────────
$encKey = getenv('DOC_ENC_KEY');
if (!$encKey || strlen($encKey) !== 64 || !ctype_xdigit($encKey)) {
    throw new RuntimeException('DOC_ENC_KEY env var missing or invalid (need 64 hex chars).');
}
define('DOC_ENC_KEY', $encKey);

// ────────────────────────────────────────────────
// Path helpers
// ────────────────────────────────────────────────
function document_abs_path(string $stored): string {
    return DOCUMENTS_DIR . basename($stored);
}

function student_photo_abs_path(string $stored): string {
    return STUDENTS_PHOTO_DIR . basename($stored);
}