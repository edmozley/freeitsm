<?php
/**
 * Asset label designs (GH #229, slice 1) - label sizes, ready-made designs, fonts.
 *
 * Today's label was one fixed shape on four A4 sheets. A DESIGN is a list of items
 * placed in millimetres on one label, and a STOCK is how labels come off the
 * printer: a TAPE or roll (one label per page, the page exactly the label's size -
 * what a Brother or Dymo driver expects) or a SHEET (labels in rows and columns).
 * Printing makes a PDF at the stock's exact size, in the browser, with
 * assets/js/asset-labels/render.js. Design: wiki Asset-Label-Designer-Design.
 *
 * Slice 1 has no visual designer yet: a design is made from a TEMPLATE, whose
 * items are worked out here from the label's size and the company's label settings
 * (fields, header, footer, logo) every time it is printed - so changing those
 * settings changes the label, as it always has. Slice 2's designer saves its own
 * items instead (`template` = 'custom').
 *
 * 🔑 TEXT IS DRAWN BY THE BROWSER. Every text item is drawn on a canvas - which
 * shapes Arabic, Indic and every other script, and uses any uploaded font - and put
 * into the PDF as an image at 600 dpi. The QR, lines and boxes stay vector. So every
 * character prints, and what prints is what the screen showed.
 */

require_once __DIR__ . '/asset_labels.php';

/** The templates a design can be made from (slice 1). */
const ASSET_LABEL_TEMPLATES = ['standard', 'tape_logo_left', 'tape_small', 'sheet_large'];

/** Dots per inch text is drawn at for the PDF - twice what most label printers print. */
const ASSET_LABEL_TEXT_DPI = 600;

/** The fonts a label can use out of the box: the bundled Noto Sans. */
const ASSET_LABEL_BUILTIN_FONT = 'noto';

/** Have Database Verification's design and font tables been made yet? */
function assetLabelDesignsReady(PDO $conn): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $conn->query("SELECT id FROM asset_label_designs LIMIT 0"); $conn->query("SELECT id FROM asset_label_fonts LIMIT 0"); return $ok = true; }
    catch (Throwable) { return $ok = false; }
}

/**
 * Every label size offered, keyed. Product names are shown as they are sold, not
 * translated. Sheets use the stock's published layout (Avery's figures for the four
 * A4 sheets FreeITSM has always offered), so labels land on the labels.
 *
 * @return array<string, array> key => {kind: tape|sheet, name, w, h, ...sheet layout}
 */
function assetLabelStocks(): array
{
    $tape = fn(string $name, float $w, float $h) => ['kind' => 'tape', 'name' => $name, 'w' => $w, 'h' => $h];
    $sheet = fn(string $name, float $w, float $h, int $cols, int $rows, float $left, float $top, float $hp, float $vp, float $qr)
        => ['kind' => 'sheet', 'name' => $name, 'w' => $w, 'h' => $h, 'page_w' => 210, 'page_h' => 297,
            'cols' => $cols, 'rows' => $rows, 'left' => $left, 'top' => $top, 'h_pitch' => $hp, 'v_pitch' => $vp, 'qr' => $qr];
    return [
        // Today's four, under their old keys so a link or bookmark with ?sheet=65 still works.
        '65' => $sheet('A4 sheet, 65 labels (38.1 x 21.2 mm, Avery L7651)', 38.1, 21.2, 5, 13, 4.7, 10.7, 40.6, 21.2, 16),
        '40' => $sheet('A4 sheet, 40 labels (45.7 x 25.4 mm, Avery L7654)', 45.7, 25.4, 4, 10, 9.7, 21.5, 48.3, 25.4, 19),
        '24' => $sheet('A4 sheet, 24 labels (63.5 x 33.9 mm, Avery L7159)', 63.5, 33.9, 3, 8, 7.2, 12.9, 66.0, 33.9, 25),
        '12' => $sheet('A4 sheet, 12 labels (63.5 x 72 mm, Avery L7164)', 63.5, 72.0, 3, 4, 7.2, 4.5, 66.0, 72.0, 38),
        'tze12'  => $tape('Brother TZe tape 12 mm (50 mm long)', 50, 12),
        'tze18'  => $tape('Brother TZe tape 18 mm (60 mm long)', 60, 18),
        'tze24'  => $tape('Brother TZe tape 24 mm (73 mm long)', 73, 24),
        'tze36'  => $tape('Brother TZe tape 36 mm (90 mm long)', 90, 36),
        'dk62x29' => $tape('Brother DK 62 x 29 mm', 62, 29),
        'dk29x90' => $tape('Brother DK 90 x 29 mm', 90, 29),
        'dymo89x36' => $tape('Dymo 89 x 36 mm (99012)', 89, 36),
        'dymo54x25' => $tape('Dymo 54 x 25 mm (11352)', 54, 25),
    ];
}

