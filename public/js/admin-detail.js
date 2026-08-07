/**
 * Admin: open competitor/score details from overview, competitors, and scores lists.
 */
(function () {
  'use strict';

  if (!window.FSQScoreDetailPanel || typeof window.FSQScoreDetailPanel.create !== 'function') {
    return;
  }

  const panel = window.FSQScoreDetailPanel.create({});

  function openFromTrigger(el) {
    if (!el) return;
    const scoreId = el.getAttribute('data-score-id');
    if (scoreId) {
      panel.openByScoreId(scoreId);
      return;
    }

    panel.openCompetitor({
      name: el.getAttribute('data-name') || 'Unnamed',
      email: el.getAttribute('data-email') || '',
      vehicle_year: el.getAttribute('data-vehicle-year') || '',
      vehicle_make: el.getAttribute('data-vehicle-make') || '',
      vehicle_model: el.getAttribute('data-vehicle-model') || '',
      vehicle_color: el.getAttribute('data-vehicle-color') || '',
      status: el.getAttribute('data-status') || '',
      status_label: el.getAttribute('data-status-label') || '',
      registered_at: el.getAttribute('data-registered-at') || '',
    });
  }

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-open-detail]');
    if (!trigger) return;

    const interactive = event.target.closest('a, form, input, select, textarea, button');
    if (interactive && interactive !== trigger && !interactive.hasAttribute('data-open-detail')) {
      return;
    }

    event.preventDefault();
    openFromTrigger(trigger);
  });
})();
