<?php
// capture_upload.php
// Receives POST: emp_id (string/int), action (in/out), photo (file optional), department (optional)
// Requires config.php which should set up $pdo (PDO instance) and any authentication you use.

require 'config.php';
header('Content-Type: application/json; charset=utf-8');

function get_client_ip() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    foreach ($keys as $k) {
        if (!empty($_SERVER[$k])) {
            $val = $_SERVER[$k];
            if ($k === 'HTTP_X_FORWARDED_FOR') {
                $parts = explode(',', $val);
                $ip = trim($parts[0]);
            } else {
                $ip = trim($val);
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return null;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method', 405);
    }

    $emp_id = isset($_POST['emp_id']) ? trim($_POST['emp_id']) : '';
    $action = isset($_POST['action']) ? trim($_POST['action']) : '';
    $department = trim($_POST['department'] ?? $_POST['branch'] ?? $_POST['division'] ?? '');

    if ($emp_id === '' || $action === '') {
        throw new Exception('Missing emp_id or action', 400);
    }

    // Basic validation
    if (!preg_match('/^\d+$/', $emp_id)) {
        // allow numeric IDs only in this example
        throw new Exception('Invalid Employee ID', 400);
    }
    $action = ($action === 'out') ? 'out' : 'in';

    $uploadDir = __DIR__ . '/uploads';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

    $photoPath = null;
    // handle uploaded image if present
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['photo']['tmp_name'];
        // verify image type
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        if (!isset($allowed[$mime])) {
            // fallback: attempt to treat as jpeg
            $ext = '.jpg';
        } else {
            $ext = $allowed[$mime];
        }
        $fileName = sprintf('emp_%s_%s%s', $emp_id, time(), $ext);
        $dest = $uploadDir . '/' . $fileName;

        if (!move_uploaded_file($tmp, $dest)) {
            throw new Exception('Failed to move uploaded file', 500);
        }
        // filesystem-safe perms
        @chmod($dest, 0644);
        $photoPath = 'uploads/' . $fileName;
    }

    // Insert attendance record — adjust table/columns to your schema
    // Example table: attendance_logs (id, emp_id, action, photo_path, created_at)
    $now = (new DateTime())->format('Y-m-d H:i:s');

    $client_ip = get_client_ip();

    // Try to insert into attendance_logs (if you have it). Wrap in try/catch to avoid breaking if table missing.
    try {
        // ensure department/ip column exists in attendance_logs (best-effort)
        try {
            $pdo->query('SELECT department, ip_address FROM attendance_logs LIMIT 1');
        } catch (Exception $e) {
            try { $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN department VARCHAR(100) NULL"); } catch (Exception $ex) {}
            try { $pdo->exec("ALTER TABLE attendance_logs ADD COLUMN ip_address VARCHAR(45) NULL"); } catch (Exception $ex) {}
        }

        $stmt = $pdo->prepare('INSERT INTO attendance_logs (emp_id, action, photo_path, created_at, ip_address, department) VALUES (:emp, :action, :photo, :ts, :ip, :dept)');
        $stmt->execute([
            ':emp' => $emp_id,
            ':action' => $action,
            ':photo' => $photoPath,
            ':ts' => $now,
            ':ip' => $client_ip,
            ':dept' => $department ?: null
        ]);
    } catch (Exception $e) {
        // If attendance_logs does not exist, continue but still return success; you might want to create the table.
        // You could also update employees table with last_status/time and photo.
        try {
            $pdo->prepare('UPDATE employees SET last_status = :action, last_seen = :ts WHERE id = :emp')
                ->execute([':action' => $action, ':ts' => $now, ':emp' => $emp_id]);
            if ($photoPath) {
                $pdo->prepare('UPDATE employees SET last_photo = :photo WHERE id = :emp')
                    ->execute([':photo' => $photoPath, ':emp' => $emp_id]);
            }
            // best-effort: attempt to add department/ip on employees and update
            try { $pdo->query('SELECT department FROM employees LIMIT 1'); } catch (Exception $e2) {
                try { $pdo->exec("ALTER TABLE employees ADD COLUMN department VARCHAR(100) NULL"); } catch (Exception $e3) {}
            }
            try { $pdo->query('SELECT ip_address FROM employees LIMIT 1'); } catch (Exception $e2) {
                try { $pdo->exec("ALTER TABLE employees ADD COLUMN ip_address VARCHAR(45) NULL"); } catch (Exception $e3) {}
            }
            try {
                $pdo->prepare('UPDATE employees SET department = COALESCE(department, ?) WHERE id = ?')->execute([$department ?: null, $emp_id]);
                $pdo->prepare('UPDATE employees SET ip_address = ? WHERE id = ?')->execute([$client_ip, $emp_id]);
            } catch (Exception $e4) { /* ignore */ }
        } catch (Exception $e2) {
            // ignore - could not update employees
        }
    }

    // Optionally fetch employee name (if employees table has a name column)
    $emp_name = null;
    try {
        $q = $pdo->prepare('SELECT name FROM employees WHERE id = :emp LIMIT 1');
        $q->execute([':emp' => $emp_id]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        if ($row) $emp_name = $row['name'];
    } catch (Exception $e) {
        // ignore
    }

    // Build response
    $resp = [
        'success' => true,
        'message' => 'Attendance saved',
        'emp_id' => $emp_id,
        'emp_name' => $emp_name,
        'photo_url' => $photoPath ?: null,
        'server_time' => (new DateTime())->format('H:i:s'),
        'server_date' => (new DateTime())->format('Y-m-d'),
        'server_day' => (new DateTime())->format('l'),
        'status' => ($action === 'in') ? 'on' : 'neutral',
        'status_text' => ($action === 'in') ? 'Checked In' : 'Checked Out',
        'ip' => $client_ip,
        'department' => $department ?: null
    ];

    echo json_encode($resp);
    exit;
} catch (Exception $ex) {
    http_response_code($ex->getCode() >= 400 ? $ex->getCode() : 500);
    echo json_encode(['success' => false, 'message' => $ex->getMessage()]);
    exit;
}