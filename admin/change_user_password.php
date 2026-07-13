<?php
session_start();
include "../config/db.php";

$error = "";
$success = "";

// ============================================
// SECURITY: Require authentication
// ============================================
if (!isset($_SESSION['admin_id'])) {
    // Store intended page for redirect after login
    $_SESSION['redirect_after_login'] = 'change_user_password.php';
    header("Location: admin_login.php");
    exit();
}

// Verify session integrity
if (isset($_SESSION['user_agent']) && $_SESSION['user_agent'] !== ($_SERVER['HTTP_USER_AGENT'] ?? '')) {
    session_destroy();
    header("Location: admin_login.php?timeout=1");
    exit();
}

// ============================================
// SECURITY: CSRF Protection
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Rate limiting for password changes
$max_attempts = 3;
$lockout_time = 900; // 15 minutes
$rate_limit_key = 'pwd_change_attempts_' . $_SESSION['admin_id'];

if (!isset($_SESSION[$rate_limit_key])) {
    $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
}

// ============================================
// Process form submission
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Check rate limit
    if ($_SESSION[$rate_limit_key]['count'] >= $max_attempts) {
        $time_since_last = time() - $_SESSION[$rate_limit_key]['last_attempt'];
        if ($time_since_last < $lockout_time) {
            $remaining = ceil(($lockout_time - $time_since_last) / 60);
            $error = "Too many attempts. Please try again in {$remaining} minute(s).";
        } else {
            $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
        }
    }
    
    // Validate CSRF token
    if (empty($error)) {
        if (!isset($_POST['csrf_token']) || !isset($_SESSION['csrf_token']) || 
            !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            $error = "Invalid security token. Please refresh the page and try again.";
        }
    }
    
    // Validate admin password confirmation (prevent unauthorized changes)
    if (empty($error)) {
        $admin_password = $_POST['admin_password'] ?? '';
        
        if (empty($admin_password)) {
            $error = "Please enter your own admin password for verification.";
        } else {
            // Verify the logged-in admin's password
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
    
    // Process the password change
    if (empty($error)) {
        $target_username = trim($_POST['username'] ?? '');
        $new_password = $_POST['new_password'] ?? ''; // Don't trim passwords
        $confirm_password = $_POST['confirm_password'] ?? '';

        // Validate inputs
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
            // SECURITY: Prevent changing own password through this tool
            $current_admin_stmt = $conn->prepare("SELECT username FROM admins WHERE id = ? LIMIT 1");
            if ($current_admin_stmt) {
                $current_admin_stmt->bind_param("i", $_SESSION['admin_id']);
                $current_admin_stmt->execute();
                $current_result = $current_admin_stmt->get_result();
                $current_admin = $current_result->fetch_assoc();
                $current_admin_stmt->close();
                
                if ($current_admin && strcasecmp($target_username, $current_admin['username']) === 0) {
                    $error = "For security reasons, you cannot change your own password here. Please use the profile settings instead.";
                    error_log("Admin ID {$_SESSION['admin_id']} attempted to change own password via admin tool");
                }
            }
        }
        
        // Proceed with password change
        if (empty($error)) {
            // SECURITY: Use prepared statement with limited info disclosure
            $stmt = $conn->prepare("SELECT id, fullname, username, role FROM admins WHERE username = ? LIMIT 1");
            
            if ($stmt) {
                $stmt->bind_param("s", $target_username);
                $stmt->execute();
                $result = $stmt->get_result();

                if ($result->num_rows === 1) {
                    $user = $result->fetch_assoc();
                    
                    // SECURITY: Check if target user is superadmin (extra protection)
                    if ($user['role'] === 'superadmin' && $_SESSION['admin_role'] !== 'superadmin') {
                        $error = "You do not have permission to change this user's password.";
                        error_log("Non-superadmin attempted to change superadmin password. Admin ID: {$_SESSION['admin_id']}");
                    } else {
                        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT, [
                            'cost' => 12 // Increased cost for better security
                        ]);

                        // Update password and clear any reset tokens
                        $upd = $conn->prepare("UPDATE admins SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
                        
                        if ($upd) {
                            $upd->bind_param("si", $hashed_password, $user['id']);
                            $upd->execute();

                            if ($upd->affected_rows > 0) {
                                // Reset rate limit on success
                                $_SESSION[$rate_limit_key] = ['count' => 0, 'last_attempt' => 0];
                                
                                // Regenerate CSRF token after successful action
                                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                                
                                $success = "Password successfully updated for user.";
                                
                                // SECURITY: Log the password change
                                $log_message = sprintf(
                                    "[%s] Password changed for user '%s' (ID: %d) by admin '%s' (ID: %d) from IP: %s",
                                    date('Y-m-d H:i:s'),
                                    $target_username,
                                    $user['id'],
                                    $_SESSION['admin_name'],
                                    $_SESSION['admin_id'],
                                    $_SERVER['REMOTE_ADDR'] ?? 'unknown'
                                );
                                error_log($log_message);
                                
                                // Clear form data
                                unset($_POST['username'], $_POST['new_password'], $_POST['confirm_password']);
                                
                            } else {
                                $error = "Failed to update password. Please try again.";
                                error_log("Password update query affected 0 rows for user ID: {$user['id']}");
                            }
                            $upd->close();
                        } else {
                            $error = "System error. Please try again later.";
                            error_log("Database prepare failed for password update: " . $conn->error);
                        }
                    }
                } else {
                    // SECURITY: Generic error message to prevent username enumeration
                    $_SESSION[$rate_limit_key]['count']++;
                    $_SESSION[$rate_limit_key]['last_attempt'] = time();
                    
                    $error = "Could not process your request. Please verify the username and try again.";
                    error_log("Password change attempted for non-existent username from admin ID: {$_SESSION['admin_id']}");
                }
                $stmt->close();
            } else {
                $error = "System error. Please try again later.";
                error_log("Database prepare failed in change_user_password.php: " . $conn->error);
            }
        }
    }
}

