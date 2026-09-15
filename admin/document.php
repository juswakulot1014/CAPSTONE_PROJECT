<?php
session_start();
include __DIR__ . "/../config/db.php";

if (!isset($_SESSION['admin_id'])) {
    http_response_code(403);
    die("Forbidden");
}

$id   = (int)($_GET['id'] ?? 0);
$mode = ($_GET['mode'] ?? 'view') === 'download' ? 'download' : 'view';

if ($id <= 0) { http_response_code(400); die("Bad request"); }

$stmt = $conn->prepare("
    SELECT entrance_id, document_name, submitted, file_path,
           file_data, file_mime, file_name
    FROM entrance_documents
    WHERE entrance_id = ?
");
$stmt->bind_param("i", $id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc || empty($doc['submitted'])) {
    http_response_code(404);
    die("Document not found");
}

$data  = null;
$mime  = $doc['file_mime'] ?: 'application/octet-stream';
$fname = $doc['file_name'] ?: ('document_' . $id);

// Prefer BLOB, fall back to disk
if (!empty($doc['file_data'])) {
    $data = $doc['file_data'];
} elseif (!empty($doc['file_path'])) {
    $abs = __DIR__ . "/../" . $doc['file_path'];
    if (!file_exists($abs)) { http_response_code(404); die("File missing on disk"); }
    $data = file_get_contents($abs);
    if (empty($doc['file_mime'])) {
        $fi   = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $abs);
        finfo_close($fi);
    }
    if (empty($doc['file_name'])) {
        $fname = basename($doc['file_path']);
    }
} else {
    http_response_code(404);
    die("No file data available");
}

// Sanitize filename for the header
$fname = preg_replace('/[^A-Za-z0-9._\- ]/', '_', $fname);

header("Content-Type: " . $mime);
header("Content-Length: " . strlen($data));
header("X-Content-Type-Options: nosniff");
header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . $fname . '"');

echo $data;
exit;