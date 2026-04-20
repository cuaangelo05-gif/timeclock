<?php
// attendance.php - ENSURE TIMEZONE SET FIRST
date_default_timezone_set('Asia/Manila');

require 'config.php';

require_once __DIR__ . '/admin_auth.php';
require 'config.php';

// Move this OUTSIDE the try block to use globally
function check_table_columns($pdo, $table, array $cols) {
    if (empty($cols)) return [];
    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $sql = "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name IN ($placeholders)";
    $params = array_merge([$table], $cols);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ... rest of code

// determine month/year to display
$year = isset($_GET['y']) ? (int)$_GET['y'] : (int)date('Y');
$month = isset($_GET['m']) ? (int)$_GET['m'] : (int)date('n');

// normalize
if ($month < 1 || $month > 12) { $month = (int)date('n'); }
if ($year < 1970 || $year > 2100) { $year = (int)date('Y'); }

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate)); // last day of month

// check attendance table columns we might rely on
$attCols = check_table_columns($pdo, 'attendance', ['photo', 'ip_address', 'event_type', 'created_at', 'employee_id']);

// build select
$selectExtra = '';
if (in_array('photo', $attCols, true)) $selectExtra .= 'a.photo AS att_photo, ';
if (in_array('ip_address', $attCols, true)) $selectExtra .= 'a.ip_address, ';
$selectExtra = rtrim($selectExtra, ', ');

// fetch attendance rows for the displayed month (with associated employee basic info)
try {
    $sql = "
        SELECT a.employee_id, a.event_type, a.created_at, " . ($selectExtra ? $selectExtra . ',' : '') . "
               e.name AS emp_name, e.department AS emp_dept, e.photo AS emp_photo, e.shift AS emp_shift, e.position AS emp_position, e.id_code AS emp_idcode
        FROM attendance a
        LEFT JOIN employees e ON e.id = a.employee_id
        WHERE DATE(a.created_at) BETWEEN ? AND ?
        ORDER BY a.created_at ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $rows = [];
}

// Structure data: grouped by date -> employee_id -> events[]
$data = [];
foreach ($rows as $r) {
    $dt = date('Y-m-d', strtotime($r['created_at']));
    $empId = (int)$r['employee_id'];
    if ($empId <= 0) continue;

    if (!isset($data[$dt])) $data[$dt] = [];
    if (!isset($data[$dt][$empId])) {
        $data[$dt][$empId] = [
            'employee' => [
                'id' => $empId,
                'name' => $r['emp_name'] ?? '',
                'department' => $r['emp_dept'] ?? '',
                'photo' => (!empty($r['emp_photo']) && file_exists(__DIR__ . '/uploads/' . $r['emp_photo'])) ? 'uploads/' . rawurlencode($r['emp_photo']) : 'uploads/default.png',
                'shift' => $r['emp_shift'] ?? '',
                'position' => $r['emp_position'] ?? '',
                'id_code' => $r['emp_idcode'] ?? '',
            ],
            'events' => []
        ];
    }
    $evt = [
        'type' => $r['event_type'] ?? '',
        'ts' => $r['created_at'],
    ];
    if (isset($r['att_photo'])) {
        $evt['photo'] = (!empty($r['att_photo']) && file_exists(__DIR__ . '/uploads/' . $r['att_photo'])) ? 'uploads/' . rawurlencode($r['att_photo']) : null;
    }
    if (isset($r['ip_address'])) $evt['ip'] = $r['ip_address'];
    $data[$dt][$empId]['events'][] = $evt;
}

// For display counts (how many employees had any attendance that day)
$countsByDate = [];
foreach ($data as $dt => $emps) {
    $countsByDate[$dt] = count($emps);
}

// A minimal month calendar generator (server-side) to render grid
$firstOfMonthTs = strtotime($startDate);
$firstWeekday = (int)date('N', $firstOfMonthTs); // 1 (Mon) to 7 (Sun)
$daysInMonth = (int)date('t', $firstOfMonthTs);

// previous/next month helpers
$prev = date('Y-m', strtotime($startDate . ' -1 month'));
$next = date('Y-m', strtotime($startDate . ' +1 month'));

