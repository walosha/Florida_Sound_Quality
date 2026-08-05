<?php
/**
 * Entry point — redirect based on auth state and role.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (isLoggedIn()) {
    $user = currentUser();
    header('Location: ' . homePathForRole($user['role'] ?? 'judge'));
} else {
    header('Location: /login.php');
}
exit;
