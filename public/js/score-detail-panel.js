/**
 * Shared staff score-detail overlay.
 * Expects #score-detail, #score-detail-body, #score-detail-close, #score-detail-backdrop.
 */
(function (global) {
  'use strict';

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

  function metricRow(label, value, max) {
    return `<div class="score-metric"><span>${escapeHtml(label)}</span><strong>${value}${max ? ' / ' + max : ''}</strong></div>`;
  }

  function notesBlock(label, text) {
    if (!text) return '';
    return `<p class="score-detail-notes"><strong>${escapeHtml(label)}:</strong> ${escapeHtml(text)}</p>`;
  }

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

  function createScoreDetailPanel(options) {
    const detail = document.getElementById(options.detailId || 'score-detail');
    const detailBody = document.getElementById(options.bodyId || 'score-detail-body');
    const detailClose = document.getElementById(options.closeId || 'score-detail-close');
    const detailBackdrop = document.getElementById(options.backdropId || 'score-detail-backdrop');
    let openScoreId = null;
    let lastFocus = null;
    let requestToken = 0;

    function isOpen() {
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

    function close() {
      if (!detail) return;
      detail.setAttribute('hidden', '');
      document.body.classList.remove('score-detail-open');
      openScoreId = null;
      requestToken += 1;
      if (detailBody) {
        detailBody.innerHTML = '';
      }
      if (lastFocus && typeof lastFocus.focus === 'function') {
        lastFocus.focus();
      }
      lastFocus = null;
    }

    function renderCompetitorOnly(info) {
      if (!detailBody) return;
      const status = info.status_label || info.status || '';
      detailBody.innerHTML = `
        <header class="score-detail-header">
          <p class="eyebrow">Competitor details</p>
          <h2 id="score-detail-title">${escapeHtml(info.name || 'Unnamed')}</h2>
          <p class="score-detail-vehicle">${escapeHtml(vehicleLabel(info))}</p>
          <p class="score-detail-meta">${escapeHtml(info.email || '—')}</p>
          ${status ? `<p class="score-detail-meta">Status: ${escapeHtml(status)}</p>` : ''}
          ${info.registered_at ? `<p class="score-detail-meta">Registered: ${escapeHtml(info.registered_at)}</p>` : ''}
        </header>
        <section class="score-detail-section">
          <p class="score-detail-notes">No score submitted yet.</p>
        </section>
      `;
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

    async function openByScoreId(scoreId) {
      if (!detailBody) return;
      if (scoreId == null || scoreId === '' || scoreId === 'undefined') {
        detailBody.innerHTML = '<p class="score-detail-error">Missing score id.</p>';
        openPanel();
        return;
      }

      openScoreId = String(scoreId);
      const token = ++requestToken;
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
        if (token !== requestToken || openScoreId !== String(scoreId)) {
          return;
        }
        renderDetail(data);
      } catch (err) {
        if (token !== requestToken || openScoreId !== String(scoreId)) {
          return;
        }
        detailBody.innerHTML = '<p class="score-detail-error">Could not load details.</p>';
      }
    }

    function openCompetitor(info) {
      if (!detailBody) return;
      openScoreId = null;
      requestToken += 1;
      renderCompetitorOnly(info || {});
      openPanel();
    }

    if (detailClose) {
      detailClose.addEventListener('click', () => close());
    }
    if (detailBackdrop) {
      detailBackdrop.addEventListener('click', () => close());
    }
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && isOpen()) {
        close();
      }
    });

    return {
      isOpen,
      close,
      openByScoreId,
      openCompetitor,
      vehicleLabel,
      escapeHtml,
    };
  }

  global.FSQScoreDetailPanel = {
    create: createScoreDetailPanel,
    vehicleLabel,
    escapeHtml,
  };
})(window);
