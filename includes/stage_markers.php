<?php
/**
 * Sound-stage diagram SVGs and optional visual marker pins.
 * Markers are viewBox-relative coordinates only — never mapped to numeric scores.
 */

declare(strict_types=1);

/** Max pins a judge may place on each diagram. */
const STAGE_MARKER_MAX = 4;

/**
 * Diagram definitions keyed by form/API id.
 *
 * @return array<string, array{file:string,png:string,public:string,viewBox:array{0:float,1:float,2:float,3:float},label:string,field:string}>
 */
function stageDiagramDefs(): array
{
    $root = dirname(__DIR__);
    return [
        'width_height' => [
            'file'    => $root . '/assets/svg/Width_Height.svg',
            'png'     => $root . '/assets/svg/width-height.png',
            'public'  => '/assets/svg/width-height.svg',
            'viewBox' => [0.0, 0.0, 168.0, 94.0],
            'label'   => 'Width / Height',
            'field'   => 'stage_markers_wh',
        ],
        'depth' => [
            'file'    => $root . '/assets/svg/depth.svg',
            'png'     => $root . '/assets/svg/depth.png',
            'public'  => '/assets/svg/depth.svg',
            'viewBox' => [0.0, 0.0, 247.0, 92.0],
            'label'   => 'Depth',
            'field'   => 'stage_markers_depth',
        ],
    ];
}

/**
 * Distinct pin colors (index 0–3). Not tied to scoring categories.
 *
 * @return list<string>
 */
function stageMarkerColors(): array
{
    return ['#c0392b', '#2471a3', '#1e8449', '#b9770e'];
}

/**
 * Load raw SVG markup from disk (no XML declaration).
 */
function loadStageDiagramSvg(string $diagramId): string
{
    $defs = stageDiagramDefs();
    if (!isset($defs[$diagramId])) {
        return '';
    }
    $path = $defs[$diagramId]['file'];
    if (!is_readable($path)) {
        return '';
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return '';
    }
    $raw = preg_replace('/<\?xml[^?]*\?>\s*/i', '', $raw) ?? $raw;
    return trim($raw);
}

/**
 * Prepare SVG for embedding: unique ids, responsive sizing, markers layer.
 *
 * @param list<array{x:float,y:float}> $markers
 */
function prepareStageDiagramSvg(string $diagramId, array $markers = [], bool $interactive = false): string
{
    $svg = loadStageDiagramSvg($diagramId);
    if ($svg === '') {
        return '';
    }

    $defs = stageDiagramDefs()[$diagramId];
    [$vx, $vy, $vw, $vh] = $defs['viewBox'];
    $prefix = 'sd-' . preg_replace('/[^a-z0-9]+/i', '-', $diagramId) . '-';

    $svg = preg_replace('/\bid="([^"]+)"/', 'id="' . $prefix . '$1"', $svg) ?? $svg;
    $svg = preg_replace('/\baria-labelledby="([^"]+)"/', 'aria-labelledby="' . $prefix . 'title ' . $prefix . 'desc"', $svg) ?? $svg;

    // Drop fixed pixel width/height so CSS can size the diagram.
    $svg = preg_replace('/\s(width|height)="[^"]*"/', '', $svg, 2) ?? $svg;

    $class = 'stage-diagram-svg' . ($interactive ? ' is-interactive' : ' is-static');
    if (preg_match('/<svg\b([^>]*)>/', $svg, $m) === 1) {
        $attrs = $m[1];
        if (!preg_match('/\bclass="/', $attrs)) {
            $attrs .= ' class="' . $class . '"';
        } else {
            $attrs = preg_replace('/\bclass="([^"]*)"/', 'class="$1 ' . $class . '"', $attrs) ?? $attrs;
        }
        if (!preg_match('/\bpreserveAspectRatio="/', $attrs)) {
            $attrs .= ' preserveAspectRatio="xMidYMid meet"';
        }
        $svg = preg_replace('/<svg\b[^>]*>/', '<svg' . $attrs . '>', $svg, 1) ?? $svg;
    }

    $layerClass = $interactive ? 'stage-marker-layer' : 'stage-marker-layer is-static';
    $markersSvg = stageMarkersSvgGroup($markers, $vw, $vh, $layerClass);
    $svg = preg_replace('/<\/svg>\s*$/i', $markersSvg . '</svg>', $svg, 1) ?? $svg;

    return $svg;
}

/**
 * @param list<array{x:float,y:float}> $markers
 */
