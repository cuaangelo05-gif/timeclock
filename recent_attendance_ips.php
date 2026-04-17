<?php
require_once __DIR__ . '/admin_auth.php';
require 'config.php';

$limit = 200;
$filterType = isset($_GET['type']) ? trim($_GET['type']) : '';
$filterDate = isset($_GET['date']) ? trim($_GET['date']) : '';

$params = [];
$where = '';

if ($filterType && in_array($filterType, ['in','out'], true)) {
    $where .= ' AND a.event_type = ?';
    $params[] = $filterType;
}
if ($filterDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
    $where .= ' AND DATE(a.created_at) = ?';
    $params[] = $filterDate;
}

try {
    $sql = "SELECT a.id, a.employee_id, a.id_code, a.event_type, a.created_at, a.ip_address, COALESCE(a.department, a.branch, a.division) AS department, COALESCE(e.name, '') AS emp_name, COALESCE(e.department, e.branch, e.division) AS emp_department
            FROM attendance a
            LEFT JOIN employees e ON e.id = a.employee_id
            WHERE 1=1 {$where}
            ORDER BY a.created_at DESC
            LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
    $error = $e->getMessage();
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Recent Attendance — IPs</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    .controls { display:flex; gap:8px; align-items:center; margin:12px 0; }
    .controls form { display:inline-flex; gap:8px; align-items:center; }
    .small-muted { color: #64748b; font-size:13px; }
    .table-wrap { margin-top:8px; }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="container">
      <h1>Recent attendance — IPs</h1>
      <div class="meta">Last <?php echo (int)$limit; ?> attendance events (shows IP and department if recorded)</div>
      <div style="margin-top:8px"><a class="btn ghost" href="admin.php">&larr; Back to Admin</a></div>
    </div>
  </header>

  <main class="container">
    <div class="controls">
      <form method="get" action="recent_attendance_ips.php" aria-label="Filter attendance">
        <label class="small-muted">Type:</label>
        <select name="type">
          <option value="">All</option>
          <option value="in" <?php if($filterType === 'in') echo 'selected'; ?>>In</option>
          <option value="out" <?php if($filterType === 'out') echo 'selected'; ?>>Out</option>
        </select>
        <label class="small-muted">Date:</label>
        <input type="date" name="date" value="<?php echo htmlspecialchars($filterDate); ?>">
        <button class="btn" type="submit">Filter</button>
      </form>
    </div>

    <?php if (!empty($error)): ?>
      <div class="alert">Error loading records: <?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="table-wrap">
      <table class="data-table" role="table" aria-label="Recent attendance">
        <thead>
          <tr>
            <th>#</th>
            <th>Time</th>
            <th>Employee</th>
            <th>ID Code</th>
            <th>Employee Dept</th>
            <th>Event</th>
            <th>IP Address</th>
            <th>Record Dept</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="small">No attendance records found.</td></tr>
          <?php else: ?>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars($r['id']); ?></td>
                <td><?php echo htmlspecialchars($r['created_at']); ?></td>
                <td><?php echo htmlspecialchars($r['emp_name'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars($r['id_code'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars($r['emp_department'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars(strtoupper($r['event_type'])); ?></td>
                <td><?php echo htmlspecialchars($r['ip_address'] ?: '—'); ?></td>
                <td><?php echo htmlspecialchars($r['department'] ?: '—'); ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </main>
</body>
</html>