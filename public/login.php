<?php
/**
 * Staff login (admin / judge) — email + password with rate limiting and CSRF.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

startAppSession();

if (isLoggedIn()) {
    $user = currentUser();
    header('Location: ' . homePathForRole($user['role'] ?? 'judge'));
    exit;
}

$error = '';
$ip = clientIp();
$emailValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } elseif (isLoginLockedOut($ip)) {
        $error = 'Too many failed attempts. Try again in 15 minutes.';
    } else {
        $emailValue = trim((string) ($_POST['email'] ?? ''));
        // bcrypt only uses the first 72 bytes; reject oversized input to avoid DoS.
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) > 72) {
            $password = substr($password, 0, 72);
        }

        $user = authenticateUser($emailValue, $password);
        if ($user !== null) {
            clearLoginAttempts($ip);
            loginUser($user);
            header('Location: ' . homePathForRole((string) $user['role']));
            exit;
        }

        recordFailedLogin($ip);
        if (isLoginLockedOut($ip)) {
            $error = 'Too many failed attempts. Try again in 15 minutes.';
        } else {
            $error = 'Incorrect email or password.';
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
    <title>Login — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-login">
    <main class="login-panel">
        <h1>Florida Sound Quality</h1>
        <p class="lead">Staff login</p>

        <?php if ($error !== ''): ?>
            <p role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <form method="post" action="/login.php" autocomplete="on">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <div class="field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    autofocus
                    autocomplete="username"
                    maxlength="255"
                    value="<?= htmlspecialchars($emailValue, ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>
            <div class="field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >
            </div>
            <button type="submit" class="btn-primary">Log in</button>
        </form>
    </main>
</body>
</html>
