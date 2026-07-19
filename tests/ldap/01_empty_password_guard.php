<?php
/**
 * RFC 4513 unauthenticated bind — the guard that must never regress.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * LDAP defines a bind with a DN and an EMPTY password as an "unauthenticated
 * bind", and many directories answer it with SUCCESS. Without an explicit
 * check, leaving the password box blank would therefore log you in as anyone
 * whose username you can guess.
 *
 * ⚠️ NEITHER TEST RIG REPRODUCES THE DANGEROUS SERVER BEHAVIOUR — both OpenLDAP
 * and Samba AD reject the empty bind themselves. So an end-to-end test against
 * a rig proves NOTHING about the guard: it would pass just as happily with the
 * guard deleted. The wiki claimed for months that such a test existed; it did
 * not, and it could not have meant anything if it had.
 *
 * WHAT THIS TEST ACTUALLY DOES
 * ----------------------------
 * It points the provider at an UNROUTABLE address (TEST-NET-1, RFC 5737) with a
 * short timeout. If the guard runs, ldapAuthenticate() returns immediately with
 * reason 'credentials' — it never opens a socket. If the guard is removed, the
 * call instead spends the connect timeout and comes back with a config/network
 * error, or worse, succeeds against a real directory.
 *
 * Speed IS the assertion: no network call can have happened.
 *
 * Run: php tests/ldap/01_empty_password_guard.php   (no directory required)
 */
$APP = dirname(__DIR__, 2);
require_once $APP . '/includes/ldap.php';

$pass = 0; $fail = 0;
function check(string $label, bool $cond, string $detail = '') {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS  %s\n", $label); }
    else       { $fail++; printf("  FAIL  %s\n        -> %s\n", $label, $detail); }
}

// 192.0.2.0/24 is reserved for documentation and is guaranteed not to route.
// Any attempt to reach it must hang until the timeout — which is the point.
$provider = [
    'id'                 => 0,
    'display_name'       => 'Unroutable (test)',
    'ldap_host'          => '192.0.2.1',
    'ldap_port'          => 389,
    'ldap_encryption'    => 'none',
    'ldap_bind_dn'       => 'cn=svc,dc=example,dc=test',
    'ldap_bind_password' => 'irrelevant',
    'ldap_base_dn'       => 'dc=example,dc=test',
    'ldap_user_filter'   => '(sAMAccountName=%s)',
    'ldap_attr_username' => 'sAMAccountName',
    'ldap_attr_email'    => 'mail',
    'ldap_attr_name'     => 'displayName',
    'ldap_attr_id'       => 'objectGUID',
    'ldap_group_base_dn' => '',
    'ldap_analyst_group' => '',
    'ldap_user_group'    => '',
    'ldap_timeout'       => 5,
    'auto_create_users'  => 1,
    'enabled'            => 1,
    'tenant_id'          => null,
];

if (!ldapExtensionAvailable()) {
    echo "SKIP: the PHP ldap extension is not loaded.\n";
    exit(0);
}

echo "\nEmpty-password guard (RFC 4513 unauthenticated bind)\n";

foreach ([
    'empty string'      => '',
    'NUL byte'          => "\0",
    'NUL-prefixed'      => "\0hunter2",
] as $label => $password) {
    $t0  = microtime(true);
    $res = ldapAuthenticate($provider, 'someone', $password);
    $ms  = (microtime(true) - $t0) * 1000;

    check("refused: $label", $res['ok'] === false, 'ok=' . var_export($res['ok'], true));
    check("  ...as bad credentials, not a config/network error",
          ($res['reason'] ?? '') === 'credentials',
          "reason=" . var_export($res['reason'] ?? null, true));
    // The real assertion: it cannot have touched the network.
    check(sprintf("  ...without any network call (%.1f ms)", $ms), $ms < 250,
          sprintf('took %.1f ms — that is long enough to have tried to connect', $ms));
}

// WHITESPACE IS NOT THE RFC 4513 CASE, and is deliberately NOT guarded.
//
// "   " has non-zero length, so the directory performs a genuine credential
// check and rejects it like any other wrong password. Trimming it here would
// mean refusing to even attempt a password that some directory might
// legitimately hold. This assertion exists so nobody "helpfully" adds a trim()
// later and quietly narrows what users are allowed to have.
echo "\nWhitespace is a REAL password — must reach the directory, not the guard\n";
$t0  = microtime(true);
$res = ldapAuthenticate($provider, 'someone', '   ');
$ms  = (microtime(true) - $t0) * 1000;
check('not short-circuited as "credentials"', ($res['reason'] ?? '') !== 'credentials',
      'the guard is trimming — a whitespace password can no longer be used at all');
check(sprintf('  ...it went to the network (%.0f ms)', $ms), $ms >= 250,
      sprintf('returned in %.1f ms', $ms));

// POSITIVE CONTROL. Without this the block above proves only that the function
// says no to everything: a stub returning false always would score full marks.
echo "\nPositive control — a NON-empty password must get past the guard\n";
$t0  = microtime(true);
$res = ldapAuthenticate($provider, 'someone', 'a-real-password');
$ms  = (microtime(true) - $t0) * 1000;

check('still refused (the host is unroutable, so it must fail)', $res['ok'] === false);
check('  ...but for a CONNECTION reason, not "credentials"',
      ($res['reason'] ?? '') !== 'credentials',
      'reason=credentials — the guard is swallowing real passwords too');
check(sprintf('  ...and it DID try the network (%.0f ms)', $ms), $ms >= 250,
      sprintf('returned in %.1f ms — too fast to have attempted a connection, so the '
            . 'timing assertions above prove nothing', $ms));

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
