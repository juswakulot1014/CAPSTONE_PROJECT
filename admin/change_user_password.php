<?php
session_start();
include "../config/db.php";

$error = "";
$success = "";

// ============================================
// SECURITY: Require authentication
// ============================================
if (!isset($_SESSION['admin_id'])) {
    $_SESSION['redirect_after_login'] = 'change_user_password.php';
    header("Location: admin_login.php");
    exit();
}

// Require superadmin role
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    $_SESSION['error'] = "Access Denied! Only the Principal can change passwords.";
    header("Location: dashboard.php");
    exit();
}

// Session integrity
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    session_destroy();
    header("Location: admin_login.php?timeout=1");
    exit();
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting
$max_attempts   = 3;
$lockout_time   = 900; // 15 minutes
$rate_limit_key = 'pwd_change_attempts_' . $_SESSION['admin_id'];
if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
}

// ============================================
// POST handling
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($_SESSION[$rate_limit_key]['count'] >= $max_attempts) {
        $since = time() - $_SESSION[$rate_limit_key]['last_attempt'];
        if ($since < $lockout_time) {
            $remaining = ceil(($lockout_time - $since) / 60);
            $error = "Too many attempts. Please try again in {$remaining} minute(s).";
        } else {
            $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
        }
    }

    if (empty($error)) {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '')) {
            $error = "Invalid security token. Please refresh the page and try again.";
        }
    }

    if (empty($error)) {
        $admin_password = $_POST['admin_password'] ?? '';
        if (empty($admin_password)) {
            $error = "Please enter your own admin password for verification.";
        } else {
            $verify_stmt = $conn->prepare("SELECT password FROM admins WHERE id = ? LIMIT 1");
            if ($verify_stmt) {
                $verify_stmt->bind_param("i", $_SESSION['admin_id']);
                $verify_stmt->execute();
                $verify_result = $verify_stmt->get_result();
                if ($verify_result->num_rows === 1) {
                    $admin_data = $verify_result->fetch_assoc();
                    if (!password_verify($admin_password, $admin_data['password'])) {
                        $error = "Your admin password is incorrect. Action denied.";
                        $_SESSION[$rate_limit_key]['count']++;
                        $_SESSION[$rate_limit_key]['last_attempt'] = time();
                        error_log("Password change verification failed for admin ID: {$_SESSION['admin_id']}");
                    }
                }
                $verify_stmt->close();
            }
        }
    }

    if (empty($error)) {
        $target_username  = trim($_POST['username'] ?? '');
        $new_password     = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($target_username) || empty($new_password) || empty($confirm_password)) {
            $error = "Please fill in all fields.";
        } elseif ($new_password !== $confirm_password) {
            $error = "New passwords do not match.";
        } elseif (strlen($new_password) < 8) {
            $error = "New password must be at least 8 characters long.";
        } elseif (strlen($new_password) > 128) {
            $error = "Password must not exceed 128 characters.";
        } elseif (!preg_match('/[A-Z]/', $new_password)) {
            $error = "Password must contain at least one uppercase letter.";
        } elseif (!preg_match('/[a-z]/', $new_password)) {
            $error = "Password must contain at least one lowercase letter.";
        } elseif (!preg_match('/[0-9]/', $new_password)) {
            $error = "Password must contain at least one number.";
        } elseif (!preg_match('/[^A-Za-z0-9]/', $new_password)) {
            $error = "Password must contain at least one special character.";
        } else {
            $current_admin_stmt = $conn->prepare("SELECT username FROM admins WHERE id = ? LIMIT 1");
            if ($current_admin_stmt) {
                $current_admin_stmt->bind_param("i", $_SESSION['admin_id']);
                $current_admin_stmt->execute();
                $current_admin = $current_admin_stmt->get_result()->fetch_assoc();
                $current_admin_stmt->close();
                if ($current_admin && strcasecmp($target_username, $current_admin['username']) === 0) {
                    $error = "For security reasons, you cannot change your own password here. Please use the profile settings instead.";
                }
            }
        }

        if (empty($error)) {
            $stmt = $conn->prepare("SELECT id, fullname, username, role FROM admins WHERE username = ? LIMIT 1");
            if ($stmt) {
                $stmt->bind_param("s", $target_username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    if ($user['role'] === 'superadmin' && $_SESSION['admin_role'] !== 'superadmin') {
                        $error = "You do not have permission to change this user's password.";
                    } else {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT, ['cost' => 12]);
                        $upd = $conn->prepare("UPDATE admins SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                        if ($upd) {
                            $upd->bind_param("si", $hashed_password, $user['id']);
                            $upd->execute();

                            if ($upd->affected_rows > 0) {
                                $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                                $success = "Password successfully updated for user: " . htmlspecialchars($target_username);

                                error_log(sprintf(
                                    "[%s] Password changed for user '%s' (ID: %d) by admin '%s' (ID: %d) from IP: %s",
                                    date('Y-m-d H:i:s'),
                                    $target_username,
                                    $user['id'],
                                    $_SESSION['admin_name'] ?? 'unknown',
                                    $_SESSION['admin_id'],
                                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                                ));
                                unset($_POST['username'], $_POST['new_password'], $_POST['confirm_password']);
                            } else {
                                $error = "Failed to update password. Please try again.";
                            }
                            $upd->close();
                        } else {
                            $error = "System error. Please try again later.";
                        }
                    }
                } else {
                    $_SESSION[$rate_limit_key]['count']++;
                    $_SESSION[$rate_limit_key]['last_attempt'] = time();
                    $error = "Could not process your request. Please verify the username and try again.";
                }
                $stmt->close();
            } else {
                $error = "System error. Please try again later.";
            }
        }
    }
}

