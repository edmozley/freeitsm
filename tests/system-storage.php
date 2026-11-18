<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * System -> Storage usage: the folder list, the counting, and the endpoint's gate.
 *
 *   php tests/system-storage.php                       (the list and the counting)
 *   FREEITSM_TEST_DATABASE=<db> FREEITSM_TEST_URL=<install url> php tests/system-storage.php
 *                                                      (and the endpoint, over HTTP)
 *
 * Read only against the install: builds the list, counts a scratch folder in the
 * temp directory, and calls the endpoint with forged sessions it removes after.
 */
chdir(__DIR__ . '/..');
$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = ''): void { global $pass, $fail; echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($ok || $detail === '' ? '' : "  <- $detail") . "\n"; $ok ? $pass++ : $fail++; }

require_once 'includes/storage_inventory.php';

echo "The list\n";
$rows = storageInventory(null);
$paths = array_column($rows, 'path');
$root = str_replace('\\', '/', getcwd());
$missingBefore = array_values(array_filter($paths, fn($p) => !is_dir($p)));
foreach (storagePersistenceDirectories() as $d) {
    if ($d['rel'] === 'uploads') continue;
    $p = rtrim(str_replace('\\', '/', $d['path']), '/');
    check("lists {$d['rel']}", in_array($p, $paths, true));
}
foreach (['documents', 'files', 'asset-imports', 'report-downloads', 'api-log-archive'] as $sub) {
    check("lists uploads/$sub on its own row", in_array("$root/uploads/$sub", $paths, true));
}
check('uploads itself is not a row (its parts are)', !in_array("$root/uploads", $paths, true));
check('every row has its own key', count(array_unique(array_column($rows, 'key'))) === count($rows));
check('every known row has a label in English', !array_filter($rows, function ($r) {
    static $en; $en ??= (include 'lang/en/system.php')['storage_usage']['what'];
    return $r['what'] !== null && !isset($en[$r['what']]);
}));
storageInventory(null);
check('building the list creates no folder', $missingBefore === array_values(array_filter($paths, fn($p) => !is_dir($p))));

echo "Counting\n";
$tmp = str_replace('\\', '/', sys_get_temp_dir()) . '/zzstor-' . getmypid();
try {
    @mkdir("$tmp/sub/deeper", 0777, true);
    file_put_contents("$tmp/a.txt", str_repeat('a', 100));
    file_put_contents("$tmp/sub/b.bin", str_repeat('b', 250));
    file_put_contents("$tmp/sub/deeper/c.bin", str_repeat('c', 50));
    file_put_contents("$tmp/.htaccess", 'Require all denied');
    file_put_contents("$tmp/sub/web.config", '<configuration/>');
    $s = storageFolderStats($tmp);
    check('counts files in every subfolder', $s['files'] === 3, (string)$s['files']);
    check('adds up their sizes', $s['bytes'] === 400, (string)$s['bytes']);
    check('leaves out the files that keep a folder private', $s['complete'] && $s['exists'] && $s['readable']);
    check('knows the newest file', is_int($s['newest']) && abs($s['newest'] - time()) < 120);
    $m = storageFolderStats("$tmp/not-here");
    check('a missing folder is "not there", not an error', $m['exists'] === false && $m['files'] === 0 && !is_dir("$tmp/not-here"));
    $e = storageFolderStats("$tmp/sub/deeper");
    check('a folder deep down is counted on its own', $e['files'] === 1 && $e['bytes'] === 50 && $e['complete']);
} finally {
    foreach (["$tmp/a.txt", "$tmp/sub/b.bin", "$tmp/sub/deeper/c.bin", "$tmp/.htaccess", "$tmp/sub/web.config"] as $f) @unlink($f);
    @rmdir("$tmp/sub/deeper"); @rmdir("$tmp/sub"); @rmdir($tmp);
}

$expected = getenv('FREEITSM_TEST_DATABASE');
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if ($expected && $base) {
    echo "The endpoint\n";
    require_once 'config.php';
    require_once 'includes/functions.php';
    $conn = connectToDatabase();
    if ($conn->query('SELECT DATABASE()')->fetchColumn() !== $expected) { echo "(endpoint skipped: config.php does not use $expected)\n"; }
    else {
        $sessDir = rtrim((string)(getenv('FREEITSM_SESS_DIR') ?: 'C:/wamp64/tmp'), '/');
        $admin = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND is_admin = 1 ORDER BY id LIMIT 1")->fetchColumn();
        $plain = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 AND (is_admin = 0 OR is_admin IS NULL) ORDER BY id LIMIT 1")->fetchColumn();
        $sids = [];
        $sess = function (int $id) use ($sessDir, &$sids) {
            $sid = 'zzstor' . bin2hex(random_bytes(4)); $sids[] = $sid;
            file_put_contents("$sessDir/sess_$sid", 'analyst_id|i:' . $id . ';analyst_name|s:4:"ZZST";');
            return $sid;
        };
        $get = function (string $query, ?string $sid) use ($base): array {
            $ch = curl_init("$base/api/system/storage_stats.php$query");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $sid ? ['Cookie: PHPSESSID=' . $sid] : []]);
            $raw = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($raw, true) ?: ['raw' => substr($raw, 0, 200)]];
        };
        try {
            $live = storageInventory($conn);
            if (defined('ENCRYPTION_KEY_PATH')) check('the encryption key is listed, marked critical', (bool)array_filter($live, fn($r) => $r['what'] === 'encryption_key' && $r['critical']));
            $key = $live[0]['key'];
            $a = $sess($admin);
            [$c, $d] = $get('?key=' . $key, $a);
            check('an administrator gets the count for a listed folder', $c === 200 && ($d['success'] ?? false) && isset($d['files'], $d['bytes']), "$c " . json_encode($d));
            [$c] = $get('?key=0000000000000000', $a);
            check('an unknown key is refused', $c === 404, (string)$c);
            [$c] = $get('?key=' . rawurlencode('C:/Windows'), $a);
            check('a path in place of a key is refused', $c === 404, (string)$c);
            if ($plain) {
                [$c] = $get('?key=' . $key, $sess($plain));
                check('an analyst who is not an administrator is refused', $c === 403, (string)$c);
            }
            [$c] = $get('?key=' . $key, null);
            check('nobody signed in is refused', $c === 401, (string)$c);
        } finally {
            foreach ($sids as $s) @unlink("$sessDir/sess_$s");
        }
    }
} else {
    echo "(endpoint skipped: set FREEITSM_TEST_DATABASE and FREEITSM_TEST_URL)\n";
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
