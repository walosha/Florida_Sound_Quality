<?php
/**
 * Admin panel — sidebar navigation with overview + section pages.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/competitors.php';
require_once __DIR__ . '/../../includes/admin.php';
require_once __DIR__ . '/../../includes/events.php';
require_once __DIR__ . '/../../includes/pagination.php';

requireRole('admin');
$user = currentUser();
if ($user === null) {
    header('Location: /login.php');
    exit;
}

$sections = [
    'overview'     => 'Overview',
    'invites'      => 'Registration',
    'competitors'  => 'Competitors',
    'scores'       => 'Submitted scores',
    'events'       => 'Events',
    'judges'       => 'Judge accounts',
];

$section = (string) ($_GET['section'] ?? 'overview');
if (!isset($sections[$section])) {
    $section = 'overview';
}

$judgeForm = ['name' => '', 'email' => ''];
$judgeErrors = [];
$eventForm = ['name' => '', 'event_date' => ''];
$eventErrors = [];

/**
 * PRG redirect back into a section with a flash message.
 */
function adminRedirect(string $section, string $flash = '', string $flashError = ''): void
{
    $_SESSION['admin_flash'] = $flash;
    $_SESSION['admin_flash_error'] = $flashError;
    header('Location: /admin/?section=' . rawurlencode($section));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? null)) {
        adminRedirect($section, '', 'Invalid request. Please try again.');
    }

    $action = (string) ($_POST['action'] ?? '');
    $postSection = (string) ($_POST['section'] ?? $section);
    if (!isset($sections[$postSection])) {
        $postSection = 'overview';
    }

    if ($action === 'send_scorecard') {
        $competitorId = filter_var($_POST['competitor_id'] ?? null, FILTER_VALIDATE_INT);
        $back = in_array($postSection, ['competitors', 'scores'], true) ? $postSection : 'scores';
        if ($competitorId === false || $competitorId <= 0) {
            adminRedirect($back, '', 'Invalid competitor.');
        }
        $result = sendCompetitorScorecard($competitorId);
        unset($_SESSION['csrf_token']);
        if ($result['ok']) {
            $msg = 'Scorecard emailed successfully'
                . ($result['provider'] ? ' via ' . $result['provider'] : '')
                . '.';
            adminRedirect($back, $msg);
        }
        adminRedirect($back, '', $result['error'] ?? 'Could not send scorecard.');
    }

    if ($action === 'create_event') {
        $eventForm['name'] = trim((string) ($_POST['name'] ?? ''));
        $eventForm['event_date'] = trim((string) ($_POST['event_date'] ?? ''));
        $created = createEvent($eventForm['name'], $eventForm['event_date']);
        if ($created['ok']) {
            unset($_SESSION['csrf_token']);
            adminRedirect(
                'events',
                'Event created: ' . ($created['event']['name'] ?? $eventForm['name'])
            );
        }
        $eventErrors = $created['errors'];
        $section = 'events';
        $_SESSION['admin_flash_error'] = 'Fix the event form and try again.';
    } elseif ($action === 'delete_event') {
        $eventId = filter_var($_POST['event_id'] ?? null, FILTER_VALIDATE_INT);
        if ($eventId === false || $eventId <= 0) {
            adminRedirect('events', '', 'Invalid event.');
        }
        $deleted = deleteEvent($eventId);
        unset($_SESSION['csrf_token']);
        if ($deleted['ok']) {
            adminRedirect('events', 'Event deleted.');
        }
        adminRedirect('events', '', $deleted['error'] ?? 'Could not delete event.');
    } elseif ($action === 'create_judge') {
        $judgeForm['name'] = trim((string) ($_POST['name'] ?? ''));
        $judgeForm['email'] = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $created = createJudgeAccount($judgeForm['email'], $judgeForm['name'], $password);
        if ($created['ok']) {
            unset($_SESSION['csrf_token']);
            adminRedirect(
                'judges',
                'Judge account created for ' . ($created['user']['email'] ?? $judgeForm['email']) . '.'
            );
        }
        $judgeErrors = $created['errors'];
        $section = 'judges';
        $_SESSION['admin_flash_error'] = $judgeErrors['_form'] ?? 'Fix the judge form and try again.';
    } elseif (!in_array($action, ['create_event', 'create_judge'], true)) {
        adminRedirect($postSection, '', 'Unknown action.');
    }
}

