/**
 * Live scoreboard — poll /api/scores.php every 5s.
 */
(function () {
  'use strict';

  const select = document.getElementById('event-filter');
  const list = document.getElementById('score-list');
  const empty = document.getElementById('scoreboard-empty');
  let currentEvent = '';
  let timer = null;

  function vehicleLabel(row) {
    const parts = [row.vehicle_year, row.vehicle_make, row.vehicle_model].filter(Boolean);
    return parts.length ? parts.join(' ') : '—';
  }

  function render(rows) {
    list.innerHTML = '';
    if (!rows.length) {
      empty.hidden = false;
      return;
    }
    empty.hidden = true;
    rows.forEach((row) => {
      const li = document.createElement('li');
      li.className = 'score-row' + (row.rank <= 3 ? ` rank-${row.rank}` : '');
      const place = row.placement
        ? `<span class="placement">${escapeHtml(String(row.placement))}</span>`
        : '';
      li.innerHTML = `
        <div class="rank">${row.rank}</div>
        <div class="who">
          <div class="name">${escapeHtml(row.competitor_name)}</div>
          <div class="vehicle">${escapeHtml(vehicleLabel(row))}</div>
        </div>
        <div class="points">${row.total_score}<span class="total-max"> / 230</span>${place}</div>
      `;
      list.appendChild(li);
    });
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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
      return;
    }
    const url = '/api/scores.php?event=' + encodeURIComponent(currentEvent);
    const res = await fetch(url, { credentials: 'same-origin' });
    const rows = await res.json();
    render(Array.isArray(rows) ? rows : []);
  }

  function startPolling() {
    if (timer) clearInterval(timer);
    timer = setInterval(() => {
      loadScores().catch(() => {});
    }, 5000);
  }

  select.addEventListener('change', () => {
    currentEvent = select.value;
    loadScores().catch(() => {});
    startPolling();
  });

  loadEvents()
    .then(loadScores)
    .then(startPolling)
    .catch(() => {
      empty.hidden = false;
      empty.textContent = 'Could not load scoreboard.';
    });
})();
