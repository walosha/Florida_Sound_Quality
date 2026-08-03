<?php
/**
 * Public live scoreboard — no login required.
 */

declare(strict_types=1);
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
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-scoreboard">
    <header class="app-header">
        <div class="brand">Florida Sound Quality</div>
        <nav>
            <a href="/login.php">Judge login</a>
        </nav>
    </header>

    <main class="scoreboard-main">
        <h1 class="page-title">Live Scoreboard</h1>
        <p class="page-lead">Standings update every 5 seconds.</p>

        <div class="scoreboard-controls">
            <div class="field">
                <label for="event-filter">Event</label>
                <select id="event-filter" aria-label="Filter by event">
                    <option value="">Loading…</option>
                </select>
            </div>
        </div>

        <ol id="score-list" class="score-list" aria-live="polite"></ol>
        <p id="scoreboard-empty" class="scoreboard-empty" hidden>No scores yet for this event.</p>
    </main>

    <script src="/js/scoreboard.js" defer></script>
</body>
</html>
