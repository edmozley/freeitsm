<?php
/**
 * Run database verification from the command line.
 *
 *   php scripts/db_verify_cli.php            report what WOULD change, change nothing
 *   php scripts/db_verify_cli.php --apply    actually apply it
 *   php scripts/db_verify_cli.php --quiet    exit code only, no JSON on stdout
 *
 * PREVIEW IS THE DEFAULT, and applying takes an explicit --apply. It reads
 * oddly for a verification tool until you remember what this one does: it
 * ALTERS and DROPS. A bare invocation is what you get from a typo, a wrong
 * working directory, or a line copied out of a wiki page, and none of those
 * should be enough to change a live schema. Saying --apply takes a second and
 * removes the whole category.
 *
 * WHY THIS EXISTS
 * ---------------
 * Schema verification has only ever been reachable as api/system/db_verify.php —
 * an HTTP endpoint behind an administrator session. That is right for a person
 * clicking a button, and useless for an unattended upgrade: anything that
 * updates FreeITSM without a browser (a Docker image coming up on a new tag, a
 * scripted deploy, an appliance updating itself overnight) has to apply the
 * schema changes that come with the new code, and had no supported way to do it.
 * The alternative people reach for — storing an administrator's password so a
 * script can log in and POST to the machine's own web server — is a far worse
 * thing to have on a server than this file.
 *
 * ⚠️ THIS CREATES, ALTERS **AND DROPS**. TAKE A BACKUP FIRST. It is the same
 * code path as the button, so it carries the same warning: see the comment at
 * the top of api/system/db_verify.php listing the columns it drops. Use
 * --preview to see what a run would do before letting it do it.
 *
 * SECURITY
 * --------
 * CLI ONLY, refused outright over HTTP. Running it already requires a shell
 * account on the server, and anyone with that can reach the database directly
 * with mysql(1) — so this grants nothing that was not already available. It is
 * a door onto the machine's own console, not a new way in from outside.
 *
 * It runs as a REAL administrator that already exists in the analysts table,
 * rather than skipping the check: the endpoint's own guard then applies
 * unchanged, and an install with no administrator at all is left to the
 * unprovisioned path the endpoint already handles. Nothing here weakens the
 * gate on the HTTP endpoint, which is untouched.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command-line only.\n");
}

// JSON goes to stdout and must stay machine-readable, so anything PHP wants to
// say about the run goes to stderr instead of being interleaved into it.
ini_set('display_errors', 'stderr');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

$args  = array_slice($argv, 1);
$apply = in_array('--apply', $args, true);
$quiet = in_array('--quiet', $args, true);

// --preview is accepted and does nothing, because it is what anyone would type
// for the safe mode and being told "unknown option" while the destructive run
// is the default would be the worst possible answer.
$entry = __DIR__ . '/../api/system/'
       . ($apply ? 'db_verify.php' : 'db_verify_preview.php');

if (!is_file($entry)) {
    fwrite(STDERR, "Cannot find " . basename($entry) . ".\n");
    exit(2);
}

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    fwrite(STDERR, "Database connection failed: " . $e->getMessage() . "\n");
    exit(3);
}

// The lowest-numbered administrator, so repeated runs act as the same person
// and the audit trail does not wander. Zero is a legitimate answer on an install
// that has not been set up yet — the endpoint recognises that case itself.
$adminId = 0;
try {
    $adminId = (int)$conn->query(
        "SELECT id FROM analysts WHERE is_admin = 1 ORDER BY id LIMIT 1"
    )->fetchColumn();
} catch (Throwable $e) {
    // No analysts table yet. Genuinely unprovisioned; let the endpoint decide.
}

if ($adminId > 0) {
    // Start the session HERE, before the endpoint does. Its own
    // session_start() then finds one already active and leaves this alone —
    // which is the whole trick, and why nothing has to be written to the
    // session store by hand.
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['analyst_id']   = $adminId;
    $_SESSION['analyst_name'] = 'command line';
}

// Its requires are relative to its own directory ('../../config.php'), so it has
// to be entered from there. config.php and functions.php are already loaded and
// require_once resolves by real path, so nothing is included twice.
chdir(dirname($entry));

// The endpoint calls session_start() on its own first line, and having already
// started one here that emits "Ignoring session_start() because a session is
// already active". It is the mechanism working as intended, but printed on every
// nightly run it reads as a fault, so swallow exactly that message and let every
// other diagnostic through untouched.
set_error_handler(function ($no, $str, $file, $line) {
    if (stripos($str, 'session_start(): Ignoring session_start()') !== false) {
        return true;
    }
    return false;
});

ob_start();
include $entry;
$body = (string)ob_get_clean();

restore_error_handler();

if (!$quiet) {
    echo $body, "\n";
    if (!$apply) {
        fwrite(STDERR, "\nPreview only - nothing was changed. Re-run with --apply to apply it.\n");
    }
}

$decoded = json_decode($body, true);
$ok      = is_array($decoded) && !empty($decoded['success']);

if (!$ok && $quiet) {
    // --quiet still has to say something when it fails, or a cron job that
    // stopped working looks exactly like one that had nothing to do.
    fwrite(STDERR, $body . "\n");
}

exit($ok ? 0 : 1);
