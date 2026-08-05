<?php
/**
 * Admin panel — invites, competitors, scores, scorecard email, judge accounts.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/competitors.php';
require_once __DIR__ . '/../../includes/admin.php';
require_once __DIR__ . '/../../includes/events.php';

requireRole('admin');
$user = currentUser();
if ($user === null) {
    header('Location: /login.php');
    exit;
}

$flash = '';
$flashError = '';
$createdInviteUrl = null;
$judgeForm = ['name' => '', 'email' => ''];
$judgeErrors = [];
$eventForm = ['name' => '', 'event_date' => ''];
$eventErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        $flashError = 'Invalid request. Please try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'create_invite') {
            try {
                $invite = createCompetitorInvite($user['id']);
                $createdInviteUrl = competitorInviteUrl((string) $invite['invite_token']);
                $flash = 'Invite created'
                    . (!empty($invite['expires_at']) ? ' (expires ' . $invite['expires_at'] . ')' : '')
                    . '. Copy the link below and send it to the competitor.';
                unset($_SESSION['csrf_token']);
            } catch (Throwable $e) {
                error_log('createCompetitorInvite failed: ' . $e->getMessage());
                $flashError = 'Could not create invite. Try again.';
            }
        } elseif ($action === 'revoke_invite') {
            $competitorId = filter_var($_POST['competitor_id'] ?? null, FILTER_VALIDATE_INT);
            if ($competitorId === false || $competitorId <= 0) {
                $flashError = 'Invalid invite.';
            } else {
                $revoked = revokeCompetitorInvite($competitorId);
                if ($revoked['ok']) {
                    $flash = 'Invite revoked.';
                    unset($_SESSION['csrf_token']);
                } else {
                    $flashError = $revoked['error'] ?? 'Could not revoke invite.';
                }
            }
        } elseif ($action === 'send_scorecard') {
            $competitorId = filter_var($_POST['competitor_id'] ?? null, FILTER_VALIDATE_INT);
            if ($competitorId === false || $competitorId <= 0) {
                $flashError = 'Invalid competitor.';
            } else {
                $result = sendCompetitorScorecard($competitorId);
                if ($result['ok']) {
                    $flash = 'Scorecard emailed successfully'
                        . ($result['provider'] ? ' via ' . $result['provider'] : '')
                        . '.';
                    unset($_SESSION['csrf_token']);
                } else {
                    $flashError = $result['error'] ?? 'Could not send scorecard.';
                }
            }
        } elseif ($action === 'create_event') {
            $eventForm['name'] = trim((string) ($_POST['name'] ?? ''));
            $eventForm['event_date'] = trim((string) ($_POST['event_date'] ?? ''));
            $created = createEvent($eventForm['name'], $eventForm['event_date']);
            if ($created['ok']) {
                $flash = 'Event created: ' . ($created['event']['name'] ?? $eventForm['name']);
                $eventForm = ['name' => '', 'event_date' => ''];
                unset($_SESSION['csrf_token']);
            } else {
                $eventErrors = $created['errors'];
                $flashError = 'Fix the event form and try again.';
            }
        } elseif ($action === 'delete_event') {
            $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
            if ($eventId === false || $eventId <= 0) {
                $flashError = 'Invalid event.';
            } else {
                $deleted = deleteEvent($eventId);
                if ($deleted['ok']) {
                    $flash = 'Event deleted.';
                    unset($_SESSION['csrf_token']);
                } else {
                    $flashError = $deleted['error'] ?? 'Could not delete event.';
                }
            }
        } elseif ($action === 'create_judge') {
            $judgeForm['name'] = trim((string) ($_POST['name'] ?? ''));
            $judgeForm['email'] = trim((string) ($_POST['email'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            $created = createJudgeAccount($judgeForm['email'], $judgeForm['name'], $password);
            if ($created['ok']) {
                $flash = 'Judge account created for ' . ($created['user']['email'] ?? $judgeForm['email']) . '.';
                $judgeForm = ['name' => '', 'email' => ''];
                unset($_SESSION['csrf_token']);
            } else {
                $judgeErrors = $created['errors'];
                $flashError = $judgeErrors['_form'] ?? 'Fix the judge form and try again.';
            }
        } else {
            $flashError = 'Unknown action.';
        }
    }
}

$token = csrfToken();
$competitors = listAdminCompetitors();
$scores = listSubmittedScores();
$staff = listStaffUsers();
$events = listEvents();

$scoredCount = 0;
$sentCount = 0;
foreach ($competitors as $row) {
    if (!empty($row['score_id'])) {
        $scoredCount++;
    }
    if (!empty($row['scorecard_sent_at'])) {
        $sentCount++;
    }
}
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
<body class="page-admin">
    <header class="admin-top">
        <div class="admin-top-inner">
            <div>
                <p class="eyebrow">Florida Sound Quality</p>
                <h1 class="page-title">Admin</h1>
                <p class="page-lead">
                    <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                    · <?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?>
                </p>
            </div>
            <a class="admin-logout" href="/logout.php">Log out</a>
        </div>
    </header>

    <main class="admin-main">
        <?php if ($flash !== ''): ?>
            <p class="flash flash-ok" role="status"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <p class="flash flash-error" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>

        <section class="admin-stats" aria-label="Summary">
            <div class="admin-stat">
                <strong><?= count($competitors) ?></strong>
                <span>Competitors</span>
            </div>
            <div class="admin-stat">
                <strong><?= $scoredCount ?></strong>
                <span>Scored</span>
            </div>
            <div class="admin-stat">
                <strong><?= $sentCount ?></strong>
                <span>Scorecards sent</span>
            </div>
            <div class="admin-stat">
                <strong><?= count($staff) ?></strong>
                <span>Staff accounts</span>
            </div>
        </section>

        <section class="admin-section" aria-labelledby="invite-heading">
            <h2 id="invite-heading">Competitor invites</h2>
            <p class="page-lead">
                Generate one unique link per competitor.
                <?php if (INVITE_EXPIRY_DAYS > 0): ?>
                    Links expire after <?= (int) INVITE_EXPIRY_DAYS ?> days unless revoked earlier.
                <?php else: ?>
                    Links do not auto-expire (you can still revoke unused ones).
                <?php endif; ?>
            </p>

            <form method="post" action="/admin/" class="invite-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_invite">
                <button type="submit" class="btn-primary">Generate invite link</button>
            </form>

            <?php if ($createdInviteUrl !== null): ?>
                <div class="invite-result">
                    <label for="new-invite-url">New invite link</label>
                    <div class="invite-copy-row">
                        <input
                            type="text"
                            id="new-invite-url"
                            class="invite-url"
                            readonly
                            value="<?= htmlspecialchars($createdInviteUrl, ENT_QUOTES, 'UTF-8') ?>"
                        >
                        <button type="button" class="btn-secondary" data-copy-target="new-invite-url">Copy</button>
                    </div>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-section" aria-labelledby="events-heading">
            <h2 id="events-heading">Events</h2>
            <p class="page-lead">Reusable event catalog for judges. Scores still store event name/date for PDFs and the public scoreboard.</p>

            <form method="post" action="/admin/" class="judge-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_event">
                <div class="field-grid">
                    <div class="field<?= isset($eventErrors['name']) ? ' is-invalid' : '' ?>">
                        <label for="event_name_new">Event name</label>
                        <input type="text" id="event_name_new" name="name" maxlength="255" required
                               value="<?= htmlspecialchars($eventForm['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($eventErrors['name'])): ?>
                            <p class="field-error"><?= htmlspecialchars($eventErrors['name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="field<?= isset($eventErrors['event_date']) ? ' is-invalid' : '' ?>">
                        <label for="event_date_new">Event date</label>
                        <input type="date" id="event_date_new" name="event_date" required
                               value="<?= htmlspecialchars($eventForm['event_date'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($eventErrors['event_date'])): ?>
                            <p class="field-error"><?= htmlspecialchars($eventErrors['event_date'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary judge-submit">Add event</button>
            </form>

            <?php if ($events === []): ?>
                <p class="empty-note">No events yet. Judges can still type event details until you add some.</p>
            <?php else: ?>
                <div class="table-wrap" style="margin-top: 1.25rem;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Name</th>
                                <th scope="col">Date</th>
                                <th scope="col">Created</th>
                                <th scope="col"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $ev): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars((string) $ev['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><?= htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars((string) ($ev['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <form method="post" action="/admin/" class="inline-form" onsubmit="return confirm('Delete this event?');">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="delete_event">
                                            <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                                            <button type="submit" class="btn-secondary">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-section" aria-labelledby="list-heading">
            <h2 id="list-heading">Competitors</h2>
            <p class="page-lead">Name, vehicle, status. Download or email a PDF scorecard when a score is ready.</p>
            <?php if ($competitors === []): ?>
                <p class="empty-note">No invites yet. Generate a link above.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Status</th>
                                <th scope="col">Name / vehicle</th>
                                <th scope="col">Email</th>
                                <th scope="col">Score</th>
                                <th scope="col">Scorecard</th>
                                <th scope="col">Invite</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($competitors as $row): ?>
                                <?php
                                $status = competitorEffectiveStatus($row);
                                $inviteUrl = competitorInviteUrl((string) $row['invite_token']);
                                $inputId = 'invite-' . (int) $row['id'];
                                $vehicle = competitorVehicleLabel($row);
                                $name = trim((string) ($row['name'] ?? ''));
                                $email = trim((string) ($row['email'] ?? ''));
                                $hasScore = !empty($row['score_id']);
                                $sentAt = $row['scorecard_sent_at'] ?? null;
                                $inviteOpen = competitorInviteIsOpen($row);
                                ?>
                                <tr>
                                    <td>
                                        <span class="status-pill status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if ($status === 'invited' && !empty($row['expires_at'])): ?>
                                            <div class="cell-sub">Expires <?= htmlspecialchars((string) $row['expires_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= htmlspecialchars($name !== '' ? $name : 'Pending registration', ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="cell-sub"><?= htmlspecialchars($vehicle, ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td><?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if ($hasScore): ?>
                                            <strong><?= (int) $row['grand_total'] ?></strong><span class="total-max"> / 230</span>
                                            <div class="cell-sub">
                                                <?= htmlspecialchars((string) ($row['score_event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="cell-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($hasScore): ?>
                                            <div class="admin-actions">
                                                <a class="btn-secondary" href="/admin/scorecard.php?competitor_id=<?= (int) $row['id'] ?>">Download PDF</a>
                                                <form method="post" action="/admin/" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="send_scorecard">
                                                    <input type="hidden" name="competitor_id" value="<?= (int) $row['id'] ?>">
                                                    <button type="submit" class="btn-secondary">
                                                        <?= $sentAt ? 'Resend email' : 'Send email' ?>
                                                    </button>
                                                </form>
                                            </div>
                                            <?php if ($sentAt): ?>
                                                <div class="cell-sub">Sent <?= htmlspecialchars((string) $sentAt, ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php else: ?>
                                                <div class="cell-sub cell-warn">Not sent</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="cell-muted">No score yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($inviteOpen): ?>
                                            <div class="invite-copy-row invite-copy-row--compact">
                                                <input
                                                    type="text"
                                                    id="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>"
                                                    class="invite-url"
                                                    readonly
                                                    value="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                >
                                                <button type="button" class="btn-secondary" data-copy-target="<?= htmlspecialchars($inputId, ENT_QUOTES, 'UTF-8') ?>">Copy</button>
                                            </div>
                                            <form method="post" action="/admin/" class="inline-form" style="margin-top:0.4rem;" onsubmit="return confirm('Revoke this invite link?');">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="revoke_invite">
                                                <input type="hidden" name="competitor_id" value="<?= (int) $row['id'] ?>">
                                                <button type="submit" class="btn-secondary">Revoke</button>
                                            </form>
                                        <?php elseif ($status === 'revoked' || $status === 'expired'): ?>
                                            <span class="cell-muted"><?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php else: ?>
                                            <span class="cell-muted">Used</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-section" aria-labelledby="scores-heading">
            <h2 id="scores-heading">Submitted scores</h2>
            <p class="page-lead">Scores grouped by competitor (one score each).</p>
            <?php if ($scores === []): ?>
                <p class="empty-note">No scores submitted yet.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Competitor</th>
                                <th scope="col">Event</th>
                                <th scope="col">Judge</th>
                                <th scope="col">Total</th>
                                <th scope="col">Email status</th>
                                <th scope="col">Scored at</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scores as $score): ?>
                                <?php
                                $vehicle = competitorVehicleLabel($score);
                                $sentAt = $score['scorecard_sent_at'] ?? null;
                                $cid = $score['competitor_id'] !== null ? (int) $score['competitor_id'] : 0;
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) $score['competitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="cell-sub"><?= htmlspecialchars($vehicle, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="cell-sub"><?= htmlspecialchars((string) $score['competitor_email'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </td>
                                    <td>
                                        <?= htmlspecialchars((string) $score['event_name'], ENT_QUOTES, 'UTF-8') ?>
                                        <div class="cell-sub"><?= htmlspecialchars((string) $score['event_date'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php if (!empty($score['placement'])): ?>
                                            <div class="cell-sub">Placement: <?= htmlspecialchars((string) $score['placement'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars((string) $score['judge_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <strong><?= (int) $score['grand_total'] ?></strong><span class="total-max"> / 230</span>
                                        <div class="cell-sub">
                                            Tonal <?= (int) $score['tonal_total'] ?>
                                            · Stage <?= (int) $score['stage_total'] ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($cid > 0): ?>
                                            <div class="admin-actions">
                                                <a class="btn-secondary" href="/admin/scorecard.php?competitor_id=<?= $cid ?>">Download PDF</a>
                                                <form method="post" action="/admin/" class="inline-form">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="action" value="send_scorecard">
                                                    <input type="hidden" name="competitor_id" value="<?= $cid ?>">
                                                    <button type="submit" class="btn-secondary">
                                                        <?= $sentAt ? 'Resend email' : 'Send email' ?>
                                                    </button>
                                                </form>
                                            </div>
                                            <?php if ($sentAt): ?>
                                                <div class="cell-sub">Sent <?= htmlspecialchars((string) $sentAt, ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php else: ?>
                                                <div class="cell-sub cell-warn">Not sent</div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="cell-muted">Legacy score (no competitor link)</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="cell-muted"><?= htmlspecialchars((string) ($score['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="admin-section" aria-labelledby="judges-heading">
            <h2 id="judges-heading">Judge accounts</h2>
            <p class="page-lead">Create judge logins (email + password). Admins are seeded separately.</p>

            <form method="post" action="/admin/" class="judge-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create_judge">
                <div class="field-grid">
                    <div class="field<?= isset($judgeErrors['name']) ? ' is-invalid' : '' ?>">
                        <label for="judge_name">Name</label>
                        <input type="text" id="judge_name" name="name" maxlength="255" required
                               value="<?= htmlspecialchars($judgeForm['name'], ENT_QUOTES, 'UTF-8') ?>">
                        <?php if (isset($judgeErrors['name'])): ?>
                            <p class="field-error"><?= htmlspecialchars($judgeErrors['name'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="field<?= isset($judgeErrors['email']) ? ' is-invalid' : '' ?>">
                        <label for="judge_email">Email</label>
                        <input type="email" id="judge_email" name="email" maxlength="255" required
                               value="<?= htmlspecialchars($judgeForm['email'], ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="off">
                        <?php if (isset($judgeErrors['email'])): ?>
                            <p class="field-error"><?= htmlspecialchars($judgeErrors['email'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="field<?= isset($judgeErrors['password']) ? ' is-invalid' : '' ?>">
                        <label for="judge_password">Temporary password</label>
                        <input type="text" id="judge_password" name="password" minlength="8" maxlength="72" required
                               autocomplete="new-password">
                        <?php if (isset($judgeErrors['password'])): ?>
                            <p class="field-error"><?= htmlspecialchars($judgeErrors['password'], ENT_QUOTES, 'UTF-8') ?></p>
                        <?php else: ?>
                            <p class="field-hint">At least 8 characters. Share with the judge securely.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <button type="submit" class="btn-primary judge-submit">Create judge</button>
            </form>

            <?php if ($staff !== []): ?>
                <div class="table-wrap" style="margin-top: 1.25rem;">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th scope="col">Role</th>
                                <th scope="col">Name</th>
                                <th scope="col">Email</th>
                                <th scope="col">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($staff as $staffUser): ?>
                                <tr>
                                    <td>
                                        <span class="status-pill status-<?= htmlspecialchars((string) $staffUser['role'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string) $staffUser['role'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td><?= htmlspecialchars((string) $staffUser['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars((string) $staffUser['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="cell-muted"><?= htmlspecialchars((string) ($staffUser['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <script>
        document.querySelectorAll('[data-copy-target]').forEach(function (btn) {
            btn.addEventListener('click', async function () {
                var id = btn.getAttribute('data-copy-target');
                var input = id ? document.getElementById(id) : null;
                if (!input) return;
                var text = input.value;
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        input.select();
                        document.execCommand('copy');
                    }
                    var prev = btn.textContent;
                    btn.textContent = 'Copied';
                    setTimeout(function () { btn.textContent = prev; }, 1500);
                } catch (e) {
                    input.select();
                }
            });
        });
    </script>
</body>
</html>
