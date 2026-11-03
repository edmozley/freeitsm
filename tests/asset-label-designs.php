<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Asset label designs (GH #229, slice 1): label sizes, the ready-made layouts, fonts.
 *
 *   php tests/asset-label-designs.php
 *   FREEITSM_TEST_DATABASE=<a throwaway database> php tests/asset-label-designs.php   (part 2 as well)
 *
 * PART 1 - no database: every sheet fits its page; a size out of range is refused;
 *          every layout on every size keeps every item inside the label; the font
 *          check reads the file's own first bytes.
 * PART 2 - the designs on a test company (id 987654, which no install uses): the
 *          Standard design is made the first time, one default per company, and the
 *          version moves on every save. Its rows are removed at the end.
 *
 * The drawing and the PDF are checked in a browser - see the developer guide.
 *
 * @wiki Asset-Label-Designer-Design
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/tenancy.php';
require_once __DIR__ . '/../includes/tenant_settings.php';
require_once __DIR__ . '/../includes/asset_label_designs.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  $what\n"; }
    else       { $fail++; echo "  FAIL  $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; }
}

echo "PART 1 - sizes, layouts and fonts\n";
$stocks = assetLabelStocks();
ok('control: there are tape and sheet sizes', count(array_filter($stocks, fn($s) => $s['kind'] === 'tape')) > 3 && count(array_filter($stocks, fn($s) => $s['kind'] === 'sheet')) === 4);
ok('today\'s four sheets keep their old keys, so ?sheet=65 links still work', isset($stocks['65'], $stocks['40'], $stocks['24'], $stocks['12']));
foreach ($stocks as $k => $s) {
    if ($s['kind'] !== 'sheet') continue;
    $right = $s['left'] + ($s['cols'] - 1) * $s['h_pitch'] + $s['w'];
    $bottom = $s['top'] + ($s['rows'] - 1) * $s['v_pitch'] + $s['h'];
    ok("sheet $k: every label is on the page ($right x $bottom mm on 210 x 297)", $right <= 210.01 && $bottom <= 297.01);
}
$refused = function (array $in) { try { assetLabelStockFrom($in); return false; } catch (InvalidArgumentException) { return true; } };
ok('control: your own size within range is accepted', !$refused(['key' => 'custom', 'w' => 62, 'h' => 29]));
ok('a label too small is refused', $refused(['key' => 'custom', 'w' => 5, 'h' => 3]));
ok('a label too large is refused', $refused(['key' => 'custom', 'w' => 500, 'h' => 30]));
ok('an unknown size is refused', $refused(['key' => 'nope']));
ok('a printer offset is held to 10 mm', assetLabelStockFrom(['key' => '65', 'offset_x' => 40])['offset_x'] === 10.0);

$settings = ['title' => 'ZHL Group', 'fields' => ['asset_tag', 'hostname', 'service_tag', 'model'], 'footer' => 'IT helpdesk ext. 4000',
             'logo_path' => 'system/uploads/branding/x.png', 'show_field_labels' => true];
$noLogo = ['logo_path' => ''] + $settings;
$outside = [];
foreach (ASSET_LABEL_TEMPLATES as $tpl) {
    foreach ($stocks as $k => $s) {
        foreach ([$settings, $noLogo] as $set) {
            foreach (assetLabelTemplateItems($tpl, assetLabelStockFrom(['key' => $k]), $set) as $it) {
                if ($it['w'] <= 0 || $it['h'] <= 0 || $it['x'] < -0.01 || $it['y'] < -0.01 || $it['x'] + $it['w'] > $s['w'] + 0.01 || $it['y'] + $it['h'] > $s['h'] + 0.01) {
                    $outside[] = "$tpl on $k: {$it['type']} at {$it['x']},{$it['y']} {$it['w']}x{$it['h']}";
                }
            }
        }
    }
}
ok('every layout on every size keeps every item inside the label', !$outside, implode('; ', array_slice($outside, 0, 3)));
$std = assetLabelTemplateItems('standard', assetLabelStockFrom(['key' => '65']), $settings);
$types = array_column($std, 'type');
ok('Standard is today\'s label: header, QR with the logo inside, the fields, footer',
   in_array('qr', $types, true) && in_array('fields', $types, true) && count(array_filter($std, fn($i) => $i['type'] === 'text')) === 2
   && array_values(array_filter($std, fn($i) => $i['type'] === 'qr'))[0]['logo'] === true);
$tape = assetLabelTemplateItems('tape_logo_left', assetLabelStockFrom(['key' => 'tze24']), $settings);
$img = array_values(array_filter($tape, fn($i) => $i['type'] === 'image'));
$qr = array_values(array_filter($tape, fn($i) => $i['type'] === 'qr'));
ok('Logo left: the logo on the left, the QR on the right, no logo inside the QR', $img && $qr && $img[0]['x'] < $qr[0]['x'] && $qr[0]['logo'] === false);
ok('...and with no logo uploaded, no picture is placed', !array_filter(assetLabelTemplateItems('tape_logo_left', assetLabelStockFrom(['key' => 'tze24']), $noLogo), fn($i) => $i['type'] === 'image'));

$dir = sys_get_temp_dir() . '/zz-fonts-' . bin2hex(random_bytes(3));
mkdir($dir);
$f = fn(string $name, string $bytes) => (file_put_contents("$dir/$name", $bytes . str_repeat("\0", 64)) ? "$dir/$name" : '');
ok('control: the bundled Noto Sans is a TTF', assetLabelFontLooksValid(__DIR__ . '/../assets/fonts/noto-sans/NotoSans-Regular.ttf', 'ttf'));
ok('a WOFF2 is recognised', assetLabelFontLooksValid($f('a.woff2', 'wOF2'), 'woff2'));
ok('an OTF is recognised', assetLabelFontLooksValid($f('a.otf', 'OTTO'), 'otf'));
ok('a PNG renamed .ttf is refused', !assetLabelFontLooksValid($f('a.ttf', "\x89PNG"), 'ttf'));
ok('a WOFF named .woff2 is refused', !assetLabelFontLooksValid($f('b.woff2', 'wOFF'), 'woff2'));
ok('a font collection (.ttc) is refused - a browser cannot load one', !assetLabelFontLooksValid($f('c.ttf', 'ttcf'), 'ttf'));
ok('any other extension is refused', !assetLabelFontLooksValid($f('d.php', "\x00\x01\x00\x00"), 'php'));
array_map('unlink', glob("$dir/*")); rmdir($dir);
ok('the bundled font\'s licence is shipped beside it', is_file(__DIR__ . '/../assets/fonts/noto-sans/OFL.txt'));

echo "\nPART 1b - what the designer saves (slice 2)\n";
$tz = assetLabelStockFrom(['key' => 'tze24']);
$keys = ['asset_tag', 'hostname', 'service_tag'];
$clean = assetLabelSanitiseItems([
    ['id' => 'a1', 'type' => 'text', 'x' => 2, 'y' => 3, 'w' => 30, 'h' => 6, 'text' => 'Serial {service_tag}', 'size' => 9, 'bold' => 1, 'align' => 'center', 'color' => '#FF0000'],
    ['id' => 'a2', 'type' => 'field', 'field' => 'hostname', 'caption' => 'above', 'x' => 1, 'y' => 1, 'w' => 20, 'h' => 5],
    ['id' => 'a3', 'type' => 'qr', 'x' => 50, 'y' => 1, 'w' => 21, 'h' => 21, 'logo' => true],
    ['id' => 'a4', 'type' => 'line', 'x' => 0, 'y' => 12, 'w' => 73, 'h' => 0],
    ['id' => 'a5', 'type' => 'line', 'x' => 30, 'y' => 0, 'w' => 0, 'h' => 24],
], $tz, $keys);
ok('control: clean items pass through, in order', array_column($clean, 'id') === ['a1', 'a2', 'a3', 'a4', 'a5']);
ok('...the text keeps its words, its {field} and its style', $clean[0]['text'] === 'Serial {service_tag}' && $clean[0]['bold'] === true && $clean[0]['align'] === 'center' && $clean[0]['color'] === '#ff0000');
ok('...a caption above is kept', $clean[1]['caption'] === 'above');
ok('...a level line and an upright line keep their 0 height / width', $clean[3]['h'] == 0 && $clean[4]['w'] == 0);
$bad = assetLabelSanitiseItems([
    ['type' => 'script', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5],
    'not an item',
    ['id' => 'b1', 'type' => 'field', 'field' => 'password_hash', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5],
    ['id' => 'b2', 'type' => 'text', 'x' => 9999, 'y' => -9999, 'w' => 0, 'h' => 9999, 'size' => 500, 'align' => 'justify', 'fit' => 'explode', 'color' => 'red;background:url(x)', 'rot' => 45, 'onclick' => 'x'],
    ['id' => 'b2', 'type' => 'image', 'src' => 'https://evil.example/x.png', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5],
    ['id' => '<b3>', 'type' => 'fields', 'fields' => ['nope', 'hostname', 'hostname'], 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5],
], $tz, $keys);
ok('an unknown item type and a non-item are dropped', count($bad) === 4 && !in_array('script', array_column($bad, 'type'), true));
ok('a field that is not offered falls back to the asset tag', $bad[0]['field'] === 'asset_tag');
ok('numbers are held inside the label\'s range', $bad[1]['x'] <= 146 && $bad[1]['y'] >= -24 && $bad[1]['w'] >= 0.5 && $bad[1]['h'] <= 72 && $bad[1]['size'] <= 96);
ok('choices outside the list fall back: align, fit, colour, rotation', $bad[1]['align'] === 'left' && $bad[1]['fit'] === 'shrink' && $bad[1]['color'] === '#000000' && $bad[1]['rot'] === 0);
ok('properties the designer does not know are not saved', !array_key_exists('onclick', $bad[1]));
ok('a picture is always the logo from label settings, never an address', $bad[2]['src'] === 'logo');
ok('a repeated or odd id is replaced', $bad[2]['id'] !== 'b2' && $bad[3]['id'] !== '<b3>');
ok('a field list keeps only offered fields, once each', $bad[3]['fields'] === ['hostname']);
ok('at most 100 items are kept', count(assetLabelSanitiseItems(array_fill(0, 150, ['type' => 'box', 'x' => 0, 'y' => 0, 'w' => 5, 'h' => 5]), $tz, $keys)) === 100);
$custom = ['design' => ['template' => 'custom', 'items' => $clean]];
ok('a designed label prints its own items, whatever the label settings say', assetLabelItemsFor($custom, $tz, $settings) === $clean);
ok('control: a template design still follows the label settings', assetLabelItemsFor(['design' => ['template' => 'tape_small']], $tz, $settings) === assetLabelTemplateItems('tape_small', $tz, $settings));

echo "\nPART 1c - black-only printers (4.9.0)\n";
$a4 = assetLabelStockFrom(['key' => '65']);
$own = assetLabelStockFrom(['key' => 'custom', 'w' => 60, 'h' => 20]);
ok('a design with no setting prints black-only on tape', assetLabelMono(['design' => ['template' => 'standard']], $tz) === true);
ok('...and on a size of your own (a roll)', assetLabelMono(['design' => []], $own) === true);
ok('control: ...but not on an A4 sheet', assetLabelMono(['design' => ['template' => 'standard']], $a4) === false);
ok('a setting wins over the size, both ways', assetLabelMono(['design' => ['mono' => false]], $tz) === false && assetLabelMono(['design' => ['mono' => true]], $a4) === true);
ok('anything but true or false is treated as unset', assetLabelMono(['design' => ['mono' => '1']], $a4) === false);

echo "\nPART 2 - designs on a test company\n";
$conn = connectToDatabase();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
if (getenv('FREEITSM_TEST_DATABASE') !== $dbName) {
    echo "  SKIP  PART 2 - set FREEITSM_TEST_DATABASE to the throwaway database config.php points at (this one is $dbName)\n";
} elseif (!assetLabelDesignsReady($conn)) {
    echo "  SKIP  PART 2 - run Database Verification on this database first\n";
} else {
    $T = 987654;
    try {
        $conn->prepare("DELETE FROM asset_label_designs WHERE tenant_id = ?")->execute([$T]);
        $list = assetLabelDesignsFor($conn, $T, null);
        ok('a company with no designs gets "Standard", as its default', count($list) >= 1 && $list[0]['design']['template'] === 'standard' && $list[0]['is_default']);
        $again = assetLabelDesignsFor($conn, $T, null);
        ok('...once, not on every visit', count(array_filter($again, fn($d) => (int)($d['tenant_id'] ?? 0) === $T)) === 1);
        $tapeId = assetLabelDesignCreate($conn, $T, 'zz tape', 'tape_logo_left', ['key' => 'tze24'], null, true);
        $n = $conn->prepare("SELECT COUNT(*) FROM asset_label_designs WHERE tenant_id = ? AND is_default = 1");
        $n->execute([$T]);
        ok('making another the default leaves exactly one default', (int)$n->fetchColumn() === 1);
        $d = $conn->query("SELECT is_default, version FROM asset_label_designs WHERE id = $tapeId")->fetch(PDO::FETCH_ASSOC);
        ok('...and it is the new one', (int)$d['is_default'] === 1);
        $u = $conn->prepare("UPDATE asset_label_designs SET name = 'zz tape 2', version = version + 1 WHERE id = ? AND version = ?");
        $u->execute([$tapeId, (int)$d['version']]);
        $first = $u->rowCount();
        $u->execute([$tapeId, (int)$d['version']]);
        ok('a save with the version it read succeeds; a second save with the same, stale, version changes nothing', $first === 1 && $u->rowCount() === 0);
        $m = assetLabelDesignCreate($conn, $T, 'zz mono off', 'tape_small', ['key' => 'tze24'], null, false, ['font' => 'noto', 'mono' => false]);
        $n = assetLabelDesignCreate($conn, $T, 'zz mono unset', 'tape_small', ['key' => 'tze24'], null);
        $get = function (int $id) use ($conn) { $s = $conn->prepare("SELECT * FROM asset_label_designs WHERE id = ?"); $s->execute([$id]); return assetLabelDesignDecode($s->fetch(PDO::FETCH_ASSOC)); };
        ok('a black-only setting given when made is saved', ($get($m)['design']['mono'] ?? null) === false && assetLabelMono($get($m), $get($m)['stock']) === false);
        ok('control: none given saves none, so it follows the size', !array_key_exists('mono', $get($n)['design']) && assetLabelMono($get($n), $get($n)['stock']) === true);
        ok('an unknown layout is refused', (function () use ($conn, $T) { try { assetLabelDesignCreate($conn, $T, 'zz', 'nope', ['key' => '65'], null); return false; } catch (InvalidArgumentException) { return true; } })());
        ok('a font that is not this company\'s falls back to Noto Sans', assetLabelFontRef($conn, $T, 'font:99999999') === ASSET_LABEL_BUILTIN_FONT);
    } finally {
        $conn->prepare("DELETE FROM asset_label_designs WHERE tenant_id = ?")->execute([$T]);
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
