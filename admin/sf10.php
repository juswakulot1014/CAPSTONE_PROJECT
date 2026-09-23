<?php
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/audit.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    $_SESSION['error'] = "Invalid student ID.";
    header("Location: student_profile.php");
    exit();
}

$student_id = (int)$_GET['id'];

// ====================== HELPERS ======================
function h($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function nz($v) { return ($v === null || $v === '') ? '—' : $v; }
function fmtDate($d) { return $d ? date('F d, Y', strtotime($d)) : '—'; }

function normalizeGradeLevel($gl) {
    $g = strtolower(trim((string)$gl));
    if ($g === '') return null;
    if (preg_match('/\b11\b/', $g) || strpos($g, '11') !== false) return 'Grade 11';
    if (preg_match('/\b12\b/', $g) || strpos($g, '12') !== false) return 'Grade 12';
    return null;
}

function normalizeTerm($t) {
    $t = strtolower(trim((string)$t));
    if ($t === '') return null;
    if (strpos($t, '1st') !== false || strpos($t, 'first')  !== false) return '1st Term';
    if (strpos($t, '2nd') !== false || strpos($t, 'second') !== false) return '2nd Term';
    if (strpos($t, '3rd') !== false || strpos($t, 'third')  !== false) return '3rd Term';
    return null;
}

function autoRemark($grade) {
    if ($grade === null || !is_numeric($grade)) return '—';
    return ((float)$grade >= 75) ? 'Passed' : 'Failed';
}

// ====================== FETCH STUDENT ======================
$sql = "
    SELECT s.student_id, s.student_id_number, s.lrn,
           s.last_name, s.first_name, s.middle_name, s.ext_name,
           s.sex, s.birth_date, s.age, s.civil_status, s.nationality, s.religion,
           a.purok_street, a.barangay, a.town_city, a.province, a.region, a.district, a.postal_code,
           p.father_name, p.mother_maiden_name, p.guardian_fullname, p.guardian_relation,
           e.previous_school_name, e.previous_school_address,
           e.previous_year_completed, e.previous_track, e.previous_strand,
           e.track, e.strand, e.program
    FROM students_info s
    LEFT JOIN addresses       a ON s.student_id = a.student_id
    LEFT JOIN parents_info    p ON s.student_id = p.student_id
    LEFT JOIN enrollment_form e ON s.student_id = e.student_id
    WHERE s.student_id = ?
    ORDER BY e.enrollment_id DESC
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    $_SESSION['error'] = "Student not found.";
    header("Location: student_profile.php");
    exit();
}

