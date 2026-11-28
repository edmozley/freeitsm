<?php
/**
 * "Keep me signed in" (GH #443) - over real HTTP against a disposable install.
 *
 *   FREEITSM_TEST_DATABASE=<throwaway db> FREEITSM_TEST_URL=http://localhost/<that install> php tests/keep-signed-in.php
 *
 * Signs a ZZ analyst in through auth/login.php with the tick, throws the PHP session
 * away (as an idle timeout, a closed browser or a container restart would) and checks
 * a page then opens signed in from the cookie alone - and every case in which it must
 * NOT: setting Off, a shorter period, a changed password, a deactivated analyst,
 * signing out, and a copied cookie used after the owner's (which removes every token).
 *
 * The controls: the same requests without the cookie are sent to the sign-in page;
 * the tick is absent while the setting is Off. Everything made here is removed in
 * `finally`; the setting is put back as it was.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
chdir($root);
require_once 'config.php';
require_once 'includes/functions.php';
$conn = connectToDatabase();
$db = (string)$conn->query('SELECT DATABASE()')->fetchColumn();
if ($db === '' || $db !== getenv('FREEITSM_TEST_DATABASE')) {
    echo "SKIPPED: set FREEITSM_TEST_DATABASE to the database this may write to (this one is $db).\n";
    exit(0);
}
$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if ($base === '' || !in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true)) {
    echo "SKIPPED: set FREEITSM_TEST_URL to the local install that uses $db.\n";
    exit(0);
}
if (!keepSignedInReady($conn)) { echo "SKIPPED: run Database Verification first (no analyst_login_tokens).\n"; exit(0); }

$pass = 0; $fail = 0;
function check(string $what, bool $ok, string $detail = '') { global $pass, $fail; if ($ok) { $pass++; echo "  [PASS] $what\n"; } else { $fail++; echo "  [FAIL] $what" . ($detail !== '' ? "  <- $detail" : '') . "\n"; } }

/** One request; returns [status, location, body, cookies set (name => value)]. */
function req(string $method, string $url, array $cookies = [], ?array $post = null): array {
    $ch = curl_init($url);
    $hdr = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => function ($c, $l) use (&$hdr) { $hdr[] = $l; return strlen($l); }]);
    if ($cookies) curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($cookies), $cookies))]);
    if ($post !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $set = []; $loc = '';
    foreach ($hdr as $l) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', $l, $m)) $set[trim($m[1])] = urldecode($m[2]);
        if (preg_match('/^Location:\s*(.+)$/i', $l, $m)) $loc = trim($m[1]);
    }
    return [$status, $loc, $body, $set];
}
$setting = function (?string $v) use ($conn) {
    if ($v === null) { $conn->prepare("DELETE FROM system_settings WHERE setting_key = 'keep_signed_in_days'")->execute(); return; }
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('keep_signed_in_days', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};
$was = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'keep_signed_in_days'")->fetchColumn();
$tag = 'zzksi' . bin2hex(random_bytes(3));
$password = 'Zz-' . bin2hex(random_bytes(8)) . '!9a';
$analystId = 0;
$made2 = 0;

/** Signs in with the tick; returns the cookies the browser would then hold. */
$signIn = function (bool $tick) use ($base, $tag, $password): array {
    [, , , $c0] = req('GET', "$base/auth/login.php");
    $jar = $c0;
    [$s, $loc, , $c1] = req('POST', "$base/auth/login.php", $jar, ['username' => $tag, 'password' => $password] + ($tick ? ['keep_signed_in' => '1'] : []));
    return [$s, $loc, array_merge($jar, $c1)];
};
/** Is a page open signed in with just these cookies? (200 on the inbox, not a bounce to login) */
$opens = function (array $cookies) use ($base): array {
    [$s, $loc, , $set] = req('GET', "$base/tickets/", $cookies);
    return [$s === 200, $set, "$s $loc"];
};

try {
    $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 1, 1, 1)')
         ->execute([$tag, password_hash($password, PASSWORD_DEFAULT), 'ZZ Keep Signed In', "$tag@example.test"]);
    $analystId = (int)$conn->lastInsertId();
    $conn->prepare("UPDATE analysts SET password_changed_datetime = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?")->execute([$analystId]);

    echo "\nThe tick\n";
    $setting('0');
    [, , $page] = req('GET', "$base/auth/login.php");
    check('CONTROL: with the setting Off the sign-in page has no tick', !str_contains($page, 'name="keep_signed_in"'));
    $setting('7');
    [, , $page] = req('GET', "$base/auth/login.php");
    check('with 7 days it offers "Keep me signed in for 7 days"', str_contains($page, 'name="keep_signed_in"') && str_contains($page, '7 days'));

    echo "\nSigning in with it, then losing the session\n";
    [$s, $loc, $jar] = $signIn(true);
    check("signed in ($s to $loc)", $s === 302 && !str_contains($loc, 'login.php'));
    $keep = $jar['fitsm_keep'] ?? '';
    check('a fitsm_keep cookie was set: selector:validator', (bool)preg_match('/^[0-9a-f]{24}:[0-9a-f]{64}$/', $keep));
    $row = $conn->prepare("SELECT selector, validator_hash FROM analyst_login_tokens WHERE analyst_id = ?");
    $row->execute([$analystId]);
    $tok = $row->fetch(PDO::FETCH_ASSOC);
    check('one token row; only a hash of the validator is stored', $tok && $tok['selector'] === substr($keep, 0, 24) && $tok['validator_hash'] === hash('sha256', hex2bin(substr($keep, 25))) && !str_contains(json_encode($tok), substr($keep, 25)));
    [$ok, , $d] = $opens([]);
    check('CONTROL: with no cookies at all the inbox sends you to sign in', !$ok, $d);
    [$ok, $set, $d] = $opens(['fitsm_keep' => $keep]);
    check('with only the cookie - the session gone - the inbox opens signed in', $ok, $d);
    $rotated = $set['fitsm_keep'] ?? '';
    check('...and the validator was rotated (same selector, new validator)', $rotated !== '' && $rotated !== $keep && substr($rotated, 0, 24) === substr($keep, 0, 24));
    [$ok, , $d] = $opens(['fitsm_keep' => $keep]);
    check('a request racing the rotation (old validator, within two minutes) still opens', $ok, $d);

    echo "\nA copied cookie\n";
    $conn->prepare("UPDATE analyst_login_tokens SET rotated_datetime = UTC_TIMESTAMP() - INTERVAL 10 MINUTE WHERE analyst_id = ?")->execute([$analystId]);
    [$ok, , $d] = $opens(['fitsm_keep' => $keep]);
    check('the old validator long after the rotation is refused', !$ok, $d);
    $n = $conn->prepare("SELECT COUNT(*) FROM analyst_login_tokens WHERE analyst_id = ?"); $n->execute([$analystId]);
    check('...and every token of that analyst is removed', (int)$n->fetchColumn() === 0);
    [$ok, , $d] = $opens(['fitsm_keep' => $rotated]);
    check('...so the owner\'s own (newer) cookie stops working too', !$ok, $d);

    $fresh = function () use ($signIn): string { [, , $j] = $signIn(true); return $j['fitsm_keep'] ?? ''; };

    echo "\nWhen it must stop\n";
    $k = $fresh();
    $setting('0');
    [$ok, , $d] = $opens(['fitsm_keep' => $k]);
    check('the setting turned Off refuses it', !$ok, $d);
    $setting('7');

    $k = $fresh();
    $conn->prepare("UPDATE analyst_login_tokens SET created_datetime = UTC_TIMESTAMP() - INTERVAL 10 DAY, expires_datetime = UTC_TIMESTAMP() + INTERVAL 20 DAY WHERE analyst_id = ?")->execute([$analystId]);
    [$ok, , $d] = $opens(['fitsm_keep' => $k]);
    check('a token made 10 days ago under a longer setting is refused at 7 days', !$ok, $d);

    $k = $fresh();
    $conn->prepare("UPDATE analysts SET password_changed_datetime = UTC_TIMESTAMP() + INTERVAL 1 MINUTE WHERE id = ?")->execute([$analystId]);
    [$ok, , $d] = $opens(['fitsm_keep' => $k]);
    check('a password changed after the token was made refuses it', !$ok, $d);
    $conn->prepare("UPDATE analysts SET password_changed_datetime = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?")->execute([$analystId]);

    $k = $fresh();
    $conn->prepare("UPDATE analysts SET is_active = 0 WHERE id = ?")->execute([$analystId]);
    [$ok, , $d] = $opens(['fitsm_keep' => $k]);
    check('a deactivated analyst is refused', !$ok, $d);
    $conn->prepare("UPDATE analysts SET is_active = 1 WHERE id = ?")->execute([$analystId]);

    echo "\nSigning out\n";
    [, , $jar] = $signIn(true);
    $k = $jar['fitsm_keep'] ?? '';
    [, , , $out] = req('GET', "$base/auth/analyst_logout.php", $jar);
    $n = $conn->prepare("SELECT COUNT(*) FROM analyst_login_tokens WHERE selector = ?"); $n->execute([substr($k, 0, 24)]);
    check('Sign out removes this browser\'s token', (int)$n->fetchColumn() === 0);
    check('...and clears the cookie', array_key_exists('fitsm_keep', $out) && $out['fitsm_keep'] === '' || ($out['fitsm_keep'] ?? 'x') === 'deleted');
    [$ok, , $d] = $opens(['fitsm_keep' => $k]);
    check('...so the cookie no longer signs in', !$ok, $d);

    echo "\nSigned-in devices\n";
    if ($conn->query("SHOW TABLES LIKE 'analyst_sessions'")->fetchColumn()) {
        // The sessions the steps above left behind are not part of this: start clean.
        $conn->prepare("DELETE FROM analyst_sessions WHERE analyst_id = ?")->execute([$analystId]);
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ?")->execute([$analystId]);
        // Two browsers: A (kept signed in) and B (not). Each opens a page once so it is recorded.
        [, , $jarA] = $signIn(true);
        [, , $jarB] = $signIn(false);
        [, , $pageA] = req('GET', "$base/tickets/", $jarA);
        req('GET', "$base/tickets/", $jarB);
        preg_match('/name="csrf-token" content="([^"]+)"/', $pageA, $m);
        $csrf = $m[1] ?? '';
        $api = function (string $method, array $jar, ?array $body = null) use ($base, $csrf): array {
            $ch = curl_init("$base/api/myaccount/sessions.php");
            $h = ['Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar)), 'Content-Type: application/json', 'X-CSRF-Token: ' . $csrf];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
            $r = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($r, true) ?: []];
        };
        [$c, $j] = $api('GET', $jarA);
        $devs = $j['devices'] ?? [];
        $thisOne = array_values(array_filter($devs, fn($d) => $d['this_device']));
        $other = array_values(array_filter($devs, fn($d) => !$d['this_device']));
        check('the list has both browsers, A marked as this device and kept signed in', $c === 200 && count($devs) >= 2 && count($thisOne) === 1 && $thisOne[0]['kept_until'] !== null, json_encode($j));
        check('...each with its IP, browser and times', isset($devs[0]['ip_address'], $devs[0]['user_agent'], $devs[0]['signed_in'], $devs[0]['last_seen']));
        [$okB] = $opens($jarB);
        check('CONTROL: browser B opens pages before it is signed out', $okB);
        $bRow = null;
        foreach ($other as $d) { if ($d['kept_until'] === null) $bRow = $d['id']; }
        [$c, $j] = $api('POST', $jarA, ['action' => 'sign_out', 'id' => $bRow]);
        check('A signs B out', $c === 200 && ($j['signed_out'] ?? 0) === 1, "$c " . json_encode($j));
        [$okB, , $d] = $opens($jarB);
        check('...and B is refused at its next click', !$okB, $d);
        [$okA] = $opens($jarA);
        check('...while A carries on', $okA);
        // A third browser, kept signed in, then "sign out of all other devices" from A.
        [, , $jarC] = $signIn(true);
        req('GET', "$base/tickets/", $jarC);
        $keepC = $jarC['fitsm_keep'] ?? '';
        [$c, $j] = $api('POST', $jarA, ['action' => 'sign_out_others']);
        check('Sign out of all other devices', $c === 200 && ($j['signed_out'] ?? 0) >= 1, json_encode($j));
        [$okC, , $d] = $opens($jarC);
        check('...C is refused', !$okC, $d);
        [$okC, , $d] = $opens(['fitsm_keep' => $keepC]);
        check('...and its Keep me signed in cookie no longer signs it back in', !$okC, $d);
        [$okA] = $opens($jarA);
        check('...while A is still signed in', $okA);
        $n = $conn->prepare("SELECT COUNT(*) FROM analyst_login_tokens WHERE analyst_id = ?"); $n->execute([$analystId]);
        check('...and A keeps its own Keep me signed in token', (int)$n->fetchColumn() === 1);
        [$c, $j] = $api('POST', $jarA, ['action' => 'sign_out', 'id' => $thisOne[0]['id']]);
        check('this device cannot sign itself out here (that is Sign out)', ($j['signed_out'] ?? 1) === 0);

        echo "\nGuards: nobody signs out anyone else's devices, unless an administrator\n";
        // A second analyst, NOT an administrator, signed in on browser D.
        $tag2 = $tag . 'b';
        $conn->prepare('INSERT INTO analysts (username, password_hash, full_name, email, is_active, is_admin, can_access_all_tenants, can_access_all_modules) VALUES (?, ?, ?, ?, 1, 0, 1, 1)')
             ->execute([$tag2, password_hash($password, PASSWORD_DEFAULT), 'ZZ Keep Signed In 2', "$tag2@example.test"]);
        $analyst2 = $made2 = (int)$conn->lastInsertId();
        $conn->prepare("UPDATE analysts SET password_changed_datetime = UTC_TIMESTAMP() - INTERVAL 1 DAY WHERE id = ?")->execute([$analyst2]);
        [, , , $c0] = req('GET', "$base/auth/login.php");
        [, , , $c1] = req('POST', "$base/auth/login.php", $c0, ['username' => $tag2, 'password' => $password]);
        $jarD = array_merge($c0, $c1);
        [, , $pageD] = req('GET', "$base/tickets/", $jarD);
        preg_match('/name="csrf-token" content="([^"]+)"/', $pageD, $m2);
        $csrfD = $m2[1] ?? '';
        $rowA = (int)$conn->query("SELECT id FROM analyst_sessions WHERE analyst_id = $analystId AND revoked_datetime IS NULL ORDER BY id DESC LIMIT 1")->fetchColumn();
        $raw = function (string $url, array $jar, ?string $csrfTok, ?array $body) {
            $ch = curl_init($url);
            $h = ['Content-Type: application/json'];
            if ($jar) $h[] = 'Cookie: ' . implode('; ', array_map(fn($k, $v) => "$k=$v", array_keys($jar), $jar));
            if ($csrfTok !== null) $h[] = 'X-CSRF-Token: ' . $csrfTok;
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST', CURLOPT_HTTPHEADER => $h] + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
            $r = (string)curl_exec($ch);
            return [(int)curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($r, true) ?: []];
        };
        [$c] = $raw("$base/api/myaccount/sessions.php", [], null, ['action' => 'sign_out_others']);
        check("not signed in: refused ($c)", $c === 401);
        [$c, $j] = $raw("$base/api/myaccount/sessions.php", $jarD, null, ['action' => 'sign_out_others']);
        check("signed in but without the page's CSRF token (as a link or form on another site would be): refused ($c)", $c >= 400 && empty($j['success']));
        [$c, $j] = $raw("$base/api/myaccount/sessions.php", $jarD, $csrfD, ['action' => 'sign_out', 'id' => $rowA]);
        check("analyst D sending analyst A's device id signs out nothing", ($j['signed_out'] ?? 1) === 0, json_encode($j));
        [$okA] = $opens($jarA);
        check('...and A is still signed in', $okA);
        [$c, $j] = $raw("$base/api/system/analyst_sessions.php?analyst_id=$analystId", $jarD, $csrfD, null);
        check("a non-administrator cannot list another analyst's devices ($c)", $c === 403, json_encode($j));
        [$c, $j] = $raw("$base/api/system/analyst_sessions.php", $jarD, $csrfD, ['analyst_id' => $analystId, 'action' => 'sign_out_all']);
        check("...nor sign them out ($c)", $c === 403);
        [$okA] = $opens($jarA);
        check('...so A is still signed in', $okA);

        // A is an administrator: A signs D out everywhere from System -> Analysts.
        [$c, $j] = $raw("$base/api/system/analyst_sessions.php?analyst_id=$analyst2", $jarA, $csrf, null);
        check('an administrator lists another analyst\'s devices', $c === 200 && count($j['devices'] ?? []) >= 1, json_encode($j));
        [$c, $j] = $raw("$base/api/system/analyst_sessions.php", $jarA, $csrf, ['analyst_id' => $analyst2, 'action' => 'sign_out_all']);
        check('...and signs them out everywhere', $c === 200 && ($j['signed_out'] ?? 0) >= 1, json_encode($j));
        [$okD, , $d] = $opens($jarD);
        check('...D is refused at its next click', !$okD, $d);
        [$okA] = $opens($jarA);
        check('...and the administrator is still signed in', $okA);
        $conn->prepare("DELETE FROM analyst_sessions WHERE analyst_id = ?")->execute([$analyst2]);
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ?")->execute([$analyst2]);
        foreach (['login_attempts', 'audit_log'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE analyst_id = ?")->execute([$analyst2]); } catch (Throwable $e) {} }
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$analyst2]);
        $made2 = 0;

        $conn->prepare("DELETE FROM analyst_sessions WHERE analyst_id = ?")->execute([$analystId]);
    } else {
        echo "  SKIP: no analyst_sessions table - run Database Verification\n";
    }

    echo "\nWithout the tick\n";
    [, , $jar] = $signIn(false);
    check('signing in without the tick sets no cookie', !isset($jar['fitsm_keep']));
} catch (Throwable $e) {
    check('no exception', false, $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
} finally {
    if (!empty($made2)) {   // the second analyst, if a failure left it behind
        foreach (['analyst_sessions', 'analyst_login_tokens', 'login_attempts', 'audit_log'] as $tb) { try { $conn->prepare("DELETE FROM $tb WHERE analyst_id = ?")->execute([$made2]); } catch (Throwable $e) {} }
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$made2]);
    }
    if ($analystId) {
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ?")->execute([$analystId]);
        try { $conn->prepare("DELETE FROM analyst_sessions WHERE analyst_id = ?")->execute([$analystId]); } catch (Throwable $e) {}
        foreach (['login_attempts', 'audit_log'] as $t) { try { $conn->prepare("DELETE FROM $t WHERE analyst_id = ?")->execute([$analystId]); } catch (Throwable $e) {} }
        $conn->prepare("DELETE FROM analysts WHERE id = ?")->execute([$analystId]);
    }
    $setting($was === false ? null : (string)$was);
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