function stageMarkersSvgGroup(array $markers, float $vbW, float $vbH, string $layerClass = 'stage-marker-layer'): string
{
    $colors = stageMarkerColors();
    // Pin radius scales with viewBox so both diagrams look similar.
    $r = max(2.2, min($vbW, $vbH) * 0.035);
    $font = $r * 1.05;
    $parts = ['<g class="' . htmlspecialchars($layerClass, ENT_QUOTES, 'UTF-8') . '">'];

    foreach ($markers as $i => $m) {
        if ($i >= STAGE_MARKER_MAX) {
            break;
        }
        $x = (float) $m['x'];
        $y = (float) $m['y'];
        $color = $colors[$i] ?? '#333333';
        $n = (string) ($i + 1);
        $parts[] = sprintf(
            '<g class="stage-marker" data-index="%d" transform="translate(%.3f,%.3f)">'
            . '<circle class="stage-marker-pin" r="%.3f" fill="%s" stroke="#ffffff" stroke-width="%.3f"/>'
            . '<text class="stage-marker-label" text-anchor="middle" dominant-baseline="central" '
            . 'y="0.15" font-size="%.3f" font-family="Helvetica, Arial, sans-serif" font-weight="700" fill="#ffffff">%s</text>'
            . '</g>',
            $i,
            $x,
            $y,
            $r,
            htmlspecialchars($color, ENT_QUOTES, 'UTF-8'),
            max(0.6, $r * 0.28),
            $font,
            $n
        );
    }

    $parts[] = '</g>';
    return implode('', $parts);
}

/**
 * Parse optional marker JSON from a form field or DB value.
 * Empty / missing → [].
 * When $strict is true, malformed JSON / non-array payload → null (validation error).
 * Individual bad entries are always skipped; never invents coordinates.
 *
 * @return list<array{x:float,y:float}>|null
 */
function parseStageMarkers(mixed $raw, ?string $diagramId = null, bool $strict = false): ?array
{
    if ($raw === null || $raw === '') {
        return [];
    }

    if (is_array($raw)) {
        $decoded = $raw;
    } else {
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return $strict ? null : [];
        }
    }

    // Reject JSON objects masquerading as marker lists: {"x":1,"y":2}
    if ($decoded !== [] && array_keys($decoded) !== range(0, count($decoded) - 1)) {
        // Allow list-like arrays; reject associative objects
        $isList = array_is_list($decoded);
        if (!$isList) {
            return $strict ? null : [];
        }
    }

    $vb = null;
    if ($diagramId !== null) {
        $defs = stageDiagramDefs();
        if (isset($defs[$diagramId])) {
            $vb = $defs[$diagramId]['viewBox'];
        }
    }

    $out = [];
    foreach ($decoded as $item) {
        if (count($out) >= STAGE_MARKER_MAX) {
            break;
        }
        if (!is_array($item)) {
            continue;
        }
        if (!array_key_exists('x', $item) || !array_key_exists('y', $item)) {
            continue;
        }
        if ($item['x'] === null || $item['y'] === null || $item['x'] === '' || $item['y'] === '') {
            continue;
        }
        if (!is_numeric($item['x']) || !is_numeric($item['y'])) {
            continue;
        }
        $x = (float) $item['x'];
        $y = (float) $item['y'];
        if (!is_finite($x) || !is_finite($y)) {
            continue;
        }
        if ($vb !== null) {
            [$minX, $minY, $w, $h] = $vb;
            $x = max($minX, min($minX + $w, $x));
            $y = max($minY, min($minY + $h, $y));
        }
        $out[] = ['x' => round($x, 3), 'y' => round($y, 3)];
    }

    return $out;
}

/**
 * Encode markers for DB / hidden input.
 *
 * @param list<array{x:float,y:float}> $markers
 */
function encodeStageMarkers(array $markers): string
{
    return json_encode(array_values($markers), JSON_UNESCAPED_SLASHES) ?: '[]';
}

/**
 * Interactive diagrams for the scoring form (Sound Stage section).
 */
