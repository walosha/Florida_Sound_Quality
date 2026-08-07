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
  const pager = document.getElementById('scoreboard-pagination');
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

    if (pager) {
      if (total > 0) pager.removeAttribute('hidden');
      else pager.setAttribute('hidden', '');
    }

    const rangeText = total ? `Showing ${from}–${to} of ${total}` : '';
    const pageText = `Page ${page} of ${pages}`;
    const rangeEl = document.getElementById('scoreboard-range');
    const pageEl = document.getElementById('scoreboard-page');
    if (rangeEl) rangeEl.textContent = rangeText;
    if (pageEl) pageEl.textContent = pageText;

    setBtnDisabled(document.getElementById('scoreboard-prev'), page <= 1);
    setBtnDisabled(document.getElementById('scoreboard-next'), page >= pages);
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

  const MARKER_COLORS = ['#c0392b', '#2471a3', '#1e8449', '#b9770e'];
  const STAGE_DIAGRAMS = [
    {
      key: 'stage_markers_wh',
      src: '/assets/svg/width-height.svg',
      label: 'Width / Height',
      vbW: 168,
      vbH: 94,
    },
    {
      key: 'stage_markers_depth',
      src: '/assets/svg/depth.svg',
      label: 'Depth',
      vbW: 247,
      vbH: 92,
    },
  ];

  function normalizeMarkers(raw) {
    if (!Array.isArray(raw)) return [];
    return raw
      .filter((m) => {
        if (!m || typeof m !== 'object') return false;
        if (m.x == null || m.y == null || m.x === '' || m.y === '') return false;
        return Number.isFinite(Number(m.x)) && Number.isFinite(Number(m.y));
      })
      .slice(0, 4)
      .map((m) => ({ x: Number(m.x), y: Number(m.y) }));
  }

  function appendStaticMarkers(svg, markers, vbW, vbH) {
    let layer = svg.querySelector('.stage-marker-layer');
    if (!layer) {
      layer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      layer.setAttribute('class', 'stage-marker-layer is-static');
      svg.appendChild(layer);
    }
    layer.replaceChildren();
    const pinR = Math.max(2.2, Math.min(vbW, vbH) * 0.035);
    markers.forEach((m, i) => {
      const g = document.createElementNS('http://www.w3.org/2000/svg', 'g');
      g.setAttribute('class', 'stage-marker');
      g.setAttribute('transform', `translate(${m.x},${m.y})`);

      const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
      circle.setAttribute('r', String(pinR));
      circle.setAttribute('fill', MARKER_COLORS[i] || '#333');
      circle.setAttribute('stroke', '#fff');
      circle.setAttribute('stroke-width', String(Math.max(0.6, pinR * 0.28)));

      const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
      text.setAttribute('text-anchor', 'middle');
      text.setAttribute('dominant-baseline', 'central');
      text.setAttribute('y', '0.15');
      text.setAttribute('font-size', String(pinR * 1.05));
      text.setAttribute('font-family', 'Helvetica, Arial, sans-serif');
      text.setAttribute('font-weight', '700');
      text.setAttribute('fill', '#ffffff');
      text.textContent = String(i + 1);

      g.appendChild(circle);
      g.appendChild(text);
      layer.appendChild(g);
    });
  }

  async function mountStageDiagrams(container, detail) {
    if (!container) return;
    const blocks = await Promise.all(
      STAGE_DIAGRAMS.map(async (diag) => {
        const markers = normalizeMarkers(detail[diag.key]);
        const wrap = document.createElement('div');
        wrap.className = 'stage-diagram is-static';
        wrap.innerHTML = `<h4 class="stage-diagram-title">${escapeHtml(diag.label)}</h4>
          <div class="stage-diagram-canvas"></div>`;
        const canvas = wrap.querySelector('.stage-diagram-canvas');
        try {
          const res = await fetch(diag.src, { credentials: 'same-origin' });
          if (!res.ok) throw new Error('svg fetch failed');
          const text = await res.text();
          const doc = new DOMParser().parseFromString(text, 'image/svg+xml');
          const svg = doc.documentElement;
          if (!svg || svg.nodeName.toLowerCase() !== 'svg') throw new Error('bad svg');
          svg.removeAttribute('width');
          svg.removeAttribute('height');
          svg.setAttribute('class', 'stage-diagram-svg is-static');
          svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
          canvas.appendChild(document.importNode(svg, true));
          appendStaticMarkers(canvas.querySelector('svg'), markers, diag.vbW, diag.vbH);
        } catch (err) {
          canvas.innerHTML = '<p class="field-hint">Diagram unavailable.</p>';
        }
        return wrap;
      })
    );
    container.replaceChildren(...blocks);
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
        <div class="stage-diagrams score-detail-diagrams" data-stage-diagrams></div>
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

    mountStageDiagrams(detailBody.querySelector('[data-stage-diagrams]'), d);
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

  const prevBtn = document.getElementById('scoreboard-prev');
  const nextBtn = document.getElementById('scoreboard-next');
  if (prevBtn) {
    prevBtn.addEventListener('click', () => goToPage(currentPage - 1));
  }
  if (nextBtn) {
    nextBtn.addEventListener('click', () => goToPage(currentPage + 1));
  }

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
