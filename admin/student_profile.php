<?php
session_start();
include __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);

/**
 * Compute initials for a strand name.
 *   "ICT Support and Computer Programming Technologies" → "ISCPT"
 *   "Hospitality and Tourism"                          → "HT"
 */
function strandInitials(string $strand): string {
    $stopwords = ['and','of','the','for','in','on','to','a','an','at','by','with','or'];
    $words = preg_split('/\s+/', trim($strand));
    $out = '';
    foreach ($words as $w) {
        $w = preg_replace('/[^A-Za-z0-9]/', '', $w);
        if ($w === '') continue;
        if (in_array(strtolower($w), $stopwords, true)) continue;
        $out .= strtoupper($w[0]);
    }
    return $out !== '' ? $out : 'GEN';
}

/**
 * Build display name: LastName, FirstName M.I. Ext.
 * Example: "Pable, Joshua A."  or  "Pable, Joshua A. Jr."
 */
function formatStudentName(array $s): string {
    $mi  = !empty($s['middle_name']) ? ' ' . strtoupper(substr(trim($s['middle_name']), 0, 1)) . '.' : '';
    $ext = !empty($s['ext_name'])    ? ' ' . trim($s['ext_name']) : '';
    return trim(($s['last_name'] ?? '') . ', ' . ($s['first_name'] ?? '') . $mi . $ext);
}

