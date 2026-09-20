<?php
session_start();
include "../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

// ====================== FETCH DASHBOARD DATA ======================
// Total counts
$total_students = $conn->query("SELECT COUNT(*) as cnt FROM students_info")->fetch_assoc()['cnt'] ?? 0;
$total_enrolled = $conn->query("SELECT COUNT(DISTINCT student_id) as cnt FROM enrollment_form")->fetch_assoc()['cnt'] ?? 0;
$active_enrollment = $conn->query("SELECT COUNT(*) as cnt FROM enrollment_form WHERE status='Active' OR status IS NULL")->fetch_assoc()['cnt'] ?? 0;
$total_sections = $conn->query("SELECT COUNT(DISTINCT section) as cnt FROM enrollment_form WHERE section IS NOT NULL AND section != ''")->fetch_assoc()['cnt'] ?? 0;

// Gender stats
$gender = $conn->query("SELECT SUM(CASE WHEN sex='Male' THEN 1 ELSE 0 END) as boys, SUM(CASE WHEN sex='Female' THEN 1 ELSE 0 END) as girls FROM students_info")->fetch_assoc();
$boys = (int)($gender['boys'] ?? 0);
$girls = (int)($gender['girls'] ?? 0);

// Recent enrollments
$recent = $conn->query("SELECT s.student_id, s.first_name, s.last_name, s.lrn, s.student_id_number, e.grade_level, e.strand, e.section, e.enrolled_at FROM students_info s JOIN enrollment_form e ON s.student_id=e.student_id ORDER BY e.enrolled_at DESC LIMIT 8");

// Grade level distribution
$grade_dist = $conn->query("SELECT grade_level, COUNT(*) as cnt FROM enrollment_form GROUP BY grade_level ORDER BY grade_level");
$grade_labels = []; $grade_data = [];
while($g = $grade_dist->fetch_assoc()) { $grade_labels[] = $g['grade_level']; $grade_data[] = (int)$g['cnt']; }

// Strand distribution  
$strand_dist = $conn->query("SELECT COALESCE(strand,'Unassigned') as strand, COUNT(*) as cnt FROM enrollment_form GROUP BY strand ORDER BY cnt DESC");
$strand_labels = []; $strand_data = []; $strand_colors = [];
$colors = ['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'];
$ci = 0;
while($s = $strand_dist->fetch_assoc()) { 
    $strand_labels[] = $s['strand']; 
    $strand_data[] = (int)$s['cnt'];
    $strand_colors[] = $colors[$ci % count($colors)];
    $ci++;
}

// Unassigned students
$unassigned = $conn->query("SELECT COUNT(*) as cnt FROM enrollment_form WHERE section IS NULL OR section = ''")->fetch_assoc()['cnt'] ?? 0;

// Recently added (this week)
$week_start = date('Y-m-d', strtotime('monday this week'));
$new_this_week = $conn->query("SELECT COUNT(*) as cnt FROM students_info WHERE created_at >= '$week_start'")->fetch_assoc()['cnt'] ?? 0;

// ====================== FILTERS ======================
$search = trim($_GET['search'] ?? '');
$grade_filter = trim($_GET['grade_level'] ?? '');
$sy_filter = trim($_GET['school_year'] ?? '');

$where = [];
$params = [];
$types = "";

if ($search !== '') {
    $where[] = "(s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= "ssss";
}
if ($grade_filter !== '') { $where[] = "e.grade_level = ?"; $params[] = $grade_filter; $types .= "s"; }
if ($sy_filter !== '') { $where[] = "e.school_year = ?"; $params[] = $sy_filter; $types .= "s"; }

$where_clause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

