<?php
// process_timein.php - Uses SERVER TIME for storage, CLIENT PC TIME for display
// FIXED: Photos now saved to ATTENDANCE table per time-in/time-out event
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Manila');
require 'config.php';

function json_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['status' => 'error', 'message' => 'Invalid request method'], 405);
}

$rawEmp = trim($_POST['emp_id'] ?? $_POST['id_code'] ?? '');
$action  = strtolower(trim($_POST['action'] ?? ''));
$departmentInput = trim($_POST['department'] ?? $_POST['branch'] ?? $_POST['division'] ?? '');

if ($rawEmp === '' || $action === '') json_out(['status'=>'error','message'=>'Missing parameters'], 400);
if (!in_array($action, ['in','out'], true)) json_out(['status'=>'error','message'=>'Invalid action'], 400);

function getExistingColumns(PDO $pdo, string $table, array $cols): array {
    if (empty($cols)) return [];
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ($placeholders)";
    $params = array_merge([$table], $cols);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

try {
    // Load employee
    $empCols = getExistingColumns($pdo, 'employees', ['department','branch','division','photo','id_code','last_status']);
    $selectParts = ['id','name'];
    $deptParts = [];
    foreach (['department','branch','division'] as $c) if (in_array($c, $empCols, true)) $deptParts[] = $c;
    if (!empty($deptParts)) $selectParts[] = 'COALESCE(' . implode(', ', $deptParts) . ') AS department';
    else $selectParts[] = "'' AS department";
    if (in_array('last_status', $empCols, true)) $selectParts[] = 'last_status';
    if (in_array('photo', $empCols, true)) $selectParts[] = 'photo';
    $hasIdCode = in_array('id_code', $empCols, true);

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM employees WHERE id = ?';
    if ($hasIdCode) $sql .= ' OR id_code = ?';
    $sql .= ' LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $params = $hasIdCode ? [$rawEmp, $rawEmp] : [$rawEmp];
    $stmt->execute($params);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$emp) {
        json_out([
            'status'=>'not_found',
            'message'=>'Employee ID not recognized.',
            'server_date'=>date('Y-m-d'),
            'day'=>date('l')
        ], 404);
    }

    $department = $departmentInput !== '' ? $departmentInput : (!empty($emp['department']) ? $emp['department'] : 'General');
    $employeeId = (int)$emp['id'];
    
    // ✅ USE SERVER TIME for storage
    $serverNow = date('Y-m-d H:i:s');  // Server time in Asia/Manila
    $serverDate = date('Y-m-d');
    $serverTime = date('H:i:s');
    
    // For display to user, send both server and client times
    $clientTime = isset($_POST['client_time']) ? trim($_POST['client_time']) : $serverNow;

    // Check today's attendance using SERVER DATE
    $checkIn = $pdo->prepare("SELECT id, created_at FROM attendance WHERE employee_id = ? AND event_type = 'in' AND DATE(created_at) = ? ORDER BY created_at ASC LIMIT 1");
    $checkIn->execute([$employeeId, $serverDate]);
    $existingIn = $checkIn->fetch(PDO::FETCH_ASSOC);
    
    $checkOut = $pdo->prepare("SELECT id, created_at FROM attendance WHERE employee_id = ? AND event_type = 'out' AND DATE(created_at) = ? ORDER BY created_at ASC LIMIT 1");
    $checkOut->execute([$employeeId, $serverDate]);
    $existingOut = $checkOut->fetch(PDO::FETCH_ASSOC);

    $attCols = getExistingColumns($pdo, 'attendance', ['department','id_code','photo']);
    $attHasDept = in_array('department', $attCols, true);
    $attHasIdCode = in_array('id_code', $attCols, true);
    $attHasPhoto = in_array('photo', $attCols, true);

    $idCodeForInsert = null;
    if ($attHasIdCode) {
        if (!empty($emp['id_code'])) $idCodeForInsert = (string)$emp['id_code'];
        elseif ($rawEmp !== '' && !ctype_digit((string)$rawEmp)) $idCodeForInsert = (string)$rawEmp;
    }

    // ✅ UPDATED FUNCTION: Now accepts $photoFilename parameter
    $doSafeInsert = function($pdo, $table, $employeeId, $eventType, $department, $idCodeForInsert, $attHasDept, $attHasIdCode, $attHasPhoto, $photoFilename = null) {
        $cols = ['employee_id', 'event_type'];
        $params = [$employeeId, $eventType];
        if ($attHasIdCode && $idCodeForInsert !== null) { 
            $cols[] = 'id_code'; 
            $params[] = $idCodeForInsert; 
        }
        if ($attHasDept) { 
            $cols[] = 'department'; 
            $params[] = $department; 
        }
        // ✅ ADD PHOTO TO ATTENDANCE TABLE (if table has photo column AND photo exists)
        if ($attHasPhoto && $photoFilename !== null) { 
            $cols[] = 'photo'; 
            $params[] = $photoFilename; 
        }
        $cols[] = 'created_at';
        $placeholders = implode(',', array_fill(0, count($params), '?')) . ', NOW()';
        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . $placeholders . ')';
        $ins = $pdo->prepare($sql);
        $ins->execute($params);
        return $pdo->lastInsertId();
    };

    $make_tags_from_times = function(?string $timeInStr, ?string $timeOutStr) {
        $half_day = false;
        $early_out = false;
        if ($timeInStr) {
            $t = strtotime($timeInStr);
            if ($t !== false) {
                $hour = (int)date('H', $t);
                $half_day = ($hour >= 12);
            }
        }
        if ($timeOutStr) {
            $to = strtotime($timeOutStr);
            if ($to !== false) {
                $cut = strtotime(date('Y-m-d', $to) . ' 17:30:00');
                $early_out = ($to < $cut);
            }
        }
        $parts = [];
        if ($half_day) $parts[] = 'Half day';
        if ($early_out) $parts[] = 'Early out';
        $text = implode(' | ', $parts);
        return ['half_day'=>(bool)$half_day,'early_out'=>(bool)$early_out,'attendance_status_text'=>$text];
    };

    $compute_status = function($dt) {
        $shiftStart = '08:30:00';
        $graceMinutes = 15;
        $dateStr = substr($dt, 0, 10);
        try {
            $shiftStartDT = new DateTime($dateStr . ' ' . $shiftStart);
            $graceDT = clone $shiftStartDT;
            $graceDT->modify("+{$graceMinutes} minutes");
            $t = new DateTime($dt);
        } catch (Exception $e) {
            return 'Unknown';
        }
        return ($t <= $graceDT) ? 'On Time' : 'Late';
    };

    $employeeResp = [
        'name' => $emp['name'],
        'photo' => (!empty($emp['photo']) && file_exists(__DIR__.'/uploads/'.$emp['photo'])) ? ('uploads/' . rawurlencode($emp['photo'])) : 'uploads/default.png'
    ];

    // ✅ UPDATED: Handle photo upload (save to ATTENDANCE table, not employees)
    $attendancePhotoFilename = null;
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['photo']; 
        $tmp = $f['tmp_name'];
        $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $ext = $allowed[$mime] ?? 'jpg';
        $safe = preg_replace('/[^A-Za-z0-9_\-]/','_', $employeeId);
        $fileName = "{$safe}_" . time() . ".{$ext}";
        $uploadDir = __DIR__ . '/uploads';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
        $dest = $uploadDir . '/' . $fileName;
        if (@move_uploaded_file($tmp, $dest)) {
            @chmod($dest, 0644);
            // ✅ SAVE FILENAME FOR ATTENDANCE TABLE (NOT EMPLOYEE TABLE)
            $attendancePhotoFilename = $fileName;
        }
    }

    // TIME IN
    if ($action === 'in') {
        if ($existingIn) {
            $tags = $make_tags_from_times($existingIn['created_at'], null);
            json_out([
                'status'=>'already_in',
                'message'=>'You have already timed in today.',
                'employee'=>$employeeResp,
                'time_in'=>$existingIn['created_at'],
                'attendance_status'=>$compute_status($existingIn['created_at']),
                'attendance_status_text'=>$tags['attendance_status_text'],
                'half_day'=>$tags['half_day'],'early_out'=>$tags['early_out'],
                'action'=>'in',
                'department'=>$department,
                'server_date'=>$serverDate,
                'day'=>date('l', strtotime($serverDate))
            ], 200);
        }

        if ($existingOut) {
            json_out([
                'status'=>'already_out',
                'message'=>'You already timed out. Time-in not allowed.',
                'employee'=>$employeeResp,
                'server_date'=>$serverDate,
                'day'=>date('l', strtotime($serverDate))
            ], 400);
        }

        $pdo->beginTransaction();
        try {
            // ✅ PASS PHOTO FILENAME TO INSERT
            $doSafeInsert($pdo, 'attendance', $employeeId, 'in', $department, $idCodeForInsert, $attHasDept, $attHasIdCode, $attHasPhoto, $attendancePhotoFilename);
            if (in_array('department', $empCols, true)) {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW(), department = COALESCE(department, ?, department) WHERE id = ?');
                $upd->execute(['in', $department, $employeeId]);
            } else {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW() WHERE id = ?');
                $upd->execute(['in', $employeeId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['status'=>'error','message'=>'Server error: '.$e->getMessage(),'server_date'=>$serverDate,'day'=>date('l')], 500);
        }

        $tags = $make_tags_from_times($serverNow, null);
        json_out([
            'status'=>'ok',
            'message'=>'Time-in recorded successfully.',
            'employee'=>$employeeResp,
            'server_date'=>$serverDate,
            'day'=>date('l', strtotime($serverDate)),
            'time_in'=>$serverNow,
            'attendance_status'=>$compute_status($serverNow),
            'attendance_status_text'=>$tags['attendance_status_text'],
            'half_day'=>$tags['half_day'],'early_out'=>$tags['early_out'],
            'action'=>'in',
            'department'=>$department,
            'display_time'=>$clientTime  // Show client time for display
        ], 200);
    } 
    // TIME OUT
    else {
        if (!$existingIn) {
            json_out(['status'=>'error','message'=>'No time-in found today.','employee'=>$employeeResp,'server_date'=>$serverDate,'day'=>date('l')], 400);
        }
        if ($existingOut) {
            $tags = $make_tags_from_times($existingIn['created_at'], $existingOut['created_at']);
            json_out([
                'status'=>'already_out',
                'message'=>'You already timed out today.',
                'employee'=>$employeeResp,
                'time_out'=>$existingOut['created_at'],
                'attendance_status_text'=>$tags['attendance_status_text'],
                'half_day'=>$tags['half_day'],'early_out'=>$tags['early_out'],
                'department'=>$department,
                'server_date'=>$serverDate,
                'day'=>date('l', strtotime($serverDate))
            ], 400);
        }

        $pdo->beginTransaction();
        try {
            // ✅ PASS PHOTO FILENAME TO INSERT
            $doSafeInsert($pdo, 'attendance', $employeeId, 'out', $department, $idCodeForInsert, $attHasDept, $attHasIdCode, $attHasPhoto, $attendancePhotoFilename);
            if (in_array('department', $empCols, true)) {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW(), department = COALESCE(department, ?, department) WHERE id = ?');
                $upd->execute(['out', $department, $employeeId]);
            } else {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW() WHERE id = ?');
                $upd->execute(['out', $employeeId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            json_out(['status'=>'error','message'=>'Server error: '.$e->getMessage(),'server_date'=>$serverDate,'day'=>date('l')], 500);
        }

        $tags = $make_tags_from_times($existingIn['created_at'], $serverNow);
        json_out([
            'status'=>'ok',
            'message'=>'Time-out recorded successfully.',
            'employee'=>$employeeResp,
            'server_date'=>$serverDate,
            'day'=>date('l', strtotime($serverDate)),
            'time_out'=>$serverNow,
            'action'=>'out',
            'department'=>$department,
            'attendance_status_text'=>$tags['attendance_status_text'],
            'half_day'=>$tags['half_day'],'early_out'=>$tags['early_out'],
            'display_time'=>$clientTime  // Show client time for display
        ], 200);
    }

} catch (Exception $ex) {
    json_out(['status'=>'error','message'=>'Server error: '.$ex->getMessage(),'server_date'=>date('Y-m-d'),'day'=>date('l')], 500);
}
?>