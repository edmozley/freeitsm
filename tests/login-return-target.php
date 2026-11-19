<?php
/* 🔴 NEVER OVER THE WEB. See tests/README.md. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

/**
 * After signing in, back to the page that sent you to the sign-in screen
 * (includes/login_return.php).
 *
 * 1. loginReturnSanitize() accepts paths on this install and refuses everything that
 *    would make the sign-in page an open redirect.
 * 2. Over HTTP (FREEITSM_TEST_URL set, e.g. http://localhost:8089/): a signed-out GET
 *    of a module page is sent to auth/login.php?return=<that page>; a JSON request
 *    and a sign-in page are not.
 *
 * Run: php tests/login-return-target.php
 *      FREEITSM_TEST_URL=http://localhost:8089/ php tests/login-return-target.php
 */

if (!defined('BASE_URL')) define('BASE_URL', '/');
require_once dirname(__DIR__) . '/includes/login_return.php';

$pass = 0; $fail = 0;
function ok(string $label, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; printf("  PASS %-60s %s\n", $label, $detail); }
    else       { $fail++; printf("  FAIL %-60s %s\n", $label, $detail); }
}

echo "\n1. loginReturnSanitize()\n";
$accept = [
    '/tickets/',
    '/tickets/?ticket=12',
    '/change-management/table.php',
    '/change-management/?change=5&tab=notes',
    '/index.php',
];
foreach ($accept as $t) ok("accepts $t", loginReturnSanitize($t) === $t);

$refuse = [
    ''                                   => 'empty',
    'tickets/'                           => 'relative',
    '//other.example/x'                  => 'protocol-relative',
    '/\\other.example/x'                 => 'backslash',
    'https://other.example/'             => 'absolute URL',
    '/%2F%2Fother.example/'              => 'encoded //',
    "/tickets/\r\nX-Extra: 1"            => 'control characters',
    '/tickets/../auth/login.php'         => 'dot-dot',
    '/tickets/%2e%2e/api/x.php'          => 'encoded dot-dot',
    '/auth/login.php'                    => 'sign-in page (loop)',
    '/auth/analyst_logout.php'           => 'sign-out',
    '/api/tickets/get.php'               => 'JSON endpoint',
    '/self-service/index.php'            => 'portal',
    '/' . str_repeat('a', 3000)          => 'too long',
    '/AUTH/login.php'                    => 'sign-in page, other case',
    '/API/tickets/get.php'               => 'JSON endpoint, other case',
    // Root aliases .htaccess rewrites into auth/: a target of one would sign the
    // analyst straight out again after every sign-in.
    '/analyst_logout.php'                => 'alias analyst_logout.php',
    '/logout'                            => 'alias logout',
    '/logout.php'                        => 'alias logout.php',
    '/login'                             => 'alias login',
    '/login.php'                         => 'alias login.php',
    '/Force_Password_Change.php'         => 'alias force_password_change.php',
    '/forgot-password'                   => 'alias forgot-password',
];
foreach ($refuse as $t => $why) ok("refuses $why", loginReturnSanitize($t) === null);
ok('refuses a non-string', loginReturnSanitize(['/tickets/']) === null);

echo "\n1b. Installed in a subfolder (/helpdesk/)\n";
ok('accepts /helpdesk/tickets/', loginReturnSanitize('/helpdesk/tickets/', '/helpdesk/') === '/helpdesk/tickets/');
ok('refuses a path outside the install', loginReturnSanitize('/tickets/', '/helpdesk/') === null);
ok('refuses /helpdesk/analyst_logout.php', loginReturnSanitize('/helpdesk/analyst_logout.php', '/helpdesk/') === null);
ok('refuses /helpdesk/auth/login.php', loginReturnSanitize('/helpdesk/auth/login.php', '/helpdesk/') === null);

echo "\n2. loginReturnTake() uses the target once\n";
$_SESSION = [];
loginReturnRemember('/tickets/?ticket=7');
ok('remembered', ($_SESSION[LOGIN_RETURN_SESSION_KEY] ?? null) === '/tickets/?ticket=7');
ok('first take returns it', loginReturnTake() === '/tickets/?ticket=7');
ok('second take is the landing page', loginReturnTake() === '/index.php');
loginReturnRemember('//other.example/');
ok('an unsafe target is not remembered', !isset($_SESSION[LOGIN_RETURN_SESSION_KEY]));
$_SESSION[LOGIN_RETURN_SESSION_KEY] = 'https://other.example/';   // planted some other way
ok('an unsafe stored target is not used', loginReturnTake() === '/index.php');

$base = getenv('FREEITSM_TEST_URL');
if ($base) {
    $base = rtrim($base, '/') . '/';
    echo "\n3. Over HTTP at $base\n";

    /** The Location header of one request, without following it. */
    $location = function (string $path, array $headers = []) use ($base): string {
        $ctx = stream_context_create(['http' => [
            'follow_location' => 0, 'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ]]);
        @file_get_contents($base . $path, false, $ctx);
        foreach ($http_response_header ?? [] as $h) {
            if (stripos($h, 'Location:') === 0) return trim(substr($h, 9));
        }
        return '';
    };
    $basePath = parse_url($base, PHP_URL_PATH) ?: '/';

    $loc = $location('change-management/table.php');
    ok('module page -> login with return', str_ends_with($loc, 'auth/login.php?return=' . rawurlencode($basePath . 'change-management/table.php')), $loc);

    $loc = $location('tickets/?ticket=12&x=a%20b');
    $q = []; parse_str((string)parse_url($loc, PHP_URL_QUERY), $q);
    ok('query string carried', ($q['return'] ?? '') === $basePath . 'tickets/?ticket=12&x=a%20b', $loc);

    $loc = $location('change-management/table.php', ['Accept: application/json']);
    ok('JSON request gets no return', !str_contains($loc, 'return='), $loc ?: '(no redirect)');

    $loc = $location('auth/force_password_change.php');
    ok('auth page gets no return', !str_contains($loc, 'return='), $loc);

    // The sign-out loop: signing out must not make sign-out the next destination.
    foreach (['analyst_logout.php', 'logout', 'auth/analyst_logout.php'] as $p) {
        $loc = $location($p);
        ok("sign-out at /$p gets no return", !str_contains($loc, 'return='), $loc ?: '(none)');
    }

    $loc = $location('');
    ok('the landing page keeps a plain sign-in URL', !str_contains($loc, 'return='), $loc ?: '(none)');

    // Negative control: the hook only rewrites a redirect that is already there.
    $loc = $location('auth/login.php');
    ok('sign-in page itself does not redirect', $loc === '', $loc ?: '(none)');
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail ? 1 : 0);
