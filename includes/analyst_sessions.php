<?php
/**
 * Signed-in devices (GH #443, Ed 28 Nov 2026: "a bit like Netflix") - every browser an
 * analyst is signed in on, and signing any of them out.
 *
 * Each signed-in analyst session gets one row in `analyst_sessions` the first time it
 * is seen (whatever signed it in: password, code, single sign-on, Keep me signed in).
 * analystSessionTrack(), run on every request from includes/functions.php, then:
 *   - refuses the session if its row has been signed out (revoked_datetime) - the same
 *     way analyst_active_gate.php ends a deactivated analyst's session, on the next
 *     request, wherever that browser is;
 *   - moves last_seen_datetime on, at most every SESSION_SEEN_EVERY_MINUTES.
 *
 * TRAP: signing a device out must also end its "Keep me signed in" token
 *   (kept_token_id), or the very next request would sign it straight back in from the
 *   cookie. analystSessionRevoke() deletes both together.
 * TRAP: a missing row is NOT a sign-out - it is a session from before this existed, or
 *   one whose row was tidied away; it is simply recorded again. Only revoked_datetime
 *   signs out, so a database problem can never sign everybody out (as in
 *   analyst_active_gate.php, a failed lookup is not evidence of anything).
 *
 * @wiki Keep-Me-Signed-In#signed-in-devices
 */

/** last_seen_datetime is written at most this often per session. */
const SESSION_SEEN_EVERY_MINUTES = 5;

/** Has Database Verification created the table yet? */
function analystSessionsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $ready = (bool)$conn->query("SHOW TABLES LIKE 'analyst_sessions'")->fetchColumn(); }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/**
 * Records this session the first time it is seen signed in, signs it out if its row
 * was signed out from another device, and keeps last_seen_datetime current.
 * Never lets a failure reach the page.
 */
function analystSessionTrack(): void
{
    if (PHP_SAPI === 'cli' || empty($_SESSION['analyst_id']) || !defined('DB_SERVER') || headers_sent()) return;
    $script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (in_array($script, ['login.php', 'logout.php', 'analyst_logout.php'], true)) return;
    try {
        $conn = connectToDatabase();
        if (!analystSessionsReady($conn)) return;
        $analystId = (int)$_SESSION['analyst_id'];
        $rowId = (int)($_SESSION['session_row_id'] ?? 0);
        if ($rowId > 0) {
            $s = $conn->prepare("SELECT revoked_datetime, last_seen_datetime FROM analyst_sessions WHERE id = ? AND analyst_id = ?");
            $s->execute([$rowId, $analystId]);
            $row = $s->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['revoked_datetime'] !== null) {
                analystSessionEndHere();   // signed out from another device: exits
            }
            if ($row) {
                if (strtotime($row['last_seen_datetime'] . ' UTC') < time() - SESSION_SEEN_EVERY_MINUTES * 60) {
                    $conn->prepare("UPDATE analyst_sessions SET last_seen_datetime = UTC_TIMESTAMP(), ip_address = ? WHERE id = ?")
                         ->execute([substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), $rowId]);
                }
                return;
            }
            // No row (see the TRAP at the top): record it again below.
        }
        analystSessionRegister($conn, $analystId);
    } catch (Throwable $e) {
        error_log('analyst sessions: ' . $e->getMessage());
    }
}

/**
 * Inserts this session's row and remembers its id in the session. Opens the session
 * for writing when the page opened it read-and-close.
 */
function analystSessionRegister(PDO $conn, int $analystId): void
{
    $keptTokenId = (int)($_SESSION['kept_token_id'] ?? 0) ?: null;
    $how = !empty($_SESSION['kept_signed_in']) ? 'kept' : (!empty($_SESSION['sso_provider_id']) ? 'sso' : 'sign_in');
    $conn->prepare("INSERT INTO analyst_sessions (analyst_id, ip_address, user_agent, signed_in_how, kept_token_id, signed_in_datetime, last_seen_datetime)
                    VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
         ->execute([$analystId, substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500), $how, $keptTokenId]);
    $id = (int)$conn->lastInsertId();
    // A browser kept signed in leaves one row per session it has had; only the newest
    // is that browser now - the older ones are sessions that have already ended.
    if ($keptTokenId !== null) {
        $conn->prepare("DELETE FROM analyst_sessions WHERE kept_token_id = ? AND id <> ? AND revoked_datetime IS NULL")->execute([$keptTokenId, $id]);
    }
    // Tidy: this analyst's rows nobody has used for 30 days, or signed out over a day ago.
    $conn->prepare("DELETE FROM analyst_sessions WHERE analyst_id = ? AND (last_seen_datetime < UTC_TIMESTAMP() - INTERVAL 30 DAY
                    OR revoked_datetime < UTC_TIMESTAMP() - INTERVAL 1 DAY)")->execute([$analystId]);
    $wasClosed = session_status() !== PHP_SESSION_ACTIVE;
    if ($wasClosed) @session_start();
    $_SESSION['session_row_id'] = $id;
    if ($wasClosed && session_status() === PHP_SESSION_ACTIVE) session_write_close();
}