// ====================== FETCH ALL ENROLLMENTS ======================
$enrollments = [];
$en_stmt = $conn->prepare("
    SELECT enrollment_id, school_year, grade_level, term,
           track, strand, program, section
    FROM enrollment_form
    WHERE student_id = ?
    ORDER BY school_year ASC, term ASC, enrollment_id ASC
");
$en_stmt->bind_param("i", $student_id);
$en_stmt->execute();
$res = $en_stmt->get_result();
while ($row = $res->fetch_assoc()) $enrollments[(int)$row['enrollment_id']] = $row;
$en_stmt->close();

// ====================== FETCH ALL GRADES ======================
$grades_by_enrollment = [];
$gr_stmt = $conn->prepare("
    SELECT enrollment_id, term, grade_level, school_year,
           subject_code, subject_name, grade, remarks
    FROM student_grades
    WHERE student_id = ?
    ORDER BY enrollment_id ASC, FIELD(term, '1st Term', '2nd Term', '3rd Term'), subject_name ASC
");
$gr_stmt->bind_param("i", $student_id);
$gr_stmt->execute();
$res = $gr_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $grades_by_enrollment[(int)$row['enrollment_id']][] = $row;
}
$gr_stmt->close();

// ====================== BUILD SF10 STRUCTURE ======================
$sf10 = [
    'Grade 11' => [
        '1st Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '2nd Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '3rd Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
    ],
    'Grade 12' => [
        '1st Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '2nd Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '3rd Term' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
    ],
];

$allGrades = [];
foreach ($grades_by_enrollment as $en_id => $rows) {
    foreach ($rows as $g) {
        $gl = normalizeGradeLevel($g['grade_level'] ?? '');
        $tm = normalizeTerm($g['term'] ?? '');
        if ($gl === null) {
            $enRow = $enrollments[$en_id] ?? null;
            if ($enRow) $gl = normalizeGradeLevel($enRow['grade_level'] ?? '');
        }
        if ($tm === null) {
            $enRow = $enrollments[$en_id] ?? null;
            if ($enRow) $tm = normalizeTerm($enRow['term'] ?? '');
        }
        if ($gl === null || $tm === null) continue;

        $allGrades[] = [
            'grade_level' => $gl,
            'term'        => $tm,
            'school_year' => $g['school_year'] ?? ($enrollments[$en_id]['school_year'] ?? ''),
            'code'        => $g['subject_code'],
            'name'        => $g['subject_name'],
            'grade'       => $g['grade'],
            'remarks'     => $g['remarks'],
        ];
    }
}

foreach ($enrollments as $en_id => $en) {
    $gl = normalizeGradeLevel($en['grade_level'] ?? '');
    $tm = normalizeTerm($en['term'] ?? '');
    if ($gl === null || $tm === null) continue;
    $sf10[$gl][$tm]['enrollment']  = $en;
    $sf10[$gl][$tm]['school_year'] = $en['school_year'];
}

foreach ($allGrades as $g) {
    $block = &$sf10[$g['grade_level']][$g['term']];

    if (empty($block['school_year']) && !empty($g['school_year'])) {
        $block['school_year'] = $g['school_year'];
    }

    $key = !empty($g['code']) ? $g['code'] : ('__' . $g['name']);

    if (!isset($block['subjects'][$key])) {
        $block['subjects'][$key] = [
            'code'   => $g['code'],
            'name'   => $g['name'],
            'final'  => null,
            'remark' => $g['remarks'] ?? null,
        ];
    }

    if ($g['grade'] !== null && is_numeric($g['grade'])) {
        $block['subjects'][$key]['final'] = (float)$g['grade'];
    }
    if (!empty($g['remarks'])) {
        $block['subjects'][$key]['remark'] = $g['remarks'];
    }
}
unset($block);

foreach ($sf10 as $gl => &$terms) {
    foreach ($terms as $termName => &$block) {
        $finalGrades = [];
        foreach ($block['subjects'] as &$subj) {
            if ($subj['final'] !== null) $finalGrades[] = $subj['final'];
        }
        unset($subj);

        uasort($block['subjects'], function($a, $b) {
            return strcasecmp($a['code'] ?: $a['name'], $b['code'] ?: $b['name']);
        });

        $block['gwa'] = count($finalGrades) > 0
            ? round(array_sum($finalGrades) / count($finalGrades), 2)
            : null;
    }
}
unset($terms);

$allFinalGrades = [];
foreach ($sf10 as $gl => $terms) {
    foreach ($terms as $termName => $block) {
        foreach ($block['subjects'] as $subj) {
            if ($subj['final'] !== null) $allFinalGrades[] = $subj['final'];
        }
    }
}
$overall_gwa = count($allFinalGrades) > 0
    ? round(array_sum($allFinalGrades) / count($allFinalGrades), 2)
    : null;

$gradeLevelGwa = [];
foreach ($sf10 as $gl => $terms) {
    $vals = [];
    foreach ($terms as $termName => $block) {
        if ($block['gwa'] !== null) $vals[] = $block['gwa'];
    }
    $gradeLevelGwa[$gl] = count($vals) > 0
        ? round(array_sum($vals) / count($vals), 2)
        : null;
}

// Defensive alias — prevents future typos like $gradeLevelGWA (capital W)
$gradeLevelGWA = $gradeLevelGwa;

$fullName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$address = implode(', ', array_filter([
    $student['purok_street'] ?? null,
    $student['barangay']     ?? null,
    $student['town_city']    ?? null,
    $student['province']     ?? null,
])) ?: '—';

audit_log($conn, 'sf10.print', ['type'=>'student','id'=>$student_id]);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SF10-SHS — <?= h($fullName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --ink:    #000;
            --rule:   #000;
            --label:  #f2f2f2;
            --head:   #d9d9d9;
            --dark:   #2b2b2b;
            --mid:    #4a4a4a;
            --band:   #e8e8e8;
            --gold:   #fef3c7;

            /* Default: Long Bond / Philippine Folio (8.5" × 13") */
            --paper-width:  215.9mm;
            --paper-height: 330.2mm;
            --paper-pad-x:  14mm;
            --paper-pad-y:  14mm;
        }

        body.paper-long   { --paper-width: 215.9mm; --paper-height: 330.2mm; }
        body.paper-legal  { --paper-width: 215.9mm; --paper-height: 355.6mm; }
        body.paper-a4     { --paper-width: 210mm;   --paper-height: 297mm;   }
        body.paper-letter { --paper-width: 215.9mm; --paper-height: 279.4mm; }

        * { box-sizing: border-box; }

        body {
            background: #d6d6d6;
            margin: 0;
            padding: 30px 0 60px;
            font-family: 'Times New Roman', Times, serif;
            color: var(--ink);
        }

        /* ---------- Screen toolbar ---------- */
        .toolbar {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            background: rgba(255,255,255,0.97);
            border-radius: 999px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
            border: 1px solid rgba(0,0,0,0.06);
            z-index: 1000;
            font-family: system-ui, sans-serif;
        }

        .toolbar .btn-back,
        .toolbar .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 18px;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-family: inherit;
            transition: transform 0.15s ease, background 0.15s ease;
            white-space: nowrap;
        }
        .toolbar .btn-print {
            background: #111827;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.25);
        }
        .toolbar .btn-print:hover { transform: translateY(-1px); background: #1f2937; }
        .toolbar .btn-back {
            background: #f3f4f6;
            color: #111827;
        }
        .toolbar .btn-back:hover { background: #e5e7eb; }

        .toolbar .divider {
            width: 1px;
            height: 24px;
            background: #e5e7eb;
        }

        .toolbar .paper-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 2px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
        }
        .toolbar .paper-group label {
            padding-left: 12px;
            font-size: 0.78rem;
            font-weight: 600;
            color: #4b5563;
            letter-spacing: 0.3px;
        }
        .toolbar .paper-group select {
            appearance: none;
            -webkit-appearance: none;
            background: #fff url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'><path fill='%236b7280' d='M0 0l5 6 5-6z'/></svg>") no-repeat right 12px center;
            border: 1px solid #e5e7eb;
            border-radius: 999px;
            padding: 6px 30px 6px 14px;
            font-size: 0.82rem;
            font-weight: 600;
            color: #111827;
            cursor: pointer;
            font-family: inherit;
            outline: none;
            transition: border-color 0.15s ease;
        }
        .toolbar .paper-group select:hover,
        .toolbar .paper-group select:focus { border-color: #9ca3af; }

        /* ---------- Paper preview ---------- */
        .sf10-container {
            width: var(--paper-width);
            min-height: var(--paper-height);
            margin: 0 auto;
            background: #fff;
            padding: var(--paper-pad-y) var(--paper-pad-x) calc(var(--paper-pad-y) + 4mm);
            font-family: 'Times New Roman', Times, serif;
            font-size: 11.5px;
            color: var(--ink);
            line-height: 1.5;
            border: 1px solid #999;
            box-shadow: 0 20px 60px rgba(0,0,0,0.28);
            transition: width 0.2s ease, min-height 0.2s ease;
        }

        /* ---------- Header ---------- */
        .official-header {
            text-align: center;
            margin-bottom: 20px;
            padding-bottom: 14px;
            border-bottom: 3px double var(--ink);
        }
        .official-header .logo {
            width: 90px;
            height: 90px;
            object-fit: contain;
            margin-bottom: 8px;
        }
        .official-header .rep-line {
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        .official-header .school-name {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1.2px;
            margin: 5px 0 2px;
            text-transform: uppercase;
        }
        .official-header .school-addr {
            font-size: 10.5px;
            font-style: italic;
        }
        .official-header .title {
            font-size: 15.5px;
            font-weight: bold;
            letter-spacing: 1.5px;
            margin: 12px 0 3px;
        }
        .official-header .subtitle {
            font-size: 10px;
            font-style: italic;
        }

        /* ---------- Section titles ---------- */
        .section-title {
            background: var(--dark);
            color: #fff;
            font-weight: bold;
            padding: 5px 12px;
            margin: 20px 0 10px;
            font-size: 11px;
            letter-spacing: 1.2px;
            text-transform: uppercase;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
        }

        /* ---------- Tables ---------- */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        th, td {
            border: 1px solid var(--ink);
            padding: 6px 9px;
            vertical-align: top;
            font-size: 11px;
        }
        th {
            background: var(--head);
            font-weight: bold;
            text-align: center;
            font-size: 10.5px;
            letter-spacing: 0.4px;
            text-transform: uppercase;
        }
        td.label {
            background: var(--label);
            font-weight: bold;
            width: 22%;
            font-size: 10.5px;
            letter-spacing: 0.3px;
            vertical-align: middle;
        }
        td.center { text-align: center; }
        td.right  { text-align: right; }
        td.mono   { font-variant-numeric: tabular-nums; }

        /* ---------- Grade blocks ---------- */
        .grade-block {
            page-break-inside: avoid;
            margin-bottom: 18px;
            border: 2px solid var(--ink);
        }
        .grade-header {
            background: var(--mid);
            color: #fff;
            padding: 6px 14px;
            font-weight: bold;
            font-size: 12.5px;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .grade-header .grade-gwa {
            background: #fff;
            color: var(--ink);
            padding: 2px 10px;
            font-size: 10px;
            letter-spacing: 0.5px;
            border: 1px solid #fff;
        }

        .term-header {
            background: var(--band);
            padding: 5px 14px;
            font-weight: bold;
            font-size: 11px;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            border-top: 1px solid var(--ink);
            border-bottom: 1px solid var(--ink);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .term-header .sy-tag {
            font-weight: normal;
            font-style: italic;
            text-transform: none;
            letter-spacing: 0;
            font-size: 10px;
        }

        .grades-table { margin: 0; }
        .grades-table th,
        .grades-table td {
            border: 1px solid var(--ink);
        }
        .grades-table thead th {
            background: #ececec;
        }
        .grades-table tbody tr:nth-child(even) td {
            background: #fafafa;
        }

        .gwa-row td {
            background: #f2f2f2 !important;
            font-weight: bold;
            text-align: right;
            padding: 6px 10px;
            letter-spacing: 0.3px;
        }
        .gwa-row td.val {
            text-align: center;
            font-size: 12px;
        }

        .grade-footer {
            background: #e6e6e6;
            font-weight: bold;
            font-size: 11px;
            padding: 6px 10px;
            text-align: right;
            letter-spacing: 0.3px;
            border-top: 2px solid var(--ink);
        }
        .grade-footer .grade-footer-val {
            display: inline-block;
            min-width: 60px;
            text-align: center;
            background: #fff;
            border: 1px solid var(--ink);
            padding: 1px 8px;
            margin-left: 10px;
            font-size: 12px;
        }

        .empty-block {
            padding: 14px;
            text-align: center;
            color: #666;
            font-style: italic;
            font-size: 10.5px;
            background: #fafafa;
        }

        /* ---------- Overall GWA ---------- */
        .overall-table td {
            background: var(--gold) !important;
            border: 2px solid var(--ink) !important;
            font-weight: bold;
            padding: 10px 14px;
            font-size: 12px;
            letter-spacing: 0.5px;
        }
        .overall-table td:last-child {
            text-align: center;
            font-size: 14px;
            font-variant-numeric: tabular-nums;
        }

        /* ---------- Certification ---------- */
        .certification {
            text-align: center;
            font-style: italic;
            padding: 10px 20px;
            line-height: 1.6;
            font-size: 11px;
        }

        .signature-block {
            display: flex;
            justify-content: space-around;
            gap: 50px;
            margin-top: 60px;
            page-break-inside: avoid;
        }
        .sig-item { flex: 1; text-align: center; }
        .sig-line {
            border-top: 1px solid var(--ink);
            margin: 0 auto 4px;
            width: 240px;
            padding-top: 5px;
            font-weight: bold;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        .sig-label {
            font-size: 10px;
            font-style: italic;
        }

        .doc-footer {
            text-align: center;
            font-size: 9px;
            color: #666;
            margin-top: 30px;
            padding-top: 8px;
            border-top: 1px dashed #999;
            letter-spacing: 0.4px;
            font-family: system-ui, sans-serif;
        }

        /* ---------- Print ---------- */
        @media print {
            @page { size: 8.5in 13in; margin: 10mm; }
            body {
                background: #fff;
                padding: 0;
            }
            .toolbar { display: none !important; }
            .sf10-container {
                width: auto;
                min-height: auto;
                margin: 0;
                padding: 0;
                border: none;
                box-shadow: none;
            }
            .grade-block { page-break-inside: avoid; }
            .signature-block { page-break-inside: avoid; }
            .grade-header,
            .section-title,
            .term-header,
            th,
            .gwa-row td,
            .grade-footer,
            .overall-table td {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }

        @media screen and (max-width: 900px) {
            .toolbar {
                flex-wrap: wrap;
                max-width: calc(100vw - 24px);
                justify-content: center;
            }
            .sf10-container {
                width: 100%;
                padding: 20px;
            }
        }
    </style>
</head>
<body class="paper-long">

<!-- Floating toolbar -->
<div class="toolbar no-print">
    <a class="btn-back" href="view_student.php?id=<?= $student_id ?>">
        <i class="bi bi-arrow-left"></i> Back
    </a>

    <div class="divider"></div>

    <div class="paper-group">
        <label for="paperSize">Paper</label>
        <select id="paperSize" aria-label="Paper size">
            <option value="long"   selected>Long Bond · 8.5 × 13</option>
            <option value="legal"  >Legal · 8.5 × 14</option>
            <option value="a4"     >A4 · 210 × 297 mm</option>
            <option value="letter" >Short Bond · 8.5 × 11</option>
        </select>
    </div>

    <button class="btn-print" onclick="printNow()">
        <i class="bi bi-printer-fill"></i> Print
    </button>
</div>

<div class="sf10-container">

    <!-- Header -->
    <div class="official-header">
        <img src="../assets/img/usat.jpg" alt="USAT College Logo" class="logo">
        <div class="rep-line">Republic of the Philippines</div>
        <div class="rep-line">Department of Education</div>
        <div class="school-name">USAT College Sagay City, Inc.</div>
        <div class="school-addr">Sagay City, Negros Occidental</div>
        <div class="title">LEARNER'S PERMANENT ACADEMIC RECORD</div>
        <div class="subtitle">SF 10 - SHS · Senior High School (Grade 11 to Grade 12)</div>
    </div>

    <!-- Personal Info -->
    <div class="section-title">Learner's Personal Information</div>
    <table>
        <tr>
            <td class="label">LRN</td>
            <td class="mono"><?= h(nz($student['lrn'])) ?></td>
            <td class="label">Student ID No.</td>
            <td class="mono"><?= h(nz($student['student_id_number'])) ?></td>
        </tr>
        <tr>
            <td class="label">Last Name</td>
            <td><?= h(nz($student['last_name'])) ?></td>
            <td class="label">First Name</td>
            <td><?= h(nz($student['first_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Middle Name</td>
            <td><?= h(nz($student['middle_name'])) ?></td>
            <td class="label">Ext. Name</td>
            <td><?= h(nz($student['ext_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Sex</td>
            <td><?= h(nz($student['sex'])) ?></td>
            <td class="label">Date of Birth</td>
            <td><?= h(fmtDate($student['birth_date'])) ?></td>
        </tr>
        <tr>
            <td class="label">Nationality</td>
            <td><?= h(nz($student['nationality'])) ?></td>
            <td class="label">Religion</td>
            <td><?= h(nz($student['religion'])) ?></td>
        </tr>
        <tr>
            <td class="label">Address</td>
            <td colspan="3"><?= h($address) ?></td>
        </tr>
        <tr>
            <td class="label">Father</td>
            <td colspan="3"><?= h(nz($student['father_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Mother (Maiden)</td>
            <td colspan="3"><?= h(nz($student['mother_maiden_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">Guardian</td>
            <td colspan="3">
                <?= h(nz($student['guardian_fullname'])) ?>
                <?php if (!empty($student['guardian_relation'])): ?>
                    (<?= h($student['guardian_relation']) ?>)
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- Scholastic Record -->
    <div class="section-title">Scholastic Record — Senior High School</div>

    <?php foreach (['Grade 11', 'Grade 12'] as $gradeLevel): ?>
        <div class="grade-block">
            <div class="grade-header">
                <span><?= strtoupper($gradeLevel) ?></span>
                <?php if ($gradeLevelGwa[$gradeLevel] !== null): ?>
                    <span class="grade-gwa">General Average: <?= number_format($gradeLevelGwa[$gradeLevel], 2) ?></span>
                <?php endif; ?>
            </div>

            <?php foreach (['1st Term', '2nd Term', '3rd Term'] as $termName): ?>
                <?php
                $block = $sf10[$gradeLevel][$termName];
                $en    = $block['enrollment'];
                $sy    = $block['school_year'];
                $subjects = $block['subjects'];
                $hasGrades = !empty($subjects);
                ?>
                <div class="term-header">
                    <span><?= h($termName) ?></span>
                    <?php if ($sy): ?>
                        <span class="sy-tag">School Year: <?= h($sy) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasGrades): ?>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th style="width:18%">Subject Code</th>
                                <th style="width:52%">Subject Title</th>
                                <th style="width:15%">Final Grade</th>
                                <th style="width:15%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subj): ?>
                                <tr>
                                    <td class="center mono"><?= h(nz($subj['code'])) ?></td>
                                    <td><?= h(nz($subj['name'])) ?></td>
                                    <td class="center mono"><strong><?= $subj['final'] !== null ? number_format($subj['final'], 2) : '—' ?></strong></td>
                                    <td class="center"><?= h(!empty($subj['remark']) ? $subj['remark'] : autoRemark($subj['final'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="gwa-row">
                                <td colspan="2">General Average for <?= h($termName) ?>:</td>
                                <td class="val mono"><?= $block['gwa'] !== null ? number_format($block['gwa'], 2) : '—' ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-block">
                        <?= $en ? 'No grades recorded for this term.' : 'No enrollment record for this term.' ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Grade-level GWA footer — FIXED: lowercase 'w' in $gradeLevelGwa -->
            <div class="grade-footer">
                <?= strtoupper($gradeLevel) ?> GENERAL AVERAGE:
                <span class="grade-footer-val mono"><?= $gradeLevelGwa[$gradeLevel] !== null ? number_format($gradeLevelGwa[$gradeLevel], 2) : '—' ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- Overall GWA -->
    <?php if ($overall_gwa !== null): ?>
        <table class="overall-table">
            <tr>
                <td style="width:80%">OVERALL GENERAL WEIGHTED AVERAGE (Grade 11 – Grade 12)</td>
                <td><?= number_format($overall_gwa, 2) ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <!-- Certification -->
    <div class="section-title">Certification</div>
    <p class="certification">
        I hereby certify that the above information is true and correct based on the records of the school.
    </p>

    <div class="signature-block">
        <div class="sig-item">
            <div class="sig-line">SCHOOL HEAD / PRINCIPAL</div>
            <div class="sig-label">Date: <?= date('F d, Y') ?></div>
        </div>
        <div class="sig-item">
            <div class="sig-line">REGISTRAR</div>
            <div class="sig-label">USAT College Sagay City, Inc.</div>
        </div>
    </div>

    <div class="doc-footer">
        Generated on <?= date('F d, Y h:i A') ?> · SF 10 - SHS · USAT College Sagay City, Inc.
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const PAPER_SIZES = {
    long:   { page: '8.5in 13in',  w: '215.9mm', h: '330.2mm', label: 'Long Bond' },
    legal:  { page: '8.5in 14in',  w: '215.9mm', h: '355.6mm', label: 'Legal' },
    a4:     { page: '210mm 297mm', w: '210mm',   h: '297mm',   label: 'A4' },
    letter: { page: '8.5in 11in',  w: '215.9mm', h: '279.4mm', label: 'Short Bond' },
};

let currentPaper = 'long';

function applyPaperSize(sizeKey) {
    const def = PAPER_SIZES[sizeKey];
    if (!def) return;

    currentPaper = sizeKey;

    document.body.classList.remove('paper-long', 'paper-legal', 'paper-a4', 'paper-letter');
    document.body.classList.add('paper-' + sizeKey);

    const container = document.querySelector('.sf10-container');
    if (container) {
        container.style.width = def.w;
        container.style.minHeight = def.h;
    }

    let styleEl = document.getElementById('dynamic-page-style');
    if (!styleEl) {
        styleEl = document.createElement('style');
        styleEl.id = 'dynamic-page-style';
        document.head.appendChild(styleEl);
    }
    styleEl.textContent = `@media print { @page { size: ${def.page}; margin: 10mm; } }`;

    try { localStorage.setItem('sf10_paper_size', sizeKey); } catch (e) {}
}

function printNow() {
    applyPaperSize(currentPaper);
    setTimeout(() => window.print(), 80);
}

document.getElementById('paperSize').addEventListener('change', function () {
    applyPaperSize(this.value);
});

(function restorePaperSize() {
    let saved = 'long';
    try { saved = localStorage.getItem('sf10_paper_size') || 'long'; } catch (e) {}
    if (!PAPER_SIZES[saved]) saved = 'long';

    const sel = document.getElementById('paperSize');
    sel.value = saved;
    applyPaperSize(saved);
})();

document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
        e.preventDefault();
        printNow();
    }
});
</script>
</body>
</html>