// Regenerate CSRF token for the form
$csrf_token = $_SESSION['csrf_token'];

// Get current admin info for the form
$admin_name = htmlspecialchars($_SESSION['admin_name'] ?? 'Administrator', ENT_QUOTES, 'UTF-8');
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change User Password • USAT Admin</title>
    
    <!-- Security headers -->
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex, nofollow">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Playfair+Display:wght@700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

    <style>
        :root { 
            --primary: #1e40af; 
            --primary-light: #3b82f6;
            --primary-dark: #1e3a8a;
            --success: #10b981;
            --error: #ef4444;
            --warning: #f59e0b;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --bg-glass: rgba(255, 255, 255, 0.97);
        }

        [data-theme="dark"] {
            --primary: #60a5fa;
            --primary-light: #93c5fd;
            --primary-dark: #3b82f6;
            --text-primary: #f1f5f9;
            --text-secondary: #94a3b8;
            --border-color: #475569;
            --bg-glass: rgba(30, 41, 59, 0.97);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 40%, #1e40af 100%);
            background-size: 400% 400%;
            animation: gradientShift 35s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
            -webkit-font-smoothing: antialiased;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .container {
            width: 100%;
            max-width: 960px;
        }

        .card {
            display: flex;
            background: var(--bg-glass);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border-radius: 28px;
            overflow: hidden;
            box-shadow: 0 40px 100px rgba(30,58,138,0.55);
            min-height: 540px;
            border: 1px solid rgba(255,255,255,0.6);
        }

        .left {
            flex: 1;
            background: linear-gradient(135deg, var(--primary-dark), var(--primary));
            padding: 4rem 3rem;
            color: white;
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        .left::before {
            content: '';
            position: absolute;
            inset: 0;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 4s ease-in-out infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.3; }
            50% { transform: scale(1.1); opacity: 0.5; }
        }

        .logo { 
            width: 140px; 
            height: 140px; 
            border-radius: 50%; 
            border: 4px solid rgba(255,255,255,0.9); 
            margin-bottom: 2rem;
            position: relative;
            z-index: 1;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
        }

        .left h1 {
            position: relative;
            z-index: 1;
            font-family: 'Playfair Display', serif;
            font-size: 2.4rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }

        .left .admin-info {
            position: relative;
            z-index: 1;
            margin-top: 2rem;
            padding: 1rem 1.5rem;
            background: rgba(255,255,255,0.15);
            border-radius: 12px;
            font-size: 0.95rem;
            backdrop-filter: blur(5px);
        }

        .right {
            flex: 1.15;
            padding: 3.5rem 4rem;
            display: flex;
            flex-direction: column;
        }

        .right h2 {
            font-size: 1.8rem;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-weight: 700;
        }

        .subtitle {
            color: var(--text-secondary);
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        .form-group {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 0.95rem;
        }

        .form-group .icon-wrapper {
            position: relative;
        }

        .form-group .icon-wrapper i {
            position: absolute;
            left: 1.2rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-secondary);
            font-size: 1.2rem;
            transition: color 0.3s ease;
            z-index: 2;
        }

        .form-group input {
            width: 100%;
            padding: 1.2rem 1.2rem 1.2rem 3.5rem;
            border: 2px solid var(--border-color);
            border-radius: 12px;
            font-size: 1.05rem;
            background: white;
            color: var(--text-primary);
            transition: all 0.3s ease;
            font-family: 'Inter', sans-serif;
        }

        [data-theme="dark"] .form-group input {
            background: #1e2937;
            border-color: #475569;
            color: #f1f5f9;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
        }

        .form-group input:focus + i,
        .form-group input:focus ~ i {
            color: var(--primary);
        }

        .password-requirements {
            background: linear-gradient(135deg, #eff6ff, #dbeafe);
            padding: 1rem 1.2rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-size: 0.85rem;
            color: #1e40af;
            border-left: 4px solid var(--primary);
        }

        [data-theme="dark"] .password-requirements {
            background: linear-gradient(135deg, #1e2937, #1e3a5f);
            color: #93c5fd;
        }

        .password-requirements ul {
            list-style: none;
            padding: 0;
            margin: 0.5rem 0 0 0;
        }

        .password-requirements li {
            padding: 0.25rem 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .password-requirements li i {
            font-size: 0.8rem;
            width: 1rem;
            text-align: center;
        }

        .btn {
            width: 100%;
            padding: 1.3rem;
            background: linear-gradient(135deg, var(--primary), var(--primary-light));
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.15rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            box-shadow: 0 8px 25px rgba(30, 58, 138, 0.3);
            position: relative;
            overflow: hidden;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }

        .btn:hover::before {
            left: 100%;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px rgba(30, 58, 138, 0.4);
        }

        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .error { 
            background: #fef2f2;
            color: #991b1b;
            padding: 1rem 1.2rem;
            border-radius: 12px;
            text-align: left;
            margin-bottom: 1.5rem;
            font-size: 0.95rem;
            border-left: 4px solid var(--error);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        .success { 
            background: #ecfdf5;
            color: #065f46;
            padding: 1.2rem 1.5rem;
            border-radius: 12px;
            text-align: left;
            margin-bottom: 1.5rem;
            font-size: 1rem;
            border-left: 4px solid var(--success);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .back-link {
            text-align: center;
            margin-top: 2rem;
        }

        .back-link a {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }

        .back-link a:hover {
            color: var(--primary-light);
        }

        /* Theme Toggle */
        .theme-toggle {
            position: fixed;
            top: 2rem;
            right: 2rem;
            background: rgba(255,255,255,0.2);
            backdrop-filter: blur(10px);
            border: 2px solid rgba(255,255,255,0.3);
            width: 48px;
            height: 48px;
            border-radius: 50%;
            color: white;
            font-size: 1.4rem;
            cursor: pointer;
            z-index: 100;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .theme-toggle:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.1);
        }

        .strength-meter {
            height: 4px;
            border-radius: 2px;
            margin-top: 0.5rem;
            transition: all 0.3s ease;
            background: #e2e8f0;
        }

        .strength-meter.weak { background: var(--error); width: 33%; }
        .strength-meter.medium { background: var(--warning); width: 66%; }
        .strength-meter.strong { background: var(--success); width: 100%; }

        /* Responsive */
        @media (max-width: 860px) {
            .card {
                flex-direction: column;
            }
            .left {
                padding: 2.5rem 2rem;
            }
            .right {
                padding: 2.5rem 2rem;
            }
            .logo {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>

<!-- Theme Toggle -->
<button class="theme-toggle" id="themeToggle" aria-label="Toggle theme">
    <i class="fas fa-moon"></i>
</button>

<div class="container">
    <div class="card">
        <!-- Left Side -->
        <div class="left">
            <img src="../assets/img/usat.jpg" alt="USAT College Seal" class="logo"
                 onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%22140%22 height=%22140%22><rect fill=%22%234a90d9%22 width=%22140%22 height=%22140%22 rx=%2270%22/><text fill=%22white%22 font-size=%2250%22 x=%2250%25%22 y=%2255%25%22 text-anchor=%22middle%22 dy=%22.3em%22>USAT</text></svg>'">
            <h1>Password Reset</h1>
            <p style="position:relative;z-index:1;opacity:0.9;">Admin Tool</p>
            
            <div class="admin-info">
                <i class="fas fa-user-shield"></i>
                <span>Logged in as: <strong><?= $admin_name ?></strong></span>
            </div>
        </div>

        <!-- Right Side -->
        <div class="right">
            <h2>Change User Password</h2>
            <p class="subtitle">Enter the username and new password below</p>

            <?php if ($error): ?>
                <div class="error" role="alert">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success" role="status">
                    <i class="fas fa-check-circle"></i>
                    <span><?= $success ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" id="passwordForm" autocomplete="off" novalidate>
                <!-- CSRF Token -->
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
                
                <!-- Target Username -->
                <div class="form-group">
                    <label for="username">Target Username</label>
                    <div class="icon-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder="Enter username to change password for" 
                               required 
                               autofocus
                               maxlength="50"
                               autocomplete="off"
                               value="<?= isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : '' ?>"
                               aria-label="Target username">
                    </div>
                </div>

                <!-- New Password -->
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="icon-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               id="new_password" 
                               name="new_password" 
                               placeholder="Enter new password" 
                               required 
                               minlength="8"
                               maxlength="128"
                               autocomplete="new-password"
                               aria-label="New password">
                    </div>
                    <div class="strength-meter" id="strengthMeter"></div>
                </div>

                <!-- Confirm Password -->
                <div class="form-group">
                    <label for="confirm_password">Confirm New Password</label>
                    <div class="icon-wrapper">
                        <i class="fas fa-lock"></i>
                        <input type="password" 
                               id="confirm_password" 
                               name="confirm_password" 
                               placeholder="Confirm new password" 
                               required 
                               minlength="8"
                               maxlength="128"
                               autocomplete="new-password"
                               aria-label="Confirm new password">
                    </div>
                </div>

                <!-- Password Requirements -->
                <div class="password-requirements">
                    <strong><i class="fas fa-shield-alt"></i> Password Requirements:</strong>
                    <ul>
                        <li><i class="fas fa-check-circle" style="color:#10b981;"></i> At least 8 characters</li>
                        <li><i class="fas fa-check-circle" style="color:#10b981;"></i> One uppercase letter (A-Z)</li>
                        <li><i class="fas fa-check-circle" style="color:#10b981;"></i> One lowercase letter (a-z)</li>
                        <li><i class="fas fa-check-circle" style="color:#10b981;"></i> One number (0-9)</li>
                        <li><i class="fas fa-check-circle" style="color:#10b981;"></i> One special character (!@#$%^&*)</li>
                    </ul>
                </div>

                <!-- Admin Password Verification -->
                <div class="form-group">
                    <label for="admin_password">Your Admin Password (Verification)</label>
                    <div class="icon-wrapper">
                        <i class="fas fa-key"></i>
                        <input type="password" 
                               id="admin_password" 
                               name="admin_password" 
                               placeholder="Enter your own password to confirm" 
                               required
                               autocomplete="current-password"
                               aria-label="Your admin password for verification">
                    </div>
                </div>

                <button type="submit" class="btn" id="submitBtn">
                    <i class="fas fa-key"></i>
                    <span>Change Password</span>
                </button>
            </form>

            <div class="back-link">
                <a href="dashboard.php">
                    <i class="fas fa-arrow-left"></i>
                    Back to Dashboard
                </a>
            </div>
        </div>
    </div>
</div>

<script>
// Theme Management
(function() {
    const html = document.documentElement;
    const toggleBtn = document.getElementById('themeToggle');
    const icon = toggleBtn.querySelector('i');
    
    const savedTheme = localStorage.getItem('admin_theme') || 'light';
    html.setAttribute('data-theme', savedTheme);
    updateIcon(savedTheme);
    
    toggleBtn.addEventListener('click', () => {
        const current = html.getAttribute('data-theme') || 'light';
        const newTheme = current === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-theme', newTheme);
        localStorage.setItem('admin_theme', newTheme);
        updateIcon(newTheme);
    });
    
    function updateIcon(theme) {
        icon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
    }
})();

// Password Strength Meter
(function() {
    const passwordInput = document.getElementById('new_password');
    const strengthMeter = document.getElementById('strengthMeter');
    
    passwordInput.addEventListener('input', () => {
        const password = passwordInput.value;
        let strength = 0;
        
        if (password.length >= 8) strength++;
        if (/[A-Z]/.test(password)) strength++;
        if (/[a-z]/.test(password)) strength++;
        if (/[0-9]/.test(password)) strength++;
        if (/[^A-Za-z0-9]/.test(password)) strength++;
        
        strengthMeter.className = 'strength-meter';
        if (strength <= 2) {
            strengthMeter.classList.add('weak');
        } else if (strength <= 3) {
            strengthMeter.classList.add('medium');
        } else {
            strengthMeter.classList.add('strong');
        }
    });
})();

// Form submission
(function() {
    const form = document.getElementById('passwordForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = submitBtn.querySelector('span');
    const btnIcon = submitBtn.querySelector('i');
    
    form.addEventListener('submit', function(e) {
        const newPass = document.getElementById('new_password').value;
        const confirmPass = document.getElementById('confirm_password').value;
        const adminPass = document.getElementById('admin_password').value;
        
        // Client-side validation
        if (!newPass || !confirmPass || !adminPass) {
            e.preventDefault();
            return;
        }
        
        if (newPass !== confirmPass) {
            e.preventDefault();
            alert('New passwords do not match!');
            return;
        }
        
        if (newPass.length < 8) {
            e.preventDefault();
            alert('Password must be at least 8 characters!');
            return;
        }
        
        // Disable button and show loading
        submitBtn.disabled = true;
        btnIcon.className = 'fas fa-spinner fa-spin';
        btnText.textContent = 'Updating...';
        
        // Re-enable after 30 seconds if stuck
        setTimeout(() => {
            if (submitBtn.disabled) {
                submitBtn.disabled = false;
                btnIcon.className = 'fas fa-key';
                btnText.textContent = 'Change Password';
            }
        }, 30000);
    });
})();

// Prevent form resubmission
if (window.history.replaceState) {
    window.history.replaceState(null, null, window.location.href);
}
</script>

</body>
</html>