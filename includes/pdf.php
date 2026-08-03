<?php
/**
 * Dompdf scorecard generator.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * @param array<string, mixed> $score
 */
function buildScorecardHtml(array $score): string
{
    $h = static fn (?string $v): string => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');

    $vehicle = trim(implode(' ', array_filter([
        $score['vehicle_year'] ?? null,
        $score['vehicle_make'] ?? null,
        $score['vehicle_model'] ?? null,
        isset($score['vehicle_color']) && $score['vehicle_color'] !== ''
            ? '(' . $score['vehicle_color'] . ')'
            : null,
    ], static fn ($v) => $v !== null && $v !== '')));

    $row = static function (string $label, $value) use ($h): string {
        return '<tr><td class="label">' . $h($label) . '</td><td class="val">' . $h((string) $value) . '</td></tr>';
    };

    $notes = static function (string $label, ?string $text) use ($h): string {
        if ($text === null || $text === '') {
            return '';
        }
        return '<p class="notes"><strong>' . $h($label) . ':</strong> ' . $h($text) . '</p>';
    };

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        body { font-family: DejaVu Sans, sans-serif; color: #1a1a1a; font-size: 12px; }
        h1 { font-size: 20px; margin: 0 0 4px; color: #c45c26; }
        h2 { font-size: 14px; margin: 16px 0 6px; text-transform: uppercase; letter-spacing: 0.04em; }
        .meta { color: #555; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        td { padding: 4px 6px; vertical-align: top; }
        td.label { width: 55%; color: #555; }
        td.val { text-align: right; font-weight: bold; }
        .subtotal td, .grand td { font-size: 14px; background: #f3ebe3; }
        .grand td { font-size: 18px; color: #c45c26; }
        .notes { margin: 4px 0 0; color: #333; }
        .header-block { background: #1a1a1a; color: #f0e6d8; padding: 14px 16px; margin: -8px -8px 16px; }
        .header-block h1 { color: #e07030; }
      </style></head><body>
      <div class="header-block">
        <h1>Florida Sound Quality</h1>
        <div>Scorecard</div>
      </div>
      <div class="meta">
        <div><strong>Event:</strong> ' . $h((string) $score['event_name']) . ' — ' . $h((string) $score['event_date']) . '</div>
        <div><strong>Competitor:</strong> ' . $h((string) $score['competitor_name']) . '</div>
        <div><strong>Judge:</strong> ' . $h((string) $score['judge_name']) . '</div>
        <div><strong>Vehicle:</strong> ' . $h($vehicle !== '' ? $vehicle : '—') . '</div>
        ' . (!empty($score['placement']) ? '<div><strong>Placement:</strong> ' . $h((string) $score['placement']) . '</div>' : '') . '
      </div>

      <h2>Tonal Accuracy</h2>
      <table>
        ' . $row('Sub-Bass (1–20)', $score['sub_bass']) . '
        ' . $row('Mid-Bass (1–20)', $score['mid_bass']) . '
        ' . $row('Midrange (1–20)', $score['midrange']) . '
        ' . $row('High Frequency (1–20)', $score['high_freq']) . '
        ' . $row('Spectral Balance (1–20)', $score['spectral_balance']) . '
        <tr class="subtotal"><td class="label">Tonal subtotal</td><td class="val">' . $h((string) $score['tonal_total']) . ' / 100</td></tr>
      </table>
      ' . $notes('Notes', $score['tonal_notes'] ?? null) . '

      <h2>Sound Stage</h2>
      <table>
        ' . $row('Listening Position (1–15)', $score['listening_position']) . '
        ' . $row('Width (1–15)', $score['width']) . '
        ' . $row('Height (1–15)', $score['height']) . '
        ' . $row('Depth (1–10)', $score['depth']) . '
        ' . $row('Ambience (1–10)', $score['ambience']) . '
        <tr class="subtotal"><td class="label">Stage subtotal</td><td class="val">' . $h((string) $score['stage_total']) . ' / 65</td></tr>
      </table>
      ' . $notes('Notes', $score['stage_notes'] ?? null) . '

      <h2>Imaging</h2>
      <table>
        ' . $row('Imaging (1–50)', $score['imaging_score']) . '
      </table>
      ' . $notes('Notes', $score['imaging_notes'] ?? null) . '

      <h2>Noise &amp; Listening</h2>
      <table>
        ' . $row('Noise (1–5)', $score['noise']) . '
        ' . $row('Listening Pleasure (1–10)', $score['listening_pleasure']) . '
      </table>
      ' . $notes('Noise notes', $score['noise_notes'] ?? null) . '
      ' . $notes('Listening notes', $score['listening_notes'] ?? null) . '

      <table class="grand"><tr><td class="label">Grand total</td><td class="val">' . $h((string) $score['grand_total']) . ' / 230</td></tr></table>
    </body></html>';
}

/**
 * @param array<string, mixed> $score
 */
function generateScorecardPdf(array $score): string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(buildScorecardHtml($score));
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}
