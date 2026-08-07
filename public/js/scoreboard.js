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
  const pager = document.getElementById('scoreboard-pagination');
  let currentEvent = '';
  let currentPage = 1;
  let perPage = 25;
  let totalPages = 1;
  let timer = null;

  const panel =
    window.FSQScoreDetailPanel && typeof window.FSQScoreDetailPanel.create === 'function'
      ? window.FSQScoreDetailPanel.create({})
      : null;

  function escapeHtml(s) {
    return window.FSQScoreDetailPanel && window.FSQScoreDetailPanel.escapeHtml
      ? window.FSQScoreDetailPanel.escapeHtml(s)
      : String(s);
  }

  function vehicleLabel(row) {
    return window.FSQScoreDetailPanel && window.FSQScoreDetailPanel.vehicleLabel
      ? window.FSQScoreDetailPanel.vehicleLabel(row)
      : '—';
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
      if (panel && panel.isOpen()) return;
      loadScores().catch(() => {});
    }, 5000);
  }

  if (list) {
    list.addEventListener('click', (event) => {
      const btn = event.target.closest('.score-row-btn');
      if (!btn || !list.contains(btn)) return;
      event.preventDefault();
      if (panel) {
        panel.openByScoreId(btn.dataset.scoreId);
      }
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
