/**
 * Live scoreboard — poll /api/scores.php every 5s.
 * Click a competitor row to open score details.
 */
(function () {
  'use strict';

  const select = document.getElementById('event-filter');
  const list = document.getElementById('score-list');
  const empty = document.getElementById('scoreboard-empty');
  const dialog = document.getElementById('score-detail');
  const detailBody = document.getElementById('score-detail-body');
  let currentEvent = '';
  let timer = null;
  let openScoreId = null;

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
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'score-row-btn';
      btn.setAttribute('aria-haspopup', 'dialog');
      btn.dataset.scoreId = String(row.id);
      btn.innerHTML = `
        <span class="rank">${row.rank}</span>
        <span class="who">
          <span class="name">${escapeHtml(row.competitor_name)}</span>
          <span class="vehicle">${escapeHtml(vehicleLabel(row))}</span>
        </span>
        <span class="points">${row.total_score}<span class="total-max"> / 230</span></span>
      `;
      btn.addEventListener('click', () => {
        openDetail(row.id).catch(() => {
          detailBody.innerHTML = '<p class="score-detail-error">Could not load details.</p>';
          if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
            dialog.showModal();
          }
        });
      });
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
    openScoreId = scoreId;
    detailBody.innerHTML = '<p class="score-detail-loading">Loading…</p>';
    if (dialog && typeof dialog.showModal === 'function' && !dialog.open) {
      dialog.showModal();
    }
    const res = await fetch('/api/scores.php?action=detail&id=' + encodeURIComponent(scoreId), {
      credentials: 'same-origin',
    });
    if (!res.ok) {
      throw new Error('detail failed');
    }
    const data = await res.json();
    if (openScoreId !== scoreId) {
      return;
    }
    renderDetail(data);
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

  if (dialog) {
    dialog.addEventListener('close', () => {
      openScoreId = null;
      detailBody.innerHTML = '';
    });
  }

  loadEvents()
    .then(loadScores)
    .then(startPolling)
    .catch(() => {
      empty.hidden = false;
      empty.textContent = 'Could not load scoreboard.';
    });
})();
