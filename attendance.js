// attendance.js — attendance kiosk client with camera capture
// FIXED: Properly syncs CLIENT PC TIME with SERVER for consistency

document.addEventListener('DOMContentLoaded', () => {
  const segBtns = Array.from(document.querySelectorAll('.seg-btn'));
  const actionInput = document.getElementById('actionInput');
  const empIdInput = document.getElementById('empId');
  const goBtn = document.getElementById('goBtn');
  const form = document.getElementById('selectionForm');
  const formMsg = document.getElementById('formMsg');

  const cardForm = document.querySelector('.card-form');
  const profileCard = document.getElementById('profileCard');
  const photoImg = document.getElementById('photoImg');
  const empNameEl = document.getElementById('empName');
  const serverDateEl = document.getElementById('serverDate');
  const dayEl = document.getElementById('dayOfWeek');
  const timeLabelEl = document.getElementById('timeLabel');
  const timeValEl = document.getElementById('timeVal');
  const statusBadgeEl = document.getElementById('statusBadge');
  const profileMsg = document.getElementById('profileMsg');
  const backBtn = document.getElementById('backBtn');

  const cameraPreview = document.getElementById('cameraPreview');
  const captureCanvas = document.getElementById('captureCanvas');
  const fallbackAvatar = document.getElementById('fallbackAvatar');

  // Auto-reset timer
  const AUTO_RESET_MS = 5000;
  let autoResetTimer = null;
  let currentStream = null;

  // ============ TIME SYNC HELPER ============
  // Fetch server time ONCE on page load to calculate offset
  let serverOffsetMs = 0;
  let serverTimeReady = false;

  async function initializeServerTimeSync() {
    try {
      const response = await fetch('server_time.php', { 
        cache: 'no-store',
        credentials: 'same-origin' 
      });
      
      if (!response.ok) throw new Error('Failed to fetch server time');
      
      const data = await response.json();
      
      if (data.status === 'ok' && data.server_ts_ms) {
        // Calculate offset: server time - client time NOW
        serverOffsetMs = data.server_ts_ms - Date.now();
        serverTimeReady = true;
      }
    } catch (err) {
      console.warn('[attendance] Could not sync server time, using client time only', err);
      serverOffsetMs = 0;
      serverTimeReady = true; // proceed anyway
    }
  }

  // Get current time (synced with server offset)
  function getCurrentTime() {
    return new Date(Date.now() + serverOffsetMs);
  }

  // Format time helpers
  function pad(n) {
    return String(n).padStart(2, '0');
  }

  function getFormattedTime() {
    const now = getCurrentTime();
    return {
      iso: now.toISOString(),
      date: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`,
      time: `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`,
      datetime: `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())} ${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`,
      dayName: now.toLocaleDateString('en-PH', { weekday: 'long' }),
      dateShort: now.toLocaleDateString('en-PH', { year: 'numeric', month: 'short', day: 'numeric' })
    };
  }

  // ============ UTILITY FUNCTIONS ============
  function log(...args) {
    // Debug logging removed for production
  }

  function validateState() {
    const action = (actionInput && actionInput.value || '').trim();
    const id = (empIdInput && empIdInput.value || '').trim();
    const validId = /^\d{1,10}$/.test(id);
    return (action === 'in' || action === 'out') && validId;
  }

  function updateGoState() {
    if (!goBtn) return;
    goBtn.disabled = !validateState();
  }

  function showProfileCard() {
    if (cardForm) cardForm.classList.add('hidden');
    if (profileCard) {
      profileCard.classList.remove('hidden');
      profileCard.classList.add('fade-in');
    }
  }

  function showFormCard() {
    if (profileCard) profileCard.classList.add('hidden');
    if (cardForm) cardForm.classList.remove('hidden');
  }

  function cancelAutoReset() {
    if (autoResetTimer) {
      clearTimeout(autoResetTimer);
      autoResetTimer = null;
    }
  }

  function startAutoReset() {
    cancelAutoReset();
    autoResetTimer = setTimeout(() => {
      resetToInitial(true);
      autoResetTimer = null;
    }, AUTO_RESET_MS);
  }

  function resetToInitial(animate = true) {
    cancelAutoReset();
    if (profileCard) {
      profileCard.classList.remove('fade-in');
      profileCard.classList.add('fade-out');
    }
    setTimeout(() => {
      showFormCard();
      if (empIdInput) {
        empIdInput.value = '';
        try { empIdInput.focus(); } catch (e) {}
      }
      if (actionInput) actionInput.value = '';
      segBtns.forEach(b => {
        b.classList.remove('active');
        b.setAttribute('aria-pressed', 'false');
      });
      if (formMsg) formMsg.textContent = '';
      if (profileMsg) profileMsg.textContent = '';
      if (statusBadgeEl) statusBadgeEl.innerHTML = '<span class="badge neutral">—</span>';
      if (timeValEl) timeValEl.textContent = '—';
      updateGoState();
      if (profileCard) profileCard.classList.remove('fade-out');
      stopCameraPreview();
    }, animate ? 220 : 10);
  }

  // ============ CAMERA HELPERS ============
  async function startCameraPreview() {
    if (!cameraPreview) return null;
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      log('getUserMedia not available');
      return null;
    }
    if (currentStream) return currentStream;
    
    try {
      const constraints = {
        video: true, // Simplified for better compatibility
        audio: false
      };
      const stream = await navigator.mediaDevices.getUserMedia(constraints);
      currentStream = stream;
      
      try {
        cameraPreview.srcObject = stream;
        cameraPreview.play().catch(() => {});
      } catch (e) {
        /* ignore */
      }
      
      if (fallbackAvatar) fallbackAvatar.style.display = 'none';
      return stream;
    } catch (err) {
      log('Camera start failed', err);
      // Show error message
      if (formMsg) {
        let errorMsg = 'Camera access failed.';
        if (err.name === 'NotAllowedError') {
          errorMsg = 'Camera permission denied. Please allow camera access in your browser.';
        } else if (err.name === 'NotFoundError') {
          errorMsg = 'No camera found. Please connect a camera.';
        } else if (err.name === 'NotReadableError') {
          errorMsg = 'Camera is already in use by another application.';
        } else if (err.name === 'OverconstrainedError') {
          errorMsg = 'Camera constraints not supported.';
        } else if (err.name === 'SecurityError') {
          errorMsg = 'Camera access blocked. Try accessing this page with HTTPS (https://localhost/timeclock/) or allow camera access for this site.';
        }
        formMsg.textContent = errorMsg;
        formMsg.style.color = 'red';
      }
      return null;
    }
  }

  function stopCameraPreview() {
    if (cameraPreview) {
      try { cameraPreview.pause(); } catch (e) {}
      try { cameraPreview.srcObject = null; } catch (e) {}
    }
    if (currentStream) {
      currentStream.getTracks().forEach(t => t.stop());
      currentStream = null;
    }
    if (fallbackAvatar) fallbackAvatar.style.display = '';
  }

  window.startCameraPreview = startCameraPreview;
  window.stopCameraPreview = stopCameraPreview;

  function captureFrameToBlob() {
    return new Promise((resolve) => {
      if (!captureCanvas || !cameraPreview) return resolve(null);
      
      const cw = cameraPreview.videoWidth || 640;
      const ch = cameraPreview.videoHeight || 640;
      const size = Math.min(cw, ch);
      
      captureCanvas.width = size;
      captureCanvas.height = size;
      
      const ctx = captureCanvas.getContext('2d');
      const sx = Math.max(0, (cw - size) / 2);
      const sy = Math.max(0, (ch - size) / 2);
      
      try {
        ctx.drawImage(cameraPreview, sx, sy, size, size, 0, 0, size, size);
        captureCanvas.toBlob((blob) => resolve(blob), 'image/jpeg', 0.85);
      } catch (e) {
        console.error('capture failed', e);
        resolve(null);
      }
    });
  }

  // ============ POPULATE PROFILE FROM SERVER ============
  function populateProfile(data) {
    const emp = data.employee || {};
    
    if (photoImg) {
      if (data.employee && data.employee.photo) {
        photoImg.src = data.employee.photo;
      } else if (data.photo_preview_url) {
        photoImg.src = data.photo_preview_url;
      } else {
        photoImg.src = 'uploads/default.png';
      }
    }
    
    if (empNameEl) {
      empNameEl.textContent = emp.name || data.name || 'Employee';
    }

    // Display SYNCED time
    const timeInfo = getFormattedTime();
    if (serverDateEl) serverDateEl.textContent = timeInfo.dateShort;
    if (dayEl) dayEl.textContent = timeInfo.dayName;

    const action = (data.action || '').toLowerCase();
    if (timeLabelEl && timeValEl) {
      if (action === 'out') {
        timeLabelEl.textContent = 'Time Out';
      } else {
        timeLabelEl.textContent = 'Time In';
      }
      timeValEl.textContent = timeInfo.time;
    }

    // Status badge
    if (statusBadgeEl) statusBadgeEl.innerHTML = '<span class="badge neutral">—</span>';

    const half = !!data.half_day;
    const early = !!data.early_out;
    const txt = data.attendance_status_text || data.attendance_status || data.status_text || '';

    if (half || early || (txt && txt.length)) {
      const parts = [];
      if (half) parts.push('Half day');
      if (early) parts.push('Early out');
      const statusText = txt || parts.join(' | ');
      
      let cls = 'badge neutral';
      const s = (statusText || '').toLowerCase();
      if (s.includes('late')) cls = 'badge late';
      if (s.includes('on time')) cls = 'badge on';
      if (s.includes('half')) cls = 'badge half';
      if (early && !half) cls = 'badge early';
      
      if (statusBadgeEl) {
        statusBadgeEl.innerHTML = `<span class="${cls}">${statusText}</span>`;
      }
    }

    // Profile message
    if (profileMsg) {
      const serverMessage = data && (data.message || data.attendance_status_text || '');
      const ip = data && (data.ip || data.client_ip || data.ip_address || '');
      let displayMsg = serverMessage || '';
      
      if (!displayMsg && data && data.status === 'ok') {
        if ((data.action || '').toLowerCase() === 'in') {
          displayMsg = 'Time-in recorded successfully.';
        } else if ((data.action || '').toLowerCase() === 'out') {
          displayMsg = 'Time-out recorded successfully.';
        }
      }
      
      if (ip) {
        displayMsg = `${displayMsg} (IP: ${ip})`.trim();
      }
      
      profileMsg.textContent = displayMsg;
    }
  }

  // ============ BIND SEGMENTED BUTTONS ============
  if (segBtns.length) {
    segBtns.forEach(btn => {
      if (!btn.hasAttribute('type')) btn.setAttribute('type', 'button');
      
      btn.addEventListener('click', async () => {
        segBtns.forEach(b => {
          b.classList.remove('active');
          b.setAttribute('aria-pressed', 'false');
        });
        btn.classList.add('active');
        btn.setAttribute('aria-pressed', 'true');
        
        if (actionInput) actionInput.value = btn.getAttribute('data-value') || '';
        updateGoState();
        if (empIdInput) empIdInput.focus();

        if (cameraPreview) {
          try { await startCameraPreview(); } catch (e) { /* ignore */ }
        }
      });
      
      btn.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          btn.click();
        }
      });
    });
  }

  if (empIdInput) {
    empIdInput.addEventListener('input', () => {
      const cleaned = empIdInput.value.replace(/[^\d]/g, '');
      if (cleaned !== empIdInput.value) empIdInput.value = cleaned;
      updateGoState();
    });
    
    try {
      empIdInput.focus();
      empIdInput.select();
    } catch (e) {}
    
    empIdInput.addEventListener('focus', () => {
      if (cameraPreview) startCameraPreview().catch(() => {});
    });
  }

  // ============ FORM SUBMIT ============
  if (form) {
    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      
      if (!validateState()) {
        if (formMsg) formMsg.textContent = 'Please select an action and enter Employee ID.';
        return;
      }

      showProfileCard();
      if (empNameEl) empNameEl.textContent = 'Loading…';
      if (photoImg) photoImg.src = 'uploads/default.png';
      if (profileMsg) profileMsg.textContent = 'Processing…';
      if (statusBadgeEl) statusBadgeEl.innerHTML = '<span class="badge neutral">—</span>';
      if (serverDateEl) serverDateEl.textContent = '';
      if (dayEl) dayEl.textContent = '';
      if (timeValEl) timeValEl.textContent = '—';

      const empId = empIdInput.value.trim();
      const action = actionInput.value.trim();

      goBtn.disabled = true;
      let photoBlob = null;
      let previewUrl = null;

      // Capture camera frame if available
      try {
        if (cameraPreview && !currentStream) {
          try { await startCameraPreview(); } catch (e) { log('startCameraPreview failed', e); }
        }

        if (currentStream) {
          await new Promise((res) => {
            const onPlay = () => {
              if (cameraPreview) cameraPreview.removeEventListener('playing', onPlay);
              res();
            };
            if (cameraPreview && cameraPreview.readyState >= 2) return res();
            if (cameraPreview) cameraPreview.addEventListener('playing', onPlay);
            setTimeout(res, 400);
          });
          
          photoBlob = await captureFrameToBlob();
          if (photoBlob) previewUrl = URL.createObjectURL(photoBlob);
        }
      } catch (e) {
        console.warn('camera capture failed', e);
      }

      try {
        // Get synced time to send to server
        const timeInfo = getFormattedTime();

        const fd = new FormData();
        fd.append('emp_id', empId);
        fd.append('action', action);
        // Send client time to server for logging
        fd.append('client_time', timeInfo.datetime);
        fd.append('client_date', timeInfo.date);

        if (photoBlob) {
          const fname = `emp_${empId}_${Date.now()}.jpg`;
          fd.append('photo', photoBlob, fname);
        }

        const res = await fetch('process_timein.php', {
          method: 'POST',
          body: fd,
          credentials: 'same-origin'
        });

        const raw = await res.text().catch(() => null);
        let data = null;
        
        try {
          if (raw) data = JSON.parse(raw);
        } catch (e) {
          data = null;
        }

        if (data && (data.message || data.status)) {
          showProfileCard();

          if (data.status === 'not_found' || res.status === 404) {
            if (empNameEl) empNameEl.textContent = 'Unknown';
            if (profileMsg) profileMsg.textContent = data.message || 'Employee not registered.';
            if (serverDateEl) serverDateEl.textContent = '';
            if (dayEl) dayEl.textContent = '';
            if (statusBadgeEl) statusBadgeEl.innerHTML = '<span class="badge neutral">—</span>';
            startAutoReset();
            stopCameraPreview();
            goBtn.disabled = false;
            return;
          }

          if (data.employee) {
            if (!data.employee.photo && previewUrl) {
              data.photo_preview_url = previewUrl;
            }
            populateProfile(data);
          } else if (previewUrl) {
            if (photoImg) photoImg.src = previewUrl;
            if (profileMsg) profileMsg.textContent = data.message || '';
          }

          if (!res.ok) {
            startAutoReset();
            stopCameraPreview();
            goBtn.disabled = false;
            return;
          }
        }

        if (!res.ok) {
          if (profileMsg) profileMsg.textContent = 'Server error. Try again.';
          startAutoReset();
          stopCameraPreview();
          goBtn.disabled = false;
          return;
        }

        if (!data) {
          if (profileMsg) profileMsg.textContent = 'Invalid server response.';
          startAutoReset();
          stopCameraPreview();
          goBtn.disabled = false;
          return;
        }

        if (previewUrl && !data.employee) {
          data.photo_preview_url = previewUrl;
        }

        populateProfile(data);

        // Ensure profile message is always set
        if (profileMsg) {
          const serverMessage = data.message || '';
          if (serverMessage) {
            profileMsg.textContent = serverMessage;
          } else if (data.status === 'ok') {
            if ((data.action || action || '').toLowerCase() === 'in') {
              profileMsg.textContent = 'Time-in recorded successfully.';
            } else if ((data.action || action || '').toLowerCase() === 'out') {
              profileMsg.textContent = 'Time-out recorded successfully.';
            }
          }
        }

        startAutoReset();

      } catch (err) {
        console.error('Submit error', err);
        if (profileMsg) profileMsg.textContent = 'Network error.';
        startAutoReset();
      } finally {
        goBtn.disabled = false;
        stopCameraPreview();
        if (previewUrl) {
          setTimeout(() => {
            try { URL.revokeObjectURL(previewUrl); } catch (e) {}
          }, 15000);
        }
      }
    });
  }

  // Back button
  if (backBtn) {
    backBtn.addEventListener('click', (e) => {
      e.preventDefault();
      cancelAutoReset();
      resetToInitial(false);
    });
  }

  // ============ RFID HANDLER ============
  const rfidInput = document.getElementById('rfidInput');
  let rfidBuffer = '';
  let rfidTimeout = null;
  
  // Keep RFID input focused to capture scanner data
  function focusRFID() {
    if (rfidInput && document.activeElement !== empIdInput) {
      try { rfidInput.focus(); } catch (e) {}
    }
  }

  if (rfidInput) {
    // Listen for RFID card data
    rfidInput.addEventListener('input', (e) => {
      const data = rfidInput.value.trim();
      
      if (data.length > 0) {
        rfidBuffer = data;
        
        // Clear timeout for next scan
        if (rfidTimeout) clearTimeout(rfidTimeout);
        
        // Process RFID data after a short delay (scanner sends all data quickly, then stops)
        rfidTimeout = setTimeout(() => {
          processRFIDData(rfidBuffer);
          rfidBuffer = '';
          rfidInput.value = '';
          focusRFID();
        }, 100);
      }
    });

    // Process RFID keydown to detect Enter (end of scan)
    rfidInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        if (rfidTimeout) clearTimeout(rfidTimeout);
        processRFIDData(rfidBuffer || rfidInput.value);
        rfidBuffer = '';
        rfidInput.value = '';
        focusRFID();
      }
    });

    // Keep RFID field focused
    document.addEventListener('click', focusRFID);
    document.addEventListener('touchend', focusRFID);
  }

  function processRFIDData(rfidCode) {
    if (!rfidCode || rfidCode.length === 0) return;

    // Show processing message
    if (formMsg) formMsg.textContent = 'Processing RFID...';

    // Look up employee ID from RFID code via server
    fetch('rfid_lookup.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: 'rfid_code=' + encodeURIComponent(rfidCode),
      credentials: 'same-origin'
    })
    .then(res => res.json())
    .then(data => {
      if (data.status === 'ok' && data.employee_id) {
        const employeeId = String(data.employee_id);
        
        log(`RFID scanned: ${rfidCode} -> Employee ID: ${employeeId} (${data.source})`);

        // Auto-populate employee ID
        if (empIdInput) {
          empIdInput.value = employeeId;
          empIdInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Clear message
        if (formMsg) formMsg.textContent = '';

        // Auto-select "Time In" if no action selected yet
        if (!actionInput || actionInput.value === '') {
          const inBtn = segBtns.find(btn => btn.getAttribute('data-value') === 'in');
          if (inBtn) {
            inBtn.click();
          }
        }

        // Auto-submit form after a brief delay to show the action was selected
        setTimeout(() => {
          if (validateState()) {
            if (form) form.dispatchEvent(new Event('submit'));
          }
        }, 200);
      } else {
        if (formMsg) formMsg.textContent = data.message || 'RFID code not recognized';
        log(`RFID lookup failed: ${rfidCode}`);
      }
    })
    .catch(err => {
      console.error('RFID lookup error', err);
      if (formMsg) formMsg.textContent = 'RFID lookup error. Try manual entry.';
    })
    .finally(() => {
      focusRFID();
    });
  }

  // Focus RFID input on page load
  focusRFID();

  // ============ INITIALIZATION ============
  // Initialize time sync first, then ready the form
  (async () => {
    await initializeServerTimeSync();
    updateGoState();
    focusRFID(); // Ensure RFID input stays focused
  })();
});