$csrf_token = $_SESSION['csrf_token'];
$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');

$attempts_used = (int)$_SESSION[$rate_limit_key]['count'];
$attempts_left = max(0, $max_attempts - $attempts_used);
$locked_until  = 0;
if ($attempts_used >= $max_attempts) {
    $since = time() - $_SESSION[$rate_limit_key]['last_attempt'];
    if ($since < $lockout_time) $locked_until = ceil(($lockout_time - $since) / 60);
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change User Password • USAT Admin</title>
    <meta name="robots" content="noindex, nofollow">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f1f5f9; --surface: #ffffff; --surface2: #f8fafc;
            --text: #1a1f36; --text2: #6b7280;
            --border: #e5e7eb; --accent: #4f46e5; --accent2: #6366f1;
            --green: #059669; --red: #dc2626; --amber: #d97706;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.04);
            --shadow: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.08);
            --radius: 12px; --radius-lg: 16px;
        }
        [data-bs-theme="dark"] {
            --bg: #0f172a; --surface: #1e293b; --surface2: #1a2436;
            --text: #f1f5f9; --text2: #94a3b8;
            --border: #334155; --accent: #818cf8; --accent2: #6366f1;
            --shadow-sm: 0 1px 2px rgba(0,0,0,0.2);
            --shadow: 0 1px 3px rgba(0,0,0,0.3);
            --shadow-lg: 0 10px 25px rgba(0,0,0,0.5);
        }
        *{font-family:'Inter',system-ui,sans-serif;margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--text);min-height:100vh}

        /* ===== Sidebar ===== */
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform 0.3s}
        .sidebar-brand{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}
        .sidebar-brand img{width:40px;height:40px;border-radius:10px}
        .sidebar-brand span{font-weight:700;font-size:1.1rem}
        .sidebar-nav{flex:1;padding:1rem 0.75rem;overflow-y:auto}
        .sidebar-nav a{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;border-radius:10px;color:var(--text2);text-decoration:none;font-weight:500;font-size:0.9rem;transition:all 0.2s;margin-bottom:0.25rem}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--accent);color:white}
        .sidebar-nav a i{font-size:1.2rem;width:24px;text-align:center}
        .sidebar-footer{padding:1rem 0.75rem;border-top:1px solid var(--border)}

        /* ===== Main ===== */
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        .breadcrumb-nav{display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--text2);margin-bottom:0.25rem}
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .breadcrumb-nav a:hover{text-decoration:underline}

        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all 0.15s}
        .theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}

        /* ===== Centered form card ===== */
        .cw-wrap {
            max-width: 640px;
            margin: 0 auto;
            animation: cw-fade 0.25s ease;
        }
        @keyframes cw-fade { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }

        .cw-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        .cw-card-head {
            display: flex;
            align-items: center;
            gap: 0.85rem;
            padding: 1.15rem 1.5rem;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, var(--surface), var(--surface2));
        }
        [data-bs-theme="dark"] .cw-card-head {
            background: linear-gradient(180deg, var(--surface), var(--surface2));
        }
        .cw-card-head-icon {
            width: 42px; height: 42px;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
            box-shadow: 0 4px 12px rgba(79,70,229,0.35);
        }
        .cw-card-head h3 {
            font-size: 1rem;
            font-weight: 700;
            margin: 0 0 0.1rem;
            color: var(--text);
        }
        .cw-card-head p {
            font-size: 0.82rem;
            color: var(--text2);
            margin: 0;
        }
        .cw-card-body { padding: 1.5rem; }

        /* ===== Form ===== */
        .cw-field { margin-bottom: 1.15rem; }
        .cw-field-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.4rem;
        }
        .cw-field label {
            display: block;
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: 0.55px;
            margin-bottom: 0.4rem;
        }
        .cw-field-head label { margin-bottom: 0; }
        .cw-charcount {
            font-size: 0.72rem;
            color: var(--text2);
            font-variant-numeric: tabular-nums;
            font-weight: 500;
        }

        .cw-input-wrap { position: relative; }
        .cw-input-wrap input {
            width: 100%;
            padding: 0.8rem 2.75rem 0.8rem 2.6rem;
            border: 1.5px solid var(--border);
            border-radius: 11px;
            font-size: 0.92rem;
            background: var(--bg);
            color: var(--text);
            font-family: inherit;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }
        .cw-input-wrap input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79,70,229,0.15);
            background: var(--surface);
        }
        .cw-input-wrap input::placeholder { color: var(--text2); opacity: 0.65; }

        .cw-input-icon {
            position: absolute;
            left: 0.95rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text2);
            font-size: 1rem;
            pointer-events: none;
            transition: color 0.15s ease;
        }
        .cw-input-wrap input:focus ~ .cw-input-icon {
            color: var(--accent);
        }
        .cw-eye {
            position: absolute;
            right: 0.5rem;
            top: 50%;
            transform: translateY(-50%);
            width: 32px;
            height: 32px;
            border: none;
            background: transparent;
            color: var(--text2);
            cursor: pointer;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.15s ease, color 0.15s ease;
        }
        .cw-eye:hover { background: var(--bg); color: var(--accent); }
        [data-bs-theme="dark"] .cw-eye:hover { background: var(--surface2); }

        .cw-hint {
            margin: 0.45rem 0 0;
            font-size: 0.78rem;
            color: var(--text2);
            line-height: 1.4;
        }

        /* ===== Divider ===== */
        .cw-divider {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin: 1.5rem 0 1.15rem;
        }
        .cw-divider::before,
        .cw-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }
        .cw-divider span {
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--text2);
            text-transform: uppercase;
            letter-spacing: 0.6px;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .cw-divider span i { font-size: 0.75rem; color: var(--accent); }

        /* ===== Strength meter — segmented ===== */
        .cw-strength {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 4px;
            margin-top: 0.55rem;
        }
        .cw-strength-seg {
            height: 5px;
            border-radius: 3px;
            background: var(--border);
            transition: background 0.2s ease;
        }
        .cw-strength-seg.active-weak   { background: var(--red); }
        .cw-strength-seg.active-medium { background: var(--amber); }
        .cw-strength-seg.active-strong { background: var(--green); }

        .cw-reqs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-top: 0.6rem;
        }
        .cw-req {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.22rem 0.6rem;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            background: var(--bg);
            color: var(--text2);
            border: 1px solid var(--border);
            transition: all 0.15s ease;
        }
        .cw-req i {
            font-size: 0.6rem;
            color: var(--text2);
            opacity: 0.55;
            transition: all 0.15s ease;
        }
        .cw-req.met {
            background: #ecfdf5;
            color: #065f46;
            border-color: #6ee7b7;
        }
        .cw-req.met i {
            color: #10b981;
            opacity: 1;
        }
        [data-bs-theme="dark"] .cw-req.met {
            background: #064e3b;
            color: #6ee7b7;
            border-color: #065f46;
        }
        [data-bs-theme="dark"] .cw-req.met i {
            color: #34d399;
        }

        /* ===== Submit ===== */
        .cw-submit {
            width: 100%;
            padding: 0.9rem 1.25rem;
            border: none;
            border-radius: 11px;
            background: linear-gradient(135deg, var(--accent), var(--accent2));
            color: white;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: inherit;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            box-shadow: 0 4px 14px rgba(79,70,229,0.3);
            transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
            margin-top: 0.75rem;
            letter-spacing: 0.2px;
        }
        .cw-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 6px 18px rgba(79,70,229,0.4);
        }
        .cw-submit:active:not(:disabled) {
            transform: translateY(0);
        }
        .cw-submit:disabled { opacity: 0.65; cursor: not-allowed; }

        /* ===== Alerts ===== */
        .cw-alert {
            display: flex;
            align-items: flex-start;
            gap: 0.65rem;
            padding: 0.85rem 1.1rem;
            border-radius: 12px;
            font-size: 0.86rem;
            line-height: 1.5;
            margin-bottom: 1.15rem;
            border-left: 4px solid;
            animation: cw-fade 0.2s ease;
        }
        .cw-alert i { margin-top: 2px; flex-shrink: 0; font-size: 1.05rem; }
        .cw-alert-error { background: #fef2f2; color: #991b1b; border-color: var(--red); }
        [data-bs-theme="dark"] .cw-alert-error { background: #450a0a; color: #fecaca; }
        .cw-alert-success { background: #ecfdf5; color: #065f46; border-color: var(--green); }
        [data-bs-theme="dark"] .cw-alert-success { background: #064e3b; color: #6ee7b7; }

        /* ===== Attempts notice ===== */
        .cw-attempts {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            padding: 0.65rem 0.9rem;
            border-radius: 10px;
            background: #fef3c7;
            color: #92400e;
            font-size: 0.82rem;
            font-weight: 600;
            margin-bottom: 1.15rem;
            border-left: 4px solid var(--amber);
        }
        [data-bs-theme="dark"] .cw-attempts {
            background: #78350f;
            color: #fcd34d;
        }
        .cw-attempts i { font-size: 1rem; }

        @media(max-width:1024px){
            .sidebar{transform:translateX(-100%)}
            .sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}
            .menu-toggle{display:flex}
        }
        @media(max-width:640px){
            .main-content{padding:1rem}
            .cw-card-body { padding: 1.15rem; }
            .cw-card-head { padding: 1rem 1.15rem; }
        }
    </style>
</head>
<body>

<!-- ===== Sidebar ===== -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><img src="../assets/img/usat.jpg" alt="USAT"><span>USAT Admin</span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
        <a href="change_user_password.php" class="active"><i class="bi bi-key"></i> Change Password</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></div>
</aside>

<!-- ===== Main ===== -->
<div class="main-content" id="mainContent">

    <!-- Topbar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="create_account.php">Accounts</a>
                    <i class="bi bi-chevron-right small"></i>
                    Change Password
                </div>
                <h2 style="font-size:1.4rem;font-weight:700;margin:0">Change User Password</h2>
                <p class="text-muted small mb-0">Reset another admin's password</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <a href="create_account.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
            <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <div class="cw-wrap">

        <!-- Alerts -->
        <?php if ($error): ?>
            <div class="cw-alert cw-alert-error" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="cw-alert cw-alert-success" role="status">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= $success ?></span>
            </div>
        <?php endif; ?>

        <?php if ($attempts_used > 0 && $locked_until === 0): ?>
            <div class="cw-attempts">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span><?= $attempts_left ?> attempt<?= $attempts_left === 1 ? '' : 's' ?> remaining before lockout.</span>
            </div>
        <?php endif; ?>

        <!-- Form card -->
        <div class="cw-card">
            <div class="cw-card-head">
                <div class="cw-card-head-icon"><i class="bi bi-key-fill"></i></div>
                <div>
                    <h3>Reset Password</h3>
                    <p>Enter the target username and set a new password.</p>
                </div>
            </div>

            <div class="cw-card-body">
                <form method="POST" id="passwordForm" autocomplete="off" novalidate>
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="cw-field">
                        <label for="username">Target Username</label>
                        <div class="cw-input-wrap">
                            <input type="text"
                                   id="username"
                                   name="username"
                                   placeholder="Enter username"
                                   required
                                   autofocus
                                   maxlength="50"
                                   autocomplete="off"
                                   value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>">
                            <i class="bi bi-person cw-input-icon"></i>
                        </div>
                    </div>

                    <div class="cw-field">
                        <div class="cw-field-head">
                            <label for="new_password">New Password</label>
                            <span class="cw-charcount" id="cwCharCount">0 / 128</span>
                        </div>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="new_password"
                                   name="new_password"
                                   placeholder="Enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="new_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <div class="cw-strength" id="cwStrength">
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                            <span class="cw-strength-seg"></span>
                        </div>
                        <div class="cw-reqs" id="cwReqs">
                            <span class="cw-req" data-req="length"><i class="bi bi-circle-fill"></i> 8+ chars</span>
                            <span class="cw-req" data-req="upper"><i class="bi bi-circle-fill"></i> A–Z</span>
                            <span class="cw-req" data-req="lower"><i class="bi bi-circle-fill"></i> a–z</span>
                            <span class="cw-req" data-req="num"><i class="bi bi-circle-fill"></i> 0–9</span>
                            <span class="cw-req" data-req="special"><i class="bi bi-circle-fill"></i> !@#</span>
                        </div>
                    </div>

                    <div class="cw-field">
                        <label for="confirm_password">Confirm New Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="confirm_password"
                                   name="confirm_password"
                                   placeholder="Re-enter new password"
                                   required
                                   minlength="8"
                                   maxlength="128"
                                   autocomplete="new-password">
                            <i class="bi bi-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="confirm_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="cw-divider">
                        <span><i class="bi bi-shield-check"></i> Verification</span>
                    </div>

                    <div class="cw-field">
                        <label for="admin_password">Your Admin Password</label>
                        <div class="cw-input-wrap">
                            <input type="password"
                                   id="admin_password"
                                   name="admin_password"
                                   placeholder="Enter your password to confirm"
                                   required
                                   autocomplete="current-password">
                            <i class="bi bi-shield-lock-fill cw-input-icon"></i>
                            <button type="button" class="cw-eye" data-toggle-pw="admin_password" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                        <p class="cw-hint">Required as proof that you're the one making this change.</p>
                    </div>

                    <button type="submit" class="cw-submit" id="cwSubmit" <?= $locked_until > 0 ? 'disabled' : '' ?>>
                        <i class="bi <?= $locked_until > 0 ? 'bi-lock-fill' : 'bi-key-fill' ?>"></i>
                        <span><?= $locked_until > 0 ? "Locked for {$locked_until} min" : 'Change Password' ?></span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== Sidebar toggle =====
const sb = document.getElementById('sidebar'), ov = document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click', () => {
    sb.classList.toggle('open');
    ov.style.display = sb.classList.contains('open') ? 'block' : 'none';
});

// ===== Theme toggle =====
const tb = document.getElementById('themeToggle'), ti = document.getElementById('themeIcon'), h = document.documentElement;
function st(t){
    h.setAttribute('data-bs-theme', t);
    ti.className = 'bi bi-' + (t === 'dark' ? 'sun-fill' : 'moon-stars-fill');
    document.cookie = 'admin_theme=' + t + ';path=/;max-age=' + 60*60*24*365;
}
(function(){
    const m = document.cookie.match(/(?:^|; )admin_theme=([^;]+)/);
    st(m ? decodeURIComponent(m[1]) : 'light');
})();
tb.addEventListener('click', () => st(h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'));

// ===== Password visibility toggles =====
document.querySelectorAll('[data-toggle-pw]').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('data-toggle-pw'));
        if (!input) return;
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            if (icon) icon.className = 'bi bi-eye-slash';
        } else {
            input.type = 'password';
            if (icon) icon.className = 'bi bi-eye';
        }
    });
});