/**
 * A stock from what was saved or asked for: a preset key, or 'custom' with a size.
 * Anything out of range is refused rather than drawn wrong.
 *
 * @param array $in {key, w?, h?, offset_x?, offset_y?}
 * @return array the stock, with 'key' and the printer offsets (sheets only)
 * @throws InvalidArgumentException when the size or key is not usable
 */
function assetLabelStockFrom(array $in): array
{
    $key = (string)($in['key'] ?? '65');
    $stocks = assetLabelStocks();
    if ($key === 'custom') {
        $w = round((float)($in['w'] ?? 0), 1);
        $h = round((float)($in['h'] ?? 0), 1);
        if ($w < 10 || $h < 6 || $w > 300 || $h > 300) throw new InvalidArgumentException('A label must be between 10 x 6 mm and 300 x 300 mm.');
        $stock = ['kind' => 'tape', 'name' => '', 'w' => $w, 'h' => $h];
    } elseif (isset($stocks[$key])) {
        $stock = $stocks[$key];
    } else {
        throw new InvalidArgumentException('Unknown label size.');
    }
    $stock['key'] = $key;
    $stock['offset_x'] = (float)max(-10, min(10, round((float)($in['offset_x'] ?? 0), 1)));
    $stock['offset_y'] = (float)max(-10, min(10, round((float)($in['offset_y'] ?? 0), 1)));
    return $stock;
}

/**
 * The items a template draws on a label of this size, from the company's label
 * settings. Everything in millimetres; font sizes in points.
 *
 * Item types: text {text}, field {field, caption}, fields {fields[], caption, tag_first},
 * qr {logo}, image {src: 'logo'}, line, box. Text-like items carry size, min (the
 * smallest a shrinking value may go), bold, align (left|center|right), valign
 * (top|middle|bottom) and fit (shrink|wrap|clip).
 *
 * @param string $template  one of ASSET_LABEL_TEMPLATES
 * @param array  $stock     assetLabelStockFrom()
 * @param array  $s         assetLabelSettings(): title, fields, footer, logo_path, show_field_labels
 */
