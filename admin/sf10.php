<?php
session_start();
include __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

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

// Normalize grade level to "Grade 11" / "Grade 12" / null
function normalizeGradeLevel($gl) {
    $g = strtolower(trim((string)$gl));
    if ($g === '') return null;
    if (preg_match('/\b11\b/', $g) || strpos($g, '11') !== false) return 'Grade 11';
    if (preg_match('/\b12\b/', $g) || strpos($g, '12') !== false) return 'Grade 12';
    return null;
}

// Normalize semester to "1st Semester" / "2nd Semester" / null
function normalizeSemester($sem) {
    $s = strtolower(trim((string)$sem));
    if ($s === '') return null;
    if (strpos($s, '1') !== false || strpos($s, 'first') !== false) return '1st Semester';
    if (strpos($s, '2') !== false || strpos($s, 'second') !== false) return '2nd Semester';
    return null;
}

// Normalize quarter to "1st Quarter" / "2nd Quarter" / "3rd Quarter" / "4th Quarter" / null
function normalizeQuarter($q) {
    $q = strtolower(trim((string)$q));
    if ($q === '') return null;
    if (strpos($q, '1') !== false || strpos($q, 'first') !== false) return '1st Quarter';
    if (strpos($q, '2') !== false || strpos($q, 'second') !== false) return '2nd Quarter';
    if (strpos($q, '3') !== false || strpos($q, 'third') !== false) return '3rd Quarter';
    if (strpos($q, '4') !== false || strpos($q, 'fourth') !== false) return '4th Quarter';
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
    SELECT enrollment_id, school_year, grade_level, semester,
           track, strand, program, section
    FROM enrollment_form
    WHERE student_id = ?
    ORDER BY school_year ASC, semester ASC, enrollment_id ASC
");
$en_stmt->bind_param("i", $student_id);
$en_stmt->execute();
$res = $en_stmt->get_result();
while ($row = $res->fetch_assoc()) $enrollments[(int)$row['enrollment_id']] = $row;
$en_stmt->close();

// ====================== FETCH ALL GRADES ======================
$grades_by_enrollment = [];
$gr_stmt = $conn->prepare("
    SELECT enrollment_id, semester, quarter, subject_code, subject_name, grade, remarks
    FROM student_grades
    WHERE student_id = ?
    ORDER BY enrollment_id ASC, quarter ASC, subject_name ASC
");
$gr_stmt->bind_param("i", $student_id);
$gr_stmt->execute();
$res = $gr_stmt->get_result();
while ($row = $res->fetch_assoc()) {
    $grades_by_enrollment[(int)$row['enrollment_id']][] = $row;
}
$gr_stmt->close();

// ====================== BUILD SF10 STRUCTURE ======================
// Canonical 4 blocks: G11-1st, G11-2nd, G12-1st, G12-2nd
$sf10 = [
    'Grade 11' => [
        '1st Semester' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '2nd Semester' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
    ],
    'Grade 12' => [
        '1st Semester' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
        '2nd Semester' => ['enrollment' => null, 'subjects' => [], 'gwa' => null, 'school_year' => ''],
    ],
];

// Map enrollments into structure
foreach ($enrollments as $en_id => $en) {
    $gl  = normalizeGradeLevel($en['grade_level']);
    $sem = normalizeSemester($en['semester']);
    if ($gl === null || $sem === null) continue; // skip non-G11/G12 rows

    $sf10[$gl][$sem]['enrollment']   = $en;
    $sf10[$gl][$sem]['school_year']  = $en['school_year'];

    $rows = $grades_by_enrollment[$en_id] ?? [];

    // Group by subject_code (fallback to subject_name)
    $subjectMap = [];
    foreach ($rows as $g) {
        $key = !empty($g['subject_code']) ? $g['subject_code'] : ('__' . $g['subject_name']);
        if (!isset($subjectMap[$key])) {
            $subjectMap[$key] = [
                'code'      => $g['subject_code'],
                'name'      => $g['subject_name'],
                'quarters'  => [],
                'plain'     => null,
                'remark'    => $g['remarks'] ?? null,
            ];
        }
        $q = normalizeQuarter($g['quarter'] ?? '');
        if ($q !== null && $g['grade'] !== null && is_numeric($g['grade'])) {
            $subjectMap[$key]['quarters'][$q] = (float)$g['grade'];
        } elseif ($g['grade'] !== null && is_numeric($g['grade'])) {
            $subjectMap[$key]['plain'] = (float)$g['grade'];
        }
        if (!empty($g['remarks'])) $subjectMap[$key]['remark'] = $g['remarks'];
    }

    // Compute final grade per subject
    $finalGrades = [];
    foreach ($subjectMap as $key => &$subj) {
        if (!empty($subj['quarters'])) {
            $vals = array_values($subj['quarters']);
            $subj['final'] = round(array_sum($vals) / count($vals), 2);
        } elseif ($subj['plain'] !== null) {
            $subj['final'] = $subj['plain'];
        } else {
            $subj['final'] = null;
        }
        if ($subj['final'] !== null) $finalGrades[] = $subj['final'];
    }
    unset($subj);

    // Sort subjects alphabetically by code
    uasort($subjectMap, function($a, $b) {
        return strcasecmp($a['code'] ?: $a['name'], $b['code'] ?: $b['name']);
    });

    $sf10[$gl][$sem]['subjects'] = $subjectMap;
    $sf10[$gl][$sem]['gwa'] = count($finalGrades) > 0
        ? round(array_sum($finalGrades) / count($finalGrades), 2)
        : null;
}

// Overall GWA (across all 4 blocks)
$allFinalGrades = [];
foreach ($sf10 as $gradeLevel => $semesters) {
    foreach ($semesters as $semName => $block) {
        foreach ($block['subjects'] as $subj) {
            if ($subj['final'] !== null) $allFinalGrades[] = $subj['final'];
        }
    }
}
$overall_gwa = count($allFinalGrades) > 0
    ? round(array_sum($allFinalGrades) / count($allFinalGrades), 2)
    : null;

// Grade-level GWAs
$gradeLevelGwa = [];
foreach ($sf10 as $gradeLevel => $semesters) {
    $vals = [];
    foreach ($semesters as $semName => $block) {
        if ($block['gwa'] !== null) $vals[] = $block['gwa'];
    }
    $gradeLevelGwa[$gradeLevel] = count($vals) > 0
        ? round(array_sum($vals) / count($vals), 2)
        : null;
}

$fullName = trim(($student['last_name'] ?? '') . ', ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$address = implode(', ', array_filter([
    $student['purok_street'] ?? null,
    $student['barangay']     ?? null,
    $student['town_city']    ?? null,
    $student['province']     ?? null,
])) ?: '—';
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
        body { background: #e5e5e5; margin: 0; padding: 20px 0; }
        .sf10-container {
            width: 210mm;
            min-height: 297mm;
            margin: 20px auto;
            background: #fff;
            padding: 12mm 15mm;
            font-family: 'Times New Roman', Times, serif;
            font-size: 12px;
            color: #000;
            line-height: 1.4;
            box-shadow: 0 2px 12px rgba(0,0,0,0.15);
        }
        .official-header { text-align: center; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #000; }
        .logo { max-width: 90px; height: auto; margin-bottom: 6px; }
        .rep-line { font-size: 12px; }
        .school-name { font-size: 14px; font-weight: bold; margin-top: 4px; letter-spacing: 0.5px; }
        .school-addr { font-size: 11px; }
        .title { font-size: 15px; font-weight: bold; margin: 8px 0 2px; letter-spacing: 1px; }
        .subtitle { font-size: 10px; font-style: italic; }
        .section-title {
            background: #333;
            color: #fff;
            font-weight: bold;
            padding: 4px 10px;
            margin: 14px 0 6px;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #000; padding: 4px 6px; vertical-align: top; font-size: 11px; }
        th { background: #e0e0e0; font-weight: bold; text-align: center; }
        td.label { background: #f5f5f5; font-weight: bold; width: 22%; }
        td.center { text-align: center; }
        td.right { text-align: right; }
        .grade-block {
            page-break-inside: avoid;
            margin-bottom: 14px;
            border: 2px solid #000;
            padding: 0;
        }
        .grade-header {
            background: #444;
            color: #fff;
            padding: 5px 10px;
            font-weight: bold;
            font-size: 12px;
            letter-spacing: 1px;
        }
        .sem-header {
            background: #d8d8d8;
            padding: 4px 10px;
            font-weight: bold;
            font-size: 11px;
            border-top: 1px solid #000;
            border-bottom: 1px solid #000;
        }
        .sem-header .sy-tag {
            float: right;
            font-weight: normal;
            font-style: italic;
        }
        .grades-table { margin: 0; }
        .gwa-row td {
            background: #f0f0f0;
            font-weight: bold;
            text-align: right;
            padding: 5px 8px;
        }
        .gwa-row td.val {
            text-align: center;
            color: #000;
        }
        .empty-block {
            padding: 12px;
            text-align: center;
            color: #888;
            font-style: italic;
            font-size: 11px;
        }
        .overall-row {
            background: #fef3c7 !important;
            border: 2px solid #000;
            font-weight: bold;
        }
        .overall-row td { padding: 6px 10px; font-size: 12px; }
        .signature { margin-top: 45px; text-align: center; }
        .sig-line { border-top: 1px solid #000; width: 260px; margin: 6px auto 0; padding-top: 2px; font-weight: bold; }
        .sig-label { font-size: 10px; }
        .no-print { text-align: center; margin: 20px auto; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            body { background: #fff; padding: 0; }
            .sf10-container { width: auto; min-height: auto; margin: 0; padding: 0; box-shadow: none; }
            .no-print { display: none !important; }
            .grade-block { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="sf10-container">

    <!-- Header -->
    <div class="official-header">
        <img src="../assets/img/usat.jpg" alt="USAT College Logo" class="logo">
        <div class="rep-line"><strong>Republic of the Philippines</strong></div>
        <div class="rep-line"><strong>Department of Education</strong></div>
        <div class="school-name">USAT COLLEGE SAGAY CITY, INC.</div>
        <div class="school-addr">Sagay City, Negros Occidental</div>
        <div class="title">LEARNER'S PERMANENT ACADEMIC RECORD (SF 10 - SHS)</div>
        <div class="subtitle">(Senior High School — Grade 11 to Grade 12)</div>
    </div>

    <!-- Personal Info -->
    <div class="section-title">LEARNER'S PERSONAL INFORMATION</div>
    <table>
        <tr>
            <td class="label">LRN</td>
            <td><?= h(nz($student['lrn'])) ?></td>
            <td class="label">Student ID No.</td>
            <td><?= h(nz($student['student_id_number'])) ?></td>
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

    <!-- Eligibility -->
    <div class="section-title">ELIGIBILITY FOR COLLEGE ENROLLMENT</div>
    <table>
        <tr>
            <td class="label">Previous School (SHS)</td>
            <td colspan="3"><?= h(nz($student['previous_school_name'])) ?></td>
        </tr>
        <tr>
            <td class="label">School Address</td>
            <td colspan="3"><?= h(nz($student['previous_school_address'])) ?></td>
        </tr>
        <tr>
            <td class="label">Year Completed</td>
            <td><?= h(nz($student['previous_year_completed'])) ?></td>
            <td class="label">Track / Strand</td>
            <td>
                <?php
                $track_strand = trim(
                    ($student['previous_track'] ?? $student['track'] ?? '') . ' - ' .
                    ($student['previous_strand'] ?? $student['strand'] ?? $student['program'] ?? ''),
                    ' -'
                );
                echo h($track_strand ?: '—');
                ?>
            </td>
        </tr>
    </table>

    <!-- Scholastic Record -->
    <div class="section-title">SCHOLASTIC RECORD — SENIOR HIGH SCHOOL</div>

    <?php foreach (['Grade 11', 'Grade 12'] as $gradeLevel): ?>
        <div class="grade-block">
            <div class="grade-header"><?= strtoupper($gradeLevel) ?></div>

            <?php foreach (['1st Semester', '2nd Semester'] as $semName): ?>
                <?php
                $block = $sf10[$gradeLevel][$semName];
                $en    = $block['enrollment'];
                $sy    = $block['school_year'];
                $subjects = $block['subjects'];
                $hasGrades = !empty($subjects);
                ?>
                <div class="sem-header">
                    <?= h($semName) ?>
                    <?php if ($sy): ?>
                        <span class="sy-tag">SY: <?= h($sy) ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($hasGrades): ?>
                    <?php
                    // Does any subject have quarter data?
                    $hasQuarters = false;
                    foreach ($subjects as $subj) {
                        if (!empty($subj['quarters'])) { $hasQuarters = true; break; }
                    }
                    ?>
                    <table class="grades-table">
                        <thead>
                            <tr>
                                <th style="width:14%">Subject Code</th>
                                <th style="width:36%">Subject Title</th>
                                <?php if ($hasQuarters): ?>
                                    <th style="width:9%">1st Q</th>
                                    <th style="width:9%">2nd Q</th>
                                    <th style="width:9%">3rd Q</th>
                                    <th style="width:9%">4th Q</th>
                                <?php endif; ?>
                                <th style="width:10%">Final</th>
                                <th style="width:13%">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subj): ?>
                                <tr>
                                    <td class="center"><?= h(nz($subj['code'])) ?></td>
                                    <td><?= h(nz($subj['name'])) ?></td>
                                    <?php if ($hasQuarters): ?>
                                        <td class="center"><?= isset($subj['quarters']['1st Quarter']) ? number_format($subj['quarters']['1st Quarter'], 2) : '—' ?></td>
                                        <td class="center"><?= isset($subj['quarters']['2nd Quarter']) ? number_format($subj['quarters']['2nd Quarter'], 2) : '—' ?></td>
                                        <td class="center"><?= isset($subj['quarters']['3rd Quarter']) ? number_format($subj['quarters']['3rd Quarter'], 2) : '—' ?></td>
                                        <td class="center"><?= isset($subj['quarters']['4th Quarter']) ? number_format($subj['quarters']['4th Quarter'], 2) : '—' ?></td>
                                    <?php endif; ?>
                                    <td class="center"><strong><?= $subj['final'] !== null ? number_format($subj['final'], 2) : '—' ?></strong></td>
                                    <td class="center"><?= h(!empty($subj['remark']) ? $subj['remark'] : autoRemark($subj['final'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="gwa-row">
                                <td colspan="<?= $hasQuarters ? 6 : 2 ?>">General Average for <?= h($semName) ?>:</td>
                                <td class="val"><?= $block['gwa'] !== null ? number_format($block['gwa'], 2) : '—' ?></td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-block">
                        <?= $en ? 'No grades recorded for this semester.' : 'No enrollment record for this semester.' ?>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>

            <!-- Grade-level GWA -->
            <table class="grades-table" style="margin:0">
                <tr class="gwa-row" style="background:#e8e8e8">
                    <td colspan="2" style="text-align:right"><strong><?= strtoupper($gradeLevel) ?> GENERAL AVERAGE:</strong></td>
                    <td class="val" style="width:20%"><strong><?= $gradeLevelGwa[$gradeLevel] !== null ? number_format($gradeLevelGwa[$gradeLevel], 2) : '—' ?></strong></td>
                </tr>
            </table>
        </div>
    <?php endforeach; ?>

    <!-- Overall GWA -->
    <?php if ($overall_gwa !== null): ?>
        <table>
            <tr class="overall-row">
                <td style="width:80%">OVERALL GENERAL WEIGHTED AVERAGE (Grade 11 – Grade 12):</td>
                <td style="width:20%;text-align:center"><?= number_format($overall_gwa, 2) ?></td>
            </tr>
        </table>
    <?php endif; ?>

    <!-- Certification -->
    <div class="section-title">CERTIFICATION</div>
    <p style="text-align:center;padding:6px 10px;line-height:1.5">
        I hereby certify that the above information is true and correct based on the records of the school.
    </p>

    <div class="row mt-4">
        <div class="col-6">
            <div class="signature">
                <div class="sig-line">SCHOOL HEAD / PRINCIPAL</div>
                <div class="sig-label">Date: <?= date('F d, Y') ?></div>
            </div>
        </div>
        <div class="col-6">
            <div class="signature">
                <div class="sig-line">REGISTRAR</div>
                <div class="sig-label">USAT College Sagay City, Inc.</div>
            </div>
        </div>
    </div>

    <p style="text-align:center;font-size:9px;color:#666;margin-top:25px">
        Generated on <?= date('F d, Y h:i A') ?> · SF 10 - SHS
    </p>

</div>

<!-- Print buttons -->
<div class="no-print">
    <button onclick="window.print()" class="btn btn-success btn-lg px-4">
        <i class="bi bi-printer-fill me-1"></i> Print SF10-SHS
    </button>
    <a href="view_student.php?id=<?= $student_id ?>" class="btn btn-secondary btn-lg px-4 ms-2">Back to Profile</a>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>