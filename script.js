// script.js - tolerant and defensive: won't write to missing elements
document.addEventListener('DOMContentLoaded', () => {
  const form = document.getElementById('punchForm');
  const input = document.getElementById('idInput');
  const liveTime = document.getElementById('liveTime');
  const feedbackMsg = document.getElementById('feedbackMsg');
  const feedbackMeta = document.getElementById('feedbackMeta');
  const recentList = document.getElementById('recentList');

  // optional elements (may be absent)
  const statusIcon = document.getElementById('statusIcon'); // may be null
  const pulse = document.getElementById('pulse'); // may be null

  // modal elements (may be null if modal removed)
  const modal = document.getElementById('punchModal');
  const modalOverlay = document.getElementById('modalOverlay');
  const modalType = document.getElementById('modalType');
  const modalShift = document.getElementById('modalShift');
  const modalPhoto = document.getElementById('modalPhoto');
  const modalOpen = document.getElementById('modalOpen');
  const modalClose = document.getElementById('modalClose');
  const modalResp = document.getElementById('modalResp');
  const modalOk = document.getElementById('modalOk');
  const modalIcon = document.getElementById('modalIcon');

  function pad(n){ return String(n).padStart(2,'0'); }
  function formatTime(d){ return `${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`; }
  function updateLiveTime(){ if (liveTime) liveTime.textContent = formatTime(new Date()); }
  updateLiveTime();
  setInterval(updateLiveTime, 1000);

  if (!form) return console.warn('punchForm not found');

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const id = input ? input.value.trim() : '';
    if (!id) {
      if (input) shake(input);
      if (feedbackMsg) feedbackMsg.textContent = 'Enter an ID code.';
      if (feedbackMeta) feedbackMeta.textContent = '';
      return;
    }

    if (feedbackMsg) feedbackMsg.textContent = 'Checking...';
    if (feedbackMeta) feedbackMeta.textContent = '';

    try {
      const formData = new FormData();
      formData.append('id_code', id);

      // append chosen event if the UI provides it
      const evtEl = document.getElementById('eventType');
      if (evtEl) {
        const v = (evtEl.value || '').trim();
        // Only append explicit 'in' or 'out' (ignore 'auto')
        if (v === 'in' || v === 'out') formData.append('event', v);
      }

      const res = await fetch('process.php', { method: 'POST', body: formData });
      const raw = await res.text();

      if (!raw) {
        if (feedbackMsg) feedbackMsg.textContent = 'Empty response from server (check logs).';
        return;
      }

      let data;
      try { data = JSON.parse(raw); } catch (err) {
        if (feedbackMsg) feedbackMsg.textContent = 'Server returned invalid response.';
        console.error('JSON parse error:', err);
        return;
      }

      if (data.status === 'ok') {
        if (feedbackMsg) feedbackMsg.textContent = data.message || 'OK';
        if (feedbackMeta) feedbackMeta.textContent = `${data.name || 'Unknown'} — ${data.timestamp || ''} [${data.division || 'General'}]`;

        if (statusIcon) statusIcon.textContent = (data.event === 'in') ? '✅' : '⏺️';

        if (modal) {
          if (modalType) modalType.textContent = (data.event === 'in') ? 'Opening' : 'Closing';
          if (modalShift) modalShift.textContent = data.division || 'General Shift';
          if (modalPhoto) modalPhoto.src = data.photo_url || 'uploads/default.png';
          if (modalOpen) modalOpen.textContent = data.opening_time || '—';
          if (modalClose) modalClose.textContent = data.closing_time || '—';
          if (modalResp) modalResp.textContent = (data.response_time !== undefined) ? Number(data.response_time).toFixed(3) : '0.000';
          if (modalIcon) modalIcon.textContent = (data.event === 'in') ? '✅' : '⏺️';
          showModal();
        }

        if (recentList) pushRecent(`${data.name} — ${data.event.toUpperCase()} @ ${data.timestamp} — ${data.division || 'General'}`);
        if (pulse) pulseGlow(data.event === 'in' ? '#6EE7B7' : '#60A5FA');
      } else if (data.status === 'not_found') {
        if (feedbackMsg) feedbackMsg.textContent = data.message || 'ID not recognized.';
        if (feedbackMeta) feedbackMeta.textContent = `Unknown ID (${id})`;
        if (statusIcon) statusIcon.textContent = '❓';
        if (input) shake(input);
        if (recentList) pushRecent(`Unknown ID (${id})`);
      } else {
        if (feedbackMsg) feedbackMsg.textContent = data.message || 'Unexpected response.';
        if (feedbackMeta) feedbackMeta.textContent = '';
        if (statusIcon) statusIcon.textContent = '⚠️';
      }
    } catch (err) {
      if (feedbackMsg) feedbackMsg.textContent = 'Network/server error (see console).';
      if (feedbackMeta) feedbackMeta.textContent = '';
      if (statusIcon) statusIcon.textContent = '⚠️';
      console.error('Fetch error:', err);
    } finally {
      if (input) { input.value = ''; input.focus(); }
    }
  });

  function showModal(){ if (!modal) return; modal.classList.remove('hidden'); modal.setAttribute('aria-hidden','false'); }
  function hideModal(){ if (!modal) return; modal.classList.add('hidden'); modal.setAttribute('aria-hidden','true'); }

  if (modalOk) modalOk.addEventListener('click', hideModal);
  if (modalOverlay) modalOverlay.addEventListener('click', hideModal);
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hideModal(); });

  function pushRecent(text){ if (!recentList) return; const li = document.createElement('li'); li.textContent = text; recentList.insertBefore(li, recentList.firstChild); while (recentList.children.length > 8) recentList.removeChild(recentList.lastChild); }
  function shake(el){ if (!el) return; el.style.transition = 'transform .12s'; el.style.transform = 'translateX(-8px)'; setTimeout(()=>el.style.transform='translateX(8px)',120); setTimeout(()=>el.style.transform='translateX(0)',240); }
  function pulseGlow(color){ if (!pulse) return; pulse.style.boxShadow = `0 8px 30px ${color}66, 0 0 40px ${color}55`; setTimeout(()=>pulse.style.boxShadow = '', 900); }
});