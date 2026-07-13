<?php
session_start();
include __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Check if exporting to Word
$export_word = isset($_GET['export_word']) && $_GET['export_word'] == 1 && isset($_GET['id']);

if ($export_word) {
    $student_id = (int)$_GET['id'];
    
    // OPTIMIZED: Single query for Word export
    $stmt = $conn->prepare("
        SELECT s.*, 
               p.father_name, p.father_occupation, p.father_contact,
               p.mother_maiden_name, p.mother_occupation, p.mother_contact,
               p.guardian_fullname, p.guardian_relation, p.guardian_contact,
               p.ave_family_income, p.is_4ps,
               a.purok_street, a.barangay, a.town_city, a.province, a.region, a.district, a.postal_code,
               e.school_year, e.grade_level, e.track, e.strand, e.program, e.section, e.semester,
               e.status, e.voucher_status, e.household_id
        FROM students_info s
        LEFT JOIN parents_info p ON s.student_id = p.student_id
        LEFT JOIN addresses a ON s.student_id = a.student_id
        LEFT JOIN enrollment_form e ON s.student_id = e.student_id
        WHERE s.student_id = ?
        ORDER BY e.enrollment_id DESC LIMIT 1
    ");
    $stmt->bind_param("i", $student_id); $stmt->execute(); 
    $student = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$student) die("Student not found.");
    
    // Extract data from single result
    $enrollment = !empty($student['school_year']) ? $student : [];
    $parents = $student;
    $address = $student;
    
    $edu_stmt = $conn->prepare("SELECT level, school_name, school_address, year_completed FROM educational_history WHERE student_id = ? ORDER BY level");
    $edu_stmt->bind_param("i", $student_id); $edu_stmt->execute(); $education = $edu_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $edu_stmt->close();
    
    $doc_stmt = $conn->prepare("SELECT document_name, submitted, file_path FROM entrance_documents WHERE student_id = ?");
    $doc_stmt->bind_param("i", $student_id); $doc_stmt->execute(); $documents = $doc_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $doc_stmt->close();
    
    $grades_stmt = $conn->prepare("SELECT sg.semester, sg.quarter, sg.subject_code, sg.subject_name, sg.grade, sg.remarks, e2.school_year, e2.grade_level FROM student_grades sg LEFT JOIN enrollment_form e2 ON sg.enrollment_id = e2.enrollment_id WHERE sg.student_id = ? ORDER BY e2.school_year DESC, e2.grade_level ASC, sg.semester ASC, sg.quarter ASC");
    $grades_stmt->bind_param("i", $student_id); $grades_stmt->execute(); $grades = $grades_stmt->get_result()->fetch_all(MYSQLI_ASSOC); $grades_stmt->close();

    header("Content-Type: application/msword");
    header("Content-Disposition: attachment; filename=student_" . ($student['lrn'] ?? 'profile') . "_" . date('Y-m-d') . ".doc");
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Student Profile</title><style>body{font-family:Arial;margin:2cm}h1{color:#1e3c72;text-align:center}h2{color:#2b4c8c;margin-top:20px;border-bottom:2px solid #2b4c8c}table{width:100%;border-collapse:collapse;margin:15px 0}th,td{border:1px solid #ddd;padding:8px;text-align:left}th{background:#f2f2f2;width:30%}</style></head><body>';
    echo '<h1>USAT College - Student Information</h1><p>Generated: '.date('F d, Y h:i A').'</p>';
    echo '<h2>Personal Info</h2><table>';
    $f=['Student ID Number'=>$student['student_id_number']??'—','LRN'=>$student['lrn']??'—','Name'=>($student['last_name']??'').', '.($student['first_name']??'').' '.($student['middle_name']??''),'Sex'=>$student['sex']??'—','Birth Date'=>$student['birth_date']??'—','Age'=>$student['age']??'—','Civil Status'=>$student['civil_status']??'—','Nationality'=>$student['nationality']??'—','Religion'=>$student['religion']??'—','Email'=>$student['email']??'—','Phone'=>$student['phone']??'—'];
    foreach($f as $l=>$v) echo "<tr><th>$l</th><td>".htmlspecialchars((string)$v)."</td></tr>";
    echo '</table>';
    if(!empty($enrollment)){echo '<h2>Enrollment</h2><table>';foreach(['school_year'=>'School Year','grade_level'=>'Grade Level','semester'=>'Semester','section'=>'Section','track'=>'Track','strand'=>'Strand','program'=>'Program','status'=>'Status','voucher_status'=>'Voucher'] as $k=>$l)echo "<tr><th>$l</th><td>".htmlspecialchars($enrollment[$k]??'—')."</td></tr>";echo '</table>';}
    if(!empty($grades)){echo '<h2>Academic Record</h2>';$t=0;$c=0;foreach($grades as $g){echo '<p><strong>'.htmlspecialchars(($g['school_year']??'—').' - '.($g['grade_level']??'—').' - '.($g['semester']??'').' '.($g['quarter']?' - '.$g['quarter']:'')).'</strong>: '.htmlspecialchars($g['subject_code']).' '.htmlspecialchars($g['subject_name']).' - '.($g['grade']!==null?number_format($g['grade'],2):'—').'</p>';if($g['grade']!==null&&is_numeric($g['grade'])){$t+=(float)$g['grade'];$c++;}}echo '<p><strong>Overall GWA: '.($c>0?number_format($t/$c,2):'N/A').'</strong></p>';}
    echo '</body></html>';
    exit;
}

// ====================== HANDLERS (unchanged) ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_document'])) {
    $eid=(int)$_POST['entrance_id']; $sid=(int)$_POST['student_id'];
    if(isset($_FILES['document_file'])&&$_FILES['document_file']['error']===0){
        $ext=strtolower(pathinfo($_FILES['document_file']['name'],PATHINFO_EXTENSION));
        if(in_array($ext,['pdf','jpg','jpeg','png','doc','docx'])){
            $dir=__DIR__."/../uploads/documents/"; if(!is_dir($dir))mkdir($dir,0755,true);
            $fn="doc_{$sid}_{$eid}_".time().".$ext"; $tp=$dir.$fn; $dp="uploads/documents/".$fn;
            if(move_uploaded_file($_FILES['document_file']['tmp_name'],$tp)){$s=$conn->prepare("UPDATE entrance_documents SET submitted=1,file_path=?,uploaded_by=?,uploaded_at=NOW() WHERE entrance_id=? AND student_id=?"); $s->bind_param("siii",$dp,$_SESSION['admin_id'],$eid,$sid); $s->execute()?$_SESSION['success']="Uploaded!":$_SESSION['error']="DB error."; $s->close();}else $_SESSION['error']="File save failed.";
        }else $_SESSION['error']="Invalid file type.";
    }else $_SESSION['error']="No file.";
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_document'])) {
    $eid=(int)$_POST['entrance_id']; $sid=(int)$_POST['student_id'];
    $s=$conn->prepare("SELECT file_path FROM entrance_documents WHERE entrance_id=? AND student_id=?"); $s->bind_param("ii",$eid,$sid); $s->execute(); $r=$s->get_result()->fetch_assoc(); $s->close();
    if($r&&!empty($r['file_path'])){$p=__DIR__."/../".$r['file_path']; if(file_exists($p))unlink($p);}
    $s=$conn->prepare("UPDATE entrance_documents SET submitted=0,file_path=NULL,uploaded_by=NULL,uploaded_at=NULL WHERE entrance_id=? AND student_id=?"); $s->bind_param("ii",$eid,$sid); $s->execute(); $s->close();
    $_SESSION['success']="Deleted!"; header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    $sid=(int)$_POST['student_id'];
    if(isset($_FILES['student_photo'])&&$_FILES['student_photo']['error']===0){$ext=strtolower(pathinfo($_FILES['student_photo']['name'],PATHINFO_EXTENSION));if(in_array($ext,['jpg','jpeg','png'])){$dir=__DIR__."/../uploads/students/"; if(!is_dir($dir))mkdir($dir,0755,true);$fn="student_{$sid}_".time().".$ext";if(move_uploaded_file($_FILES['student_photo']['tmp_name'],$dir.$fn)){$s=$conn->prepare("UPDATE students_info SET photo=? WHERE student_id=?"); $s->bind_param("si",$fn,$sid); $s->execute(); $s->close();$_SESSION['success']="Photo updated!";}else $_SESSION['error']="Save failed.";}else $_SESSION['error']="JPG/PNG only.";}else $_SESSION['error']="No file.";
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_student'])) {
    $sid=(int)$_POST['student_id']; $conn->begin_transaction();
    try{foreach(['student_grades','entrance_documents','educational_history','addresses','parents_info','enrollment_form'] as $t){$s=$conn->prepare("DELETE FROM `$t` WHERE student_id=?"); $s->bind_param("i",$sid); $s->execute(); $s->close();}$s=$conn->prepare("DELETE FROM students_info WHERE student_id=?"); $s->bind_param("i",$sid); $s->execute(); $s->close();$conn->commit();$_SESSION['success']="Student deleted!"; header("Location: student_profile.php"); exit();}
    catch(Exception $e){$conn->rollback();$_SESSION['error']="Failed."; header("Location: view_student.php?id=$sid"); exit();}
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_grade'])) {
    $sid=(int)$_POST['student_id']; $sem=trim($_POST['semester']??''); $qtr=trim($_POST['quarter']??''); $sc=trim($_POST['subject_code']??''); $sn=trim($_POST['subject_name']??''); $gr=!empty($_POST['grade'])?(float)$_POST['grade']:null; $rem=trim($_POST['remarks']??'');
    $es=$conn->prepare("SELECT enrollment_id FROM enrollment_form WHERE student_id=? ORDER BY enrollment_id DESC LIMIT 1"); $es->bind_param("i",$sid); $es->execute(); $er=$es->get_result()->fetch_assoc(); $es->close();
    if(!$er){$_SESSION['error']="No enrollment."; header("Location: view_student.php?id=$sid"); exit();}
    if(empty($sem)||empty($sc)||empty($sn)){$_SESSION['error']="Fill required fields.";}
    else{$s=$conn->prepare("INSERT INTO student_grades (student_id,enrollment_id,semester,quarter,subject_code,subject_name,grade,remarks) VALUES (?,?,?,?,?,?,?,?)"); $s->bind_param("iissssds",$sid,$er['enrollment_id'],$sem,$qtr,$sc,$sn,$gr,$rem); $s->execute()?$_SESSION['success']="Added!":$_SESSION['error']="Failed."; $s->close();}
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_grade'])) {
    $gid=(int)$_POST['grade_id']; $sid=(int)$_POST['student_id']; $sem=trim($_POST['semester']??''); $qtr=trim($_POST['quarter']??''); $sc=trim($_POST['subject_code']??''); $sn=trim($_POST['subject_name']??''); $gr=!empty($_POST['grade'])?(float)$_POST['grade']:null; $rem=trim($_POST['remarks']??'');
    $es=$conn->prepare("SELECT enrollment_id FROM enrollment_form WHERE student_id=? ORDER BY enrollment_id DESC LIMIT 1"); $es->bind_param("i",$sid); $es->execute(); $er=$es->get_result()->fetch_assoc(); $es->close();
    $s=$conn->prepare("UPDATE student_grades SET semester=?,quarter=?,subject_code=?,subject_name=?,grade=?,remarks=?,enrollment_id=? WHERE grade_id=? AND student_id=?"); $s->bind_param("ssssdsiii",$sem,$qtr,$sc,$sn,$gr,$rem,$er['enrollment_id'],$gid,$sid); $s->execute()?$_SESSION['success']="Updated!":$_SESSION['error']="Failed."; $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_grade'])) {
    $gid=(int)$_POST['grade_id']; $sid=(int)$_POST['student_id'];
    $s=$conn->prepare("DELETE FROM student_grades WHERE grade_id=? AND student_id=?"); $s->bind_param("ii",$gid,$sid); $s->execute()?$_SESSION['success']="Deleted!":$_SESSION['error']="Failed."; $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_education'])) {
    $sid=(int)$_POST['student_id']; $lvl=trim($_POST['level']??''); $schn=trim($_POST['school_name']??''); $scha=trim($_POST['school_address']??''); $yr=trim($_POST['year_completed']??'');
    if(empty($lvl)||empty($schn)){$_SESSION['error']="Required fields.";}else{$s=$conn->prepare("INSERT INTO educational_history (student_id,level,school_name,school_address,year_completed) VALUES (?,?,?,?,?)"); $s->bind_param("issss",$sid,$lvl,$schn,$scha,$yr); $s->execute()?$_SESSION['success']="Added!":$_SESSION['error']="Failed."; $s->close();}
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_education'])) {
    $eid=(int)$_POST['edu_id']; $sid=(int)$_POST['student_id']; $lvl=trim($_POST['level']??''); $schn=trim($_POST['school_name']??''); $scha=trim($_POST['school_address']??''); $yr=trim($_POST['year_completed']??'');
    $s=$conn->prepare("UPDATE educational_history SET level=?,school_name=?,school_address=?,year_completed=? WHERE edu_id=? AND student_id=?"); $s->bind_param("ssssii",$lvl,$schn,$scha,$yr,$eid,$sid); $s->execute()?$_SESSION['success']="Updated!":$_SESSION['error']="Failed."; $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_education'])) {
    $eid=(int)$_POST['edu_id']; $sid=(int)$_POST['student_id'];
    $s=$conn->prepare("DELETE FROM educational_history WHERE edu_id=? AND student_id=?"); $s->bind_param("ii",$eid,$sid); $s->execute()?$_SESSION['success']="Deleted!":$_SESSION['error']="Failed."; $s->close();
    header("Location: view_student.php?id=$sid"); exit();
}

