<?php
session_start();
include __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// ====================== ROUTING / FILTERS ======================
$format = $_GET['format'] ?? '';          // '', 'csv', 'word'
$mode   = $_GET['mode']   ?? 'students';  // CSV mode: 'students' | 'grades'

$student_id_filter = (int)($_GET['student_id'] ?? 0);

$selected_sy    = trim($_GET['school_year']    ?? '');
$grade_filter   = trim($_GET['grade_level']    ?? '');
$strand_filter  = trim($_GET['strand_filter']  ?? '');
$section_filter = trim($_GET['section_filter'] ?? '');
$status_filter  = trim($_GET['status_filter']  ?? '');

$where  = [];
$params = [];
$types  = "";

if ($student_id_filter > 0) {
    $where[]  = "s.student_id = ?";
    $params[] = $student_id_filter;
    $types   .= "i";
} else {
    if ($selected_sy !== '')    { $where[] = "e.school_year = ?";  $params[] = $selected_sy;    $types .= "s"; }
    if ($grade_filter !== '')   { $where[] = "e.grade_level = ?"; $params[] = $grade_filter;   $types .= "s"; }
    if ($strand_filter !== '')  { $where[] = "e.strand = ?";      $params[] = $strand_filter;  $types .= "s"; }
    if ($section_filter !== '') { $where[] = "e.section = ?";     $params[] = $section_filter; $types .= "s"; }
    if ($status_filter !== '')  { $where[] = "e.status = ?";      $params[] = $status_filter;  $types .= "s"; }
}

$where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

// ====================== HELPERS ======================
function csvSafe($v) {
    $v = (string)$v;
    if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) $v = "'" . $v;
    return $v;
}

function calcGwa(array $grades) {
    $t = 0; $c = 0;
    foreach ($grades as $g) {
        if ($g['grade'] !== null && is_numeric($g['grade'])) { $t += (float)$g['grade']; $c++; }
    }
    return $c > 0 ? round($t / $c, 2) : null;
}

