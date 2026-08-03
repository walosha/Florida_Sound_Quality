<?php
/**
 * Scoring form stub — full form lands in Phase 2.
 * Present so login redirect and session persistence can be verified now.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

requireLogin();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Score — Florida Sound Quality</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <main>
        <h1>Florida Sound Quality</h1>
        <p>Scoring form (Phase 2)</p>
        <p>You are logged in. Session is active.</p>
        <p><a href="/logout.php">Log out</a></p>
    </main>
</body>
</html>
