<?php
/* NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Handover preview and email used English labels because only the print page
 * supplied translations. Every caller must get the active locale by default,
 * without translating an administrator's template text or changing escaping.
 *
 * Run: php tests/asset-handover-labels.php
 * No database, saved templates or outgoing emails are touched.
 */
require_once __DIR__ . '/../includes/php_version_guard.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/services/handover_templates.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $condition): void
{
    global $pass, $fail;
    if ($condition) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

$blocks = HandoverTemplates::defaultBlocks();
foreach ($blocks as &$block) {
    if ($block['type'] === 'title') {
        $block['text']['heading'] = 'ZZ handover for {{employee.name}}';
    }
    if ($block['type'] === 'assets') {
        $block['columns'] = array_fill_keys(array_keys($block['columns']), true);
    }
}
unset($block);
$user = ['id' => 42, 'name' => 'ZZ <b>Sample</b>', 'email' => 'zz_handover@example.invalid'];
$assets = [['asset_type' => 'ZZ Laptop', 'hostname' => 'ZZ <script>asset</script>',
    'manufacturer' => 'ZZ Make', 'model' => 'ZZ Model', 'service_tag' => 'ZZ-001', 'asset_tag' => 'ZZ-002']];

foreach ([
    'de' => ['Typ', 'Name', 'Hersteller und Modell', 'Seriennummer', 'Inventarnummer', 'Zugewiesen', 'Standort', 'Status', 'Notizen'],
    'en' => ['Type', 'Name', 'Make and model', 'Serial number', 'Asset tag', 'Assigned', 'Location', 'Status', 'Notes'],
] as $locale => $headings) {
    I18n::setLocale($locale);
    $html = HandoverTemplates::renderBlocks($blocks, $user, $assets);
    preg_match_all('~<th>(.*?)</th>~s', $html, $matches);
    check("$locale: all table headings use the active locale", $matches[1] === $headings);
    check("$locale: person labels use the active locale",
        str_contains($html, 'class="field-label">' . t('asset-management.handover.field_name') . '</div>')
        && str_contains($html, 'class="field-label">' . t('asset-management.handover.field_email') . '</div>'));
    check("$locale: template text and merge codes are preserved",
        str_contains($html, 'ZZ handover for ZZ &lt;b&gt;Sample&lt;/b&gt;') && !str_contains($html, '{{employee.name}}'));
    check("$locale: person and asset values remain escaped",
        !str_contains($html, '<b>') && !str_contains($html, '<script>')
        && str_contains($html, 'ZZ &lt;script&gt;asset&lt;/script&gt;'));
    $empty = HandoverTemplates::renderBlocks($blocks, $user, []);
    check("$locale: the no-equipment message uses the active locale",
        str_contains($empty, htmlspecialchars(t('asset-management.handover.none'), ENT_QUOTES, 'UTF-8')));
    $override = HandoverTemplates::renderBlocks($blocks, $user, $assets, ['labels' => ['col_type' => 'ZZ <type>']]);
    check("$locale: explicit labels still override the default and are escaped",
        str_contains($override, '<th>ZZ &lt;type&gt;</th>') && !str_contains($override, '<type>'));
    check("$locale: partial overrides retain translations for other columns",
        str_contains($override, '<th>' . $headings[2] . '</th>'));
}
echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