function assetLabelTemplateItems(string $template, array $stock, array $s): array
{
    $w = (float)$stock['w'];
    $h = (float)$stock['h'];
    $pt = fn(float $mm) => $mm / 0.3528;                 // a height in mm as a font size in points
    $logo = (string)($s['logo_path'] ?? '') !== '';
    $fields = $s['fields'] ?? ['asset_tag'];
    $caption = !empty($s['show_field_labels']);
    $title = (string)($s['title'] ?? '');
    $footer = (string)($s['footer'] ?? '');
    $items = [];
    $add = function (array $it) use (&$items) { $it['id'] = 'i' . (count($items) + 1); $items[] = $it + ['rot' => 0]; };
    $text = ['bold' => false, 'italic' => false, 'align' => 'left', 'valign' => 'middle', 'fit' => 'shrink', 'color' => '#000000'];

    if ($template === 'tape_logo_left') {
        // The issue's layout: logo on the left, title and fields in the middle, QR on the right.
        $pad = min(1.5, $h * 0.08);
        // Sized by the height, but never more than a third of the width - on a tall
        // label the height alone would make the QR wider than the label.
        $qr = min($h - 2 * $pad, $w * 0.34);
        $logoW = $logo ? min($w * 0.3, $qr * 1.6) : 0;
        if ($logo) $add(['type' => 'image', 'src' => 'logo', 'x' => $pad, 'y' => $pad, 'w' => $logoW, 'h' => $qr]);
        $add(['type' => 'qr', 'logo' => false, 'x' => $w - $pad - $qr, 'y' => $pad, 'w' => $qr, 'h' => $qr]);
        $x0 = $pad + ($logo ? $logoW + 1.5 : 0);
        $mw = $w - $x0 - $qr - 2 * $pad;
        $colH = $h - 2 * $pad;                    // the middle column uses the label's full height
        $y = $pad;
        if ($title !== '') {
            $th = min($colH * 0.22, 6);
            $add(['type' => 'text', 'text' => $title, 'x' => $x0, 'y' => $y, 'w' => $mw, 'h' => $th, 'size' => $pt($th * 0.8), 'min' => 4] + ['bold' => true] + $text);
            $y += $th;
        }
        $add(['type' => 'fields', 'fields' => $fields, 'caption' => $caption ? 'above' : 'none', 'x' => $x0, 'y' => $y, 'w' => $mw, 'h' => $pad + $colH - $y,
              'size' => 8, 'tag_size' => 9, 'min' => 4, 'valign' => 'top'] + $text);
        return $items;
    }
    if ($template === 'tape_small') {
        // A small label: QR and the asset tag, nothing else.
        $pad = min(1, $h * 0.08);
        $qr = min($h - 2 * $pad, $w * 0.45);
        $add(['type' => 'qr', 'logo' => $logo, 'x' => $pad, 'y' => $pad, 'w' => $qr, 'h' => $qr]);
        $add(['type' => 'field', 'field' => 'asset_tag', 'caption' => false, 'x' => 2 * $pad + $qr, 'y' => $pad, 'w' => $w - 3 * $pad - $qr, 'h' => $qr,
              'size' => $pt($qr * 0.45), 'min' => 5] + ['bold' => true] + $text);
        return $items;
    }
    if ($template === 'sheet_large') {
        // A tall label: logo across the top, the QR, then the fields under it.
        // Every height in proportion to the label's, so it still fits on a small one.
        $pad = min(2, $h * 0.05);
        $titleH = $title !== '' ? min(4, $h * 0.08) : 0;
        $footH = $footer !== '' ? min(3.5, $h * 0.07) : 0;
        $y = $pad;
        if ($logo) { $add(['type' => 'image', 'src' => 'logo', 'x' => $pad, 'y' => $y, 'w' => $w - 2 * $pad, 'h' => $h * 0.18]); $y += $h * 0.18 + $pad / 2; }
        if ($title !== '') { $add(['type' => 'text', 'text' => $title, 'x' => $pad, 'y' => $y, 'w' => $w - 2 * $pad, 'h' => $titleH, 'size' => $pt($titleH * 0.75), 'min' => 4, 'bold' => true, 'align' => 'center'] + $text); $y += $titleH; }
        // The QR takes at most half of what is left, so the fields always have the rest.
        $qr = min($w - 2 * $pad, ($h - $y - $pad - $footH) * 0.5);
        $add(['type' => 'qr', 'logo' => false, 'x' => ($w - $qr) / 2, 'y' => $y, 'w' => $qr, 'h' => $qr]);
        $y += $qr + $pad / 2;
        $add(['type' => 'fields', 'fields' => $fields, 'caption' => $caption ? 'inline' : 'none', 'x' => $pad, 'y' => $y, 'w' => $w - 2 * $pad,
              'h' => $h - $y - $pad - $footH, 'size' => 8, 'tag_size' => 11, 'min' => 4, 'align' => 'center', 'valign' => 'top'] + $text);
        if ($footer !== '') $add(['type' => 'text', 'text' => $footer, 'x' => $pad, 'y' => $h - $pad - $footH, 'w' => $w - 2 * $pad, 'h' => $footH, 'size' => $pt($footH * 0.7), 'min' => 4, 'align' => 'center', 'color' => '#555555'] + $text);
        return $items;
    }

    // 'standard': today's label, as labels.php drew it - an optional header, the QR on
    // the left with the tag and fields beside it (or under it, on a tall label), an
    // optional footer. The same sizes it computed, from the label's height and QR size.
    $qr = (float)($stock['qr'] ?? min($h * 0.75, $w * 0.45));
    $padX = 1.5; $padY = 1.2;
    $headPt = max(4.8, min(7.5, $h / 4.2));
    $footPt = max(4.5, min(7.0, $h / 4.8));
    $headH = $title !== '' ? $headPt * 0.3528 * 1.25 : 0;
    $footH = $footer !== '' ? $footPt * 0.3528 * 1.25 : 0;
    $tagPt = max(6.2, min(13, $qr / 2.5));
    $subPt = max(4.8, min(8.5, $qr / (3.2 + count($fields) * 0.35)));
    $bodyY = $padY + $headH;
    $bodyH = $h - 2 * $padY - $headH - $footH;
    $qr = min($qr, $bodyH, $w - 2 * $padX);
    if ($h > $w) {
        $add(['type' => 'qr', 'logo' => $logo, 'x' => ($w - $qr) / 2, 'y' => $bodyY, 'w' => $qr, 'h' => $qr]);
        $add(['type' => 'fields', 'fields' => $fields, 'caption' => $caption ? 'inline' : 'none', 'x' => $padX, 'y' => $bodyY + $qr + 1.2, 'w' => $w - 2 * $padX,
              'h' => $bodyH - $qr - 1.2, 'size' => $subPt, 'tag_size' => $tagPt, 'min' => 4, 'align' => 'center', 'valign' => 'top'] + $text);
        $tx = $padX; $tw = $w - 2 * $padX; $talign = 'center';
    } else {
        $add(['type' => 'qr', 'logo' => $logo, 'x' => $padX, 'y' => $bodyY + ($bodyH - $qr) / 2, 'w' => $qr, 'h' => $qr]);
        $tx = $padX + $qr + 1.5; $tw = $w - $tx - $padX; $talign = 'left';
        $add(['type' => 'fields', 'fields' => $fields, 'caption' => $caption ? 'inline' : 'none', 'x' => $tx, 'y' => $bodyY, 'w' => $tw, 'h' => $bodyH,
              'size' => $subPt, 'tag_size' => $tagPt, 'min' => 4] + $text);
    }
    if ($title !== '') $add(['type' => 'text', 'text' => mb_strtoupper($title), 'x' => $tx, 'y' => $padY, 'w' => $tw, 'h' => $headH, 'size' => $headPt, 'min' => 4,
                             'bold' => true, 'align' => $talign, 'fit' => 'clip', 'color' => '#444444'] + $text);
    if ($footer !== '') $add(['type' => 'text', 'text' => $footer, 'x' => $tx, 'y' => $h - $padY - $footH, 'w' => $tw, 'h' => $footH, 'size' => $footPt, 'min' => 4,
                              'align' => $talign, 'fit' => 'clip', 'color' => '#555555'] + $text);
    return $items;
}

