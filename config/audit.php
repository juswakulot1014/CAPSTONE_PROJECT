<?php
// config/audit.php — lightweight audit log.
//
// Logs admin actions to the audit_log table.
//
// Usage:
//   require_once __DIR__ . '/audit.php';    // usually from bootstrap or auth
//   audit_log($conn, 'admin.login',    ['type' => 'admin',   'id' => 1]);
//   audit_log($conn, 'student.delete', ['type' => 'student', 'id' => 42]);
//   audit_log($conn, 'grade.edit',     ['type' => 'grade',   'id' => 7, 'old' => 88, 'new' => 92]);
//
// The function never throws. If the log write fails (missing table, DB down),
// it silently logs to error_log and returns — the page keeps running.
// Never let auditing break the actual request.

if (!function_exists('audit_log')) {

    function audit_log(mysqli $conn, string $action, array $meta = []): void
    {
        static $stmt = null;

        if ($stmt === null) {
            $stmt = $conn->prepare("
                INSERT INTO audit_log
                    (admin_id, action, target_type, target_id,
                     ip_address, user_agent, meta, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            if (!$stmt) {
                error_log('audit_log: prepare failed: ' . $conn->error);
                return;
            }
        }

        $admin_id    = (int)($_SESSION['admin_id'] ?? 0);
        $target_type = isset($meta['type']) ? (string)$meta['type'] : null;
        $target_id   = isset($meta['id'])   ? (int)$meta['id']      : null;
        $ip          = $_SERVER['REMOTE_ADDR'] ?? '';
        $ua          = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
        $json        = !empty($meta)
            ? json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : null;

        // mysqli bind_param: types string is 7 chars for 7 placeholders.
        // admin_id(i), action(s), target_type(s), target_id(i),
        // ip_address(s), user_agent(s), meta(s)
        $stmt->bind_param(
            'ississs',
            $admin_id,
            $action,
            $target_type,
            $target_id,
            $ip,
            $ua,
            $json
        );

        if (!$stmt->execute()) {
            error_log('audit_log: execute failed: ' . $stmt->error);
        }
        // Do NOT close the statement — it is reused across the request via `static`.
    }
}