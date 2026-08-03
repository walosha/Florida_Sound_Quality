<?php
/**
 * Entry point — redirect based on auth state.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    header('Location: /score.php');
} else {
    header('Location: /login.php');
}
exit;
