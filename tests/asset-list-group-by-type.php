<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * Assets: the list grouped by type (GH #337), in headless Chrome.
 *
 * Feeds the real page (asset-management/) a made-up list - nothing is written to
 * the database - and reads the headings it draws. Pinned:
 *   - one heading per type, in the active types' order, each with its count;
 *   - an asset whose type is not in the active list (switched off, hidden from
 *     this company, another company's) gets a heading of its own, named as
 *     get_assets.php names it - never "No type";
 *   - "No type" last; off, the list is flat as before.
 *
 * Run:  FREEITSM_BASE_URL=<throwaway install URL> php tests/asset-list-group-by-type.php
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

echo "\nAssets: the list grouped by type\n" . str_repeat('=', 70) . "\n";
$base = getenv('FREEITSM_BASE_URL');
if (!$base) { echo "  SKIP  set FREEITSM_BASE_URL to the throwaway install's URL\n"; exit(0); }
$chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe';
foreach (['/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $alt) if (!is_file($chrome) && is_file($alt)) $chrome = $alt;
if (!is_file($chrome)) { echo "  SKIP  no Chrome\n"; exit(0); }

$conn = connectToDatabase();
$admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
$sessDir = rtrim((string)ini_get('session.save_path'), '/' . chr(92)) ?: sys_get_temp_dir();
$sid = 'zzagt' . bin2hex(random_bytes(6));
$frame = dirname(__DIR__) . '/zz_agt_' . bin2hex(random_bytes(3)) . '.php';

// Runs inside the page once it has loaded: a made-up list, grouped, then flat.
$probe = <<<'JS'
(()=>{const w=f.contentWindow,d=f.contentDocument;
w.eval("assetTypes=[{id:1,name:'ZZ Laptop',is_active:1},{id:2,name:'ZZ Phone',is_active:1}];"
 +"assets=[{id:9001,hostname:'A',asset_type_id:1,asset_type_name:'ZZ Laptop'},{id:9002,hostname:'B',asset_type_id:7,asset_type_name:'ZZ Old printer'},"
 +"{id:9003,hostname:'C',asset_type_id:null},{id:9004,hostname:'D',asset_type_id:7,asset_type_name:'ZZ Old printer'},{id:9005,hostname:'E',asset_type_id:2,asset_type_name:'ZZ Phone'}];"
 +"groupByType=true;closedAssetGroups=new Set();renderAssetsList();");
const heads=[...d.querySelectorAll('#assetsList .asset-group-head')].map(h=>[h.querySelector('span:not(.asset-group-chev):not(.asset-group-count)').textContent,h.querySelector('.asset-group-count').textContent]);
const rows=d.querySelectorAll('#assetsList .asset-item').length;
w.eval("groupByType=false;renderAssetsList();");
const flat=[d.querySelectorAll('#assetsList .asset-item').length,d.querySelectorAll('#assetsList .asset-group-head').length];
return {heads,rows,flat};})()
JS;
$probe = str_replace("\n", ' ', $probe);

try {
    file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $admin . ';analyst_name|s:5:"zztst";is_admin|i:1;csrf_token|s:64:"' . bin2hex(random_bytes(32)) . '";');
    file_put_contents($frame, "<?php setcookie('PHPSESSID', '$sid', 0, '/'); ?><!doctype html><html><body style=\"margin:0\"><pre id=\"out\">waiting</pre>"
        . "<iframe id=\"f\" src=\"asset-management/\" style=\"width:1600px;height:1000px;border:0\"></iframe><script>const f=document.getElementById('f');"
        . "f.addEventListener('load',()=>{const s=f.contentDocument.createElement('style');s.textContent='*{transition:none!important;animation:none!important}';f.contentDocument.head.appendChild(s);"
        . "setTimeout(()=>{let o;try{o=$probe}catch(e){o={err:String(e)}};document.getElementById('out').textContent=JSON.stringify(o);},3000);});</script></body></html>");
    $out = (string)shell_exec('"' . $chrome . '" --headless=new --disable-gpu --window-size=1700,1100 --user-data-dir="' . sys_get_temp_dir() . '/zz_agt_chrome_' . getmypid() . '" --virtual-time-budget=12000 --dump-dom "' . rtrim($base, '/') . '/' . basename($frame) . '" 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
    $j = preg_match('#<pre id="out">(.*?)</pre>#s', $out, $m) ? (json_decode(html_entity_decode($m[1]), true) ?: []) : [];
    if (!$j || isset($j['err'])) {
        ok('the page could be read', false, $j['err'] ?? 'no output');
    } else {
        $heads = $j['heads'];
        ok('POSITIVE CONTROL: grouped, every asset is drawn', $j['rows'] === 5, (string)$j['rows']);
        ok('one heading per type, active types first in their order, each with its count',
            array_slice($heads, 0, 2) === [['ZZ Laptop', '1'], ['ZZ Phone', '1']], json_encode($heads));
        ok('a type not in the active list keeps its own heading and name, not "No type"',
            ($heads[2] ?? null) === ['ZZ Old printer', '2'], json_encode($heads));
        ok('"No type" holds only the asset with no type, and comes last', count($heads) === 4 && ($heads[3][1] ?? null) === '1', json_encode($heads));
        ok('off, the list is flat as before', $j['flat'] === [5, 0], json_encode($j['flat']));
    }
} finally {
    @unlink($frame);
    @unlink("$sessDir/sess_$sid");
}

echo "\n" . str_repeat('=', 70) . "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
