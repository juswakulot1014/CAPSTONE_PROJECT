<?php
// Start session only if not already active
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database only once
include_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/paths.php";

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['lang'])) {
    $browserLang = substr($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 0, 2);
    $_SESSION['lang'] = in_array($browserLang, ['tl', 'fil']) ? 'fil' : 'en';
}
if (isset($_GET['lang'])) $_SESSION['lang'] = $_GET['lang'];
$lang = $_SESSION['lang'];

$t = [
    'en' => [
        'school' => 'USAT COLLEGE SAGAY CITY INC.',
        'form' => 'Student Enrollment Form',
        'ay' => 'Academic Year 2026–2027',
        'student' => 'Student Information',
        'parents' => 'Parents & Guardian Information',
        'address' => 'Complete Home Address',
        'education' => 'Educational History',
        'enrollment' => 'Enrollment Details',
        'voucher_question' => 'Are you a CCT/4Ps recipient? *',
        'yes' => 'YES', 'no' => 'NO',
        'entrance_data' => 'Entrance Data Presented (select at least one) *',
        'upload_docs' => 'Upload or Capture Entrance Documents',
        'choose_file' => 'Choose file',
        'no_file' => 'No file chosen',
        'capture' => 'Capture Photo',
        'good_moral' => 'Good Moral Certificate',
        'jhs_certificate' => 'Junior High School Certificate (Original)',
        'birth_certificate' => 'NSO/PSA Birth Certificate (Original)',
        'pictures_2x2' => '2 pcs. 2×2 picture',
        'lrn' => 'LRN *', 'lname' => 'Last Name *', 'fname' => 'First Name *', 'mname' => 'Middle Name',
        'nick' => 'Nickname', 'ext' => 'Extension Name', 'sex' => 'Sex *',
        'male' => 'Male', 'female' => 'Female',
        'bdate' => 'Birth Date *', 'age' => 'Age *',
        'civil' => 'Civil Status', 'nation' => 'Nationality', 'religion' => 'Religion',
        'height' => 'Height (cm)', 'weight' => 'Weight (kg)',
        'email' => 'Email', 'phone' => 'Phone',
        'skills' => 'Special Skills',
        'father' => 'Father Name',
        'fjob' => 'Father Occupation',
        'fcontact' => 'Father Contact Number',
        'mother' => 'Mother Maiden Name',
        'mjob' => 'Mother Occupation',
        'mcontact' => 'Mother Contact Number',
        'income' => 'Family Income',
        'guardian' => 'Guardian Information',
        'guardian_name' => 'Guardian Full Name',
        'guardian_relation' => 'Relationship to Student',
        'guardian_contact' => 'Guardian Contact Number',
        'street' => 'Purok / Street', 'barangay' => 'Barangay', 'city' => 'Town / City',
        'province' => 'Province', 'region' => 'Region', 'district' => 'District', 'postal' => 'Postal Code',
        'elem' => 'Elementary', 'jhs' => 'Junior High School', 'trans' => 'Transferred',
        'school_name' => 'School Name', 'school_addr' => 'School Address', 'year_comp' => 'Year Completed',
        'add_school' => 'Add School', 'sy' => 'School Year *', 'grade' => 'Grade Level *',
        'semester' => 'Semester *', 'track' => 'Track', 'strand' => 'Strand *', 'program' => 'Program *',
        'household' => 'Household ID',
        'submit' => 'Submit Enrollment', 'back' => 'Back',
        'required' => 'This field is required',
        'invalid_lrn' => 'LRN must be 12 digits',
        'invalid_name' => 'Invalid name format',
        'invalid_age' => 'Age must be between 14 and 25',
        'invalid_email' => 'Invalid email format',
        'invalid_phone' => 'Phone must be 11 digits (09XXXXXXXXX)',
        'select_one_entrance' => 'Please select at least one entrance document',
        'transferred_in' => 'Transferred IN from another school',
        'prev_school_name' => 'Name of Previous School',
        'prev_school_address' => 'Address of Previous School',
        'prev_track' => 'Previous TRACK',
        'prev_strand' => 'Previous STRAND',
        'prev_program' => 'Previous PROGRAM',
        'prev_year_completed' => 'Year Completed (Previous School)',
        'voucher_qualified' => 'Voucher Status (for transferee)',
        'qualified_voucher' => 'Qualified Voucher Recipient',
        'not_qualified_voucher' => 'Not Qualified Voucher Recipient',
    ],
    'fil' => [
        'school' => 'USAT Senior High School',
        'form' => 'Porma ng Pagpaparehistro ng Mag-aaral',
        'ay' => 'Taong Panuruan 2025–2026',
        'student' => 'Impormasyon ng Mag-aaral',
        'parents' => 'Impormasyon ng mga Magulang at Guardian',
        'address' => 'Tirahan',
        'education' => 'Kasaysayang Pang-edukasyon',
        'enrollment' => 'Detalye ng Pag-enroll',
        'voucher_question' => 'Ikaw ba ay tumatanggap ng CCT/4Ps? *',
        'yes' => 'OO', 'no' => 'HINDI',
        'entrance_data' => 'Datos na Iniharap sa Pagpasok (pumili ng kahit isa) *',
        'upload_docs' => 'Mag-upload o Kumuha ng Larawan ng mga Dokumento',
        'choose_file' => 'Pumili ng file',
        'no_file' => 'Walang napiling file',
        'capture' => 'Kumuha ng Larawan',
        'good_moral' => 'Sertipiko ng Mabuting Asal',
        'jhs_certificate' => 'Sertipiko mula sa Junior High School (Orihinal)',
        'birth_certificate' => 'NSO/PSA Sertipiko ng Kapanganakan (Orihinal)',
        'pictures_2x2' => '2 piraso na 2×2 larawan',
        'lrn' => 'LRN *', 'lname' => 'Apelyido *', 'fname' => 'Pangalan *', 'mname' => 'Gitnang Pangalan',
        'nick' => 'Palayaw', 'ext' => 'Karugtong ng Pangalan', 'sex' => 'Kasarian *',
        'male' => 'Lalaki', 'female' => 'Babae',
        'bdate' => 'Araw ng Kapanganakan *', 'age' => 'Edad *',
        'civil' => 'Katayuang Sibil', 'nation' => 'Nasyonalidad', 'religion' => 'Relihiyon',
        'height' => 'Taas (cm)', 'weight' => 'Timbang (kg)',
        'email' => 'Email', 'phone' => 'Telepono',
        'skills' => 'Espesyal na Kasanayan',
        'father' => 'Pangalan ng Ama',
        'fjob' => 'Hanapbuhay ng Ama',
        'fcontact' => 'Numero ng Contact ng Ama',
        'mother' => 'Apelyido ng Ina sa Dalaga',
        'mjob' => 'Hanapbuhay ng Ina',
        'mcontact' => 'Numero ng Contact ng Ina',
        'income' => 'Kita ng Pamilya',
        'guardian' => 'Impormasyon ng Guardian',
        'guardian_name' => 'Buong Pangalan ng Guardian',
        'guardian_relation' => 'Relasyon sa Mag-aaral',
        'guardian_contact' => 'Numero ng Contact ng Guardian',
        'street' => 'Purok / Kalye', 'barangay' => 'Barangay', 'city' => 'Bayan / Lungsod',
        'province' => 'Lalawigan', 'region' => 'Rehiyon', 'district' => 'Distrito', 'postal' => 'Postal Code',
        'elem' => 'Elementarya', 'jhs' => 'Junior High School', 'trans' => 'Lumipat',
        'school_name' => 'Pangalan ng Paaralan', 'school_addr' => 'Address ng Paaralan', 'year_comp' => 'Taong Natapos',
        'add_school' => 'Magdagdag ng Paaralan', 'sy' => 'Taon ng Paaralan *', 'grade' => 'Antas ng Baitang *',
        'semester' => 'Semestre *', 'track' => 'Track', 'strand' => 'Strand *', 'program' => 'Programa *',
        'household' => 'Household ID',
        'submit' => 'Ipasa ang Pagpaparehistro', 'back' => 'Bumalik',
        'required' => 'Kailangan ang patlang na ito',
        'invalid_lrn' => 'Ang LRN ay dapat 12 digits',
        'invalid_name' => 'Hindi wasto ang format ng pangalan',
        'invalid_age' => 'Ang edad ay dapat nasa pagitan ng 14 at 25',
        'invalid_email' => 'Hindi wasto ang format ng email',
        'invalid_phone' => 'Ang telepono ay dapat 11 digits (09XXXXXXXXX)',
        'select_one_entrance' => 'Pumili ng kahit isang dokumento sa pagpasok',
        'transferred_in' => 'Lumipat mula sa ibang paaralan (Transferred IN)',
        'prev_school_name' => 'Pangalan ng Dating Paaralan',
        'prev_school_address' => 'Address ng Dating Paaralan',
        'prev_track' => 'Dating TRACK',
        'prev_strand' => 'Dating STRAND',
        'prev_program' => 'Dating PROGRAMA',
        'prev_year_completed' => 'Taong Natapos sa Dating Paaralan',
        'voucher_qualified' => 'Katayuan ng Voucher (para sa lumipat)',
        'qualified_voucher' => 'Qualified Voucher Recipient',
        'not_qualified_voucher' => 'Hindi Qualified Voucher Recipient',
    ]
];

$errors = [];
$fields = [
    'lrn' => ['required' => true, 'pattern' => '/^\d{12}$/', 'msg' => 'invalid_lrn'],
    'last_name' => ['required' => true, 'pattern' => '/^[A-Za-z\s\-\']{2,}$/', 'msg' => 'invalid_name'],
    'first_name' => ['required' => true, 'pattern' => '/^[A-Za-z\s\-\']{2,}$/', 'msg' => 'invalid_name'],
    'sex' => ['required' => true],
    'birth_date' => ['required' => true],
    'school_year' => ['required' => true],
    'grade_level' => ['required' => true],
    'semester' => ['required' => true],
    'strand' => ['required' => true],
    'program' => ['required' => true],
    'is_4ps' => ['required' => true]
];