/** This browser was signed out from another device: end the session and stop the request. */
function analystSessionEndHere(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) @session_start();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    if (function_exists('keepSignedInSetCookie')) keepSignedInSetCookie('');   // its token is already gone
    $isApi = str_contains((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/')
          || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
          || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    if ($isApi) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    header('Location: ' . (defined('BASE_URL') ? BASE_URL : '/') . 'auth/login.php');
    exit;
}

/**
 * The analyst's devices for the list: sessions not signed out that are still open
 * (seen within PHP's idle limit) or can come back (a valid Keep me signed in token).
 *
 * @return list<array>
 */
function analystSessionList(PDO $conn, int $analystId): array
{
    if (!analystSessionsReady($conn)) return [];
    $idleSeconds = max(600, (int)ini_get('session.gc_maxlifetime'));
    $keep = function_exists('keepSignedInReady') && keepSignedInReady($conn);
    $s = $conn->prepare("SELECT s.id, s.ip_address, s.user_agent, s.signed_in_how, s.signed_in_datetime, s.last_seen_datetime, s.kept_token_id"
        . ($keep ? ", t.expires_datetime AS kept_until" : ", NULL AS kept_until") . "
          FROM analyst_sessions s"
        . ($keep ? " LEFT JOIN analyst_login_tokens t ON t.id = s.kept_token_id AND t.expires_datetime > UTC_TIMESTAMP()" : "") . "
         WHERE s.analyst_id = ? AND s.revoked_datetime IS NULL
           AND (s.last_seen_datetime > UTC_TIMESTAMP() - INTERVAL ? SECOND" . ($keep ? " OR t.id IS NOT NULL" : "") . ")
         ORDER BY s.last_seen_datetime DESC");
    $s->execute([$analystId, $idleSeconds]);
    $mine = (int)($_SESSION['session_row_id'] ?? 0);
    return array_map(fn($r) => [
        'id' => (int)$r['id'], 'ip_address' => (string)$r['ip_address'], 'user_agent' => (string)$r['user_agent'],
        'how' => (string)$r['signed_in_how'], 'signed_in' => (string)$r['signed_in_datetime'], 'last_seen' => (string)$r['last_seen_datetime'],
        'kept_until' => $r['kept_until'] !== null ? (string)$r['kept_until'] : null, 'this_device' => (int)$r['id'] === $mine,
    ], $s->fetchAll(PDO::FETCH_ASSOC));
}

/**
 * Signs out one of the analyst's devices ($rowId), or every one but this ($rowId null),
 * together with its Keep me signed in token (see the TRAP at the top). Never this
 * session itself - that is what Sign out is for.
 *
 * @param ?int $rowId  one of THEIR rows, or null for all the others
 * @return int how many were signed out
 */
function analystSessionRevoke(PDO $conn, int $analystId, ?int $rowId): int
{
    if (!analystSessionsReady($conn)) return 0;
    $mine = (int)($_SESSION['session_row_id'] ?? 0);
    $where = $rowId === null ? "analyst_id = ? AND id <> ? AND revoked_datetime IS NULL" : "analyst_id = ? AND id = ? AND id <> ? AND revoked_datetime IS NULL";
    $args  = $rowId === null ? [$analystId, $mine] : [$analystId, $rowId, $mine];
    $t = $conn->prepare("SELECT kept_token_id FROM analyst_sessions WHERE $where AND kept_token_id IS NOT NULL");
    $t->execute($args);
    $tokens = array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN));
    $u = $conn->prepare("UPDATE analyst_sessions SET revoked_datetime = UTC_TIMESTAMP() WHERE $where");
    $u->execute($args);
    if ($tokens && function_exists('keepSignedInReady') && keepSignedInReady($conn)) {
        $in = implode(',', array_fill(0, count($tokens), '?'));
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND id IN ($in)")->execute(array_merge([$analystId], $tokens));
    }
    if ($rowId === null && function_exists('keepSignedInReady') && keepSignedInReady($conn)) {
        // A token with no session row at the moment (its browser is closed) is a device too.
        $keepMine = (int)($_SESSION['kept_token_id'] ?? 0);
        $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND id <> ?")->execute([$analystId, $keepMine]);
    }
    return $u->rowCount();
}

/**
 * An administrator signs out one of another analyst's devices ($rowId) or all of them
 * (null), with their Keep me signed in tokens. Only api/system/analyst_sessions.php,
 * behind requireAdminJson(), calls this. The caller's OWN current session is never
 * included, even when the administrator is looking at themselves.
 *
 * @param int $targetId  the analyst whose devices are signed out
 * @param ?int $rowId    one of the target's rows, or null for every one
 * @return int how many were signed out
 */
function analystSessionRevokeAny(PDO $conn, int $targetId, ?int $rowId): int
{
    if (!analystSessionsReady($conn)) return 0;
    $mine = (int)($_SESSION['session_row_id'] ?? 0);
    $where = "analyst_id = ? AND id <> ? AND revoked_datetime IS NULL" . ($rowId !== null ? " AND id = ?" : "");
    $args  = $rowId !== null ? [$targetId, $mine, $rowId] : [$targetId, $mine];
    $t = $conn->prepare("SELECT kept_token_id FROM analyst_sessions WHERE $where AND kept_token_id IS NOT NULL");
    $t->execute($args);
    $tokens = array_map('intval', $t->fetchAll(PDO::FETCH_COLUMN));
    $u = $conn->prepare("UPDATE analyst_sessions SET revoked_datetime = UTC_TIMESTAMP() WHERE $where");
    $u->execute($args);
    if (function_exists('keepSignedInReady') && keepSignedInReady($conn)) {
        if ($rowId === null) {
            // Everywhere: every token too, except the administrator's own browser's.
            $keepMine = $targetId === (int)($_SESSION['analyst_id'] ?? 0) ? (int)($_SESSION['kept_token_id'] ?? 0) : 0;
            $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND id <> ?")->execute([$targetId, $keepMine]);
        } elseif ($tokens) {
            $in = implode(',', array_fill(0, count($tokens), '?'));
            $conn->prepare("DELETE FROM analyst_login_tokens WHERE analyst_id = ? AND id IN ($in)")->execute(array_merge([$targetId], $tokens));
        }
    }
    return $u->rowCount();
}
