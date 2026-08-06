<?php
/**
 * Judge scoring — competitor list, or form for one registered competitor.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';
require_once __DIR__ . '/../includes/competitors.php';
require_once __DIR__ . '/../includes/events.php';
require_once __DIR__ . '/../includes/pagination.php';

requireRole('judge');
$token = csrfToken();
$judge = currentUser();
if ($judge === null) {
    header('Location: /login.php');
    exit;
}

$events = listEvents();
$competitorId = filter_var($_GET['competitor_id'] ?? '', FILTER_VALIDATE_INT);
$competitor = null;
$pageMode = 'list';
$competitors = ['rows' => [], 'total' => 0];

if ($competitorId !== false && $competitorId > 0) {
    $competitor = findCompetitorById($competitorId);
    if ($competitor === null || !in_array(($competitor['status'] ?? ''), ['registered', 'scored'], true)) {
        http_response_code(404);
        $pageMode = 'missing';
    } elseif (!competitorIsScoreable($competitor)) {
        $pageMode = 'already_scored';
    } else {
        $pageMode = 'form';
    }
} else {
    $pager = paginationParams();
    $competitors = listJudgeCompetitors($pager['page'], $pager['per_page']);
}

/**
 * Render a large-tap stepper control.
 */
function renderStepper(string $name, string $label, int $min, int $max, int $value = 0): void
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="stepper-field" data-field="<?= $safeName ?>" data-min="<?= $min ?>" data-max="<?= $max ?>">
        <div class="stepper-label-row">
            <label for="<?= $safeName ?>"><?= $safeLabel ?></label>
            <span class="stepper-range"><?= $min ?>–<?= $max ?></span>
        </div>
        <div class="stepper">
            <button type="button" class="stepper-btn" data-dir="-1" aria-label="Decrease <?= $safeLabel ?>">−</button>
            <input
                type="text"
                inputmode="numeric"
                pattern="[0-9]*"
                id="<?= $safeName ?>"
                name="<?= $safeName ?>"
                class="stepper-value"
                value="<?= $value > 0 ? $value : '' ?>"
                data-min="<?= $min ?>"
                data-max="<?= $max ?>"
                autocomplete="off"
                required
            >
            <button type="button" class="stepper-btn" data-dir="1" aria-label="Increase <?= $safeLabel ?>">+</button>
        </div>
        <p class="field-error" hidden></p>
    </div>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $pageMode === 'form' ? 'Score competitor' : 'Competitors' ?> — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-score">
    <header class="app-header">
        <div class="brand">Florida Sound Quality</div>
        <nav>
            <a href="/score.php">Competitors</a>
            <a href="/scoreboard.php">Scoreboard</a>
            <a href="/logout.php">Log out</a>
        </nav>
    </header>

    <main class="score-main">
        <?php if ($pageMode === 'list'): ?>
            <h1 class="page-title">Competitors</h1>
            <p class="page-lead">
                Signed in as <?= htmlspecialchars($judge['name'], ENT_QUOTES, 'UTF-8') ?>.
                Select a registered competitor to score.
            </p>

            <?php if ((int) $competitors['total'] === 0): ?>
                <p class="empty-note">No registered competitors yet. An admin must send invite links first.</p>
            <?php else: ?>
                <?php renderPagination($competitors, '/score.php'); ?>
                <ul class="judge-competitor-list">
                    <?php foreach ($competitors['rows'] as $row): ?>
                        <?php
                        $status = (string) $row['status'];
                        $isRegistered = $status === 'registered' && empty($row['score_id']);
                        $href = $isRegistered
                            ? '/score.php?competitor_id=' . (int) $row['id']
                            : null;
                        ?>
                        <li class="judge-competitor-card<?= $isRegistered ? '' : ' is-scored' ?>">
                            <?php if ($href !== null): ?>
                                <a class="judge-competitor-link" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>">
                            <?php else: ?>
                                <div class="judge-competitor-link">
                            <?php endif; ?>
                                <div class="judge-competitor-main">
                                    <strong><?= htmlspecialchars((string) ($row['name'] ?? 'Competitor'), ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="cell-sub"><?= htmlspecialchars(competitorVehicleLabel($row), ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <div class="judge-competitor-meta">
                                    <span class="status-pill status-<?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars(competitorStatusLabel($status), ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($row['grand_total'])): ?>
                                        <span class="judge-total"><?= (int) $row['grand_total'] ?><span class="total-max"> / 230</span></span>
                                    <?php elseif ($isRegistered): ?>
                                        <span class="judge-action">Score →</span>
                                    <?php endif; ?>
                                </div>
                            <?php if ($href !== null): ?>
                                </a>
                            <?php else: ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php renderPagination($competitors, '/score.php'); ?>
            <?php endif; ?>

        <?php elseif ($pageMode === 'missing'): ?>
            <h1 class="page-title">Competitor not found</h1>
            <p class="page-lead">That competitor is not available for scoring.</p>
            <p><a href="/score.php">← Back to competitors</a></p>

        <?php elseif ($pageMode === 'already_scored'): ?>
            <h1 class="page-title">Already scored</h1>
            <p class="page-lead">
                <?= htmlspecialchars((string) ($competitor['name'] ?? 'This competitor'), ENT_QUOTES, 'UTF-8') ?>
                already has a score. One score per competitor.
            </p>
            <p><a href="/score.php">← Back to competitors</a></p>

        <?php else: ?>
            <?php
            $cName = (string) ($competitor['name'] ?? '');
            $cEmail = (string) ($competitor['email'] ?? '');
            $cYear = $competitor['vehicle_year'] !== null ? (string) $competitor['vehicle_year'] : '';
            $cMake = (string) ($competitor['vehicle_make'] ?? '');
            $cModel = (string) ($competitor['vehicle_model'] ?? '');
            $cColor = (string) ($competitor['vehicle_color'] ?? '');
            ?>
            <p class="back-link"><a href="/score.php">← Competitors</a></p>
            <h1 class="page-title">Score <?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="page-lead">
                <?= htmlspecialchars(competitorVehicleLabel($competitor), ENT_QUOTES, 'UTF-8') ?>.
                Tap − / + to set each score. Max grand total 230.
            </p>

            <div id="form-status" class="form-status" role="status" aria-live="polite" hidden></div>

            <form
                id="score-form"
                method="post"
                action="/submit.php"
                enctype="multipart/form-data"
                novalidate
                data-redirect-on-success="/score.php"
            >
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="submission_uuid" id="submission_uuid" value="">
                <input type="hidden" name="competitor_id" value="<?= (int) $competitor['id'] ?>">
                <input type="hidden" name="judge_name" value="<?= htmlspecialchars($judge['name'], ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="competitor_name" value="<?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="competitor_email" value="<?= htmlspecialchars($cEmail, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="vehicle_year" value="<?= htmlspecialchars($cYear, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="vehicle_make" value="<?= htmlspecialchars($cMake, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="vehicle_model" value="<?= htmlspecialchars($cModel, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="vehicle_color" value="<?= htmlspecialchars($cColor, ENT_QUOTES, 'UTF-8') ?>">

                <section class="score-section" aria-labelledby="sec-competitor">
                    <h2 id="sec-competitor">Competitor</h2>
                    <dl class="reg-summary">
                        <div>
                            <dt>Name</dt>
                            <dd><?= htmlspecialchars($cName, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Email</dt>
                            <dd><?= htmlspecialchars($cEmail, ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                        <div>
                            <dt>Vehicle</dt>
                            <dd><?= htmlspecialchars(competitorVehicleLabel($competitor), ENT_QUOTES, 'UTF-8') ?></dd>
                        </div>
                    </dl>
                </section>

                <section class="score-section" aria-labelledby="sec-event">
                    <h2 id="sec-event">Event</h2>
                    <?php if ($events === []): ?>
                        <p class="section-hint">No events in the catalog yet — enter event details below (admin can add reusable events later).</p>
                        <div class="field-grid">
                            <div class="field">
                                <label for="event_date">Event date</label>
                                <input type="date" id="event_date" name="event_date" required>
                                <p class="field-error" hidden></p>
                            </div>
                            <div class="field">
                                <label for="event_name">Event name</label>
                                <input type="text" id="event_name" name="event_name" maxlength="255" required>
                                <p class="field-error" hidden></p>
                            </div>
                            <div class="field">
                                <label for="placement">Placement (optional)</label>
                                <input type="text" id="placement" name="placement" maxlength="100" placeholder="e.g. 1st">
                                <p class="field-error" hidden></p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="field-grid">
                            <div class="field">
                                <label for="event_id">Event</label>
                                <select id="event_id" name="event_id" required>
                                    <option value="">Select event</option>
                                    <?php foreach ($events as $ev): ?>
                                        <option
                                            value="<?= (int) $ev['id'] ?>"
                                            data-date="<?= htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-name="<?= htmlspecialchars((string) $ev['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        >
                                            <?= htmlspecialchars((string) $ev['name'], ENT_QUOTES, 'UTF-8') ?>
                                            — <?= htmlspecialchars((string) $ev['event_date'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="field-error" hidden></p>
                            </div>
                            <div class="field">
                                <label for="placement">Placement (optional)</label>
                                <input type="text" id="placement" name="placement" maxlength="100" placeholder="e.g. 1st">
                                <p class="field-error" hidden></p>
                            </div>
                        </div>
                        <input type="hidden" id="event_date" name="event_date" value="">
                        <input type="hidden" id="event_name" name="event_name" value="">
                    <?php endif; ?>
                </section>

                <section class="score-section" aria-labelledby="sec-paper">
                    <h2 id="sec-paper">Paper sheet <span class="optional-tag">optional</span></h2>
                    <p class="section-hint">Photo or scan of the original paper scoring sheet, kept as a reference image.</p>
                    <div class="field field-upload">
                        <label for="paper_sheet">Reference image</label>
                        <input
                            type="file"
                            id="paper_sheet"
                            name="paper_sheet"
                            accept="image/jpeg,image/png,image/webp,image/heic,image/heif,.jpg,.jpeg,.png,.webp,.heic,.heif"
                            capture="environment"
                        >
                        <p class="field-hint">JPEG, PNG, WebP, or HEIC · max 12 MB</p>
                        <div id="paper-sheet-preview" class="upload-preview" hidden>
                            <img id="paper-sheet-preview-img" alt="Paper sheet preview">
                            <button type="button" id="paper-sheet-clear" class="btn-text">Remove</button>
                        </div>
                        <p class="field-error" hidden></p>
                    </div>
                </section>

                <section class="score-section" data-section="tonal" aria-labelledby="sec-tonal">
                    <div class="section-head">
                        <h2 id="sec-tonal">Tonal Accuracy</h2>
                        <p class="section-total">Subtotal <strong id="tonal-total">0</strong><span class="total-max"> / 100</span></p>
                    </div>
                    <?php
                    foreach (scoreFieldDefs() as $name => $def) {
                        if ($def['section'] === 'tonal') {
                            renderStepper($name, $def['label'], $def['min'], $def['max']);
                        }
                    }
                    ?>
                    <div class="field">
                        <label for="tonal_notes">Notes</label>
                        <textarea id="tonal_notes" name="tonal_notes" rows="2"></textarea>
                    </div>
                </section>

                <section class="score-section" data-section="stage" aria-labelledby="sec-stage">
                    <div class="section-head">
                        <h2 id="sec-stage">Sound Stage</h2>
                        <p class="section-total">Subtotal <strong id="stage-total">0</strong><span class="total-max"> / 65</span></p>
                    </div>
                    <?php
                    foreach (scoreFieldDefs() as $name => $def) {
                        if ($def['section'] === 'stage') {
                            renderStepper($name, $def['label'], $def['min'], $def['max']);
                        }
                    }
                    ?>
                    <div class="field">
                        <label for="stage_notes">Notes</label>
                        <textarea id="stage_notes" name="stage_notes" rows="2"></textarea>
                    </div>
                </section>

                <section class="score-section" data-section="imaging" aria-labelledby="sec-imaging">
                    <div class="section-head">
                        <h2 id="sec-imaging">Imaging</h2>
                        <p class="section-total">Score <strong id="imaging-total">0</strong><span class="total-max"> / 50</span></p>
                    </div>
                    <?php renderStepper('imaging_score', 'Imaging', 1, 50); ?>
                    <div class="field">
                        <label for="imaging_notes">Notes</label>
                        <textarea id="imaging_notes" name="imaging_notes" rows="2"></textarea>
                    </div>
                </section>

                <section class="score-section" data-section="noise" aria-labelledby="sec-noise">
                    <div class="section-head">
                        <h2 id="sec-noise">Noise &amp; Listening</h2>
                        <p class="section-total">Subtotal <strong id="noise-total">0</strong><span class="total-max"> / 15</span></p>
                    </div>
                    <?php
                    foreach (scoreFieldDefs() as $name => $def) {
                        if ($def['section'] === 'noise') {
                            renderStepper($name, $def['label'], $def['min'], $def['max']);
                        }
                    }
                    ?>
                    <div class="field">
                        <label for="noise_notes">Noise notes</label>
                        <textarea id="noise_notes" name="noise_notes" rows="2"></textarea>
                    </div>
                    <div class="field">
                        <label for="listening_notes">Listening notes</label>
                        <textarea id="listening_notes" name="listening_notes" rows="2"></textarea>
                    </div>
                </section>

                <div class="grand-total-bar">
                    <span class="grand-label">Grand total</span>
                    <strong id="grand-total">0</strong>
                    <span class="total-max"> / 230</span>
                </div>

                <button type="submit" id="submit-btn" class="btn-primary">Submit score</button>
            </form>
            <script src="/js/score-form.js" defer></script>
        <?php endif; ?>
    </main>
</body>
</html>
