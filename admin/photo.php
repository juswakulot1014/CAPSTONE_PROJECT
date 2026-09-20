<?php
session_start();
include __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/paths.php";

if (!isset($_SESSION['admin_id'])) { http_response_code(403); die("Forbidden"); }

$sid = (int)($_GET['id'] ?? 0);
if ($sid <= 0) { http_response_code(400); die("Bad request"); }

$s = $conn->prepare("SELECT photo FROM students_info WHERE student_id = ?");
$s->bind_param("i", $sid);
$s->execute();
$row = $s->get_result()->fetch_assoc();
$s->close();

if (!$row || empty($row['photo'])) { http_response_code(404); die("No photo"); }

$abs = student_photo_abs_path($row['photo']);
if (!file_exists($abs)) { http_response_code(404); die("Photo missing"); }

$fi = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($fi, $abs);
finfo_close($fi);

header("Content-Type: " . $mime);
header("Content-Length: " . filesize($abs));
header("Cache-Control: private, max-age=3600");
header("X-Content-Type-Options: nosniff");

readfile($abs);
exit;