$flash = (string) ($_SESSION['admin_flash'] ?? '');
$flashError = (string) ($_SESSION['admin_flash_error'] ?? '');
unset($_SESSION['admin_flash'], $_SESSION['admin_flash_error']);

$token = csrfToken();
$counts = adminDashboardCounts();
$registeredCount = $counts['registered'];
$scoredCount = $counts['scored'];
$pendingScorecards = $counts['pending_scorecards'];
$registrationUrl = competitorRegistrationUrl();

$pager = paginationParams();
$competitors = ['rows' => [], 'total' => 0];
$scores = ['rows' => [], 'total' => 0];
$staff = ['rows' => [], 'total' => 0];
$events = ['rows' => [], 'total' => 0];
$recentCompetitors = [];
$recentScores = [];

if ($section === 'overview') {
    $recentCompetitors = listAdminCompetitors(1, 10)['rows'];
    $recentCompetitors = array_slice($recentCompetitors, 0, 5);
    $recentScores = listSubmittedScores(1, 10)['rows'];
    $recentScores = array_slice($recentScores, 0, 5);
} elseif ($section === 'competitors') {
    $competitors = listAdminCompetitors($pager['page'], $pager['per_page']);
} elseif ($section === 'scores') {
    $scores = listSubmittedScores($pager['page'], $pager['per_page']);
} elseif ($section === 'events') {
    $events = listEventsPaginated($pager['page'], $pager['per_page']);
} elseif ($section === 'judges') {
    $staff = listStaffUsers($pager['page'], $pager['per_page']);
}