// ===== Strength meter + requirement pills + char counter =====
(function() {
    const input = document.getElementById('new_password');
    const segs  = document.querySelectorAll('#cwStrength .cw-strength-seg');
    const reqs  = document.getElementById('cwReqs');
    const count = document.getElementById('cwCharCount');
    if (!input || !segs.length || !reqs) return;

    const rules = {
        length:  p => p.length >= 8,
        upper:   p => /[A-Z]/.test(p),
        lower:   p => /[a-z]/.test(p),
        num:     p => /[0-9]/.test(p),
        special: p => /[^A-Za-z0-9]/.test(p),
    };

    input.addEventListener('input', () => {
        const p = input.value;
        let met = 0;

        for (const [key, test] of Object.entries(rules)) {
            const pill = reqs.querySelector('[data-req="' + key + '"]');
            if (!pill) continue;
            const ok = test(p);
            pill.classList.toggle('met', ok);
            if (ok) met++;
        }

        const level = met <= 2 ? 'active-weak'
                    : met <= 3 ? 'active-medium'
                    : 'active-strong';
        segs.forEach((seg, i) => {
            seg.className = 'cw-strength-seg';
            if (i < met) seg.classList.add(level);
        });

        if (count) {
            const n = p.length;
            count.textContent = n + ' / 128';
            count.style.color = n > 128 ? 'var(--red)' : (n >= 8 ? 'var(--green)' : 'var(--text2)');
        }
    });
})();

// ===== Form submit =====
(function() {
    const form = document.getElementById('passwordForm');
    const btn  = document.getElementById('cwSubmit');
    if (!form || !btn) return;
    const btnText = btn.querySelector('span');
    const btnIcon = btn.querySelector('i');

    form.addEventListener('submit', function(e) {
        const np = document.getElementById('new_password').value;
        const cp = document.getElementById('confirm_password').value;
        const ap = document.getElementById('admin_password').value;
        const un = document.getElementById('username').value.trim();

        if (!un || !np || !cp || !ap) { e.preventDefault(); return; }
        if (np !== cp) { e.preventDefault(); alert('New passwords do not match.'); return; }
        if (np.length < 8) { e.preventDefault(); alert('Password must be at least 8 characters.'); return; }

        btn.disabled = true;
        if (btnIcon) btnIcon.className = 'spinner-border spinner-border-sm';
        if (btnText) btnText.textContent = 'Updating...';
    });
})();
</script>
</body>
</html>