/**
 * Does this design print in solid black only (GH #229)? A tape or roll printer has no
 * grey - its driver prints grey as dots - so text, lines and boxes go solid black and
 * the logo pure black and white. Unset (every design made before 4.9.0) follows the
 * label size it is printed on: tape and roll yes, A4 sheets no. Mirrors monoFor() in
 * render.js.
 *
 * @param array $design  a design row from assetLabelDesignDecode()
 * @param array $stock   the stock it is printed on
 */
function assetLabelMono(array $design, array $stock): bool
{
    $m = $design['design']['mono'] ?? null;
    return is_bool($m) ? $m : ($stock['kind'] ?? 'sheet') !== 'sheet';
}

/**
 * A design's items for a label of this size: what the designer saved (template
 * 'custom', slice 2), or worked out from the template and the label settings.
 *
 * @param array $design  a design row from assetLabelDesignDecode()
 * @param array $stock   the stock to draw on - a custom design is always drawn on its own
 * @param array $s       assetLabelSettings()
 */
function assetLabelItemsFor(array $design, array $stock, array $s): array
{
    $d = $design['design'] ?? [];
    if (($d['template'] ?? '') === 'custom') return is_array($d['items'] ?? null) ? $d['items'] : [];
    return assetLabelTemplateItems((string)($d['template'] ?? 'standard'), $stock, $s);
}