// ====================== FETCH DATA (OPTIMIZED - SINGLE QUERY) ======================
if(!isset($_GET['id'])||!is_numeric($_GET['id'])){$_SESSION['error']="No ID."; header("Location: student_profile.php"); exit();}
$student_id=(int)$_GET['id'];

// SINGLE OPTIMIZED QUERY - replaces 4 separate queries
$s = $conn->prepare("
    SELECT s.*, 
           p.father_name, p.father_occupation, p.father_contact,
           p.mother_name, p.mother_maiden_name, p.mother_occupation, p.mother_contact,
           p.guardian_fullname, p.guardian_relation, p.guardian_contact,
           p.ave_family_income, p.is_4ps, p.household_id as parent_household_id,
           a.purok_street, a.barangay, a.town_city, a.province, 
           a.region, a.district, a.postal_code,
           e.enrollment_id, e.grade_level, e.track, e.strand, e.program, 
           e.section, e.school_year, e.semester, e.status, e.voucher_status, 
           e.household_id, e.is_transferred, e.previous_school_name, 
           e.previous_school_address, e.previous_track, e.previous_strand, 
           e.previous_program, e.previous_year_completed, e.cct_4ps
    FROM students_info s
    LEFT JOIN parents_info p ON s.student_id = p.student_id
    LEFT JOIN addresses a ON s.student_id = a.student_id
    LEFT JOIN enrollment_form e ON s.student_id = e.student_id
    WHERE s.student_id = ?
    ORDER BY e.enrollment_id DESC LIMIT 1
");
$s->bind_param("i", $student_id);
$s->execute();
$student = $s->get_result()->fetch_assoc();
$s->close();

if (!$student) { $_SESSION['error'] = "Not found."; header("Location: student_profile.php"); exit(); }

// All data is now in $student array
$enrollment = !empty($student['enrollment_id']) ? $student : [];
$parents = $student;
$address = $student;

// Education and documents still separate (less frequent, simpler queries)
$s=$conn->prepare("SELECT edu_id,level,school_name,school_address,year_completed FROM educational_history WHERE student_id=? ORDER BY CASE level WHEN 'Elementary' THEN 1 WHEN 'JHS' THEN 2 ELSE 3 END"); 
$s->bind_param("i",$student_id); $s->execute(); $education=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

$s=$conn->prepare("SELECT entrance_id,document_name,submitted,file_path FROM entrance_documents WHERE student_id=? ORDER BY document_name"); 
$s->bind_param("i",$student_id); $s->execute(); $documents=$s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();

// Fetch grades with enrollment details for full academic record
$grades_stmt = $conn->prepare("
    SELECT sg.grade_id, sg.semester, sg.quarter, sg.subject_code, sg.subject_name, sg.grade, sg.remarks,
           e2.school_year, e2.grade_level
    FROM student_grades sg
    LEFT JOIN enrollment_form e2 ON sg.enrollment_id = e2.enrollment_id
    WHERE sg.student_id = ? 
    ORDER BY e2.school_year DESC, e2.grade_level ASC, sg.semester ASC, sg.quarter ASC, sg.subject_name ASC
");
$grades_stmt->bind_param("i", $student_id);
$grades_stmt->execute();
$grades_result = $grades_stmt->get_result();

// Organize by School Year → Grade Level → Semester → Quarter
$academic_record = [];
$all_grades_flat = [];
while ($row = $grades_result->fetch_assoc()) {
    $sy = $row['school_year'] ?? 'Unknown SY';
    $gl = $row['grade_level'] ?? 'Unknown Grade';
    $sem = $row['semester'] ?? 'Unknown Semester';
    $qtr = !empty($row['quarter']) ? $row['quarter'] : 'No Quarter';
    $academic_record[$sy][$gl][$sem][$qtr][] = $row;
    $all_grades_flat[] = $row;
}
$grades_stmt->close();

// Overall GWA
$total_grade_points = 0; $grade_count = 0;
foreach ($all_grades_flat as $g) { if ($g['grade'] !== null && is_numeric($g['grade'])) { $total_grade_points += (float)$g['grade']; $grade_count++; } }
$overall_gwa = $grade_count > 0 ? round($total_grade_points / $grade_count, 2) : null;

// GWA per school year
$gwa_per_year = [];
foreach ($academic_record as $sy => $levels) {
    $yt = 0; $yc = 0;
    foreach ($levels as $gl => $semesters) { foreach ($semesters as $sem => $quarters) { foreach ($quarters as $qtr => $subjects) { foreach ($subjects as $subj) { if ($subj['grade'] !== null && is_numeric($subj['grade'])) { $yt += (float)$subj['grade']; $yc++; } } } } }
    $gwa_per_year[$sy] = $yc > 0 ? round($yt / $yc, 2) : null;
}

$theme=isset($_COOKIE['admin_theme'])&&$_COOKIE['admin_theme']==='dark'?'dark':'light';
$initials=strtoupper(substr($student['first_name']??'',0,1).substr($student['last_name']??'',0,1));
$photo=(!empty($student['photo'])&&file_exists(__DIR__."/../uploads/students/".$student['photo']))?"../uploads/students/".htmlspecialchars($student['photo']):'';
$fullName=htmlspecialchars(trim(($student['last_name']??'').', '.($student['first_name']??'').' '.($student['middle_name']??'')));
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $fullName ?> • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f0f2f5; --surface: #ffffff; --text: #1a1f36; --text2: #6b7280;
            --border: #e5e7eb; --accent: #4f46e5; --accent2: #6366f1;
            --green: #059669; --red: #dc2626; --amber: #d97706;
            --shadow: 0 1px 3px rgba(0,0,0,0.1); --shadow-lg: 0 10px 25px rgba(0,0,0,0.08);
            --radius: 12px; --radius-lg: 16px;
        }
        [data-bs-theme="dark"] {
            --bg: #0f172a; --surface: #1e293b; --text: #f1f5f9; --text2: #94a3b8;
            --border: #334155; --accent: #818cf8; --accent2: #6366f1;
        }
        *{font-family:'Inter',system-ui,sans-serif;margin:0;padding:0;box-sizing:border-box}
        body{background:var(--bg);color:var(--text);min-height:100vh}
        .sidebar{position:fixed;left:0;top:0;bottom:0;width:260px;background:var(--surface);border-right:1px solid var(--border);z-index:200;display:flex;flex-direction:column;transition:transform 0.3s}
        .sidebar-brand{padding:1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:0.75rem}
        .sidebar-brand img{width:40px;height:40px;border-radius:10px}.sidebar-brand span{font-weight:700;font-size:1.1rem}
        .sidebar-nav{flex:1;padding:1rem 0.75rem;overflow-y:auto}
        .sidebar-nav a{display:flex;align-items:center;gap:0.75rem;padding:0.7rem 1rem;border-radius:10px;color:var(--text2);text-decoration:none;font-weight:500;font-size:0.9rem;transition:all 0.2s;margin-bottom:0.25rem}
        .sidebar-nav a:hover,.sidebar-nav a.active{background:var(--accent);color:white}
        .sidebar-nav a i{font-size:1.2rem;width:24px;text-align:center}
        .sidebar-footer{padding:1rem 0.75rem;border-top:1px solid var(--border)}
        .main-content{margin-left:260px;padding:1.5rem;min-height:100vh}
        .topbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem}
        .menu-toggle{display:none;width:40px;height:40px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;align-items:center;justify-content:center}
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
        .card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}.card-header h3 i{color:var(--accent)}
        .card-body{padding:1.5rem}.card-body.no-padding{padding:0}
        .hero{display:flex;gap:2rem;align-items:center;flex-wrap:wrap;padding:2rem}
        .hero-avatar img,.hero-avatar .avatar-fallback{width:110px;height:110px;border-radius:50%;object-fit:cover;border:4px solid var(--accent);box-shadow:0 0 0 4px rgba(79,70,229,0.2)}
        .hero-avatar .avatar-fallback{background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;color:white;font-size:2.5rem;font-weight:800}
        .hero-avatar .camera-btn{position:absolute;bottom:0;right:0;width:34px;height:34px;border-radius:50%;background:var(--surface);border:2px solid var(--border);cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--accent)}
        .hero-info h1{font-size:1.6rem;font-weight:700;margin-bottom:0.5rem}
        .hero-badges{display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:1rem}
        .badge-pill{display:inline-flex;align-items:center;gap:0.35rem;padding:0.3rem 0.75rem;border-radius:50px;font-size:0.75rem;font-weight:600}
        .badge-pill.primary{background:#eef2ff;color:#4338ca}.badge-pill.success{background:#d1fae5;color:#065f46}.badge-pill.info{background:#dbeafe;color:#1e40af}
        [data-bs-theme="dark"] .badge-pill.primary{background:#312e81;color:#a5b4fc}[data-bs-theme="dark"] .badge-pill.success{background:#064e3b;color:#6ee7b7}[data-bs-theme="dark"] .badge-pill.info{background:#1e3a5f;color:#93c5fd}
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:1rem}
        .stat-card{background:var(--bg);border-radius:var(--radius);padding:1rem;text-align:center}
        .stat-value{font-size:1.5rem;font-weight:700;color:var(--accent)}.stat-label{font-size:0.72rem;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:0.25rem}
        .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:0;border-top:1px solid var(--border);border-left:1px solid var(--border)}
        .info-cell{padding:0.75rem 1rem;border-right:1px solid var(--border);border-bottom:1px solid var(--border)}
        .info-cell .label{font-size:0.68rem;font-weight:600;color:var(--text2);text-transform:uppercase;letter-spacing:0.6px;margin-bottom:0.2rem}
        .info-cell .value{font-weight:500;font-size:0.88rem}.info-cell.span-2{grid-column:span 2}
        .table-admin{width:100%;border-collapse:collapse}
        .table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.7rem 0.8rem;text-align:left;border-bottom:2px solid var(--border)}
        .table-admin td{padding:0.6rem 0.8rem;border-bottom:1px solid var(--border);font-size:0.82rem;vertical-align:middle}
        .table-admin tr:hover td{background:rgba(79,70,229,0.03)}
        .academic-sy-header{background:var(--bg);padding:0.75rem 1.5rem;border-bottom:1px solid var(--border)}
        .quarter-group{margin:0.5rem 1.5rem;padding:0.75rem 1rem;border-radius:var(--radius);border-left:4px solid}
        .status-dot{display:inline-flex;align-items:center;gap:0.35rem;font-size:0.78rem;font-weight:600}
        .status-dot::before{content:'';width:8px;height:8px;border-radius:50%}
        .status-dot.success::before{background:var(--green)}.status-dot.danger::before{background:var(--red)}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white}
        .btn{font-weight:500;border-radius:8px}.btn-xs{padding:0.2rem 0.5rem;font-size:0.7rem;border-radius:6px}
        .btn-icon{width:30px;height:30px;padding:0;display:inline-flex;align-items:center;justify-content:center;border-radius:8px}
        .btn-group-actions{display:flex;gap:0.35rem}
        .breadcrumb-nav{display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--text2);margin-bottom:0.25rem}
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}.hero{flex-direction:column;text-align:center}.hero-badges{justify-content:center}}
        @media(max-width:640px){.main-content{padding:1rem}.info-grid{grid-template-columns:1fr}.info-cell.span-2{grid-column:span 1}.stat-grid{grid-template-columns:repeat(2,1fr)}}
    </style>
