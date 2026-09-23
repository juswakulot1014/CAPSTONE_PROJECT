<?php
// config/security.php — central security bootstrap.
// Include FIRST in every entry-point, BEFORE session_start().

if (defined('SECURITY_BOOTSTRAPPED')) return;
define('SECURITY_BOOTSTRAPPED', true);

// ---------- Hardened session bootstrap ----------
function secure_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $isHttps = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.cookie_httponly', '1');

    session_start();

    $now = time();
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = $now;
        $_SESSION['_last']    = $now;
    } elseif ($now - $_SESSION['_last'] > 1800 || $now - $_SESSION['_created'] > 28800) {
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['_created'] = $now;
        $_SESSION['_last']    = $now;
    } else {
        $_SESSION['_last'] = $now;
    }

    if (!isset($_SESSION['_rot']) || $now - $_SESSION['_rot'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_rot'] = $now;
    }
}

// ---------- Security headers ----------
function send_security_headers(): void {
    if (headers_sent()) return;
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header(
        "Content-Security-Policy: default-src 'self'; "
      . "script-src 'self' https://cdn.jsdelivr.net https://code.jquery.com https://cdnjs.cloudflare.com 'unsafe-inline'; "
      . "style-src 'self' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; "
      . "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
      . "img-src 'self' data:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'"
    );
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// ---------- CSRF ----------
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}
function verify_csrf(bool $rotate = false): void {
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(419);
        exit('Invalid CSRF token. Please refresh and try again.');
    }
    if ($rotate) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ---------- Rate limiting (session-based) ----------
// Usage:
//   $rl = rate_limit_check('login_1.2.3.4', 5, 900);
//   if ($rl['locked']) { ... }
//   rate_limit_hit('login_1.2.3.4');       // on failure
//   rate_limit_reset('login_1.2.3.4');     // on success
function rate_limit_check(string $key, int $max = 5, int $window = 900): array {
    $k = 'rl_' . $key;
    $now = time();
    if (!isset($_SESSION[$k])) {
        $_SESSION[$k] = ['count' => 0, 'start' => $now];
    }
    if ($now - $_SESSION[$k]['start'] > $window) {
        $_SESSION[$k] = ['count' => 0, 'start' => $now];
    }
    $b = $_SESSION[$k];
    return [
        'count'       => $b['count'],
        'remaining'   => max(0, $max - $b['count']),
        'window_left' => max(0, $window - ($now - $b['start'])),
        'locked'      => $b['count'] >= $max,
    ];
}
function rate_limit_hit(string $key): void {
    $k = 'rl_' . $key;
    $now = time();
    if (!isset($_SESSION[$k])) {
        $_SESSION[$k] = ['count' => 0, 'start' => $now];
    }
    $_SESSION[$k]['count']++;
}
function rate_limit_reset(string $key): void {
    unset($_SESSION['rl_' . $key]);
}

// ---------- Auth guards ----------
function require_admin(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: admin_login.php');
        exit;
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (isset($_SESSION['user_agent']) && !hash_equals($_SESSION['user_agent'], $ua)) {
        session_unset();
        session_destroy();
        header('Location: admin_login.php?timeout=1');
        exit;
    }
}
function require_superadmin(): void {
    require_admin();
    if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
        http_response_code(403);
        exit('Forbidden — Super Admin only.');
    }
}
/**
 * Unified guard. Pass a role to enforce it. Pass null to just require login.
 * Preserves intended destination for post-login redirect.
 */
function require_role(?string $role = null): void {
    if (empty($_SESSION['admin_id'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
        header('Location: admin_login.php');
        exit;
    }
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if (isset($_SESSION['user_agent']) && !hash_equals($_SESSION['user_agent'], $ua)) {
        session_unset();
        session_destroy();
        header('Location: admin_login.php?timeout=1');
        exit;
    }
    if ($role !== null && ($_SESSION['admin_role'] ?? '') !== $role) {
        http_response_code(403);
        exit('Forbidden.');
    }
}
function require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit('Method Not Allowed');
    }
}

// ---------- Helpers ----------
function is_https(): bool {
    return (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}
function safe_filename(string $v): string {
    return preg_replace('/[^A-Za-z0-9_\-]/', '_', $v) ?: 'file';
}