/**
 * Cleans the items the designer sends, before they are saved: only the known item
 * types and their known options, numbers held to sensible ranges, colours as
 * #rrggbb, fields from the company's own list. Anything else is dropped, so a saved
 * design can only ever hold what the drawing code understands.
 *
 * @param array    $items      what the browser sent
 * @param array    $stock      the design's label size, to hold positions near it
 * @param string[] $fieldKeys  the fields a label may show (assetLabelAvailableFields() keys)
 * @return array the clean items, at most 100
 */
function assetLabelSanitiseItems(array $items, array $stock, array $fieldKeys): array
{
    $W = (float)$stock['w']; $H = (float)$stock['h'];
    $num = fn($v, float $lo, float $hi, float $def) => is_numeric($v) ? round(max($lo, min($hi, (float)$v)), 2) : $def;
    $pick = fn($v, array $ok, $def) => in_array($v, $ok, true) ? $v : $def;
    $colour = fn($v) => is_string($v) && preg_match('/^#[0-9a-f]{6}$/i', $v) ? strtolower($v) : '#000000';
    $out = [];
    $seen = [];
    foreach (array_slice(array_values($items), 0, 100) as $i => $it) {
        if (!is_array($it)) continue;
        $type = (string)($it['type'] ?? '');
        if (!in_array($type, ['text', 'field', 'fields', 'qr', 'image', 'line', 'box'], true)) continue;
        $id = is_string($it['id'] ?? null) && preg_match('/^[a-z0-9]{1,16}$/i', $it['id']) && !isset($seen[$it['id']]) ? $it['id'] : 'i' . ($i + 1) . bin2hex(random_bytes(2));
        $seen[$id] = true;
        $c = ['id' => $id, 'type' => $type,
              'x' => $num($it['x'] ?? 0, -$W, 2 * $W, 0), 'y' => $num($it['y'] ?? 0, -$H, 2 * $H, 0),
              // A line runs from (x, y) to (x + w, y + h): either can be 0 (upright or level).
              'w' => $num($it['w'] ?? 10, $type === 'line' ? 0 : 0.5, 3 * $W, 10), 'h' => $num($it['h'] ?? 5, $type === 'line' ? 0 : 0.5, 3 * $H, 5),
              'rot' => $pick((int)($it['rot'] ?? 0), [0, 90, 180, 270], 0)];
        if (in_array($type, ['text', 'field', 'fields'], true)) {
            $size = $num($it['size'] ?? 8, 2, 96, 8);
            $c += ['size' => $size, 'min' => $num($it['min'] ?? 4, 2, $size, min(4, $size)),
                   'bold' => !empty($it['bold']), 'italic' => !empty($it['italic']),
                   'align' => $pick($it['align'] ?? 'left', ['left', 'center', 'right'], 'left'),
                   'valign' => $pick($it['valign'] ?? 'middle', ['top', 'middle', 'bottom'], 'middle'),
                   'fit' => $pick($it['fit'] ?? 'shrink', ['shrink', 'wrap', 'clip'], 'shrink'),
                   'color' => $colour($it['color'] ?? '#000000')];
        }
        $caption = fn($v) => $v === true ? 'inline' : $pick($v, ['none', 'inline', 'above'], 'none');
        if ($type === 'text') $c['text'] = mb_substr((string)($it['text'] ?? ''), 0, 500);
        if ($type === 'field') {
            $c['field'] = in_array($it['field'] ?? '', $fieldKeys, true) ? $it['field'] : 'asset_tag';
            $c['caption'] = $caption($it['caption'] ?? 'none');
        }
        if ($type === 'fields') {
            $c['fields'] = array_values(array_unique(array_filter((array)($it['fields'] ?? []), fn($f) => in_array($f, $fieldKeys, true))));
            if (!$c['fields']) $c['fields'] = ['asset_tag'];
            $c['caption'] = $caption($it['caption'] ?? 'none');
            $c['tag_size'] = $num($it['tag_size'] ?? $c['size'], 2, 96, $c['size']);
        }
        if ($type === 'qr') $c['logo'] = !empty($it['logo']);
        if ($type === 'image') $c['src'] = 'logo';
        if ($type === 'line' || $type === 'box') { $c['thick'] = $num($it['thick'] ?? 0.3, 0.1, 5, 0.3); $c['color'] = $colour($it['color'] ?? '#000000'); }
        if ($type === 'box') $c['fill'] = !empty($it['fill']);
        $out[] = $c;
    }
    return $out;
}

