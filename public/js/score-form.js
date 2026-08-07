/**
 * Scoring form: UUID idempotency, steppers, live totals, optional paper sheet, submit.
 */
(function () {
  'use strict';

  const TONAL = ['sub_bass', 'mid_bass', 'midrange', 'high_freq', 'spectral_balance'];
  const STAGE = ['listening_position', 'width', 'height', 'depth', 'ambience'];
  const NOISE = ['noise', 'listening_pleasure'];
  const PAPER_MAX_BYTES = 12 * 1024 * 1024;
  const PAPER_OK_TYPES = new Set([
    'image/jpeg',
    'image/png',
    'image/webp',
    'image/heic',
    'image/heif',
  ]);

  const form = document.getElementById('score-form');
  if (!form) return;

  const uuidInput = document.getElementById('submission_uuid');
  const submitBtn = document.getElementById('submit-btn');
  const statusEl = document.getElementById('form-status');
  const paperInput = document.getElementById('paper_sheet');
  const paperPreview = document.getElementById('paper-sheet-preview');
  const paperPreviewImg = document.getElementById('paper-sheet-preview-img');
  const paperClear = document.getElementById('paper-sheet-clear');
  const eventSelect = document.getElementById('event_id');
  const competitorIdEl = form.elements.namedItem('competitor_id');
  const draftKey = competitorIdEl && competitorIdEl.value
    ? `fsq-score-draft-v1-${competitorIdEl.value}`
    : null;
  let paperObjectUrl = null;
  let draftTimer = null;

  function uuidv4() {
    if (crypto.randomUUID) return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  function setUuid() {
    uuidInput.value = uuidv4();
  }

  function parseValue(input) {
    const raw = String(input.value).trim();
    if (raw === '') return null;
    const n = Number.parseInt(raw, 10);
    return Number.isFinite(n) ? n : null;
  }

  function sumFields(names) {
    return names.reduce((sum, name) => {
      const el = form.elements.namedItem(name);
      if (!el) return sum;
      const n = parseValue(el);
      return sum + (n === null ? 0 : n);
    }, 0);
  }

  function setFieldError(name, message) {
    const wrap = form.querySelector(`[data-field="${name}"]`) || form.querySelector(`#${CSS.escape(name)}`)?.closest('.field');
    const input = form.elements.namedItem(name);
    const err = wrap?.querySelector('.field-error');
    if (input && input.classList) {
      input.classList.toggle('is-invalid', Boolean(message));
    }
    wrap?.classList.toggle('is-invalid', Boolean(message));
    if (err) {
      if (message) {
        err.hidden = false;
        err.textContent = message;
      } else {
        err.hidden = true;
        err.textContent = '';
      }
    }
  }

  function clearErrors() {
    form.querySelectorAll('.is-invalid').forEach((el) => el.classList.remove('is-invalid'));
    form.querySelectorAll('.field-error').forEach((el) => {
      el.hidden = true;
      el.textContent = '';
    });
  }

  function clearPaperPreview() {
    if (paperObjectUrl) {
      URL.revokeObjectURL(paperObjectUrl);
      paperObjectUrl = null;
    }
    if (paperPreviewImg) paperPreviewImg.removeAttribute('src');
    if (paperPreview) paperPreview.hidden = true;
  }

  function resetPaperSheet() {
    if (paperInput) paperInput.value = '';
    clearPaperPreview();
    setFieldError('paper_sheet', '');
  }

  function validatePaperSheet() {
    if (!paperInput || !paperInput.files || paperInput.files.length === 0) {
      setFieldError('paper_sheet', '');
      return true;
    }
    const file = paperInput.files[0];
    const typeOk = !file.type || PAPER_OK_TYPES.has(file.type);
    const name = (file.name || '').toLowerCase();
    const extOk = /\.(jpe?g|png|webp|heic|heif)$/.test(name);
    if (!typeOk && !extOk) {
      setFieldError('paper_sheet', 'Use a JPEG, PNG, WebP, or HEIC photo.');
      return false;
    }
    if (file.size > PAPER_MAX_BYTES) {
      setFieldError('paper_sheet', 'Image must be under 12 MB.');
      return false;
    }
    setFieldError('paper_sheet', '');
    return true;
  }

  function showPaperPreview(file) {
    clearPaperPreview();
    if (!file || !paperPreview || !paperPreviewImg) return;
    // HEIC often cannot preview in-browser; still keep the file selected.
    if (file.type === 'image/heic' || file.type === 'image/heif' || /\.hei[cf]$/i.test(file.name || '')) {
      paperPreview.hidden = false;
      paperPreviewImg.alt = file.name || 'Paper sheet selected';
      paperPreviewImg.removeAttribute('src');
      return;
    }
    paperObjectUrl = URL.createObjectURL(file);
    paperPreviewImg.src = paperObjectUrl;
    paperPreviewImg.alt = 'Paper sheet preview';
    paperPreview.hidden = false;
  }

  function validateField(input) {
    if (!input || !input.dataset.min) return true;
    const min = Number(input.dataset.min);
    const max = Number(input.dataset.max);
    const n = parseValue(input);
    if (n === null) {
      setFieldError(input.name, `Enter a value (${min}–${max}).`);
      return false;
    }
    if (n < min || n > max) {
      setFieldError(input.name, `Must be ${min}–${max}.`);
      return false;
    }
    setFieldError(input.name, '');
    return true;
  }

  function updateTotals() {
    const tonal = sumFields(TONAL);
    const stage = sumFields(STAGE);
    const imaging = sumFields(['imaging_score']);
    const noise = sumFields(NOISE);
    const grand = tonal + stage + imaging + noise;

    const set = (id, val) => {
      const el = document.getElementById(id);
      if (el) el.textContent = String(val);
    };
    set('tonal-total', tonal);
    set('stage-total', stage);
    set('imaging-total', imaging);
    set('noise-total', noise);
    set('grand-total', grand);
  }

  function showStatus(message, kind) {
    statusEl.hidden = !message;
    statusEl.textContent = message || '';
    statusEl.className = 'form-status' + (kind ? ` is-${kind}` : '');
  }

  function syncEventFromSelect() {
    if (!eventSelect) return;
    const opt = eventSelect.options[eventSelect.selectedIndex];
    const dateEl = form.elements.namedItem('event_date');
    const nameEl = form.elements.namedItem('event_name');
    if (dateEl) dateEl.value = opt ? (opt.getAttribute('data-date') || '') : '';
    if (nameEl) nameEl.value = opt ? (opt.getAttribute('data-name') || '') : '';
  }

  function collectDraft() {
    const data = { fields: {}, uuid: uuidInput ? uuidInput.value : '' };
    Array.from(form.elements).forEach((el) => {
      if (!el.name || el.name === 'csrf_token' || el.name === 'paper_sheet') return;
      if (el.type === 'file') return;
      if (el.type === 'checkbox' || el.type === 'radio') {
        if (el.checked) data.fields[el.name] = el.value;
        return;
      }
      data.fields[el.name] = el.value;
    });
    return data;
  }

  function saveDraft() {
    if (!draftKey) return;
    try {
      sessionStorage.setItem(draftKey, JSON.stringify(collectDraft()));
    } catch (e) {
      // quota / private mode — ignore
    }
  }

  function scheduleDraftSave() {
    if (!draftKey) return;
    clearTimeout(draftTimer);
    draftTimer = setTimeout(saveDraft, 300);
  }

  function clearDraft() {
    if (!draftKey) return;
    try {
      sessionStorage.removeItem(draftKey);
    } catch (e) {
      // ignore
    }
  }

  function loadDraft() {
    if (!draftKey) return false;
    let raw;
    try {
      raw = sessionStorage.getItem(draftKey);
    } catch (e) {
      return false;
    }
    if (!raw) return false;
    let data;
    try {
      data = JSON.parse(raw);
    } catch (e) {
      return false;
    }
    if (!data || !data.fields) return false;
    Object.keys(data.fields).forEach((name) => {
      const el = form.elements.namedItem(name);
      if (!el || el.type === 'file' || name === 'csrf_token') return;
      el.value = data.fields[name];
    });
    if (uuidInput && data.uuid) {
      uuidInput.value = data.uuid;
    }
    syncEventFromSelect();
    updateTotals();
    showStatus('Restored unsaved draft from this device.', 'info');
    return true;
  }

  // Steppers
  form.querySelectorAll('.stepper-field').forEach((wrap) => {
    const input = wrap.querySelector('.stepper-value');
    wrap.querySelectorAll('.stepper-btn').forEach((btn) => {
      btn.addEventListener('click', () => {
        const dir = Number(btn.dataset.dir);
        const min = Number(input.dataset.min);
        const max = Number(input.dataset.max);
        let n = parseValue(input);
        if (n === null) n = dir > 0 ? min : min;
        else n = Math.min(max, Math.max(min, n + dir));
        input.value = String(n);
        validateField(input);
        updateTotals();
        scheduleDraftSave();
      });
    });
    input.addEventListener('input', () => {
      validateField(input);
      updateTotals();
      scheduleDraftSave();
    });
    input.addEventListener('blur', () => validateField(input));
  });

  if (eventSelect) {
    eventSelect.addEventListener('change', () => {
      syncEventFromSelect();
      setFieldError('event_id', '');
      scheduleDraftSave();
    });
  }

  form.addEventListener('input', (e) => {
    if (e.target && e.target.name && e.target.name !== 'paper_sheet') {
      scheduleDraftSave();
    }
  });
  form.addEventListener('change', (e) => {
    if (e.target && e.target.name && e.target.name !== 'paper_sheet') {
      scheduleDraftSave();
    }
  });

  if (paperInput) {
    paperInput.addEventListener('change', () => {
      if (!validatePaperSheet()) {
        clearPaperPreview();
        return;
      }
      const file = paperInput.files && paperInput.files[0];
      if (file) showPaperPreview(file);
      else clearPaperPreview();
    });
  }
  if (paperClear) {
    paperClear.addEventListener('click', () => resetPaperSheet());
  }

  /**
   * Sound-stage diagram pins — visual only; coords are viewBox-relative.
   * Pins are numbered 1–4 by placement order; not mapped to score categories.
   */
  const MARKER_COLORS = ['#c0392b', '#2471a3', '#1e8449', '#b9770e'];

  function parseMarkersJson(raw) {
    if (!raw) return [];
    try {
      const data = JSON.parse(raw);
      if (!Array.isArray(data)) return [];
      return data
        .filter((m) => {
          if (!m || typeof m !== 'object') return false;
          if (m.x == null || m.y == null || m.x === '' || m.y === '') return false;
          return Number.isFinite(Number(m.x)) && Number.isFinite(Number(m.y));
        })
        .slice(0, 4)
        .map((m) => ({ x: Number(m.x), y: Number(m.y) }));
    } catch (e) {
      return [];
    }
  }

  function clientToSvgPoint(svg, clientX, clientY) {
    const ctm = svg.getScreenCTM();
    if (!ctm) return null;
    const pt = svg.createSVGPoint();
    pt.x = clientX;
    pt.y = clientY;
    return pt.matrixTransform(ctm.inverse());
  }

  function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
  }

  function initStageDiagram(wrap) {
    const svg = wrap.querySelector('svg.stage-diagram-svg');
    const layer = wrap.querySelector('.stage-marker-layer');
    const input = form.elements.namedItem(wrap.dataset.field);
    const clearBtn = wrap.querySelector('.stage-diagram-clear');
    if (!svg || !layer || !input) return;

    const max = Number(wrap.dataset.max) || 4;
    const vbX = Number(wrap.dataset.vbX) || 0;
    const vbY = Number(wrap.dataset.vbY) || 0;
    const vbW = Number(wrap.dataset.vbW) || 100;
    const vbH = Number(wrap.dataset.vbH) || 100;
    const pinR = Math.max(2.2, Math.min(vbW, vbH) * 0.035);

    let markers = parseMarkersJson(input.value);
    let dragIndex = -1;

    function syncInput() {
      input.value = JSON.stringify(markers.map((m) => ({
        x: Math.round(m.x * 1000) / 1000,
        y: Math.round(m.y * 1000) / 1000,
      })));
      if (clearBtn) clearBtn.hidden = markers.length === 0;
      scheduleDraftSave();
    }

    function renderMarkers() {
      layer.replaceChildren();
      markers.forEach((m, i) => {
        const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
        g.setAttribute('class', 'stage-marker');
        g.setAttribute('data-index', String(i));
        g.setAttribute('transform', `translate(${m.x},${m.y})`);
        g.style.cursor = 'grab';
        g.style.touchAction = 'none';

        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('class', 'stage-marker-pin');
        circle.setAttribute('r', String(pinR));
        circle.setAttribute('fill', MARKER_COLORS[i] || '#333');
        circle.setAttribute('stroke', '#fff');
        circle.setAttribute('stroke-width', String(Math.max(0.6, pinR * 0.28)));

        const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
        text.setAttribute('class', 'stage-marker-label');
        text.setAttribute('text-anchor', 'middle');
        text.setAttribute('dominant-baseline', 'central');
        text.setAttribute('y', '0.15');
        text.setAttribute('font-size', String(pinR * 1.05));
        text.setAttribute('font-family', 'Helvetica, Arial, sans-serif');
        text.setAttribute('font-weight', '700');
        text.setAttribute('fill', '#ffffff');
        text.style.pointerEvents = 'none';
        text.textContent = String(i + 1);

        g.appendChild(circle);
        g.appendChild(text);
        layer.appendChild(g);
      });
    }

    function setMarkerPos(index, x, y) {
      markers[index] = {
        x: clamp(x, vbX, vbX + vbW),
        y: clamp(y, vbY, vbY + vbH),
      };
      const g = layer.querySelector(`[data-index="${index}"]`);
      if (g) {
        g.setAttribute('transform', `translate(${markers[index].x},${markers[index].y})`);
      }
    }

    function markerIndexFromTarget(target) {
      const g = target && target.closest ? target.closest('.stage-marker') : null;
      if (!g || !layer.contains(g)) return -1;
      const idx = Number(g.getAttribute('data-index'));
      return Number.isFinite(idx) ? idx : -1;
    }

    function onPointerDown(e) {
      if (e.button != null && e.button !== 0) return;
      const pt = clientToSvgPoint(svg, e.clientX, e.clientY);
      if (!pt) return;

      const hit = markerIndexFromTarget(e.target);

      if (hit >= 0) {
        dragIndex = hit;
      } else if (markers.length < max) {
        markers.push({ x: clamp(pt.x, vbX, vbX + vbW), y: clamp(pt.y, vbY, vbY + vbH) });
        dragIndex = markers.length - 1;
        renderMarkers();
        syncInput();
      } else {
        return;
      }

      const g = layer.querySelector(`[data-index="${dragIndex}"]`);
      if (g) g.style.cursor = 'grabbing';
      svg.classList.add('is-dragging');
      try {
        svg.setPointerCapture(e.pointerId);
      } catch (err) {
        // ignore
      }
      e.preventDefault();
    }

    function onPointerMove(e) {
      if (dragIndex < 0) return;
      const pt = clientToSvgPoint(svg, e.clientX, e.clientY);
      if (!pt) return;
      setMarkerPos(dragIndex, pt.x, pt.y);
      e.preventDefault();
    }

    function onPointerUp(e) {
      if (dragIndex < 0) return;
      const g = layer.querySelector(`[data-index="${dragIndex}"]`);
      if (g) g.style.cursor = 'grab';
      dragIndex = -1;
      svg.classList.remove('is-dragging');
      try {
        svg.releasePointerCapture(e.pointerId);
      } catch (err) {
        // ignore
      }
      syncInput();
    }

    svg.addEventListener('pointerdown', onPointerDown);
    svg.addEventListener('pointermove', onPointerMove);
    svg.addEventListener('pointerup', onPointerUp);
    svg.addEventListener('pointercancel', onPointerUp);
    svg.style.touchAction = 'none';

    if (clearBtn) {
      clearBtn.addEventListener('click', () => {
        markers = [];
        renderMarkers();
        syncInput();
      });
    }

    // Restore from draft / existing hidden value
    renderMarkers();
    if (clearBtn) clearBtn.hidden = markers.length === 0;
  }

  function initStageDiagrams() {
    form.querySelectorAll('.stage-diagram').forEach((wrap) => initStageDiagram(wrap));
  }

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors();
    showStatus('');

    let ok = true;
    form.querySelectorAll('.stepper-value').forEach((input) => {
      if (!validateField(input)) ok = false;
    });
    ['event_date', 'event_name', 'judge_name', 'competitor_name', 'competitor_email'].forEach((name) => {
      const el = form.elements.namedItem(name);
      if (el && !String(el.value).trim()) {
        setFieldError(name, 'Required.');
        ok = false;
      }
    });
    if (eventSelect && !String(eventSelect.value).trim()) {
      setFieldError('event_id', 'Select an event.');
      ok = false;
    }
    const competitorIdField = form.elements.namedItem('competitor_id');
    if (!competitorIdField || !String(competitorIdField.value).trim()) {
      showStatus('Select a competitor from the list first.', 'error');
      return;
    }
    if (!validatePaperSheet()) ok = false;
    if (!ok) {
      showStatus('Fix the highlighted fields.', 'error');
      return;
    }

    submitBtn.disabled = true;
    showStatus('Saving…', 'info');

    try {
      const res = await fetch('/submit.php', {
        method: 'POST',
        body: new FormData(form),
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      const payload = await res.json().catch(() => ({}));

      if (res.status === 403) {
        showStatus(payload.errors?._form || 'Session expired. Reload and try again.', 'error');
        return;
      }

      if (!payload.success) {
        const errors = payload.errors || {};
        Object.keys(errors).forEach((key) => {
          if (key === '_form') return;
          setFieldError(key, errors[key]);
        });
        showStatus(errors._form || 'Could not save. Check highlighted fields.', 'error');
        return;
      }

      const total = payload.grandTotal != null ? ` Grand total: ${payload.grandTotal}.` : '';
      if (payload.duplicate) {
        showStatus(`Already saved (score #${payload.scoreId}).${total}`, 'success');
      } else {
        let msg = `Score #${payload.scoreId} saved.${total}`;
        if (payload.paperStored) {
          msg += ' Paper sheet archived.';
        }
        msg += ' Scorecard email is sent by an admin when ready.';
        showStatus(msg, 'success');
      }

      const redirectTo = form.dataset.redirectOnSuccess || payload.redirect || '/score.php';
      clearDraft();
      setTimeout(() => {
        window.location.href = redirectTo;
      }, 900);
    } catch (err) {
      showStatus('Network error. Check connection and try again.', 'error');
      submitBtn.disabled = false;
    }
  });

  // Init
  setUuid();
  const restored = loadDraft();
  if (!restored) {
    const dateEl = form.elements.namedItem('event_date');
    if (dateEl && dateEl.type === 'date' && !dateEl.value) {
      dateEl.value = new Date().toISOString().slice(0, 10);
    }
  }
  syncEventFromSelect();
  updateTotals();
  // After draft restore so hidden marker JSON is already populated
  initStageDiagrams();
})();
