<?php
session_start();
include "../config/db.php";

// ====================== SECURITY: Only Super Admin Allowed ======================
if (!isset($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'superadmin') {
    $_SESSION['error'] = "Access Denied! Only the Principal can manage accounts.";
    header("Location: dashboard.php");
    exit();
}

// ====================== HANDLE ADD ACCOUNT ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_account'])) {
    $fullname = trim($_POST['fullname']);
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $role     = trim($_POST['role']);

    if (empty($fullname) || empty($username) || empty($password) || empty($role)) {
        $_SESSION['error'] = "All fields are required!";
    } elseif (strlen($password) < 8) {
        $_SESSION['error'] = "Password must be at least 8 characters!";
    } else {
        $check = $conn->prepare("SELECT id FROM admins WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        
        if ($check->get_result()->num_rows > 0) {
            $_SESSION['error'] = "Username already exists!";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO admins (fullname, username, password, role) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("ssss", $fullname, $username, $hashed_password, $role);
            
            if ($stmt->execute()) {
                $_SESSION['success'] = "Account created for " . htmlspecialchars($fullname) . "!";
            } else {
                $_SESSION['error'] = "Failed to create account.";
            }
            $stmt->close();
        }
        $check->close();
    }
    header("Location: create_account.php");
    exit();
}

// ====================== HANDLE DELETE ACCOUNT ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_account'])) {
    $account_id = (int)$_POST['account_id'];

    if ($account_id == $_SESSION['admin_id']) {
        $_SESSION['error'] = "You cannot delete your own account!";
    } else {
        // Prevent deleting the last superadmin
        $count_sa = $conn->query("SELECT COUNT(*) as cnt FROM admins WHERE role='superadmin'")->fetch_assoc()['cnt'];
        $target_role = $conn->query("SELECT role FROM admins WHERE id=$account_id")->fetch_assoc()['role'] ?? '';
        
        if ($target_role === 'superadmin' && $count_sa <= 1) {
            $_SESSION['error'] = "Cannot delete the last Super Admin account!";
        } else {
            $stmt = $conn->prepare("DELETE FROM admins WHERE id = ?");
            $stmt->bind_param("i", $account_id);
            $stmt->execute() ? $_SESSION['success'] = "Account deleted!" : $_SESSION['error'] = "Failed to delete.";
            $stmt->close();
        }
    }
    header("Location: create_account.php");
    exit();
}