/**
 * The designs a company can print with: its own, and any made for every company.
 * The first time a company has none, its "Standard" design is made - today's label,
 * from its current settings - and becomes its default, so nothing changes on upgrade.
 *
 * @param int $tenantId  the company (getActiveTenantId())
 * @return array<int, array> rows with stock and design decoded
 */
function assetLabelDesignsFor(PDO $conn, int $tenantId, ?int $analystId = null): array
{
    $load = function () use ($conn, $tenantId) {
        $s = $conn->prepare("SELECT * FROM asset_label_designs WHERE tenant_id = ? OR tenant_id IS NULL ORDER BY is_default DESC, name");
        $s->execute([$tenantId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    };
    $rows = $load();
    if (!$rows) {
        assetLabelDesignCreate($conn, $tenantId, t('asset-management.label_designs.standard_name'), 'standard', ['key' => '65'], $analystId, true);
        $rows = $load();
    }
    return array_map('assetLabelDesignDecode', $rows);
}

/** A design row with its JSON decoded. */
function assetLabelDesignDecode(array $r): array
{
    $r['stock'] = json_decode((string)$r['stock_json'], true) ?: assetLabelStockFrom([]);
    $r['design'] = json_decode((string)$r['design_json'], true) ?: ['template' => 'standard'];
    unset($r['stock_json'], $r['design_json']);
    $r['id'] = (int)$r['id'];
    $r['is_default'] = (bool)$r['is_default'];
    return $r;
}

/**
 * Makes a design from a template. The first design a company makes, or one marked
 * default, becomes its default.
 *
 * @param string $template  one of ASSET_LABEL_TEMPLATES
 * @param array  $stockIn   for assetLabelStockFrom()
 * @param array  $options   {font: 'noto' or 'font:<id>', mono?: bool - left unset, it follows the size}
 * @return int the new design's id
 */
function assetLabelDesignCreate(PDO $conn, ?int $tenantId, string $name, string $template, array $stockIn, ?int $analystId, bool $default = false, array $options = []): int
{
    if (!in_array($template, ASSET_LABEL_TEMPLATES, true)) throw new InvalidArgumentException('Unknown template.');
    $name = trim($name);
    if ($name === '' || mb_strlen($name) > 100) throw new InvalidArgumentException('Give the design a name, up to 100 characters.');
    $stock = assetLabelStockFrom($stockIn);
    $design = ['v' => 1, 'template' => $template, 'font' => assetLabelFontRef($conn, $tenantId, (string)($options['font'] ?? ASSET_LABEL_BUILTIN_FONT))];
    if (isset($options['mono'])) $design['mono'] = (bool)$options['mono'];
    $conn->beginTransaction();
    try {
        $n = $conn->prepare("SELECT COUNT(*) FROM asset_label_designs WHERE tenant_id <=> ?");
        $n->execute([$tenantId]);
        $default = $default || (int)$n->fetchColumn() === 0;
        if ($default) $conn->prepare("UPDATE asset_label_designs SET is_default = 0 WHERE tenant_id <=> ?")->execute([$tenantId]);
        $conn->prepare("INSERT INTO asset_label_designs (tenant_id, name, stock_json, design_json, is_default, version, created_by, created_datetime, updated_by, updated_datetime)
                        VALUES (?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP(), ?, UTC_TIMESTAMP())")
             ->execute([$tenantId, $name, json_encode($stock), json_encode($design), $default ? 1 : 0, $analystId, $analystId]);
        $id = (int)$conn->lastInsertId();
        $conn->commit();
        return $id;
    } catch (Throwable $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * A font reference a design may use: the bundled Noto Sans, or a font uploaded for
 * this company (or for every company). Anything else falls back to Noto Sans.
 *
 * @param string $ref  'noto' or 'font:<id>'
 */
function assetLabelFontRef(PDO $conn, ?int $tenantId, string $ref): string
{
    if (preg_match('/^font:(\d+)$/', $ref, $m)) {
        $s = $conn->prepare("SELECT id FROM asset_label_fonts WHERE id = ? AND (tenant_id <=> ? OR tenant_id IS NULL)");
        $s->execute([(int)$m[1], $tenantId]);
        if ($s->fetchColumn()) return 'font:' . (int)$m[1];
    }
    return ASSET_LABEL_BUILTIN_FONT;
}

/** The fonts a company can use, uploaded ones after the bundled one. */
function assetLabelFontsFor(PDO $conn, ?int $tenantId): array
{
    $s = $conn->prepare("SELECT id, name, original_name, format, size_bytes, created_datetime FROM asset_label_fonts WHERE tenant_id <=> ? OR tenant_id IS NULL ORDER BY name");
    $s->execute([$tenantId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Is this file really a font of the kind its extension says? Read from its first
 * bytes, because what a server's fileinfo calls a font differs between versions,
 * and an older one does not know .woff2 at all. A font collection (.ttc) is refused:
 * a browser cannot load one.
 *
 * @param string $path  the uploaded file
 * @param string $ext   ttf, otf, woff or woff2
 */
function assetLabelFontLooksValid(string $path, string $ext): bool
{
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    $ok = [
        'ttf'   => ["\x00\x01\x00\x00", 'true'],
        'otf'   => ['OTTO', "\x00\x01\x00\x00"],
        'woff'  => ['wOFF'],
        'woff2' => ['wOF2'],
    ];
    return isset($ok[$ext]) && in_array($head, $ok[$ext], true);
}

/**
 * What one asset shows on a label: every field's value, as labels.php always printed
 * them ("model" is make and model together), plus the QR's address.
 *
 * @param array $a       the asset row (asset_tag, hostname, service_tag, manufacturer, model, company_name, location_name, asset_type_name, url)
 * @param array $custom  its custom field values, 'cf_<key>' => value
 */
function assetLabelValues(array $a, array $custom): array
{
    return [
        'asset_tag'    => (string)($a['asset_tag'] ?? ''),
        'hostname'     => (string)($a['hostname'] ?? ''),
        'service_tag'  => (string)($a['service_tag'] ?? ''),
        'manufacturer' => (string)($a['manufacturer'] ?? ''),
        'model'        => trim(($a['manufacturer'] ?? '') . ' ' . ($a['model'] ?? '')),
        'company'      => (string)($a['company_name'] ?? ''),
        'location'     => (string)($a['location_name'] ?? ''),
        'asset_type'   => (string)($a['asset_type_name'] ?? ''),
    ] + $custom;
}
