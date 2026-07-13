<?php

// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database only once
include_once __DIR__ . "/../config/db.php";

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
    
    // Set default values for optional fields
    $lrn = $_POST['lrn'] ?? '';
    $first_name = $_POST['first_name'] ?? '';
    $last_name = $_POST['last_name'] ?? '';
    $middle_name = !empty($_POST['middle_name']) ? $_POST['middle_name'] : null;
    $nick_name = !empty($_POST['nick_name']) ? $_POST['nick_name'] : null;
    $ext_name = !empty($_POST['ext_name']) ? $_POST['ext_name'] : null;
    $sex = $_POST['sex'] ?? '';
    $birth_date = $_POST['birth_date'] ?? '';
    $age = $_POST['age'] ?? 0;
    $civil_status = !empty($_POST['civil_status']) ? $_POST['civil_status'] : null;
    $nationality = !empty($_POST['nationality']) ? $_POST['nationality'] : null;
    $religion = !empty($_POST['religion']) ? $_POST['religion'] : null;
    $height = !empty($_POST['height']) ? floatval($_POST['height']) : null;
    $weight = !empty($_POST['weight']) ? floatval($_POST['weight']) : null;
    $email = !empty($_POST['email']) ? $_POST['email'] : null;
    $phone = !empty($_POST['phone']) ? $_POST['phone'] : null;
    $special_skills = !empty($_POST['special_skills']) ? $_POST['special_skills'] : null;
    $photo = null;
    
    $stmt->bind_param("ssssssssisssdddsss", 
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
        $photo
    );
    
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
    
    $seq_stmt->bind_param("is", $seq_start_position, $base_id);
    $seq_stmt->execute();
    $seq_stmt->bind_result($next_sequence);
    $seq_stmt->fetch();
    $seq_stmt->close();
    
    $student_id_number = $base_id . '-' . str_pad($next_sequence, 4, '0', STR_PAD_LEFT);
    
    $update_stmt = $conn->prepare("
        UPDATE students_info 
        SET student_id_number = ? 
        WHERE student_id = ?
    ");
    
    if (!$update_stmt) {
        throw new Exception("Prepare failed for student_id_number update: " . $conn->error);
    }
    
    $update_stmt->bind_param("si", $student_id_number, $student_info_id);
    
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
    
    $purok_street = !empty($_POST['purok_street']) ? $_POST['purok_street'] : null;
    $barangay = !empty($_POST['barangay']) ? $_POST['barangay'] : null;
    $town_city = !empty($_POST['town_city']) ? $_POST['town_city'] : null;
    $province = !empty($_POST['province']) ? $_POST['province'] : null;
    $region = !empty($_POST['region']) ? $_POST['region'] : null;
    $district = !empty($_POST['district']) ? $_POST['district'] : null;
    $postal_code = !empty($_POST['postal_code']) ? $_POST['postal_code'] : null;
    
    $stmt->bind_param("isssssss",
        $student_info_id,
        $purok_street,
        $barangay,
        $town_city,
        $province,
        $region,
        $district,
        $postal_code
    );
    
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
    
    $father_name = !empty($_POST['father_name']) ? $_POST['father_name'] : null;
    $father_occupation = !empty($_POST['father_occupation']) ? $_POST['father_occupation'] : null;
    $father_contact = !empty($_POST['father_contact']) ? $_POST['father_contact'] : null;
    $mother_name = !empty($_POST['mother_maiden_name']) ? $_POST['mother_maiden_name'] : null;
    $mother_maiden_name = !empty($_POST['mother_maiden_name']) ? $_POST['mother_maiden_name'] : null;
    $mother_occupation = !empty($_POST['mother_occupation']) ? $_POST['mother_occupation'] : null;
    $mother_contact = !empty($_POST['mother_contact']) ? $_POST['mother_contact'] : null;
    $ave_family_income = !empty($_POST['ave_family_income']) ? floatval($_POST['ave_family_income']) : null;
    $is_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;
    $guardian_fullname = !empty($_POST['guardian_fullname']) ? $_POST['guardian_fullname'] : null;
    $guardian_relation = !empty($_POST['guardian_relation']) ? $_POST['guardian_relation'] : null;
    $guardian_contact = !empty($_POST['guardian_contact']) ? $_POST['guardian_contact'] : null;
    $parent_household_id = !empty($_POST['household_id']) ? $_POST['household_id'] : null;
    
    $stmt->bind_param("isssssssdissis",
        $student_info_id,       // i
        $father_name,           // s
        $father_occupation,     // s
        $father_contact,        // s
        $mother_name,           // s
        $mother_maiden_name,    // s
        $mother_occupation,     // s
        $mother_contact,        // s
        $ave_family_income,     // d
        $is_4ps,                // i
        $guardian_fullname,     // s
        $guardian_relation,     // s
        $guardian_contact,      // s
        $parent_household_id    // s
    );
    
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
            if (empty($level)) continue;
            
            $school_name = !empty($_POST['school_name'][$i]) ? $_POST['school_name'][$i] : null;
            $school_address = !empty($_POST['school_address'][$i]) ? $_POST['school_address'][$i] : null;
            $year_completed = !empty($_POST['year_completed'][$i]) ? $_POST['year_completed'][$i] : null;
            
            $stmt->bind_param("issss",
                $student_info_id,
                $level,
                $school_name,
                $school_address,
                $year_completed
            );
            
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
    $household_id = !empty($_POST['household_id']) ? $_POST['household_id'] : null;
    $is_transferred = isset($_POST['is_transferred']) ? 1 : 0;
    $previous_school_name = !empty($_POST['previous_school_name']) ? $_POST['previous_school_name'] : null;
    $previous_school_address = !empty($_POST['previous_school_address']) ? $_POST['previous_school_address'] : null;
    $previous_track = !empty($_POST['previous_track']) ? $_POST['previous_track'] : null;
    $previous_strand = !empty($_POST['previous_strand']) ? $_POST['previous_strand'] : null;
    $previous_program = !empty($_POST['previous_program']) ? $_POST['previous_program'] : null;
    $previous_year_completed = !empty($_POST['previous_year_completed']) ? intval($_POST['previous_year_completed']) : null;
    $voucher_status = null;
    if (isset($_POST['voucher_qualified'])) {
        $voucher_status = ($_POST['voucher_qualified'] == '1') ? 'Qualified' : 'Not Qualified';
    }
    $cct_4ps = isset($_POST['is_4ps']) ? intval($_POST['is_4ps']) : 0;
    
    $stmt->bind_param("issssssssisssssisi",
        $student_info_id,       // i
        $school_year,           // s
        $grade_level,           // s
        $semester,              // s
        $track,                 // s
        $strand,                // s
        $program,               // s
        $section,               // s
        $household_id,          // s
        $is_transferred,        // i
        $previous_school_name,  // s
        $previous_school_address, // s
        $previous_track,        // s
        $previous_strand,       // s
        $previous_program,      // s
        $previous_year_completed, // i
        $voucher_status,        // s
        $cct_4ps                // i
    );
    
    if (!$stmt->execute()) {
        throw new Exception("Execute failed for enrollment_form: " . $stmt->error);
    }
    $stmt->close();

    // ============================================
    // 7. INSERT entrance documents
    // ============================================
    if (!empty($_POST['entrance_data']) && is_array($_POST['entrance_data'])) {
        $doc_stmt = $conn->prepare("INSERT INTO entrance_documents 
            (student_id, document_name) 
            VALUES (?, ?)");
        
        if ($doc_stmt) {
            foreach ($_POST['entrance_data'] as $document) {
                $doc_stmt->bind_param("is", $student_info_id, $document);
                $doc_stmt->execute();
            }
            $doc_stmt->close();
        }
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
    
    unset($_SESSION['form_data']);
    
    header('Location: enroll_form.php');
    exit();

} catch (Exception $e) {
    $conn->rollback();
    
    error_log("Enrollment Error: " . $e->getMessage());
    error_log("Error Trace: " . $e->getTraceAsString());
    
    $_SESSION['error'] = 'Failed to enroll student. Error: ' . $e->getMessage();
    
    header('Location: enroll_form.php');
    exit();
}
?>