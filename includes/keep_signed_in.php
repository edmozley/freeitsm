<?php
/**
 * "Keep me signed in" for analysts (GH #443).
 *
 * WHY. An analyst's sign-in is a PHP session and nothing else, so it ends when the
 * session does: after 24 idle minutes (PHP's session.gc_maxlifetime), when the browser
 * closes (the session cookie has no expiry), and when a Docker container restarts
 * (sessions live in the container's temporary folder). "Trust this device" never
 * helped with any of that - it only skips the one-time code at the NEXT sign-in.
 *
 * WHAT. With System -> Security -> "Keep analysts signed in" set to a number of days,
 * the sign-in page offers "Keep me signed in". Ticking it stores a token: a random
 * selector (looked up) and a random validator (kept only as a SHA-256 hash), in an
 * HttpOnly, SameSite=Lax cookie that is Secure on HTTPS. When a request arrives with
 * no session and that cookie, keepSignedInAutoRestore() (run from includes/functions.php,
 * which every page and endpoint loads before it checks the session) signs the analyst
 * back in and gives the token a new validator.
 *
 * TRAP: the token is only as strong as the checks made each time it is used, so ALL of
 * them live in keepSignedInRestore() and none of them is "remembered":
 *   - the setting is read now: Off refuses every token, and a shorter period cuts tokens
 *     made under a longer one (created_datetime), as the trust duration does (#429);
 *   - the analyst must still be active, not locked, not owing a password change, and must
 *     not have changed their password since the token was made - so a password change or
 *     a reset by an administrator ends every "keep me signed in" without a hook at each
 *     place a password changes;
 *   - a known selector with the wrong validator means the cookie was copied and one copy
 *     has already been used: every token of that analyst is deleted.
 * Two-factor: a token is only ever made after the whole sign-in, code included, so using
 * it counts as having passed the code.
 *
 * Not (yet) for single sign-on, which has its own session with the identity provider, or
 * for the self-service portal.
 *
 * @wiki Keep-Me-Signed-In
 */

const KEEP_SIGNED_IN_COOKIE  = 'fitsm_keep';
/** The choices on System -> Security. 0 = Off, today's behaviour. */
const KEEP_SIGNED_IN_CHOICES = [0, 1, 7, 14, 30];

/** The setting now, in days (0 = Off). Anything not on the list reads as Off. */
function keepSignedInDays(PDO $conn): int
{
    try {
        $s = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'keep_signed_in_days'");
        $s->execute();
        $d = (int)$s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
    return in_array($d, KEEP_SIGNED_IN_CHOICES, true) ? $d : 0;
}

/** Has Database Verification created the table yet? Nothing happens until it has. */
function keepSignedInReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $ready = (bool)$conn->query("SHOW TABLES LIKE 'analyst_login_tokens'")->fetchColumn(); }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/**
 * Sends (or, with $value '', removes) the cookie.
 *
 * @param string $value  "selector:validator", or '' to delete it
 * @param int $expires  a Unix time; ignored when deleting
 */