function fetchExportData(mysqli $conn, string $where_sql, array $params, string $types): array {
    $sql = "
        SELECT s.student_id, s.student_id_number, s.lrn,
               s.last_name, s.first_name, s.middle_name, s.nick_name, s.ext_name,
               s.sex, s.birth_date, s.age, s.civil_status, s.nationality, s.religion,
               s.height, s.weight, s.email, s.phone, s.special_skills,
               p.father_name, p.father_occupation, p.father_contact,
               p.mother_name, p.mother_maiden_name, p.mother_occupation, p.mother_contact,
               p.guardian_fullname, p.guardian_relation, p.guardian_contact,
               p.ave_family_income, p.is_4ps, p.household_id AS parent_household_id,
               a.purok_street, a.barangay, a.town_city, a.province,
               a.region, a.district, a.postal_code,
               e.enrollment_id, e.school_year, e.grade_level, e.track, e.strand, e.program,
               e.section, e.term, e.status, e.voucher_status, e.household_id
        FROM students_info s
        LEFT JOIN parents_info p ON s.student_id = p.student_id
        LEFT JOIN addresses   a ON s.student_id = a.student_id
        LEFT JOIN enrollment_form e ON s.student_id = e.student_id
        $where_sql
        ORDER BY s.last_name ASC, s.first_name ASC, e.school_year DESC, e.term ASC
    ";
    $stmt = $conn->prepare($sql);
    if (!empty($params)) $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $students = [];
    $ids = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($students[$sid])) {
            $students[$sid] = [
                'info' => [
                    'student_id'        => $r['student_id'],
                    'student_id_number' => $r['student_id_number'],
                    'lrn'               => $r['lrn'],
                    'last_name'         => $r['last_name'],
                    'first_name'        => $r['first_name'],
                    'middle_name'       => $r['middle_name'],
                    'nick_name'         => $r['nick_name'],
                    'ext_name'          => $r['ext_name'],
                    'sex'               => $r['sex'],
                    'birth_date'        => $r['birth_date'],
                    'age'               => $r['age'],
                    'civil_status'      => $r['civil_status'],
                    'nationality'       => $r['nationality'],
                    'religion'          => $r['religion'],
                    'height'            => $r['height'],
                    'weight'            => $r['weight'],
                    'email'             => $r['email'],
                    'phone'             => $r['phone'],
                    'special_skills'    => $r['special_skills'],
                    'father_name'       => $r['father_name'],
                    'father_occupation' => $r['father_occupation'],
                    'father_contact'    => $r['father_contact'],
                    'mother_name'       => $r['mother_name'],
                    'mother_maiden_name'=> $r['mother_maiden_name'],
                    'mother_occupation' => $r['mother_occupation'],
                    'mother_contact'    => $r['mother_contact'],
                    'guardian_fullname' => $r['guardian_fullname'],
                    'guardian_relation' => $r['guardian_relation'],
                    'guardian_contact'  => $r['guardian_contact'],
                    'ave_family_income' => $r['ave_family_income'],
                    'is_4ps'            => $r['is_4ps'],
                    'household_id'      => $r['parent_household_id'],
                    'purok_street'      => $r['purok_street'],
                    'barangay'          => $r['barangay'],
                    'town_city'         => $r['town_city'],
                    'province'          => $r['province'],
                    'region'            => $r['region'],
                    'district'          => $r['district'],
                    'postal_code'       => $r['postal_code'],
                ],
                'enrollments' => [],
                'grades'      => [],
            ];
            $ids[] = $sid;
        }
        if (!empty($r['enrollment_id'])) {
            $students[$sid]['enrollments'][(int)$r['enrollment_id']] = [
                'enrollment_id' => $r['enrollment_id'],
                'school_year'   => $r['school_year'],
                'grade_level'   => $r['grade_level'],
                'track'         => $r['track'],
                'strand'        => $r['strand'],
                'program'       => $r['program'],
                'section'       => $r['section'],
                'term'          => $r['term'],
                'status'        => $r['status'],
                'voucher_status'=> $r['voucher_status'],
                'household_id'  => $r['household_id'],
            ];
        }
    }

    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $gsql = "
            SELECT sg.grade_id, sg.student_id, sg.enrollment_id,
                   sg.term, sg.grade_level, sg.school_year,
                   sg.subject_code, sg.subject_name, sg.grade, sg.remarks
            FROM student_grades sg
            WHERE sg.student_id IN ($ph)
            ORDER BY sg.student_id, sg.school_year DESC, sg.grade_level ASC,
                     sg.term ASC, sg.subject_name ASC
        ";
        $gstmt = $conn->prepare($gsql);
        $gstmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $gstmt->execute();
        $gres = $gstmt->get_result();
        while ($g = $gres->fetch_assoc()) {
            $students[(int)$g['student_id']]['grades'][] = $g;
        }
        $gstmt->close();
    }

    return $students;
}

function fmtDate($d) { return $d ? date('M d, Y', strtotime($d)) : '—'; }
function nz($v) { return ($v === null || $v === '') ? '—' : $v; }

// ====================== FETCH ======================
$students = fetchExportData($conn, $where_sql, $params, $types);

$single_name = '';
$single_id_number = '';
if ($student_id_filter > 0 && !empty($students)) {
    $first = reset($students);
    $single_name = trim(($first['info']['last_name'] ?? '') . ', ' . ($first['info']['first_name'] ?? '') . ' ' . ($first['info']['middle_name'] ?? ''));
    $single_id_number = $first['info']['student_id_number'] ?? '';
}