// ────────────────────────────────────────────────
// UPDATE SCHOOL YEAR AFTER PROMOTION
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_school_year'])) {
    $old_sy = trim($_POST['old_school_year'] ?? '');
    $new_sy = trim($_POST['new_school_year'] ?? '');
    
    if (empty($old_sy) || empty($new_sy)) {
        $_SESSION['error'] = "Both old and new school years are required.";
    } elseif ($old_sy === $new_sy) {
        $_SESSION['error'] = "New school year must be different from the old one.";
    } else {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE enrollment_form SET school_year = ? WHERE school_year = ? AND grade_level IN ('Grade 12', 'Graduated')");
            $stmt->bind_param("ss", $new_sy, $old_sy);
            $stmt->execute();
            $updated = $stmt->affected_rows;
            $stmt->close();
            $conn->commit();
            
            if ($updated > 0) {
                $_SESSION['success'] = "School year updated! $updated promoted student(s) moved from $old_sy to $new_sy.";
            } else {
                $_SESSION['info'] = "No promoted students found with school year $old_sy.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Update failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_sy));
    exit();
}

// ────────────────────────────────────────────────
// PROMOTE SELECTED STUDENTS
// ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['promote_selected'])) {
    $selected_ids = $_POST['selected_students'] ?? [];
    $promote_sy = trim($_POST['promote_school_year'] ?? '');
    $new_school_year = trim($_POST['new_school_year'] ?? '');
    
    if (empty($selected_ids)) {
        $_SESSION['error'] = "No students selected for promotion.";
    } elseif (empty($promote_sy)) {
        $_SESSION['error'] = "School Year is required.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "New School Year is required.";
    } else {
        $conn->begin_transaction();
        try {
            $promoted_11 = 0;
            $graduated_12 = 0;
            
            foreach ($selected_ids as $student_id) {
                $sid = (int)$student_id;
                
                $check = $conn->prepare("SELECT grade_level, status FROM enrollment_form WHERE student_id = ? AND school_year = ? AND (status = 'Active' OR status IS NULL) LIMIT 1");
                $check->bind_param("is", $sid, $promote_sy);
                $check->execute();
                $result = $check->get_result()->fetch_assoc();
                $check->close();
                
                if ($result) {
                    if ($result['grade_level'] === 'Grade 11') {
                        $upd = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE student_id = ? AND school_year = ?");
                        $upd->bind_param("sis", $new_school_year, $sid, $promote_sy);
                        $upd->execute();
                        if ($upd->affected_rows > 0) $promoted_11++;
                        $upd->close();
                    } elseif ($result['grade_level'] === 'Grade 12') {
                        // Graduate: keep grade_level = 'Grade 12' so grade snapshots stay correct
                        $upd = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE student_id = ? AND school_year = ? AND grade_level = 'Grade 12'");
                        $upd->bind_param("sis", $new_school_year, $sid, $promote_sy);
                        $upd->execute();
                        if ($upd->affected_rows > 0) $graduated_12++;
                        $upd->close();
                    }
                }
            }
            
            $conn->commit();
            
            $msg = [];
            if ($promoted_11 > 0) $msg[] = "$promoted_11 → Grade 12";
            if ($graduated_12 > 0) $msg[] = "$graduated_12 → Graduated";
            
            if (!empty($msg)) {
                $_SESSION['success'] = "Promotion completed! " . implode(" • ", $msg) . ". School year updated to $new_school_year.";
            } else {
                $_SESSION['error'] = "No eligible active students were promoted.";
            }
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

// ────────────────────────────────────────────────
// PROMOTE ALL STUDENTS
// ────────────────────────────────────────────────
if (isset($_GET['promote_students']) && isset($_GET['school_year']) && isset($_GET['promote_type'])) {
    $promote_sy = trim($_GET['school_year']);
    $promote_type = trim($_GET['promote_type']);
    $new_school_year = trim($_GET['new_school_year'] ?? '');
    
    if (empty($promote_sy)) {
        $_SESSION['error'] = "Please select a School Year to promote students.";
    } elseif (empty($new_school_year)) {
        $_SESSION['error'] = "Please provide a New School Year.";
    } else {
        $conn->begin_transaction();
        try {
            $total_promoted = 0;
            
            if ($promote_type === 'grade11') {
                $stmt = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy);
                $stmt->execute();
                $total_promoted = $stmt->affected_rows;
                $stmt->close();
                $_SESSION['success'] = $total_promoted > 0 ? "$total_promoted student(s) promoted from Grade 11 to Grade 12. School year updated to $new_school_year." : "No active Grade 11 students found.";
            } elseif ($promote_type === 'grade12') {
                // Graduate: keep grade_level = 'Grade 12'
                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy);
                $stmt->execute();
                $total_promoted = $stmt->affected_rows;
                $stmt->close();
                $_SESSION['success'] = $total_promoted > 0 ? "$total_promoted student(s) graduated. School year updated to $new_school_year." : "No active Grade 12 students found.";
            } elseif ($promote_type === 'both') {
                $stmt = $conn->prepare("UPDATE enrollment_form SET grade_level = 'Grade 12', school_year = ? WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p11 = $stmt->affected_rows; $stmt->close();
                $stmt = $conn->prepare("UPDATE enrollment_form SET status = 'Graduated', school_year = ? WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
                $stmt->bind_param("ss", $new_school_year, $promote_sy); $stmt->execute(); $p12 = $stmt->affected_rows; $stmt->close();
                $total_promoted = $p11 + $p12;
                $msg = [];
                if ($p11 > 0) $msg[] = "$p11 → Grade 12";
                if ($p12 > 0) $msg[] = "$p12 → Graduated";
                $_SESSION['success'] = !empty($msg) ? "Promotion completed! " . implode(" • ", $msg) . ". School year updated to $new_school_year." : "No active students found.";
            }
            
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = "Promotion failed: " . $e->getMessage();
        }
    }
    header("Location: student_profile.php?school_year=" . urlencode($new_school_year ?: $promote_sy));
    exit();
}

// ────────────────────────────────────────────────
// FILTER INPUTS
// ────────────────────────────────────────────────
$search       = trim($_GET['search'] ?? '');
$grade_level  = trim($_GET['grade_level'] ?? '');
$school_year  = trim($_GET['school_year'] ?? '');
$status       = trim($_GET['status'] ?? '');

// ────────────────────────────────────────────────
// PAGINATION
// ────────────────────────────────────────────────
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 50;
$offset = ($page - 1) * $per_page;

// ────────────────────────────────────────────────
// AUTO SECTION ASSIGNMENT — initials + letter
// ────────────────────────────────────────────────
if (isset($_GET['auto_assign'])) {
    if (empty($grade_level) || empty($school_year)) {
        $_SESSION['error'] = "Please select Grade Level and School Year to auto-assign sections.";
    } else {
        $target_size      = max(5, min(60, (int)($_GET['section_size'] ?? 35)));
        $replace_existing = !empty($_GET['replace_sections']);
        $max_sections     = 26;

        $stmt = $conn->prepare("
            SELECT e.enrollment_id, e.strand, e.program, e.section,
                   s.last_name, s.first_name, s.sex
            FROM enrollment_form e
            INNER JOIN students_info s ON e.student_id = s.student_id
            WHERE e.grade_level = ?
              AND e.school_year = ?
              AND COALESCE(e.status, 'Active') = 'Active'
            ORDER BY e.strand ASC, s.last_name ASC, s.first_name ASC
        ");
        $stmt->bind_param("ss", $grade_level, $school_year);
        $stmt->execute();
        $all_rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (empty($all_rows)) {
            $_SESSION['info'] = "No active students found for Grade $grade_level - SY $school_year.";
        } else {
            $groups           = [];
            $skipped_groups   = [];
            $skipped_students = 0;

            foreach ($all_rows as $r) {
                $strand = trim((string)($r['strand'] ?? ''));
                if ($strand === '') $strand = trim((string)($r['program'] ?? ''));
                if ($strand === '') $strand = 'General';
                $groups[$strand][] = $r;
            }

            if (!$replace_existing) {
                foreach ($groups as $strand => $rows_in_group) {
                    $has_existing = false;
                    foreach ($rows_in_group as $r) {
                        if (!empty($r['section'])) { $has_existing = true; break; }
                    }
                    if ($has_existing) {
                        $skipped_groups[] = $strand;
                        $skipped_students += count($rows_in_group);
                        unset($groups[$strand]);
                    }
                }
            }

            if (empty($groups)) {
                $_SESSION['info'] = "Nothing to assign — every strand in $grade_level / $school_year already has sections. Use Replace mode to force a full reassignment.";
            } else {
                $upd = $conn->prepare("UPDATE enrollment_form SET section = ? WHERE enrollment_id = ?");
                if (!$upd) {
                    $_SESSION['error'] = "Prepare failed: " . $conn->error;
                } else {
                    $total_assigned = 0;
                    $total_sections = 0;
                    $capped_groups  = [];

                    $conn->begin_transaction();
                    try {
                        foreach ($groups as $strand => $students) {
                            $base = strandInitials($strand);
                            if ($base === '') $base = 'GEN';

                            $males = []; $females = [];
                            foreach ($students as $s) {
                                $sx = strtoupper(trim($s['sex'] ?? ''));
                                if ($sx === 'M' || $sx === 'MALE') $males[] = $s;
                                else $females[] = $s;
                            }

                            $ordered = [];
                            $mi = 0; $fi = 0;
                            $n_m = count($males); $n_f = count($females);
                            $n_total = $n_m + $n_f;
                            for ($i = 0; $i < $n_total; $i++) {
                                $pick_male = false;
                                if ($mi < $n_m && $fi < $n_f) {
                                    $pick_male = ($mi * $n_f) <= ($fi * $n_m);
                                } elseif ($mi < $n_m) {
                                    $pick_male = true;
                                }
                                $ordered[] = $pick_male ? $males[$mi++] : $females[$fi++];
                            }

                            $n = count($ordered);
                            $num_sections = (int)ceil($n / $target_size);
                            if ($num_sections < 1) $num_sections = 1;

                            if ($num_sections > $max_sections) {
                                $capped_groups[] = $base . " (needed $num_sections, capped at $max_sections)";
                                $num_sections = $max_sections;
                            }

                            $per_section = (int)ceil($n / $num_sections);

                            $idx = 0;
                            for ($sec = 0; $sec < $num_sections; $sec++) {
                                $letter = chr(65 + $sec);
                                $section_name = $base . ' ' . $letter;

                                for ($i = 0; $i < $per_section && $idx < $n; $i++, $idx++) {
                                    $upd->bind_param("si", $section_name, $ordered[$idx]['enrollment_id']);
                                    if (!$upd->execute()) {
                                        throw new Exception("Failed to update section: " . $upd->error);
                                    }
                                    $total_assigned++;
                                }
                                $total_sections++;
                            }
                        }
                        $upd->close();
                        $conn->commit();

                        $msg = "Auto-section complete: $total_assigned student(s) assigned to $total_sections section(s) for $grade_level - SY $school_year.";
                        if ($skipped_students > 0) {
                            $msg .= " Skipped " . count($skipped_groups) . " strand(s) (" . $skipped_students . " student(s)) that already had sections.";
                        }
                        if (!empty($capped_groups)) {
                            $msg .= " Capped at $max_sections sections for: " . implode(', ', $capped_groups) . ".";
                        }
                        $_SESSION['success'] = $msg;
                    } catch (Exception $e) {
                        $conn->rollback();
                        $upd->close();
                        $_SESSION['error'] = "Auto-section failed: " . $e->getMessage();
                    }
                }
            }
        }
    }
    header("Location: student_profile.php?grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year));
    exit();
}

// ────────────────────────────────────────────────
// MAIN STUDENTS QUERY — uses e.term now
// ────────────────────────────────────────────────
$sql = "SELECT s.student_id, s.student_id_number, s.lrn, s.last_name, s.first_name, s.middle_name, s.ext_name, s.sex, s.age, e.grade_level, e.school_year, e.section, e.strand, e.track, e.program, e.term, COALESCE(e.status, 'Active') AS status, COUNT(*) OVER() as total_count FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";

$params = []; $types = "";
if ($search !== '') { $sql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ? OR s.student_id_number LIKE ?)"; $like = "%$search%"; $params = [$like,$like,$like,$like]; $types = "ssss"; }
if ($grade_level !== '') { $sql .= " AND e.grade_level = ?"; $params[] = $grade_level; $types .= "s"; }
if ($school_year !== '') { $sql .= " AND e.school_year = ?"; $params[] = $school_year; $types .= "s"; }
if ($status !== '' && $status !== 'All') { $sql .= " AND COALESCE(e.status, 'Active') = ?"; $params[] = $status; $types .= "s"; }
$sql .= " ORDER BY s.last_name ASC, s.first_name ASC LIMIT ? OFFSET ?";
$params[] = $per_page; $types .= "i";
$params[] = $offset; $types .= "i";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$all_students = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_students = !empty($all_students) ? (int)$all_students[0]['total_count'] : 0;
$total_pages = ceil($total_students / $per_page);
$stmt->close();

// Gender Stats
$gsql = "SELECT SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls FROM students_info s INNER JOIN enrollment_form e ON s.student_id = e.student_id WHERE 1=1";
$gp = []; $gt = "";
if ($search !== '') { $gsql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $gp = [$like,$like,$like]; $gt = "sss"; }
if ($grade_level !== '') { $gsql .= " AND e.grade_level = ?"; $gp[] = $grade_level; $gt .= "s"; }
if ($school_year !== '') { $gsql .= " AND e.school_year = ?"; $gp[] = $school_year; $gt .= "s"; }
if ($status !== '' && $status !== 'All') { $gsql .= " AND COALESCE(e.status, 'Active') = ?"; $gp[] = $status; $gt .= "s"; }
$gs = $conn->prepare($gsql); if(!empty($gp)) $gs->bind_param($gt,...$gp); $gs->execute(); $st = $gs->get_result()->fetch_assoc(); $gs->close();
$boys = (int)($st['boys']??0); $girls = (int)($st['girls']??0);

// Section Stats
$ssql = "SELECT e.section, COUNT(*) AS total, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('MALE','M') THEN 1 ELSE 0 END) AS boys, SUM(CASE WHEN UPPER(TRIM(s.sex)) IN ('FEMALE','F') THEN 1 ELSE 0 END) AS girls FROM enrollment_form e INNER JOIN students_info s ON e.student_id = s.student_id WHERE e.section IS NOT NULL AND e.section != ''";
$sp = []; $stt = "";
if ($search !== '') { $ssql .= " AND (s.first_name LIKE ? OR s.last_name LIKE ? OR s.lrn LIKE ?)"; $sp = [$like,$like,$like]; $stt = "sss"; }
if ($grade_level !== '') { $ssql .= " AND e.grade_level = ?"; $sp[] = $grade_level; $stt .= "s"; }
if ($school_year !== '') { $ssql .= " AND e.school_year = ?"; $sp[] = $school_year; $stt .= "s"; }
if ($status !== '' && $status !== 'All') { $ssql .= " AND COALESCE(e.status, 'Active') = ?"; $sp[] = $status; $stt .= "s"; }
$ssql .= " GROUP BY e.section ORDER BY e.section ASC";
$ss = $conn->prepare($ssql); if(!empty($sp)) $ss->bind_param($stt,...$sp); $ss->execute(); $all_sections = $ss->get_result()->fetch_all(MYSQLI_ASSOC); $ss->close();

// Promote preview counts
$promote_preview_11 = 0; $promote_preview_12 = 0;
if ($school_year) {
    $ps = $conn->prepare("SELECT COUNT(*) as cnt FROM enrollment_form WHERE grade_level = 'Grade 11' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
    $ps->bind_param("s", $school_year); $ps->execute(); $pr = $ps->get_result()->fetch_assoc(); $promote_preview_11 = (int)($pr['cnt']??0); $ps->close();
    $ps = $conn->prepare("SELECT COUNT(*) as cnt FROM enrollment_form WHERE grade_level = 'Grade 12' AND school_year = ? AND (status = 'Active' OR status IS NULL)");
    $ps->bind_param("s", $school_year); $ps->execute(); $pr = $ps->get_result()->fetch_assoc(); $promote_preview_12 = (int)($pr['cnt']??0); $ps->close();
}

$show_promote_mode = isset($_GET['show_promotable']) && $school_year;

// Dropdowns
$grade_levels = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC");
$school_years = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC");
$possible_statuses = ['Active', 'Transferred', 'Stopped', 'Dropped', 'Graduated'];
$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';

$base_url = "?search=" . urlencode($search) . "&grade_level=" . urlencode($grade_level) . "&school_year=" . urlencode($school_year) . "&status=" . urlencode($status);
if ($show_promote_mode) $base_url .= "&show_promotable=1";

$next_sy = '';
$parts = explode('-', $school_year);
if (count($parts) === 2) {
    $next_sy = ((int)$parts[0] + 1) . '-' . ((int)$parts[1] + 1);
}
?>

<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Profiles • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
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
        .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1.5rem}
        .stat-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);padding:1rem 1.25rem;text-align:center;transition:all 0.3s}.stat-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);border-color:var(--accent)}.stat-value{font-size:1.6rem;font-weight:700;color:var(--accent)}.stat-label{font-size:0.72rem;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;margin-top:0.2rem}
        .card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden}
        .card-header{padding:1rem 1.5rem;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}.card-header h3{margin:0;font-size:0.95rem;font-weight:600;display:flex;align-items:center;gap:0.5rem}.card-header h3 i{color:var(--accent)}.card-body{padding:1.5rem}.card-body.no-padding{padding:0}
        .filter-bar{display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center}.filter-bar input,.filter-bar select{background:var(--bg);border:1px solid var(--border);border-radius:10px;padding:0.5rem 1rem;font-size:0.85rem;color:var(--text);min-width:160px}
        .table-admin{width:100%;border-collapse:collapse}.table-admin th{background:var(--bg);font-size:0.7rem;font-weight:700;color:var(--text2);text-transform:uppercase;letter-spacing:0.5px;padding:0.75rem 1rem;text-align:left;border-bottom:2px solid var(--border)}.table-admin td{padding:0.7rem 1rem;border-bottom:1px solid var(--border);font-size:0.85rem;vertical-align:middle}.table-admin tr:hover td{background:rgba(79,70,229,0.03)}.table-admin tr:last-child td{border-bottom:none}
        .student-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:1rem}
        .student-card{background:var(--surface);border-radius:var(--radius-lg);border:1px solid var(--border);box-shadow:var(--shadow);padding:1.25rem;transition:all 0.3s;display:flex;gap:1rem;align-items:flex-start;position:relative}.student-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--accent)}.student-card.selected{border-color:var(--amber);background:rgba(245,158,11,0.05);box-shadow:0 0 0 2px rgba(245,158,11,0.3)}.student-card .select-check{position:absolute;top:0.75rem;right:0.75rem;width:22px;height:22px;accent-color:var(--amber);cursor:pointer;z-index:2;transform:scale(1.3)}
        .student-avatar{width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;color:white;font-weight:700;font-size:1.2rem;flex-shrink:0}
        .student-info{flex:1;min-width:0}.student-info h4{font-size:0.95rem;font-weight:600;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;padding-right:30px}.student-info .meta{font-size:0.75rem;color:var(--text2);margin-top:0.25rem}.student-info .tags{display:flex;gap:0.35rem;flex-wrap:wrap;margin-top:0.5rem}
        .tag{display:inline-block;padding:0.15rem 0.5rem;border-radius:20px;font-size:0.68rem;font-weight:600;background:var(--bg);color:var(--text2)}.tag.accent{background:#eef2ff;color:#4338ca}[data-bs-theme="dark"] .tag.accent{background:#312e81;color:#a5b4fc}.tag.promotable{background:#fef3c7;color:#92400e}[data-bs-theme="dark"] .tag.promotable{background:#78350f;color:#fcd34d}.tag.initials{background:#fce7f3;color:#9d174d;font-weight:700;letter-spacing:0.5px}[data-bs-theme="dark"] .tag.initials{background:#831843;color:#fbcfe8}
        .status-dot{display:inline-flex;align-items:center;gap:0.3rem;font-size:0.72rem;font-weight:600}.status-dot::before{content:'';width:7px;height:7px;border-radius:50%}.status-dot.success::before{background:var(--green)}.status-dot.warning::before{background:var(--amber)}.status-dot.danger::before{background:var(--red)}
        .promote-bar{background:linear-gradient(135deg,#fef3c7,#fde68a);border:2px solid #f59e0b;border-radius:var(--radius);padding:0.75rem 1.25rem;margin-bottom:1rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.75rem;position:sticky;top:0;z-index:50}[data-bs-theme="dark"] .promote-bar{background:linear-gradient(135deg,#78350f,#92400e);border-color:#f59e0b;color:#fef3c7}.promote-bar .selected-count{font-weight:700;font-size:1.1rem}
        .btn-promote{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;border:none;font-weight:600;white-space:nowrap}.btn-promote:hover{background:linear-gradient(135deg,#d97706,#b45309);color:#fff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(217,119,6,0.3)}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}.theme-btn:hover{background:var(--accent);color:white;border-color:var(--accent)}.btn{font-weight:500;border-radius:8px}
        .pagination{display:flex;gap:0.35rem;align-items:center}.pagination .btn{min-width:36px}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}@media(max-width:640px){.main-content{padding:1rem}.student-grid{grid-template-columns:1fr}.filter-bar{flex-direction:column}.filter-bar input,.filter-bar select{width:100%}.promote-bar{flex-direction:column;text-align:center}}
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
    <?php if(isset($_SESSION['info'])): ?><div class="alert alert-info alert-dismissible fade show d-flex align-items-center gap-2 mb-3 rounded-3 border-0 shadow-sm"><i class="bi bi-info-circle-fill fs-5"></i> <?= $_SESSION['info'] ?><button class="btn-close" data-bs-dismiss="alert"></button></div><?php unset($_SESSION['info']); endif; ?>

    <div class="topbar">
        <div class="d-flex align-items-center gap-3"><button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button><div><h2 style="font-size:1.4rem;font-weight:700;margin:0">Student Profiles</h2><p class="text-muted small mb-0">Manage enrolled students</p></div></div>
        <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
    </div>

    <div class="stat-grid"><div class="stat-card"><div class="stat-value"><?= number_format($total_students) ?></div><div class="stat-label">Total Students</div></div><div class="stat-card"><div class="stat-value"><?= number_format($boys) ?></div><div class="stat-label">Boys</div></div><div class="stat-card"><div class="stat-value"><?= number_format($girls) ?></div><div class="stat-label">Girls</div></div><div class="stat-card"><div class="stat-value"><?= count($all_sections) ?></div><div class="stat-label">Sections</div></div></div>

    <div class="card"><div class="card-header"><h3><i class="bi bi-funnel"></i> Filters</h3></div><div class="card-body">
        <form class="filter-bar" method="GET" id="filterForm">
            <input type="hidden" name="page" value="1">
            <input type="text" name="search" placeholder="Search name, LRN, ID..." value="<?= htmlspecialchars($search) ?>">
            <select name="grade_level"><option value="">All Grades</option><?php while($gl=$grade_levels->fetch_assoc()): ?><option value="<?= htmlspecialchars($gl['grade_level']) ?>" <?= $gl['grade_level']===$grade_level?'selected':'' ?>><?= htmlspecialchars($gl['grade_level']) ?></option><?php endwhile; ?></select>
            <select name="school_year"><option value="">All Years</option><?php while($sy=$school_years->fetch_assoc()): ?><option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $sy['school_year']===$school_year?'selected':'' ?>><?= htmlspecialchars($sy['school_year']) ?></option><?php endwhile; ?></select>
            <select name="status"><option value="">All Status</option><?php foreach($possible_statuses as $st): ?><option value="<?= $st ?>" <?= $st===$status?'selected':'' ?>><?= $st ?></option><?php endforeach; ?></select>
            <button type="submit" class="btn btn-primary btn-sm">Filter</button>
            <a href="student_profile.php" class="btn btn-outline-secondary btn-sm">Reset</a>
            <button type="submit" name="auto_assign" value="1" class="btn btn-success btn-sm"><i class="bi bi-magic me-1"></i> Auto Section</button>
            <?php if ($school_year && ($promote_preview_11 > 0 || $promote_preview_12 > 0)): ?>
            <div class="dropdown">
                <button class="btn btn-promote btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown"><i class="bi bi-rocket-takeoff me-1"></i> Promote All <span class="badge bg-light text-dark ms-1"><?= $promote_preview_11 + $promote_preview_12 ?></span></button>
                <ul class="dropdown-menu shadow-lg border-0 rounded-3">
                    <?php if ($promote_preview_11 > 0): ?><li><a class="dropdown-item" href="#" onclick="showPromoteModal('grade11')"><i class="bi bi-arrow-up-circle text-warning me-2"></i> Promote Grade 11 → 12 <span class="badge bg-warning text-dark ms-2"><?= $promote_preview_11 ?></span></a></li><?php endif; ?>
                    <?php if ($promote_preview_12 > 0): ?><li><a class="dropdown-item" href="#" onclick="showPromoteModal('grade12')"><i class="bi bi-mortarboard text-success me-2"></i> Graduate Grade 12 <span class="badge bg-success ms-2"><?= $promote_preview_12 ?></span></a></li><?php endif; ?>
                    <?php if ($promote_preview_11 > 0 && $promote_preview_12 > 0): ?><li><hr class="dropdown-divider"></li><li><a class="dropdown-item" href="#" onclick="showPromoteModal('both')"><i class="bi bi-rocket-takeoff text-primary me-2"></i> Promote Both <span class="badge bg-primary ms-2"><?= $promote_preview_11 + $promote_preview_12 ?></span></a></li><?php endif; ?>
                </ul>
            </div>
            <a href="?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>&status=Active&show_promotable=1" class="btn btn-outline-warning btn-sm"><i class="bi bi-list-check me-1"></i> Select to Promote</a>
            <?php endif; ?>
        </form>
        <?php if ($school_year && ($promote_preview_11 > 0 || $promote_preview_12 > 0)): ?>
        <div class="mt-2 small text-muted"><i class="bi bi-info-circle me-1"></i> <?= $promote_preview_11 > 0 ? "<strong>$promote_preview_11</strong> Grade 11 → 12" : "" ?><?= $promote_preview_11 > 0 && $promote_preview_12 > 0 ? " • " : "" ?><?= $promote_preview_12 > 0 ? "<strong>$promote_preview_12</strong> Grade 12 → Graduated" : "" ?> ready for <strong><?= htmlspecialchars($school_year) ?></strong></div>
        <?php endif; ?>
    </div></div>

    <?php if(!empty($all_sections) && !$show_promote_mode): ?>
    <div class="card"><div class="card-header"><h3><i class="bi bi-diagram-3"></i> Sections (<?= count($all_sections) ?>)</h3></div><div class="card-body no-padding"><table class="table-admin"><thead><tr><th>Section</th><th class="text-center">Total</th><th class="text-center">Boys</th><th class="text-center">Girls</th><th class="text-center">Action</th></tr></thead><tbody><?php foreach($all_sections as $sec): ?><tr><td class="fw-bold"><?= htmlspecialchars($sec['section']) ?></td><td class="fw-semibold text-center"><?= $sec['total'] ?></td><td class="text-center" style="color:var(--blue)"><?= $sec['boys']??0 ?></td><td class="text-center" style="color:var(--red)"><?= $sec['girls']??0 ?></td><td class="text-center"><a href="sections_list.php?section=<?= urlencode($sec['section']) ?>" class="btn btn-outline-primary btn-xs"><i class="bi bi-eye me-1"></i> View</a></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-people"></i> Students (<?= $total_students ?>)</h3>
            <div class="d-flex gap-2 align-items-center">
                <?php if ($show_promote_mode): ?><button class="btn btn-outline-secondary btn-sm" onclick="selectAll()"><i class="bi bi-check-all me-1"></i> Select All</button><button class="btn btn-outline-secondary btn-sm" onclick="deselectAll()"><i class="bi bi-x-circle me-1"></i> Deselect All</button><a href="?school_year=<?= urlencode($school_year) ?>&grade_level=<?= urlencode($grade_level) ?>" class="btn btn-outline-secondary btn-sm">Cancel</a><?php endif; ?>
                <?php if ($total_pages > 1): ?><div class="pagination ms-3"><?php if ($page > 1): ?><a href="<?= $base_url ?>&page=1" class="btn btn-outline-secondary btn-xs" title="First"><i class="bi bi-chevron-double-left"></i></a><?php endif; ?><?php if ($page > 1): ?><a href="<?= $base_url ?>&page=<?= $page-1 ?>" class="btn btn-outline-secondary btn-xs"><i class="bi bi-chevron-left"></i></a><?php endif; ?><span class="text-muted small px-2">Page <?= $page ?> of <?= $total_pages ?></span><?php if ($page < $total_pages): ?><a href="<?= $base_url ?>&page=<?= $page+1 ?>" class="btn btn-outline-secondary btn-xs"><i class="bi bi-chevron-right"></i></a><?php endif; ?><?php if ($page < $total_pages): ?><a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-outline-secondary btn-xs" title="Last"><i class="bi bi-chevron-double-right"></i></a><?php endif; ?></div><?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if ($show_promote_mode): ?>
            <div class="promote-bar" id="promoteBar" style="display:none"><div><span class="selected-count" id="selectedCount">0</span> student(s) selected</div>
                <div class="d-flex gap-2 align-items-center">
                    <input type="text" id="promoteNewSY" class="form-control form-control-sm" placeholder="New SY (e.g. <?= htmlspecialchars($next_sy) ?>)" value="<?= htmlspecialchars($next_sy) ?>" style="width:160px">
                    <button class="btn btn-promote btn-sm" id="promoteSelectedBtn" disabled onclick="submitPromoteSelected()"><i class="bi bi-rocket-takeoff me-1"></i> Promote & Update SY</button>
                </div>
                <form method="POST" id="promoteForm"><input type="hidden" name="promote_school_year" value="<?= htmlspecialchars($school_year) ?>"><input type="hidden" name="new_school_year" id="promoteNewSYHidden"><div id="selectedIdsContainer"></div></form>
            </div>
            <?php endif; ?>

            <?php if($total_students > 0): ?>
            <div class="student-grid">
                <?php foreach($all_students as $s):
                    $name = htmlspecialchars(formatStudentName($s));
                    $initials = strtoupper(substr($s['first_name']??'',0,1).substr($s['last_name']??'',0,1));
                    $st = $s['status'] ?? 'Active';
                    $isPromotable = ($st === 'Active') && in_array($s['grade_level']??'', ['Grade 11', 'Grade 12']);
                    $strand_txt = $s['strand'] ?? '';
                    $strand_init = $strand_txt !== '' ? strandInitials($strand_txt) : '—';
                ?>
                <div class="student-card <?= $show_promote_mode && $isPromotable ? 'promotable-card' : '' ?>" data-student-id="<?= $s['student_id'] ?>" data-promotable="<?= $isPromotable ? '1' : '0' ?>">
                    <?php if ($show_promote_mode && $isPromotable): ?><input type="checkbox" class="select-check student-checkbox" value="<?= $s['student_id'] ?>" onchange="updateSelection()"><?php endif; ?>
                    <div class="student-avatar"><?= $initials ?></div>
                    <div class="student-info">
                        <h4><?= $name ?></h4>
                        <div class="meta">LRN: <?= htmlspecialchars($s['lrn']??'—') ?> · ID: <?= htmlspecialchars($s['student_id_number']??'—') ?></div>
                        <div class="tags">
                            <span class="tag accent"><?= htmlspecialchars($s['grade_level']??'—') ?></span>
                            <span class="tag"><?= htmlspecialchars($s['section']??'No Section') ?></span>
                            <span class="tag" title="<?= htmlspecialchars($strand_txt ?: '—') ?>"><?= htmlspecialchars($strand_txt ?: '—') ?></span>
                            <?php if ($strand_init !== '—'): ?>
                                <span class="tag initials" title="Strand initials"><?= htmlspecialchars($strand_init) ?></span>
                            <?php endif; ?>
                            <?php if ($show_promote_mode && $isPromotable): ?><span class="tag promotable"><i class="bi bi-arrow-up-circle"></i> <?= $s['grade_level']=='Grade 11'?'→ 12':'→ Grad' ?></span><?php endif; ?>
                            <span class="status-dot <?= $st=='Active'?'success':($st=='Dropped'||$st=='Graduated'?'danger':'warning') ?>"><?= $st ?></span>
                        </div>
                        <div class="mt-2"><a href="view_student.php?id=<?= $s['student_id'] ?>" class="btn btn-outline-primary btn-xs"><i class="bi bi-eye me-1"></i> View Profile</a></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($total_pages > 1): ?><div class="d-flex justify-content-center mt-4"><div class="pagination"><?php if ($page > 1): ?><a href="<?= $base_url ?>&page=1" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-double-left"></i> First</a><?php endif; ?><?php if ($page > 1): ?><a href="<?= $base_url ?>&page=<?= $page-1 ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-chevron-left"></i> Previous</a><?php endif; ?><span class="text-muted small px-3">Page <?= $page ?> of <?= $total_pages ?></span><?php if ($page < $total_pages): ?><a href="<?= $base_url ?>&page=<?= $page+1 ?>" class="btn btn-outline-secondary btn-sm">Next <i class="bi bi-chevron-right"></i></a><?php endif; ?><?php if ($page < $total_pages): ?><a href="<?= $base_url ?>&page=<?= $total_pages ?>" class="btn btn-outline-secondary btn-sm">Last <i class="bi bi-chevron-double-right"></i></a><?php endif; ?></div></div><?php endif; ?>
            <?php else: ?><div class="text-center py-5 text-muted"><i class="bi bi-people-fill display-4 d-block mb-3"></i>No students found</div><?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade" id="promoteConfirmModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow"><div class="modal-header" style="background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff"><h6 class="modal-title fw-bold"><i class="bi bi-rocket-takeoff me-2"></i>Promote Students</h6><button class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><input type="hidden" id="promoteActionType"><div class="mb-3"><label class="form-label fw-semibold small">Current School Year</label><input type="text" class="form-control bg-light" id="currentSY" readonly></div><div class="mb-3"><label class="form-label fw-semibold small">New School Year <span class="text-danger">*</span></label><input type="text" class="form-control" id="newSY" placeholder="e.g., 2026-2027" required><div class="form-text">Suggested: <strong id="suggestedSY"></strong></div></div><div class="mb-3"><label class="form-label fw-semibold small">Promotion Details</label><div id="promoteDetails" class="small text-muted"></div></div><div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i> This will promote students AND update their school year.</div></div><div class="modal-footer"><button class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button><button class="btn btn-promote btn-sm" id="confirmPromoteBtn"><i class="bi bi-rocket-takeoff me-1"></i> Promote & Update</button></div></div></div></div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle').addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));

function showPromoteModal(type){const sy='<?= addslashes($school_year) ?>',g11=<?= $promote_preview_11 ?>,g12=<?= $promote_preview_12 ?>;document.getElementById('currentSY').value=sy;document.getElementById('promoteActionType').value=type;const p=sy.split('-');if(p.length===2){const ns=(parseInt(p[0])+1)+'-'+(parseInt(p[1])+1);document.getElementById('suggestedSY').textContent=ns;document.getElementById('newSY').value=ns}let d='';if(type==='grade11')d=`<strong>${g11}</strong> Grade 11 → Grade 12`;else if(type==='grade12')d=`<strong>${g12}</strong> Grade 12 → Graduated`;else{if(g11>0)d+=`<strong>${g11}</strong> Grade 11 → Grade 12<br>`;if(g12>0)d+=`<strong>${g12}</strong> Grade 12 → Graduated`}document.getElementById('promoteDetails').innerHTML=d;new bootstrap.Modal(document.getElementById('promoteConfirmModal')).show()}
document.getElementById('confirmPromoteBtn').addEventListener('click',function(){const t=document.getElementById('promoteActionType').value,o=document.getElementById('currentSY').value,n=document.getElementById('newSY').value.trim();if(!n){alert('Please enter the new school year.');return}if(n===o){alert('New school year must be different.');return}window.location.href=`student_profile.php?promote_students=1&school_year=${encodeURIComponent(o)}&promote_type=${t}&new_school_year=${encodeURIComponent(n)}`});

function updateSelection(){const c=document.querySelectorAll('.student-checkbox:checked'),n=c.length,b=document.getElementById('promoteBar'),t=document.getElementById('promoteSelectedBtn'),l=document.getElementById('selectedCount'),p=document.getElementById('selectedIdsContainer');if(b)b.style.display=n>0?'flex':'none';if(l)l.textContent=n;if(t)t.disabled=n===0;if(p){p.innerHTML='';c.forEach(cb=>{const i=document.createElement('input');i.type='hidden';i.name='selected_students[]';i.value=cb.value;p.appendChild(i)})}document.querySelectorAll('.student-checkbox').forEach(cb=>{const cd=cb.closest('.student-card');if(cd)cd.classList.toggle('selected',cb.checked)})}
function selectAll(){document.querySelectorAll('.student-checkbox').forEach(cb=>{cb.checked=true});updateSelection()}
function deselectAll(){document.querySelectorAll('.student-checkbox').forEach(cb=>{cb.checked=false});updateSelection()}
function submitPromoteSelected(){const n=document.querySelectorAll('.student-checkbox:checked').length;if(n===0){alert('No students selected.');return}const nsy=document.getElementById('promoteNewSY').value.trim();if(!nsy){alert('Please enter the new school year.');return}document.getElementById('promoteNewSYHidden').value=nsy;if(confirm(`Promote ${n} selected student(s) to new SY ${nsy}?\n\n⚠️ Cannot undo.`))document.getElementById('promoteForm').submit()}
document.addEventListener('DOMContentLoaded',updateSelection);
</script>
</body>
</html>