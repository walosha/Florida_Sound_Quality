<?php
/**
 * Scoring form — protected. Live totals + steppers via score-form.js.
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/validation.php';

requireLogin();
$token = csrfToken();

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
    <title>Score — Florida Sound Quality</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;600;700&family=Barlow+Semi+Condensed:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body class="page-score">
    <header class="app-header">
        <div class="brand">Florida Sound Quality</div>
        <nav>
            <a href="/scoreboard.php">Scoreboard</a>
            <a href="/logout.php">Log out</a>
        </nav>
    </header>

    <main class="score-main">
        <h1 class="page-title">Scoring Sheet</h1>
        <p class="page-lead">Tap − / + to set each score. Totals update live. Max grand total 230.</p>

        <div id="form-status" class="form-status" role="status" aria-live="polite" hidden></div>

        <form id="score-form" method="post" action="/submit.php" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="submission_uuid" id="submission_uuid" value="">

            <section class="score-section" aria-labelledby="sec-event">
                <h2 id="sec-event">Event &amp; Competitor</h2>
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
                        <label for="judge_name">Judge name</label>
                        <input type="text" id="judge_name" name="judge_name" maxlength="255" required>
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="competitor_name">Competitor name</label>
                        <input type="text" id="competitor_name" name="competitor_name" maxlength="255" required>
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="competitor_email">Competitor email</label>
                        <input type="email" id="competitor_email" name="competitor_email" maxlength="255" required>
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="placement">Placement (optional)</label>
                        <input type="text" id="placement" name="placement" maxlength="100" placeholder="e.g. 1st">
                        <p class="field-error" hidden></p>
                    </div>
                </div>
            </section>

            <section class="score-section" aria-labelledby="sec-vehicle">
                <h2 id="sec-vehicle">Vehicle</h2>
                <div class="field-grid">
                    <div class="field">
                        <label for="vehicle_year">Year</label>
                        <input type="text" inputmode="numeric" id="vehicle_year" name="vehicle_year" maxlength="4">
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="vehicle_make">Make</label>
                        <input type="text" id="vehicle_make" name="vehicle_make" maxlength="100">
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="vehicle_model">Model</label>
                        <input type="text" id="vehicle_model" name="vehicle_model" maxlength="100">
                        <p class="field-error" hidden></p>
                    </div>
                    <div class="field">
                        <label for="vehicle_color">Color</label>
                        <input type="text" id="vehicle_color" name="vehicle_color" maxlength="50">
                        <p class="field-error" hidden></p>
                    </div>
                </div>
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
    </main>

    <script src="/js/score-form.js" defer></script>
</body>
</html>