$filter_desc = [];
if ($student_id_filter > 0) {
    $filter_desc[] = "Single student: " . ($single_name !== '' ? $single_name : "ID $student_id_filter");
} else {
    if ($selected_sy !== '')    $filter_desc[] = "School Year: $selected_sy";
    if ($grade_filter !== '')   $filter_desc[] = "Grade: $grade_filter";
    if ($strand_filter !== '')  $filter_desc[] = "Strand: $strand_filter";
    if ($section_filter !== '') $filter_desc[] = "Section: $section_filter";
    if ($status_filter !== '')  $filter_desc[] = "Status: $status_filter";
}
$filter_text = $filter_desc ? implode(' · ', $filter_desc) : 'All students';

// ====================== CSV EXPORT ======================
if ($format === 'csv') {
    set_time_limit(600);
    while (ob_get_level()) ob_end_clean();

    $fname = ($student_id_filter > 0 ? 'student_' . $student_id_filter : 'students') . '_' . $mode . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    if ($mode === 'grades') {
        fputcsv($out, [
            'Student ID Number','LRN','Last Name','First Name','Middle Name','Sex',
            'School Year','Grade Level','Term',
            'Subject Code','Subject Name','Grade','Remarks'
        ]);
        foreach ($students as $sid => $s) {
            if (empty($s['grades'])) continue;
            foreach ($s['grades'] as $g) {
                fputcsv($out, [
                    csvSafe($s['info']['student_id_number']),
                    csvSafe($s['info']['lrn']),
                    csvSafe($s['info']['last_name']),
                    csvSafe($s['info']['first_name']),
                    csvSafe($s['info']['middle_name']),
                    csvSafe($s['info']['sex']),
                    csvSafe($g['school_year']),
                    csvSafe($g['grade_level']),
                    csvSafe($g['term']),
                    csvSafe($g['subject_code']),
                    csvSafe($g['subject_name']),
                    $g['grade'] !== null ? number_format((float)$g['grade'], 2, '.', '') : '',
                    csvSafe($g['remarks']),
                ]);
            }
        }
    } else {
        fputcsv($out, [
            'Student ID Number','LRN','Last Name','First Name','Middle Name','Sex','Birth Date','Age',
            'Email','Phone',
            'Address',
            'Father Name','Father Contact','Mother (Maiden)','Mother Contact',
            'Guardian','Guardian Contact','Family Income','4Ps','Household ID',
            'School Year','Grade Level','Term','Section','Track','Strand','Program',
            'Status','Voucher Status',
            'Grade Summary','GWA'
        ]);

        foreach ($students as $sid => $s) {
            $info = $s['info'];
            $address = trim(implode(', ', array_filter([
                $info['purok_street'], $info['barangay'], $info['town_city'],
                $info['province'], $info['region'], $info['postal_code']
            ])));

            if (empty($s['enrollments'])) {
                fputcsv($out, [
                    csvSafe($info['student_id_number']), csvSafe($info['lrn']),
                    csvSafe($info['last_name']), csvSafe($info['first_name']), csvSafe($info['middle_name']),
                    csvSafe($info['sex']), csvSafe($info['birth_date']), csvSafe($info['age']),
                    csvSafe($info['email']), csvSafe($info['phone']),
                    csvSafe($address),
                    csvSafe($info['father_name']), csvSafe($info['father_contact']),
                    csvSafe($info['mother_maiden_name']), csvSafe($info['mother_contact']),
                    csvSafe($info['guardian_fullname']), csvSafe($info['guardian_contact']),
                    csvSafe($info['ave_family_income']), csvSafe($info['is_4ps']), csvSafe($info['household_id']),
                    '','','','','','','','','',
                    '', ''
                ]);
                continue;
            }

            foreach ($s['enrollments'] as $en) {
                $parts = [];
                foreach ($s['grades'] as $g) {
                    if ((int)$g['enrollment_id'] !== (int)$en['enrollment_id']) continue;
                    $code = $g['subject_code'] ?: $g['subject_name'];
                    $gv   = $g['grade'] !== null ? number_format((float)$g['grade'], 2, '.', '') : '—';
                    $parts[] = $code . ': ' . $gv;
                }
                $summary = implode(' | ', $parts);
                $gwa = calcGwa(array_filter($s['grades'], fn($g) => (int)$g['enrollment_id'] === (int)$en['enrollment_id']));

                fputcsv($out, [
                    csvSafe($info['student_id_number']), csvSafe($info['lrn']),
                    csvSafe($info['last_name']), csvSafe($info['first_name']), csvSafe($info['middle_name']),
                    csvSafe($info['sex']), csvSafe($info['birth_date']), csvSafe($info['age']),
                    csvSafe($info['email']), csvSafe($info['phone']),
                    csvSafe($address),
                    csvSafe($info['father_name']), csvSafe($info['father_contact']),
                    csvSafe($info['mother_maiden_name']), csvSafe($info['mother_contact']),
                    csvSafe($info['guardian_fullname']), csvSafe($info['guardian_contact']),
                    csvSafe($info['ave_family_income']), csvSafe($info['is_4ps']), csvSafe($info['household_id']),
                    csvSafe($en['school_year']), csvSafe($en['grade_level']), csvSafe($en['term']),
                    csvSafe($en['section']), csvSafe($en['track']), csvSafe($en['strand']),
                    csvSafe($en['program']), csvSafe($en['status']), csvSafe($en['voucher_status']),
                    csvSafe($summary),
                    $gwa !== null ? number_format($gwa, 2, '.', '') : '',
                ]);
            }
        }
    }
    fclose($out);
    exit;
}

