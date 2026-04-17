<?php
// employee_calendar.php - searchable month calendar per employee
// Shows status only on days that have attendance or leave records
require 'config.php';

$employeeId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($employeeId <= 0) {
    http_response_code(400);
    echo "Invalid employee id.";
    exit;
}

// fetch employee info (prefer department)
$stmt = $pdo->prepare('SELECT id, id_code, name, COALESCE(department) AS department, photo FROM employees WHERE id = ? LIMIT 1');
$stmt->execute([$employeeId]);
$emp = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$emp) {
    http_response_code(404);
    echo "Employee not found.";
    exit;
}

// optional focus date from the date picker (YYYY-MM-DD)
$focusDate = null;
if (!empty($_GET['focus']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['focus'])) {
    $focusDate = $_GET['focus'];
}

// get requested year-month (YYYY-MM)
// If a focus date is provided, show that month so the focused day is visible
if ($focusDate !== null) {
    $ym = substr($focusDate, 0, 7);
} else {
    $ym = isset($_GET['ym']) ? trim($_GET['ym']) : date('Y-m');
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
        $ym = date('Y-m');
    }
}

try {
    $firstDay = new DateTime($ym . '-01');
} catch (Exception $e) {
    $firstDay = new DateTime('first day of this month');
}
$year = (int)$firstDay->format('Y');
$month = (int)$firstDay->format('m');
$daysInMonth = (int)$firstDay->format('t');
$startDate = $firstDay->format('Y-m-01');
$endDate = $firstDay->format('Y-m-t');

// rules (same as admin)
$shiftStart = '08:30:00';
$graceMinutes = 15;
$minWorkSeconds = 4 * 3600; // half-day threshold

// build photo path (fallback to default)
$photoPath = 'uploads/default.png';
if (!empty($emp['photo']) && file_exists(__DIR__ . '/uploads/' . $emp['photo'])) {
    $photoPath = 'uploads/' . rawurlencode($emp['photo']);
}

// load attendance aggregated by date for the month (including photos)
$attendanceByDate = [];
try {
    // Check if attendance.photo column exists
    $checkPhotoCol = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'attendance' AND column_name = 'photo' LIMIT 1");
    $hasAttendancePhoto = (bool)$checkPhotoCol->fetch();
    
    $photoSelect = $hasAttendancePhoto ? ', GROUP_CONCAT(DISTINCT a.photo ORDER BY a.created_at SEPARATOR ",") AS photos' : '';
    
    $q = "
      SELECT
        DATE(a.created_at) AS day,
        MIN(CASE WHEN a.event_type = 'in' THEN a.created_at ELSE NULL END) AS first_in,
        (SELECT a2.ip_address FROM attendance a2
           WHERE a2.employee_id = a.employee_id AND a2.event_type = 'in' AND DATE(a2.created_at) = DATE(a.created_at)
           ORDER BY a2.created_at ASC LIMIT 1) AS first_in_ip,
        MAX(CASE WHEN a.event_type = 'out' THEN a.created_at ELSE NULL END) AS last_out,
        (SELECT a3.ip_address FROM attendance a3
           WHERE a3.employee_id = a.employee_id AND a3.event_type = 'out' AND DATE(a3.created_at) = DATE(a.created_at)
           ORDER BY a3.created_at DESC LIMIT 1) AS last_out_ip,
        COALESCE(MAX(a.department)) AS department
        $photoSelect
      FROM attendance a
      WHERE a.employee_id = ? AND DATE(a.created_at) BETWEEN ? AND ?
      GROUP BY DATE(a.created_at)
    ";
    $stmt = $pdo->prepare($q);
    $stmt->execute([$employeeId, $startDate, $endDate]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $attendanceByDate[$r['day']] = $r;
    }
} catch (Exception $e) {
    // if attendance table missing or other error, leave empty
}

// load leaves for the month (if table exists)
$leavesByDate = [];
try {
    $stmt = $pdo->prepare("SELECT leave_date, note FROM leaves WHERE employee_id = ? AND leave_date BETWEEN ? AND ?");
    $stmt->execute([$employeeId, $startDate, $endDate]);
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $leavesByDate[$r['leave_date']] = $r['note'] ?? '';
    }
} catch (Exception $e) {
    // ignore if leaves table missing
}

