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
 * --all imports every source that is due: one whose "Import automatically"
 * interval has passed, or one set to 0 ("every time the scheduled task runs").
 * So it can run every 15 minutes and each source still keeps its own pace.
 *
 * --auto is what FreeITSM itself starts, in the background, when no scheduled
 * task is doing it (includes/directory_sync_auto.php). The source was already
 * claimed by whoever started it; it is recorded as a 'page' run.
 *
 * Whatever starts it, one source is never imported twice at once (dsyncLock()).
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
require_once __DIR__ . '/../includes/job_runs.php';   // run history (#182)
require_once __DIR__ . '/../includes/directory_sync_auto.php';

$opts     = getopt('', ['provider::', 'all', 'preview', 'quiet', 'auto']);
$auto     = isset($opts['auto']) && !isset($opts['all']) && !isset($opts['preview']);
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
          WHERE enabled = 1 AND sync_enabled = 1 AND protocol IN ('ldap', 'carddav', 'oidc')
       ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
    // An OIDC row is only a people source when it is Entra (entra_sync.php).
    require_once __DIR__ . '/../includes/entra_sync.php';
    $providers = array_values(array_filter($providers,
        fn($p) => $p['protocol'] !== 'oidc' || entraIsEntraIssuer((string)$p['issuer_url'])));
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

// #182: a --preview writes nothing, so it is recorded as SKIPPED - a dry run is
// never evidence that the job works, and can never clear a failure. A single
// --provider run is a manual run of that one source. An --auto run was started by
// FreeITSM because no schedule did: 'page', which health never counts as the
// schedule being alive.
$trigger = isset($opts['all']) ? 'cron' : ($auto ? 'page' : 'manual');
$runId = $preview ? null : JobRun::start($conn, 'directory_sync', $trigger);
if ($preview) JobRun::skipped($conn, 'directory_sync', 'manual', 'Dry run (--preview): nothing written.');

require_once __DIR__ . '/../includes/encryption.php';

$exit = 0;
foreach ($providers as $p) {
    // ⚠️ Only the bind password is encrypted on a provider, and decryptValue is
    // what unwraps it. decryptMailboxRow() is for target_mailboxes and knows
    // nothing about this column, so it left the password as the literal string
    // "ENC:…" and the bind failed with "Invalid credentials" — which reads like
    // a wrong password rather than an un-decrypted one.
    $p['ldap_bind_password'] = decryptValue($p['ldap_bind_password'] ?? '');
    $label = $p['display_name'] ?: ('provider ' . $p['id']);

    if (!$preview) {
        // --all: a source with an interval runs only when due, and claiming it is
        // what stops FreeITSM's own background start running it as well. --auto
        // was claimed by whoever started this process. Anything else is a run
        // somebody asked for by name, which still postpones the automatic one.
        $interval = (int)($p['sync_interval_minutes'] ?? 0);
        if (isset($opts['all']) && $interval > 0) {
            if (!dsyncClaimDue($conn, (int)$p['id'])) {
                if (!$quiet) echo "Not due: $label imports every $interval minute(s).\n\n";
                continue;
            }
        } elseif (!$auto) {
            dsyncMarkAttempt($conn, (int)$p['id']);
        }
        if (!dsyncLock($conn, (int)$p['id'])) {
            if (!$quiet) echo "Skipped: an import of $label is already running.\n\n";
            continue;
        }
    }

    if (!$quiet) {
        echo str_repeat('=', 70) . "\n";
        echo ($preview ? 'PREVIEW' : 'SYNC') . ' — ' . $label . ' [' . $p['protocol'] . ']' . "\n";
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
    } elseif ($p['protocol'] === 'oidc' && (int)$p['sync_enabled'] === 1
              && entraIsEntraIssuer((string)$p['issuer_url'])) {
        // Microsoft Entra ID through Graph. Secrets are decrypted inside.
        require_once __DIR__ . '/../includes/entra_sync.php';
        $run = entraSyncRun($conn, $p, $mode, null);
    } else {
        if (!$quiet) echo "Skipped: this OIDC provider is not an Entra ID provider with directory sync switched on.\n\n";
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
    if (!$preview) {
        JobRun::source($conn, $runId, 'directory_sync', 'provider:' . (int)$p['id'], (string)($p['display_name'] ?: ('provider ' . $p['id'])),
            !in_array($run['status'] ?? '', ['failed', 'stopped'], true), $run['message'] ?? null);
    }
    if (($run['status'] ?? '') === 'failed')  $exit = 1;
    if (($run['status'] ?? '') === 'stopped') $exit = 2;
}

JobRun::finish($conn, $runId);
exit($exit);
