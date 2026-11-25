<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * "Show the button on the analyst login" (5.11.0): a provider can keep its
 * button off the analyst sign-in page, as GH #147 lets it off the portal's.
 *
 *   1. Saved: the dialog's tick is stored; a save that does not mention it keeps
 *      it; the provider list returns it; a directory (LDAP) is always 1.
 *   2. The analyst login: a hidden provider has no button, a shown one has
 *      (positive control); with EVERY button hidden the email box still shows
 *      and the "or" divider does not.
 *   3. The email box still routes an analyst assigned to the hidden provider.
 *   4. The portal login is not affected (its own tick is separate).
 *
 * 🔴 Never touches the install's own database: imports database/freeitsm.sql into
 * a throwaway zz_ssobtn_* database and drops it at the end, pass or fail. Every
 * call is its own process (endpoints exit).
 *
 * Run: php tests/sso-analyst-button.php
 */

$ROOT = dirname(__DIR__);

// Child: one endpoint or page.   --run <db> <analyst|anon> <id> <script>   body on STDIN
if (($argv[1] ?? '') === '--run') {
    [, , $db, $who, $id, $script] = $argv;
    define('DB_NAME', $db);
    ini_set('display_errors', 'stderr');
    $body = stream_get_contents(STDIN);
    class ZzInput {
        public static string $data = '';
        private int $pos = 0;
        public $context;
        public function stream_open($path, $m, $o, &$p): bool { return $path === 'php://input'; }
        public function stream_read(int $n): string { $c = substr(self::$data, $this->pos, $n); $this->pos += strlen($c); return $c; }
        public function stream_eof(): bool { return $this->pos >= strlen(self::$data); }
        public function stream_stat() { return ['size' => strlen(self::$data)]; }
    }
    ZzInput::$data = $body;
    stream_wrapper_unregister('php');
    stream_wrapper_register('php', 'ZzInput');
    $_GET = [];
    $_SERVER['REQUEST_METHOD'] = $body !== '' ? 'POST' : 'GET';
    $_SERVER['SCRIPT_FILENAME'] = "$ROOT/$script";
    $_SERVER['SCRIPT_NAME'] = '/freeitsm-app/' . $script;
    $_SERVER['REQUEST_URI'] = '/freeitsm-app/' . $script;
    $_SERVER['HTTP_HOST'] = 'localhost';
    session_id('zzssobtn' . $who . $id);
    @session_start();
    $_SESSION = $who === 'analyst' ? ['analyst_id' => (int)$id, 'analyst_name' => 'ZZ', 'csrf_token' => 'zz'] : [];
    if ($who === 'analyst') $_SERVER['HTTP_X_CSRF_TOKEN'] = 'zz';
    session_write_close();
    chdir(dirname("$ROOT/$script"));
    require "$ROOT/$script";
    exit;
}

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-72s\n", $label); }
    else       { $fail++; printf("  FAIL %-72s %s\n", $label, substr($detail, 0, 400)); }
}
/** Runs a script; returns [decoded JSON or null, raw output]. */
function run(string $db, string $who, int $id, string $script, ?array $body = null): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --run ' . escapeshellarg($db) . " $who $id " . escapeshellarg($script);
    $p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fwrite($pipes[0], $body === null ? '' : json_encode($body)); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    $start = strpos($out, '{');
    $j = $start === false ? null : json_decode(substr($out, $start), true);
    return [is_array($j) ? $j : null, $out . ($err !== '' ? ' | ' . $err : '')];
}
/** The provider ids that have a button on a login page. */
function buttons(string $html): array {
    preg_match_all('#oidc_login\.php\?provider=(\d+)#', $html, $m);
    return array_map('intval', $m[1]);
}