// ── NEW HELPERS ──────────────────────────────────────────────────────
/**
 * Delete staged upload files older than 2 hours.
 * Cheap garbage collection — runs on every POST.
 */
function purge_old_staging(): void
{
    if (!is_dir(STAGING_DIR)) return;
    $now = time();
    foreach (glob(STAGING_DIR . '*') as $f) {
        if (is_file($f) && ($now - filemtime($f)) > 7200) {
            @unlink($f);
        }
    }
}

/**
 * Move an uploaded file into the staging directory.
 * Returns metadata array on success, null on failure ($err is populated).
 */
function stage_file(string $tmp_path, string $orig_name, string $label, ?string &$err = null): ?array
{
    $max = 5 * 1024 * 1024;

    $size = @filesize($tmp_path);
    if ($size === false || $size === 0) { $err = "Empty file"; return null; }
    if ($size > $max) { $err = "File too large: " . htmlspecialchars($orig_name); return null; }

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $tmp_path);
    finfo_close($fi);

    $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mime, $allowed, true)) {
        $err = "Invalid file type: " . htmlspecialchars($orig_name);
        return null;
    }

    $token = bin2hex(random_bytes(16));
    $ext = strtolower(pathinfo($orig_name, PATHINFO_EXTENSION));
    if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true)) $ext = 'bin';
    $staged = STAGING_DIR . $token . '.' . $ext;

    if (!move_uploaded_file($tmp_path, $staged)) {
        if (!@rename($tmp_path, $staged)) { $err = "Cannot store file"; return null; }
    }
    @chmod($staged, 0600);

    return [
        'label'        => $label,
        'staging_path' => $staged,
        'mime'         => $mime,
        'name'         => $orig_name,
        'size'         => $size,
    ];
}

/**
 * Stage raw bytes (for camera captures). Same shape as stage_file().
 */
function stage_bytes(string $bin, string $label, string $orig_name, ?string &$err = null): ?array
{
    $max = 5 * 1024 * 1024;
    if ($bin === '' || strlen($bin) > $max) { $err = "Invalid capture size"; return null; }

    $tmp = tempnam(sys_get_temp_dir(), 'cam_');
    if ($tmp === false || file_put_contents($tmp, $bin) === false) {
        $err = "Cannot stage capture";
        return null;
    }

    $fi = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($fi, $tmp);
    finfo_close($fi);

    $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mime, $allowed, true)) {
        @unlink($tmp);
        $err = "Invalid capture type";
        return null;
    }

    $token = bin2hex(random_bytes(16));
    $ext = $mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg');
    $staged = STAGING_DIR . $token . '.' . $ext;

    if (!@rename($tmp, $staged)) {
        @unlink($tmp);
        $err = "Cannot store capture";
        return null;
    }
    @chmod($staged, 0600);

    return [
        'label'        => $label,
        'staging_path' => $staged,
        'mime'         => $mime,
        'name'         => $orig_name,
        'size'         => strlen($bin),
    ];
}

// ── DOCUMENT OPTIONS (moved up so POST handler can use it) ───────────
$doc_options = [
    'Good Moral Certificate' => 'good_moral',
    'Junior High School Certificate (Original)' => 'jhs_certificate',
    'NSO/PSA Birth Certificate (Original)' => 'birth_certificate',
    '2 pcs 2×2 picture' => 'pictures_2x2'
];

// ------------------------------------------------------------------
// POST handling with CSRF validation and file processing
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    purge_old_staging();

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('Invalid CSRF token');
    }

    foreach ($fields as $f => $rules) {
        $val = $_POST[$f] ?? '';
        if ($f === 'is_4ps') {
            if (($val === '' || $val === null) && $rules['required']) {
                $errors[$f] = $t[$lang]['required'];
            }
        } else {
            $val = trim($val);
            if ($rules['required'] && empty($val)) {
                $errors[$f] = $t[$lang]['required'];
            } elseif (!empty($val) && isset($rules['pattern']) && !preg_match($rules['pattern'], $val)) {
                $errors[$f] = $t[$lang][$rules['msg']];
            }
        }
    }

    // ── Server-side age recompute (never trust the client value)
    if (!empty($_POST['birth_date'])) {
        $birth = DateTime::createFromFormat('Y-m-d', $_POST['birth_date']);
        if (!$birth) {
            $errors['birth_date'] = $t[$lang]['required'];
        } else {
            $age = (new DateTime())->diff($birth)->y;
            if ($age < 14 || $age > 25) {
                $errors['birth_date'] = $t[$lang]['invalid_age'];
            }
            $_POST['age'] = $age;
        }
    }

    foreach (['phone', 'father_contact', 'mother_contact', 'guardian_contact'] as $field) {
        if (!empty($_POST[$field]) && !preg_match('/^09\d{9}$/', $_POST[$field])) {
            $errors[$field] = $t[$lang]['invalid_phone'];
        }
    }

    if (empty($_POST['entrance_data'] ?? [])) {
        $errors['entrance_data'] = $t[$lang]['select_one_entrance'];
    }

    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = $t[$lang]['invalid_email'];
    }

    // ── STAGED FILE PROCESSING ───────────────────────────────────────
    // Session now carries METADATA ONLY — no base64 blobs.
    $prev = $_SESSION['uploaded_files'] ?? [];
    $uploaded_files = [];  // keyed by doc_key

    $checked_labels = $_POST['entrance_data'] ?? [];

    // 1) carry forward previously staged files whose docs are still checked
    foreach ($prev as $doc_key => $info) {
        $label = $info['label'] ?? '';
        $path  = $info['staging_path'] ?? '';
        if (in_array($label, $checked_labels, true) && $path !== '' && is_file($path)) {
            $uploaded_files[$doc_key] = $info;
        } elseif ($path !== '' && is_file($path)) {
            @unlink($path);  // user unchecked it → drop
        }
    }

    // 2) new uploads from <input type="file" name="entrance_files[doc_key]">
    if (!empty($_FILES['entrance_files']['name']) && is_array($_FILES['entrance_files']['name'])) {
        foreach ($_FILES['entrance_files']['name'] as $doc_key => $name) {
            if ($_FILES['entrance_files']['error'][$doc_key] !== UPLOAD_ERR_OK || $name === '') continue;

            $label = array_search($doc_key, $doc_options, true) ?: $doc_key;
            $err = null;
            $meta = stage_file($_FILES['entrance_files']['tmp_name'][$doc_key], $name, $label, $err);

            if ($meta) {
                if (!empty($uploaded_files[$doc_key]['staging_path'])) {
                    @unlink($uploaded_files[$doc_key]['staging_path']);
                }
                $uploaded_files[$doc_key] = $meta;
            } else {
                $errors['upload'] = $err ?: "Upload failed";
            }
        }
    }

    // 3) camera captures — <input name="camera_data[doc_key]">
    if (!empty($_POST['camera_data']) && is_array($_POST['camera_data'])) {
        foreach ($_POST['camera_data'] as $doc_key => $data) {
            if (empty($data) || strpos($data, 'data:image/') !== 0) continue;
            $comma = strpos($data, ',');
            if ($comma === false) continue;

            $bin = base64_decode(substr($data, $comma + 1), true);
            if ($bin === false || $bin === '') continue;

            $label = array_search($doc_key, $doc_options, true) ?: $doc_key;
            $err = null;
            $meta = stage_bytes($bin, $label, $label . '.jpg', $err);

            if ($meta) {
                if (!empty($uploaded_files[$doc_key]['staging_path'])) {
                    @unlink($uploaded_files[$doc_key]['staging_path']);
                }
                $uploaded_files[$doc_key] = $meta;
            } else {
                $errors['upload'] = $err ?: "Capture failed";
            }
        }
    }

    if (empty($errors)) {
        $_SESSION['form_data']      = $_POST;
        $_SESSION['uploaded_files'] = $uploaded_files;
        header('Location: ' . basename(__FILE__) . '?review=1');
        exit;
    } else {
        $_SESSION['errors']         = $errors;
        $_SESSION['old']            = $_POST;
        $_SESSION['uploaded_files'] = $uploaded_files; // keep what we managed to stage
        header('Location: ' . basename(__FILE__));
        exit;
    }
}

// ----------------------------------------------------------------
// Display modes
// ----------------------------------------------------------------
if (isset($_GET['edit'])) {
    $mode = 'form';
    if (isset($_SESSION['form_data'])) {
        $_POST = $_SESSION['form_data'];
    }
} elseif (isset($_GET['review']) && isset($_SESSION['form_data'])) {
    $mode = 'review';
    $_POST = $_SESSION['form_data'];
} else {
    $mode = 'form';
    if (isset($_SESSION['old'])) {
        $_POST = $_SESSION['old'];
        unset($_SESSION['old']);
    }
    if (isset($_SESSION['errors'])) {
        $errors = $_SESSION['errors'];
        unset($_SESSION['errors']);
    }
}

$edu_history = [];
if (isset($_POST['edu_level']) && is_array($_POST['edu_level'])) {
    foreach ($_POST['edu_level'] as $i => $level) {
        $edu_history[] = [
            'level' => $level,
            'school_name' => $_POST['school_name'][$i] ?? '',
            'school_address' => $_POST['school_address'][$i] ?? '',
            'year_completed' => $_POST['year_completed'][$i] ?? ''
        ];
    }
}
if (empty($edu_history)) {
    $edu_history[] = ['level' => 'Elementary', 'school_name' => '', 'school_address' => '', 'year_completed' => ''];
}