</head>
<body>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand"><img src="../assets/img/usat.jpg" alt="USAT"><span>USAT Admin</span></div>
    <nav class="sidebar-nav">
        <a href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
        <a href="student_profile.php" class="active"><i class="bi bi-people-fill"></i> Students</a>
        <a href="reports.php"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
        <a href="create_account.php"><i class="bi bi-person-plus"></i> Accounts</a>
    </nav>
    <div class="sidebar-footer"><a href="logout.php" class="btn btn-outline-danger btn-sm w-100"><i class="bi bi-box-arrow-right me-1"></i> Logout</a></div>
</aside>

<div class="main-content" id="mainContent">
    <?php if(isset($_SESSION['success'])): ?><div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-check-circle-fill fs-5"></i> <?= $_SESSION['success'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['success']); endif; ?>
    <?php if(isset($_SESSION['error'])): ?><div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-exclamation-triangle-fill fs-5"></i> <?= $_SESSION['error'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['error']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3"><button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button><div><div class="breadcrumb-nav"><a href="student_profile.php">Students</a> <i class="bi bi-chevron-right small"></i> Profile</div><h2 style="font-size:1.3rem;font-weight:700;margin:0">Student Profile</h2></div></div>
        <div class="d-flex gap-2"><a href="?id=<?= $student_id ?>&export_word=1" class="btn btn-outline-success btn-sm"><i class="bi bi-file-word me-1"></i> Export</a><button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i> Print</button><button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button></div>
    </div>

    <!-- Hero Card -->
    <div class="card"><div class="hero">
        <div class="hero-avatar position-relative"><?php if($photo): ?><img src="<?= $photo ?>" alt="Photo"><?php else: ?><div class="avatar-fallback"><?= $initials ?></div><?php endif; ?><button class="camera-btn" data-bs-toggle="modal" data-bs-target="#uploadPhotoModal"><i class="bi bi-camera-fill small"></i></button></div>
        <div class="hero-info flex-grow-1"><h1><?= $fullName ?></h1><div class="hero-badges"><span class="badge-pill primary"><i class="bi bi-upc-scan"></i> LRN: <?= htmlspecialchars($student['lrn']??'N/A') ?></span><span class="badge-pill info"><i class="bi bi-person-badge"></i> <?= htmlspecialchars($student['student_id_number']??'No ID') ?></span><?php if($overall_gwa!==null): ?><span class="badge-pill success"><i class="bi bi-star-fill"></i> GWA: <?= number_format($overall_gwa,2) ?></span><?php endif; ?><span class="badge-pill <?= ($enrollment['status']??'Active')=='Active'?'success':'warning' ?>"><?= $enrollment['status']??'Active' ?></span></div><div class="btn-group-actions mt-2"><a href="edit_student.php?id=<?= $student_id ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil-square me-1"></i> Edit</a><button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteStudentModal"><i class="bi bi-trash3 me-1"></i> Delete</button></div></div>
    </div></div>

    <!-- Quick Stats -->
    <div class="stat-grid"><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($enrollment['grade_level']??'—') ?></div><div class="stat-label">Grade</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($enrollment['section']??'—') ?></div><div class="stat-label">Section</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($enrollment['strand']??'—') ?></div><div class="stat-label">Strand</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($student['age']??'—') ?></div><div class="stat-label">Age</div></div><div class="stat-card"><div class="stat-value"><?= htmlspecialchars($student['sex']??'—') ?></div><div class="stat-label">Sex</div></div><div class="stat-card"><div class="stat-value"><?= $overall_gwa!==null?number_format($overall_gwa,2):'—' ?></div><div class="stat-label">GWA</div></div></div>

    <!-- Personal Info + Enrollment/Address -->
    <div class="row g-3 mt-2">
        <div class="col-lg-6"><div class="card h-100"><div class="card-header"><h3><i class="bi bi-person-vcard"></i> Personal Information</h3></div><div class="card-body no-padding"><div class="info-grid"><div class="info-cell"><div class="label">Birth Date</div><div class="value"><?= htmlspecialchars($student['birth_date']??'—') ?></div></div><div class="info-cell"><div class="label">Civil Status</div><div class="value"><?= htmlspecialchars($student['civil_status']??'—') ?></div></div><div class="info-cell"><div class="label">Nationality</div><div class="value"><?= htmlspecialchars($student['nationality']??'—') ?></div></div><div class="info-cell"><div class="label">Religion</div><div class="value"><?= htmlspecialchars($student['religion']??'—') ?></div></div><div class="info-cell"><div class="label">Height</div><div class="value"><?= htmlspecialchars($student['height']??'—') ?> cm</div></div><div class="info-cell"><div class="label">Weight</div><div class="value"><?= htmlspecialchars($student['weight']??'—') ?> kg</div></div><div class="info-cell"><div class="label">Email</div><div class="value"><?= htmlspecialchars($student['email']??'—') ?></div></div><div class="info-cell"><div class="label">Phone</div><div class="value"><?= htmlspecialchars($student['phone']??'—') ?></div></div><div class="info-cell"><div class="label">Nickname</div><div class="value"><?= htmlspecialchars($student['nick_name']??'—') ?></div></div><div class="info-cell"><div class="label">Extension</div><div class="value"><?= htmlspecialchars($student['ext_name']??'—') ?></div></div><div class="info-cell span-2"><div class="label">Special Skills</div><div class="value"><?= nl2br(htmlspecialchars($student['special_skills']??'None')) ?></div></div></div></div></div></div>
        <div class="col-lg-6">
            <div class="card"><div class="card-header"><h3><i class="bi bi-mortarboard"></i> Enrollment Details</h3></div><div class="card-body no-padding"><?php if(!empty($enrollment)): ?><div class="info-grid"><div class="info-cell"><div class="label">School Year</div><div class="value"><?= htmlspecialchars($enrollment['school_year']??'—') ?></div></div><div class="info-cell"><div class="label">Grade Level</div><div class="value"><?= htmlspecialchars($enrollment['grade_level']??'—') ?></div></div><div class="info-cell"><div class="label">Semester</div><div class="value"><?= htmlspecialchars($enrollment['semester']??'—') ?></div></div><div class="info-cell"><div class="label">Section</div><div class="value fw-bold" style="color:var(--accent)"><?= htmlspecialchars($enrollment['section']??'—') ?></div></div><div class="info-cell"><div class="label">Track</div><div class="value"><?= htmlspecialchars($enrollment['track']??'—') ?></div></div><div class="info-cell"><div class="label">Strand</div><div class="value"><?= htmlspecialchars($enrollment['strand']??'—') ?></div></div><div class="info-cell"><div class="label">Program</div><div class="value"><?= htmlspecialchars($enrollment['program']??'—') ?></div></div><div class="info-cell"><div class="label">Voucher</div><div class="value"><?= htmlspecialchars($enrollment['voucher_status']??'—') ?></div></div><div class="info-cell"><div class="label">4Ps ID</div><div class="value"><?= htmlspecialchars($enrollment['household_id']??'—') ?></div></div><div class="info-cell"><div class="label">Status</div><div class="value"><span class="status-dot <?= ($enrollment['status']??'Active')=='Active'?'success':'danger' ?>"><?= $enrollment['status']??'Active' ?></span></div></div></div><?php else: ?><div class="p-4 text-center text-muted">No enrollment record.</div><?php endif; ?></div></div>
            <div class="card mt-3"><div class="card-header"><h3><i class="bi bi-geo-alt"></i> Address</h3></div><div class="card-body no-padding"><?php if(!empty($address)): ?><div class="info-grid"><div class="info-cell"><div class="label">Purok/Street</div><div class="value"><?= htmlspecialchars($address['purok_street']??'—') ?></div></div><div class="info-cell"><div class="label">Barangay</div><div class="value"><?= htmlspecialchars($address['barangay']??'—') ?></div></div><div class="info-cell"><div class="label">Town/City</div><div class="value"><?= htmlspecialchars($address['town_city']??'—') ?></div></div><div class="info-cell"><div class="label">Province</div><div class="value"><?= htmlspecialchars($address['province']??'—') ?></div></div><div class="info-cell"><div class="label">Region</div><div class="value"><?= htmlspecialchars($address['region']??'—') ?></div></div><div class="info-cell"><div class="label">District</div><div class="value"><?= htmlspecialchars($address['district']??'—') ?></div></div><div class="info-cell"><div class="label">Postal Code</div><div class="value"><?= htmlspecialchars($address['postal_code']??'—') ?></div></div></div><?php else: ?><div class="p-4 text-center text-muted">No address.</div><?php endif; ?></div></div>
        </div>
    </div>

    <!-- Parents & Guardian -->
    <div class="card mt-3"><div class="card-header"><h3><i class="bi bi-people"></i> Parents & Guardian</h3></div><div class="card-body no-padding"><?php if(!empty($parents)): ?><div class="info-grid"><div class="info-cell"><div class="label">Father Name</div><div class="value"><?= htmlspecialchars($parents['father_name']??'—') ?></div></div><div class="info-cell"><div class="label">Father Occupation</div><div class="value"><?= htmlspecialchars($parents['father_occupation']??'—') ?></div></div><div class="info-cell"><div class="label">Father Contact</div><div class="value"><?= htmlspecialchars($parents['father_contact']??'—') ?></div></div><div class="info-cell"><div class="label">Mother (Maiden)</div><div class="value"><?= htmlspecialchars($parents['mother_maiden_name']??'—') ?></div></div><div class="info-cell"><div class="label">Mother Occupation</div><div class="value"><?= htmlspecialchars($parents['mother_occupation']??'—') ?></div></div><div class="info-cell"><div class="label">Mother Contact</div><div class="value"><?= htmlspecialchars($parents['mother_contact']??'—') ?></div></div><div class="info-cell"><div class="label">Family Income</div><div class="value fw-bold"><?= $parents['ave_family_income']?'₱'.number_format($parents['ave_family_income'],2):'—' ?></div></div><div class="info-cell"><div class="label">4Ps</div><div class="value"><span class="status-dot <?= !empty($parents['is_4ps'])?'success':'danger' ?>"><?= !empty($parents['is_4ps'])?'Yes':'No' ?></span></div></div><div class="info-cell"><div class="label">Household ID</div><div class="value"><?= htmlspecialchars($parents['parent_household_id']??$parents['household_id']??'—') ?></div></div><div class="info-cell span-2" style="background:var(--bg)"><div class="label">Guardian</div><div class="value"><?= htmlspecialchars($parents['guardian_fullname']??'—') ?> (<?= htmlspecialchars($parents['guardian_relation']??'—') ?>) — <?= htmlspecialchars($parents['guardian_contact']??'—') ?></div></div></div><?php else: ?><div class="p-4 text-center text-muted">No data.</div><?php endif; ?></div></div>

    <!-- Documents -->
    <div class="card mt-3"><div class="card-header"><h3><i class="bi bi-file-earmark-check"></i> Required Documents</h3></div><div class="card-body no-padding"><?php if(!empty($documents)): ?><table class="table-admin"><thead><tr><th>Document</th><th width="100">Status</th><th width="130" style="text-align:right">Action</th></tr></thead><tbody><?php foreach($documents as $d): $ok=!empty($d['submitted'])&&!empty($d['file_path']); ?><tr><td><i class="bi bi-file-earmark-text me-2 text-muted"></i> <?= htmlspecialchars($d['document_name']) ?></td><td><span class="status-dot <?= $ok?'success':'danger' ?>"><?= $ok?'Submitted':'Missing' ?></span></td><td style="text-align:right"><?php if($ok): ?><div class="btn-group-actions" style="justify-content:flex-end"><a href="../<?= htmlspecialchars($d['file_path']) ?>" class="btn btn-icon btn-outline-primary" target="_blank"><i class="bi bi-eye"></i></a><button class="btn btn-icon btn-outline-danger delete-doc-btn" data-eid="<?= $d['entrance_id'] ?>" data-name="<?= htmlspecialchars($d['document_name']) ?>"><i class="bi bi-trash3"></i></button></div><?php else: ?><button class="btn btn-primary btn-xs upload-doc-btn" data-eid="<?= $d['entrance_id'] ?>" data-name="<?= htmlspecialchars($d['document_name']) ?>"><i class="bi bi-upload me-1"></i> Upload</button><?php endif; ?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="p-4 text-center text-muted">No documents.</div><?php endif; ?></div></div>

    <!-- Education -->
    <div class="card mt-3"><div class="card-header"><h3><i class="bi bi-book"></i> Educational History</h3><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addEducationModal"><i class="bi bi-plus-lg me-1"></i> Add</button></div><div class="card-body no-padding"><?php if(!empty($education)): ?><table class="table-admin"><thead><tr><th>Level</th><th>School Name</th><th>Address</th><th width="80">Year</th><th width="100" style="text-align:right">Action</th></tr></thead><tbody><?php foreach($education as $e): ?><tr><td class="fw-semibold"><?= htmlspecialchars($e['level']) ?></td><td><?= htmlspecialchars($e['school_name']) ?></td><td><?= htmlspecialchars($e['school_address']??'—') ?></td><td><?= htmlspecialchars($e['year_completed']??'—') ?></td><td style="text-align:right"><div class="btn-group-actions" style="justify-content:flex-end"><button class="btn btn-icon btn-outline-warning edit-edu-btn" data-bs-toggle="modal" data-bs-target="#editEducationModal" data-id="<?= $e['edu_id'] ?>" data-lvl="<?= htmlspecialchars($e['level']) ?>" data-sch="<?= htmlspecialchars($e['school_name']) ?>" data-adr="<?= htmlspecialchars($e['school_address']??'') ?>" data-yr="<?= htmlspecialchars($e['year_completed']??'') ?>"><i class="bi bi-pencil"></i></button><button class="btn btn-icon btn-outline-danger del-edu-btn" data-id="<?= $e['edu_id'] ?>" data-sch="<?= htmlspecialchars($e['school_name']) ?>"><i class="bi bi-trash3"></i></button></div></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="p-4 text-center text-muted">No records.</div><?php endif; ?></div></div>

    <!-- ACADEMIC RECORD WITH QUARTERS -->
    <div class="card mt-3"><div class="card-header"><h3><i class="bi bi-journal-text"></i> Academic Record & Grades</h3><div class="d-flex gap-2"><?php if($overall_gwa!==null): ?><span class="badge-pill primary">Overall GWA: <strong><?= number_format($overall_gwa,2) ?></strong></span><?php endif; ?><button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addGradeModal"><i class="bi bi-plus-lg me-1"></i> Add Grade</button></div></div><div class="card-body no-padding">
        <?php if(!empty($academic_record)): ?><?php $qColors=['1st Quarter'=>['bg'=>'#eef2ff','text'=>'#4338ca','border'=>'#c7d2fe'],'2nd Quarter'=>['bg'=>'#dbeafe','text'=>'#1e40af','border'=>'#93c5fd'],'3rd Quarter'=>['bg'=>'#ede9fe','text'=>'#6d28d9','border'=>'#c4b5fd'],'4th Quarter'=>['bg'=>'#fce7f3','text'=>'#9d174d','border'=>'#f9a8d4'],'No Quarter'=>['bg'=>'#f1f5f9','text'=>'#64748b','border'=>'#e2e8f0']];
        foreach($academic_record as $school_year => $grade_levels): ?><div><div class="academic-sy-header"><div class="d-flex justify-content-between align-items-center"><h6 class="fw-bold mb-0" style="color:var(--accent)"><i class="bi bi-calendar-range me-2"></i>SY: <?= htmlspecialchars($school_year) ?></h6><?php if(isset($gwa_per_year[$school_year])): ?><span class="badge-pill primary">SY GWA: <?= number_format($gwa_per_year[$school_year],2) ?></span><?php endif; ?></div></div>
        <?php foreach($grade_levels as $grade_level => $semesters): ?><div class="px-4 py-2" style="background:rgba(79,70,229,0.04);border-bottom:1px solid var(--border)"><h6 class="fw-bold mb-0 small"><i class="bi bi-mortarboard me-2"></i><?= htmlspecialchars($grade_level) ?></h6></div>
        <?php foreach($semesters as $semester => $quarters): ?><div class="px-4 py-1 small text-muted fw-semibold"><?= htmlspecialchars($semester) ?></div>
        <?php foreach($quarters as $quarter => $subjects): $qt=0;$qc=0;foreach($subjects as $s){if($s['grade']!==null&&is_numeric($s['grade'])){$qt+=(float)$s['grade'];$qc++;}}$qgwa=$qc>0?round($qt/$qc,2):null;$qcData=$qColors[$quarter]??$qColors['No Quarter']; ?>
        <div class="quarter-group" style="background:<?= $qcData['bg'] ?>;border-color:<?= $qcData['border'] ?>"><div class="d-flex justify-content-between align-items-center mb-2"><span class="fw-bold small" style="color:<?= $qcData['text'] ?>"><i class="bi bi-caret-right-fill me-1"></i><?= htmlspecialchars($quarter) ?> (<?= count($subjects) ?>)</span><?php if($qgwa!==null): ?><span class="badge-pill" style="background:white;color:<?= $qcData['text'] ?>;font-size:0.7rem">Q GWA: <?= number_format($qgwa,2) ?></span><?php endif; ?></div>
        <table class="table-admin" style="background:white;border-radius:8px;overflow:hidden;font-size:0.78rem"><thead><tr><th width="80">Code</th><th>Subject</th><th width="70">Grade</th><th width="100">Remarks</th><th width="70" style="text-align:right">Action</th></tr></thead><tbody><?php foreach($subjects as $g): ?><tr><td class="fw-semibold small"><?= htmlspecialchars($g['subject_code']) ?></td><td><?= htmlspecialchars($g['subject_name']) ?></td><td class="fw-bold <?= ($g['grade']??0)>=75?'text-success':'text-danger' ?>"><?= $g['grade']!==null?number_format($g['grade'],2):'—' ?></td><td class="small"><?= htmlspecialchars($g['remarks']??'—') ?></td><td style="text-align:right"><div class="btn-group-actions" style="justify-content:flex-end"><button class="btn btn-icon btn-outline-warning edit-grd-btn" data-bs-toggle="modal" data-bs-target="#editGradeModal" data-id="<?= $g['grade_id'] ?>" data-sem="<?= htmlspecialchars($g['semester']) ?>" data-qtr="<?= htmlspecialchars($g['quarter']??'') ?>" data-code="<?= htmlspecialchars($g['subject_code']) ?>" data-name="<?= htmlspecialchars($g['subject_name']) ?>" data-grd="<?= $g['grade']??'' ?>" data-rem="<?= htmlspecialchars($g['remarks']??'') ?>"><i class="bi bi-pencil"></i></button><button class="btn btn-icon btn-outline-danger del-grd-btn" data-id="<?= $g['grade_id'] ?>" data-name="<?= htmlspecialchars($g['subject_name']) ?>"><i class="bi bi-trash3"></i></button></div></td></tr><?php endforeach; ?></tbody></table></div>
        <?php endforeach; ?><?php endforeach; ?><?php endforeach; ?></div><?php endforeach; ?>
        <?php else: ?><div class="p-4 text-center text-muted"><i class="bi bi-journal-text display-4 d-block mb-3"></i>No grades recorded yet.</div><?php endif; ?>
    </div></div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<!-- MODALS (same as original) -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-camera me-2"></i>Change Photo</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST" enctype="multipart/form-data"><div class="modal-body"><input type="hidden" name="upload_photo" value="1"><input type="hidden" name="student_id" value="<?= $student_id ?>"><input type="file" name="student_photo" class="form-control" accept="image/*" required></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm">Upload</button></div></form></div></div></div>