// Prepare JSON-encoded attendance data for client-side (safe)
$attendanceJson = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$countsJson = json_encode($countsByDate);
$currentDate = date('Y-m-d');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Attendance — Calendar</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="admin.css">
  <style>
    :root{
      --bg:#f6f8fb;
      --card:#ffffff;
      --muted:#6b7280;
      --accent:#2563eb;
      --danger:#ef4444;
      --success:#10b981;
      --surface-2:#eef2f7;
      --radius:10px;
      --shadow:0 6px 18px rgba(16,24,40,0.06);
      --gap:16px;
      font-family: Inter, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
    }
    body{margin:18px;background:var(--bg);color:#111827}
    .container{max-width:1100px;margin:0 auto;display:grid;grid-template-columns:320px 1fr;gap:var(--gap)}
    .card{background:var(--card);border-radius:var(--radius);padding:14px;box-shadow:var(--shadow);}

    /* Calendar */
    .calendar { display:flex;flex-direction:column;gap:8px; }
    .cal-header { display:flex;align-items:center;justify-content:space-between;gap:8px }
    .cal-nav { display:flex;gap:8px;align-items:center }
    .cal-month { font-weight:700;font-size:16px }
    .cal-grid { display:grid; grid-template-columns: repeat(7, 1fr); gap:6px; margin-top:6px; }
    .cal-weekday { font-size:12px;color:var(--muted); text-align:center }
    .cal-day {
      min-height:72px;
      border-radius:8px;
      padding:8px;
      box-sizing:border-box;
      background:transparent;
      cursor:pointer;
      position:relative;
      display:flex;
      flex-direction:column;
      justify-content:space-between;
      transition:background .12s ease, transform .06s ease;
    }
    .cal-day:hover { background: rgba(15,23,42,0.02); }
    .cal-day.inactive { opacity:0.45; cursor:default; }
    .cal-day .date { font-weight:600; font-size:13px; }
    .cal-day .meta { font-size:12px; color:var(--muted) }

    .cal-day.selected { background: linear-gradient(90deg, rgba(37,99,235,0.08), rgba(37,99,235,0.03)); box-shadow: inset 3px 0 0 var(--accent); }

    .badge-count {
      display:inline-block;padding:4px 8px;border-radius:999px;background:var(--surface-2);font-size:12px;color:var(--muted);
    }

    /* Attendance list (right column) */
    .attendance-list { display:flex;flex-direction:column;gap:8px; }
    .att-row { display:flex;align-items:center;gap:12px;padding:10px;border-radius:8px;border:1px solid #f1f5f9;background:transparent; }
    .att-thumb { width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid #eef2f7; }
    .att-info { flex:1; min-width:0; }
    .att-name { font-weight:600 }
    .att-meta { color:var(--muted); font-size:13px }
    .att-times { display:flex;gap:10px;align-items:center;color:var(--muted);font-size:13px }
    .att-actions { display:flex;gap:8px;align-items:center }

    .btn-compact { background:transparent;border:1px solid var(--surface-2);padding:6px 8px;border-radius:8px;cursor:pointer;color:var(--muted) }
    .btn-primary { background:var(--accent);color:#fff;border:0;padding:8px 10px;border-radius:8px;cursor:pointer }

    /* Modal */
    .modal-backdrop { position:fixed;inset:0;background:rgba(2,6,23,0.45);display:none;align-items:center;justify-content:center;z-index:9999;padding:16px }
    .modal { background:var(--card);border-radius:12px;max-width:720px;width:100%;box-shadow:var(--shadow);overflow:hidden }
    .modal-body { padding:16px; display:grid;grid-template-columns: 120px 1fr; gap:12px; align-items:start }
    .modal-photo { width:120px;height:120px;border-radius:8px;object-fit:cover;border:1px solid #eef2f7; background:#f8fafc }
    .modal-row { margin-bottom:10px }
    .modal-label { color:var(--muted); font-size:13px }
    .modal-value { font-weight:600; margin-top:4px }

    .badge { display:inline-block;padding:6px 8px;border-radius:999px;font-size:12px }
    .badge.on { background:rgba(16,185,129,0.12); color:var(--success) }
    .badge.absent { background:rgba(239,68,68,0.08); color:var(--danger) }
    .badge.late { background:rgba(245,158,11,0.08); color:#f59e0b }
    .badge.half { background:rgba(249,115,22,0.08); color:#f97316 }
    .badge.early { background:rgba(239,68,68,0.07); color:var(--danger) }
    .modal-close { float:right;margin:8px; background:transparent;border:0;color:var(--muted);cursor:pointer }

    /* Responsive */
    @media (max-width: 980px) {
      .container { grid-template-columns: 1fr; }
      .cal-day { min-height:64px; }
      .modal-body { grid-template-columns: 1fr; }
      .modal-photo { width:100%; height:200px; }
    }
  </style>
</head>
<body>
  <div class="container">
    <!-- left: calendar -->
    <div class="card calendar" aria-label="Attendance calendar">
      <div class="cal-header">
        <div class="cal-month">
          <?php echo date('F Y', strtotime($startDate)); ?>
        </div>
        <div class="cal-nav">
          <a class="btn-compact" href="?y=<?php echo date('Y', strtotime($startDate . ' -1 month')); ?>&m=<?php echo date('n', strtotime($startDate . ' -1 month')); ?>">‹ Prev</a>
          <a class="btn-compact" href="?y=<?php echo date('Y'); ?>&m=<?php echo date('n'); ?>">Today</a>
          <a class="btn-compact" href="?y=<?php echo date('Y', strtotime($startDate . ' +1 month')); ?>&m=<?php echo date('n', strtotime($startDate . ' +1 month')); ?>">Next ›</a>
        </div>
      </div>

      <div class="cal-grid" aria-hidden="false">
        <!-- weekdays -->
        <?php
        $weekdays = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
        foreach ($weekdays as $wd) {
            echo "<div class=\"cal-weekday\">$wd</div>";
        }

        // print blank cells before first day
        $cell = 1;
        $printed = 0;
        $startBlank = $firstWeekday - 1; // number of blanks (Mon=1 -> 0 blanks)
        for ($i=0;$i<$startBlank;$i++) {
            echo '<div class="cal-day inactive"></div>';
            $printed++;
            $cell++;
        }

        // loop days
        for ($d=1; $d<=$daysInMonth; $d++, $printed++) {
            $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
            $isToday = ($dateStr === date('Y-m-d'));
            $count = isset($countsByDate[$dateStr]) ? (int)$countsByDate[$dateStr] : 0;
            $selectedClass = ($dateStr === ($selected = ($_GET['date'] ?? $currentDate))) ? ' selected' : '';
            // We'll set selected client-side; server output is initial state.
            echo '<div class="cal-day' . ($isToday ? ' today' : '') . '" data-date="' . $dateStr . '">';
            echo '<div class="date">' . $d . '</div>';
            if ($count > 0) {
                echo '<div class="meta"><span class="badge-count" title="' . $count . ' attendance records">' . $count . '</span></div>';
            } else {
                echo '<div class="meta">&nbsp;</div>';
            }
            echo '</div>';
        }

        // trailing blanks to complete the row
        while (($printed % 7) !== 0) {
            echo '<div class="cal-day inactive"></div>';
            $printed++;
        }
        ?>
      </div>
      <div style="margin-top:10px;font-size:13px;color:var(--muted)">Click a date to view attendance for that day. Use month nav to switch months.</div>
    </div>

    <!-- right: attendance list -->
    <div class="card attendance-list" aria-live="polite">
      <div style="display:flex;justify-content:space-between;align-items:center">
        <div>
          <div style="font-weight:700" id="attListTitle">Attendance — <?php echo htmlspecialchars($currentDate); ?></div>
          <div class="small muted" id="attListSubtitle">Showing records for selected date</div>
        </div>
        <div>
          <button class="btn-compact" id="clearDate">Show All</button>
        </div>
      </div>

      <div id="attListContainer" style="display:flex;flex-direction:column;gap:8px;margin-top:8px;min-height:120px">
        <!-- populated by JavaScript -->
        <div class="small muted">Select a date on the calendar to view attendance</div>
      </div>
    </div>
  </div>

  <!-- Modal (hidden by default) -->
  <div class="modal-backdrop" id="modalBackdrop" role="dialog" aria-modal="true" aria-hidden="true">
    <div class="modal" role="document" aria-labelledby="modalTitle">
      <button class="modal-close" id="modalClose" aria-label="Close">&times;</button>
      <div style="padding:12px;border-bottom:1px solid #f1f5f9">
        <div id="modalTitle" style="font-weight:700">Attendance summary</div>
      </div>
      <div class="modal-body">
        <img src="" alt="Employee photo" id="modalPhoto" class="modal-photo">
        <div>
          <div class="modal-row">
            <div class="modal-label">Full name</div>
            <div class="modal-value" id="modalName">—</div>
          </div>
          <div class="modal-row">
            <div class="modal-label">Department</div>
            <div class="modal-value" id="modalDept">—</div>
          </div>

          <div style="margin-top:8px;border-top:1px dashed #f1f5f9;padding-top:8px">
            <div class="modal-row">
              <div class="modal-label">Time In</div>
              <div class="modal-value" id="modalTimeIn">—</div>
              <div class="small muted" id="modalInPhotoContainer"></div>
            </div>

            <div class="modal-row">
              <div class="modal-label">Time Out</div>
              <div class="modal-value" id="modalTimeOut">—</div>
              <div class="small muted" id="modalOutPhotoContainer"></div>
            </div>

            <div class="modal-row" style="margin-top:8px">
              <div class="modal-label">Status</div>
              <div id="modalStatus" style="margin-top:6px"></div>
            </div>
          </div>
        </div>
      </div>

      <div style="padding:12px;border-top:1px solid #f1f5f9;display:flex;justify-content:flex-end;gap:8px">
        <button class="btn-compact" id="modalCloseBtn">Close</button>
      </div>
    </div>
  </div>

  <script>
    // Preloaded attendance data (date -> employeeId -> { employee, events[] })
    const attendanceData = <?php echo $attendanceJson ?: '{}'; ?>;
    const countsByDate = <?php echo $countsJson ?: '{}'; ?>;
    const today = '<?php echo $currentDate; ?>';

    // Utility: find first event of type 'in' and last of type 'out'
    function getInOut(events) {
      const ins = events.filter(e => (e.type || '').toLowerCase() === 'in').sort((a,b)=> new Date(a.ts) - new Date(b.ts));
      const outs = events.filter(e => (e.type || '').toLowerCase() === 'out').sort((a,b)=> new Date(a.ts) - new Date(b.ts));
      return {
        in: ins.length ? ins[0] : null,
        out: outs.length ? outs[outs.length-1] : null
      };
    }

    // Date selection & list rendering
    const attListContainer = document.getElementById('attListContainer');
    const attListTitle = document.getElementById('attListTitle');
    const attListSubtitle = document.getElementById('attListSubtitle');
    const calendarDays = Array.from(document.querySelectorAll('.cal-day')).filter(d => d.dataset && d.dataset.date);
    let selectedDate = today;

    function formatTimeLocal(timestamp) {
  if (!timestamp) return '—';
  const d = new Date(timestamp);
  // Force Asia/Manila timezone (UTC+8) for display
  // Since database stores in Manila time, we need to account for browser timezone difference
  const manilaOptions = {
    timeZone: 'Asia/Manila',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  };
  return d.toLocaleString('en-PH', manilaOptions);
}

    function clearSelection() {
      calendarDays.forEach(c => c.classList.remove('selected'));
      selectedDate = null;
      attListTitle.textContent = 'Attendance — All dates';
      attListSubtitle.textContent = 'Showing records for the loaded month';
      renderList(null);
    }

    function selectDate(dateStr, pushState = false) {
      selectedDate = dateStr;
      calendarDays.forEach(c => c.classList.toggle('selected', c.dataset.date === dateStr));
      attListTitle.textContent = 'Attendance — ' + dateStr;
      const cnt = countsByDate[dateStr] || 0;
      attListSubtitle.textContent = cnt ? (cnt + ' employee' + (cnt>1 ? 's' : '') + ' recorded') : 'No attendance records for this date';
      renderList(dateStr);
      if (pushState) history.pushState({date: dateStr}, '', '#'+dateStr);
    }

    function renderList(dateStr) {
      attListContainer.innerHTML = '';
      const target = dateStr ? (attendanceData[dateStr] || {}) : null;
      if (!dateStr) {
        // Show a friendly placeholder
        attListContainer.innerHTML = '<div class="small muted">Select a date to see attendance records. Click "Show All" to return to this message.</div>';
        return;
      }

      const entries = Object.values(target);
      if (!entries.length) {
        attListContainer.innerHTML = '<div class="small muted">No attendance records for this date.</div>';
        return;
      }

      entries.forEach(item => {
        const emp = item.employee;
        const io = getInOut(item.events || []);
        const el = document.createElement('div');
        el.className = 'att-row';
        el.innerHTML = `
          <img class="att-thumb" src="${emp.photo || 'uploads/default.png'}" alt="${escapeHtml(emp.name)}">
          <div class="att-info">
            <div class="att-name">${escapeHtml(emp.name)} <span style="font-weight:400;color:var(--muted);">(${escapeHtml(emp.id_code || '')})</span></div>
            <div class="att-meta">${escapeHtml(emp.department || '—')} — ${escapeHtml(emp.position || '')}</div>
            <div class="att-times" style="margin-top:6px">
              <div>In: <strong>${io.in ? formatTimeLocal(io.in.ts) : '—'}</strong></div>
              <div>Out: <strong>${io.out ? formatTimeLocal(io.out.ts) : '—'}</strong></div>
            </div>
          </div>
          <div class="att-actions">
            <button class="btn-compact" data-emp="${emp.id}" data-date="${dateStr}" title="View attendance summary">View</button>
          </div>
        `;
        attListContainer.appendChild(el);

        // wire view button
        const btn = el.querySelector('button');
        btn.addEventListener('click', function(){
          openSummaryModal(emp.id, dateStr);
        });
      });
    }

    // Escape for safety in text nodes
    function escapeHtml(s) {
      if (!s) return '';
      return String(s).replace(/[&<>"']/g, function(m){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m];
      });
    }

    // Calendar clicks
    calendarDays.forEach(c => {
      if (c.classList.contains('inactive')) return;
      c.addEventListener('click', function(){
        const d = this.dataset.date;
        if (!d) return;
        selectDate(d, true);
      });
    });

    document.getElementById('clearDate').addEventListener('click', function(){
      clearSelection();
      history.pushState({}, '', location.pathname + location.search);
    });

    // Initialize: default to today's date if available, else first with records, else none
    (function initSelection(){
      const initialHash = location.hash.replace('#','');
      if (initialHash && attendanceData[initialHash]) {
        selectDate(initialHash, false);
        return;
      }
      if (attendanceData[today]) {
        selectDate(today, false);
        return;
      }
      // pick first date with data
      const firstDate = Object.keys(attendanceData)[0];
      if (firstDate) selectDate(firstDate, false);
      else {
        // none - leave placeholder
        attListContainer.innerHTML = '<div class="small muted">No attendance records found for this month. Select another month using calendar nav.</div>';
      }
    })();

    // Modal logic
    const modalBackdrop = document.getElementById('modalBackdrop');
    const modalClose = document.getElementById('modalClose');
    const modalCloseBtn = document.getElementById('modalCloseBtn');

    function openSummaryModal(empId, dateStr) {
  const day = attendanceData[dateStr] && attendanceData[dateStr][empId];
  if (!day) {
    alert('No attendance data for this employee on this date.');
    return;
  }
  const emp = day.employee;
  const io = getInOut(day.events || []);

  // populate modal fields
  document.getElementById('modalPhoto').src = emp.photo || 'uploads/default.png';
  document.getElementById('modalName').textContent = emp.name || '—';
  document.getElementById('modalDept').textContent = emp.department || '—';
  
  // Format times in Asia/Manila timezone
  const manilaOptions = {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hour12: false
  };
  
  const formatManilaTime = (ts) => {
    if (!ts) return '—';
    return new Date(ts).toLocaleString('en-PH', manilaOptions);
  };
  
  document.getElementById('modalTimeIn').textContent = io.in ? formatManilaTime(io.in.ts) : '—';
  document.getElementById('modalTimeOut').textContent = io.out ? formatManilaTime(io.out.ts) : '—';
      // photos per event if available
      const inPhotoContainer = document.getElementById('modalInPhotoContainer');
      const outPhotoContainer = document.getElementById('modalOutPhotoContainer');
      inPhotoContainer.innerHTML = '';
      outPhotoContainer.innerHTML = '';
      if (io.in && io.in.photo) {
        inPhotoContainer.innerHTML = '<div style="margin-top:6px"><img src="' + io.in.photo + '" alt="In photo" style="width:160px;border-radius:6px;border:1px solid #eef2f7;object-fit:cover"></div>';
      }
      if (io.out && io.out.photo) {
        outPhotoContainer.innerHTML = '<div style="margin-top:6px"><img src="' + io.out.photo + '" alt="Out photo" style="width:160px;border-radius:6px;border:1px solid #eef2f7;object-fit:cover"></div>';
      }

      // compute status
      const statusEl = document.getElementById('modalStatus');
      statusEl.innerHTML = renderStatusBadge(emp.shift || '', io.in ? new Date(io.in.ts) : null, io.out ? new Date(io.out.ts) : null);

      // show modal
      modalBackdrop.style.display = 'flex';
      modalBackdrop.setAttribute('aria-hidden', 'false');
      // trap focus minimally
      modalBackdrop.querySelector('.modal').focus && modalBackdrop.querySelector('.modal').focus();
    }

    function closeModal() {
      modalBackdrop.style.display = 'none';
      modalBackdrop.setAttribute('aria-hidden', 'true');
    }

    modalClose.addEventListener('click', closeModal);
    modalCloseBtn.addEventListener('click', closeModal);
    modalBackdrop.addEventListener('click', function(e){
      if (e.target === modalBackdrop) closeModal();
    });
    document.addEventListener('keydown', function(e){
      if (e.key === 'Escape') closeModal();
    });

    // Status logic (MVP rules described)
    // shiftStr format expected: "HH:MM-HH:MM" or empty (fallback)
    function parseShift(shiftStr) {
      if (!shiftStr || !shiftStr.includes('-')) {
        return {start: '08:30', end: '17:30'}; // sensible default
      }
      const parts = shiftStr.split('-');
      return { start: parts[0].trim(), end: parts[1].trim() };
    }

    function toDateTimeFor(dateStr, timeStr) {
      // dateStr in 'YYYY-MM-DD' expected; timeStr 'HH:MM'
      if (!dateStr || !timeStr) return null;
      return new Date(dateStr + 'T' + timeStr + ':00');
    }

function renderStatusBadge(shiftStr, timeIn, timeOut) {
  const graceMinutes = 15;
  const minWorkSeconds = 4 * 3600;
  const shift = parseShift(shiftStr);
  const refDate = (timeIn || timeOut) ? ((timeIn || timeOut).toISOString().slice(0,10)) : null;
  
  let shiftStart = null, shiftEnd = null;
  if (refDate) {
    shiftStart = toDateTimeFor(refDate, shift.start);
    shiftEnd = toDateTimeFor(refDate, shift.end);
  }

  if (!timeIn && !timeOut) {
    return '<span class="badge absent">Absent</span>';
  }

  let graceThreshold = shiftStart ? new Date(shiftStart.getTime() + graceMinutes*60000) : null;

  if (!timeOut) {
    // 8:30-8:45 = ON TIME  |  8:46+ = LATE
    if (graceThreshold && timeIn > graceThreshold) {
      return '<span class="badge late">Late</span>';
    } else {
      return '<span class="badge on">On Time</span>';
    }
  }

  const workedSeconds = Math.max(0, Math.floor((timeOut.getTime() - timeIn.getTime()) / 1000));
  const isFullDay = workedSeconds >= minWorkSeconds;
  const isLate = graceThreshold && timeIn > graceThreshold;

  if (isLate) {
    if (!isFullDay) return '<span class="badge half">Half-day | Late</span>';
    return '<span class="badge late">Late</span>';
  }
  if (!isFullDay) return '<span class="badge early">Early Out</span>';
  return '<span class="badge on">Regular</span>';
}

    
    
  </script>
  <script>
// =============== SYNC TIME WITH SERVER ===============
let serverOffsetMs = 0;
let syncedServerTime = null;

// Fetch server time once and calculate offset
fetch('sync_time.php', { cache: 'no-store' })
  .then(r => {
    if (!r.ok) throw new Error('time_fetch_failed');
    return r.json();
  })
  .then(data => {
    if (data && data.server_ts_ms) {
      // Calculate offset: how much ahead/behind server is
      const clientNowMs = Date.now();
      serverOffsetMs = Number(data.server_ts_ms) - clientNowMs;
    }
  })
  .catch(err => {
    console.warn('[time-sync] Could not fetch server time, using client time:', err);
    serverOffsetMs = 0; // Fallback to client time
  });

// Helper: Get current server-synced time
function getSyncedServerTime() {
  return new Date(Date.now() + serverOffsetMs);
}

// Make globally available for all scripts
window.getSyncedServerTime = getSyncedServerTime;
</script>
</body>
</html>