$pageTitle = $sections[$section] . ' — Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css?v=5">
</head>
<body class="page-admin">
    <div class="admin-shell">
        <aside class="admin-sidebar" id="admin-sidebar">
            <div class="admin-sidebar-brand">
                <p class="eyebrow">Florida Sound Quality</p>
                <strong>Admin</strong>
            </div>
            <nav class="admin-nav" aria-label="Admin">
                <?php foreach ($sections as $key => $label): ?>
                    <a
                        class="admin-nav-link<?= $section === $key ? ' is-active' : '' ?>"
                        href="/admin/?section=<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>"
                        <?= $section === $key ? 'aria-current="page"' : '' ?>
                    >
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($key === 'scores' && $pendingScorecards > 0): ?>
                            <span class="admin-nav-badge"><?= $pendingScorecards ?></span>
                        <?php elseif ($key === 'competitors' && $registeredCount > 0): ?>
                            <span class="admin-nav-badge"><?= $registeredCount ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="admin-sidebar-foot">
                <p class="admin-sidebar-user">
                    <?= htmlspecialchars($user['name'], ENT_QUOTES, 'UTF-8') ?>
                    <span><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8') ?></span>
                </p>
                <a class="admin-logout" href="/logout.php">Log out</a>
            </div>
        </aside>

        <div class="admin-content">
            <header class="admin-content-top">
                <button type="button" class="admin-menu-btn" id="admin-menu-btn" aria-controls="admin-sidebar" aria-expanded="false">
                    Menu
                </button>
                <div>
                    <p class="eyebrow">Admin</p>
                    <h1 class="page-title"><?= htmlspecialchars($sections[$section], ENT_QUOTES, 'UTF-8') ?></h1>
                </div>
            </header>

            <main class="admin-main">
                <?php if ($flash !== ''): ?>
                    <p class="flash flash-ok" role="status"><?= htmlspecialchars($flash, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <?php if ($flashError !== ''): ?>
                    <p class="flash flash-error" role="alert"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>

                <?php if ($section === 'overview'): ?>
                    <section class="admin-stats" aria-label="Summary">
                        <a class="admin-stat" href="/admin/?section=competitors">
                            <strong><?= $counts['competitors'] ?></strong>
                            <span>Competitors</span>
                        </a>
                        <a class="admin-stat" href="/admin/?section=competitors">
                            <strong><?= $registeredCount ?></strong>
                            <span>Awaiting score</span>
                        </a>
                        <a class="admin-stat" href="/admin/?section=scores">
                            <strong><?= $scoredCount ?></strong>
                            <span>Scored</span>
                        </a>
                        <a class="admin-stat" href="/admin/?section=scores">
                            <strong><?= $pendingScorecards ?></strong>
                            <span>Unsent scorecards</span>
                        </a>
                        <a class="admin-stat" href="/admin/?section=events">
                            <strong><?= $counts['events'] ?></strong>
                            <span>Events</span>
                        </a>
                        <a class="admin-stat" href="/admin/?section=judges">
                            <strong><?= $counts['staff'] ?></strong>
                            <span>Staff accounts</span>
                        </a>
                    </section>

                    <section class="admin-section">
                        <h2>Quick actions</h2>
                        <div class="admin-quick-actions">
                            <a class="btn-secondary" href="/admin/?section=invites">Registration link</a>
                            <a class="btn-secondary" href="/admin/?section=events">Manage events</a>
                            <a class="btn-secondary" href="/admin/?section=scores">Send scorecards</a>
                            <a class="btn-secondary" href="/admin/?section=judges">Add judge</a>
                            <a class="btn-secondary" href="/scoreboard.php" target="_blank" rel="noopener">Open scoreboard</a>
                        </div>
                    </section>

                    <div class="admin-overview-grid">
                        <section class="admin-section">
                            <div class="admin-section-head">
                                <h2>Recent competitors</h2>
                                <a href="/admin/?section=competitors">View all</a>
                            </div>
                            <?php if ($recentCompetitors === []): ?>
                                <p class="empty-note">No competitors yet. Share the <a href="/admin/?section=invites">registration link</a>.</p>
                            <?php else: ?>
                                <ul class="admin-mini-list">
                                    <?php foreach ($recentCompetitors as $row): ?>
                                        <?php
                                        $eff = competitorEffectiveStatus($row);
                                        $name = trim((string) ($row['name'] ?? ''));
                                        $scoreId = !empty($row['score_id']) ? (int) $row['score_id'] : 0;
                                        ?>
                                        <li>
                                            <button
                                                type="button"
                                                class="admin-mini-btn"
                                                data-open-detail
                                                <?php if ($scoreId > 0): ?>
                                                    data-score-id="<?= $scoreId ?>"
                                                <?php else: ?>
                                                    data-name="<?= htmlspecialchars($name !== '' ? $name : 'Unnamed', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-email="<?= htmlspecialchars((string) ($row['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-vehicle-year="<?= htmlspecialchars((string) ($row['vehicle_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-vehicle-make="<?= htmlspecialchars((string) ($row['vehicle_make'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-vehicle-model="<?= htmlspecialchars((string) ($row['vehicle_model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-vehicle-color="<?= htmlspecialchars((string) ($row['vehicle_color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-status="<?= htmlspecialchars($eff, ENT_QUOTES, 'UTF-8') ?>"
                                                    data-status-label="<?= htmlspecialchars(competitorStatusLabel($eff), ENT_QUOTES, 'UTF-8') ?>"
                                                    data-registered-at="<?= htmlspecialchars((string) ($row['registered_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                <?php endif; ?>
                                            >
                                                <span class="admin-mini-btn-main">
                                                    <strong><?= htmlspecialchars($name !== '' ? $name : 'Unnamed', ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <span class="cell-sub"><?= htmlspecialchars(competitorVehicleLabel($row), ENT_QUOTES, 'UTF-8') ?></span>
                                                </span>
                                                <span class="status-pill status-<?= htmlspecialchars($eff, ENT_QUOTES, 'UTF-8') ?>">
                                                    <?= htmlspecialchars(competitorStatusLabel($eff), ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>

                        <section class="admin-section">
                            <div class="admin-section-head">
                                <h2>Recent scores</h2>
                                <a href="/admin/?section=scores">View all</a>
                            </div>
                            <?php if ($recentScores === []): ?>
                                <p class="empty-note">No scores submitted yet.</p>
                            <?php else: ?>
                                <ul class="admin-mini-list">
                                    <?php foreach ($recentScores as $score): ?>
                                        <li>
                                            <button
                                                type="button"
                                                class="admin-mini-btn"
                                                data-open-detail
                                                data-score-id="<?= (int) $score['id'] ?>"
                                            >
                                                <span class="admin-mini-btn-main">
                                                    <strong><?= htmlspecialchars((string) $score['competitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <span class="cell-sub"><?= htmlspecialchars((string) $score['event_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </span>
                                                <strong class="admin-mini-total"><?= (int) $score['grand_total'] ?></strong>
                                            </button>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </section>
                    </div>

                <?php elseif ($section === 'invites'): ?>
                    <section class="admin-section">
                        <p class="page-lead">
                            One public registration link for all competitors.
                            Anyone with the link can register; no unique tokens or accounts.
                        </p>
                        <div class="invite-result">
                            <label for="registration-url">Registration link</label>
                            <div class="invite-copy-row">
                                <input type="text" id="registration-url" class="invite-url" readonly
                                       value="<?= htmlspecialchars($registrationUrl, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="btn-secondary" data-copy-target="registration-url">Copy</button>
                            </div>
                        </div>
                        <p class="empty-note" style="margin-top:1.25rem;">
                            Registered competitors appear under
                            <a href="/admin/?section=competitors">Competitors</a>
                            (<?= $registeredCount ?> awaiting score).
                        </p>
                    </section>

                <?php elseif ($section === 'competitors'): ?>
                    <section class="admin-section">
                        <p class="page-lead">Name, vehicle, status. Click a competitor to view full details. Download or email a PDF scorecard when a score is ready.</p>
                        <?php if ((int) $competitors['total'] === 0): ?>
                            <p class="empty-note">No competitors yet. Share the <a href="/admin/?section=invites">registration link</a>.</p>
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
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($competitors['rows'] as $row): ?>
                                            <?php
                                            $status = competitorEffectiveStatus($row);
                                            $vehicle = competitorVehicleLabel($row);
                                            $name = trim((string) ($row['name'] ?? ''));
                                            $email = trim((string) ($row['email'] ?? ''));
                                            $hasScore = !empty($row['score_id']);
                                            $sentAt = $row['scorecard_sent_at'] ?? null;
                                            ?>
                                            <tr>
                                                <td>
                                                    <span class="status-pill status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <button
                                                        type="button"
                                                        class="admin-name-btn"
                                                        data-open-detail
                                                        <?php if ($hasScore): ?>
                                                            data-score-id="<?= (int) $row['score_id'] ?>"
                                                        <?php else: ?>
                                                            data-name="<?= htmlspecialchars($name !== '' ? $name : 'Unnamed', ENT_QUOTES, 'UTF-8') ?>"
                                                            data-email="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-vehicle-year="<?= htmlspecialchars((string) ($row['vehicle_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-vehicle-make="<?= htmlspecialchars((string) ($row['vehicle_make'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-vehicle-model="<?= htmlspecialchars((string) ($row['vehicle_model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-vehicle-color="<?= htmlspecialchars((string) ($row['vehicle_color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                                            data-status-label="<?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>"
                                                            data-registered-at="<?= htmlspecialchars((string) ($row['registered_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                        <?php endif; ?>
                                                    >
                                                        <strong><?= htmlspecialchars($name !== '' ? $name : 'Unnamed', ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <span class="cell-sub"><?= htmlspecialchars($vehicle, ENT_QUOTES, 'UTF-8') ?></span>
                                                    </button>
                                                </td>
                                                <td><?= htmlspecialchars($email !== '' ? $email : '—', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <?php if ($hasScore): ?>
                                                        <button
                                                            type="button"
                                                            class="admin-name-btn"
                                                            data-open-detail
                                                            data-score-id="<?= (int) $row['score_id'] ?>"
                                                        >
                                                            <strong><?= (int) $row['grand_total'] ?></strong><span class="total-max"> / 230</span>
                                                            <span class="cell-sub"><?= htmlspecialchars((string) ($row['score_event_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="cell-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="admin-actions">
                                                        <button
                                                            type="button"
                                                            class="btn-secondary"
                                                            data-open-detail
                                                            <?php if ($hasScore): ?>
                                                                data-score-id="<?= (int) $row['score_id'] ?>"
                                                            <?php else: ?>
                                                                data-name="<?= htmlspecialchars($name !== '' ? $name : 'Unnamed', ENT_QUOTES, 'UTF-8') ?>"
                                                                data-email="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                                                                data-vehicle-year="<?= htmlspecialchars((string) ($row['vehicle_year'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-vehicle-make="<?= htmlspecialchars((string) ($row['vehicle_make'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-vehicle-model="<?= htmlspecialchars((string) ($row['vehicle_model'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-vehicle-color="<?= htmlspecialchars((string) ($row['vehicle_color'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-status="<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>"
                                                                data-status-label="<?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-registered-at="<?= htmlspecialchars((string) ($row['registered_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                            <?php endif; ?>
                                                        >View details</button>
                                                        <?php if ($hasScore): ?>
                                                            <a class="btn-secondary" href="/admin/scorecard.php?competitor_id=<?= (int) $row['id'] ?>">Download PDF</a>
                                                            <form method="post" action="/admin/?section=competitors" class="inline-form">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="section" value="competitors">
                                                                <input type="hidden" name="action" value="send_scorecard">
                                                                <input type="hidden" name="competitor_id" value="<?= (int) $row['id'] ?>">
                                                                <button type="submit" class="btn-secondary"><?= $sentAt ? 'Resend email' : 'Send email' ?></button>
                                                            </form>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($hasScore): ?>
                                                        <?php if ($sentAt): ?>
                                                            <div class="cell-sub">Sent <?= htmlspecialchars((string) $sentAt, ENT_QUOTES, 'UTF-8') ?></div>
                                                        <?php else: ?>
                                                            <div class="cell-sub cell-warn">Not sent</div>
                                                        <?php endif; ?>
                                                    <?php else: ?>
                                                        <div class="cell-sub cell-muted">No score yet</div>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php renderPagination($competitors, '/admin/', ['section' => 'competitors']); ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($section === 'scores'): ?>
                    <section class="admin-section">
                        <p class="page-lead">Scores grouped by competitor (one score each). Click a row to view the full breakdown. Send PDF scorecards when ready.</p>
                        <?php if ((int) $scores['total'] === 0): ?>
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
                                        <?php foreach ($scores['rows'] as $score): ?>
                                            <?php
                                            $vehicle = competitorVehicleLabel($score);
                                            $sentAt = $score['scorecard_sent_at'] ?? null;
                                            $cid = $score['competitor_id'] !== null ? (int) $score['competitor_id'] : 0;
                                            ?>
                                            <tr>
                                                <td>
                                                    <button
                                                        type="button"
                                                        class="admin-name-btn"
                                                        data-open-detail
                                                        data-score-id="<?= (int) $score['id'] ?>"
                                                    >
                                                        <strong><?= htmlspecialchars((string) $score['competitor_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                        <span class="cell-sub"><?= htmlspecialchars($vehicle, ENT_QUOTES, 'UTF-8') ?></span>
                                                        <span class="cell-sub"><?= htmlspecialchars((string) $score['competitor_email'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    </button>
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
                                                    <button
                                                        type="button"
                                                        class="admin-name-btn"
                                                        data-open-detail
                                                        data-score-id="<?= (int) $score['id'] ?>"
                                                    >
                                                        <strong><?= (int) $score['grand_total'] ?></strong><span class="total-max"> / 230</span>
                                                        <span class="cell-sub">Tonal <?= (int) $score['tonal_total'] ?> · Stage <?= (int) $score['stage_total'] ?></span>
                                                    </button>
                                                </td>
                                                <td>
                                                    <div class="admin-actions">
                                                        <button
                                                            type="button"
                                                            class="btn-secondary"
                                                            data-open-detail
                                                            data-score-id="<?= (int) $score['id'] ?>"
                                                        >View details</button>
                                                        <?php if ($cid > 0): ?>
                                                            <a class="btn-secondary" href="/admin/scorecard.php?competitor_id=<?= $cid ?>">Download PDF</a>
                                                            <form method="post" action="/admin/?section=scores" class="inline-form">
                                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                                <input type="hidden" name="section" value="scores">
                                                                <input type="hidden" name="action" value="send_scorecard">
                                                                <input type="hidden" name="competitor_id" value="<?= $cid ?>">
                                                                <button type="submit" class="btn-secondary"><?= $sentAt ? 'Resend email' : 'Send email' ?></button>
                                                            </form>
                                                        <?php else: ?>
                                                            <span class="cell-muted">Legacy score (no competitor link)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <?php if ($cid > 0): ?>
                                                        <?php if ($sentAt): ?>
                                                            <div class="cell-sub">Sent <?= htmlspecialchars((string) $sentAt, ENT_QUOTES, 'UTF-8') ?></div>
                                                        <?php else: ?>
                                                            <div class="cell-sub cell-warn">Not sent</div>
                                                        <?php endif; ?>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="cell-muted"><?= htmlspecialchars((string) ($score['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php renderPagination($scores, '/admin/', ['section' => 'scores']); ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($section === 'events'): ?>
                    <section class="admin-section">
                        <p class="page-lead">Reusable event catalog for judges. Scores still store event name/date for PDFs and the staff scoreboard.</p>
                        <form method="post" action="/admin/?section=events" class="judge-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="section" value="events">
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
                        <?php if ((int) $events['total'] === 0): ?>
                            <p class="empty-note">No events yet. Judges can still type event details until you add some.</p>
                        <?php else: ?>
                            <div class="table-wrap">
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
                                        <?php foreach ($events['rows'] as $ev): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars((string) $ev['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                                <td><?= htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="cell-muted"><?= htmlspecialchars((string) ($ev['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>
                                                    <form method="post" action="/admin/?section=events" class="inline-form" onsubmit="return confirm('Delete this event?');">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="section" value="events">
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
                            <?php renderPagination($events, '/admin/', ['section' => 'events']); ?>
                        <?php endif; ?>
                    </section>

                <?php elseif ($section === 'judges'): ?>
                    <section class="admin-section">
                        <p class="page-lead">Create judge logins (email + password). Admins are seeded separately.</p>
                        <form method="post" action="/admin/?section=judges" class="judge-form">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="section" value="judges">
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
                        <?php if ((int) $staff['total'] > 0): ?>
                            <div class="table-wrap">
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
                                        <?php foreach ($staff['rows'] as $staffUser): ?>
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
                            <?php renderPagination($staff, '/admin/', ['section' => 'judges']); ?>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <div class="admin-backdrop" id="admin-backdrop" hidden></div>

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

    <script src="/js/score-detail-panel.js?v=5" defer></script>
    <script src="/js/admin-detail.js?v=5" defer></script>
    <script>
        (function () {
            var btn = document.getElementById('admin-menu-btn');
            var sidebar = document.getElementById('admin-sidebar');
            var backdrop = document.getElementById('admin-backdrop');
            if (!btn || !sidebar) return;

            function setOpen(open) {
                document.body.classList.toggle('admin-nav-open', open);
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
                if (backdrop) backdrop.hidden = !open;
            }

            btn.addEventListener('click', function () {
                setOpen(!document.body.classList.contains('admin-nav-open'));
            });
            if (backdrop) {
                backdrop.addEventListener('click', function () { setOpen(false); });
            }

            document.querySelectorAll('[data-copy-target]').forEach(function (copyBtn) {
                copyBtn.addEventListener('click', async function () {
                    var id = copyBtn.getAttribute('data-copy-target');
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
                        var prev = copyBtn.textContent;
                        copyBtn.textContent = 'Copied';
                        setTimeout(function () { copyBtn.textContent = prev; }, 1500);
                    } catch (e) {
                        input.select();
                    }
                });
            });
        })();
    </script>
</body>
</html>
