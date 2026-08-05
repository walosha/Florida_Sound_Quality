<?php
/**
 * Admin panel stub (Phase 0). Full UI arrives in Phase 3.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');
$user = currentUser();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-login">
    <main class="login-panel">
        <h1>Admin</h1>
        <p class="lead">
            Signed in as <?= htmlspecialchars($user['name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
            (<?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>)
        </p>
        <p>Admin panel is coming in a later phase. Invite links, competitor list, and scorecard email will live here.</p>
        <p><a href="/logout.php">Log out</a></p>
    </main>
</body>
</html>
