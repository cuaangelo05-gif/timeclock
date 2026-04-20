<?php
$start = microtime(true);

header('Content-Type: application/json; charset=utf-8');
require 'config.php';

// Use Philippine time explicitly
date_default_timezone_set('Asia/Manila');

$input = trim($_POST['id_code'] ?? $_POST['emp_id'] ?? '');
if ($input === '') {
    echo json_encode(['status'=>'error','message'=>'Please enter an ID code.']);
    exit;
}

if (!preg_match('/^[A-Za-z0-9\-_]{1,64}$/', $input)) {
    echo json_encode(['status'=>'error','message'=>'Invalid ID format.']);
    exit;
}

// helper: return existing columns on a table
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
    // Build a safe SELECT that only references existing columns
    $empCols = getExistingColumns($pdo, 'employees', ['department','branch','division','photo','last_status','id_code']);
    $selectParts = ['id','name'];
    $deptParts = [];
    foreach (['department','branch','division'] as $c) {
        if (in_array($c, $empCols, true)) $deptParts[] = $c;
    }
    if (!empty($deptParts)) $selectParts[] = 'COALESCE(' . implode(', ', $deptParts) . ') AS department';
    else $selectParts[] = "'' AS department";
    if (in_array('last_status', $empCols, true)) $selectParts[] = 'last_status';
    if (in_array('photo', $empCols, true)) $selectParts[] = 'photo';
    $hasIdCode = in_array('id_code', $empCols, true);

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM employees WHERE ';
    // Accept either id (numeric) or id_code
    $sql .= 'id = ?';
    if ($hasIdCode) $sql .= ' OR id_code = ?';
    $sql .= ' LIMIT 1';

    $stmt = $pdo->prepare($sql);
    $params = $hasIdCode ? [$input, $input] : [$input];
    $stmt->execute($params);
    $emp = $stmt->fetch();

    if (!$emp) {
        echo json_encode(['status'=>'not_found','message'=>"ID not recognized. Ask admin to register your ID.", 'id_code'=>$input]);
        exit;
    }

    $employeeId = (int)$emp['id'];
    $department = $emp['department'] ?? 'General';
    $todayDate = date('Y-m-d');
    $now = date('Y-m-d H:i:s');

    // find today's first in and first out (no branch/division references here)
    $inCheck = $pdo->prepare("SELECT id, created_at FROM attendance WHERE employee_id = ? AND event_type = 'in' AND DATE(created_at) = ? ORDER BY created_at ASC LIMIT 1");
    $inCheck->execute([$employeeId, $todayDate]);
    $firstIn = $inCheck->fetch();

    $outCheck = $pdo->prepare("SELECT id, created_at FROM attendance WHERE employee_id = ? AND event_type = 'out' AND DATE(created_at) = ? ORDER BY created_at ASC LIMIT 1");
    $outCheck->execute([$employeeId, $todayDate]);
    $firstOut = $outCheck->fetch();

    // decide new event (toggle by last_status if available)
    $lastStatus = $emp['last_status'] ?? null;
    $newEvent = ($lastStatus === 'in') ? 'out' : 'in';

    // compute simple on-time/late for display (shift start 09:00)
    $compute_status = function($datetimeStr, $dateStr = null) {
        $shiftStart = SHIFT_START_TIME;
        $graceMinutes = GRACE_PERIOD_MINUTES;
        if ($dateStr === null) $dateStr = date('Y-m-d');
        try {
            $shiftStartDT = new DateTime($dateStr . ' ' . $shiftStart);
            $graceDT = clone $shiftStartDT;
            $graceDT->modify("+{$graceMinutes} minutes");
            $t = new DateTime($datetimeStr);
        } catch (Exception $e) {
            return 'Unknown';
        }
        return ($t <= $graceDT) ? 'On Time' : 'Late';
    };

    if ($newEvent === 'in') {
        if ($firstIn) {
            echo json_encode([
                'status' => 'already_in',
                'message' => 'You have already timed in today.',
                'employee' => [
                    'name' => $emp['name'],
                    'photo' => (!empty($emp['photo']) && file_exists(__DIR__.'/uploads/'.$emp['photo'])) ? ('uploads/' . rawurlencode($emp['photo'])) : 'uploads/default.png'
                ],
                'server_date' => $todayDate,
                'day' => date('l', strtotime($todayDate)),
                'time_in' => $firstIn['created_at'],
                'attendance_status' => $compute_status($firstIn['created_at'], $todayDate),
                'action' => 'in',
                'department' => $department
            ]);
            exit;
        }

        if ($firstOut) {
            echo json_encode([
                'status' => 'error',
                'message' => 'You have already timed out for today. Time-In is no longer allowed.',
                'department' => $department
            ]);
            exit;
        }

        // insert time-in - include department only if attendance table has that column
        $attCols = getExistingColumns($pdo, 'attendance', ['department']);
        $pdo->beginTransaction();
        try {
            if (!empty($attCols)) {
                $ins = $pdo->prepare('INSERT INTO attendance (employee_id, id_code, event_type, department, created_at) VALUES (?, ?, ?, ?, NOW())');
                $ins->execute([$employeeId, $input, 'in', $department]);
            } else {
                $ins = $pdo->prepare('INSERT INTO attendance (employee_id, id_code, event_type, created_at) VALUES (?, ?, ?, NOW())');
                $ins->execute([$employeeId, $input, 'in']);
            }
            // update employees.last_status (and department if exists)
            $empDeptCols = getExistingColumns($pdo, 'employees', ['department']);
            if (!empty($empDeptCols)) {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW(), department = COALESCE(department, ?, department) WHERE id = ?');
                $upd->execute(['in', $department, $employeeId]);
            } else {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW() WHERE id = ?');
                $upd->execute(['in', $employeeId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        $status = $compute_status($now, $todayDate);
        echo json_encode([
            'status' => 'ok',
            'message' => 'Time-in recorded successfully.',
            'employee' => [
                'name' => $emp['name'],
                'photo' => (!empty($emp['photo']) && file_exists(__DIR__.'/uploads/'.$emp['photo'])) ? ('uploads/' . rawurlencode($emp['photo'])) : 'uploads/default.png'
            ],
            'server_date' => $todayDate,
            'day' => date('l'),
            'time_in' => $now,
            'attendance_status' => $status,
            'action' => 'in',
            'department' => $department
        ]);
        exit;
    } else {
        if (!$firstIn) {
            echo json_encode(['status' => 'error', 'message' => 'No time-in found today. Please time in first.', 'department' => $department]);
            exit;
        }
        if ($firstOut) {
            echo json_encode(['status' => 'already_out', 'message' => 'You have already timed out today.', 'department' => $department]);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $attCols = getExistingColumns($pdo, 'attendance', ['department']);
            if (!empty($attCols)) {
                $ins = $pdo->prepare('INSERT INTO attendance (employee_id, id_code, event_type, department, created_at) VALUES (?, ?, ?, ?, NOW())');
                $ins->execute([$employeeId, $input, 'out', $department]);
            } else {
                $ins = $pdo->prepare('INSERT INTO attendance (employee_id, id_code, event_type, created_at) VALUES (?, ?, ?, NOW())');
                $ins->execute([$employeeId, $input, 'out']);
            }
            $empDeptCols = getExistingColumns($pdo, 'employees', ['department']);
            if (!empty($empDeptCols)) {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW(), department = COALESCE(department, ?, department) WHERE id = ?');
                $upd->execute(['out', $department, $employeeId]);
            } else {
                $upd = $pdo->prepare('UPDATE employees SET last_status = ?, last_timestamp = NOW() WHERE id = ?');
                $upd->execute(['out', $employeeId]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        echo json_encode([
            'status' => 'ok',
            'message' => 'Time-out recorded successfully.',
            'employee' => [
                'name' => $emp['name'],
                'photo' => (!empty($emp['photo']) && file_exists(__DIR__.'/uploads/'.$emp['photo'])) ? ('uploads/' . rawurlencode($emp['photo'])) : 'uploads/default.png'
            ],
            'server_date' => $todayDate,
            'day' => date('l'),
            'time_out' => $now,
            'action' => 'out',
            'department' => $department
        ]);
        exit;
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error: '.$e->getMessage()]);
    exit;
}