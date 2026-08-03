<?php
/**
 * Judge login — form + POST handler with rate limiting and CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

startAppSession();

if (!empty($_SESSION['authenticated'])) {
    header('Location: /score.php');
    exit;
}

$error = '';
$ip = clientIp();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif (isLoginLockedOut($ip)) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $password = (string) ($_POST['password'] ?? '');

        if (verifyJudgePassword($password)) {
            clearLoginAttempts($ip);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            unset($_SESSION['csrf_token']);
            header('Location: /score.php');
            exit;
        }

        recordFailedLogin($ip);
        if (isLoginLockedOut($ip)) {
            $error = 'Too many failed attempts. Try again in 15 minutes.';
        } else {
            $error = 'Incorrect password.';
        }
    }
}

$token = csrfToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Judge Login — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-login">
    <main class="login-panel">
        <h1>Florida Sound Quality</h1>
        <p class="lead">Judge login</p>

        <?php if ($error !== ''): ?>
            <p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/login.php" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autofocus
                    autocomplete="current-password"
                >
            </div>
            <button type="submit" class="btn-primary">Log in</button>
        </form>
    </main>
</body>
</html>
