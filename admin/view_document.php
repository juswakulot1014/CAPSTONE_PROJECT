<?php
// admin/view_document.php — secure document streaming endpoint
// Usage:  view_document.php?id=123          (inline in browser)
//         view_document.php?id=123&dl=1     (force download)

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/paths.php";
require_once __DIR__ . "/../config/crypto.php";

/* ───────────────────────────────────────────────
   1. Auth gate — admin must be logged in
   ─────────────────────────────────────────────── */
$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if ($admin_id === null) {
    http_response_code(401);
    exit('Unauthorized');
}

/* ───────────────────────────────────────────────
   2. Input validation
   ─────────────────────────────────────────────── */
$doc_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$doc_id || $doc_id < 1) {
    http_response_code(400);
    exit('Invalid document id');
}
$force_download = isset($_GET['dl']) && $_GET['dl'] === '1';

/* ───────────────────────────────────────────────
   3. Fetch the document row
   ─────────────────────────────────────────────── */
$stmt = $conn->prepare("
    SELECT d.id, d.student_id, d.document_name, d.file_path, d.file_mime, d.file_name,
           s.student_id_number, s.first_name, s.last_name
    FROM entrance_documents d
    JOIN students_info s ON s.student_id = d.student_id
    WHERE d.id = ? AND d.submitted = 1 AND d.file_path IS NOT NULL
    LIMIT 1
");
if (!$stmt) {
    error_log('view_document: prepare failed: ' . $conn->error);
    http_response_code(500);
    exit('Server error');
}
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found');
}

/* ───────────────────────────────────────────────
   4. Resolve the file on disk (must live in DOCUMENTS_DIR)
   ─────────────────────────────────────────────── */
$abs_path = realpath(DOCUMENTS_DIR . basename($doc['file_path']));
$base_dir = realpath(DOCUMENTS_DIR);

if ($abs_path === false || $base_dir === false || !str_starts_with($abs_path, $base_dir)) {
    error_log("view_document: path escape attempt for doc {$doc_id}");
    http_response_code(404);
    exit('Document not found');
}

$plain = doc_read_decrypt($abs_path);
if ($plain === false || $plain === '') {
    error_log("view_document: decrypt failed for doc {$doc_id}");
    http_response_code(500);
    exit('Cannot read document');
}

/* ───────────────────────────────────────────────
   5. Audit log
   ─────────────────────────────────────────────── */
$ip = $_SERVER['REMOTE_ADDR'] ?? '';
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
$log = $conn->prepare("
    INSERT INTO document_access_log (document_id, student_id, admin_id, action, ip_address, user_agent, accessed_at)
    VALUES (?, ?, ?, ?, ?, ?, NOW())
");
if ($log) {
    $action = $force_download ? 'download' : 'view';
    $log->bind_param('iiisss',
        $doc_id,
        $doc['student_id'],
        $admin_id,
        $action,
        $ip,
        $ua
    );
    $log->execute();
    $log->close();
}

/* ───────────────────────────────────────────────
   6. Stream to client
   ─────────────────────────────────────────────── */
$allowed_mimes = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
];
$mime = $doc['file_mime'];
if (!isset($allowed_mimes[$mime])) $mime = 'application/octet-stream';

$ext_map = [
    'application/pdf' => 'pdf',
    'image/jpeg'      => 'jpg',
    'image/png'       => 'png',
    'application/octet-stream' => 'bin',
];

$safe_name = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $doc['file_name'] ?: ($doc['document_name'] . '.' . ($ext_map[$mime] ?? 'bin')));
$safe_name = str_replace(["\r", "\n", '"'], '', $safe_name);
if ($safe_name === '') {
    $safe_name = 'document.' . ($ext_map[$mime] ?? 'bin');
}

$disposition = $force_download ? 'attachment' : 'inline';

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($plain));
header("Content-Disposition: {$disposition}; filename=\"{$safe_name}\"");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('Expires: 0');
header("Content-Security-Policy: sandbox; default-src 'none';");

while (ob_get_level()) ob_end_clean();
echo $plain;
exit;