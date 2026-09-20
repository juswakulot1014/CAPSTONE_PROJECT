<?php
session_start();
include "../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$page_title = "Enrollment Reports";

// Check if we are exporting to Word
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1;

// Get filters
$selected_sy = $_GET['school_year'] ?? '';
$strand_filter = $_GET['strand_filter'] ?? '';
$view_graduated = isset($_GET['view_graduated']);

$sy_filter = "";
$params_main = [];
$types_main = "";

if (!empty($selected_sy)) {
    $sy_filter = " AND e.school_year = ?";
    $params_main[] = $selected_sy;
    $types_main .= "s";
}
if (!empty($strand_filter)) {
    $sy_filter .= " AND e.strand = ?";
    $params_main[] = $strand_filter;
    $types_main .= "s";
}

// ====================== KPI QUERIES (SEPARATE - RELIABLE) ======================
// Total Students
$ts_sql = "SELECT COUNT(DISTINCT e.student_id) as total FROM enrollment_form e WHERE 1=1 $sy_filter";
$stmt = $conn->prepare($ts_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$total_students = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); 
$stmt->close();

// Total Sections
$tsec_sql = "SELECT COUNT(DISTINCT e.section) as total FROM enrollment_form e WHERE e.section IS NOT NULL AND TRIM(e.section) != '' $sy_filter";
$stmt = $conn->prepare($tsec_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$total_sections = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); 
$stmt->close();

