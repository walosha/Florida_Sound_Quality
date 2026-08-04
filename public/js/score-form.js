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
  let paperObjectUrl = null;

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
      });
    });
    input.addEventListener('input', () => {
      validateField(input);
      updateTotals();
    });
    input.addEventListener('blur', () => validateField(input));
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
        if (payload.emailSent === false && payload.emailWarning) {
          msg += ' ' + payload.emailWarning;
        } else if (payload.emailSent === false) {
          msg += ' Email not sent.';
        } else if (payload.emailSent) {
          msg += ' Email sent.';
        }
        if (payload.paperStored) {
          msg += ' Paper sheet archived.';
        }
        showStatus(msg, payload.emailSent === false && !payload.duplicate ? 'warn' : 'success');
      }

      form.reset();
      resetPaperSheet();
      const dateEl = form.elements.namedItem('event_date');
      if (dateEl) dateEl.value = new Date().toISOString().slice(0, 10);
      setUuid();
      updateTotals();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } catch (err) {
      showStatus('Network error. Check connection and try again.', 'error');
    } finally {
      submitBtn.disabled = false;
    }
  });

  // Init
  setUuid();
  const dateEl = form.elements.namedItem('event_date');
  if (dateEl && !dateEl.value) {
    dateEl.value = new Date().toISOString().slice(0, 10);
  }
  updateTotals();
})();
