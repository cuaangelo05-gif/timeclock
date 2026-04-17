<?php
// mark_leave.php - mark/unmark employee leave for today
require_once __DIR__ . '/admin_auth.php';
require 'config.php';

$employeeId = isset($_POST['employee_id']) ? (int)$_POST['employee_id'] : 0;
if ($employeeId <= 0) {
    header('Location: admin.php#employees');
    exit;
}

$today = date('Y-m-d');
$feedback = '';

try {
    // Ensure leaves table exists
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS leaves (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            leave_date DATE NOT NULL,
            note VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_leave (employee_id, leave_date),
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Exception $tableErr) {
        // Table might already exist, continue
    }

    // Check if already marked for today
    $stmt = $pdo->prepare('SELECT id FROM leaves WHERE employee_id = ? AND leave_date = ? LIMIT 1');
    $stmt->execute([$employeeId, $today]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Unmark leave - delete the record
        $del = $pdo->prepare('DELETE FROM leaves WHERE employee_id = ? AND leave_date = ?');
        $del->execute([$employeeId, $today]);
        $feedback = 'Leave unmarked for today.';
    } else {
        // Mark leave - insert the record
        $ins = $pdo->prepare('INSERT INTO leaves (employee_id, leave_date, note) VALUES (?, ?, ?)');
        $ins->execute([$employeeId, $today, 'On leave']);
        $feedback = 'Leave marked for today.';
    }

    // Redirect back to employees section (not dashboard)
    header('Location: admin.php#employees');
    exit;

} catch (Exception $e) {
    $feedback = 'Error: ' . $e->getMessage();
    header('Location: admin.php#employees');
    exit;
}
?>