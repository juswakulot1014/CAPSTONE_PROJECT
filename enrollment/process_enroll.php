<?php

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database only once
include_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/paths.php";

// Check if request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['error'] = 'Invalid request method.';
    header('Location: enroll_form.php');
    exit();
}

// Verify CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: enroll_form.php');
    exit();
}

/**
 * Auto-detect types and bind params safely.
 * Prevents "number of elements in type definition string" errors.
 */
function bind_params(mysqli_stmt $stmt, array $values): void
{
    $types = '';
    foreach ($values as $v) {
        $types .= match (true) {
            is_int($v)   => 'i',
            is_float($v) => 'd',
            default      => 's', // strings and nulls
        };
    }
    $stmt->bind_param($types, ...$values);
}

// Track files written to disk in case we need to clean up on rollback
$written_files = [];

// Begin transaction
$conn->begin_transaction();

try {
    // ============================================
    // 1. INSERT INTO students_info
    // ============================================
    $stmt = $conn->prepare("INSERT INTO students_info 
        (lrn, first_name, last_name, middle_name, nick_name, ext_name, sex, birth_date, age, 
         civil_status, nationality, religion, height, weight, email, phone, special_skills, photo) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception("Prepare failed for students_info: " . $conn->error);
    }

    $lrn = $_POST['lrn'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $middle_name = !empty($_POST['middle_name']) ? $_POST['middle_name'] : null;
    $nick_name = !empty($_POST['nick_name']) ? $_POST['nick_name'] : null;
    $ext_name = !empty($_POST['ext_name']) ? $_POST['ext_name'] : null;
    $sex = $_POST['sex'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $age = (int)($_POST['age'] ?? 0);
    $civil_status = !empty($_POST['civil_status']) ? $_POST['civil_status'] : null;
    $nationality = !empty($_POST['nationality']) ? $_POST['nationality'] : null;
    $religion = !empty($_POST['religion']) ? $_POST['religion'] : null;
    $height = !empty($_POST['height']) ? floatval($_POST['height']) : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
    $email = !empty($_POST['email']) ? $_POST['email'] : null;
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null;
    $special_skills = !empty($_POST['special_skills']) ? $_POST['special_skills'] : null;
    $photo = null; // No photo uploaded in this version

    bind_params($stmt, [
        $lrn,
        $first_name,
        $last_name,
        $middle_name,
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
        $photo,
    ]);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed for students_info: " . $stmt->error);
    }

    $student_info_id = $conn->insert_id;
    $stmt->close();

    // ============================================
    // 2. GENERATE STUDENT ID NUMBER
    // ============================================
    $school_year = $_POST['school_year'] ?? '';

    $school_year_parts = explode('-', $school_year);
    $school_year_short = end($school_year_parts);

    $birth_date_formatted = date('Ymd', strtotime($birth_date));

    $base_id = 'USAT-SY' . $school_year_short . $birth_date_formatted;

    $seq_stmt = $conn->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING(student_id_number, ?) AS UNSIGNED)), 0) + 1 
        FROM students_info 
        WHERE student_id_number LIKE CONCAT(?, '-%')
        FOR UPDATE
    ");

    if (!$seq_stmt) {
        throw new Exception("Prepare failed for sequence lookup: " . $conn->error);
    }

    $seq_start_position = strlen($base_id) + 2;

    bind_params($seq_stmt, [$seq_start_position, $base_id]);
    $seq_stmt->execute();
    $seq_stmt->bind_result($next_sequence);
    $seq_stmt->fetch();
    $seq_stmt->close();

    $student_id_number = $base_id . '-' . str_pad((string)$next_sequence, 4, '0', STR_PAD_LEFT);

    $update_stmt = $conn->prepare("
        UPDATE students_info 
        SET student_id_number = ? 
        WHERE student_id = ?
    ");

    if (!$update_stmt) {
        throw new Exception("Prepare failed for student_id_number update: " . $conn->error);
    }

    bind_params($update_stmt, [$student_id_number, $student_info_id]);

    if (!$update_stmt->execute()) {
        throw new Exception("Execute failed for student_id_number update: " . $update_stmt->error);
    }
    $update_stmt->close();

    // ============================================
    // 3. INSERT INTO addresses
    // ============================================
    $stmt = $conn->prepare("INSERT INTO addresses 
        (student_id, purok_street, barangay, town_city, province, region, district, postal_code) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception("Prepare failed for addresses: " . $conn->error);
    }

    $purok_street = $_POST['purok_street'] ?? null;
    $barangay = $_POST['barangay'] ?? null;
    $town_city = $_POST['town_city'] ?? null;
    $province = $_POST['province'] ?? null;
    $region = $_POST['region'] ?? null;
    $district = $_POST['district'] ?? null;
    $postal_code = $_POST['postal_code'] ?? null;

    bind_params($stmt, [
        $student_info_id,
        $purok_street,
        $barangay,
        $town_city,
        $province,
        $region,
        $district,
        $postal_code,
    ]);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed for addresses: " . $stmt->error);
    }
    $stmt->close();

    // ============================================
    // 4. INSERT INTO parents_info
    // ============================================
    $stmt = $conn->prepare("INSERT INTO parents_info 
        (student_id, father_name, father_occupation, father_contact, mother_name,
         mother_maiden_name, mother_occupation, mother_contact, ave_family_income, 
         is_4ps, guardian_fullname, guardian_relation, guardian_contact, household_id) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

    if (!$stmt) {
        throw new Exception("Prepare failed for parents_info: " . $conn->error);
    }

    $father_name = $_POST['father_name'] ?? null;
    $father_occupation = $_POST['father_occupation'] ?? null;
    $father_contact = $_POST['father_contact'] ?? null;
    $mother_name = $_POST['mother_maiden_name'] ?? null;
    $mother_maiden_name = $_POST['mother_maiden_name'] ?? null;
    $mother_occupation = $_POST['mother_occupation'] ?? null;
    $mother_contact = $_POST['mother_contact'] ?? null;
    $ave_family_income = !empty($_POST['ave_family_income']) ? floatval($_POST['ave_family_income']) : null;
    $is_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;
    $guardian_fullname = $_POST['guardian_fullname'] ?? null;
    $guardian_relation = $_POST['guardian_relation'] ?? null;
    $guardian_contact = $_POST['guardian_contact'] ?? null;
    $parent_household_id = $_POST['household_id'] ?? null;

    bind_params($stmt, [
        $student_info_id,
        $father_name,
        $father_occupation,
        $father_contact,
        $mother_name,
        $mother_maiden_name,
        $mother_occupation,
        $mother_contact,
        $ave_family_income,
        $is_4ps,
        $guardian_fullname,
        $guardian_relation,
        $guardian_contact,
        $parent_household_id,
    ]);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed for parents_info: " . $stmt->error);
    }
    $stmt->close();

    // ============================================
    // 5. INSERT INTO educational_history
    // ============================================
    if (isset($_POST['edu_level']) && is_array($_POST['edu_level']) && count($_POST['edu_level']) > 0) {
        $stmt = $conn->prepare("INSERT INTO educational_history 
            (student_id, level, school_name, school_address, year_completed) 
            VALUES (?, ?, ?, ?, ?)");

        if (!$stmt) {
            throw new Exception("Prepare failed for educational_history: " . $conn->error);
        }

        foreach ($_POST['edu_level'] as $i => $level) {
            if (empty($level)) {
                continue;
            }

            $school_name = $_POST['school_name'][$i] ?? null;
            $school_address = $_POST['school_address'][$i] ?? null;
            $year_completed = $_POST['year_completed'][$i] ?? null;

            bind_params($stmt, [
                $student_info_id,
                $level,
                $school_name,
                $school_address,
                $year_completed,
            ]);

            if (!$stmt->execute()) {
                throw new Exception("Execute failed for educational_history entry $i: " . $stmt->error);
            }
        }
        $stmt->close();
    }

    // ============================================
    // 6. INSERT INTO enrollment_form
    // ============================================
    $stmt = $conn->prepare("INSERT INTO enrollment_form 
        (student_id, school_year, grade_level, semester, track, strand, program, section,
         household_id, is_transferred, previous_school_name, previous_school_address, 
         previous_track, previous_strand, previous_program, previous_year_completed, 
         voucher_status, cct_4ps, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')");

    if (!$stmt) {
        throw new Exception("Prepare failed for enrollment_form: " . $conn->error);
    }

    $grade_level = $_POST['grade_level'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $track = $_POST['track'] ?? 'TECHPRO ELECTIVES';
    $strand = $_POST['strand'] ?? '';
    $program = $_POST['program'] ?? '';
    $section = !empty($_POST['section']) ? $_POST['section'] : $strand;
    $household_id = $_POST['household_id'] ?? null;
    $is_transferred = isset($_POST['is_transferred']) ? 1 : 0;
    $previous_school_name = $_POST['previous_school_name'] ?? null;
    $previous_school_address = $_POST['previous_school_address'] ?? null;
    $previous_track = $_POST['previous_track'] ?? null;
    $previous_strand = $_POST['previous_strand'] ?? null;
    $previous_program = $_POST['previous_program'] ?? null;
    $previous_year_completed = !empty($_POST['previous_year_completed']) ? intval($_POST['previous_year_completed']) : null;
    $voucher_status = null;
    if (isset($_POST['voucher_qualified'])) {
        $voucher_status = ($_POST['voucher_qualified'] == '1') ? 'Qualified' : 'Not Qualified';
    }
    $cct_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;

    bind_params($stmt, [
        $student_info_id,
        $school_year,
        $grade_level,
        $semester,
        $track,
        $strand,
        $program,
        $section,
        $household_id,
        $is_transferred,
        $previous_school_name,
        $previous_school_address,
        $previous_track,
        $previous_strand,
        $previous_program,
        $previous_year_completed,
        $voucher_status,
        $cct_4ps,
    ]);

    if (!$stmt->execute()) {
        throw new Exception("Execute failed for enrollment_form: " . $stmt->error);
    }
    $stmt->close();

    // ============================================
    // 7. INSERT entrance document checkboxes (no file)
    // ============================================
    if (!empty($_POST['entrance_data']) && is_array($_POST['entrance_data'])) {
        $doc_stmt = $conn->prepare("INSERT INTO entrance_documents 
            (student_id, document_name) 
            VALUES (?, ?)");

        if ($doc_stmt) {
            foreach ($_POST['entrance_data'] as $document) {
                bind_params($doc_stmt, [$student_info_id, $document]);
                $doc_stmt->execute();
            }
            $doc_stmt->close();
        }
    }

    // ============================================
    // 8. SAVE UPLOADED FILES TO PRIVATE STORAGE (disk, not BLOB)
    // ============================================
    if (!empty($_SESSION['uploaded_files']) && is_array($_SESSION['uploaded_files'])) {

        // Safety limit: don't allow more than 10 files per enrollment
        if (count($_SESSION['uploaded_files']) > 10) {
            throw new Exception("Too many files uploaded (max 10).");
        }

        $uploaded_by = $_SESSION['user_id'] ?? null;

        if ($uploaded_by !== null) {
            $file_stmt = $conn->prepare("INSERT INTO entrance_documents 
                (student_id, document_name, submitted, uploaded_at, file_path, file_mime, file_name, uploaded_by) 
                VALUES (?, ?, 1, NOW(), ?, ?, ?, ?)");
        } else {
            $file_stmt = $conn->prepare("INSERT INTO entrance_documents 
                (student_id, document_name, submitted, uploaded_at, file_path, file_mime, file_name) 
                VALUES (?, ?, 1, NOW(), ?, ?, ?)");
        }

        if (!$file_stmt) {
            throw new Exception("Prepare failed for file insert: " . $conn->error);
        }

        // Allowed MIME types (real content, not client-declared)
        $allowed_mime = [
            'application/pdf'  => 'pdf',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
        ];

        $max_file_bytes = 5 * 1024 * 1024; // 5 MB

        if (!is_dir(DOCUMENTS_DIR)) {
            mkdir(DOCUMENTS_DIR, 0755, true);
        }

        foreach ($_SESSION['uploaded_files'] as $file) {
            $label       = $file['label'] ?? 'Document';
            $data        = $file['data']  ?? '';
            $declared_mime = $file['mime'] ?? '';
            $name        = $file['name']  ?? $label;

            // Decode base64
            $binary_data = base64_decode($data, true);
            if ($binary_data === false || $binary_data === '') {
                continue; // skip invalid
            }

            // Size cap
            if (strlen($binary_data) > $max_file_bytes) {
                throw new Exception("File too large: " . $label . " (max 5 MB).");
            }

            // Write to temp, then verify MIME from content
            $tmp = tempnam(sys_get_temp_dir(), 'enroll_');
            if ($tmp === false) {
                throw new Exception("Cannot create temp file.");
            }
            if (file_put_contents($tmp, $binary_data) === false) {
                @unlink($tmp);
                throw new Exception("Failed to stage file: " . $label);
            }

            $fi = finfo_open(FILEINFO_MIME_TYPE);
            $real_mime = finfo_file($fi, $tmp);
            finfo_close($fi);

            if (!isset($allowed_mime[$real_mime])) {
                @unlink($tmp);
                throw new Exception("Invalid file type for '{$label}': {$real_mime}. Only PDF, JPG, PNG allowed.");
            }

            // Random, unguessable filename
            $ext = $allowed_mime[$real_mime];
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Exception $e) {
                $token = bin2hex(openssl_random_pseudo_bytes(16));
            }
            $filename = "enroll_{$student_info_id}_{$token}.{$ext}";
            $abs_path = DOCUMENTS_DIR . $filename;

            if (!rename($tmp, $abs_path)) {
                @unlink($tmp);
                throw new Exception("Failed to save file: " . $label);
            }

            // Track for rollback cleanup
            $written_files[] = $abs_path;

            // Sanitize original filename for storage
            $safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $name);
            if ($safe_name === '' || $safe_name === null) {
                $safe_name = $filename;
            }

            if ($uploaded_by !== null) {
                bind_params($file_stmt, [
                    $student_info_id,
                    $label,
                    $filename,
                    $real_mime,
                    $safe_name,
                    (int)$uploaded_by,
                ]);
            } else {
                bind_params($file_stmt, [
                    $student_info_id,
                    $label,
                    $filename,
                    $real_mime,
                    $safe_name,
                ]);
            }

            if (!$file_stmt->execute()) {
                throw new Exception("Execute failed for file insert: " . $file_stmt->error);
            }
        }
        $file_stmt->close();
    }

    // ============================================
    // COMMIT TRANSACTION
    // ============================================
    $conn->commit();

    $_SESSION['success'] = true;
    $_SESSION['success_message'] = "Student successfully enrolled!<br><br>
        <strong>Student ID:</strong> " . htmlspecialchars($student_id_number) . "<br>
        <strong>LRN:</strong> " . htmlspecialchars($lrn) . "<br>
        <strong>Name:</strong> " . htmlspecialchars($first_name . ' ' . $last_name) . "<br>
        <strong>Birth Date:</strong> " . htmlspecialchars($birth_date) . "<br>
        <strong>School Year:</strong> " . htmlspecialchars($school_year) . "<br>
        <strong>Grade Level:</strong> " . htmlspecialchars($grade_level) . "<br>
        <strong>Strand:</strong> " . htmlspecialchars($strand);

    // Clear session data after successful enrollment
    unset($_SESSION['form_data']);
    unset($_SESSION['uploaded_files']);

    header('Location: enroll_form.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();

    // Clean up any files written to disk during this failed enrollment
    foreach ($written_files as $abs) {
        if (file_exists($abs)) {
            @unlink($abs);
        }
    }

    error_log("Enrollment Error: " . $e->getMessage());
    error_log("Error Trace: " . $e->getTraceAsString());

    $_SESSION['error'] = 'Failed to enroll student. Error: ' . $e->getMessage();

    header('Location: enroll_form.php');
    exit();
}