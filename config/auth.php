<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$__public_scripts = [
    'admin_login.php',
    'logout.php',
    'forgot_password.php',
    'reset_password.php',
];

$__current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');

if (!in_array($__current_script, $__public_scripts, true)) {
    $__required_role = $REQUIRE_ROLE ?? null;
    if (!is_string($__required_role) || $__required_role === '') {
        $__required_role = null;
    }
    require_role($__required_role);
}

// Always initialise the CSRF token (login forms need it too).
csrf_token();