function renderStageDiagramsInteractive(): void
{
    foreach (stageDiagramDefs() as $id => $def) {
        $field = htmlspecialchars($def['field'], ENT_QUOTES, 'UTF-8');
        $label = htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8');
        $safeId = htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
        $public = htmlspecialchars($def['public'], ENT_QUOTES, 'UTF-8');
        [$vx, $vy, $vw, $vh] = $def['viewBox'];
        $svg = prepareStageDiagramSvg($id, [], true);
        if ($svg === '') {
            continue;
        }
        ?>
        <div
            class="stage-diagram"
            data-diagram="<?= $safeId ?>"
            data-field="<?= $field ?>"
            data-public-src="<?= $public ?>"
            data-vb-x="<?= htmlspecialchars((string) $vx, ENT_QUOTES, 'UTF-8') ?>"
            data-vb-y="<?= htmlspecialchars((string) $vy, ENT_QUOTES, 'UTF-8') ?>"
            data-vb-w="<?= htmlspecialchars((string) $vw, ENT_QUOTES, 'UTF-8') ?>"
            data-vb-h="<?= htmlspecialchars((string) $vh, ENT_QUOTES, 'UTF-8') ?>"
            data-max="<?= (int) STAGE_MARKER_MAX ?>"
        >
            <div class="stage-diagram-head">
                <h3 class="stage-diagram-title"><?= $label ?></h3>
                <button type="button" class="btn-text stage-diagram-clear" hidden>Clear pins</button>
            </div>
            <div class="stage-diagram-canvas" role="img" aria-label="<?= $label ?> soundstage diagram — tap to place up to <?= (int) STAGE_MARKER_MAX ?> pins">
                <?= $svg ?>
            </div>
            <input type="hidden" name="<?= $field ?>" id="<?= $field ?>" value="[]">
            <p class="field-hint">Tap to place a pin (up to <?= (int) STAGE_MARKER_MAX ?>). Drag to move. Pins are visual only — enter Width / Height / Depth / Ambience scores in the fields above.</p>
        </div>
        <?php
    }
}

/**
 * HTML fragment for PDF diag cells.
 * Dompdf cannot reliably render these complex path SVGs, so artwork is a PNG
 * raster of the same SVG with pins composited at viewBox-mapped pixel positions.
 *
 * @param list<array{x:float,y:float}> $markers
 */
function stageDiagramPdfHtml(string $diagramId, array $markers): string
{
    $defs = stageDiagramDefs();
    if (!isset($defs[$diagramId])) {
        return '&nbsp;';
    }
    $def = $defs[$diagramId];
    $dataUri = stageDiagramPdfDataUri($diagramId, $markers);
    if ($dataUri === null) {
        $svg = prepareStageDiagramSvg($diagramId, $markers, false);
        return $svg === '' ? '&nbsp;' : '<div class="diag-svg">' . $svg . '</div>';
    }
    $alt = htmlspecialchars($def['label'], ENT_QUOTES, 'UTF-8');
    $src = htmlspecialchars($dataUri, ENT_QUOTES, 'UTF-8');
    return '<div class="diag-frame"><img class="diag-art" src="' . $src . '" alt="' . $alt . '"></div>';
}

/**
 * Build a PNG data-URI of the diagram artwork with optional pins.
 *
 * @param list<array{x:float,y:float}> $markers
 */
function stageDiagramPdfDataUri(string $diagramId, array $markers): ?string
{
    $defs = stageDiagramDefs();
    if (!isset($defs[$diagramId])) {
        return null;
    }
    $def = $defs[$diagramId];
    $pngPath = $def['png'];
    if (!is_readable($pngPath) || !function_exists('imagecreatefrompng')) {
        return null;
    }

    $img = @imagecreatefrompng($pngPath);
    if ($img === false) {
        return null;
    }

    imagesavealpha($img, true);
    $pxW = imagesx($img);
    $pxH = imagesy($img);
    [$vx, $vy, $vw, $vh] = $def['viewBox'];
    $colors = stageMarkerColors();

    foreach ($markers as $i => $m) {
        if ($i >= STAGE_MARKER_MAX) {
            break;
        }
        $cx = (int) round((($m['x'] - $vx) / $vw) * $pxW);
        $cy = (int) round((($m['y'] - $vy) / $vh) * $pxH);
        $r = max(8, (int) round(min($pxW, $pxH) * 0.035));

        $hex = $colors[$i] ?? '#333333';
        $rr = hexdec(substr($hex, 1, 2));
        $gg = hexdec(substr($hex, 3, 2));
        $bb = hexdec(substr($hex, 5, 2));
        $fill = imagecolorallocate($img, $rr, $gg, $bb);
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $fill);
        imageellipse($img, $cx, $cy, $r * 2, $r * 2, $white);

        $label = (string) ($i + 1);
        $font = 5;
        $tw = imagefontwidth($font) * strlen($label);
        $th = imagefontheight($font);
        imagestring($img, $font, (int) ($cx - $tw / 2), (int) ($cy - $th / 2), $label, $white);
    }

    ob_start();
    imagepng($img);
    $binary = ob_get_clean();
    unset($img);
    if ($binary === false || $binary === '') {
        return null;
    }
    return 'data:image/png;base64,' . base64_encode($binary);
}
