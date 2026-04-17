<?php
require 'config.php';

// Lightweight summary counts (optional)
try {
    $totalsStmt = $pdo->query("SELECT COUNT(*) AS total, SUM(last_status='in') AS in_count, SUM(last_status='out') AS out_count FROM employees");
    $totals = $totalsStmt->fetch();
    $totalEmployees = (int)($totals['total'] ?? 0);
    $inCount = (int)($totals['in_count'] ?? 0);
    $outCount = (int)($totals['out_count'] ?? 0);
} catch (Exception $e) {
    $totalEmployees = $inCount = $outCount = 0;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Company Attendance</title>
  <meta name="viewport" content="width=device-width,initial-scale=1" />

  <!-- Inter font -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="attendance.css">
  <style>
    /* video uses same sizing as the avatar image and is always visible (no hidden-camera) */
    video.company-avatar {
      display: block;
      width: 160px;
      height: 160px;
      border-radius: 999px;
      object-fit: cover;
      box-shadow:0 12px 28px rgba(15,23,36,0.06);
      margin: 6px auto 0;
      background: #f3f4f6;
    }
  </style>
</head>
<body>
  <!-- Brand logos centered top (fixed) -->
  <div class="brand-logos" role="img" aria-label="Company logos">
    <div class="brand-logo-item"><img src="assets/logos/image.png" alt="McAsia" loading="lazy"></div>
  </div>

  <main class="kiosk" id="kiosk">

    <!-- Camera area (always visible). Use poster for a default image when stream isn't attached -->
    <header class="logo-wrap" aria-hidden="false">
      <video id="cameraPreview"
             class="company-avatar"
             autoplay
             playsinline
             muted
             poster="assets/logo.jpg"
             title="Camera preview">
        <!-- fallback: browser will show poster if no stream -->
      </video>
      <canvas id="captureCanvas" width="320" height="320" style="display:none"></canvas>
    </header>

    <!-- MVP Time / Date Card (CLIENT PC TIME) -->
    <section class="mvp-time-card" aria-live="polite" aria-atomic="true">
      <div class="mvp-time-row">
        <div class="mvp-clock" id="mvpClock" aria-hidden="false">
          <div id="mvpTime" class="mvp-time">--:--:--</div>
          <!-- Date and weekday under time -->
          <div id="mvpDate" class="mvp-full-date">Loading date…</div>
          <div id="mvpDay" class="mvp-day">—</div>
        </div>

        <div class="mvp-side" aria-hidden="true"></div>
      </div>
    </section>

    <!-- Card-style container with required fields -->
    <section class="card card-form" aria-labelledby="selectionTitle">
      <h2 id="selectionTitle" class="card-title">Attendance</h2>

      <form id="selectionForm" class="selection-form" autocomplete="off" novalidate>
        <!-- Attendance Action -->
        <div class="field">
          <label class="field-label">Attendance Action</label>
          <div class="segmented" role="radiogroup" aria-label="Attendance Action" id="actionSeg">
            <button type="button" class="seg-btn" data-value="in" aria-pressed="false">Time In</button>
            <button type="button" class="seg-btn" data-value="out" aria-pressed="false">Time Out</button>
          </div>
          <input type="hidden" name="action" id="actionInput" value="">
        </div>

        <!-- Employee ID (numeric only) -->
        <div class="field">
          <label for="empId" class="field-label">Employee ID</label>
          <input
            id="empId"
            name="emp_id"
            type="tel"
            inputmode="numeric"
            pattern="[0-9]*"
            placeholder="Enter Employee ID"
            maxlength="10"
            aria-label="Employee ID"
            required
          />
        </div>

        <!-- Action button -->
        <div class="field actions">
          <button id="goBtn" class="btn-primary" type="submit" disabled>GO</button>
        </div>
      </form>

      <!-- Helper / Status -->
      <div id="formMsg" class="form-msg" role="status" aria-live="polite"></div>
    </section>

    <!-- Profile card (shown after successful response) -->
    <section id="profileCard" class="card profile-card hidden" aria-live="polite">
      <div class="profile-left">
        <img id="photoImg" src="uploads/default.png" alt="Employee photo" class="photo">
      </div>

      <div class="profile-right">
        <h3 id="empName" class="emp-name">Name</h3>

        <dl class="profile-data">
          <div class="row">
            <dt>Date</dt>
            <dd id="serverDate">—</dd>
          </div>
          <div class="row">
            <dt>Day</dt>
            <dd id="dayOfWeek">—</dd>
          </div>
          <div class="row">
            <dt id="timeLabel">Time</dt>
            <dd id="timeVal">—</dd>
          </div>
          <div class="row">
            <dt>Status</dt>
            <dd id="statusBadge"><span class="badge neutral">—</span></dd>
          </div>
        </dl>

        <div id="profileMsg" class="profile-msg"></div>

        <div class="profile-actions">
          <button id="backBtn" class="btn-ghost">Back</button>
        </div>
      </div>
    </section>

    <footer class="kiosk-footer small muted">© Company — Attendance Kiosk</footer>
  </main>

  <!-- CLIENT PC TIME ONLY - Simple & Reliable -->
  <script>
  (function(){
    const timeEl = document.getElementById('mvpTime');
    const dateEl = document.getElementById('mvpDate');
    const dayEl  = document.getElementById('mvpDay');

    function pad(n){ return String(n).padStart(2,'0'); }
    
    function updateClock(){
      const now = new Date();
      
      // Format time: HH:MM:SS
      if (timeEl) {
        timeEl.textContent = `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
      }
      
      // Format date: MMM DD, YYYY
      if (dateEl) {
        const options = { year: 'numeric', month: 'short', day: 'numeric' };
        dateEl.textContent = now.toLocaleDateString('en-PH', options);
      }
      
      // Format day: Monday, Tuesday, etc.
      if (dayEl) {
        const options = { weekday: 'long' };
        dayEl.textContent = now.toLocaleDateString('en-PH', options);
      }
    }

    // Initial update
    updateClock();
    
    // Update every second
    setInterval(updateClock, 1000);
  })();
  </script>

  <script src="attendance.js"></script>
</body>
<script>
// Auto-start camera preview on page load
document.addEventListener('DOMContentLoaded', async () => {
  const cameraPreview = document.getElementById('cameraPreview');
  if (!cameraPreview) return;
  
  if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    console.warn('getUserMedia not supported');
    cameraPreview.style.display = 'none'; // Hide video if not supported
    return;
  }
  
  try {
    const stream = await navigator.mediaDevices.getUserMedia({
      video: true, // Simplified constraints for better compatibility
      audio: false
    });
    cameraPreview.srcObject = stream;
    await cameraPreview.play();
    console.log('✅ Camera started');
  } catch (err) {
    console.warn('❌ Camera permission denied or not available:', err);
    cameraPreview.style.display = 'none'; // Hide video on error
    // Optionally show a message
    const msg = document.getElementById('formMsg');
    if (msg) {
      msg.textContent = 'Camera access failed. Please check permissions and try refreshing.';
      msg.style.color = 'red';
    }
  }
});
</script>
</html>