<div class="modal fade" id="uploadDocumentModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-cloud-upload me-2"></i>Upload Document</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><form id="docForm" enctype="multipart/form-data"><input type="hidden" name="upload_document" value="1"><input type="hidden" name="student_id" value="<?= $student_id ?>"><input type="hidden" name="entrance_id" id="m_eid"><p class="fw-semibold small mb-2" id="m_dname"></p><input type="file" name="document_file" id="m_file" class="form-control" required><div id="m_msg" class="mt-2"></div></form></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary btn-sm" id="btnUpDoc">Upload</button></div></div></div></div>
<div class="modal fade" id="deleteDocModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header bg-danger text-white"><h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Document</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Delete <strong id="del_dname"></strong>?</p></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><form method="POST"><input type="hidden" name="delete_document" value="1"><input type="hidden" name="student_id" value="<?= $student_id ?>"><input type="hidden" name="entrance_id" id="del_eid"><button class="btn btn-danger btn-sm">Delete</button></form></div></div></div></div>
<div class="modal fade" id="deleteStudentModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header bg-danger text-white"><h6 class="modal-title fw-bold"><i class="bi bi-exclamation-triangle me-2"></i>Delete Student</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="mb-0">Permanently delete <strong><?= $fullName ?></strong> and all records?</p></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><form method="POST"><input type="hidden" name="delete_student" value="1"><input type="hidden" name="student_id" value="<?= $student_id ?>"><button class="btn btn-danger btn-sm">Delete</button></form></div></div></div></div>
<div class="modal fade" id="addGradeModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Grade</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="student_id" value="<?= $student_id ?>"><select name="semester" class="form-select form-select-sm mb-2" required><option value="">Select Semester</option><option value="1st Semester">1st Semester</option><option value="2nd Semester">2nd Semester</option></select><select name="quarter" class="form-select form-select-sm mb-2"><option value="">Select Quarter (optional)</option><option value="1st Quarter">1st Quarter</option><option value="2nd Quarter">2nd Quarter</option><option value="3rd Quarter">3rd Quarter</option><option value="4th Quarter">4th Quarter</option></select><input name="subject_code" class="form-control form-control-sm mb-2" placeholder="Subject Code" required><input name="subject_name" class="form-control form-control-sm mb-2" placeholder="Subject Name" required><input type="number" step="0.01" name="grade" class="form-control form-control-sm mb-2" placeholder="Grade"><input name="remarks" class="form-control form-control-sm" placeholder="Remarks"></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button name="add_grade" class="btn btn-primary btn-sm">Save</button></div></form></div></div></div>
<div class="modal fade" id="editGradeModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Grade</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="grade_id" id="eg_id"><input type="hidden" name="student_id" value="<?= $student_id ?>"><select name="semester" id="eg_sem" class="form-select form-select-sm mb-2" required><option value="">Select Semester</option><option value="1st Semester">1st Semester</option><option value="2nd Semester">2nd Semester</option></select><select name="quarter" id="eg_qtr" class="form-select form-select-sm mb-2"><option value="">Select Quarter</option><option value="1st Quarter">1st Quarter</option><option value="2nd Quarter">2nd Quarter</option><option value="3rd Quarter">3rd Quarter</option><option value="4th Quarter">4th Quarter</option></select><input name="subject_code" id="eg_code" class="form-control form-control-sm mb-2" required><input name="subject_name" id="eg_name" class="form-control form-control-sm mb-2" required><input type="number" step="0.01" name="grade" id="eg_grd" class="form-control form-control-sm mb-2"><input name="remarks" id="eg_rem" class="form-control form-control-sm"></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button name="edit_grade" class="btn btn-primary btn-sm">Update</button></div></form></div></div></div>
<div class="modal fade" id="deleteGradeModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header bg-danger text-white"><h6 class="modal-title fw-bold"><i class="bi bi-trash3 me-2"></i>Delete Grade</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Delete <strong id="dg_name"></strong>?</p></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><form method="POST"><input type="hidden" name="grade_id" id="dg_id"><input type="hidden" name="student_id" value="<?= $student_id ?>"><button name="delete_grade" class="btn btn-danger btn-sm">Delete</button></form></div></div></div></div>
<div class="modal fade" id="addEducationModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-plus-circle me-2"></i>Add Education</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="student_id" value="<?= $student_id ?>"><input name="level" class="form-control form-control-sm mb-2" placeholder="Level" required><input name="school_name" class="form-control form-control-sm mb-2" placeholder="School Name" required><input name="school_address" class="form-control form-control-sm mb-2" placeholder="Address"><input name="year_completed" class="form-control form-control-sm" placeholder="Year"></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button name="add_education" class="btn btn-primary btn-sm">Save</button></div></form></div></div></div>
<div class="modal fade" id="editEducationModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header"><h6 class="modal-title fw-bold"><i class="bi bi-pencil me-2"></i>Edit Education</h6><button class="btn-close" data-bs-dismiss="modal"></button></div><form method="POST"><div class="modal-body"><input type="hidden" name="edu_id" id="ee_id"><input type="hidden" name="student_id" value="<?= $student_id ?>"><input name="level" id="ee_lvl" class="form-control form-control-sm mb-2" required><input name="school_name" id="ee_sch" class="form-control form-control-sm mb-2" required><input name="school_address" id="ee_adr" class="form-control form-control-sm mb-2"><input name="year_completed" id="ee_yr" class="form-control form-control-sm"></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button name="edit_education" class="btn btn-primary btn-sm">Update</button></div></form></div></div></div>
<div class="modal fade" id="deleteEducationModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header bg-danger text-white"><h6 class="modal-title fw-bold"><i class="bi bi-trash3 me-2"></i>Delete Education</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p>Delete <strong id="de_sch"></strong>?</p></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><form method="POST"><input type="hidden" name="edu_id" id="de_id"><input type="hidden" name="student_id" value="<?= $student_id ?>"><button name="delete_education" class="btn btn-danger btn-sm">Delete</button></form></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();
tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));
$('.upload-doc-btn').click(function(){$('#m_eid').val($(this).data('eid'));$('#m_dname').text($(this).data('name'));$('#m_msg').html('');$('#m_file').val('');$('#uploadDocumentModal').modal('show')});
$('.delete-doc-btn').click(function(){$('#del_eid').val($(this).data('eid'));$('#del_dname').text($(this).data('name'));$('#deleteDocModal').modal('show')});
$('#btnUpDoc').click(function(){var f=new FormData($('#docForm')[0]);$.ajax({url:'view_student.php',type:'POST',data:f,contentType:false,processData:false,beforeSend:function(){$('#btnUpDoc').prop('disabled',true).html('<span class="spinner-border spinner-border-sm"></span>')},success:function(){location.reload()},error:function(){$('#m_msg').html('<div class="alert alert-danger py-1 small mt-2">Failed</div>');$('#btnUpDoc').prop('disabled',false).html('Upload')}})});
$('.edit-grd-btn').click(function(){var b=$(this);$('#eg_id').val(b.data('id'));$('#eg_sem').val(b.data('sem'));$('#eg_qtr').val(b.data('qtr'));$('#eg_code').val(b.data('code'));$('#eg_name').val(b.data('name'));$('#eg_grd').val(b.data('grd'));$('#eg_rem').val(b.data('rem'))});
$('.del-grd-btn').click(function(){var b=$(this);$('#dg_id').val(b.data('id'));$('#dg_name').text(b.data('name'));$('#deleteGradeModal').modal('show')});
$('.edit-edu-btn').click(function(){var b=$(this);$('#ee_id').val(b.data('id'));$('#ee_lvl').val(b.data('lvl'));$('#ee_sch').val(b.data('sch'));$('#ee_adr').val(b.data('adr'));$('#ee_yr').val(b.data('yr'))});
$('.del-edu-btn').click(function(){var b=$(this);$('#de_id').val(b.data('id'));$('#de_sch').text(b.data('sch'));$('#deleteEducationModal').modal('show')});
</script>
</body>
</html>