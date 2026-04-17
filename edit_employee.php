<?php
// edit_employee.php - edit employee details form
require_once __DIR__ . '/admin_auth.php';
require 'config.php';

$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($employeeId <= 0) {
    header('Location: admin.php');
    exit;
}

// Load employee
try {
    $stmt = $pdo->prepare('SELECT id, id_code, name, department, position, employment_type, shift, attendance_status, date_hired, photo FROM employees WHERE id = ? LIMIT 1');
    $stmt->execute([$employeeId]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        header('Location: admin.php');
        exit;
    }
} catch (Exception $e) {
    header('Location: admin.php');
    exit;
}

// Get departments
try {
    $stmt = $pdo->query("SELECT name FROM departments ORDER BY name ASC");
    $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $departments = ['General', 'CamFinLending', 'CashManagement', 'MAGtech'];
}

$feedback = '';

// Handle update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_employee') {
    $name = trim($_POST['name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $employment_type = trim($_POST['employment_type'] ?? '');
    $shift = trim($_POST['shift'] ?? '');
    $attendance_status = trim($_POST['attendance_status'] ?? '');
    $date_hired = trim($_POST['date_hired'] ?? '');

    if ($name === '' || $department === '' || $position === '' || $employment_type === '' || $shift === '' || $attendance_status === '') {
        $feedback = "All fields are required.";
    } else {
        try {
            $stmt = $pdo->prepare('UPDATE employees SET name = ?, department = ?, position = ?, employment_type = ?, shift = ?, attendance_status = ?, date_hired = ? WHERE id = ?');
            $stmt->execute([$name, $department, $position, $employment_type, $shift, $attendance_status, $date_hired, $employeeId]);
            $feedback = "Employee updated successfully.";
            $emp['name'] = $name;
            $emp['department'] = $department;
            $emp['position'] = $position;
            $emp['employment_type'] = $employment_type;
            $emp['shift'] = $shift;
            $emp['attendance_status'] = $attendance_status;
            $emp['date_hired'] = $date_hired;
        } catch (Exception $e) {
            $feedback = "Error updating employee: " . $e->getMessage();
        }
    }
}

$photoPath = (!empty($emp['photo']) && file_exists(__DIR__ . '/uploads/' . $emp['photo']))
    ? 'uploads/' . rawurlencode($emp['photo'])
    : 'uploads/default.png';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Edit Employee — Admin</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --muted: #6b7280;
      --accent: #2563eb;
      --radius: 10px;
      --shadow: 0 6px 18px rgba(16, 24, 40, 0.06);
    }
    body { margin: 0; background: var(--bg); }
    .container { max-width: 720px; margin: 28px auto; padding: 0 16px; }
    .card { background: var(--card); padding: 24px; border-radius: var(--radius); box-shadow: var(--shadow); }
    .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
    .form-row.full { grid-column: 1 / -1; }
    .field { display: flex; flex-direction: column; gap: 6px; }
    label { font-size: 13px; color: var(--muted); font-weight: 600; }
    input, select { padding: 10px; border: 1px solid #e6eef8; border-radius: 8px; font-size: 14px; }
    .photo-preview { width: 120px; height: 120px; border-radius: 8px; object-fit: cover; border: 1px solid #eef2f7; }
    .actions { display: flex; gap: 8px; align-items: center; margin-top: 24px; }
    .btn { padding: 10px 14px; border-radius: 8px; border: 0; font-weight: 700; cursor: pointer; }
    .btn.primary { background: var(--accent); color: #fff; }
    .btn.ghost { background: transparent; border: 1px solid #e6eef8; color: var(--muted); }
    .feedback { padding: 12px; border-radius: 8px; margin-bottom: 16px; color: #0f1724; background: #eef7ff; border-left: 4px solid var(--accent); }
    @media (max-width: 620px) { .form-row { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <div class="container">
    <header style="margin-bottom: 20px;">
      <a href="admin.php" class="btn ghost" style="display: inline-block; margin-bottom: 12px;">← Back to Admin</a>
      <h1 style="margin: 0 0 6px 0;">Edit Employee</h1>
      <p style="margin: 0; color: var(--muted); font-size: 13px;">Update employee information</p>
    </header>

    <div class="card">
      <?php if ($feedback): ?>
        <div class="feedback"><?php echo htmlspecialchars($feedback); ?></div>
      <?php endif; ?>

      <div style="display: flex; gap: 16px; margin-bottom: 20px; align-items: flex-start;">
        <img src="<?php echo $photoPath; ?>" alt="<?php echo htmlspecialchars($emp['name']); ?>" class="photo-preview">
        <div>
          <div style="font-weight: 700; font-size: 16px;"><?php echo htmlspecialchars($emp['name']); ?></div>
          <div style="color: var(--muted); font-size: 13px; margin-top: 4px;">ID: <?php echo htmlspecialchars($emp['id_code']); ?></div>
        </div>
      </div>

      <form method="post" action="edit_employee.php?id=<?php echo $employeeId; ?>" novalidate>
        <input type="hidden" name="action" value="update_employee">

        <div class="form-row">
          <div class="field">
            <label for="name">Full Name</label>
            <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($emp['name']); ?>" required>
          </div>
          <div class="field">
            <label for="id_code">Employee ID Code (read-only)</label>
            <input id="id_code" type="text" value="<?php echo htmlspecialchars($emp['id_code']); ?>" disabled>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="department">Department</label>
            <select id="department" name="department" required>
              <?php foreach ($departments as $d): ?>
                <option value="<?php echo htmlspecialchars($d); ?>" <?php if ($emp['department'] === $d) echo 'selected'; ?>>
                  <?php echo htmlspecialchars($d); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label for="position">Position / Role</label>
            <input id="position" name="position" type="text" value="<?php echo htmlspecialchars($emp['position'] ?? ''); ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="employment_type">Employment Type</label>
            <select id="employment_type" name="employment_type" required>
              <option value="Full-time" <?php if ($emp['employment_type'] === 'Full-time') echo 'selected'; ?>>Full-time</option>
              <option value="Part-time" <?php if ($emp['employment_type'] === 'Part-time') echo 'selected'; ?>>Part-time</option>
              <option value="Contract" <?php if ($emp['employment_type'] === 'Contract') echo 'selected'; ?>>Contract</option>
            </select>
          </div>
          <div class="field">
            <label for="shift">Work Schedule / Shift</label>
            <input id="shift" name="shift" type="text" placeholder="e.g. 08:30-17:30" value="<?php echo htmlspecialchars($emp['shift'] ?? ''); ?>" required>
          </div>
        </div>

        <div class="form-row">
          <div class="field">
            <label for="attendance_status">Attendance Status</label>
            <select id="attendance_status" name="attendance_status" required>
              <option value="Active" <?php if ($emp['attendance_status'] === 'Active') echo 'selected'; ?>>Active</option>
              <option value="Inactive" <?php if ($emp['attendance_status'] === 'Inactive') echo 'selected'; ?>>Inactive</option>
              <option value="Suspended" <?php if ($emp['attendance_status'] === 'Suspended') echo 'selected'; ?>>Suspended</option>
            </select>
          </div>
          <div class="field">
            <label for="date_hired">Date Hired</label>
            <input id="date_hired" name="date_hired" type="date" value="<?php echo htmlspecialchars($emp['date_hired'] ?? date('Y-m-d')); ?>" required>
          </div>
        </div>

        <div class="actions">
          <button type="submit" class="btn primary">Save Changes</button>
          <a href="admin.php" class="btn ghost">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</body>
</html>