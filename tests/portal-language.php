<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * A self-service portal user chooses their own language (GH #368).
 *
 *   FREEITSM_TEST_URL=http://localhost/<test install> php tests/portal-language.php   (a TEST install, never a real one)
 *
 * Portal users have no analyst row, so the analysts' interface_language preference
 * could never apply to them: the portal followed the browser's language only. Now
 * My account -> Language stores portal_user_preferences.interface_language
 * (api/self-service/preference.php, allow-listed) and I18n::initFromSession()
 * reads it first on a portal page.
 *
 * Each case runs in its own PHP process, because FREEITSM_SELF_SERVICE is a
 * constant and I18n keeps its locale in a static.
 * Writes, and removes in a finally block: one portal user "zz-portal-lang@example.invalid"
 * and their preference row.
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$pass = 0; $fail = 0;
/**
 * Records and prints one PASS or FAIL line.
 *
 * @param string $what  what is being checked, in a few words
 * @param bool $cond  true when the check passed
 * @param string $detail  shown after a failure - the values that disagreed
 */
function ok(string $what, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  PASS  {$what}\n"; }
    else       { $fail++; echo "  FAIL  {$what}" . ($detail ? "  <- {$detail}" : '') . "\n"; }
}

/**
 * The locale I18n::initFromSession() picks in a fresh PHP process.
 *
 * @param array $session  the session to start with
 * @param bool $portalPage  true to define FREEITSM_SELF_SERVICE first, as the portal header does
 * @param string $acceptLanguage  the browser's Accept-Language header
 */
function localeFor(array $session, bool $portalPage, string $acceptLanguage = 'en-GB'): string {
    $root = var_export(dirname(__DIR__), true);
    $code = '<?php $_SESSION = ' . var_export($session, true) . '; $_SERVER["HTTP_ACCEPT_LANGUAGE"] = ' . var_export($acceptLanguage, true) . ';'
          . ($portalPage ? 'define("FREEITSM_SELF_SERVICE", true);' : '')
          . 'require ' . $root . ' . "/config.php"; require ' . $root . ' . "/includes/functions.php"; require ' . $root . ' . "/includes/i18n.php";'
          . 'I18n::initFromSession(); echo I18n::getLocale();';
    $file = tempnam(sys_get_temp_dir(), 'zzpl') . '.php';
    file_put_contents($file, $code);
    $out = trim((string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file)));
    @unlink($file);
    return $out;
}

/**
 * POSTs to api/self-service/preference.php over HTTP as the given portal user, with
 * a session file written for the call (and removed). FREEITSM_TEST_URL names the
 * install that uses this database.
 *
 * @param int $userId  the signed-in portal user
 * @param array $body  the POST body
 */
function postPreference(int $userId, array $body): array {
    $base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
    $sid = 'zzpl' . bin2hex(random_bytes(8));
    $file = rtrim((string)ini_get('session.save_path'), '/\\') . "/sess_$sid";
    $token = bin2hex(random_bytes(16));
    file_put_contents($file, 'ss_user_id|i:' . $userId . ';csrf_token|s:32:"' . $token . '";');
    $ch = curl_init("$base/api/self-service/preference.php");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Cookie: PHPSESSID=$sid", "X-CSRF-Token: $token"], CURLOPT_TIMEOUT => 20]);
    $out = (string)curl_exec($ch);
    curl_close($ch);
    @unlink($file);
    return json_decode($out, true) ?: ['raw' => substr($out, 0, 300)];
}

echo "\nPortal users choose their language (GH #368)\n" . str_repeat('=', 70) . "\n";
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
echo "  database: $db\n";
if (!preg_match('/test/i', $db)) { echo "  STOP  this test adds a portal user; run it only on a test install\n"; exit(1); }
if (!getenv('FREEITSM_TEST_URL')) { echo "  SKIP  set FREEITSM_TEST_URL to the install that uses this database\n"; exit(0); }

$analyst = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$analystPref = $conn->prepare("SELECT preference_value FROM user_preferences WHERE analyst_id = ? AND preference_key = 'interface_language'");
$analystPref->execute([$analyst]);
$analystLocale = (string)($analystPref->fetchColumn() ?: '');

$userId = 0;
try {
    $conn->prepare("INSERT INTO users (email, display_name, is_active, created_at) VALUES ('zz-portal-lang@example.invalid', 'ZZ Portal Lang', 1, UTC_TIMESTAMP())")->execute();
    $userId = (int)$conn->lastInsertId();
    $portal = ['ss_user_id' => $userId];

    echo "\nNo choice yet\n";
    ok("the portal follows the browser's language, as before", localeFor($portal, true, 'fr-FR,fr;q=0.9') === 'fr', localeFor($portal, true, 'fr-FR,fr;q=0.9'));

    echo "\nSaving it (api/self-service/preference.php)\n";
    $r = postPreference($userId, ['key' => 'interface_language', 'value' => 'xx']);
    ok('an unsupported language is refused', empty($r['success']), json_encode($r));
    $r = postPreference($userId, ['key' => 'interface_language', 'value' => 'de']);
    ok('a supported language is stored', !empty($r['success']) && ($r['stored'] ?? false) === true, json_encode($r));

    echo "\nReading it (I18n::initFromSession)\n";
    ok("a portal page uses the portal user's choice over the browser's", localeFor($portal, true, 'fr-FR') === 'de', localeFor($portal, true, 'fr-FR'));
    ok("the portal's own API calls (no portal flag, no analyst) use it too", localeFor($portal, false, 'fr-FR') === 'de', localeFor($portal, false, 'fr-FR'));
    if ($analyst) {
        $both = ['ss_user_id' => $userId, 'analyst_id' => $analyst];
        ok('an analyst who also has a portal account: the portal page uses the portal choice', localeFor($both, true) === 'de', localeFor($both, true));
        $expected = $analystLocale !== '' ? $analystLocale : 'en';
        ok('...and the analyst app keeps the analyst\'s own language', localeFor($both, false, 'en-GB') === $expected, localeFor($both, false, 'en-GB') . " expected $expected");
    }

    $r = postPreference($userId, ['key' => 'interface_language', 'value' => '']);
    ok("choosing the browser's language again is accepted", !empty($r['success']), json_encode($r));
    ok('...and the browser decides once more', localeFor($portal, true, 'pl-PL') === 'pl', localeFor($portal, true, 'pl-PL'));

    echo "\nThe strings\n";
    foreach (['en', 'de', 'fr'] as $l) {
        $lang = include __DIR__ . "/../lang/$l/self-service.php";
        ok("$l has the Language label and its options", !empty($lang['account']['language']) && !empty($lang['account']['language_browser']));
    }
} finally {
    if ($userId) {
        $conn->prepare("DELETE FROM portal_user_preferences WHERE user_id = ?")->execute([$userId]);
        $conn->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
    }
    $left = (int)$conn->query("SELECT COUNT(*) FROM users WHERE email = 'zz-portal-lang@example.invalid'")->fetchColumn();
    echo "\n";
    ok('everything the test wrote is gone', $left === 0, "$left rows left");
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