// Students list with filters
$students_sql = "SELECT s.student_id, s.first_name, s.last_name, s.middle_name, s.lrn, s.student_id_number, s.sex, s.age, e.grade_level, e.school_year, e.strand, e.program, e.section, e.status FROM students_info s LEFT JOIN enrollment_form e ON s.student_id=e.student_id $where_clause ORDER BY s.last_name ASC LIMIT 100";
$stmt = $conn->prepare($students_sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$students = $stmt->get_result();
$filtered_count = $students->num_rows;
$stmt->close();

// Dropdowns
$grade_levels = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL ORDER BY grade_level ASC");
$school_years = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL ORDER BY school_year DESC");

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --bg: #f1f5f9; --surface: #ffffff; --text: #1a1f36; --text2: #6b7280;
            --border: #e5e7eb; --accent: #4f46e5; --accent2: #6366f1;
            --green: #059669; --red: #dc2626; --amber: #d97706; --blue: #2563eb;
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
        
        /* Sidebar */
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
        
        /* Stat Cards */
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;transition:all 0.3s;cursor:default}
        .stat-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--accent)}
        .stat-icon{width:50px;height:50px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
        .stat-icon.blue{background:#eef2ff;color:#4f46e5} [data-bs-theme="dark"] .stat-icon.blue{background:#312e81;color:#a5b4fc}
        .stat-icon.green{background:#d1fae5;color:#059669} [data-bs-theme="dark"] .stat-icon.green{background:#064e3b;color:#6ee7b7}
        .stat-icon.purple{background:#ede9fe;color:#7c3aed} [data-bs-theme="dark"] .stat-icon.purple{background:#4c1d95;color:#c4b5fd}
        .stat-icon.amber{background:#fef3c7;color:#d97706} [data-bs-theme="dark"] .stat-icon.amber{background:#78350f;color:#fcd34d}
        .stat-icon.red{background:#fee2e2;color:#dc2626} [data-bs-theme="dark"] .stat-icon.red{background:#7f1d1d;color:#fca5a5}
        .stat-info .stat-value{font-size:1.5rem;font-weight:700;line-height:1.2}
        .stat-info .stat-label{font-size:0.75rem;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:0.15rem}
        
        /* Card */
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}
        .card-header h3 i{color:var(--accent)}
        .card-body{padding:1.5rem}
        .card-body.no-padding{padding:0}
        
        /* Table */
        .table-admin{width:100%;border-collapse:collapse}
        .table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border)}
        .table-admin td{padding:0.7rem 1rem;border-bottom:1px solid var(--border);font-size:0.85rem;vertical-align:middle}
        .table-admin tr:hover td{background:rgba(79,70,229,0.03)}
        .table-admin tr:last-child td{border-bottom:none}
        
        /* Status dot */
        .status-dot{display:inline-flex;align-items:center;gap:0.35rem;font-size:0.78rem;font-weight:600}
        .status-dot::before{content:'';width:8px;height:8px;border-radius:50%;flex-shrink:0}
        .status-dot.success::before{background:var(--green)}.status-dot.danger::before{background:var(--red)}.status-dot.warning::before{background:var(--amber)}
        
        /* Filter bar */
        .filter-bar{display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center}
        .filter-bar input,.filter-bar select{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:0.5rem 1rem;font-size:0.85rem;color:var(--text);min-width:180px}
        .filter-bar button,.filter-bar a{white-space:nowrap}
        
        /* Badges */
        .badge-pill{display:inline-flex;align-items:center;gap:0.35rem;padding:0.25rem 0.7rem;border-radius:50px;font-size:0.72rem;font-weight:600}
        .badge-pill.success{background:#d1fae5;color:#065f46}.badge-pill.danger{background:#fee2e2;color:#991b1b}
        [data-bs-theme="dark"] .badge-pill.success{background:#064e3b;color:#6ee7b7}[data-bs-theme="dark"] .badge-pill.danger{background:#7f1d1d;color:#fca5a5}
        
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}
        
        .btn{font-weight:500;border-radius:8px}
        .btn-xs{padding:0.2rem 0.55rem;font-size:0.7rem;border-radius:6px}
        
        @media(max-width:1024px){
            .sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}
            .main-content{margin-left:0}.menu-toggle{display:flex}
        }
        @media(max-width:640px){
            .main-content{padding:1rem}.stat-grid{grid-template-columns:repeat(2,1fr)}
            .filter-bar{flex-direction:column}.filter-bar input,.filter-bar select{width:100%}
        }
    </style>
</head>
<body>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <img src="../assets/img/usat.jpg" alt="USAT" onerror="this.style.background='var(--accent)'">
        <span>USAT Admin</span>
    </div>
    <nav class="sidebar-nav">
        <a href="dashboard.php" class="active"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
    </nav>
    <div class="sidebar-footer">
        <a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a>
    </div>
</aside>

<div class="main-content" id="mainContent">

    <!-- Alerts -->
    <?php if(isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-check-circle-fill fs-5"></i> <?= $_SESSION['success'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= $_SESSION['error'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div>
        <?php unset($_SESSION['error']); endif; ?>

    <!-- Top Bar -->
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <h2 style="font-size:1.4rem;font-weight:700;margin:0">Dashboard</h2>
                <p class="text-muted small mb-0">Enrollment overview & management</p>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button>
            <button class="theme-btn" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($total_students) ?></div><div class="stat-label">Total Students</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($active_enrollment) ?></div><div class="stat-label">Active Enrolled</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="bi bi-diagram-3"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($total_sections) ?></div><div class="stat-label">Sections</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon amber"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($unassigned) ?></div><div class="stat-label">Need Section</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="bi bi-gender-male"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($boys) ?></div><div class="stat-label">Boys</div></div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="bi bi-gender-female"></i></div>
            <div class="stat-info"><div class="stat-value"><?= number_format($girls) ?></div><div class="stat-label">Girls</div></div>
        </div>
    </div>

    <!-- Charts Row -->
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-bar-chart"></i> Enrollment by Grade Level</h3></div>
                <div class="card-body"><canvas id="gradeChart" height="200"></canvas></div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header"><h3><i class="bi bi-pie-chart"></i> Strand Distribution</h3></div>
                <div class="card-body"><canvas id="strandChart" height="200"></canvas></div>
            </div>
        </div>
    </div>

    <!-- Filter & Student List -->
    <div class="card mt-3">
        <div class="card-header">
            <h3><i class="bi bi-list-ul"></i> Student Directory <span class="text-muted small">(<?= $filtered_count ?> results)</span></h3>
            <form class="filter-bar" method="GET">
                <input type="text" name="search" placeholder="Search name, LRN, ID..." value="<?= htmlspecialchars($search) ?>">
                <select name="grade_level"><option value="">All Grades</option><?php while($gl=$grade_levels->fetch_assoc()): ?><option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level']===$grade_filter?'selected':'' ?>><?= htmlspecialchars($gl['grade_level']) ?></option><?php endwhile; ?></select>
                <select name="school_year"><option value="">All Years</option><?php while($sy=$school_years->fetch_assoc()): ?><option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year']===$sy_filter?'selected':'' ?>><?= htmlspecialchars($sy['school_year']) ?></option><?php endwhile; ?></select>
                <button type="submit" class="btn btn-primary btn-sm">Filter</button>
                <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            </form>
        </div>
        <div class="card-body no-padding">
            <?php if($students->num_rows > 0): ?>
                <div style="overflow-x:auto">
                    <table class="table-admin">
                        <thead><tr><th>#</th><th>Student ID</th><th>Name</th><th>LRN</th><th>Grade</th><th>Strand</th><th>Section</th><th>Status</th><th style="text-align:right">Action</th></tr></thead>
                        <tbody>
                            <?php $n=1; while($s=$students->fetch_assoc()): 
                                $name = htmlspecialchars($s['last_name'].', '.$s['first_name'].($s['middle_name']?' '.$s['middle_name']:''));
                                $status = $s['status'] ?? 'Active';
                            ?>
                            <tr>
                                <td class="text-muted small"><?= $n++ ?></td>
                                <td class="fw-semibold small"><?= htmlspecialchars($s['student_id_number']??'—') ?></td>
                                <td class="fw-semibold"><?= $name ?></td>
                                <td class="small"><?= htmlspecialchars($s['lrn']??'—') ?></td>
                                <td><?= htmlspecialchars($s['grade_level']??'—') ?></td>
                                <td class="small"><?= htmlspecialchars($s['strand']??'—') ?></td>
                                <td class="fw-semibold" style="color:var(--accent)"><?= htmlspecialchars($s['section']??'—') ?></td>
                                <td><span class="status-dot <?= $status=='Active'?'success':'warning' ?>"><?= $status ?></span></td>
                                <td style="text-align:right"><a href="view_student.php?id=<?= $s['student_id'] ?>" class="btn btn-outline-primary btn-xs"><i class="bi bi-eye me-1"></i> View</a></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-center text-muted">No students found matching your filters.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Enrollments -->
    <div class="card mt-3">
        <div class="card-header"><h3><i class="bi bi-clock-history"></i> Recent Enrollments</h3></div>
        <div class="card-body no-padding">
            <?php if($recent && $recent->num_rows > 0): ?>
                <table class="table-admin">
                    <thead><tr><th>Student ID</th><th>Name</th><th>LRN</th><th>Grade</th><th>Strand</th><th>Section</th><th>Date</th></tr></thead>
                    <tbody>
                        <?php while($r=$recent->fetch_assoc()): ?>
                        <tr>
                            <td class="fw-semibold small"><?= htmlspecialchars($r['student_id_number']??'—') ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($r['last_name'].', '.$r['first_name']) ?></td>
                            <td class="small"><?= htmlspecialchars($r['lrn']??'—') ?></td>
                            <td><?= htmlspecialchars($r['grade_level']??'—') ?></td>
                            <td class="small"><?= htmlspecialchars($r['strand']??'—') ?></td>
                            <td style="color:var(--accent)"><?= htmlspecialchars($r['section']??'—') ?></td>
                            <td class="text-muted small"><?= date('M d, Y', strtotime($r['enrolled_at']??'')) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            <?php else: ?><div class="p-4 text-center text-muted">No recent enrollments.</div><?php endif; ?>
        </div>
    </div>
</div>

<!-- Mobile overlay -->
<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sidebar
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});

// Theme
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();
tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));

// Charts
new Chart(document.getElementById('gradeChart'),{
    type:'bar',data:{labels:<?= json_encode($grade_labels) ?>,datasets:[{label:'Students',data:<?= json_encode($grade_data) ?>,backgroundColor:'#4f46e5',borderRadius:8}]},
    options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:getComputedStyle(document.documentElement).getPropertyValue('--border')}}}}
});
new Chart(document.getElementById('strandChart'),{
    type:'doughnut',data:{labels:<?= json_encode($strand_labels) ?>,datasets:[{data:<?= json_encode($strand_data) ?>,backgroundColor:<?= json_encode($strand_colors) ?>,borderWidth:2,borderColor:'var(--surface)'}]},
    options:{responsive:true,plugins:{legend:{position:'bottom',labels:{padding:20,usePointStyle:true,pointStyleWidth:10}}}}
});
</script>
</body>
</html>