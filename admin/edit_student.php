<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

$student_id = (int)($_GET['id'] ?? 0);
if ($student_id <= 0) { die("Invalid student ID"); }

// Fetch complete student data
$stmt = $conn->prepare("SELECT s.*, p.father_name, p.father_occupation, p.father_contact, p.mother_maiden_name, p.mother_occupation, p.mother_contact, p.ave_family_income, p.guardian_fullname, p.guardian_relation, p.guardian_contact, p.household_id as parent_household_id, a.purok_street, a.barangay, a.town_city, a.province, a.region, a.district, a.postal_code, e.grade_level, e.track, e.strand, e.program, e.section, e.school_year, e.term, e.voucher_status, e.household_id, COALESCE(e.status, 'Active') AS status FROM students_info s LEFT JOIN parents_info p ON s.student_id = p.student_id LEFT JOIN addresses a ON s.student_id = a.student_id LEFT JOIN enrollment_form e ON s.student_id = e.student_id WHERE s.student_id = ? ORDER BY e.enrollment_id DESC LIMIT 1");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc() ?: [];
$stmt->close();
if (empty($student)) die("Student not found.");

$edu_stmt = $conn->prepare("SELECT * FROM educational_history WHERE student_id = ? ORDER BY CASE level WHEN 'Elementary' THEN 1 WHEN 'JHS' THEN 2 ELSE 3 END, year_completed DESC");
$edu_stmt->bind_param("i", $student_id);
$edu_stmt->execute();
$education_rows = $edu_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$edu_stmt->close();

$edu_data = [];
foreach ($education_rows as $edu) { $edu_data[$edu['level']] = $edu; }

$religion_options = [
    'Roman Catholic',
    'Islam',
    'Iglesia ni Cristo',
    'Protestant',
    'Born Again Christian',
    'Seventh-Day Adventist',
    "Jehovah's Witnesses",
    'Bible Baptist',
    'Mormon (LDS)',
    'Buddhism',
    'Hinduism',
    'Atheist',
];

