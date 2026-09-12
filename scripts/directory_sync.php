<?php
/**
 * Run a directory sync from the command line.
 *
 *   php scripts/directory_sync.php --provider=1 --preview
 *   php scripts/directory_sync.php --provider=1
 *   php scripts/directory_sync.php --all
 *
 * Preview writes nothing and is the sensible first thing to run against any
 * directory you have not synced before.
 *
 * This is also what a scheduled task calls. It is deliberately a thin wrapper:
 * everything that decides anything lives in includes/directory_sync.php, so the
 * screen and the cron job cannot drift apart.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script is command line only.\n");
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/directory_sync.php';

$opts     = getopt('', ['provider::', 'all', 'preview', 'quiet']);
$preview  = isset($opts['preview']);
$quiet    = isset($opts['quiet']);
$mode     = $preview ? 'preview' : 'live';

$conn = connectToDatabase();

$providers = [];
if (isset($opts['all'])) {
    // 🔴 `--all` has NO protocol filter, and once a third protocol existed that
    // became a real bug rather than a tidiness point: an OIDC or CardDAV row
    // with sync_enabled = 1 was selected here and handed to directorySyncRun(),
    // which opens an LDAP connection to it. The fix is the protocol filter plus
    // the routing in the loop below — a scheduled task must not depend on
    // nobody having ticked the wrong box.
    $providers = $conn->query(
        "SELECT * FROM auth_providers
          WHERE enabled = 1 AND sync_enabled = 1 AND protocol IN ('ldap', 'carddav')
       ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
} elseif (!empty($opts['provider'])) {
    $s = $conn->prepare("SELECT * FROM auth_providers WHERE id = ?");
    $s->execute([(int)$opts['provider']]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    if ($row) $providers = [$row];
} else {
    exit("Usage: php scripts/directory_sync.php --provider=<id> [--preview]\n"
       . "       php scripts/directory_sync.php --all [--preview]\n");
}

if (!$providers) {
    exit("No matching provider with sync enabled.\n");
}

require_once __DIR__ . '/../includes/encryption.php';

$exit = 0;
foreach ($providers as $p) {
    // ⚠️ Only the bind password is encrypted on a provider, and decryptValue is
    // what unwraps it. decryptMailboxRow() is for target_mailboxes and knows
    // nothing about this column, so it left the password as the literal string
    // "ENC:…" and the bind failed with "Invalid credentials" — which reads like
    // a wrong password rather than an un-decrypted one.
    $p['ldap_bind_password'] = decryptValue($p['ldap_bind_password'] ?? '');

    if (!$quiet) {
        echo str_repeat('=', 70) . "\n";
        echo ($preview ? 'PREVIEW' : 'SYNC') . ' — ' . ($p['display_name'] ?: ('provider ' . $p['id']))
           . ' [' . $p['protocol'] . ']' . "\n";
        echo str_repeat('=', 70) . "\n";
    }

    // The protocol picks the engine. A named --provider can be either kind, so
    // this routes rather than assuming — and an OIDC id asked for explicitly is
    // refused with a sentence instead of an LDAP connection error.
    if ($p['protocol'] === 'carddav') {
        require_once __DIR__ . '/../includes/carddav_sync.php';
        $run = cardDavSyncRun($conn, $p, $mode, null);
    } elseif ($p['protocol'] === 'ldap') {
        $run = directorySyncRun($conn, $p, $mode, null);
    } else {
        if (!$quiet) echo "Skipped: an OIDC provider has no people to import.\n\n";
        continue;
    }

    if (!$quiet) {
        printf("  status       : %s\n", $run['status'] ?? '?');
        printf("  found        : %d\n", $run['seen_count'] ?? 0);
        printf("  created      : %d\n", $run['created_count'] ?? 0);
        printf("  updated      : %d\n", $run['updated_count'] ?? 0);
        printf("  adopted      : %d\n", $run['adopted_count'] ?? 0);
        printf("  deactivated  : %d\n", $run['deactivated_count'] ?? 0);
        printf("  conflicts    : %d\n", $run['conflict_count'] ?? 0);
        printf("  errors       : %d\n", $run['error_count'] ?? 0);
        if (!empty($run['message'])) echo "\n  " . wordwrap($run['message'], 66, "\n  ") . "\n";
        echo "\n";
    }

    // A stopped run is not a failure to be retried blindly — it is a refusal
    // that wants a human. Distinct exit codes so a cron wrapper can tell.
    if (($run['status'] ?? '') === 'failed')  $exit = 1;
    if (($run['status'] ?? '') === 'stopped') $exit = 2;
}

exit($exit);