function keepSignedInSetCookie(string $value, int $expires = 0): void
{
    if (headers_sent()) return;
    setcookie(KEEP_SIGNED_IN_COOKIE, $value, [
        'expires'  => $value === '' ? time() - 3600 : $expires,
        'path'     => '/',
        'secure'   => function_exists('requestIsHttps') ? requestIsHttps() : false,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/**
 * After a complete sign-in on which "Keep me signed in" was ticked: make the token and
 * send the cookie. Does nothing while the setting is Off or before Database Verification.
 *
 * @param int $analystId  the analyst who has just signed in
 */
function keepSignedInIssue(PDO $conn, int $analystId): void
{
    $days = keepSignedInDays($conn);
    if ($days <= 0 || $analystId <= 0 || !keepSignedInReady($conn)) return;
    $selector  = bin2hex(random_bytes(12));
    $validator = random_bytes(32);
    $conn->prepare("INSERT INTO analyst_login_tokens (analyst_id, selector, validator_hash, user_agent, ip_address, created_datetime, last_used_datetime, expires_datetime)
                    VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY))")
         ->execute([$analystId, $selector, hash('sha256', $validator),
                    substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $days]);
    // Signed-in devices ties this session's row to the token, so signing the device out
    // ends both (includes/analyst_sessions.php).
    $_SESSION['kept_token_id'] = (int)$conn->lastInsertId();
    keepSignedInSetCookie($selector . ':' . bin2hex($validator), time() + $days * 86400);
    // Expired ones of this analyst go while we are here.
    $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND expires_datetime < UTC_TIMESTAMP()")->execute([$analystId]);
}

/**
 * The cookie split into selector and validator, or null when it is not one of ours.
 *
 * @return array{0:string,1:string}|null
 */
function keepSignedInParseCookie(): ?array
{
    $raw = (string)($_COOKIE[KEEP_SIGNED_IN_COOKIE] ?? '');
    if (!preg_match('/^([0-9a-f]{24}):([0-9a-f]{64})$/', $raw, $m)) return null;
    return [$m[1], hex2bin($m[2])];
}

/**
 * Signs the analyst back in from the cookie, if every check passes (see the TRAP at the
 * top). Writes the analyst's session the way a sign-in does and rotates the validator.
 *
 * @return bool true when the session is now signed in
 */
function keepSignedInRestore(PDO $conn): bool
{
    $parts = keepSignedInParseCookie();
    if ($parts === null) { keepSignedInSetCookie(''); return false; }
    [$selector, $validator] = $parts;
    $days = keepSignedInDays($conn);
    if ($days <= 0 || !keepSignedInReady($conn)) { keepSignedInSetCookie(''); return false; }

    $s = $conn->prepare("SELECT t.id, t.analyst_id, t.validator_hash, t.previous_hash, t.rotated_datetime, t.created_datetime,
                                a.username, a.full_name, a.email, a.is_active, a.locked_until,
                                a.must_change_password, a.password_changed_datetime
                           FROM analyst_login_tokens t JOIN analysts a ON a.id = t.analyst_id
                          WHERE t.selector = ? AND t.expires_datetime > UTC_TIMESTAMP()
                            AND t.created_datetime > DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? DAY)");
    $s->execute([$selector, $days]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if (!$row) { keepSignedInSetCookie(''); return false; }

    $given = hash('sha256', $validator);
    // TRAP: a page fires several requests at once. After the session has gone, two can
    // arrive with the same cookie: the first rotates the validator, the second still
    // carries the old one. Within two minutes of a rotation the old validator is still
    // accepted (and not rotated again - the first response already set the new cookie);
    // without this, every busy page would look like a stolen cookie and sign you out.
    $justRotated = !empty($row['previous_hash']) && !empty($row['rotated_datetime'])
        && strtotime($row['rotated_datetime'] . ' UTC') > time() - 120
        && hash_equals((string)$row['previous_hash'], $given);
    if (!$justRotated && !hash_equals((string)$row['validator_hash'], $given)) {
        // A real selector with a wrong validator: the cookie was copied and a copy was
        // used first. Nothing of this analyst's can be trusted any more.
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ?")->execute([(int)$row['analyst_id']]);
        error_log('keep signed in: a used token came back for analyst ' . (int)$row['analyst_id'] . ' - all of their tokens removed');
        keepSignedInSetCookie('');
        return false;
    }
    $locked  = !empty($row['locked_until']) && strtotime($row['locked_until'] . ' UTC') > time();
    $changed = !empty($row['password_changed_datetime']) && strtotime($row['password_changed_datetime'] . ' UTC') > strtotime($row['created_datetime'] . ' UTC');
    if ((int)$row['is_active'] !== 1 || $locked || !empty($row['must_change_password']) || $changed) {
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE id = ?")->execute([(int)$row['id']]);
        keepSignedInSetCookie('');
        return false;
    }

    // Signed in, as a sign-in does it (auth/login.php): a new session id first.
    sessionPromoteToAuthenticated();
    $_SESSION['analyst_id']       = (int)$row['analyst_id'];
    $_SESSION['analyst_username'] = $row['username'];
    $_SESSION['analyst_name']     = $row['full_name'];
    $_SESSION['analyst_email']    = $row['email'];
    $_SESSION['allowed_modules']  = getAnalystAllowedModules($conn, (int)$row['analyst_id']);
    $_SESSION['kept_signed_in']   = true;
    $_SESSION['kept_token_id']    = (int)$row['id'];   // see analyst_sessions.php

    if ($justRotated) return true;   // the request racing the one that rotated it

    // A new validator every time: a stolen copy works at most until the owner's next visit.
    $newValidator = random_bytes(32);
    $conn->prepare("UPDATE analyst_login_tokens SET previous_hash = validator_hash, validator_hash = ?, rotated_datetime = UTC_TIMESTAMP(),
                           last_used_datetime = UTC_TIMESTAMP(), ip_address = ? WHERE id = ?")
         ->execute([hash('sha256', $newValidator), substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), (int)$row['id']]);
    $exp = $conn->prepare("SELECT expires_datetime FROM analyst_login_tokens WHERE id = ?");
    $exp->execute([(int)$row['id']]);
    keepSignedInSetCookie($selector . ':' . bin2hex($newValidator), (int)strtotime((string)$exp->fetchColumn() . ' UTC'));
    return true;
}

/**
 * Run on every request from includes/functions.php: no analyst session, but the cookie
 * is there - try it. Opens the session for writing if the page opened it read-and-close,
 * and closes it again. Never lets a failure reach the page.
 */
function keepSignedInAutoRestore(): void
{
    if (PHP_SAPI === 'cli' || empty($_COOKIE[KEEP_SIGNED_IN_COOKIE]) || !empty($_SESSION['analyst_id']) || headers_sent()) return;
    static $tried = false;
    if ($tried) return;
    $tried = true;
    try {
        $wasClosed = session_status() !== PHP_SESSION_ACTIVE;
        if ($wasClosed) session_start();
        if (empty($_SESSION['analyst_id'])) keepSignedInRestore(connectToDatabase());
        if ($wasClosed) session_write_close();
    } catch (Throwable $e) {
        error_log('keep signed in: restore failed - ' . $e->getMessage());
    }
}

/** Sign out: this browser's token goes, and the cookie with it. */
function keepSignedInForget(PDO $conn): void
{
    $parts = keepSignedInParseCookie();
    if ($parts !== null && keepSignedInReady($conn)) {
        try { $conn->prepare("DELETE FROM analyst_login_tokens WHERE selector = ?")->execute([$parts[0]]); } catch (Throwable $e) {}
    }
    keepSignedInSetCookie('');
}

/**
 * Every device this analyst is kept signed in on (My Account), newest use first.
 *
 * @return list<array{id:int,user_agent:string,ip_address:string,created:string,last_used:string,expires:string,this_device:bool}>
 */
function keepSignedInList(PDO $conn, int $analystId): array
{
    if (!keepSignedInReady($conn)) return [];
    $mine = keepSignedInParseCookie();
    $s = $conn->prepare("SELECT id, selector, user_agent, ip_address, created_datetime, last_used_datetime, expires_datetime
                           FROM analyst_login_tokens WHERE analyst_id = ? AND expires_datetime > UTC_TIMESTAMP()
                          ORDER BY last_used_datetime DESC");
    $s->execute([$analystId]);
    $out = [];
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'user_agent' => (string)$r['user_agent'], 'ip_address' => (string)$r['ip_address'],
                  'created' => (string)$r['created_datetime'], 'last_used' => (string)$r['last_used_datetime'],
                  'expires' => (string)$r['expires_datetime'], 'this_device' => $mine !== null && hash_equals((string)$r['selector'], $mine[0])];
    }
    return $out;
}

/**
 * Stop keeping this analyst signed in on one device ($tokenId) or on all of them (null).
 *
 * @param ?int $tokenId  one token of THEIRS, or null for every one
 * @return int how many were removed
 */
function keepSignedInRevoke(PDO $conn, int $analystId, ?int $tokenId = null): int
{
    if (!keepSignedInReady($conn)) return 0;
    if ($tokenId === null) {
        $s = $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ?");
        $s->execute([$analystId]);
    } else {
        $s = $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND id = ?");
        $s->execute([$analystId, $tokenId]);
    }
    return $s->rowCount();
}
