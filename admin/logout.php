<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../config/audit.php';

// --- 1. Audit the logout BEFORE destroying the session ---------------------
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
if ($admin_id > 0) {
    if (function_exists('audit_log')) {
        try {
            audit_log($conn, 'admin.logout', [
                'type' => 'admin',
                'id'   => $admin_id,
            ]);
        } catch (Throwable $e) {
            error_log('logout: audit_log() threw: ' . $e->getMessage());
        }
    } else {
        error_log(sprintf(
            'logout: audit_log() undefined after require. audit.php=%s bootstrap.php=%s',
            realpath(__DIR__ . '/../config/audit.php')     ?: 'MISSING',
            realpath(__DIR__ . '/../config/bootstrap.php') ?: 'MISSING'
        ));
    }
}

// --- 2. Clear remember-me token from DB ------------------------------------
$remember_cookie_name = 'admin_remember_token';
if (isset($_COOKIE[$remember_cookie_name])) {
    $t = $_COOKIE[$remember_cookie_name];
    $clear = $conn->prepare("UPDATE admins SET remember_token = NULL, remember_expires = NULL WHERE remember_token = ?");
    if ($clear) {
        $clear->bind_param('s', $t);
        $clear->execute();
        $clear->close();
    }

    setcookie($remember_cookie_name, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'domain'   => '',
        'secure'   => function_exists('is_https') ? is_https() : false,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    unset($_COOKIE[$remember_cookie_name]);
}

// --- 3. Wipe the session ---------------------------------------------------
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}

session_destroy();

// --- 4. Redirect to login --------------------------------------------------
header('Location: admin_login.php');
exit;