// ====================== FETCH ACCOUNTS ======================
$accounts = $conn->query("SELECT id, fullname, username, role, created_at FROM admins ORDER BY created_at DESC")->fetch_all(MYSQLI_ASSOC);
$total_accounts = count($accounts);
$superadmin_count = count(array_filter($accounts, fn($a) => $a['role'] === 'superadmin'));
$registrar_count = $total_accounts - $superadmin_count;

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Accounts • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f1f5f9; --surface: #ffffff; --text: #1a1f36; --text2: #6b7280;
            --border: #e5e7eb; --accent: #4f46e5; --accent2: #6366f1;
            --green: #059669; --red: #dc2626; --amber: #d97706;
            --shadow: 0 1px 3px rgba(0,0,0,0.06); --shadow-lg: 0 10px 25px rgba(0,0,0,0.08);
            --radius: 12px; --radius-lg: 16px;
        }
        [data-bs-theme="dark"] {
            --bg: #0f172a; --surface: #1e293b; --text: #f1f5f9; --text2: #94a3b8;
            --border: #334155; --accent: #818cf8; --accent2: #6366f1;
            --shadow: 0 1px 3px rgba(0,0,0,0.3); --shadow-lg: 0 10px 25px rgba(0,0,0,0.5);
        }
        *{font-family:'Inter',system-ui,sans-serif;margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--text);min-height:100vh}
        
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform 0.3s}
        .sidebar-brand{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}
        .sidebar-brand img{width:40px;height:40px;border-radius:10px}
        .sidebar-brand span{font-weight:700;font-size:1.1rem}
        .sidebar-nav{flex:1;padding:1rem 0.75rem;overflow-y:auto}
        .sidebar-nav a{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;border-radius:10px;color:var(--text2);text-decoration:none;font-weight:500;font-size:0.9rem;transition:all 0.2s;margin-bottom:0.25rem}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--accent);color:white}
        .sidebar-nav a i{font-size:1.2rem;width:24px;text-align:center}
        .sidebar-footer{padding:1rem 0.75rem;border-top:1px solid var(--border)}
        
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);padding:1rem 1.25rem;text-align:center;transition:all 0.3s}
        .stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--accent)}
        .stat-value{font-size:1.6rem;font-weight:700;color:var(--accent)}
        .stat-label{font-size:0.72rem;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem}
        
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}
        .card-header h3 i{color:var(--accent)}
        .card-body{padding:1.5rem}.card-body.no-padding{padding:0}
        
        .table-admin{width:100%;border-collapse:collapse}
        .table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border)}
        .table-admin td{padding:0.75rem 1rem;border-bottom:1px solid var(--border);font-size:0.85rem;vertical-align:middle}
        .table-admin tr:hover td{background:rgba(79,70,229,0.03)}
        .table-admin tr:last-child td{border-bottom:none}
        
        .badge-pill{display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.7rem;border-radius:50px;font-size:0.72rem;font-weight:600}
        .badge-pill.danger{background:#fee2e2;color:#991b1b}[data-bs-theme="dark"] .badge-pill.danger{background:#7f1d1d;color:#fca5a5}
        .badge-pill.primary{background:#dbeafe;color:#1e40af}[data-bs-theme="dark"] .badge-pill.primary{background:#1e3a5f;color:#93c5fd}
        
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}
        .btn{font-weight:500;border-radius:8px}
        
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}
        @media(max-width:640px){.main-content{padding:1rem}.stat-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><img src="../assets/img/usat.jpg" alt="USAT"><span>USAT Admin</span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php" class="active"><i class="bi bi-person-plus"></i> Accounts</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></div>
</aside>

<div class="main-content" id="mainContent">
    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-check-circle-fill fs-5"></i> <?= $_SESSION['success'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= $_SESSION['error'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['error']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2 style="font-size:1.4rem;font-weight:700;margin:0">Admin Accounts</h2>
                <p class="text-muted small mb-0">Super Admin access only</p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge-pill danger"><i class="bi bi-shield-lock"></i> Restricted Area</span>
            <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <!-- Stats -->
    <div class="stat-grid">
        <div class="stat-card"><div class="stat-value"><?= $total_accounts ?></div><div class="stat-label">Total Accounts</div></div>
        <div class="stat-card"><div class="stat-value"><?= $superadmin_count ?></div><div class="stat-label">Super Admins</div></div>
        <div class="stat-card"><div class="stat-value"><?= $registrar_count ?></div><div class="stat-label">Registrars</div></div>
    </div>

    <!-- Accounts Table -->
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-people"></i> All Admin Accounts</h3>
            <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addAccountModal"><i class="bi bi-plus-lg me-1"></i> Add Account</button>
        </div>
        <div class="card-body no-padding">
            <table class="table-admin">
                <thead><tr><th>ID</th><th>Full Name</th><th>Username</th><th>Role</th><th>Created</th><th class="text-center">Action</th></tr></thead>
                <tbody>
                    <?php if(!empty($accounts)): ?>
                        <?php foreach($accounts as $row): $is_self = $row['id'] == $_SESSION['admin_id']; ?>
                        <tr>
                            <td class="text-muted small">#<?= $row['id'] ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($row['fullname']) ?><?= $is_self?' <span class="text-muted small">(you)</span>':'' ?></td>
                            <td><?= htmlspecialchars($row['username']) ?></td>
                            <td><span class="badge-pill <?= $row['role']==='superadmin'?'danger':'primary' ?>"><i class="bi bi-<?= $row['role']==='superadmin'?'shield-fill':'person-check' ?>"></i> <?= ucfirst($row['role']) ?></span></td>
                            <td class="text-muted small"><?= date('M d, Y', strtotime($row['created_at'])) ?></td>
                            <td class="text-center">
                                <?php if(!$is_self): ?>
                                    <button class="btn btn-outline-danger btn-xs" onclick="confirmDelete(<?= $row['id'] ?>,'<?= addslashes(htmlspecialchars($row['fullname'])) ?>')"><i class="bi bi-trash3 me-1"></i> Delete</button>
                                <?php else: ?>
                                    <span class="text-muted small">Current</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted">No accounts found.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Account Modal -->
<div class="modal fade" id="addAccountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-2"></i>Create New Account</h6><button class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3"><label class="form-label small fw-semibold">Full Name</label><input type="text" name="fullname" class="form-control" placeholder="e.g. Juan Dela Cruz" required></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Username</label><input type="text" name="username" class="form-control" placeholder="e.g. juan_admin" required></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Password</label><input type="password" name="password" class="form-control" placeholder="Min. 8 characters" minlength="8" required></div>
                    <div class="mb-3"><label class="form-label small fw-semibold">Role</label>
                        <select name="role" class="form-select" required>
                            <option value="registrar">Registrar</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                        <div class="form-text small">Super Admin has full access including account management.</div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button type="submit" name="add_account" class="btn btn-primary btn-sm">Create Account</button></div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header bg-danger text-white"><h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Account</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body"><p>Delete <strong id="deleteAccountName"></strong>? This cannot be undone.</p></div>
            <div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" id="deleteForm"><input type="hidden" name="delete_account" value="1"><input type="hidden" name="account_id" id="deleteAccountId"><button class="btn btn-danger btn-sm">Delete</button></form>
            </div>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();
tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));

function confirmDelete(id, name) {
    document.getElementById('deleteAccountId').value = id;
    document.getElementById('deleteAccountName').textContent = name;
    new bootstrap.Modal(document.getElementById('deleteConfirmModal')).show();
}
</script>
</body>
</html>