$errors = [];
$old = $student;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old = array_merge($old, $_POST);
    if (isset($_POST['school_name']) && is_array($_POST['school_name'])) {
        foreach ($_POST['school_name'] as $level => $value) {
            $old['school_name'][$level] = trim($value ?? '');
            $old['school_address'][$level] = trim($_POST['school_address'][$level] ?? '');
            $old['year_completed'][$level] = trim($_POST['year_completed'][$level] ?? '');
        }
    }

    $conn->begin_transaction();
    try {
        $lrn = trim($_POST['lrn'] ?? '');
        $first_name = trim($_POST['first_name'] ?? '');
        $middle_name = trim($_POST['middle_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $nick_name = trim($_POST['nick_name'] ?? '');
        $ext_name = trim($_POST['ext_name'] ?? '');
        $birth_date = $_POST['birth_date'] ?? null;
        $sex = $_POST['sex'] ?? '';
        $civil_status = trim($_POST['civil_status'] ?? '');
        $nationality = trim($_POST['nationality'] ?? 'Filipino');
        $religion = trim($_POST['religion'] ?? '');
        $height = !empty($_POST['height']) ? (float)$_POST['height'] : null;
        $weight = !empty($_POST['weight']) ? (float)$_POST['weight'] : null;
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $special_skills = trim($_POST['special_skills'] ?? '');

        $father_name = trim($_POST['father_name'] ?? '');
        $father_occupation = trim($_POST['father_occupation'] ?? '');
        $father_contact = trim($_POST['father_contact'] ?? '');
        $mother_maiden_name = trim($_POST['mother_maiden_name'] ?? '');
        $mother_occupation = trim($_POST['mother_occupation'] ?? '');
        $mother_contact = trim($_POST['mother_contact'] ?? '');
        $ave_family_income = !empty($_POST['ave_family_income']) ? (float)$_POST['ave_family_income'] : null;
        $guardian_fullname = trim($_POST['guardian_fullname'] ?? '');
        $guardian_relation = trim($_POST['guardian_relation'] ?? '');
        $guardian_contact = trim($_POST['guardian_contact'] ?? '');

        $purok_street = trim($_POST['purok_street'] ?? '');
        $barangay = trim($_POST['barangay'] ?? '');
        $town_city = trim($_POST['town_city'] ?? '');
        $province = trim($_POST['province'] ?? '');
        $region = trim($_POST['region'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $postal_code = trim($_POST['postal_code'] ?? '');

        $grade_level = trim($_POST['grade_level'] ?? '');
        $track = trim($_POST['track'] ?? 'TECHPRO ELECTIVES');
        $strand = trim($_POST['strand'] ?? '');
        $program = trim($_POST['program'] ?? '');
        $section = trim($_POST['section'] ?? '');
        $school_year = trim($_POST['school_year'] ?? '');
        $term = trim($_POST['term'] ?? '');
        $student_status = trim($_POST['status'] ?? 'Active');
        $voucher_status = $_POST['voucher_status'] ?? null;
        $household_id = trim($_POST['household_id'] ?? '');

        $age = null;
        if (!empty($birth_date)) {
            $date_obj = DateTime::createFromFormat('Y-m-d', $birth_date);
            if (!$date_obj || $date_obj->format('Y-m-d') !== $birth_date) throw new Exception("Invalid birth date.");
            if ($date_obj > new DateTime()) throw new Exception("Birth date cannot be in the future.");
            $age = (new DateTime())->diff($date_obj)->y;
        }

        if (empty($first_name)) $errors['first_name'] = "First name is required";
        if (empty($last_name)) $errors['last_name'] = "Last name is required";
        if (empty($birth_date)) $errors['birth_date'] = "Birth date is required";
        if (empty($sex)) $errors['sex'] = "Sex is required";
        if (!empty($errors)) throw new Exception("Please correct the errors below.");

        // Update students_info
        // FIXED: type string correctly matches values
        //   position 12 = 's' for religion
        //   position 14 = 'd' for weight
        $stmt = $conn->prepare("UPDATE students_info SET lrn=?,first_name=?,middle_name=?,last_name=?,nick_name=?,ext_name=?,sex=?,birth_date=?,age=?,civil_status=?,nationality=?,religion=?,height=?,weight=?,email=?,phone=?,special_skills=? WHERE student_id=?");
        $stmt->bind_param("ssssssssisssddsssi",
            $lrn,
            $first_name,
            $middle_name,
            $last_name,
            $nick_name,
            $ext_name,
            $sex,
            $birth_date,
            $age,
            $civil_status,
            $nationality,
            $religion,
            $height,
            $weight,
            $email,
            $phone,
            $special_skills,
            $student_id
        );
        $stmt->execute(); if ($stmt->error) throw new Exception("Error: " . $stmt->error);

        // Parents upsert
        $stmt = $conn->prepare("SELECT COUNT(*) FROM parents_info WHERE student_id=?");
        $stmt->bind_param("i",$student_id);
        $stmt->execute();
        $stmt->bind_result($pe);
        $stmt->fetch();
        $stmt->close();

        if ($pe) {
            $stmt = $conn->prepare("UPDATE parents_info SET father_name=?,father_occupation=?,father_contact=?,mother_maiden_name=?,mother_occupation=?,mother_contact=?,ave_family_income=?,guardian_fullname=?,guardian_relation=?,guardian_contact=?,household_id=? WHERE student_id=?");
            $stmt->bind_param("ssssssdssssi",
                $father_name,
                $father_occupation,
                $father_contact,
                $mother_maiden_name,
                $mother_occupation,
                $mother_contact,
                $ave_family_income,
                $guardian_fullname,
                $guardian_relation,
                $guardian_contact,
                $household_id,
                $student_id
            );
        } else {
            $stmt = $conn->prepare("INSERT INTO parents_info (student_id,father_name,father_occupation,father_contact,mother_maiden_name,mother_occupation,mother_contact,ave_family_income,guardian_fullname,guardian_relation,guardian_contact,household_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param("issssssdssss",
                $student_id,
                $father_name,
                $father_occupation,
                $father_contact,
                $mother_maiden_name,
                $mother_occupation,
                $mother_contact,
                $ave_family_income,
                $guardian_fullname,
                $guardian_relation,
                $guardian_contact,
                $household_id
            );
        }
        $stmt->execute();
        if ($stmt->error) throw new Exception("Parents error: ".$stmt->error);
        $stmt->close();

        // Address upsert
        $stmt=$conn->prepare("SELECT COUNT(*) FROM addresses WHERE student_id=?"); $stmt->bind_param("i",$student_id); $stmt->execute(); $stmt->bind_result($ae); $stmt->fetch(); $stmt->close();
        if($ae){$stmt=$conn->prepare("UPDATE addresses SET purok_street=?,barangay=?,town_city=?,province=?,region=?,district=?,postal_code=? WHERE student_id=?"); $stmt->bind_param("sssssssi",$purok_street,$barangay,$town_city,$province,$region,$district,$postal_code,$student_id);}
        else{$stmt=$conn->prepare("INSERT INTO addresses (student_id,purok_street,barangay,town_city,province,region,district,postal_code) VALUES (?,?,?,?,?,?,?,?)"); $stmt->bind_param("isssssss",$student_id,$purok_street,$barangay,$town_city,$province,$region,$district,$postal_code);}
        $stmt->execute(); if ($stmt->error) throw new Exception("Address error: ".$stmt->error);

        // Enrollment upsert
        $stmt=$conn->prepare("SELECT COUNT(*) FROM enrollment_form WHERE student_id=?"); $stmt->bind_param("i",$student_id); $stmt->execute(); $stmt->bind_result($ee); $stmt->fetch(); $stmt->close();
        if($ee){$stmt=$conn->prepare("UPDATE enrollment_form SET grade_level=?,track=?,strand=?,program=?,section=?,school_year=?,term=?,status=?,voucher_status=?,household_id=? WHERE student_id=?"); $stmt->bind_param("ssssssssssi",$grade_level,$track,$strand,$program,$section,$school_year,$term,$student_status,$voucher_status,$household_id,$student_id);}
        elseif(!empty($grade_level)||!empty($school_year)){$stmt=$conn->prepare("INSERT INTO enrollment_form (student_id,grade_level,track,strand,program,section,school_year,term,status,voucher_status,household_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)"); $stmt->bind_param("issssssssss",$student_id,$grade_level,$track,$strand,$program,$section,$school_year,$term,$student_status,$voucher_status,$household_id);}
        if(isset($stmt)){$stmt->execute(); if($stmt->error)throw new Exception("Enrollment error: ".$stmt->error);}

        // Educational history
        $existing_levels = array_keys($edu_data);
        $submitted_levels = isset($_POST['school_name']) ? array_keys($_POST['school_name']) : [];
        foreach($existing_levels as $lvl){if(!in_array($lvl,$submitted_levels)){$d=$conn->prepare("DELETE FROM educational_history WHERE student_id=? AND level=?"); $d->bind_param("is",$student_id,$lvl); $d->execute(); $d->close();}}
        if(isset($_POST['school_name'])&&is_array($_POST['school_name'])){
            foreach($_POST['school_name'] as $lvl=>$sn){$lvl=trim($lvl);$sn=trim($sn??'');$sa=trim($_POST['school_address'][$lvl]??'');$yc=trim($_POST['year_completed'][$lvl]??'');
                if(empty($sn)&&empty($sa)&&empty($yc)){$d=$conn->prepare("DELETE FROM educational_history WHERE student_id=? AND level=?"); $d->bind_param("is",$student_id,$lvl); $d->execute(); $d->close(); continue;}
                $c=$conn->prepare("SELECT COUNT(*) FROM educational_history WHERE student_id=? AND level=?"); $c->bind_param("is",$student_id,$lvl); $c->execute(); $c->bind_result($ex); $c->fetch(); $c->close();
                if($ex){$s=$conn->prepare("UPDATE educational_history SET school_name=?,school_address=?,year_completed=? WHERE student_id=? AND level=?"); $s->bind_param("sssis",$sn,$sa,$yc,$student_id,$lvl);}
                else{$s=$conn->prepare("INSERT INTO educational_history (student_id,level,school_name,school_address,year_completed) VALUES (?,?,?,?,?)"); $s->bind_param("issss",$student_id,$lvl,$sn,$sa,$yc);}
                $s->execute(); if($s->error)throw new Exception("Education error: ".$s->error);
            }
        }

        // ---- Audit the student_info update with a diff ----
        audit_diff($conn, 'student.update', [
            'lrn'            => $student['lrn']            ?? null,
            'first_name'     => $student['first_name']     ?? null,
            'middle_name'    => $student['middle_name']    ?? null,
            'last_name'      => $student['last_name']      ?? null,
            'nick_name'      => $student['nick_name']      ?? null,
            'ext_name'       => $student['ext_name']       ?? null,
            'sex'            => $student['sex']            ?? null,
            'birth_date'     => $student['birth_date']     ?? null,
            'age'            => $student['age']            ?? null,
            'civil_status'   => $student['civil_status']   ?? null,
            'nationality'    => $student['nationality']    ?? null,
            'religion'       => $student['religion']       ?? null,
            'height'         => $student['height']         ?? null,
            'weight'         => $student['weight']         ?? null,
            'email'          => $student['email']          ?? null,
            'phone'          => $student['phone']          ?? null,
            'special_skills' => $student['special_skills'] ?? null,
        ], [
            'lrn'            => $lrn,
            'first_name'     => $first_name,
            'middle_name'    => $middle_name ?: null,
            'last_name'      => $last_name,
            'nick_name'      => $nick_name ?: null,
            'ext_name'       => $ext_name ?: null,
            'sex'            => $sex,
            'birth_date'     => $birth_date,
            'age'            => $age,
            'civil_status'   => $civil_status ?: null,
            'nationality'    => $nationality,
            'religion'       => $religion ?: null,
            'height'         => $height,
            'weight'         => $weight,
            'email'          => $email ?: null,
            'phone'          => $phone ?: null,
            'special_skills' => $special_skills ?: null,
        ], 'student', $student_id);

        $conn->commit();
        $_SESSION['success'] = "Student record updated!";
        header("Location: view_student.php?id=$student_id"); exit();
    } catch (Exception $e) { $conn->rollback(); $errors['general'] = $e->getMessage(); }
}

$status_options = ['Active', 'Transferred', 'Stopped', 'Dropped'];
$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';

$current_religion = trim((string)($old['religion'] ?? ''));
$religion_is_known = in_array($current_religion, $religion_options, true);
$religion_select_value = $religion_is_known ? $current_religion : ($current_religion !== '' ? '__other__' : '');
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Student • USAT Admin</title>
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

        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}
        .card-header h3{margin:0;font-size:0.95rem;font-weight:600}
        .card-header i{color:var(--accent);font-size:1.2rem}
        .card-body{padding:1.5rem}

        .form-label{font-size:0.8rem;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-bottom:0.35rem}
        .form-label.required::after{content:" *";color:var(--red)}
        .form-control,.form-select{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:0.65rem 1rem;font-size:0.9rem;color:var(--text);transition:all 0.2s}
        .form-control:focus,.form-select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,70,229,0.15);outline:none}

        .edu-block{background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:1rem}

        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}
        .btn{font-weight:500;border-radius:8px}

        .badge-pill{display:inline-flex;align-items:center;gap:0.3rem;padding:0.25rem 0.7rem;border-radius:50px;font-size:0.72rem;font-weight:600}
        .badge-pill.success{background:#d1fae5;color:#065f46}.badge-pill.warning{background:#fef3c7;color:#92400e}.badge-pill.danger{background:#fee2e2;color:#991b1b}.badge-pill.info{background:#dbeafe;color:#1e40af}
        [data-bs-theme="dark"] .badge-pill.success{background:#064e3b;color:#6ee7b7}[data-bs-theme="dark"] .badge-pill.warning{background:#78350f;color:#fcd34d}[data-bs-theme="dark"] .badge-pill.danger{background:#7f1d1d;color:#fca5a5}[data-bs-theme="dark"] .badge-pill.info{background:#1e3a5f;color:#93c5fd}

        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}
        @media(max-width:640px){.main-content{padding:1rem}}
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><img src="../assets/img/usat.jpg" alt="USAT"><span>USAT Admin</span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></div>
</aside>

<div class="main-content" id="mainContent">
    <?php if(!empty($errors['general'])): ?><div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= htmlspecialchars($errors['general']) ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div><h2 style="font-size:1.4rem;font-weight:700;margin:0">Edit Student</h2><p class="text-muted small mb-0"><?= htmlspecialchars($student['first_name'].' '.$student['last_name']) ?> · <span class="badge-pill <?= strtolower($student['status']??'Active')=='active'?'success':(strtolower($student['status']??'')=='dropped'?'danger':'warning') ?>"><?= $student['status']??'Active' ?></span></p></div>
        </div>
        <div class="d-flex gap-2">
            <a href="view_student.php?id=<?= $student_id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
            <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
        </div>
    </div>

    <form method="POST" id="editForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- Basic Info -->
        <div class="card">
            <div class="card-header"><i class="bi bi-person-vcard"></i><h3>Basic Information</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">LRN</label><input type="text" name="lrn" class="form-control" value="<?= htmlspecialchars($old['lrn']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label required">First Name</label><input type="text" name="first_name" class="form-control <?= isset($errors['first_name'])?'is-invalid':'' ?>" value="<?= htmlspecialchars($old['first_name']??'') ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Middle Name</label><input type="text" name="middle_name" class="form-control" value="<?= htmlspecialchars($old['middle_name']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label required">Last Name</label><input type="text" name="last_name" class="form-control <?= isset($errors['last_name'])?'is-invalid':'' ?>" value="<?= htmlspecialchars($old['last_name']??'') ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Nickname</label><input type="text" name="nick_name" class="form-control" value="<?= htmlspecialchars($old['nick_name']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label">Extension</label><input type="text" name="ext_name" class="form-control" value="<?= htmlspecialchars($old['ext_name']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label required">Birth Date</label><input type="date" name="birth_date" id="birth_date" class="form-control <?= isset($errors['birth_date'])?'is-invalid':'' ?>" value="<?= htmlspecialchars($old['birth_date']??'') ?>" required></div>
                    <div class="col-md-3"><label class="form-label">Age</label><input type="number" id="age" class="form-control bg-light" readonly value="<?= htmlspecialchars($old['age']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label required">Sex</label><select name="sex" class="form-select" required><option value="">Select</option><option value="Male" <?= ($old['sex']??'')=='Male'?'selected':'' ?>>Male</option><option value="Female" <?= ($old['sex']??'')=='Female'?'selected':'' ?>>Female</option></select></div>
                    <div class="col-md-4"><label class="form-label">Civil Status</label><select name="civil_status" class="form-select"><option value="">Select</option><option value="Single" <?= ($old['civil_status']??'')=='Single'?'selected':'' ?>>Single</option><option value="Married" <?= ($old['civil_status']??'')=='Married'?'selected':'' ?>>Married</option></select></div>
                    <div class="col-md-4"><label class="form-label">Nationality</label><input type="text" name="nationality" class="form-control" value="<?= htmlspecialchars($old['nationality']??'Filipino') ?>"></div>

                    <!-- Religion — real dropdown + "Other" text input -->
                    <div class="col-md-4">
                        <label class="form-label">Religion</label>
                        <select id="religion_select" class="form-select" onchange="syncReligion()">
                            <option value="">— Select Religion —</option>
                            <?php foreach ($religion_options as $r): ?>
                                <option value="<?= htmlspecialchars($r) ?>" <?= $religion_select_value === $r ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r) ?>
                                </option>
                            <?php endforeach; ?>
                            <option value="__other__" <?= $religion_select_value === '__other__' ? 'selected' : '' ?>>Other (specify)</option>
                        </select>
                        <input type="text"
                               id="religion_other"
                               class="form-control mt-2"
                               placeholder="Enter religion"
                               style="<?= $religion_select_value === '__other__' ? '' : 'display:none;' ?>"
                               value="<?= $religion_select_value === '__other__' ? htmlspecialchars($current_religion) : '' ?>">
                        <input type="hidden" name="religion" id="religion_hidden" value="<?= htmlspecialchars($current_religion) ?>">
                    </div>

                    <div class="col-md-2"><label class="form-label">Height (cm)</label><input type="number" step="0.01" name="height" class="form-control" value="<?= htmlspecialchars($old['height']??'') ?>"></div>
                    <div class="col-md-2"><label class="form-label">Weight (kg)</label><input type="number" step="0.01" name="weight" class="form-control" value="<?= htmlspecialchars($old['weight']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= htmlspecialchars($old['email']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Phone</label><input type="tel" name="phone" class="form-control" value="<?= htmlspecialchars($old['phone']??'') ?>"></div>
                    <div class="col-12"><label class="form-label">Special Skills</label><textarea name="special_skills" class="form-control" rows="2"><?= htmlspecialchars($old['special_skills']??'') ?></textarea></div>
                </div>
            </div>
        </div>

        <!-- Parents -->
        <div class="card">
            <div class="card-header"><i class="bi bi-people"></i><h3>Parents & Guardian</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4"><label class="form-label">Father Name</label><input type="text" name="father_name" class="form-control" value="<?= htmlspecialchars($old['father_name']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Father Occupation</label><input type="text" name="father_occupation" class="form-control" value="<?= htmlspecialchars($old['father_occupation']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Father Contact</label><input type="tel" name="father_contact" class="form-control" value="<?= htmlspecialchars($old['father_contact']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Mother (Maiden)</label><input type="text" name="mother_maiden_name" class="form-control" value="<?= htmlspecialchars($old['mother_maiden_name']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Mother Occupation</label><input type="text" name="mother_occupation" class="form-control" value="<?= htmlspecialchars($old['mother_occupation']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Mother Contact</label><input type="tel" name="mother_contact" class="form-control" value="<?= htmlspecialchars($old['mother_contact']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Family Income (₱)</label><input type="number" step="0.01" name="ave_family_income" class="form-control" value="<?= htmlspecialchars($old['ave_family_income']??'') ?>"></div>
                    <div class="col-12"><hr><h6 class="fw-bold mb-3"><i class="bi bi-shield-check me-2"></i>Guardian</h6></div>
                    <div class="col-md-4"><label class="form-label">Guardian Name</label><input type="text" name="guardian_fullname" class="form-control" value="<?= htmlspecialchars($old['guardian_fullname']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Relationship</label><input type="text" name="guardian_relation" class="form-control" value="<?= htmlspecialchars($old['guardian_relation']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Guardian Contact</label><input type="tel" name="guardian_contact" class="form-control" value="<?= htmlspecialchars($old['guardian_contact']??'') ?>"></div>
                </div>
            </div>
        </div>

        <!-- Address -->
        <div class="card">
            <div class="card-header"><i class="bi bi-geo-alt"></i><h3>Address</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Purok/Street</label><input type="text" name="purok_street" class="form-control" value="<?= htmlspecialchars($old['purok_street']??'') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Barangay</label><input type="text" name="barangay" class="form-control" value="<?= htmlspecialchars($old['barangay']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Town/City</label><input type="text" name="town_city" class="form-control" value="<?= htmlspecialchars($old['town_city']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Province</label><input type="text" name="province" class="form-control" value="<?= htmlspecialchars($old['province']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Region</label><input type="text" name="region" class="form-control" value="<?= htmlspecialchars($old['region']??'') ?>"></div>
                    <div class="col-md-6"><label class="form-label">District</label><input type="text" name="district" class="form-control" value="<?= htmlspecialchars($old['district']??'') ?>"></div>
                    <div class="col-md-6"><label class="form-label">Postal Code</label><input type="text" name="postal_code" class="form-control" value="<?= htmlspecialchars($old['postal_code']??'') ?>"></div>
                </div>
            </div>
        </div>

        <!-- Enrollment -->
        <div class="card">
            <div class="card-header"><i class="bi bi-mortarboard"></i><h3>Enrollment & Status</h3></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><label class="form-label">Grade Level</label><input type="text" name="grade_level" class="form-control" value="<?= htmlspecialchars($old['grade_level']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label">School Year</label><input type="text" name="school_year" class="form-control" value="<?= htmlspecialchars($old['school_year']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label">Section</label><input type="text" name="section" class="form-control" value="<?= htmlspecialchars($old['section']??'') ?>"></div>
                    <div class="col-md-3"><label class="form-label">Term</label><input type="text" name="term" class="form-control" value="<?= htmlspecialchars($old['term']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label">Track</label><input type="text" name="track" class="form-control bg-light" value="<?= htmlspecialchars($old['track']??'TECHPRO ELECTIVES') ?>" readonly></div>
                    <div class="col-md-4"><label class="form-label">Strand</label><select name="strand" id="strand" class="form-select"><option value="">Select</option></select></div>
                    <div class="col-md-4"><label class="form-label">Program</label><select name="program" id="program" class="form-select"><option value="">Select</option></select></div>
                    <div class="col-md-4"><label class="form-label">Voucher</label><select name="voucher_status" class="form-select"><option value="">Select</option><option value="Qualified" <?= ($old['voucher_status']??'')=='Qualified'?'selected':'' ?>>Qualified</option><option value="Not Qualified" <?= ($old['voucher_status']??'')=='Not Qualified'?'selected':'' ?>>Not Qualified</option></select></div>
                    <div class="col-md-4"><label class="form-label">Household ID</label><input type="text" name="household_id" class="form-control" value="<?= htmlspecialchars($old['household_id']??'') ?>"></div>
                    <div class="col-md-4"><label class="form-label required">Status</label><select name="status" class="form-select" required><?php foreach($status_options as $st): ?><option value="<?= $st ?>" <?= ($old['status']??'Active')==$st?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select></div>
                </div>
            </div>
        </div>

        <!-- Education -->
        <div class="card">
            <div class="card-header"><i class="bi bi-book"></i><h3>Educational History</h3><button type="button" class="btn btn-primary btn-sm" id="addLevelBtn"><i class="bi bi-plus-lg me-1"></i> Add Level</button></div>
            <div class="card-body" id="eduContainer">
                <?php $display_levels = $edu_data;
                if($_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['school_name'])){foreach(array_keys($_POST['school_name']) as $l){if(!isset($display_levels[$l]))$display_levels[$l]=['level'=>$l];}}
                foreach($display_levels as $lvl=>$edu): ?>
                <div class="edu-block" data-level="<?= htmlspecialchars($lvl) ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3"><h6 class="fw-bold mb-0" style="color:var(--accent)"><?= htmlspecialchars($lvl) ?></h6><button type="button" class="btn btn-outline-danger btn-xs remove-level-btn"><i class="bi bi-trash3"></i></button></div>
                    <div class="row g-3">
                        <div class="col-md-5"><label class="form-label">School Name</label><input type="text" name="school_name[<?= htmlspecialchars($lvl) ?>]" class="form-control" value="<?= htmlspecialchars($old['school_name'][$lvl]??$edu['school_name']??'') ?>"></div>
                        <div class="col-md-5"><label class="form-label">Address</label><input type="text" name="school_address[<?= htmlspecialchars($lvl) ?>]" class="form-control" value="<?= htmlspecialchars($old['school_address'][$lvl]??$edu['school_address']??'') ?>"></div>
                        <div class="col-md-2"><label class="form-label">Year</label><input type="text" name="year_completed[<?= htmlspecialchars($lvl) ?>]" class="form-control" value="<?= htmlspecialchars($old['year_completed'][$lvl]??$edu['year_completed']??'') ?>"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-3 mt-4">
            <a href="view_student.php?id=<?= $student_id ?>" class="btn btn-outline-secondary btn-lg rounded-pill px-4">Cancel</a>
            <button type="submit" class="btn btn-primary btn-lg rounded-pill px-5" id="submitBtn"><i class="bi bi-check-lg me-2"></i> Save Changes</button>
        </div>
    </form>
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

// Religion select <-> text input sync
function syncReligion() {
    const sel = document.getElementById('religion_select');
    const inp = document.getElementById('religion_other');
    const hid = document.getElementById('religion_hidden');

    if (sel.value === '__other__') {
        inp.style.display = 'block';
        inp.focus();
        hid.value = inp.value.trim();
    } else {
        inp.style.display = 'none';
        inp.value = '';
        hid.value = sel.value;
    }
}

document.getElementById('religion_other').addEventListener('input', function () {
    document.getElementById('religion_hidden').value = this.value.trim();
});

// Age calc
document.getElementById('birth_date').addEventListener('change',function(){const b=new Date(this.value),t=new Date();let a=t.getFullYear()-b.getFullYear();if(t.getMonth()<b.getMonth()||(t.getMonth()===b.getMonth()&&t.getDate()<b.getDate()))a--;document.getElementById('age').value=a>=0?a:''});

// Strand/Program
const strands={"Automotive and Small Engine Technologies":["Driving and Automotive Servicing","Automotive Servicing (Electrical Repair)","Automotive Servicing (Engine and Chassis Repairs)"],"Construction and Building Technologies":["Carpentry","Manual Metal Arc Welding"],"ICT Support and Computer Programming Technologies":["Computer Programming (Java)","Computer Programming (.NET)","Computer System Servicing"],"Industrial Technologies":["Electronics Product Assembly and Servicing"],"Agri-Fishery Business and Food Innovation":["Agricultural Crops Production"],"Hospitality and Tourism":["Food and Beverage Operation","Hotel Operation (Housekeeping)"]};
const ss=document.getElementById('strand'),ps=document.getElementById('program');
Object.keys(strands).forEach(s=>{const o=document.createElement('option');o.value=s;o.textContent=s;ss.appendChild(o)});
ss.addEventListener('change',function(){ps.innerHTML='<option value="">Select</option>';if(strands[this.value])strands[this.value].forEach(p=>{const o=document.createElement('option');o.value=p;o.textContent=p;ps.appendChild(o)})});
const savedS="<?= addslashes($old['strand']??$student['strand']??'') ?>",savedP="<?= addslashes($old['program']??$student['program']??'') ?>";
if(savedS&&strands[savedS]){ss.value=savedS;ss.dispatchEvent(new Event('change'));setTimeout(()=>{if(savedP)ps.value=savedP},50)}

// Add level
document.getElementById('addLevelBtn').addEventListener('click',()=>{const l=prompt('Level name (e.g. Senior High School):');if(!l)return;if(document.querySelector(`.edu-block[data-level="${l}"]`)){alert('Level exists!');return}
const t=document.createElement('div');t.className='edu-block';t.setAttribute('data-level',l);t.innerHTML=`<div class="d-flex justify-content-between align-items-center mb-3"><h6 class="fw-bold mb-0" style="color:var(--accent)">${l}</h6><button type="button" class="btn btn-outline-danger btn-xs remove-level-btn"><i class="bi bi-trash3"></i></button></div><div class="row g-3"><div class="col-md-5"><label class="form-label">School Name</label><input type="text" name="school_name[${l}]" class="form-control"></div><div class="col-md-5"><label class="form-label">Address</label><input type="text" name="school_address[${l}]" class="form-control"></div><div class="col-md-2"><label class="form-label">Year</label><input type="text" name="year_completed[${l}]" class="form-control"></div></div>`;document.getElementById('eduContainer').appendChild(t);t.querySelector('.remove-level-btn').addEventListener('click',function(){this.closest('.edu-block').remove()})});
document.querySelectorAll('.remove-level-btn').forEach(b=>b.addEventListener('click',function(){this.closest('.edu-block').remove()}));
document.getElementById('editForm').addEventListener('submit',function(){const b=document.getElementById('submitBtn');b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving...'});
</script>
</body>
</html>