// ====================== WORD EXPORT ======================
if ($format === 'word') {
    set_time_limit(600);
    while (ob_get_level()) ob_end_clean();

    $wfname = $student_id_filter > 0
        ? 'student_' . $student_id_filter . '_' . date('Y-m-d_His') . '.doc'
        : 'students_full_export_' . date('Y-m-d_His') . '.doc';

    header('Content-Type: application/msword; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $wfname . '"');

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Students Export</title><style>
        body{font-family:Arial,sans-serif;margin:1.5cm;color:#222;font-size:11px}
        h1{color:#1e3c72;border-bottom:3px solid #1e3c72;padding-bottom:8px;font-size:22px}
        h2{color:#2b4c8c;margin-top:24px;border-bottom:2px solid #2b4c8c;padding-bottom:4px;font-size:15px;page-break-before:always}
        h3{color:#4f46e5;margin:14px 0 6px;font-size:12px}
        table{width:100%;border-collapse:collapse;margin:6px 0 12px}
        th,td{border:1px solid #ccc;padding:5px 7px;text-align:left;font-size:10px;vertical-align:top}
        th{background:#f0f0f0;font-weight:bold}
        .meta{background:#f9f9f9;padding:8px 12px;border-left:4px solid #4f46e5;margin:10px 0;font-size:11px}
        .grades th{background:#eef2ff}
        .badge{padding:2px 8px;border-radius:10px;background:#eef2ff;color:#4338ca;font-size:9px;font-weight:bold}
    </style></head><body>';

    $title = $student_id_filter > 0 ? 'USAT College - Student Record' : 'USAT College - Student Masterlist';
    echo '<h1>' . $title . '</h1>';
    echo '<div class="meta">';
    echo '<strong>Generated:</strong> ' . date('F d, Y h:i A') . '<br>';
    echo '<strong>Scope:</strong> ' . htmlspecialchars($filter_text) . '<br>';
    echo '<strong>Total students:</strong> ' . count($students);
    echo '</div>';

    $idx = 0;
    foreach ($students as $sid => $s) {
        $idx++;
        $info = $s['info'];
        $fullName = trim(($info['last_name'] ?? '') . ', ' . ($info['first_name'] ?? '') . ' ' . ($info['middle_name'] ?? ''));

        if ($student_id_filter > 0) {
            echo '<h2>' . htmlspecialchars($fullName) . '</h2>';
        } else {
            echo '<h2>' . $idx . '. ' . htmlspecialchars($fullName) . '</h2>';
        }

        echo '<h3>Personal Information</h3><table>';
        $rows = [
            'Student ID Number' => $info['student_id_number'],
            'LRN'               => $info['lrn'],
            'Sex'               => $info['sex'],
            'Birth Date'        => fmtDate($info['birth_date']),
            'Age'               => $info['age'],
            'Civil Status'      => $info['civil_status'],
            'Nationality'       => $info['nationality'],
            'Religion'          => $info['religion'],
            'Height / Weight'   => trim(($info['height'] ?? '') . ' cm / ' . ($info['weight'] ?? '') . ' kg', ' /'),
            'Email'             => $info['email'],
            'Phone'             => $info['phone'],
            'Nickname'          => $info['nick_name'],
            'Extension'         => $info['ext_name'],
            'Special Skills'    => $info['special_skills'],
        ];
        foreach ($rows as $k => $v) echo '<tr><th style="width:22%">' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars(nz($v)) . '</td></tr>';
        echo '</table>';

        echo '<h3>Parents & Guardian</h3><table>';
        $prows = [
            'Father'            => $info['father_name'],
            'Father Occupation' => $info['father_occupation'],
            'Father Contact'    => $info['father_contact'],
            'Mother (Maiden)'   => $info['mother_maiden_name'],
            'Mother Occupation' => $info['mother_occupation'],
            'Mother Contact'    => $info['mother_contact'],
            'Guardian'          => trim(($info['guardian_fullname'] ?? '') . ' (' . ($info['guardian_relation'] ?? '') . ')', ' ()'),
            'Guardian Contact'  => $info['guardian_contact'],
            'Family Income'     => $info['ave_family_income'] ? 'PHP ' . number_format((float)$info['ave_family_income'], 2) : null,
            '4Ps'               => $info['is_4ps'] ? 'Yes' : 'No',
            'Household ID'      => $info['household_id'],
        ];
        foreach ($prows as $k => $v) echo '<tr><th style="width:22%">' . htmlspecialchars($k) . '</th><td>' . htmlspecialchars(nz($v)) . '</td></tr>';
        echo '</table>';

        $addrParts = array_filter([$info['purok_street'], $info['barangay'], $info['town_city'], $info['province'], $info['region'], $info['district'], $info['postal_code']]);
        if ($addrParts) {
            echo '<h3>Address</h3><table><tr><th style="width:22%">Full Address</th><td>' . htmlspecialchars(implode(', ', $addrParts)) . '</td></tr></table>';
        }

        if (!empty($s['enrollments'])) {
            echo '<h3>Enrollment</h3><table>';
            echo '<tr><th>School Year</th><th>Grade</th><th>Term</th><th>Section</th><th>Track</th><th>Strand</th><th>Program</th><th>Status</th><th>Voucher</th></tr>';
            foreach ($s['enrollments'] as $en) {
                echo '<tr>';
                echo '<td>' . htmlspecialchars(nz($en['school_year'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['grade_level'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['term'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['section'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['track'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['strand'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['program'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['status'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($en['voucher_status'])) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        }

        if (!empty($s['grades'])) {
            echo '<h3>Academic Record</h3>';
            $gwa = calcGwa($s['grades']);
            if ($gwa !== null) echo '<p><span class="badge">Overall GWA: ' . number_format($gwa, 2) . '</span></p>';
            echo '<table class="grades">';
            echo '<tr><th>School Year</th><th>Grade</th><th>Term</th><th>Code</th><th>Subject</th><th>Grade</th><th>Remarks</th></tr>';
            foreach ($s['grades'] as $g) {
                $gv = $g['grade'] !== null ? number_format((float)$g['grade'], 2) : '—';
                echo '<tr>';
                echo '<td>' . htmlspecialchars(nz($g['school_year'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($g['grade_level'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($g['term'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($g['subject_code'])) . '</td>';
                echo '<td>' . htmlspecialchars(nz($g['subject_name'])) . '</td>';
                echo '<td style="text-align:center;font-weight:bold">' . $gv . '</td>';
                echo '<td>' . htmlspecialchars(nz($g['remarks'])) . '</td>';
                echo '</tr>';
            }
            echo '</table>';
        } else {
            echo '<h3>Academic Record</h3><p style="color:#888;font-style:italic">No grades recorded.</p>';
        }
    }

    echo '<p style="text-align:center;color:#888;margin-top:30px;font-size:10px"><em>End of Report — ' . count($students) . ' student(s)</em></p>';
    echo '</body></html>';
    exit;
}

// ====================== DROPDOWN DATA ======================
$sy_list      = $conn->query("SELECT DISTINCT school_year FROM enrollment_form WHERE school_year IS NOT NULL AND school_year != '' ORDER BY school_year DESC")->fetch_all(MYSQLI_ASSOC) ?: [];
$grade_list   = $conn->query("SELECT DISTINCT grade_level FROM enrollment_form WHERE grade_level IS NOT NULL AND grade_level != '' ORDER BY grade_level ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$strand_list  = $conn->query("SELECT DISTINCT strand FROM enrollment_form WHERE strand IS NOT NULL AND strand != '' ORDER BY strand ASC")->fetch_all(MYSQLI_ASSOC) ?: [];
$section_list = $conn->query("SELECT DISTINCT section FROM enrollment_form WHERE section IS NOT NULL AND section != '' ORDER BY section ASC")->fetch_all(MYSQLI_ASSOC) ?: [];

$theme = isset($_COOKIE['admin_theme']) && $_COOKIE['admin_theme'] === 'dark' ? 'dark' : 'light';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="<?= $theme ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $student_id_filter > 0 ? 'Export Student' : 'Export Students' ?> • USAT Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <style>
        :root {--bg:#f0f2f5;--surface:#fff;--text:#1a1f36;--text2:#6b7280;--border:#e5e7eb;--accent:#4f46e5;--accent2:#6366f1;--green:#059669;--red:#dc2626;--shadow:0 1px 3px rgba(0,0,0,0.1);--shadow-lg:0 10px 25px rgba(0,0,0,0.08);--radius:12px;--radius-lg:16px}
        [data-bs-theme="dark"] {--bg:#0f172a;--surface:#1e293b;--text:#f1f5f9;--text2:#94a3b8;--border:#334155;--accent:#818cf8;--accent2:#6366f1}
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
        .card-body{padding:1.5rem}
        .theme-btn{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center}
        .theme-btn:hover{background:var(--accent);color:white}
        .btn{font-weight:500;border-radius:8px}
        .breadcrumb-nav{display:flex;align-items:center;gap:0.5rem;font-size:0.82rem;color:var(--text2);margin-bottom:0.25rem}
        .breadcrumb-nav a{color:var(--accent);text-decoration:none;font-weight:500}
        .export-card{display:flex;gap:1rem;padding:1.25rem;border:1px solid var(--border);border-radius:var(--radius);background:var(--surface);transition:all 0.2s;align-items:flex-start}
        .export-card:hover{border-color:var(--accent);box-shadow:var(--shadow-lg);transform:translateY(-2px)}
        .export-icon{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.5rem;flex-shrink:0}
        .icon-word{background:#dbeafe;color:#1e40af}.icon-csv{background:#d1fae5;color:#065f46}.icon-grades{background:#fef3c7;color:#92400e}
        [data-bs-theme="dark"] .icon-word{background:#1e3a5f;color:#93c5fd}
        [data-bs-theme="dark"] .icon-csv{background:#064e3b;color:#6ee7b7}
        [data-bs-theme="dark"] .icon-grades{background:#78350f;color:#fbbf24}
        .single-banner{background:linear-gradient(135deg,#4f46e5,#6366f1);color:#fff;padding:1.5rem;border-radius:var(--radius-lg);margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem}
        .single-banner .avatar{width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;flex-shrink:0}
        @media(max-width:1024px){.sidebar{transform:translateX(-100%)}.sidebar.open{transform:translateX(0)}.main-content{margin-left:0}.menu-toggle{display:flex}}
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

<div class="main-content">
    <div class="topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="menu-toggle" id="menuToggle"><i class="bi bi-list fs-5"></i></button>
            <div>
                <div class="breadcrumb-nav">
                    <a href="student_profile.php">Students</a> <i class="bi bi-chevron-right small"></i>
                    <?php if ($student_id_filter > 0): ?>
                        <a href="view_student.php?id=<?= $student_id_filter ?>"><?= htmlspecialchars($single_name ?: 'Student') ?></a> <i class="bi bi-chevron-right small"></i> Export
                    <?php else: ?>
                        Export
                    <?php endif; ?>
                </div>
                <h2 style="font-size:1.4rem;font-weight:700;margin:0">
                    <?= $student_id_filter > 0 ? 'Export Student' : 'Export All Students' ?>
                </h2>
            </div>
        </div>
        <button class="theme-btn" id="themeToggle"><i class="bi bi-moon-stars-fill" id="themeIcon"></i></button>
    </div>

    <?php if ($student_id_filter > 0 && $single_name): ?>
        <div class="single-banner">
            <div class="avatar">
                <?= htmlspecialchars(strtoupper(substr(explode(',', $single_name)[1] ?? 'S', 1, 1) . substr(explode(',', $single_name)[0] ?? 'S', 1, 1))) ?>
            </div>
            <div class="flex-grow-1">
                <h4 class="mb-1 fw-bold"><?= htmlspecialchars($single_name) ?></h4>
                <div style="opacity:0.9;font-size:0.85rem">
                    <?php if ($single_id_number !== ''): ?>
                        Student ID Number: <?= htmlspecialchars($single_id_number) ?>
                    <?php else: ?>
                        Student ID Number: —
                    <?php endif; ?>
                </div>
            </div>
            <a href="view_student.php?id=<?= $student_id_filter ?>" class="btn btn-light btn-sm">
                <i class="bi bi-arrow-left me-1"></i> Back to Profile
            </a>
        </div>
    <?php endif; ?>

    <?php if ($student_id_filter <= 0): ?>
    <div class="card">
        <div class="card-header"><h3><i class="bi bi-funnel"></i> Filters (optional)</h3></div>
        <div class="card-body">
            <form method="GET" id="filterForm" class="row g-3">
                <div class="col-md-3"><label class="form-label small fw-semibold text-muted">School Year</label>
                    <select name="school_year" class="form-select form-select-sm">
                        <option value="">All Years</option>
                        <?php foreach ($sy_list as $sy): ?>
                            <option value="<?= htmlspecialchars($sy['school_year']) ?>" <?= $selected_sy === $sy['school_year'] ? 'selected' : '' ?>><?= htmlspecialchars($sy['school_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-semibold text-muted">Grade Level</label>
                    <select name="grade_level" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($grade_list as $g): ?>
                            <option value="<?= htmlspecialchars($g['grade_level']) ?>" <?= $grade_filter === $g['grade_level'] ? 'selected' : '' ?>><?= htmlspecialchars($g['grade_level']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label small fw-semibold text-muted">Strand</label>
                    <select name="strand_filter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($strand_list as $s): ?>
                            <option value="<?= htmlspecialchars($s['strand']) ?>" <?= $strand_filter === $s['strand'] ? 'selected' : '' ?>><?= htmlspecialchars($s['strand']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-semibold text-muted">Section</label>
                    <select name="section_filter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($section_list as $s): ?>
                            <option value="<?= htmlspecialchars($s['section']) ?>" <?= $section_filter === $s['section'] ? 'selected' : '' ?>><?= htmlspecialchars($s['section']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status_filter" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach (['Active','Transferred','Stopped','Dropped','Graduated'] as $st): ?>
                            <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= $st ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 d-flex gap-2 mt-3">
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check2 me-1"></i> Apply Filters</button>
                    <a href="export_students.php" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><h3><i class="bi bi-download"></i> Choose Export Format</h3></div>
        <div class="card-body">
            <p class="text-muted small mb-3">
                <?= $student_id_filter > 0
                    ? 'Export the currently viewed student.'
                    : 'Filters above will apply to whichever format you choose.' ?>
            </p>

            <div class="row g-3">
                <div class="col-lg-6">
                    <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'word']))) ?>" class="text-decoration-none">
                        <div class="export-card">
                            <div class="export-icon icon-word"><i class="bi bi-file-earmark-word-fill"></i></div>
                            <div>
                                <h5 class="fw-bold mb-1" style="color:var(--text)">Word Document (.doc)</h5>
                                <p class="text-muted small mb-0">Full formatted report — personal info, parents, address, enrollment, and academic record. Best for printing and filing.</p>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-6">
                    <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'csv', 'mode' => 'students']))) ?>" class="text-decoration-none">
                        <div class="export-card">
                            <div class="export-icon icon-csv"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
                            <div>
                                <h5 class="fw-bold mb-1" style="color:var(--text)">CSV — Students & Grades Summary</h5>
                                <p class="text-muted small mb-0">One row per enrollment. All student info, parent info, address, enrollment details, a concatenated grade summary, and GWA.</p>
                            </div>
                        </div>
                    </a>
                </div>

                <div class="col-lg-6">
                    <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['format' => 'csv', 'mode' => 'grades']))) ?>" class="text-decoration-none">
                        <div class="export-card">
                            <div class="export-icon icon-grades"><i class="bi bi-list-columns-reverse"></i></div>
                            <div>
                                <h5 class="fw-bold mb-1" style="color:var(--text)">CSV — Grades Only (flat)</h5>
                                <p class="text-muted small mb-0">One row per grade. Ideal for pivot tables, grade analysis, or importing into another system.</p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3><i class="bi bi-people"></i> Preview</h3></div>
        <div class="card-body">
            <p class="mb-0 text-muted small">
                <?php if ($student_id_filter > 0): ?>
                    Ready to export: <strong><?= htmlspecialchars($single_name ?: "student #$student_id_filter") ?></strong>
                <?php else: ?>
                    With current filters: <strong><?= count($students) ?></strong> student(s) will be exported.
                    <br>Filters: <?= htmlspecialchars($filter_text) ?>
                <?php endif; ?>
            </p>
        </div>
    </div>
</div>

<div id="sidebarOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:199" onclick="document.getElementById('sidebar').classList.remove('open');this.style.display='none'"></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const sb=document.getElementById('sidebar'),ov=document.getElementById('sidebarOverlay');
document.getElementById('menuToggle')?.addEventListener('click',()=>{sb.classList.toggle('open');ov.style.display=sb.classList.contains('open')?'block':'none'});
const tb=document.getElementById('themeToggle'),ti=document.getElementById('themeIcon'),h=document.documentElement;
function st(t){h.setAttribute('data-bs-theme',t);ti.className='bi bi-'+(t==='dark'?'sun-fill':'moon-stars-fill');document.cookie='admin_theme='+t+';path=/;max-age='+60*60*24*365}
(function(){const m=document.cookie.match(/admin_theme=([^;]+)/);st(m?m[1]:'light')})();
tb.addEventListener('click',()=>st(h.getAttribute('data-bs-theme')==='dark'?'light':'dark'));
</script>
</body>
</html>