// helper to compute status for a date
function compute_day_status($date, $attendance, $leaveNote, $shiftStart, $graceMinutes, $minWorkSeconds) {
    // no attendance and no leave -> empty
    if ($leaveNote === null && !$attendance) {
        return null;
    }
    if ($leaveNote !== null) return ['label'=>'On Leave','class'=>'leave','detail'=>$leaveNote];

    $firstIn = $attendance['first_in'] ?? null;
    $lastOut = $attendance['last_out'] ?? null;

    // if either missing, mark half-day (incomplete)
    if (!$firstIn || !$lastOut) {
        $half = false;
        $early = false;
        if ($firstIn) {
            $half = (int)date('H', strtotime($firstIn)) >= 12;
        }
        if ($lastOut) {
            $early = strtotime($lastOut) < strtotime(date('Y-m-d', strtotime($lastOut)) . ' 17:30:00');
        }
        $parts = [];
        if ($half) $parts[] = 'Half day';
        if ($early) $parts[] = 'Early out';
        $label = !empty($parts) ? implode(' | ', $parts) : 'Half-day';
        $cls = 'half';
        return ['label'=>$label,'class'=>$cls,'detail'=>'In/Out incomplete'];
    }

    // compute worked seconds
    $firstTs = strtotime($firstIn);
    $lastTs = strtotime($lastOut);
    $worked = max(0, $lastTs - $firstTs);

    // half-day if first in at or after 12:00
    $half_day = (int)date('H', $firstTs) >= 12;

    // early out if last out before 17:30
    $early_out = $lastTs < strtotime(date('Y-m-d', $lastTs) . ' 17:30:00');

    // compose label
    $labels = [];
    if ($half_day) $labels[] = 'Half day';
    if ($early_out) $labels[] = 'Early out';

    if (!empty($labels)) {
        $label = implode(' | ', $labels);
        $cls = $half_day ? 'half' : 'early';
        return ['label'=>$label,'class'=>$cls,'detail'=>sprintf('In %s — Out %s', date('H:i', $firstTs), date('H:i', $lastTs))];
    }

    // fallback to on time / late logic
    $shiftDT = strtotime($date . ' ' . $shiftStart);
    $lateThreshold = $shiftDT + ($graceMinutes * 60);
    if ($firstTs > $lateThreshold) {
        $label = 'Late'; $cls = 'late';
    } else {
        $label = 'On time'; $cls = 'on';
    }

    if ($worked < $minWorkSeconds) {
        return ['label'=>'Half-day','class'=>'half','detail'=>sprintf('Worked %s', gmdate('H:i', $worked))];
    }
    return ['label'=>$label,'class'=>$cls,'detail'=>sprintf('In %s — Out %s', date('H:i', $firstTs), date('H:i', $lastTs))];
}

