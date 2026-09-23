<?php
// config/bootstrap.php
// Include this FIRST in every entry point. Nothing else goes above it.
declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/db.php';

secure_session_start();
send_security_headers();