require "$ROOT/config.php";
$mysql = getenv('MYSQL_BIN') ?: (glob('C:/wamp64/bin/mysql/*/bin/mysql.exe')[0] ?? 'mysql');
$db = 'zz_ssobtn_' . getmypid();
echo "\nShow the button on the analyst login\n" . str_repeat('=', 80) . "\nScratch database: $db\n";
$admin = new PDO('mysql:host=' . DB_SERVER . ';charset=utf8mb4', DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
try {
    putenv('MYSQL_PWD=' . DB_PASSWORD);
    $out = shell_exec(sprintf('"%s" -u %s -h %s %s < "%s/database/freeitsm.sql" 2>&1', $mysql, escapeshellarg(DB_USERNAME), escapeshellarg(DB_SERVER), escapeshellarg($db), $ROOT));
    if (trim((string)$out) !== '') echo "import said: " . substr(trim($out), 0, 300) . "\n";
    $c = new PDO('mysql:host=' . DB_SERVER . ";dbname=$db;charset=utf8mb4", DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $c->exec("INSERT INTO system_settings (setting_key, setting_value) VALUES ('sso_enabled', '1')
              ON DUPLICATE KEY UPDATE setting_value = '1'");
    $c->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules)
              VALUES ('zzadmin', 'x', 'ZZ Admin', 'zzadmin@zz.invalid', 1, 1, 1, 1)");
    $adm = (int)$c->lastInsertId();
    $save = 'api/system/save_sso_provider.php';
    $oidc = fn(string $name, array $extra = []) => $extra + ['display_name' => $name, 'protocol' => 'oidc', 'enabled' => 1,
        'issuer_url' => 'https://' . strtolower(str_replace(' ', '', $name)) . '.zz.invalid', 'client_id' => 'zz'];

    echo "\n1. Saved\n";
    [$r, $raw] = run($db, 'analyst', $adm, $save, $oidc('ZZ Live'));
    $live = (int)($r['id'] ?? 0);
    ok('a provider saved without the tick shows its button (default 1)', $live > 0
       && (int)$c->query("SELECT analyst_show_button FROM auth_providers WHERE id = $live")->fetchColumn() === 1, $raw);
    [$r, $raw] = run($db, 'analyst', $adm, $save, $oidc('ZZ Pilot', ['analyst_show_button' => 0]));
    $pilot = (int)($r['id'] ?? 0);
    ok('unticked is stored as 0', $pilot > 0
       && (int)$c->query("SELECT analyst_show_button FROM auth_providers WHERE id = $pilot")->fetchColumn() === 0, $raw);
    [$r, $raw] = run($db, 'analyst', $adm, $save, $oidc('ZZ Pilot renamed', ['id' => $pilot]));
    ok('a save that does not mention it keeps it', ($r['success'] ?? null) === true
       && (int)$c->query("SELECT analyst_show_button FROM auth_providers WHERE id = $pilot")->fetchColumn() === 0, $raw);
    [$r, $raw] = run($db, 'analyst', $adm, 'api/system/get_sso_providers.php');
    $byId = array_column($r['providers'] ?? [], 'analyst_show_button', 'id');
    ok('the provider list returns it', ($byId[$pilot] ?? null) === 0 && ($byId[$live] ?? null) === 1, json_encode($byId));
    [$r, $raw] = run($db, 'analyst', $adm, $save, ['display_name' => 'ZZ Directory', 'protocol' => 'ldap', 'enabled' => 0,
        'ldap_host' => 'zz.invalid', 'ldap_base_dn' => 'dc=zz', 'ldap_user_filter' => '(uid=%s)', 'analyst_show_button' => 0]);
    $ldap = (int)($r['id'] ?? 0);
    ok('a directory has no button, so it is always 1', $ldap > 0
       && (int)$c->query("SELECT analyst_show_button FROM auth_providers WHERE id = $ldap")->fetchColumn() === 1, $raw);

    echo "\n2. The analyst login\n";
    [, $html] = run($db, 'anon', 0, 'auth/login.php');
    $b = buttons($html);
    ok('the shown provider has its button (positive control)', in_array($live, $b, true), json_encode($b));
    ok('the hidden provider has none', !in_array($pilot, $b, true), json_encode($b));
    ok('the email box is there', str_contains($html, 'id="ssoEmail"'));
    ok('...with the "or" divider above the button', (bool)preg_match('#<span style="flex:1;height:1px;background:\#ddd;"></span>#', $html));
    $c->exec("UPDATE auth_providers SET analyst_show_button = 0 WHERE id = $live");
    [, $html] = run($db, 'anon', 0, 'auth/login.php');
    ok('every button hidden: no buttons', buttons($html) === [], json_encode(buttons($html)));
    ok('...the email box STILL shows', str_contains($html, 'id="ssoEmail"') && str_contains($html, 'id="continueBtn"'));
    ok('...and no "or" divider with nothing under it', !preg_match('#<span style="flex:1;height:1px;background:\#ddd;"></span>#', $html));
    $c->exec("UPDATE auth_providers SET analyst_show_button = 1 WHERE id = $live");

    echo "\n3. The email box routes the assigned analysts\n";
    $c->exec("INSERT INTO analysts (username, password_hash, full_name, email, is_active, auth_provider_id)
              VALUES ('zzpilot', 'x', 'ZZ Pilot analyst', 'pilot@zz.invalid', 1, $pilot)");
    [$r, $raw] = run($db, 'anon', 0, 'api/auth/resolve_login.php', ['email' => 'pilot@zz.invalid']);
    ok('an analyst assigned to the hidden provider is sent to it', ($r['mode'] ?? '') === 'sso' && (int)($r['provider_id'] ?? 0) === $pilot, $raw);
    [$r, $raw] = run($db, 'anon', 0, 'api/auth/resolve_login.php', ['email' => 'someone@zz.invalid']);
    ok('anyone else is not (positive control)', ($r['mode'] ?? '') === 'local', $raw);

    echo "\n4. The portal login\n";
    [, $html] = run($db, 'anon', 0, 'self-service/login.php');
    $b = buttons($html);
    ok('the portal still shows the provider hidden from analysts', in_array($pilot, $b, true) && in_array($live, $b, true), json_encode($b));
} finally {
    $admin->exec("DROP DATABASE IF EXISTS `$db`");
    foreach (glob(rtrim(preg_replace('/^\d+;/', '', session_save_path() ?: sys_get_temp_dir()), '/\\') . '/sess_zzssobtn*') ?: [] as $f) @unlink($f);
}
printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