// small sanitizer for HTML
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// prev/next month strings
$prev = (clone $firstDay)->modify('-1 month')->format('Y-m');
$next = (clone $firstDay)->modify('+1 month')->format('Y-m');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo h($emp['name']); ?> — Attendance Calendar <?php echo h($ym); ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    :root {
      --bg: #f6f8fb;
      --card: #ffffff;
      --muted: #6b7280;
      --accent: #2563eb;
      --danger: #ef4444;
      --success: #10b981;
      --surface-2: #eef2f7;
      --radius: 10px;
      --shadow: 0 6px 18px rgba(16,24,40,0.06);
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; margin: 0; }
    body {
      background: var(--bg);
      color: #111827;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
    }

    .page-container {
      max-width: 1100px;
      margin: 0 auto;
      padding: 18px;
    }

    .header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      margin-bottom: 18px;
      flex-wrap: wrap;
    }

    .header-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .employee-avatar {
      width: 64px;
      height: 64px;
      border-radius: 12px;
      object-fit: cover;
      border: 2px solid var(--surface-2);
      background: #f8fafc;
    }

    .employee-info h1 {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
    }

    .employee-info p {
      margin: 4px 0 0 0;
      font-size: 13px;
      color: var(--muted);
    }

    .header-right {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .btn {
      padding: 10px 12px;
      border-radius: 8px;
      border: 1px solid var(--surface-2);
      background: transparent;
      color: var(--muted);
      cursor: pointer;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.12s ease;
    }

    .btn:hover {
      background: rgba(15, 23, 42, 0.04);
      color: #111827;
    }

    .btn.primary {
      background: var(--accent);
      color: #fff;
      border-color: var(--accent);
    }

    .btn.primary:hover {
      background: #1d4ed8;
    }

    .nav-group {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .date-controls {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .date-controls .month-label {
      font-weight: 600;
      min-width: 140px;
      text-align: center;
    }

    .date-search-form {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .date-search-form input[type="date"] {
      padding: 8px 10px;
      border: 1px solid var(--surface-2);
      border-radius: 8px;
      background: var(--card);
      font-size: 13px;
    }

    .card {
      background: var(--card);
      border-radius: var(--radius);
      padding: 16px;
      box-shadow: var(--shadow);
    }

    .info-banner {
      background: rgba(37, 99, 235, 0.08);
      border-left: 3px solid var(--accent);
      padding: 12px 14px;
      border-radius: 8px;
      font-size: 13px;
      color: #1e40af;
      margin-bottom: 18px;
    }

    /* Calendar Grid */
    .cal-grid {
      width: 100%;
      border-collapse: collapse;
      background: var(--card);
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow);
    }

    .cal-grid th {
      background: #fbfdff;
      color: var(--muted);
      font-weight: 700;
      font-size: 12px;
      padding: 12px;
      text-align: center;
      border-bottom: 1px solid var(--surface-2);
    }

    .cal-grid td {
      border: 1px solid var(--surface-2);
      padding: 10px;
      vertical-align: top;
      height: 110px;
      background: #fafbfc;
      cursor: pointer;
      transition: all 0.12s ease;
      position: relative;
    }

    .cal-grid td:hover:not(.cal-empty) {
      background: #fff;
      box-shadow: inset 0 0 10px rgba(37, 99, 235, 0.08);
    }

    .cal-grid td.cal-empty {
      background: transparent;
      border-color: transparent;
      cursor: default;
    }

    .day-num {
      font-weight: 700;
      font-size: 14px;
      margin-bottom: 8px;
      display: block;
      color: #111827;
    }

    .cal-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      margin-bottom: 6px;
    }

    .cal-badge.on {
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
    }

    .cal-badge.late {
      background: rgba(239, 68, 68, 0.12);
      color: #dc2626;
    }

    .cal-badge.half {
      background: rgba(249, 115, 22, 0.12);
      color: #d97706;
    }

    .cal-badge.early {
      background: rgba(249, 115, 22, 0.12);
      color: #d97706;
    }

    .cal-badge.leave {
      background: rgba(99, 102, 241, 0.12);
      color: #4f46e5;
    }

    .cal-detail {
      font-size: 12px;
      color: var(--muted);
      line-height: 1.4;
      margin-top: 4px;
    }

    .cal-time {
      display: flex;
      justify-content: space-between;
      font-size: 11px;
      color: #64748b;
      margin-top: 4px;
      padding-top: 4px;
      border-top: 1px dashed #e2e8f0;
    }

    /* Modal */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(2, 6, 23, 0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 16px;
      animation: fadeIn 0.2s ease;
    }

    .modal-backdrop.active {
      display: flex;
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal {
      background: var(--card);
      border-radius: 12px;
      max-width: 720px;
      width: 100%;
      box-shadow: 0 20px 60px rgba(16, 24, 40, 0.15);
      overflow: hidden;
      animation: slideUp 0.3s ease;
    }

    @keyframes slideUp {
      from { transform: translateY(20px); opacity: 0; }
      to { transform: translateY(0); opacity: 1; }
    }

    .modal-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 16px;
      border-bottom: 1px solid var(--surface-2);
    }

    .modal-header h2 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
    }

    .modal-close {
      background: transparent;
      border: 0;
      color: var(--muted);
      cursor: pointer;
      font-size: 24px;
      width: 32px;
      height: 32px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 6px;
      transition: all 0.12s ease;
    }

    .modal-close:hover {
      background: var(--surface-2);
      color: #111827;
    }

    .modal-body {
      padding: 16px;
      display: grid;
      grid-template-columns: 120px 1fr;
      gap: 16px;
      align-items: start;
    }

    .modal-photo {
      width: 120px;
      height: 120px;
      border-radius: 8px;
      object-fit: cover;
      border: 1px solid var(--surface-2);
      background: #f8fafc;
    }

    .modal-section {
      margin-bottom: 12px;
    }

    .modal-label {
      font-size: 12px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
      font-weight: 600;
    }

    .modal-value {
      font-size: 14px;
      font-weight: 600;
      color: #111827;
    }

    .modal-time-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid var(--surface-2);
    }

    .time-block h3 {
      margin: 0 0 4px 0;
      font-size: 12px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 600;
    }

    .time-block .time {
      font-size: 16px;
      font-weight: 700;
      color: #111827;
    }

    .time-block .ip {
      font-size: 11px;
      color: var(--muted);
      margin-top: 3px;
    }

    .photo-gallery {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
      gap: 8px;
      margin-top: 8px;
    }

    .photo-gallery img {
      width: 100%;
      height: 80px;
      border-radius: 6px;
      object-fit: cover;
      border: 1px solid var(--surface-2);
      cursor: pointer;
      transition: transform 0.12s ease, box-shadow 0.12s ease;
    }

    .photo-gallery img:hover {
      transform: scale(1.05);
      box-shadow: 0 4px 12px rgba(37, 99, 235, 0.15);
    }

    .badge-status {
      display: inline-block;
      padding: 6px 10px;
      border-radius: 6px;
      font-size: 12px;
      font-weight: 600;
      margin-top: 8px;
    }

    .badge-status.on {
      background: rgba(16, 185, 129, 0.12);
      color: #059669;
    }

    .badge-status.late {
      background: rgba(239, 68, 68, 0.12);
      color: #dc2626;
    }

    .badge-status.half {
      background: rgba(249, 115, 22, 0.12);
      color: #d97706;
    }

    .badge-status.early {
      background: rgba(249, 115, 22, 0.12);
      color: #d97706;
    }

    .legend {
      display: flex;
      gap: 16px;
      flex-wrap: wrap;
      margin-top: 16px;
      padding-top: 16px;
      border-top: 1px solid var(--surface-2);
    }

    .legend-item {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
    }

    .legend-badge {
      width: 12px;
      height: 12px;
      border-radius: 3px;
    }

    .legend-badge.on { background: #10b981; }
    .legend-badge.late { background: #ef4444; }
    .legend-badge.half { background: #f97316; }
    .legend-badge.leave { background: #6366f1; }

    /* Highlight for focused date */
    .cell-highlight {
      outline: 2px solid var(--accent);
      outline-offset: 2px;
      animation: pulseHighlight 0.8s ease-out;
    }

    @keyframes pulseHighlight {
      0% { box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.2); }
      100% { box-shadow: 0 0 0 0 rgba(37, 99, 235, 0); }
    }

    /* Responsive */
    @media (max-width: 768px) {
      .header {
        flex-direction: column;
        align-items: flex-start;
      }

      .header-right {
        width: 100%;
      }

      .date-search-form {
        width: 100%;
      }

      .date-search-form input[type="date"] {
        flex: 1;
      }

      .cal-grid td {
        height: 100px;
        padding: 8px;
      }

      .day-num {
        font-size: 13px;
      }

      .modal-body {
        grid-template-columns: 1fr;
      }

      .modal-photo {
        width: 100%;
        height: 180px;
      }
    }
  </style>
</head>
<body>
  <div class="page-container">
    <!-- Header -->
    <header class="header">
      <div class="header-left">
        <img src="<?php echo $photoPath; ?>" alt="<?php echo h($emp['name']); ?>" class="employee-avatar">
        <div class="employee-info">
          <h1><?php echo h($emp['name']); ?></h1>
          <p><strong><?php echo h($emp['id_code']); ?></strong> • <?php echo h($emp['department'] ?? 'No Department'); ?></p>
        </div>
      </div>

      <div class="header-right">
        <div class="nav-group">
          <a href="admin.php#employees" class="btn">← Back to Admin</a>
        </div>
        <div class="date-controls">
          <a href="employee_calendar.php?id=<?php echo $employeeId; ?>&amp;ym=<?php echo h($prev); ?>" class="btn">‹</a>
          <span class="month-label"><?php echo $firstDay->format('F Y'); ?></span>
          <a href="employee_calendar.php?id=<?php echo $employeeId; ?>&amp;ym=<?php echo h($next); ?>" class="btn">›</a>
        </div>
      </div>
    </header>

    <!-- Date Picker -->
    <div style="margin-top: 18px; display: flex; justify-content: right;">
      <form class="date-search-form" method="get" action="employee_calendar.php" aria-label="Go to specific date">
        <input type="hidden" name="id" value="<?php echo $employeeId; ?>">
        <input type="date" name="focus" value="<?php echo h($focusDate ?? ''); ?>" aria-label="Select date">
        <button class="btn primary" type="submit">📅 Jump to Date</button>
      </form>
    </div>

    <!-- Info Banner -->
    <div class="info-banner">
      💡 Click any day with attendance records to view detailed information including check-in/out times and photos.
    </div>

    <!-- Calendar -->
    <div class="card" style="padding: 0; overflow: hidden;">
      <table class="cal-grid" role="grid" aria-label="Attendance calendar">
        <thead>
          <tr>
            <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
          </tr>
        </thead>
        <tbody>
          <?php
          $firstWeekday = (int)$firstDay->format('w');
          $weeks = ceil(($firstWeekday + $daysInMonth) / 7);
          for ($w = 0; $w < $weeks; $w++): ?>
            <tr>
              <?php for ($d = 0; $d < 7; $d++): ?>
                <?php
                  $cellIndex = $w * 7 + $d;
                  $cellDay = $cellIndex - $firstWeekday + 1;
                  if ($cellDay < 1 || $cellDay > $daysInMonth): ?>
                    <td class="cal-empty" aria-hidden="true"></td>
                  <?php else:
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $cellDay);
                    $att = $attendanceByDate[$dateStr] ?? null;
                    $leaveNote = array_key_exists($dateStr, $leavesByDate) ? $leavesByDate[$dateStr] : null;
                    $st = compute_day_status($dateStr, $att, $leaveNote, $shiftStart, $graceMinutes, $minWorkSeconds);
                ?>
                  <td role="gridcell" 
                      aria-label="<?php echo h($dateStr . ' ' . ($st ? $st['label'] : 'No record')); ?>" 
                      data-date="<?php echo h($dateStr); ?>"
                      <?php if ($st): ?>onclick="openModal('<?php echo h($dateStr); ?>')" style="cursor: pointer;"<?php endif; ?>>
                    <span class="day-num"><?php echo $cellDay; ?></span>

                    <?php if ($st): ?>
                      <span class="cal-badge <?php echo h($st['class']); ?>"><?php echo h($st['label']); ?></span>
                      <div class="cal-detail"><?php echo h($st['detail']); ?></div>
                      <?php if ($att && !empty($att['first_in']) && !empty($att['last_out'])): ?>
                        <div class="cal-time">
                          <span><?php echo h(date('H:i', strtotime($att['first_in']))); ?></span>
                          <span><?php echo h(date('H:i', strtotime($att['last_out']))); ?></span>
                        </div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </td>
                <?php endif; ?>
              <?php endfor; ?>
            </tr>
          <?php endfor; ?>
        </tbody>
      </table>

      <div style="padding: 16px; border-top: 1px solid var(--surface-2);">
        <div class="legend">
          <div class="legend-item">
            <span class="legend-badge on"></span>
            <span>On Time</span>
          </div>
          <div class="legend-item">
            <span class="legend-badge late"></span>
            <span>Late</span>
          </div>
          <div class="legend-item">
            <span class="legend-badge half"></span>
            <span>Half-day / Early Out</span>
          </div>
          <div class="legend-item">
            <span class="legend-badge leave"></span>
            <span>On Leave</span>
          </div>
        </div>
      </div>
    </div>

    
  </div>

  <!-- Modal -->
  <div class="modal-backdrop" id="modalBackdrop">
    <div class="modal">
      <div class="modal-header">
        <h2>Attendance Details</h2>
        <button class="modal-close" id="modalClose" aria-label="Close modal">&times;</button>
      </div>
      <div class="modal-body">
        <img src="<?php echo $photoPath; ?>" alt="<?php echo h($emp['name']); ?>" class="modal-photo">
        <div>
          <div class="modal-section">
            <div class="modal-label">Employee</div>
            <div class="modal-value"><?php echo h($emp['name']); ?></div>
          </div>

          <div class="modal-section">
            <div class="modal-label">Department</div>
            <div class="modal-value" id="modalDept">—</div>
          </div>

          <div class="modal-section">
            <div class="modal-label">Date</div>
            <div class="modal-value" id="modalDate">—</div>
          </div>

          <div class="modal-time-grid">
            <div class="time-block">
              <h3>TIME In</h3>
              <div class="time" id="modalTimeIn">—</div>
              <div class="ip" id="modalInIp"></div>
            </div>
            <div class="time-block">
              <h3>TIME Out</h3>
              <div class="time" id="modalTimeOut">—</div>
              <div class="ip" id="modalOutIp"></div>
            </div>
          </div>

          <div class="modal-section">
            <div class="modal-label">Status</div>
            <div id="modalStatus"></div>
          </div>

          <div class="modal-section">
            <div class="modal-label">Captured Photos</div>
            <div class="photo-gallery" id="photoGallery"></div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script>
    const attendanceData = <?php echo json_encode($attendanceByDate, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?: '{}'; ?>;
    const modalBackdrop = document.getElementById('modalBackdrop');
    const modalClose = document.getElementById('modalClose');
    const employeeId = <?php echo $employeeId; ?>;

    function formatTime(timestamp) {
      if (!timestamp) return '—';
      try {
        const d = new Date(timestamp);
        return d.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
      } catch (e) {
        return timestamp;
      }
    }

    function formatDate(dateStr) {
      try {
        const d = new Date(dateStr + 'T00:00:00');
        return d.toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
      } catch (e) {
        return dateStr;
      }
    }

    function openModal(dateStr) {
      const day = attendanceData[dateStr];
      if (!day) {
        alert('No attendance data for this date.');
        return;
      }

      // Populate modal
      document.getElementById('modalDate').textContent = formatDate(dateStr);
      document.getElementById('modalDept').textContent = day.department || '—';
      document.getElementById('modalTimeIn').textContent = formatTime(day.first_in);
      document.getElementById('modalTimeOut').textContent = formatTime(day.last_out);
      
      document.getElementById('modalInIp').textContent = day.first_in_ip ? 'IP: ' + day.first_in_ip : '';
      document.getElementById('modalOutIp').textContent = day.last_out_ip ? 'IP: ' + day.last_out_ip : '';

      // Status badge
      const statusEl = document.getElementById('modalStatus');
      const hasBoth = day.first_in && day.last_out;
      let statusClass = 'half';
      let statusText = 'Incomplete';

      if (day.first_in && day.last_out) {
        const firstTs = new Date(day.first_in).getTime();
        const lastTs = new Date(day.last_out).getTime();
        const worked = Math.max(0, lastTs - firstTs);
        const shiftStart = new Date(dateStr + 'T08:30:00').getTime();
        const lateThreshold = shiftStart + (15 * 60 * 1000);

        if (firstTs > lateThreshold) {
          statusClass = 'late';
          statusText = 'Late';
        } else {
          statusClass = 'on';
          statusText = 'On Time';
        }

        if (worked < (4 * 3600 * 1000)) {
          statusClass = 'half';
          statusText = 'Half-day';
        }
      }

      statusEl.innerHTML = '<span class="badge-status ' + statusClass + '">' + statusText + '</span>';

      // Photos
      const photoGallery = document.getElementById('photoGallery');
      photoGallery.innerHTML = '';
      if (day.photos) {
        const photos = day.photos.split(',').filter(p => p.trim());
        if (photos.length > 0) {
          photos.forEach(photo => {
            const img = document.createElement('img');
            img.src = 'uploads/' + encodeURIComponent(photo.trim());
            img.alt = 'Attendance photo';
            img.addEventListener('click', () => {
              window.open(img.src, '_blank');
            });
            photoGallery.appendChild(img);
          });
        } else {
          photoGallery.innerHTML = '<p style="color: var(--muted); font-size: 12px;">No photos captured.</p>';
        }
      } else {
        photoGallery.innerHTML = '<p style="color: var(--muted); font-size: 12px;">No photos captured.</p>';
      }

      // Show modal
      modalBackdrop.classList.add('active');
    }

    function closeModal() {
      modalBackdrop.classList.remove('active');
    }

    modalClose.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', (e) => {
      if (e.target === modalBackdrop) closeModal();
    });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeModal();
    });

    // Highlight focused date
    (function() {
      const focusDate = <?php echo $focusDate ? json_encode($focusDate) : 'null'; ?>;
      if (!focusDate) return;

      document.addEventListener('DOMContentLoaded', () => {
        const cell = document.querySelector('[data-date="' + focusDate + '"]');
        if (cell) {
          cell.classList.add('cell-highlight');
          cell.scrollIntoView({ behavior: 'smooth', block: 'center' });
          setTimeout(() => cell.classList.remove('cell-highlight'), 3500);
        }
      });
    })();
  </script>
</body>
</html>