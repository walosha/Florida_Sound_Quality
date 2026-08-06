<?php
/**
 * Staff-only live scoreboard — requires admin or judge login.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireRole(['admin', 'judge']);
$user = currentUser();
$home = homePathForRole($user['role'] ?? 'judge');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scoreboard — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=3">
</head>
<body class="page-scoreboard">
    <header class="app-header">
        <div class="brand">Florida Sound Quality</div>
        <nav>
            <a href="<?= htmlspecialchars($home, ENT_QUOTES, 'UTF-8') ?>">Back</a>
            <a href="/logout.php">Log out</a>
        </nav>
    </header>

    <main class="scoreboard-main">
        <h1 class="page-title">Live Scoreboard</h1>
        <p class="page-lead">Staff only. Standings update every 5 seconds. Click a competitor for score details.</p>

        <div class="scoreboard-controls">
            <div class="field">
                <label for="event-filter">Event</label>
                <select id="event-filter" aria-label="Filter by event">
                    <option value="">Loading…</option>
                </select>
            </div>
            <div class="field">
                <label for="per-page-filter">Per page</label>
                <select id="per-page-filter" aria-label="Results per page">
                    <option value="10">10</option>
                    <option value="25" selected>25</option>
                    <option value="50">50</option>
                    <option value="100">100</option>
                </select>
            </div>
        </div>

        <div id="scoreboard-pagination" class="list-pagination" hidden>
            <p class="list-pagination-range" id="scoreboard-range"></p>
            <div class="list-pagination-nav">
                <button type="button" class="btn-secondary" id="scoreboard-prev">Previous</button>
                <span class="list-pagination-page" id="scoreboard-page"></span>
                <button type="button" class="btn-secondary" id="scoreboard-next">Next</button>
            </div>
        </div>

        <ol id="score-list" class="score-list" aria-live="polite"></ol>
        <p id="scoreboard-empty" class="scoreboard-empty" hidden>No scores yet for this event.</p>

        <div id="scoreboard-pagination-bottom" class="list-pagination" hidden>
            <p class="list-pagination-range" id="scoreboard-range-bottom"></p>
            <div class="list-pagination-nav">
                <button type="button" class="btn-secondary" id="scoreboard-prev-bottom">Previous</button>
                <span class="list-pagination-page" id="scoreboard-page-bottom"></span>
                <button type="button" class="btn-secondary" id="scoreboard-next-bottom">Next</button>
            </div>
        </div>
    </main>

    <div id="score-detail" class="score-detail" hidden>
        <button type="button" class="score-detail-backdrop" id="score-detail-backdrop" aria-label="Close details"></button>
        <div class="score-detail-panel" role="dialog" aria-modal="true" aria-labelledby="score-detail-title">
            <div class="score-detail-toolbar">
                <button type="button" class="btn-secondary" id="score-detail-close">Close</button>
            </div>
            <div id="score-detail-body" class="score-detail-body">
                <p class="score-detail-loading">Loading…</p>
            </div>
        </div>
    </div>

    <script src="/js/scoreboard.js?v=3" defer></script>
</body>
</html>
