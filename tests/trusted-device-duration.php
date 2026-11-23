<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * #429 - Security -> Trusted Device -> Trust duration is honoured at sign-in.
 *
 * A trusted device skips the OTP step only while BOTH hold:
 *   - its own expires_datetime (fixed when it was trusted), and
 *   - today's trust duration, counted from when it was trusted.
 * Before the fix only the first was checked, so setting the duration to 0
 * ("disable entirely") or shortening it changed nothing for devices already trusted.
 *
 * Signs in over HTTP as a temporary ZZ analyst (MFA on, trust opted in) with a
 * trusted device made 10 days ago that expires in 20 days, under durations 30, 5
 * and 0. The analyst, its device rows and the setting are put back afterwards.
 *
 * Run against a TEST install only:
 *   FREEITSM_TEST_URL=http://localhost/freeitsm-prtest/ php tests/trusted-device-duration.php
 */

$base = rtrim((string)getenv('FREEITSM_TEST_URL'), '/');
if ($base === '') { fwrite(STDERR, "Set FREEITSM_TEST_URL to a TEST install.\n"); exit(2); }

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
$conn = connectToDatabase();

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-62s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-62s %s\n", $label, $detail); }
}

/** Posts the sign-in form with the device cookie; returns [status, Location]. */
function signIn(string $base, string $user, string $pw, string $cookie): array {
    $ch = curl_init($base . '/auth/login.php');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['username' => $user, 'password' => $pw]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIE         => 'trusted_device=' . $cookie,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $resp = (string)curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $loc = preg_match('/^Location:\s*(\S+)/mi', $resp, $m) ? $m[1] : '';
    return [$code, $loc, $resp];
}

$old = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'trusted_device_days'")->fetchColumn();
$setDays = function (?string $v) use ($conn) {
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES ('trusted_device_days', ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$v]);
};

$user = 'zz_trust_' . bin2hex(random_bytes(4));
$pw   = 'Zz-' . bin2hex(random_bytes(12));
$id   = 0;

try {
    $conn->prepare("INSERT INTO analysts (username, password_hash, full_name, email, is_active, totp_enabled, trust_device_enabled,
                                          password_changed_datetime)
                    VALUES (?, ?, 'ZZ Trusted Device Test', ?, 1, 1, 1, UTC_TIMESTAMP())")
         ->execute([$user, password_hash($pw, PASSWORD_DEFAULT), $user . '@example.com']);
    $id = (int)$conn->lastInsertId();

    $raw = random_bytes(64);
    $conn->prepare("INSERT INTO trusted_devices (analyst_id, device_token_hash, created_datetime, expires_datetime)
                    VALUES (?, ?, DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 DAY), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 20 DAY))")
         ->execute([$id, hash('sha256', $raw)]);
    $cookie = bin2hex($raw);

    // Trusted = signed straight in (302 away from the sign-in page); not trusted = the OTP step.
    $trusted = fn(array $r) => $r[0] === 302 && stripos($r[1], 'login.php') === false;

    echo "\nTrusted device made 10 days ago, expiring in 20\n";

    $setDays('30');
    $r = signIn($base, $user, $pw, $cookie);
    ok('duration 30 days: OTP skipped', $trusted($r), "HTTP {$r[0]} {$r[1]}");

    $setDays('5');
    $r = signIn($base, $user, $pw, $cookie);
    ok('duration cut to 5 days: OTP asked for again', !$trusted($r), "HTTP {$r[0]} {$r[1]}");

    $setDays('0');
    $r = signIn($base, $user, $pw, $cookie);
    ok('duration 0 (feature off): OTP asked for again', !$trusted($r), "HTTP {$r[0]} {$r[1]}");

    $setDays('30');
    $r = signIn($base, $user, $pw, 'not-hex');
    ok('a malformed cookie: OTP asked for, no crash', !$trusted($r) && $r[0] !== 500, "HTTP {$r[0]}");

    $r = signIn($base, $user, $pw, $cookie);
    ok('back to 30 days: OTP skipped again (control)', $trusted($r), "HTTP {$r[0]} {$r[1]}");
} finally {
    $setDays($old === false ? null : (string)$old);
    if ($id) {
        foreach (['trusted_devices' => 'analyst_id', 'login_attempts' => 'analyst_id', 'analysts' => 'id'] as $t => $c) {
            try { $conn->prepare("DELETE FROM `$t` WHERE `$c` = ?")->execute([$id]); } catch (Exception $e) { /* table may not exist */ }
        }
    }
}

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
