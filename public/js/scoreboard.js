/**
 * Live scoreboard — poll /api/scores.php every 5s.
 * Click a competitor row to open score details.
 */
(function () {
  'use strict';

  const PER_PAGE_OPTIONS = [10, 25, 50, 100];
  const select = document.getElementById('event-filter');
  const perPageSelect = document.getElementById('per-page-filter');
  const list = document.getElementById('score-list');
  const empty = document.getElementById('scoreboard-empty');
  const detail = document.getElementById('score-detail');
  const detailBody = document.getElementById('score-detail-body');
  const detailClose = document.getElementById('score-detail-close');
  const detailBackdrop = document.getElementById('score-detail-backdrop');
  const pagerTop = document.getElementById('scoreboard-pagination');
  const pagerBottom = document.getElementById('scoreboard-pagination-bottom');
  let currentEvent = '';
  let currentPage = 1;
  let perPage = 25;
  let totalPages = 1;
  let timer = null;
  let openScoreId = null;
  let lastFocus = null;

  function vehicleLabel(row) {
    const parts = [row.vehicle_year, row.vehicle_make, row.vehicle_model].filter(Boolean);
    if (row.vehicle_color) {
      parts.push('(' + row.vehicle_color + ')');
    }
    return parts.length ? parts.join(' ') : '—';
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function isDetailOpen() {
    return detail && !detail.hasAttribute('hidden');
  }

  function openPanel() {
    if (!detail) return;
    lastFocus = document.activeElement;
    detail.removeAttribute('hidden');
    document.body.classList.add('score-detail-open');
    if (detailClose) {
      detailClose.focus();
    }
  }

  function closePanel() {
    if (!detail) return;
    detail.setAttribute('hidden', '');
    document.body.classList.remove('score-detail-open');
    openScoreId = null;
    if (detailBody) {
      detailBody.innerHTML = '';
    }
    if (lastFocus && typeof lastFocus.focus === 'function') {
      lastFocus.focus();
    }
    lastFocus = null;
  }

  function setBtnDisabled(btn, disabled) {
    if (!btn) return;
    btn.disabled = disabled;
    btn.classList.toggle('is-disabled', disabled);
    btn.setAttribute('aria-disabled', disabled ? 'true' : 'false');
  }

  function updatePagination(meta) {
    const total = meta.total || 0;
    const page = meta.page || 1;
    const pages = meta.total_pages || 1;
    const from = meta.from || 0;
    const to = meta.to || 0;
    currentPage = page;
    totalPages = pages;

    const show = total > 0;
    [pagerTop, pagerBottom].forEach((el) => {
      if (!el) return;
      if (show) el.removeAttribute('hidden');
      else el.setAttribute('hidden', '');
    });

    const rangeText = total ? `Showing ${from}–${to} of ${total}` : '';
    const pageText = `Page ${page} of ${pages}`;
    const rangeTop = document.getElementById('scoreboard-range');
    const rangeBottom = document.getElementById('scoreboard-range-bottom');
    const pageTop = document.getElementById('scoreboard-page');
    const pageBottom = document.getElementById('scoreboard-page-bottom');
    if (rangeTop) rangeTop.textContent = rangeText;
    if (rangeBottom) rangeBottom.textContent = rangeText;
    if (pageTop) pageTop.textContent = pageText;
    if (pageBottom) pageBottom.textContent = pageText;

    setBtnDisabled(document.getElementById('scoreboard-prev'), page <= 1);
    setBtnDisabled(document.getElementById('scoreboard-prev-bottom'), page <= 1);
    setBtnDisabled(document.getElementById('scoreboard-next'), page >= pages);
    setBtnDisabled(document.getElementById('scoreboard-next-bottom'), page >= pages);
  }

  function render(rows) {
    if (!list) return;
    list.innerHTML = '';
    if (!rows.length) {
      if (empty) empty.hidden = false;
      return;
    }
    if (empty) empty.hidden = true;
    rows.forEach((row) => {
      const scoreId = row.id;
      const li = document.createElement('li');
      li.className = 'score-row' + (row.rank <= 3 ? ` rank-${row.rank}` : '');
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'score-row-btn';
      btn.setAttribute('aria-haspopup', 'dialog');
      if (scoreId != null) {
        btn.dataset.scoreId = String(scoreId);
      }
      btn.innerHTML = `
        <span class="rank">${row.rank}</span>
        <span class="who">
          <span class="name">${escapeHtml(row.competitor_name)}</span>
          <span class="vehicle">${escapeHtml(vehicleLabel(row))}</span>
        </span>
        <span class="points">${row.total_score}<span class="total-max"> / 230</span></span>
      `;
      li.appendChild(btn);
      list.appendChild(li);
    });
  }

  function metricRow(label, value, max) {
    return `<div class="score-metric"><span>${escapeHtml(label)}</span><strong>${value}${max ? ' / ' + max : ''}</strong></div>`;
  }

  function notesBlock(label, text) {
    if (!text) return '';
    return `<p class="score-detail-notes"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</p>`;
  }

  function renderDetail(d) {
    if (!detailBody) return;
    const placement = d.placement
      ? `<p class="score-detail-meta">Placement: ${escapeHtml(d.placement)}</p>`
      : '';

    detailBody.innerHTML = `
      <header class="score-detail-header">
        <p class="eyebrow">Score details</p>
        <h2 id="score-detail-title">${escapeHtml(d.competitor_name)}</h2>
        <p class="score-detail-vehicle">${escapeHtml(vehicleLabel(d))}</p>
        <p class="score-detail-meta">${escapeHtml(d.event_name)} · ${escapeHtml(d.event_date)}</p>
        <p class="score-detail-meta">Judge: ${escapeHtml(d.judge_name)}</p>
        <p class="score-detail-meta">${escapeHtml(d.competitor_email)}</p>
        ${placement}
        <p class="score-detail-total">${d.grand_total}<span class="total-max"> / 230</span></p>
      </header>

      <section class="score-detail-section">
        <h3>Tonal Accuracy</h3>
        ${metricRow('Sub-Bass', d.sub_bass, 20)}
        ${metricRow('Mid-Bass', d.mid_bass, 20)}
        ${metricRow('Midrange', d.midrange, 20)}
        ${metricRow('High Frequency', d.high_freq, 20)}
        ${metricRow('Spectral Balance', d.spectral_balance, 20)}
        ${metricRow('Tonal subtotal', d.tonal_total, 100)}
        ${notesBlock('Notes', d.tonal_notes)}
      </section>

      <section class="score-detail-section">
        <h3>Sound Stage</h3>
        ${metricRow('Listening Position', d.listening_position, 15)}
        ${metricRow('Width', d.width, 15)}
        ${metricRow('Height', d.height, 15)}
        ${metricRow('Depth', d.depth, 10)}
        ${metricRow('Ambience', d.ambience, 10)}
        ${metricRow('Stage subtotal', d.stage_total, 65)}
        ${notesBlock('Notes', d.stage_notes)}
      </section>

      <section class="score-detail-section">
        <h3>Imaging</h3>
        ${metricRow('Imaging', d.imaging_score, 50)}
        ${notesBlock('Notes', d.imaging_notes)}
      </section>

      <section class="score-detail-section">
        <h3>Noise &amp; Listening</h3>
        ${metricRow('Noise', d.noise, 5)}
        ${metricRow('Listening Pleasure', d.listening_pleasure, 10)}
        ${notesBlock('Noise notes', d.noise_notes)}
        ${notesBlock('Listening notes', d.listening_notes)}
      </section>
    `;
  }

  async function openDetail(scoreId) {
    if (!detailBody) return;
    if (scoreId == null || scoreId === '' || scoreId === 'undefined') {
      detailBody.innerHTML = '<p class="score-detail-error">Missing score id.</p>';
      openPanel();
      return;
    }

    openScoreId = String(scoreId);
    detailBody.innerHTML = '<p class="score-detail-loading">Loading…</p>';
    openPanel();

    try {
      const res = await fetch('/api/scores.php?action=detail&id=' + encodeURIComponent(scoreId), {
        credentials: 'same-origin',
      });
      if (!res.ok) {
        throw new Error('detail failed');
      }
      const data = await res.json();
      if (openScoreId !== String(scoreId)) {
        return;
      }
      renderDetail(data);
    } catch (err) {
      if (openScoreId !== String(scoreId)) {
        return;
      }
      detailBody.innerHTML = '<p class="score-detail-error">Could not load details.</p>';
    }
  }

  async function loadEvents() {
    const res = await fetch('/api/scores.php?action=events', { credentials: 'same-origin' });
    const data = await res.json();
    const events = data.events || [];
    select.innerHTML = '';
    if (!events.length) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No events yet';
      select.appendChild(opt);
      currentEvent = '';
      render([]);
      updatePagination({ total: 0, page: 1, total_pages: 1, from: 0, to: 0 });
      return;
    }
    events.forEach((name) => {
      const opt = document.createElement('option');
      opt.value = name;
      opt.textContent = name;
      select.appendChild(opt);
    });
    currentEvent = data.default || events[0];
    select.value = currentEvent;
  }

  async function loadScores() {
    if (!currentEvent) {
      render([]);
      updatePagination({ total: 0, page: 1, total_pages: 1, from: 0, to: 0 });
      return;
    }
    const url =
      '/api/scores.php?event=' +
      encodeURIComponent(currentEvent) +
      '&page=' +
      encodeURIComponent(String(currentPage)) +
      '&per_page=' +
      encodeURIComponent(String(perPage));
    const res = await fetch(url, { credentials: 'same-origin' });
    const data = await res.json();
    const rows = Array.isArray(data) ? data : data.scores || [];
    const meta = Array.isArray(data)
      ? {
          total: rows.length,
          page: 1,
          total_pages: 1,
          from: rows.length ? 1 : 0,
          to: rows.length,
        }
      : data;
    if (meta.page && meta.page !== currentPage) {
      currentPage = meta.page;
    }
    render(rows);
    updatePagination(meta);
  }

  function goToPage(page) {
    const next = Math.max(1, Math.min(totalPages, page));
    if (next === currentPage) return;
    currentPage = next;
    loadScores().catch(() => {});
    startPolling();
  }

  function startPolling() {
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
      if (isDetailOpen()) return;
      loadScores().catch(() => {});
    }, 5000);
  }

  if (list) {
    list.addEventListener('click', (event) => {
      const btn = event.target.closest('.score-row-btn');
      if (!btn || !list.contains(btn)) return;
      event.preventDefault();
      openDetail(btn.dataset.scoreId);
    });
  }

  if (select) {
    select.addEventListener('change', () => {
      currentEvent = select.value;
      currentPage = 1;
      loadScores().catch(() => {});
      startPolling();
    });
  }

  if (perPageSelect) {
    const initial = parseInt(perPageSelect.value, 10);
    if (PER_PAGE_OPTIONS.indexOf(initial) !== -1) {
      perPage = initial;
    }
    perPageSelect.addEventListener('change', () => {
      const next = parseInt(perPageSelect.value, 10);
      perPage = PER_PAGE_OPTIONS.indexOf(next) !== -1 ? next : 25;
      currentPage = 1;
      loadScores().catch(() => {});
      startPolling();
    });
  }

  function bindPager(prevId, nextId) {
    const prev = document.getElementById(prevId);
    const next = document.getElementById(nextId);
    if (prev) {
      prev.addEventListener('click', () => goToPage(currentPage - 1));
    }
    if (next) {
      next.addEventListener('click', () => goToPage(currentPage + 1));
    }
  }
  bindPager('scoreboard-prev', 'scoreboard-next');
  bindPager('scoreboard-prev-bottom', 'scoreboard-next-bottom');

  if (detailClose) {
    detailClose.addEventListener('click', () => closePanel());
  }
  if (detailBackdrop) {
    detailBackdrop.addEventListener('click', () => closePanel());
  }
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && isDetailOpen()) {
      closePanel();
    }
  });

  loadEvents()
    .then(loadScores)
    .then(startPolling)
    .catch(() => {
      if (empty) {
        empty.hidden = false;
        empty.textContent = 'Could not load scoreboard.';
      }
    });
})();