// Gender
$gen_sql = "SELECT SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) as boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) as girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE 1=1 $sy_filter";
$stmt = $conn->prepare($gen_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$gender = $stmt->get_result()->fetch_assoc() ?: []; 
$stmt->close();
$boys = (int)($gender['boys'] ?? 0);
$girls = (int)($gender['girls'] ?? 0);
$total_gender = $boys + $girls;
$boy_percent = $total_gender > 0 ? round(($boys / $total_gender) * 100, 1) : 0;
$girl_percent = $total_gender > 0 ? round(($girls / $total_gender) * 100, 1) : 0;

// Total Strands
$tstr_sql = "SELECT COUNT(DISTINCT e.strand) as total FROM enrollment_form e WHERE e.strand IS NOT NULL $sy_filter";
$stmt = $conn->prepare($tstr_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$total_strands = (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0); 
$stmt->close();

// ====================== GRADUATED STUDENTS ======================
$graduated_students = [];
$total_graduated = 0;
if ($view_graduated) {
    $grad_sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.sex, e.grade_level, e.strand, e.program, e.section, e.school_year, e.status FROM students_info s JOIN enrollment_form e ON s.student_id = e.student_id WHERE e.status = 'Graduated'";
    $grad_params = []; $grad_types = "";
    if (!empty($selected_sy)) { $grad_sql .= " AND e.school_year = ?"; $grad_params[] = $selected_sy; $grad_types .= "s"; }
    if (!empty($strand_filter)) { $grad_sql .= " AND e.strand = ?"; $grad_params[] = $strand_filter; $grad_types .= "s"; }
    $grad_sql .= " ORDER BY s.last_name ASC, s.first_name ASC";
    $gs = $conn->prepare($grad_sql);
    if (!empty($grad_params)) $gs->bind_param($grad_types, ...$grad_params);
    $gs->execute(); 
    $graduated_students = $gs->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; 
    $total_graduated = count($graduated_students); 
    $gs->close();
}

// ====================== REPORT QUERIES ======================
// Grade Level Report
$grade_sql = "SELECT e.grade_level, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.grade_level IS NOT NULL $sy_filter GROUP BY e.grade_level ORDER BY e.grade_level ASC";
$stmt = $conn->prepare($grade_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$grade_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; 
$stmt->close();

// Strand Report
$strand_sql = "SELECT COALESCE(e.strand, 'General') AS strand, COALESCE(e.program, 'N/A') AS program, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE 1=1 $sy_filter GROUP BY e.strand, e.program ORDER BY total_students DESC, strand ASC";
$stmt = $conn->prepare($strand_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$strand_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; 
$stmt->close();

// Section Report
$section_sql = "SELECT e.grade_level, e.section, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.section IS NOT NULL AND TRIM(e.section) != '' $sy_filter GROUP BY e.grade_level, e.section ORDER BY e.grade_level ASC, e.section ASC";
$stmt = $conn->prepare($section_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$section_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; 
$stmt->close();

// Yearly Trend Report
$year_sql = "SELECT e.school_year, COUNT(DISTINCT s.student_id) AS total_students, SUM(CASE WHEN s.sex = 'Male' THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN s.sex = 'Female' THEN 1 ELSE 0 END) AS girls FROM enrollment_form e JOIN students_info s ON e.student_id = s.student_id WHERE e.school_year IS NOT NULL $sy_filter GROUP BY e.school_year ORDER BY e.school_year DESC";
$stmt = $conn->prepare($year_sql);
if (!empty($params_main)) $stmt->bind_param($types_main, ...$params_main);
$stmt->execute(); 
$year_report = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; 
$stmt->close();

// Dropdowns
$sy_list = $conn->query("SELECT DISTINCT school_year FROM enrollment_form ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$strand_list = $conn->query("SELECT DISTINCT strand FROM enrollment_form WHERE strand IS NOT NULL AND strand != '' ORDER BY strand ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

// ====================== CHART DATA ======================
$grade_labels = []; $grade_totals = []; $grade_boys = []; $grade_girls = [];
foreach($grade_report as $g){ $grade_labels[]=$g['grade_level']; $grade_totals[]=(int)$g['total_students']; $grade_boys[]=(int)$g['boys']; $grade_girls[]=(int)$g['girls']; }
$strand_labels = []; $strand_totals = [];
foreach($strand_report as $s){ $strand_labels[]=$s['strand'].($s['program']!='N/A'?' - '.$s['program']:''); $strand_totals[]=(int)$s['total_students']; }
$year_labels = []; $year_totals = [];
foreach(array_reverse($year_report) as $y){ $year_labels[]=$y['school_year']; $year_totals[]=(int)$y['total_students']; }

// ====================== WORD EXPORT ======================
if ($export_word) {
    header("Content-Type: application/msword");
    header("Content-Disposition: attachment; filename=enrollment_report_" . date('Y-m-d') . ".doc");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title><style>body{font-family:Arial;margin:2cm}h1{color:#1e3c72}h2{color:#2b4c8c;margin-top:20px;border-bottom:2px solid #2b4c8c}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #ddd;padding:8px}th{background:#f2f2f2}</style></head><body>';
    echo '<h1>USAT College Enrollment Report</h1><p>Generated: '.date('F d, Y h:i A').'</p>';
    if(!empty($selected_sy)) echo '<p><strong>School Year:</strong> '.htmlspecialchars($selected_sy).'</p>';
    if(!empty($strand_filter)) echo '<p><strong>Strand:</strong> '.htmlspecialchars($strand_filter).'</p>';
    if ($view_graduated) {
        echo '<h2>Graduated Students</h2><p>Total: '.$total_graduated.'</p>';
        echo '<table><tr><th>Student ID</th><th>LRN</th><th>Name</th><th>Strand</th><th>Section</th><th>School Year</th></tr>';
        foreach($graduated_students as $g) echo '<tr><td>'.htmlspecialchars($g['student_id_number']??'—').'</td><td>'.htmlspecialchars($g['lrn']??'—').'</td><td>'.htmlspecialchars($g['last_name'].', '.$g['first_name']).'</td><td>'.htmlspecialchars($g['strand']??'—').'</td><td>'.htmlspecialchars($g['section']??'—').'</td><td>'.htmlspecialchars($g['school_year']??'—').'</td></tr>';
        echo '</table>';
    } else {
        echo '<h2>Summary</h2><table><tr><th>Total Students</th><td>'.number_format($total_students).'</td><th>Total Sections</th><td>'.number_format($total_sections).'</td></tr><tr><th>Boys</th><td>'.number_format($boys).' ('.$boy_percent.'%)</td><th>Girls</th><td>'.number_format($girls).' ('.$girl_percent.'%)</td></tr></table>';
        echo '<h2>By Grade Level</h2><table><tr><th>Grade</th><th>Total</th><th>Boys</th><th>Girls</th></tr>';
        foreach($grade_report as $r) echo '<tr><td>'.htmlspecialchars($r['grade_level']).'</td><td>'.number_format($r['total_students']).'</td><td>'.number_format($r['boys']).'</td><td>'.number_format($r['girls']).'</td></tr>';
        echo '</table>';
    }
    echo '<p><em>End of Report</em></p></body></html>';
    exit;
}

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
    <style>
        :root {--bg:#f1f5f9;--surface:#fff;--text:#1a1f36;--text2:#6b7280;--border:#e5e7eb;--accent:#4f46e5;--accent2:#6366f1;--green:#059669;--red:#dc2626;--amber:#d97706;--shadow:0 1px 3px rgba(0,0,0,0.06);--shadow-lg:0 10px 25px rgba(0,0,0,0.08);--radius:12px;--radius-lg:16px}
        [data-bs-theme="dark"] {--bg:#0f172a;--surface:#1e293b;--text:#f1f5f9;--text2:#94a3b8;--border:#334155;--accent:#818cf8;--accent2:#6366f1;--shadow:0 1px 3px rgba(0,0,0,0.3);--shadow-lg:0 10px 25px rgba(0,0,0,0.5)}
        *{font-family:'Inter',system-ui,sans-serif;margin:0;padding:0;box-sizing:border-box}body{background:var(--bg);color:var(--text);min-height:100vh}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform 0.3s}
        .sidebar-brand{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}.sidebar-brand img{width:40px;height:40px;border-radius:10px}.sidebar-brand span{font-weight:700;font-size:1.1rem}
        .sidebar-nav{flex:1;padding:1rem 0.75rem;overflow-y:auto}.sidebar-nav a{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;border-radius:10px;color:var(--text2);text-decoration:none;font-weight:500;font-size:0.9rem;transition:all 0.2s;margin-bottom:0.25rem}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--accent);color:white}.sidebar-nav a i{font-size:1.2rem;width:24px;text-align:center}.sidebar-footer{padding:1rem 0.75rem;border-top:1px solid var(--border)}
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}.menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);padding:1.25rem;text-align:center;transition:all 0.3s}.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--accent)}.stat-card .stat-value{font-size:1.6rem;font-weight:700;color:var(--accent)}.stat-card .stat-label{font-size:0.72rem;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem}
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}.card-header h3 i{color:var(--accent)}.card-body{padding:1.5rem}.card-body.no-padding{padding:0}
        .table-admin{width:100%;border-collapse:collapse}.table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border)}
        .table-admin td{padding:0.7rem 1rem;border-bottom:1px solid var(--border);font-size:0.85rem;vertical-align:middle}.table-admin tr:hover td{background:rgba(79,70,229,0.03)}.table-admin tr:last-child td{border-bottom:none}
        .btn-grad{background:linear-gradient(135deg,#059669,#047857);color:#fff;border:none;font-weight:600}.btn-grad:hover{background:linear-gradient(135deg,#047857,#065f46);color:#fff}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}.theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}.btn{font-weight:500;border-radius:8px}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}@media(max-width:640px){.main-content{padding:1rem}.stat-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><img src="../assets/img/usat.jpg" alt="USAT"><span>USAT Admin</span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php" class="active"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></div>
</aside>

<div class="main-content" id="mainContent">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3"><button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button><div><h2 style="font-size:1.4rem;font-weight:700;margin:0">Reports</h2><p class="text-muted small mb-0">Enrollment statistics & summaries</p></div></div>
        <div class="d-flex gap-2">
            <?php if($view_graduated): ?><a href="reports.php?<?= http_build_query(array_filter(['school_year'=>$selected_sy,'strand_filter'=>$strand_filter])) ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back to Reports</a><?php endif; ?>
            <a href="?export_word=1<?= !empty($selected_sy)?'&school_year='.urlencode($selected_sy):'' ?><?= !empty($strand_filter)?'&strand_filter='.urlencode($strand_filter):'' ?><?= $view_graduated?'&view_graduated=1':'' ?>" class="btn btn-outline-success btn-sm"><i class="bi bi-file-word me-1"></i> Export</a>
            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm"><i class="bi bi-printer me-1"></i> Print</button>
            <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <!-- Filter -->
    <div class="card"><div class="card-header"><h3><i class="bi bi-funnel"></i> Filters</h3></div><div class="card-body">
        <form method="GET" class="d-flex gap-3 align-items-end flex-wrap">
            <div><label class="form-label small fw-semibold text-muted">School Year</label><select name="school_year" class="form-select" style="min-width:180px"><option value="">All Years</option><?php foreach($sy_list as $sy): ?><option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $selected_sy==$sy['school_year']?'selected':'' ?>><?= htmlspecialchars($sy['school_year']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small fw-semibold text-muted">Strand</label><select name="strand_filter" class="form-select" style="min-width:200px"><option value="">All Strands</option><?php foreach($strand_list as $st): ?><option value="<?= htmlspecialchars($st['strand']) ?>" <?= $strand_filter==$st['strand']?'selected':'' ?>><?= htmlspecialchars($st['strand']) ?></option><?php endforeach; ?></select></div>
            <button type="submit" class="btn btn-primary btn-sm">Apply</button>
            <a href="reports.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            <?php if(!$view_graduated): ?><a href="reports.php?view_graduated=1<?= !empty($selected_sy)?'&school_year='.urlencode($selected_sy):'' ?><?= !empty($strand_filter)?'&strand_filter='.urlencode($strand_filter):'' ?>" class="btn btn-grad btn-sm"><i class="bi bi-mortarboard me-1"></i> View Graduated Students</a><?php endif; ?>
        </form>
    </div></div>

    <?php if($view_graduated): ?>
    <div class="stat-grid"><div class="stat-card"><div class="stat-value" style="color:var(--green)"><?= number_format($total_graduated) ?></div><div class="stat-label">Total Graduated</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($selected_sy?:'All Years') ?></div><div class="stat-label">School Year</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($strand_filter?:'All') ?></div><div class="stat-label">Strand</div></div></div>
    <div class="card"><div class="card-header"><h3><i class="bi bi-mortarboard"></i> Graduated Students (<?= $total_graduated ?>)</h3></div><div class="card-body no-padding">
        <?php if(!empty($graduated_students)): ?><table class="table-admin"><thead><tr><th>Student ID</th><th>LRN</th><th>Name</th><th>Sex</th><th>Strand</th><th>Program</th><th>Section</th><th>School Year</th><th>Action</th></tr></thead><tbody>
            <?php foreach($graduated_students as $g): ?><tr><td class="fw-semibold small"><?= htmlspecialchars($g['student_id_number']??'—') ?></td><td class="small"><?= htmlspecialchars($g['lrn']??'—') ?></td><td class="fw-semibold"><?= htmlspecialchars($g['last_name'].', '.$g['first_name']) ?></td><td><?= htmlspecialchars($g['sex']??'—') ?></td><td><?= htmlspecialchars($g['strand']??'—') ?></td><td class="small"><?= htmlspecialchars($g['program']??'—') ?></td><td style="color:var(--accent)"><?= htmlspecialchars($g['section']??'—') ?></td><td><?= htmlspecialchars($g['school_year']??'—') ?></td><td><a href="view_student.php?id=<?= $g['student_id'] ?>" class="btn btn-outline-primary btn-xs"><i class="bi bi-eye me-1"></i> View</a></td></tr><?php endforeach; ?>
        </tbody></table><?php else: ?><div class="text-center py-5 text-muted"><i class="bi bi-mortarboard display-4 d-block mb-3"></i>No graduated students found</div><?php endif; ?>
    </div></div>
    <?php else: ?>
    <div class="stat-grid"><div class="stat-card"><div class="stat-value"><?= number_format($total_students) ?></div><div class="stat-label">Total Students</div></div><div class="stat-card"><div class="stat-value"><?= number_format($total_sections) ?></div><div class="stat-label">Sections</div></div><div class="stat-card"><div class="stat-value"><?= number_format($boys) ?> / <?= number_format($girls) ?></div><div class="stat-label">Boys (<?= $boy_percent ?>%) / Girls (<?= $girl_percent ?>%)</div></div><div class="stat-card"><div class="stat-value"><?= number_format($total_strands) ?></div><div class="stat-label">Strands</div></div></div>
    <div class="row g-3">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-bar-chart"></i> By Grade Level</h3></div><div class="card-body"><canvas id="gradeChart" height="180"></canvas></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-pie-chart"></i> By Strand</h3></div><div class="card-body"><canvas id="strandChart" height="180"></canvas></div></div></div>
        <div class="col-12"><div class="card"><div class="card-header"><h3><i class="bi bi-graph-up"></i> Yearly Trend</h3></div><div class="card-body"><canvas id="trendChart" height="100"></canvas></div></div></div>
    </div>
    <div class="row g-3 mt-2">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-list-ol"></i> Grade Level Breakdown</h3></div><div class="card-body no-padding"><table class="table-admin"><thead><tr><th>Grade</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th><th class="text-center">Ratio</th></tr></thead><tbody><?php foreach($grade_report as $r): $ratio=$r['girls']>0?round($r['boys']/$r['girls'],2):'—'; ?><tr><td class="fw-bold"><?= htmlspecialchars($r['grade_level']) ?></td><td class="fw-semibold text-center"><?= number_format($r['total_students']) ?></td><td class="text-center"><?= number_format($r['boys']) ?></td><td class="text-center"><?= number_format($r['girls']) ?></td><td class="text-center text-muted"><?= $ratio ?>:1</td></tr><?php endforeach; ?><?php if(empty($grade_report)): ?><tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr><?php endif; ?></tbody></table></div></div></div>
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-tags"></i> Strand & Program</h3></div><div class="card-body no-padding"><table class="table-admin"><thead><tr><th>Strand</th><th>Program</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th></tr></thead><tbody><?php foreach($strand_report as $r): ?><tr><td class="fw-semibold"><?= htmlspecialchars($r['strand']) ?></td><td><?= htmlspecialchars($r['program']) ?></td><td class="fw-semibold text-center"><?= number_format($r['total_students']) ?></td><td class="text-center"><?= number_format($r['boys']) ?></td><td class="text-center"><?= number_format($r['girls']) ?></td></tr><?php endforeach; ?><?php if(empty($strand_report)): ?><tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr><?php endif; ?></tbody></table></div></div></div>
        <div class="col-12"><div class="card"><div class="card-header"><h3><i class="bi bi-diagram-3"></i> Sections</h3></div><div class="card-body no-padding"><table class="table-admin"><thead><tr><th>Grade</th><th>Section</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th></tr></thead><tbody><?php foreach($section_report as $r): ?><tr><td><?= htmlspecialchars($r['grade_level']) ?></td><td class="fw-bold" style="color:var(--accent)"><?= htmlspecialchars($r['section']) ?></td><td class="fw-semibold text-center"><?= number_format($r['total_students']) ?></td><td class="text-center"><?= number_format($r['boys']) ?></td><td class="text-center"><?= number_format($r['girls']) ?></td></tr><?php endforeach; ?><?php if(empty($section_report)): ?><tr><td colspan="5" class="text-center text-muted py-3">No data</td></tr><?php endif; ?></tbody></table></div></div></div>
        <div class="col-12"><div class="card"><div class="card-header"><h3><i class="bi bi-calendar-range"></i> Yearly Trend</h3></div><div class="card-body no-padding"><table class="table-admin"><thead><tr><th>School Year</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th></tr></thead><tbody><?php foreach($year_report as $r): ?><tr><td class="fw-bold"><?= htmlspecialchars($r['school_year']) ?></td><td class="fw-semibold text-center"><?= number_format($r['total_students']) ?></td><td class="text-center"><?= number_format($r['boys']) ?></td><td class="text-center"><?= number_format($r['girls']) ?></td></tr><?php endforeach; ?><?php if(empty($year_report)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data</td></tr><?php endif; ?></tbody></table></div></div></div>
    </div>
    <?php endif; ?>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));
<?php if(!$view_graduated): ?>
new Chart(document.getElementById('gradeChart'),{type:'bar',data:{labels:<?= json_encode($grade_labels) ?>,datasets:[{label:'Boys',data:<?= json_encode($grade_boys) ?>,backgroundColor:'#4f46e5',borderRadius:6},{label:'Girls',data:<?= json_encode($grade_girls) ?>,backgroundColor:'#ec4899',borderRadius:6}]},options:{responsive:true,scales:{y:{beginAtZero:true,grid:{color:getComputedStyle(document.documentElement).getPropertyValue('--border')}},x:{grid:{display:false}}}}});
new Chart(document.getElementById('strandChart'),{type:'doughnut',data:{labels:<?= json_encode($strand_labels) ?>,datasets:[{data:<?= json_encode($strand_totals) ?>,backgroundColor:['#4f46e5','#7c3aed','#6366f1','#818cf8','#a5b4fc','#c7d2fe','#8b5cf6','#6d28d9'],borderWidth:0}]},options:{responsive:true,plugins:{legend:{position:'bottom',labels:{padding:15,usePointStyle:true}}}}});
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:<?= json_encode($year_labels) ?>,datasets:[{label:'Students',data:<?= json_encode($year_totals) ?>,borderColor:'#4f46e5',backgroundColor:'rgba(79,70,229,0.1)',fill:true,tension:0.4,pointRadius:5,pointBackgroundColor:'#4f46e5'}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,grid:{color:getComputedStyle(document.documentElement).getPropertyValue('--border')}},x:{grid:{display:false}}}}});
<?php endif; ?>
</script>
</body>
</html>