// ── Metadata only now — no base64 blobs
$display_files = isset($_SESSION['uploaded_files']) ? $_SESSION['uploaded_files'] : [];
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t[$lang]['form'] ?> | USAT College</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;color:#1a202c}
        body::before{content:'';position:fixed;top:0;left:0;width:100%;height:100%;background:url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=2070') center/cover no-repeat;opacity:0.05;pointer-events:none;z-index:-1}
        .hero-header{background:linear-gradient(135deg,rgba(30,136,229,0.95),rgba(21,101,192,0.95));backdrop-filter:blur(10px);padding:2rem 1rem;text-align:center;color:white;border-bottom:4px solid #ffd700;margin-bottom:2rem;position:relative;overflow:hidden}
        .hero-header img{max-width:100px;border-radius:50%;border:4px solid #ffd700;box-shadow:0 10px 30px rgba(0,0,0,0.2)}
        .hero-header h1{font-size:2rem;font-weight:700;margin-top:1rem}
        .hero-header p{font-size:1.1rem;opacity:0.95}
        .form-container{max-width:1300px;margin:0 auto;padding:0 1.5rem 3rem}
        .section-card{background:rgba(255,255,255,0.98);border-radius:20px;padding:1.8rem;margin-bottom:1.8rem;box-shadow:0 20px 40px -15px rgba(0,0,0,0.15)}
        .section-header{display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #e2e8f0}
        .section-header i{font-size:1.8rem;color:#1e88e5}
        .section-header h2{font-size:1.5rem;font-weight:600;color:#1a202c}
        .form-group{position:relative;margin-bottom:1rem}
        .form-group input,.form-group select,.form-group textarea{width:100%;padding:0.85rem 1rem;border:2px solid #e2e8f0;border-radius:12px;font-size:0.95rem;background:white}
        .form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:#1e88e5;box-shadow:0 0 0 3px rgba(30,136,229,0.1);outline:none}
        .form-group label{position:absolute;left:1rem;top:0.85rem;color:#718096;font-size:0.95rem;pointer-events:none;transition:all 0.3s ease;background:white;padding:0 0.4rem;font-weight:500;z-index:1}
        .form-group.filled label,.form-group input:focus+label,.form-group select:focus+label,.form-group textarea:focus+label{top:-0.6rem;font-size:0.75rem;color:#1e88e5;font-weight:600}
        .checkbox-container{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.8rem;margin:1rem 0}
        .checkbox-item input[type="checkbox"]{width:auto;margin-right:0.5rem}
        .checkbox-item label{position:static;display:inline;pointer-events:auto}
        .btn-custom{padding:0.9rem 2rem;border-radius:50px;font-weight:600;transition:all 0.3s ease}
        .btn-submit{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white}
        .btn-back{background:linear-gradient(135deg,#6c757d 0%,#5a6268 100%);color:white}
        .btn-add{background:linear-gradient(135deg,#28a745 0%,#20c997 100%);color:white}
        .btn-capture{background:linear-gradient(135deg,#0d6efd 0%,#6610f2 100%);color:white;border:none}
        .btn-capture:hover{background:linear-gradient(135deg,#0b5ed7 0%,#520dc2 100%);color:white}
        .transferee-section{margin-top:1.5rem;padding:1.5rem;background:linear-gradient(135deg,#f8f9fa 0%,#e9ecef 100%);border-radius:16px;border-left:4px solid #1e88e5}
        .review-container{background:white;border-radius:20px;padding:2rem;box-shadow:0 20px 40px -15px rgba(0,0,0,0.1)}
        .review-section{margin-bottom:2rem;padding:1.5rem;background:#f8f9fa;border-radius:12px;border-left:4px solid #1e88e5}
        .review-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:0.8rem}
        .review-item{padding:0.5rem;border-bottom:1px solid #dee2e6}
        .review-item strong{color:#2d3748;font-weight:600}
        .lang-switch{position:fixed;top:20px;right:20px;z-index:1000;background:white;border-radius:50px;padding:0.5rem;box-shadow:0 2px 10px rgba(0,0,0,0.1)}
        .lang-btn{padding:0.5rem 1rem;border:none;background:none;font-weight:600;transition:all 0.3s ease;border-radius:50px}
        .lang-btn.active{background:#1e88e5;color:white}

        /* === Enhanced error states === */
        .form-group.error input,
        .form-group.error select,
        .form-group.error textarea {
            border-color: #dc3545 !important;
            background: #fff5f5 !important;
            animation: shake 0.3s ease-in-out;
        }
        .form-group.error label {
            color: #dc3545 !important;
        }
        .form-group.error > input + label,
        .form-group.error > select + label,
        .form-group.error > textarea + label {
            top: -0.6rem;
            font-size: 0.75rem;
        }
        .form-group.error::after {
            content: '\f06a';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 1rem;
            top: 0.85rem;
            color: #dc3545;
            font-size: 1rem;
            pointer-events: none;
        }
        .error-msg {
            display: flex;
            align-items: flex-start;
            gap: 0.35rem;
            color: #dc3545;
            font-size: 0.78rem;
            margin-top: 0.35rem;
            font-weight: 500;
            line-height: 1.3;
            animation: slideDown 0.2s ease-out;
        }
        .error-msg::before {
            content: '\f071';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            font-size: 0.75rem;
            flex-shrink: 0;
            margin-top: 1px;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-4px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes shake {
            0%,100% { transform: translateX(0); }
            25%     { transform: translateX(-4px); }
            75%     { transform: translateX(4px); }
        }

        /* === Error summary banner === */
        .error-banner {
            background: linear-gradient(135deg, #fef2f2, #fee2e2);
            border-left: 5px solid #dc3545;
            color: #991b1b;
            padding: 1rem 1.25rem;
            border-radius: 14px;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            box-shadow: 0 4px 16px rgba(220,53,69,0.12);
            animation: slideDown 0.3s ease-out;
        }
        .error-banner > i {
            font-size: 1.4rem;
            color: #dc3545;
            margin-top: 2px;
            flex-shrink: 0;
        }
        .error-banner strong {
            display: block;
            font-size: 0.95rem;
            margin-bottom: 0.35rem;
            color: #991b1b;
        }
        .error-banner ul {
            margin: 0;
            padding-left: 1.25rem;
            font-size: 0.88rem;
        }
        .error-banner li { margin-bottom: 0.15rem; }

        /* === Progress indicator === */
        .progress-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            padding: 1rem 1.5rem;
            background: white;
            border-radius: 50px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.06);
            gap: 0.5rem;
        }
        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        .progress-step .step-number {
            width: 38px;
            height: 38px;
            background: #e2e8f0;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #718096;
            position: relative;
            z-index: 2;
            transition: all 0.3s ease;
        }
        .progress-step .step-label {
            display: block;
            font-size: 0.72rem;
            color: #718096;
            margin-top: 0.4rem;
            font-weight: 500;
            letter-spacing: 0.2px;
        }
        .progress-step.active .step-number {
            background: linear-gradient(135deg, #1e88e5, #1565c0);
            color: white;
            box-shadow: 0 0 0 4px rgba(30,136,229,0.2);
            transform: scale(1.05);
        }
        .progress-step.active .step-label {
            color: #1e88e5;
            font-weight: 700;
        }
        .progress-step.completed .step-number {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }
        .progress-step.completed .step-label {
            color: #059669;
            font-weight: 600;
        }
        @media (max-width: 640px) {
            .progress-step .step-label { display: none; }
            .progress-step .step-number { width: 32px; height: 32px; font-size: 0.85rem; }
        }

        /* === File upload rows === */
        .upload-row {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            padding: 1.1rem;
            margin-bottom: 0.75rem;
            transition: all 0.2s;
        }
        .upload-row:hover {
            border-color: #cbd5e1;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .upload-row.has-file {
            border-color: #10b981;
            background: linear-gradient(135deg, #f0fdf4, #dcfce7);
        }
        .upload-row .form-label {
            font-size: 0.7rem;
            font-weight: 700;
            color: #64748b;
            letter-spacing: 0.5px;
            margin-bottom: 0.4rem;
            text-transform: uppercase;
        }
        .preview-thumb{width:80px;height:60px;object-fit:cover;border-radius:8px;border:2px solid #e5e7eb}
        .camera-modal{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.9);display:flex;align-items:center;justify-content:center}
        .camera-container{background:#000;border-radius:16px;overflow:hidden;max-width:600px;width:95%}
        .camera-container video{width:100%;display:block}
        .camera-container .camera-controls{padding:1rem;display:flex;gap:0.75rem;justify-content:center;background:#111}
        .camera-container .camera-controls button{padding:0.6rem 1.5rem;border-radius:50px;font-weight:600}
        .doc-upload-row{display:none; margin-top:0.5rem; padding-left:1rem; border-left:3px solid #0d6efd;}
        .doc-upload-row.visible{display:block;}

        /* === SweetAlert overrides === */
        .swal2-popup {
            border-radius: 20px !important;
            padding: 2rem 1.5rem !important;
        }
        .swal2-title {
            font-size: 1.4rem !important;
            font-weight: 700 !important;
        }
        .swal2-html-container {
            font-size: 0.95rem !important;
            line-height: 1.6 !important;
        }
        .swal2-confirm {
            border-radius: 50px !important;
            padding: 0.7rem 2rem !important;
            font-weight: 600 !important;
        }
    </style>
</head>
<body>

<div class="lang-switch">
    <button class="lang-btn <?= $lang == 'en' ? 'active' : '' ?>" onclick="switchLang('en')">EN</button>
    <button class="lang-btn <?= $lang == 'fil' ? 'active' : '' ?>" onclick="switchLang('fil')">FIL</button>
</div>

<div class="hero-header">
    <img src="../assets/img/usat.jpg" alt="USAT College Logo" onerror="this.src='https://via.placeholder.com/100'">
    <h1><?= $t[$lang]['school'] ?></h1>
    <p><?= $t[$lang]['form'] ?> | <?= $t[$lang]['ay'] ?></p>
</div>

<div class="form-container">

    <?php if ($mode === 'form'): ?>

        <?php if (!empty($errors)): ?>
            <div class="error-banner">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <strong>Please fix the following before submitting:</strong>
                    <ul>
                        <?php foreach ($errors as $field => $msg): ?>
                            <?php if ($field === 'upload') continue; ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endif; ?>

        <?php if (isset($errors['upload'])): ?>
            <div class="error-banner">
                <i class="fas fa-file-excel"></i>
                <div>
                    <strong>File upload problem:</strong>
                    <ul><li><?= htmlspecialchars($errors['upload']) ?></li></ul>
                </div>
            </div>
        <?php endif; ?>

        <div class="progress-indicator" id="progressIndicator">
            <div class="progress-step active" data-step="1"><div class="step-number">1</div><div class="step-label">Student Info</div></div>
            <div class="progress-step" data-step="2"><div class="step-number">2</div><div class="step-label">Parents & Guardian</div></div>
            <div class="progress-step" data-step="3"><div class="step-number">3</div><div class="step-label">Address</div></div>
            <div class="progress-step" data-step="4"><div class="step-number">4</div><div class="step-label">Education</div></div>
            <div class="progress-step" data-step="5"><div class="step-number">5</div><div class="step-label">Enrollment</div></div>
        </div>

        <form method="POST" action="" id="enrollForm" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">

            <!-- STUDENT INFORMATION -->
            <div class="section-card" data-section="1">
                <div class="section-header"><i class="fas fa-user-graduate"></i><h2><?= $t[$lang]['student'] ?></h2></div>
                <div class="row g-3">
                    <div class="col-md-6 form-group <?= isset($errors['lrn']) ? 'error' : '' ?>"><input type="text" name="lrn" id="lrn" placeholder=" " value="<?= htmlspecialchars($_POST['lrn'] ?? '') ?>" required pattern="\d{12}" maxlength="12"><label><i class="fas fa-id-card me-1"></i> <?= $t[$lang]['lrn'] ?></label><?php if(isset($errors['lrn'])): ?><span class="error-msg"><?= $errors['lrn'] ?></span><?php endif; ?></div>
                    <div class="col-md-6 form-group <?= isset($errors['last_name']) ? 'error' : '' ?>"><input type="text" name="last_name" id="last_name" placeholder=" " value="<?= htmlspecialchars($_POST['last_name'] ?? '') ?>" required pattern="[A-Za-z\s\-']{2,}"><label><i class="fas fa-user me-1"></i> <?= $t[$lang]['lname'] ?></label><?php if(isset($errors['last_name'])): ?><span class="error-msg"><?= $errors['last_name'] ?></span><?php endif; ?></div>
                    <div class="col-md-6 form-group <?= isset($errors['first_name']) ? 'error' : '' ?>"><input type="text" name="first_name" id="first_name" placeholder=" " value="<?= htmlspecialchars($_POST['first_name'] ?? '') ?>" required pattern="[A-Za-z\s\-']{2,}"><label><i class="fas fa-user me-1"></i> <?= $t[$lang]['fname'] ?></label><?php if(isset($errors['first_name'])): ?><span class="error-msg"><?= $errors['first_name'] ?></span><?php endif; ?></div>
                    <div class="col-md-6 form-group"><input type="text" name="middle_name" id="middle_name" placeholder=" " value="<?= htmlspecialchars($_POST['middle_name'] ?? '') ?>"><label><i class="fas fa-user me-1"></i> <?= $t[$lang]['mname'] ?></label></div>
                    <div class="col-md-4 form-group"><input type="text" name="nick_name" id="nick_name" placeholder=" " value="<?= htmlspecialchars($_POST['nick_name'] ?? '') ?>"><label><i class="fas fa-smile me-1"></i> <?= $t[$lang]['nick'] ?></label></div>
                    <div class="col-md-4 form-group"><input type="text" name="ext_name" id="ext_name" placeholder=" " value="<?= htmlspecialchars($_POST['ext_name'] ?? '') ?>"><label><i class="fas fa-tag me-1"></i> <?= $t[$lang]['ext'] ?></label></div>
                    <div class="col-md-4 form-group <?= isset($errors['sex']) ? 'error' : '' ?>"><select name="sex" id="sex" required><option value="" disabled selected></option><option value="Male" <?= ($_POST['sex'] ?? '') == 'Male' ? 'selected' : '' ?>><?= $t[$lang]['male'] ?></option><option value="Female" <?= ($_POST['sex'] ?? '') == 'Female' ? 'selected' : '' ?>><?= $t[$lang]['female'] ?></option></select><label><i class="fas fa-venus-mars me-1"></i> <?= $t[$lang]['sex'] ?></label></div>
                    <div class="col-md-4 form-group <?= isset($errors['birth_date']) ? 'error' : '' ?>"><input type="date" id="birth_date" name="birth_date" value="<?= htmlspecialchars($_POST['birth_date'] ?? '') ?>" required><label><i class="fas fa-calendar-alt me-1"></i> <?= $t[$lang]['bdate'] ?></label></div>
                    <div class="col-md-4 form-group"><input type="number" id="age" name="age" placeholder=" " value="<?= htmlspecialchars($_POST['age'] ?? '') ?>" min="14" max="25" readonly style="background:#f8f9fa"><label><i class="fas fa-birthday-cake me-1"></i> <?= $t[$lang]['age'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="text" name="civil_status" id="civil_status" placeholder=" " value="<?= htmlspecialchars($_POST['civil_status'] ?? '') ?>"><label><i class="fas fa-ring me-1"></i> <?= $t[$lang]['civil'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="text" name="nationality" id="nationality" placeholder=" " value="<?= htmlspecialchars($_POST['nationality'] ?? '') ?>"><label><i class="fas fa-flag me-1"></i> <?= $t[$lang]['nation'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="text" name="religion" id="religion" placeholder=" " value="<?= htmlspecialchars($_POST['religion'] ?? '') ?>"><label><i class="fas fa-church me-1"></i> <?= $t[$lang]['religion'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="number" step="0.01" name="height" id="height" placeholder=" " value="<?= htmlspecialchars($_POST['height'] ?? '') ?>"><label><i class="fas fa-arrow-up me-1"></i> <?= $t[$lang]['height'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="number" step="0.01" name="weight" id="weight" placeholder=" " value="<?= htmlspecialchars($_POST['weight'] ?? '') ?>"><label><i class="fas fa-weight-hanging me-1"></i> <?= $t[$lang]['weight'] ?></label></div>
                    <div class="col-md-5 form-group <?= isset($errors['email']) ? 'error' : '' ?>"><input type="email" name="email" id="email" placeholder=" " value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"><label><i class="fas fa-envelope me-1"></i> <?= $t[$lang]['email'] ?></label></div>
                    <div class="col-md-4 form-group <?= isset($errors['phone']) ? 'error' : '' ?>"><input type="tel" name="phone" id="phone" placeholder=" " value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" pattern="09\d{9}" maxlength="11"><label><i class="fas fa-phone-alt me-1"></i> <?= $t[$lang]['phone'] ?></label></div>
                    <div class="col-12 form-group"><textarea name="special_skills" id="special_skills" rows="2" placeholder=" "><?= htmlspecialchars($_POST['special_skills'] ?? '') ?></textarea><label><i class="fas fa-lightbulb me-1"></i> <?= $t[$lang]['skills'] ?></label></div>
                </div>
            </div>

            <!-- PARENTS & GUARDIAN -->
            <div class="section-card" data-section="2">
                <div class="section-header"><i class="fas fa-users"></i><h2><?= $t[$lang]['parents'] ?></h2></div>
                <div class="row g-3">
                    <div class="col-md-6 form-group"><input type="text" name="father_name" id="father_name" placeholder=" " value="<?= htmlspecialchars($_POST['father_name'] ?? '') ?>"><label><i class="fas fa-male me-1"></i> <?= $t[$lang]['father'] ?></label></div>
                    <div class="col-md-6 form-group <?= isset($errors['father_contact']) ? 'error' : '' ?>"><input type="tel" name="father_contact" id="father_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['father_contact'] ?? '') ?>" pattern="09\d{9}" maxlength="11"><label><i class="fas fa-phone me-1"></i> <?= $t[$lang]['fcontact'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="father_occupation" id="father_occupation" placeholder=" " value="<?= htmlspecialchars($_POST['father_occupation'] ?? '') ?>"><label><i class="fas fa-briefcase me-1"></i> <?= $t[$lang]['fjob'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="mother_maiden_name" id="mother_maiden_name" placeholder=" " value="<?= htmlspecialchars($_POST['mother_maiden_name'] ?? '') ?>"><label><i class="fas fa-female me-1"></i> <?= $t[$lang]['mother'] ?></label></div>
                    <div class="col-md-6 form-group <?= isset($errors['mother_contact']) ? 'error' : '' ?>"><input type="tel" name="mother_contact" id="mother_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['mother_contact'] ?? '') ?>" pattern="09\d{9}" maxlength="11"><label><i class="fas fa-phone me-1"></i> <?= $t[$lang]['mcontact'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="mother_occupation" id="mother_occupation" placeholder=" " value="<?= htmlspecialchars($_POST['mother_occupation'] ?? '') ?>"><label><i class="fas fa-briefcase me-1"></i> <?= $t[$lang]['mjob'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="ave_family_income" id="ave_family_income" placeholder=" " value="<?= htmlspecialchars($_POST['ave_family_income'] ?? '') ?>"><label><i class="fas fa-money-bill-wave me-1"></i> <?= $t[$lang]['income'] ?></label></div>
                    <div class="col-md-6 form-group <?= isset($errors['is_4ps']) ? 'error' : '' ?>"><select name="is_4ps" id="is_4ps" required><option value="" disabled <?= !isset($_POST['is_4ps']) || $_POST['is_4ps'] === '' ? 'selected' : '' ?>></option><option value="1" <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '1') ? 'selected' : '' ?>><?= $t[$lang]['yes'] ?></option><option value="0" <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '0') ? 'selected' : '' ?>><?= $t[$lang]['no'] ?></option></select><label><i class="fas fa-hand-holding-usd me-1"></i> <?= $t[$lang]['voucher_question'] ?></label></div>
                    <div class="col-12 mt-4"><div class="section-header" style="border-bottom:none;padding-bottom:0"><i class="fas fa-shield-alt"></i><h5 class="text-primary mb-0"><?= $t[$lang]['guardian'] ?></h5></div></div>
                    <div class="col-md-6 form-group"><input type="text" name="guardian_fullname" id="guardian_fullname" placeholder=" " value="<?= htmlspecialchars($_POST['guardian_fullname'] ?? '') ?>"><label><i class="fas fa-user-shield me-1"></i> <?= $t[$lang]['guardian_name'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="guardian_relation" id="guardian_relation" placeholder=" " value="<?= htmlspecialchars($_POST['guardian_relation'] ?? '') ?>"><label><i class="fas fa-handshake me-1"></i> <?= $t[$lang]['guardian_relation'] ?></label></div>
                    <div class="col-md-6 form-group <?= isset($errors['guardian_contact']) ? 'error' : '' ?>"><input type="tel" name="guardian_contact" id="guardian_contact" placeholder="09XXXXXXXXX" value="<?= htmlspecialchars($_POST['guardian_contact'] ?? '') ?>" pattern="09\d{9}" maxlength="11"><label><i class="fas fa-phone-alt me-1"></i> <?= $t[$lang]['guardian_contact'] ?></label></div>
                </div>
            </div>

            <!-- ADDRESS SECTION -->
            <div class="section-card" data-section="3">
                <div class="section-header"><i class="fas fa-map-marker-alt"></i><h2><?= $t[$lang]['address'] ?></h2></div>
                <div class="row g-3">
                    <div class="col-md-6 form-group"><input type="text" name="purok_street" id="purok_street" placeholder=" " value="<?= htmlspecialchars($_POST['purok_street'] ?? '') ?>"><label><i class="fas fa-road me-1"></i> <?= $t[$lang]['street'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="barangay" id="barangay" placeholder=" " value="<?= htmlspecialchars($_POST['barangay'] ?? '') ?>"><label><i class="fas fa-home me-1"></i> <?= $t[$lang]['barangay'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="town_city" id="town_city" placeholder=" " value="<?= htmlspecialchars($_POST['town_city'] ?? '') ?>"><label><i class="fas fa-city me-1"></i> <?= $t[$lang]['city'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="province" id="province" placeholder=" " value="<?= htmlspecialchars($_POST['province'] ?? '') ?>"><label><i class="fas fa-globe-asia me-1"></i> <?= $t[$lang]['province'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="region" id="region" placeholder=" " value="<?= htmlspecialchars($_POST['region'] ?? '') ?>"><label><i class="fas fa-map me-1"></i> <?= $t[$lang]['region'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="district" id="district" placeholder=" " value="<?= htmlspecialchars($_POST['district'] ?? '') ?>"><label><i class="fas fa-chart-line me-1"></i> <?= $t[$lang]['district'] ?></label></div>
                    <div class="col-md-6 form-group"><input type="text" name="postal_code" id="postal_code" placeholder=" " value="<?= htmlspecialchars($_POST['postal_code'] ?? '') ?>"><label><i class="fas fa-mail-bulk me-1"></i> <?= $t[$lang]['postal'] ?></label></div>
                </div>
            </div>

            <!-- EDUCATIONAL HISTORY -->
            <div class="section-card" data-section="4">
                <div class="section-header"><i class="fas fa-graduation-cap"></i><h2><?= $t[$lang]['education'] ?></h2></div>
                <div id="edu-container"><?php foreach ($edu_history as $edu): ?><div class="edu-entry row g-3 align-items-end"><div class="col-md-3 form-group filled"><select name="edu_level[]" class="edu-level" style="background:white"><option value="Elementary" <?= ($edu['level']=='Elementary')?'selected':'' ?>><?= $t[$lang]['elem'] ?></option><option value="JHS" <?= ($edu['level']=='JHS')?'selected':'' ?>><?= $t[$lang]['jhs'] ?></option><option value="Transferred" <?= ($edu['level']=='Transferred')?'selected':'' ?>><?= $t[$lang]['trans'] ?></option></select><label>Level</label></div><div class="col-md-3 form-group"><input type="text" name="school_name[]" placeholder=" " value="<?= htmlspecialchars($edu['school_name']) ?>"><label><?= $t[$lang]['school_name'] ?></label></div><div class="col-md-3 form-group"><input type="text" name="school_address[]" placeholder=" " value="<?= htmlspecialchars($edu['school_address']) ?>"><label><?= $t[$lang]['school_addr'] ?></label></div><div class="col-md-3 form-group"><input type="text" name="year_completed[]" placeholder=" " value="<?= htmlspecialchars($edu['year_completed']) ?>"><label><?= $t[$lang]['year_comp'] ?></label></div></div><?php endforeach; ?></div>
                <button type="button" class="btn btn-add mt-3" id="addEduBtn"><i class="fas fa-plus me-2"></i><?= $t[$lang]['add_school'] ?></button>
            </div>

            <!-- ENROLLMENT DETAILS -->
            <div class="section-card" data-section="5">
                <div class="section-header"><i class="fas fa-clipboard-list"></i><h2><?= $t[$lang]['enrollment'] ?></h2></div>
                <div class="row g-3">
                    <div class="col-md-3 form-group <?= isset($errors['school_year']) ? 'error' : '' ?>"><input type="text" name="school_year" id="school_year" placeholder=" " value="<?= htmlspecialchars($_POST['school_year'] ?? '2026-2027') ?>" required><label><i class="fas fa-calendar me-1"></i> <?= $t[$lang]['sy'] ?></label></div>
                    <div class="col-md-3 form-group <?= isset($errors['grade_level']) ? 'error' : '' ?>"><select name="grade_level" id="grade_level" required><option value="" disabled selected></option><option value="Grade 11" <?= ($_POST['grade_level'] ?? '') == 'Grade 11' ? 'selected' : '' ?>>Grade 11</option><option value="Grade 12" <?= ($_POST['grade_level'] ?? '') == 'Grade 12' ? 'selected' : '' ?>>Grade 12</option></select><label><i class="fas fa-layer-group me-1"></i> <?= $t[$lang]['grade'] ?></label></div>
                    <div class="col-md-3 form-group <?= isset($errors['semester']) ? 'error' : '' ?>"><select name="semester" id="semester" required><option value="" disabled selected></option><option value="1st Semester" <?= ($_POST['semester'] ?? '') == '1st Semester' ? 'selected' : '' ?>>1st Semester</option><option value="2nd Semester" <?= ($_POST['semester'] ?? '') == '2nd Semester' ? 'selected' : '' ?>>2nd Semester</option></select><label><i class="fas fa-chart-simple me-1"></i> <?= $t[$lang]['semester'] ?></label></div>
                    <div class="col-md-3 form-group"><input type="text" name="track" value="TECHPRO ELECTIVES" readonly required style="background:#f8f9fa;cursor:not-allowed"><label><i class="fas fa-road me-1"></i> <?= $t[$lang]['track'] ?></label></div>
                </div>
                <div class="row g-3 mt-2"><div class="col-md-6 form-group <?= isset($errors['strand']) ? 'error' : '' ?>"><select name="strand" id="strand" required><option value="" disabled selected></option></select><label><i class="fas fa-tree me-1"></i> <?= $t[$lang]['strand'] ?></label></div><div class="col-md-6 form-group <?= isset($errors['program']) ? 'error' : '' ?>"><select name="program" id="program" required><option value="" disabled selected></option></select><label><i class="fas fa-code-branch me-1"></i> <?= $t[$lang]['program'] ?></label></div></div>
                <div class="form-group mt-3"><input type="text" name="household_id" id="household_id" placeholder=" " value="<?= htmlspecialchars($_POST['household_id'] ?? '') ?>"><label><i class="fas fa-home me-1"></i> <?= $t[$lang]['household'] ?></label></div>
                <div class="mt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="is_transferred" id="is_transferred" value="1" style="width:18px;height:18px" <?= !empty($_POST['is_transferred']) ? 'checked' : '' ?>><label class="form-check-label fw-bold ms-2" for="is_transferred"><i class="fas fa-exchange-alt me-1 text-primary"></i> <?= $t[$lang]['transferred_in'] ?></label></div>
                <div id="transferee-fields" class="transferee-section" style="display:<?= !empty($_POST['is_transferred']) ? 'block' : 'none' ?>"><div class="row g-3"><div class="col-md-6 form-group"><input type="text" name="previous_school_name" placeholder=" " value="<?= htmlspecialchars($_POST['previous_school_name'] ?? '') ?>"><label><?= $t[$lang]['prev_school_name'] ?></label></div><div class="col-md-6 form-group"><textarea name="previous_school_address" rows="2" placeholder=" "><?= htmlspecialchars($_POST['previous_school_address'] ?? '') ?></textarea><label><?= $t[$lang]['prev_school_address'] ?></label></div><div class="col-md-4 form-group"><input type="text" name="previous_track" placeholder=" " value="<?= htmlspecialchars($_POST['previous_track'] ?? '') ?>"><label><?= $t[$lang]['prev_track'] ?></label></div><div class="col-md-4 form-group"><input type="text" name="previous_strand" placeholder=" " value="<?= htmlspecialchars($_POST['previous_strand'] ?? '') ?>"><label><?= $t[$lang]['prev_strand'] ?></label></div><div class="col-md-4 form-group"><input type="text" name="previous_program" placeholder=" " value="<?= htmlspecialchars($_POST['previous_program'] ?? '') ?>"><label><?= $t[$lang]['prev_program'] ?></label></div><div class="col-md-4 form-group"><input type="number" name="previous_year_completed" placeholder=" " value="<?= htmlspecialchars($_POST['previous_year_completed'] ?? '') ?>" min="2000" max="<?= date('Y') ?>"><label><?= $t[$lang]['prev_year_completed'] ?></label></div></div><div class="mt-4"><label class="form-label fw-bold mb-2"><?= $t[$lang]['voucher_qualified'] ?></label><div class="d-flex gap-4"><div class="form-check"><input class="form-check-input" type="radio" name="voucher_qualified" id="vq_yes" value="1" <?= ($_POST['voucher_qualified'] ?? '') == '1' ? 'checked' : '' ?>><label class="form-check-label" for="vq_yes"><?= $t[$lang]['qualified_voucher'] ?></label></div><div class="form-check"><input class="form-check-input" type="radio" name="voucher_qualified" id="vq_no" value="0" <?= ($_POST['voucher_qualified'] ?? '') == '0' ? 'checked' : '' ?>><label class="form-check-label" for="vq_no"><?= $t[$lang]['not_qualified_voucher'] ?></label></div></div></div></div></div>
            </div>

            <!-- ENTRANCE DOCUMENTS -->
            <div class="section-card">
                <div class="section-header"><i class="fas fa-folder-open"></i><h2><?= $t[$lang]['entrance_data'] ?></h2></div>

                <div class="checkbox-container">
                    <?php foreach ($doc_options as $doc_name => $doc_key): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="entrance_data[]" value="<?= htmlspecialchars($doc_name) ?>" id="<?= $doc_key ?>"
                                <?= in_array($doc_name, $_POST['entrance_data'] ?? [], true) ? 'checked' : '' ?>>
                            <label for="<?= $doc_key ?>"><i class="fas fa-check-circle me-2 text-primary"></i><?= $t[$lang][$doc_key] ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if(isset($errors['entrance_data'])): ?><span class="error-msg"><i class="fas fa-exclamation-triangle me-1"></i><?= $errors['entrance_data'] ?></span><?php endif; ?>
                <?php if(isset($errors['upload'])): ?>
                    <div class="alert alert-danger mt-2">
                        <i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($errors['upload']) ?>
                    </div>
                <?php endif; ?>

                <div class="mt-3">
                    <?php foreach ($doc_options as $doc_name => $doc_key):
                        $file_info = $display_files[$doc_key] ?? null;
                        $has_upload = $file_info !== null;
                        $is_checked = in_array($doc_name, $_POST['entrance_data'] ?? [], true);
                        $show_row = $is_checked || $has_upload;
                    ?>
                        <div class="doc-upload-row <?= $show_row ? 'visible' : '' ?>" id="uploadRow_<?= $doc_key ?>">
                            <div class="upload-row row g-3 align-items-end <?= $has_upload ? 'has-file' : '' ?>">
                                <div class="col-md-4">
                                    <label class="form-label small fw-semibold">Document Label</label>
                                    <input type="text" class="form-control" value="<?= htmlspecialchars($doc_name) ?>" readonly>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">Upload File</label>
                                    <input type="file"
                                           name="entrance_files[<?= $doc_key ?>]"
                                           class="form-control"
                                           accept=".pdf,.jpg,.jpeg,.png"
                                           <?php if ($has_upload): ?>disabled style="background:#e9ecef"<?php endif; ?>>
                                    <?php if ($has_upload): ?>
                                        <small class="text-success"><i class="fas fa-check-circle"></i> Already staged — re-upload to replace</small>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-semibold">or Capture</label>
                                    <button type="button" class="btn btn-capture btn-sm w-100" onclick="openCamera('<?= $doc_key ?>')"><i class="fas fa-camera me-1"></i> <?= $t[$lang]['capture'] ?></button>
                                </div>
                                <div class="col-md-2">
                                    <?php if ($has_upload): ?>
                                        <span class="badge bg-success" style="word-break:break-all;"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($file_info['name']) ?> (<?= round(($file_info['size'] ?? 0) / 1024, 1) ?> KB)</span>
                                    <?php endif; ?>
                                    <div class="preview-thumb" id="preview_<?= $doc_key ?>" style="display:none"><img src="" class="preview-thumb" id="previewImg_<?= $doc_key ?>"></div>
                                    <input type="hidden" name="camera_data[<?= $doc_key ?>]" id="cameraData_<?= $doc_key ?>" value="">
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="text-center mt-5 mb-4">
                <button type="submit" class="btn btn-submit btn-custom px-5"><i class="fas fa-paper-plane me-2"></i><?= $t[$lang]['submit'] ?></button>
                <a href="../admin/admin_login.php" class="btn btn-back btn-custom px-5 ms-3"><i class="fas fa-arrow-left me-2"></i><?= $t[$lang]['back'] ?></a>
            </div>
        </form>
    <?php else: ?>
        <!-- REVIEW MODE -->
        <?php
            $full_name = trim(
                ($_POST['first_name'] ?? '') . ' ' .
                ($_POST['middle_name'] ?? '') . ' ' .
                ($_POST['last_name'] ?? '') . ' ' .
                ($_POST['ext_name'] ?? '')
            );
            $full_name_display = $full_name !== '' ? preg_replace('/\s+/', ' ', $full_name) : '—';

            $birth_display = !empty($_POST['birth_date'])
                ? date('F d, Y', strtotime($_POST['birth_date']))
                : '—';

            $vq = $_POST['voucher_qualified'] ?? '';
            $voucher_display = $vq === '1'
                ? $t[$lang]['qualified_voucher']
                : ($vq === '0' ? $t[$lang]['not_qualified_voucher'] : '—');

            $section_display = !empty($_POST['section'])
                ? $_POST['section']
                : ($_POST['strand'] ?? '—');
        ?>

        <div class="review-container">

            <div class="text-center mb-4">
                <i class="fas fa-check-circle" style="font-size:4rem;color:#28a745"></i>
                <h1 class="mt-3" style="color:#1e88e5"><?= $t[$lang]['form'] ?> — Review &amp; Confirm</h1>
                <p class="text-muted">Please review your information before submitting</p>
            </div>

            <div class="review-section" style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-left-color:#4f46e5">
                <div class="row g-3">
                    <div class="col-md-8">
                        <h3 class="mb-2" style="color:#1e3c72"><i class="fas fa-user-graduate me-2"></i><?= htmlspecialchars($full_name_display) ?></h3>
                        <div class="d-flex flex-wrap gap-3 small">
                            <?php if (!empty($_POST['lrn'])): ?>
                                <span><strong>LRN:</strong> <?= htmlspecialchars($_POST['lrn']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($_POST['sex'])): ?>
                                <span><strong>Sex:</strong> <?= htmlspecialchars($_POST['sex']) ?></span>
                            <?php endif; ?>
                            <?php if (!empty($_POST['age'])): ?>
                                <span><strong>Age:</strong> <?= htmlspecialchars($_POST['age']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <div class="small">
                            <div><strong>Grade Level:</strong> <?= htmlspecialchars($_POST['grade_level'] ?? '—') ?></div>
                            <div><strong>Strand:</strong> <?= htmlspecialchars($_POST['strand'] ?? '—') ?></div>
                            <div><strong>School Year:</strong> <?= htmlspecialchars($_POST['school_year'] ?? '—') ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="review-section">
                <h3><i class="fas fa-user-graduate me-2"></i><?= $t[$lang]['student'] ?></h3>
                <div class="review-grid">
                    <?php
                    $student_fields = [
                        'lrn'            => 'LRN',
                        'last_name'      => 'Last Name',
                        'first_name'     => 'First Name',
                        'middle_name'    => 'Middle Name',
                        'nick_name'      => 'Nickname',
                        'ext_name'       => 'Extension Name',
                        'sex'            => 'Sex',
                        'birth_date'     => 'Birth Date',
                        'age'            => 'Age',
                        'civil_status'   => 'Civil Status',
                        'nationality'    => 'Nationality',
                        'religion'       => 'Religion',
                        'height'         => 'Height',
                        'weight'         => 'Weight',
                        'email'          => 'Email',
                        'phone'          => 'Phone',
                        'special_skills' => 'Special Skills',
                    ];
                    foreach ($student_fields as $k => $l):
                        $val = $_POST[$k] ?? '';
                        if ($k === 'birth_date' && $val !== '') $val = $birth_display;
                        if ($k === 'height'     && $val !== '') $val .= ' cm';
                        if ($k === 'weight'     && $val !== '') $val .= ' kg';
                    ?>
                        <div class="review-item"><strong><?= $l ?>:</strong> <?= htmlspecialchars($val !== '' ? $val : '—') ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="review-section">
                <h3><i class="fas fa-users me-2"></i><?= $t[$lang]['parents'] ?></h3>
                <div class="review-grid">
                    <?php
                    $parent_fields = [
                        'father_name'        => 'Father Name',
                        'father_occupation'  => 'Father Occupation',
                        'father_contact'     => 'Father Contact',
                        'mother_maiden_name' => 'Mother Maiden Name',
                        'mother_occupation'  => 'Mother Occupation',
                        'mother_contact'     => 'Mother Contact',
                        'ave_family_income'  => 'Average Family Income',
                    ];
                    foreach ($parent_fields as $k => $l):
                        $val = $_POST[$k] ?? '';
                    ?>
                        <div class="review-item"><strong><?= $l ?>:</strong> <?= htmlspecialchars($val !== '' ? $val : '—') ?></div>
                    <?php endforeach; ?>
                    <div class="review-item">
                        <strong>CCT/4Ps Recipient:</strong>
                        <?= (isset($_POST['is_4ps']) && $_POST['is_4ps'] == '1') ? $t[$lang]['yes'] : $t[$lang]['no'] ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($_POST['guardian_fullname']) || !empty($_POST['guardian_relation']) || !empty($_POST['guardian_contact'])): ?>
            <div class="review-section">
                <h3><i class="fas fa-shield-alt me-2"></i><?= $t[$lang]['guardian'] ?></h3>
                <div class="review-grid">
                    <div class="review-item"><strong>Guardian Name:</strong> <?= htmlspecialchars($_POST['guardian_fullname'] ?? '—') ?></div>
                    <div class="review-item"><strong>Relationship:</strong> <?= htmlspecialchars($_POST['guardian_relation'] ?? '—') ?></div>
                    <div class="review-item"><strong>Contact Number:</strong> <?= htmlspecialchars($_POST['guardian_contact'] ?? '—') ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="review-section">
                <h3><i class="fas fa-map-marker-alt me-2"></i><?= $t[$lang]['address'] ?></h3>
                <div class="review-grid">
                    <?php
                    $addr_fields = [
                        'purok_street' => 'Purok / Street',
                        'barangay'     => 'Barangay',
                        'town_city'    => 'Town / City',
                        'province'     => 'Province',
                        'region'       => 'Region',
                        'district'     => 'District',
                        'postal_code'  => 'Postal Code',
                    ];
                    foreach ($addr_fields as $k => $l):
                        $val = $_POST[$k] ?? '';
                    ?>
                        <div class="review-item"><strong><?= $l ?>:</strong> <?= htmlspecialchars($val !== '' ? $val : '—') ?></div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="review-section">
                <h3><i class="fas fa-graduation-cap me-2"></i><?= $t[$lang]['education'] ?></h3>
                <?php
                $has_edu = false;
                if (isset($_POST['edu_level']) && is_array($_POST['edu_level'])):
                    foreach ($_POST['edu_level'] as $i => $level):
                        $sn = $_POST['school_name'][$i] ?? '';
                        $sa = $_POST['school_address'][$i] ?? '';
                        $yc = $_POST['year_completed'][$i] ?? '';
                        if ($level === '' && $sn === '' && $sa === '' && $yc === '') continue;
                        $has_edu = true;
                ?>
                    <div style="margin-bottom:1rem;padding:1rem;background:white;border-radius:8px;border-left:3px solid #1e88e5">
                        <strong style="color:#1e88e5"><?= htmlspecialchars($level ?: '—') ?></strong>
                        <div class="mt-2 small">
                            <div><strong>School:</strong> <?= htmlspecialchars($sn ?: '—') ?></div>
                            <div><strong>Address:</strong> <?= htmlspecialchars($sa ?: '—') ?></div>
                            <div><strong>Year Completed:</strong> <?= htmlspecialchars($yc ?: '—') ?></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
                <?php if (!$has_edu): ?>
                    <p class="text-muted mb-0">No educational history provided.</p>
                <?php endif; ?>
            </div>

            <div class="review-section">
                <h3><i class="fas fa-clipboard-list me-2"></i><?= $t[$lang]['enrollment'] ?></h3>
                <div class="review-grid">
                    <div class="review-item"><strong>School Year:</strong> <?= htmlspecialchars($_POST['school_year'] ?? '—') ?></div>
                    <div class="review-item"><strong>Grade Level:</strong> <?= htmlspecialchars($_POST['grade_level'] ?? '—') ?></div>
                    <div class="review-item"><strong>Semester:</strong> <?= htmlspecialchars($_POST['semester'] ?? '—') ?></div>
                    <div class="review-item"><strong>Track:</strong> <?= htmlspecialchars($_POST['track'] ?? '—') ?></div>
                    <div class="review-item"><strong>Strand:</strong> <?= htmlspecialchars($_POST['strand'] ?? '—') ?></div>
                    <div class="review-item"><strong>Program:</strong> <?= htmlspecialchars($_POST['program'] ?? '—') ?></div>
                    <div class="review-item"><strong>Section:</strong> <?= htmlspecialchars($section_display) ?></div>
                    <div class="review-item"><strong>Household ID:</strong> <?= htmlspecialchars($_POST['household_id'] ?? '—') ?></div>
                    <div class="review-item"><strong>Voucher Status:</strong> <?= htmlspecialchars($voucher_display) ?></div>
                    <div class="review-item">
                        <strong>Transferee:</strong>
                        <?= !empty($_POST['is_transferred']) ? 'Yes' : 'No' ?>
                    </div>
                </div>
            </div>

            <?php if (!empty($_POST['is_transferred'])): ?>
            <div class="review-section">
                <h3><i class="fas fa-exchange-alt me-2"></i><?= $t[$lang]['transferred_in'] ?></h3>
                <div class="review-grid">
                    <div class="review-item"><strong>Previous School:</strong> <?= htmlspecialchars($_POST['previous_school_name'] ?? '—') ?></div>
                    <div class="review-item"><strong>Previous Address:</strong> <?= htmlspecialchars($_POST['previous_school_address'] ?? '—') ?></div>
                    <div class="review-item"><strong>Previous Track:</strong> <?= htmlspecialchars($_POST['previous_track'] ?? '—') ?></div>
                    <div class="review-item"><strong>Previous Strand:</strong> <?= htmlspecialchars($_POST['previous_strand'] ?? '—') ?></div>
                    <div class="review-item"><strong>Previous Program:</strong> <?= htmlspecialchars($_POST['previous_program'] ?? '—') ?></div>
                    <div class="review-item"><strong>Year Completed:</strong> <?= htmlspecialchars($_POST['previous_year_completed'] ?? '—') ?></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="review-section">
                <h3><i class="fas fa-folder-open me-2"></i><?= $t[$lang]['entrance_data'] ?></h3>

                <h5 class="mt-2 mb-2"><i class="fas fa-check-circle text-success me-2"></i>Documents Selected</h5>
                <ul class="list-unstyled mb-3">
                    <?php if (!empty($_POST['entrance_data'] ?? [])): ?>
                        <?php foreach (($_POST['entrance_data'] ?? []) as $d): ?>
                            <li class="mb-1">
                                <i class="fas fa-check text-success me-2"></i><?= htmlspecialchars($d) ?>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="text-muted">None selected</li>
                    <?php endif; ?>
                </ul>

                <?php if (!empty($display_files)): ?>
                    <h5 class="mt-3 mb-2"><i class="fas fa-paperclip text-primary me-2"></i>Staged Files (<?= count($display_files) ?>)</h5>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($display_files as $dk => $f):
                            $is_img = strpos($f['mime'] ?? '', 'image/') === 0;
                        ?>
                            <li class="mb-2 d-flex align-items-center gap-2">
                                <?php if ($is_img): ?>
                                    <i class="fas fa-image text-primary"></i>
                                <?php else: ?>
                                    <i class="fas fa-file-pdf text-danger"></i>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($f['label']) ?></strong>
                                <span class="badge bg-light text-dark"><?= htmlspecialchars($f['mime'] ?? '—') ?></span>
                                <span class="text-muted small">(<?= round(($f['size'] ?? 0) / 1024, 1) ?> KB)</span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0 mt-3"><em>No files attached. You may still submit and upload them later.</em></p>
                <?php endif; ?>
            </div>

            <form method="POST" action="process_enroll.php" class="text-center mt-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <?php
                // Skip nested/internal keys — no blobs posted; staging paths stay server-side.
                $skip_keys = ['existing_file', 'keep_existing_file', 'camera_data', 'entrance_files'];
                foreach ($_POST as $k => $v):
                    if (in_array($k, $skip_keys, true)) continue;
                    if (is_array($v)):
                        foreach ($v as $val):
                            if (is_array($val)) continue;
                ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>[]" value="<?= htmlspecialchars((string)$val) ?>">
                <?php
                        endforeach;
                    else:
                ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars((string)$v) ?>">
                <?php
                    endif;
                endforeach;
                ?>

                <button type="submit" class="btn btn-submit btn-custom px-5"><i class="fas fa-save me-2"></i> Confirm &amp; Save Enrollment</button>
            </form>
            <div class="text-center mt-3"><a href="?edit=1" class="btn btn-back btn-custom px-4"><i class="fas fa-edit me-2"></i> Edit</a></div>
        </div>
    <?php endif; ?>
</div>

<!-- CAMERA MODAL -->
<div id="cameraModal" class="camera-modal" style="display:none">
    <div class="camera-container">
        <video id="cameraVideo" autoplay playsinline></video>
        <div class="camera-controls">
            <button type="button" class="btn btn-light" onclick="capturePhoto()"><i class="fas fa-camera me-2"></i>Capture</button>
            <button type="button" class="btn btn-outline-light" onclick="closeCamera()"><i class="fas fa-times me-2"></i>Cancel</button>
        </div>
    </div>
    <canvas id="cameraCanvas" style="display:none"></canvas>
</div>

<script>
function switchLang(lang){const url=new URL(window.location.href);url.searchParams.set('lang',lang);window.location.href=url.toString()}
function capitalize(str){if(!str||typeof str!=='string')return str;return str.replace(/\b\w+/g,function(word){return word.charAt(0).toUpperCase()+word.slice(1).toLowerCase()})}
const nameFields=['last_name','first_name','middle_name','nick_name','ext_name','father_name','mother_maiden_name','guardian_fullname'];
function initFloatingLabels(container=document){container.querySelectorAll('.form-group').forEach(group=>{const input=group.querySelector('input,select,textarea');if(!input)return;const uf=()=>{if(input.value&&input.value.trim()!=='')group.classList.add('filled');else group.classList.remove('filled')};input.addEventListener('focus',()=>group.classList.add('filled'));input.addEventListener('blur',uf);input.addEventListener('input',uf);input.addEventListener('change',uf);uf()})}
const bd=document.getElementById('birth_date'),ai=document.getElementById('age');
function calcAge(){if(!bd||!bd.value)return;const b=new Date(bd.value),t=new Date();let a=t.getFullYear()-b.getFullYear();if(t.getMonth()<b.getMonth()||(t.getMonth()===b.getMonth()&&t.getDate()<b.getDate()))a--;if(ai)ai.value=a}
if(bd){bd.addEventListener('change',calcAge);window.addEventListener('load',calcAge)}
document.addEventListener('DOMContentLoaded',()=>{nameFields.forEach(f=>{const i=document.querySelector(`input[name="${f}"]`);if(i){i.addEventListener('input',()=>{if(i.value.length>0)i.value=capitalize(i.value)});i.addEventListener('blur',()=>{if(i.value.trim()!=='')i.value=capitalize(i.value.trim())})}});initFloatingLabels()});
const ss=document.getElementById('strand'),ps=document.getElementById('program'),progs={"Automotive and Small Engine Technologies":["Driving and Automotive Servicing","Automotive Servicing (Electrical Repair)","Automotive Servicing (Engine and Chassis Repairs)"],"Construction and Building Technologies":["Carpentry","Manual Metal Arc Welding"],"ICT Support and Computer Programming Technologies":["Computer Programming (Java)","Computer Programming (.NET)","Computer System Servicing"],"Industrial Technologies":["Electronics Product Assembly and Servicing"],"Agri-Fishery Business and Food Innovation":["Agricultural Crops Production"],"Hospitality and Tourism":["Food and Beverage Operation","Hotel Operation (Housekeeping)"]};
if(ss){ss.innerHTML='<option value="" disabled selected></option>';Object.keys(progs).forEach(k=>{let o=document.createElement("option");o.value=o.textContent=k;ss.appendChild(o)});ss.addEventListener("change",()=>{ps.innerHTML='<option value="" disabled selected></option>';if(progs[ss.value])progs[ss.value].forEach(p=>{let o=document.createElement("option");o.value=o.textContent=p;ps.appendChild(o)});initFloatingLabels(ps.parentElement)});const cs=<?= json_encode($_POST['strand']??'') ?>,cp=<?= json_encode($_POST['program']??'') ?>;if(cs){ss.value=cs;ss.dispatchEvent(new Event('change'));if(cp){const chk=()=>{if(ps.options.length>1){ps.value=cp;if(ps.value)ps.parentElement.classList.add('filled')}else setTimeout(chk,10)};chk()}}}
function addEdu(){const c=document.getElementById('edu-container'),t=document.createElement('div');t.className='edu-entry row g-3 align-items-end';t.innerHTML=`<div class="col-md-3 form-group filled"><select name="edu_level[]" class="edu-level" style="background:white"><option value="Elementary" selected>Elementary</option><option value="JHS">Junior High School</option><option value="Transferred">Transferred</option></select><label>Level</label></div><div class="col-md-3 form-group"><input type="text" name="school_name[]" placeholder=" " value=""><label>School Name</label></div><div class="col-md-3 form-group"><input type="text" name="school_address[]" placeholder=" " value=""><label>School Address</label></div><div class="col-md-3 form-group"><input type="text" name="year_completed[]" placeholder=" " value=""><label>Year Completed</label></div>`;c.appendChild(t);initFloatingLabels(t)}
document.getElementById('addEduBtn')?.addEventListener('click',addEdu);
const tc=document.getElementById('is_transferred'),ts=document.getElementById('transferee-fields');
if(tc&&ts){const tg=()=>{ts.style.display=tc.checked?'block':'none';if(tc.checked)initFloatingLabels(ts)};tc.addEventListener('change',tg);window.addEventListener('load',tg)}

document.querySelectorAll('input[name="entrance_data[]"]').forEach(cb => {
    cb.addEventListener('change', function() {
        const rowId = 'uploadRow_' + this.id;
        const row = document.getElementById(rowId);
        if (row) {
            if (this.checked) {
                row.classList.add('visible');
            } else {
                row.classList.remove('visible');
                const fileInput = row.querySelector('input[type="file"]');
                if (fileInput) { fileInput.value = ''; fileInput.disabled = false; }
                const preview = row.querySelector('.preview-thumb');
                if (preview) preview.style.display = 'none';
                const camData = row.querySelector('input[name^="camera_data"]');
                if (camData) camData.value = '';
            }
        }
    });
});

let currentRow=null, stream=null;
async function openCamera(rowId){
    currentRow = rowId;
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');
    modal.style.display = 'flex';
    try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
        video.srcObject = stream;
    } catch(e) {
        alert('Cannot access camera. Please check permissions.');
        closeCamera();
    }
}
function closeCamera(){
    const modal = document.getElementById('cameraModal');
    modal.style.display = 'none';
    if(stream){ stream.getTracks().forEach(t => t.stop()); stream = null; }
}
function capturePhoto(){
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('cameraCanvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataUrl = canvas.toDataURL('image/jpeg', 0.9);
    const dataInput = document.getElementById('cameraData_' + currentRow);
    if (dataInput) {
        dataInput.value = dataUrl;
        const preview = document.getElementById('preview_' + currentRow);
        if (preview) {
            const img = preview.querySelector('img');
            if (img) {
                img.src = dataUrl;
                preview.style.display = 'block';
            }
        }
        const row = document.querySelector('#uploadRow_' + currentRow);
        if (row) {
            const fileInput = row.querySelector('input[type="file"]');
            if (fileInput) fileInput.value = '';
        }
    }
    closeCamera();
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('input[name="entrance_data[]"]:checked').forEach(cb => {
        const rowId = 'uploadRow_' + cb.id;
        const row = document.getElementById(rowId);
        if (row) row.classList.add('visible');
    });
});

// Progress indicator follows scroll
(function() {
    const sections = document.querySelectorAll('[data-section]');
    const steps = document.querySelectorAll('.progress-step');
    if (!sections.length || !steps.length) return;

    function updateProgress() {
        const scrollPos = window.scrollY + window.innerHeight * 0.4;
        let activeStep = 1;
        sections.forEach(sec => {
            if (sec.offsetTop <= scrollPos) {
                activeStep = parseInt(sec.dataset.section) || 1;
            }
        });

        steps.forEach((step, i) => {
            step.classList.remove('active', 'completed');
            const stepNum = i + 1;
            if (stepNum === activeStep) {
                step.classList.add('active');
            } else if (stepNum < activeStep) {
                step.classList.add('completed');
                const numEl = step.querySelector('.step-number');
                if (numEl && !numEl.dataset.checked) {
                    numEl.dataset.checked = '1';
                    numEl.innerHTML = '<i class="fas fa-check" style="font-size:0.85rem"></i>';
                }
            } else {
                const numEl = step.querySelector('.step-number');
                if (numEl && numEl.dataset.checked) {
                    numEl.removeAttribute('data-checked');
                    numEl.textContent = stepNum;
                }
            }
        });
    }

    window.addEventListener('scroll', updateProgress, { passive: true });
    updateProgress();
})();

// Form validation
const form = document.getElementById('enrollForm');
if (form) {
    form.addEventListener('submit', function(e) {
        let v = true;
        let firstError = null;
        let errorList = [];

        form.querySelectorAll('.form-group.error').forEach(g => g.classList.remove('error'));

        form.querySelectorAll('[required]').forEach(f => {
            if (!f.checkValidity()) {
                v = false;
                const group = f.closest('.form-group');
                if (group) group.classList.add('error');
                if (!firstError) firstError = f;
                const label = f.closest('.form-group')?.querySelector('label')?.textContent?.trim() || 'Field';
                errorList.push(label.replace(/\*$/, '').trim());
            }
        });

        const lrn = document.getElementById('lrn');
        if (lrn && lrn.value && !/^\d{12}$/.test(lrn.value)) {
            v = false;
            lrn.closest('.form-group')?.classList.add('error');
            if (!firstError) firstError = lrn;
            errorList.push('LRN must be exactly 12 digits');
        }

        const i4 = document.getElementById('is_4ps');
        if (i4 && (!i4.value || i4.value === '')) {
            v = false;
            i4.closest('.form-group')?.classList.add('error');
            if (!firstError) firstError = i4;
            errorList.push('CCT/4Ps recipient (Yes/No)');
        }

        const entranceChecked = form.querySelectorAll('input[name="entrance_data[]"]:checked');
        if (entranceChecked.length === 0) {
            v = false;
            errorList.push('At least one Entrance Document');
        }

        if (!v) {
            e.preventDefault();

            Swal.fire({
                icon: 'warning',
                title: 'Please complete the form',
                html: '<p style="margin-bottom:0.75rem">The following fields need your attention:</p>'
                    + '<ul style="text-align:left;display:inline-block;margin:0;padding-left:1.2rem;font-size:0.9rem">'
                    + errorList.map(err => '<li>' + err.replace(/</g,'&lt;') + '</li>').join('')
                    + '</ul>',
                confirmButtonColor: '#1e88e5',
                confirmButtonText: 'Got it'
            });

            if (firstError) {
                setTimeout(() => {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    firstError.focus({ preventScroll: true });
                }, 300);
            }
        }
    });
}

// Success / error alerts
document.addEventListener('DOMContentLoaded', function() {
    <?php if (isset($_SESSION['success']) && $_SESSION['success'] === true): ?>
        Swal.fire({
            icon: 'success',
            title: 'Enrollment Successful!',
            html: <?= json_encode($_SESSION['success_message'] ?? 'Student successfully enrolled!') ?>,
            confirmButtonColor: '#1e88e5',
            confirmButtonText: 'Continue',
            allowOutsideClick: false
        }).then(() => {
            window.location.href = 'enroll_form.php';
        });
        <?php unset($_SESSION['success'], $_SESSION['success_message']); ?>

    <?php elseif (isset($_SESSION['error'])): ?>
        Swal.fire({
            icon: 'error',
            title: 'Enrollment Failed',
            html: '<div style="text-align:left;padding:0.5rem 0">' +
                  '<p style="margin-bottom:0.75rem">We couldn\'t complete the enrollment.</p>' +
                  '<div style="background:#fef2f2;border-left:4px solid #dc3545;padding:0.75rem 1rem;border-radius:8px;font-size:0.9rem;color:#991b1b">' +
                  <?= json_encode($_SESSION['error']) ?> +
                  '</div></div>',
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Try Again'
        });
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
});
</script>
</body>
</html>