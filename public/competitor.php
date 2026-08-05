<?php
/**
 * Public competitor registration via invite token.
 * Pretty URL: /competitor/{64-hex-token}  (also ?token= via rewrite)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/competitors.php';

startAppSession();

$token = (string) ($_GET['token'] ?? '');
$competitor = findCompetitorByToken($token);

$errors = [];
$values = [
    'name'          => '',
    'email'         => '',
    'vehicle_year'  => '',
    'vehicle_make'  => '',
    'vehicle_model' => '',
    'vehicle_color' => '',
];
$formError = '';

if ($competitor === null) {
    http_response_code(404);
    $pageState = 'invalid';
} elseif (($competitor['status'] ?? '') !== 'invited') {
    $pageState = 'used';
} elseif (!competitorInviteIsOpen($competitor)) {
    $pageState = competitorEffectiveStatus($competitor);
    if ($pageState === 'invited') {
        $pageState = 'invalid';
    }
} else {
    $pageState = 'form';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
            $formError = 'Invalid request. Please try again.';
        } else {
            foreach (array_keys($values) as $key) {
                $values[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            $result = validateCompetitorRegistration($_POST);
            if (!$result['ok']) {
                $errors = $result['errors'];
            } else {
                $saved = registerCompetitor((int) $competitor['id'], $result['data']);
                if ($saved['ok']) {
                    $pageState = 'success';
                    $competitor = $saved['competitor'] ?? $competitor;
                    unset($_SESSION['csrf_token']);
                } else {
                    $formError = $saved['error'] ?? 'Could not save registration.';
                    $savedRow = $saved['competitor'] ?? $competitor;
                    if ($savedRow !== null && !competitorInviteIsOpen($savedRow)) {
                        $pageState = competitorEffectiveStatus($savedRow);
                        if (($savedRow['status'] ?? '') !== 'invited') {
                            $pageState = 'used';
                        }
                        $competitor = $savedRow;
                    }
                }
            }
        }
    }
}

$csrf = $pageState === 'form' ? csrfToken() : '';

function fieldClass(array $errors, string $key): string
{
    return isset($errors[$key]) ? 'field is-invalid' : 'field';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Competitor registration — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-register">
    <main class="register-panel">
        <p class="eyebrow">Florida Sound Quality</p>
        <h1>Competitor registration</h1>

        <?php if ($pageState === 'invalid'): ?>
            <p class="lead">This invite link is invalid or incomplete.</p>
            <p>Ask the event organizer for a new registration link.</p>

        <?php elseif ($pageState === 'revoked'): ?>
            <p class="lead">This invite has been revoked.</p>
            <p>Ask the event organizer for a new registration link.</p>

        <?php elseif ($pageState === 'expired'): ?>
            <p class="lead">This invite has expired.</p>
            <p>Ask the event organizer for a new registration link.</p>

        <?php elseif ($pageState === 'used'): ?>
            <p class="lead">This invite has already been used.</p>
            <?php if (!empty($competitor['name'])): ?>
                <p>
                    Registered as
                    <strong><?= htmlspecialchars((string) $competitor['name'], ENT_QUOTES, 'UTF-8') ?></strong>.
                    Judges will score you at the event — no account needed.
                </p>
            <?php else: ?>
                <p>Judges will score you at the event — no account needed.</p>
            <?php endif; ?>

        <?php elseif ($pageState === 'success'): ?>
            <p class="lead flash flash-ok" role="status">You're registered.</p>
            <p>
                Thanks, <?= htmlspecialchars((string) ($competitor['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>.
                Bring your vehicle to the event; a judge will score you from their panel.
            </p>
            <dl class="reg-summary">
                <div>
                    <dt>Vehicle</dt>
                    <dd>
                        <?= htmlspecialchars(
                            trim(
                                implode(' ', array_filter([
                                    (string) ($competitor['vehicle_year'] ?? ''),
                                    (string) ($competitor['vehicle_make'] ?? ''),
                                    (string) ($competitor['vehicle_model'] ?? ''),
                                    (string) ($competitor['vehicle_color'] ?? ''),
                                ]))
                            ),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </dd>
                </div>
                <div>
                    <dt>Email</dt>
                    <dd><?= htmlspecialchars((string) ($competitor['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
            </dl>

        <?php else: ?>
            <p class="lead">Enter your details. No login or account is created.</p>

            <?php if ($formError !== ''): ?>
                <p class="flash flash-error" role="alert"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <form method="post" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

                <div class="<?= fieldClass($errors, 'name') ?>">
                    <label for="name">Name</label>
                    <input type="text" id="name" name="name" maxlength="255" required
                           value="<?= htmlspecialchars($values['name'], ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="name">
                    <?php if (isset($errors['name'])): ?>
                        <p class="field-error"><?= htmlspecialchars($errors['name'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php else: ?>
                        <p class="field-error" hidden></p>
                    <?php endif; ?>
                </div>

                <div class="<?= fieldClass($errors, 'email') ?>">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" maxlength="255" required
                           value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="email">
                    <?php if (isset($errors['email'])): ?>
                        <p class="field-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php else: ?>
                        <p class="field-error" hidden></p>
                    <?php endif; ?>
                </div>

                <h2 class="reg-subhead">Vehicle information</h2>

                <div class="field-grid">
                    <div class="<?= fieldClass($errors, 'vehicle_year') ?>">
                        <label for="vehicle_year">Year</label>
                        <input type="number" id="vehicle_year" name="vehicle_year" min="1900" max="2100" required
                               inputmode="numeric"
                               value="<?= htmlspecialchars($values['vehicle_year'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($errors['vehicle_year'])): ?>
                            <p class="field-error"><?= htmlspecialchars($errors['vehicle_year'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                            <p class="field-error" hidden></p>
                        <?php endif; ?>
                    </div>
                    <div class="<?= fieldClass($errors, 'vehicle_color') ?>">
                        <label for="vehicle_color">Color</label>
                        <input type="text" id="vehicle_color" name="vehicle_color" maxlength="50" required
                               value="<?= htmlspecialchars($values['vehicle_color'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($errors['vehicle_color'])): ?>
                            <p class="field-error"><?= htmlspecialchars($errors['vehicle_color'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                            <p class="field-error" hidden></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field-grid">
                    <div class="<?= fieldClass($errors, 'vehicle_make') ?>">
                        <label for="vehicle_make">Make</label>
                        <input type="text" id="vehicle_make" name="vehicle_make" maxlength="100" required
                               value="<?= htmlspecialchars($values['vehicle_make'], ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="organization">
                        <?php if (isset($errors['vehicle_make'])): ?>
                            <p class="field-error"><?= htmlspecialchars($errors['vehicle_make'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                            <p class="field-error" hidden></p>
                        <?php endif; ?>
                    </div>
                    <div class="<?= fieldClass($errors, 'vehicle_model') ?>">
                        <label for="vehicle_model">Model</label>
                        <input type="text" id="vehicle_model" name="vehicle_model" maxlength="100" required
                               value="<?= htmlspecialchars($values['vehicle_model'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($errors['vehicle_model'])): ?>
                            <p class="field-error"><?= htmlspecialchars($errors['vehicle_model'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                            <p class="field-error" hidden></p>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" class="btn-primary">Submit registration</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
