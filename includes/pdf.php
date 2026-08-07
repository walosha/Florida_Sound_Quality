<?php
/**
 * Dompdf scorecard generator — matches the official paper score sheet layout.
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
    $cell = static fn (?string $v): string => $h($v !== null && trim((string) $v) !== '' ? (string) $v : '');

    $dateRaw = (string) ($score['event_date'] ?? '');
    $dateDisplay = $dateRaw;
    if ($dateRaw !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateRaw) === 1) {
        $ts = strtotime($dateRaw);
        if ($ts !== false) {
            $dateDisplay = date('n/j/Y', $ts);
        }
    }

    $imagingScore = isset($score['imaging_score']) ? (int) $score['imaging_score'] : null;
    $imagingBands = [
        ['Excellent', '41-50 pts', 41, 50],
        ['Good', '31-40 pts', 31, 40],
        ['Average', '21-30 pts', 21, 30],
        ['Below Average', '11-20 pts', 11, 20],
        ['Needs A Lot Of Work', '1-10 pts', 1, 10],
    ];

    $nl = static function (?string $text) use ($h): string {
        if ($text === null || trim($text) === '') {
            return '';
        }
        return nl2br($h($text));
    };

    $row = static function (string $item, string $pts, ?string $sc, string $aux = '') use ($h, $cell): string {
        return '<tr>'
            . '<td class="item">' . $h($item) . '</td>'
            . '<td class="pts">' . $h($pts) . '</td>'
            . '<td class="sc">' . $cell($sc) . '</td>'
            . '<td class="aux">' . $aux . '</td>'
            . '</tr>';
    };

    $tonalRows = [
        $row('Sub Bass', '1-20 pts', isset($score['sub_bass']) ? (string) $score['sub_bass'] : null),
        $row('Mid Bass', '1-20 pts', isset($score['mid_bass']) ? (string) $score['mid_bass'] : null),
        $row('Midrange', '1-20 pts', isset($score['midrange']) ? (string) $score['midrange'] : null),
        $row('High Frequencies', '1-20 pts', isset($score['high_freq']) ? (string) $score['high_freq'] : null),
        $row(
            'Spectral Balance',
            '1-20 pts',
            isset($score['spectral_balance']) ? (string) $score['spectral_balance'] : null,
            $h((isset($score['tonal_total']) ? (string) $score['tonal_total'] : '') . '/100')
        ),
    ];

    $stageRows = [
        $row('Listening Position To Stage', '1-15 pts', isset($score['listening_position']) ? (string) $score['listening_position'] : null),
        $row('Width', '1-15 pts', isset($score['width']) ? (string) $score['width'] : null),
        $row('Height', '1-15 pts', isset($score['height']) ? (string) $score['height'] : null),
        $row('Depth', '1-10 pts', isset($score['depth']) ? (string) $score['depth'] : null),
        $row(
            'Ambience',
            '1-10 pts',
            isset($score['ambience']) ? (string) $score['ambience'] : null,
            $h((isset($score['stage_total']) ? (string) $score['stage_total'] : '') . '/65')
        ),
    ];

    $imagingRows = [];
    foreach ($imagingBands as $idx => [$label, $pts, $min, $max]) {
        $bandScore = ($imagingScore !== null && $imagingScore >= $min && $imagingScore <= $max)
            ? (string) $imagingScore
            : null;
        $aux = ($idx === count($imagingBands) - 1)
            ? $h(($imagingScore !== null ? (string) $imagingScore : '') . '/50')
            : '';
        $imagingRows[] = $row($label, $pts, $bandScore, $aux);
    }

    $section = static function (
        string $title,
        array $bodyRows,
        string $notesLabel,
        string $notesHtml
    ): string {
        $span = 1 + count($bodyRows);
        $html = '<tr>'
            . '<td class="sec">' . $title . '</td>'
            . '<td class="pts">&nbsp;</td>'
            . '<td class="score-h">SCORE</td>'
            . '<td class="aux">&nbsp;</td>'
            . '<td class="notes" rowspan="' . $span . '">'
            . '<div class="notes-lab">' . $notesLabel . '</div>'
            . '<div class="notes-body">' . $notesHtml . '</div>'
            . '</td>'
            . '</tr>';
        foreach ($bodyRows as $r) {
            $html .= $r;
        }
        return $html;
    };

    $noiseScore = isset($score['noise']) ? (string) $score['noise'] : '';
    $lpScore = isset($score['listening_pleasure']) ? (string) $score['listening_pleasure'] : '';

    return '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
        @page { margin: 20px 24px; size: letter portrait; }
        body { margin:0; padding:0; font-family: Helvetica, Arial, sans-serif; font-size:8pt; color:#000; background:#fff; }
        table { width:100%; border-collapse:collapse; table-layout:fixed; }
        td { border:0.75pt solid #000; vertical-align:top; padding:0; background:#fff; color:#000; }
        .lab { font-size:6.5pt; text-transform:uppercase; padding:2px 4px 0; line-height:1.1; }
        .dat { font-size:9.5pt; padding:1px 4px 5px; line-height:1.15; min-height:11px; }
        .bar { font-size:8pt; font-weight:bold; text-transform:uppercase; letter-spacing:0.04em; padding:3px 5px; line-height:1.1; }
        .name-row .dat { padding-bottom:10px; }
        .diag-box { height:118px; line-height:118px; font-size:1px; overflow:hidden; }
        .notes-space-box { height:52px; line-height:52px; font-size:1px; overflow:hidden; }
        .sec { font-size:9.5pt; font-weight:bold; padding:3px 4px; vertical-align:middle; line-height:1.1; width:34%; }
        .score-h { font-size:6.5pt; font-weight:bold; text-align:center; vertical-align:middle; text-transform:uppercase; padding:2px 1px; width:7%; }
        .item { font-size:8pt; padding:2px 4px; vertical-align:middle; line-height:1.1; width:34%; }
        .pts { font-size:7.5pt; padding:2px 3px; vertical-align:middle; white-space:nowrap; line-height:1.1; width:14%; }
        .sc { font-size:9.5pt; font-weight:bold; text-align:center; vertical-align:middle; padding:2px 1px; width:7%; }
        .aux { font-size:8pt; font-weight:bold; text-align:right; vertical-align:middle; padding:2px 4px 2px 1px; width:7%; white-space:nowrap; }
        .notes { width:38%; }
        .notes-lab { font-size:7.5pt; padding:3px 5px 1px; line-height:1.1; }
        .notes-body { font-size:7.5pt; padding:0 5px 4px; line-height:1.25; }
        .w-date{width:18%;} .w-event{width:37%;} .w-judge{width:45%;}
        .w-year{width:18%;} .w-make{width:18%;} .w-model{width:28%;} .w-color{width:36%;}
        .foot-empty{width:10%;}
        .foot-total{width:22%; font-size:10pt; font-weight:bold; text-align:center; vertical-align:middle; padding:7px 4px;}
        .foot-score{width:14%; font-size:14pt; font-weight:bold; text-align:center; vertical-align:middle; padding:7px 4px;}
        .foot-max{width:26%; font-size:9pt; text-align:center; vertical-align:middle; padding:7px 4px;}
        .foot-place{width:28%;}
        .place-extra{height:16px;}
        .noise-title { font-size:9.5pt; font-weight:bold; padding:3px 4px; vertical-align:middle; }
      </style></head><body>

      <table>
        <tr>
          <td class="w-date"><div class="lab">Date</div><div class="dat">' . $cell($dateDisplay) . '</div></td>
          <td class="w-event"><div class="lab">Event</div><div class="dat">' . $cell((string) ($score['event_name'] ?? '')) . '</div></td>
          <td class="w-judge"><div class="lab">Judge</div><div class="dat">' . $cell((string) ($score['judge_name'] ?? '')) . '</div></td>
        </tr>
        <tr class="name-row">
          <td colspan="3"><div class="lab">Name</div><div class="dat">' . $cell((string) ($score['competitor_name'] ?? '')) . '</div></td>
        </tr>
        <tr>
          <td colspan="3" class="bar">Vehicle Information</td>
        </tr>
      </table>

      <table style="margin-top:-0.75pt;">
        <tr>
          <td class="w-year"><div class="lab">Year</div><div class="dat">' . $cell(isset($score['vehicle_year']) ? (string) $score['vehicle_year'] : '') . '</div></td>
          <td class="w-make"><div class="lab">Make</div><div class="dat">' . $cell((string) ($score['vehicle_make'] ?? '')) . '</div></td>
          <td class="w-model"><div class="lab">Model</div><div class="dat">' . $cell((string) ($score['vehicle_model'] ?? '')) . '</div></td>
          <td class="w-color"><div class="lab">Color</div><div class="dat">' . $cell((string) ($score['vehicle_color'] ?? '')) . '</div></td>
        </tr>
        <tr>
          <td colspan="2"><div class="lab">Width/Height</div><div class="dat" style="padding-bottom:2px;">&nbsp;</div></td>
          <td colspan="2"><div class="lab">Depth</div><div class="dat" style="padding-bottom:2px;">&nbsp;</div></td>
        </tr>
        <tr>
          <td colspan="2"><div class="diag-box">&nbsp;</div></td>
          <td colspan="2"><div class="diag-box">&nbsp;</div></td>
        </tr>
      </table>

      <table style="margin-top:-0.75pt;">
        ' . $section('Tonal Accuracy', $tonalRows, 'Tonal Accuracy Notes', $nl($score['tonal_notes'] ?? null)) . '
        ' . $section('Sound Stage', $stageRows, 'Sound Stage Notes', $nl($score['stage_notes'] ?? null)) . '
        ' . $section('Imaging', $imagingRows, 'Imaging Notes', $nl($score['imaging_notes'] ?? null)) . '

        <tr>
          <td class="noise-title">Noise and Listening</td>
          <td class="pts">&nbsp;</td>
          <td class="score-h">SCORE</td>
          <td class="aux">&nbsp;</td>
          <td class="notes">&nbsp;</td>
        </tr>
        <tr>
          <td class="item">Noise</td>
          <td class="pts">1-5 pts</td>
          <td class="sc">' . $cell($noiseScore !== '' ? $noiseScore : null) . '</td>
          <td class="aux">/5</td>
          <td class="notes">
            <div class="notes-lab">Noise Notes</div>
            <div class="notes-body">' . $nl($score['noise_notes'] ?? null) . '</div>
          </td>
        </tr>
        <tr>
          <td class="item">Listening Pleasure</td>
          <td class="pts">1-10 pts</td>
          <td class="sc">' . $cell($lpScore !== '' ? $lpScore : null) . '</td>
          <td class="aux">/10</td>
          <td class="notes">
            <div class="notes-lab">Listening Pleasure Notes</div>
            <div class="notes-body">' . $nl($score['listening_notes'] ?? null) . '</div>
          </td>
        </tr>

        <tr>
          <td colspan="5"><div class="notes-space-box">&nbsp;</div></td>
        </tr>
      </table>

      <table style="margin-top:-0.75pt;">
        <tr>
          <td class="foot-empty">&nbsp;</td>
          <td class="foot-total">TOTAL SCORE</td>
          <td class="foot-score">' . $cell(isset($score['grand_total']) ? (string) $score['grand_total'] : '') . '</td>
          <td class="foot-max">Max 230 Points</td>
          <td class="foot-place"><div class="lab">Placement</div><div class="dat" style="font-size:11pt;font-weight:bold;">' . $cell((string) ($score['placement'] ?? '')) . '</div></td>
        </tr>
        <tr>
          <td colspan="4" style="border:none;height:16px;"></td>
          <td class="place-extra">&nbsp;</td>
        </tr>
      </table>

    </body></html>';
}

/**
 * @param array<string, mixed> $score
 */
function generateScorecardPdf(array $score): string
{
    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml(buildScorecardHtml($score));
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}
