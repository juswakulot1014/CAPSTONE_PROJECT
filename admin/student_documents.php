<?php
// admin/student_documents.php — list all documents for a student, with view/download/upload
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../config/db.php";
require_once __DIR__ . "/../config/paths.php";

/* ── 1. Auth ────────────────────────────────────── */
$admin_id = $_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? null;
if ($admin_id === null) {
    header('Location: admin_login.php');
    exit;
}

/* ── 2. CSRF for the admin upload widget ────────── */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/* ── 3. Resolve student ─────────────────────────── */
$student_id = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT);
if (!$student_id || $student_id < 1) {
    http_response_code(400);
    exit('Invalid student id');
}

$stmt = $conn->prepare("
    SELECT student_id, student_id_number, lrn, first_name, middle_name, last_name, ext_name,
           sex, birth_date, email, phone
    FROM students_info
    WHERE student_id = ?
    LIMIT 1
");
if (!$stmt) { http_response_code(500); exit('Server error'); }
$stmt->bind_param('i', $student_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) { http_response_code(404); exit('Student not found'); }

$full_name = trim(
    ($student['first_name'] ?? '') . ' ' .
    ($student['middle_name'] ?? '') . ' ' .
    ($student['last_name'] ?? '') . ' ' .
    ($student['ext_name'] ?? '')
);
$full_name = preg_replace('/\s+/', ' ', $full_name);

/* ── 4. Fetch the student's documents ───────────── */
$doc_rows = [];
$q = $conn->prepare("
    SELECT id, document_name, submitted, uploaded_at, file_path, file_mime, file_name, uploaded_by
    FROM entrance_documents
    WHERE student_id = ?
    ORDER BY submitted DESC, document_name ASC
");
if ($q) {
    $q->bind_param('i', $student_id);
    $q->execute();
    $res = $q->get_result();
    while ($r = $res->fetch_assoc()) $doc_rows[] = $r;
    $q->close();
}

/* ── 5. Summary counters ────────────────────────── */
$total_docs   = count($doc_rows);
$uploaded_cnt = 0;
$missing_cnt  = 0;
foreach ($doc_rows as $d) {
    if (!empty($d['file_path']) && (int)$d['submitted'] === 1) $uploaded_cnt++;
    else                                                     $missing_cnt++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Documents — <?= htmlspecialchars($full_name) ?> | USAT College</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    *{margin:0;padding:0;box-sizing:border-box}
    body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;color:#1a202c;padding-bottom:3rem}
    body::before{content:'';position:fixed;top:0;left:0;width:100%;height:100%;background:url('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?q=80&w=2070') center/cover no-repeat;opacity:0.05;pointer-events:none;z-index:-1}
    .hero-header{background:linear-gradient(135deg,rgba(30,136,229,0.95),rgba(21,101,192,0.95));backdrop-filter:blur(10px);padding:2rem 1rem;text-align:center;color:white;border-bottom:4px solid #ffd700;margin-bottom:2rem}
    .hero-header img{max-width:90px;border-radius:50%;border:4px solid #ffd700;box-shadow:0 10px 30px rgba(0,0,0,0.2)}
    .hero-header h1{font-size:1.7rem;font-weight:700;margin-top:0.8rem}
    .hero-header p{font-size:1rem;opacity:0.95}
    .form-container{max-width:1200px;margin:0 auto;padding:0 1.5rem}
    .section-card{background:rgba(255,255,255,0.98);border-radius:20px;padding:1.8rem;margin-bottom:1.8rem;box-shadow:0 20px 40px -15px rgba(0,0,0,0.15)}
    .section-header{display:flex;align-items:center;gap:12px;margin-bottom:1.5rem;padding-bottom:1rem;border-bottom:2px solid #e2e8f0}
    .section-header i{font-size:1.8rem;color:#1e88e5}
    .section-header h2{font-size:1.35rem;font-weight:600;color:#1a202c}
    .student-card{background:linear-gradient(135deg,#eef2ff,#e0e7ff);border-left:5px solid #4f46e5;border-radius:16px;padding:1.5rem;margin-bottom:1.5rem}
    .student-card h3{color:#1e3c72;font-weight:700;margin-bottom:0.5rem}
    .student-meta{display:flex;flex-wrap:wrap;gap:1rem;font-size:0.88rem;color:#4a5568}
    .student-meta span strong{color:#2d3748}
    .summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.5rem}
    .summary-tile{background:white;border-radius:14px;padding:1rem 1.25rem;box-shadow:0 4px 12px rgba(0,0,0,0.05);border-left:4px solid #1e88e5}
    .summary-tile.uploaded{border-left-color:#10b981}
    .summary-tile.missing{border-left-color:#f59e0b}
    .summary-tile .label{font-size:0.72rem;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase}
    .summary-tile .value{font-size:1.6rem;font-weight:800;color:#1a202c;margin-top:0.25rem}
    .doc-table{width:100%;border-collapse:separate;border-spacing:0 0.6rem}
    .doc-table thead th{font-size:0.72rem;font-weight:700;color:#64748b;letter-spacing:0.5px;text-transform:uppercase;padding:0.4rem 0.75rem;text-align:left}
    .doc-table tbody tr{background:linear-gradient(135deg,#f8fafc,#f1f5f9);border-radius:14px;transition:all 0.2s}
    .doc-table tbody tr:hover{background:linear-gradient(135deg,#eff6ff,#dbeafe);transform:translateY(-1px);box-shadow:0 4px 16px rgba(30,136,229,0.1)}
    .doc-table tbody td{padding:1rem 0.75rem;vertical-align:middle;border:none}
    .doc-table tbody td:first-child{border-radius:14px 0 0 14px}
    .doc-table tbody td:last-child{border-radius:0 14px 14px 0}
    .doc-label{font-weight:600;color:#1a202c;display:flex;align-items:center;gap:0.5rem}
    .status-badge{display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.75rem;border-radius:50px;font-size:0.78rem;font-weight:600}
    .status-badge.uploaded{background:#dcfce7;color:#065f46}
    .status-badge.missing{background:#fef3c7;color:#92400e}
    .action-buttons{display:flex;gap:0.4rem;flex-wrap:wrap}
    .btn-icon{width:36px;height:36px;border-radius:10px;border:none;display:inline-flex;align-items:center;justify-content:center;transition:all 0.2s;text-decoration:none}
    .btn-view{background:#e0e7ff;color:#4338ca}
    .btn-view:hover{background:#c7d2fe;color:#3730a3}
    .btn-download{background:#dbeafe;color:#1d4ed8}
    .btn-download:hover{background:#bfdbfe;color:#1e40af}
    .btn-upload{background:#fef3c7;color:#b45309}
    .btn-upload:hover{background:#fde68a;color:#92400e}
    .btn-back{background:linear-gradient(135deg,#6c757d 0%,#5a6268 100%);color:white;padding:0.8rem 1.8rem;border-radius:50px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:0.5rem;transition:all 0.3s}
    .btn-back:hover{color:white;transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,0.15)}
    .empty-state{text-align:center;padding:3rem 1rem;color:#64748b}
    .empty-state i{font-size:3rem;color:#cbd5e1;margin-bottom:1rem}
    .modal-content{border-radius:20px;border:none;overflow:hidden}
    .modal-header{background:linear-gradient(135deg,#1e88e5,#1565c0);color:white;border-bottom:none;padding:1.25rem 1.5rem}
    .modal-header h5{font-weight:700}
    .modal-body{padding:1.75rem}
    .modal-footer{border-top:1px solid #e2e8f0;padding:1rem 1.5rem}
    .form-control{border:2px solid #e2e8f0;border-radius:12px;padding:0.7rem 0.9rem;font-size:0.95rem}
    .form-control:focus{border-color:#1e88e5;box-shadow:0 0 0 3px rgba(30,136,229,0.1)}
    .btn-primary-custom{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;border:none;border-radius:50px;padding:0.7rem 1.8rem;font-weight:600;transition:all 0.3s}
    .btn-primary-custom:hover{transform:translateY(-1px);box-shadow:0 8px 20px rgba(102,126,234,0.4);color:white}
    .btn-secondary-custom{background:#f1f5f9;color:#475569;border:none;border-radius:50px;padding:0.7rem 1.8rem;font-weight:600}
    .btn-secondary-custom:hover{background:#e2e8f0;color:#1e293b}
    .preview-thumb{max-width:100%;max-height:60vh;border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,0.15)}
    .swal2-popup{border-radius:20px !important;padding:2rem 1.5rem !important}
    .swal2-title{font-size:1.4rem !important;font-weight:700 !important}
    .swal2-confirm{border-radius:50px !important;padding:0.7rem 2rem !important;font-weight:600 !important}
</style>
</head>
<body>

<div class="hero-header">
    <img src="../../assets/img/usat.jpg" alt="USAT Logo" onerror="this.src='https://via.placeholder.com/90'">
    <h1><i class="fas fa-folder-open me-2"></i>Student Documents</h1>
    <p>View, download, and manage entrance requirements</p>
</div>

<div class="form-container">

    <!-- STUDENT SUMMARY -->
    <div class="student-card">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h3><i class="fas fa-user-graduate me-2"></i><?= htmlspecialchars($full_name ?: 'Unnamed Student') ?></h3>
                <div class="student-meta">
                    <?php if (!empty($student['student_id_number'])): ?>
                        <span><strong>Student ID:</strong> <?= htmlspecialchars($student['student_id_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student['lrn'])): ?>
                        <span><strong>LRN:</strong> <?= htmlspecialchars($student['lrn']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student['sex'])): ?>
                        <span><strong>Sex:</strong> <?= htmlspecialchars($student['sex']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student['birth_date'])): ?>
                        <span><strong>Birth Date:</strong> <?= htmlspecialchars(date('F d, Y', strtotime($student['birth_date']))) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student['email'])): ?>
                        <span><strong>Email:</strong> <?= htmlspecialchars($student['email']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($student['phone'])): ?>
                        <span><strong>Phone:</strong> <?= htmlspecialchars($student['phone']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- SUMMARY TILES -->
    <div class="summary-grid">
        <div class="summary-tile">
            <div class="label">Total Docs</div>
            <div class="value"><?= $total_docs ?></div>
        </div>
        <div class="summary-tile uploaded">
            <div class="label">Uploaded</div>
            <div class="value"><?= $uploaded_cnt ?></div>
        </div>
        <div class="summary-tile missing">
            <div class="label">Missing / Pending</div>
            <div class="value"><?= $missing_cnt ?></div>
        </div>
    </div>

    <!-- DOCUMENT TABLE -->
    <div class="section-card">
        <div class="section-header">
            <i class="fas fa-paperclip"></i>
            <h2>Entrance Documents</h2>
        </div>

        <?php if (empty($doc_rows)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p class="mb-0">No documents have been recorded for this student yet.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="doc-table">
                    <thead>
                        <tr>
                            <th style="width:38%">Document</th>
                            <th style="width:16%">Status</th>
                            <th style="width:20%">File</th>
                            <th style="width:14%">Uploaded</th>
                            <th style="width:12%">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($doc_rows as $d):
                            $has_file = !empty($d['file_path']) && (int)$d['submitted'] === 1;
                            $is_img   = $has_file && strpos((string)$d['file_mime'], 'image/') === 0;
                            $is_pdf   = $has_file && $d['file_mime'] === 'application/pdf';
                        ?>
                            <tr>
                                <td>
                                    <div class="doc-label">
                                        <i class="fas <?= $is_img ? 'fa-image text-primary' : ($is_pdf ? 'fa-file-pdf text-danger' : 'fa-file text-secondary') ?>"></i>
                                        <?= htmlspecialchars($d['document_name']) ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($has_file): ?>
                                        <span class="status-badge uploaded"><i class="fas fa-check-circle"></i> Uploaded</span>
                                    <?php else: ?>
                                        <span class="status-badge missing"><i class="fas fa-clock"></i> Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($has_file): ?>
                                        <small class="text-muted"><?= htmlspecialchars($d['file_name'] ?: '—') ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($d['uploaded_at'])): ?>
                                        <small class="text-muted"><?= htmlspecialchars(date('M d, Y H:i', strtotime($d['uploaded_at']))) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">—</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="action-buttons">
                                        <?php if ($has_file): ?>
                                            <a href="view_document.php?id=<?= (int)$d['id'] ?>" target="_blank"
                                               class="btn-icon btn-view" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="view_document.php?id=<?= (int)$d['id'] ?>&dl=1"
                                               class="btn-icon btn-download" title="Download">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        <?php else: ?>
                                            <button type="button"
                                                    class="btn-icon btn-upload"
                                                    title="Upload"
                                                    onclick="openUploadModal(<?= (int)$d['id'] ?>, <?= json_encode($d['document_name'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP) ?>)">
                                                <i class="fas fa-cloud-upload-alt"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="text-center mb-4">
        <a href="javascript:history.back()" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
</div>

<!-- UPLOAD MODAL -->
<div class="modal fade" id="uploadModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="mb-0"><i class="fas fa-cloud-upload-alt me-2"></i>Upload Document</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="uploadForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="document_id" id="uploadDocId" value="">

                    <p class="mb-3">Uploading: <strong id="uploadDocName">—</strong></p>

                    <div class="mb-3">
                        <label for="uploadFileInput" class="form-label fw-semibold small">Select File</label>
                        <input type="file" class="form-control" id="uploadFileInput" name="document_file"
                               accept=".pdf,.jpg,.jpeg,.png" required>
                        <small class="text-muted">Accepted: PDF, JPG, PNG. Max 10 MB.</small>
                    </div>

                    <div id="uploadProgress" class="progress" style="height:8px;display:none;">
                        <div class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width:0%"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-secondary-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom" id="uploadSubmitBtn">
                        <i class="fas fa-upload me-2"></i>Upload
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
const uploadModalEl = document.getElementById('uploadModal');
const uploadModal   = new bootstrap.Modal(uploadModalEl);

function openUploadModal(docId, docName) {
    document.getElementById('uploadDocId').value = docId;
    document.getElementById('uploadDocName').textContent = docName;
    document.getElementById('uploadFileInput').value = '';
    document.getElementById('uploadProgress').style.display = 'none';
    document.querySelector('#uploadProgress .progress-bar').style.width = '0%';
    uploadModal.show();
}

document.getElementById('uploadForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const fileInput = document.getElementById('uploadFileInput');
    if (!fileInput.files.length) return;

    const fd = new FormData(this);
    const xhr = new XMLHttpRequest();

    const progressBox = document.getElementById('uploadProgress');
    const progressBar = progressBox.querySelector('.progress-bar');
    const submitBtn   = document.getElementById('uploadSubmitBtn');

    progressBox.style.display = 'block';
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';

    xhr.upload.addEventListener('progress', function(ev) {
        if (ev.lengthComputable) {
            const pct = Math.round((ev.loaded / ev.total) * 100);
            progressBar.style.width = pct + '%';
        }
    });

    xhr.addEventListener('load', function() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Upload';

        let res;
        try { res = JSON.parse(xhr.responseText); }
        catch (err) { res = { success: false, message: 'Invalid server response' }; }

        if (res.success) {
            uploadModal.hide();
            Swal.fire({
                icon: 'success',
                title: 'Uploaded!',
                text: 'The document was uploaded successfully.',
                confirmButtonColor: '#1e88e5',
                confirmButtonText: 'OK'
            }).then(() => location.reload());
        } else {
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: res.message || 'Something went wrong.',
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Try Again'
            });
        }
    });

    xhr.addEventListener('error', function() {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-upload me-2"></i>Upload';
        Swal.fire({
            icon: 'error',
            title: 'Network Error',
            text: 'Could not reach the server. Please try again.',
            confirmButtonColor: '#dc3545'
        });
    });

    xhr.open('POST', 'upload_document.php', true);
    xhr.send(fd);
});
</script>
</body>
</html>