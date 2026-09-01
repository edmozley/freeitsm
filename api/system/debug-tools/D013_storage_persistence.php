<?php
/**
 * Debug Tool D013 — will the files this server holds survive its next update?
 *
 * Answers one question, and it is a question that can only usefully be asked
 * BEFORE the damage: "of the directories FreeITSM writes user files into, which
 * are on storage that outlives the container, and which are on storage that is
 * thrown away the next time somebody rebuilds?"
 *
 * ⚠️ THIS IS THE ONE DIAGNOSTIC THAT IS WORTH RUNNING WHEN NOTHING IS WRONG.
 * Every other tool here explains a failure that has already happened. Under
 * Docker the failure this one describes is unrecoverable once it occurs: the
 * rebuild removes the old container, its writable layer goes with it, and no
 * `docker cp` or image archaeology gets the files back. D012 will tell you
 * afterwards that the files are gone. Only this one can tell you beforehand.
 *
 * 🔑 IT SHARES ITS RULE AND ITS DIRECTORY LIST WITH THE SYSTEM BANNER AND THE
 * SETUP SCREEN — includes/storage_persistence.php. Three screens asking the
 * same question must not each carry their own copy of the answer; that is the
 * shape of drift that produced #109 in the first place, where .gitignore had
 * the right list all along and docker-compose.yml never consulted it.
 *
 * ⚠️ ON ANYTHING THAT IS NOT A CONTAINER THIS TOOL SAYS SO AND STOPS. On WAMP,
 * XAMPP or a native LAMP box the question is meaningless — `git pull` does not
 * delete these folders — and inventing a verdict there would be noise. It says
 * which case it found rather than printing an empty report.
 *
 * READ-ONLY. It stats directories. It writes nothing, reads no file contents,
 * touches no database row and prints no secrets.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D013';
$DIAG_NAME = 'Storage persistence — what survives an update';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/storage_persistence.php';

// Debug tools are administrators-only (issue #34). Fail closed.
try {
    $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    $__dbgAdmin = false;
}
if (!$__dbgAdmin) {
    http_response_code(403);
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo "Administrator access required.\n";
    exit;
}

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function emit_and_exit($sections) {
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}

$report = storagePersistenceReport();

// ---- 1. ENVIRONMENT ----------------------------------------------------
$env = [
    "PHP version        : " . PHP_VERSION,
    "Application root   : " . dirname(__DIR__, 3),
    "Container detected : " . (storagePersistenceInContainer() ? 'YES — this is a container' : 'no'),
];
// Say WHICH signal answered, and say so honestly when one of them could not be
// asked. "no" and "could not look" are very different things to put in front of
// an operator whose uploads may be about to be destroyed (GH #127).
$env[] = "  via " . STORAGE_PERSISTENCE_ENV_MARKER . ": "
       . (getenv(STORAGE_PERSISTENCE_ENV_MARKER, true) !== false
            ? 'set — our own image identifies itself'
            : 'not set');
$env[] = "  via /.dockerenv    : "
       . (!storagePersistenceRootReadable()
            ? 'COULD NOT LOOK — open_basedir does not permit reading /. This is not '
              . 'the same as "no"; the marker above is what answers here.'
            : (@file_exists('/.dockerenv') ? 'present' : 'absent'));
if ($report['applicable']) {
    $env[] = "Root filesystem dev: " . var_export($report['root_device'], true)
           . "   (a directory on a DIFFERENT device is on storage that outlives the container)";
}
addSection($sections, "ENVIRONMENT", $env);

// ---- 2. NOT A CONTAINER: say which case, and stop ------------------------
if (!$report['applicable']) {
    addSection($sections, "NOT APPLICABLE", [
        "This server is not running inside a Docker container, so there is nothing",
        "for this tool to check.",
        "",
        "The failure it looks for is specific to containers: rebuilding replaces the",
        "container and discards anything inside it that is not on a volume. On a",
        "native install (WAMP, XAMPP, LAMP, a plain Linux box) these folders live in",
        "the application directory and .gitignore keeps `git pull` away from them.",
        "",
        "⚠ ONE THING STILL APPLIES TO YOU. `git pull` is safe. Deploying by REPLACING",
        "  the application folder is not — deleting the old one and unpacking a new",
        "  one, or an FTP sync set to mirror, removes these directories along with",
        "  everything in them:",
        "",
    ]);
    $lines = [];
    foreach (storagePersistenceDirectories() as $d) {
        $lines[] = sprintf("    %-42s %s", $d['rel'], is_dir($d['path']) ? '' : '(not present yet)');
    }
    $lines[] = "";
    $lines[] = "  If that is how you deploy, copy them aside first. This is not";
    $lines[] = "  hypothetical — it is how one operator lost their uploads on Debian.";
    addSection($sections, "DIRECTORIES TO PRESERVE ON A FOLDER-REPLACE DEPLOY", $lines);
    emit_and_exit($sections);
}

// ---- 3. THE DIRECTORIES -------------------------------------------------
$rows = [
    sprintf("%-42s %-12s %s", 'DIRECTORY', 'VERDICT', 'WHAT IS IN IT'),
    sprintf("%-42s %-12s %s", str_repeat('-', 42), str_repeat('-', 12), str_repeat('-', 40)),
];
foreach ($report['directories'] as $d) {
    $verdict = [
        'persisted' => 'SURVIVES',
        'at_risk'   => '*AT RISK*',
        'missing'   => 'no folder',
        'unknown'   => 'unknown',
    ][$d['status']];
    $rows[] = sprintf("%-42s %-12s %s", $d['rel'], $verdict, $d['label']);
}
$rows[] = "";
$rows[] = "SURVIVES  = on a volume, a bind mount, or inside a mounted parent. Safe.";
$rows[] = "*AT RISK* = on the container's own writable layer. DESTROYED on the next rebuild.";
$rows[] = "no folder = nothing has been stored here yet. Created on first use — but it";
$rows[] = "            will be created on the writable layer unless it is mounted, so a";
$rows[] = "            folder listed here is a future problem, not an absent one.";
$rows[] = "unknown   = the check could not be made. Not treated as a fault either way.";
addSection($sections, "STORAGE DIRECTORIES", $rows);

// ---- 4. HOW THE VERDICT IS REACHED --------------------------------------
addSection($sections, "HOW THIS IS DECIDED", [
    "Each directory is compared against the device of the container's root",
    "filesystem. Same device means it lives on the writable layer that `docker",
    "compose up --build` throws away. A different device means it is on something",
    "mounted from outside, which is carried across.",
    "",
    "⚠ Deliberately NOT the more obvious test of whether the directory is a mount",
    "  point in its own right. Bind-mounting the whole application directory is a",
    "  perfectly safe setup in which none of these folders is individually a mount",
    "  point — that test would tell a safe operator their data was about to be",
    "  destroyed. A check that cries wolf gets switched off and then disbelieved",
    "  when it is finally right.",
]);

// ---- 5. VERDICT ---------------------------------------------------------
$verdict = [];
if ($report['at_risk'] === 0) {
    $verdict[] = "✓ Every storage directory on this server is on persistent storage.";
    $verdict[] = "  An update will not touch the files in them.";
    if ($report['unknown'] > 0) {
        $verdict[] = "";
        $verdict[] = "  " . $report['unknown'] . " could not be checked and are not included in that";
        $verdict[] = "  statement — see the table above rather than reading this as a clean bill.";
    }
} else {
    $verdict[] = "✗ " . $report['at_risk'] . " of " . count($report['directories']) . " storage directories are NOT persistent.";
    $verdict[] = "";
    $verdict[] = "  Everything in them will be destroyed the next time this container is";
    $verdict[] = "  rebuilt — and rebuilding is what updating FreeITSM does. The database";
    $verdict[] = "  will survive, so afterwards the records will still be there pointing at";
    $verdict[] = "  files that are gone, and attachments will report \"recorded but missing";
    $verdict[] = "  from storage\".";
    $verdict[] = "";
    $verdict[] = "  ⚠ THIS IS NOT RECOVERABLE AFTERWARDS. The rebuild removes the old";
    $verdict[] = "    container and its files go with it. Act before you update, not after.";

    if ($report['critical_at_risk']) {
        $verdict[] = "";
        $verdict[] = "  ⚠⚠ THE ENCRYPTION KEY IS AMONG THEM. That is the most serious entry in";
        $verdict[] = "     the list. Mailbox passwords and integration credentials are encrypted";
        $verdict[] = "     with it; if it is regenerated they are not corrupt, they are simply";
        $verdict[] = "     unreadable for good, and every one has to be entered again by hand.";
    }

    $vols = storagePersistenceSuggestedVolumes($report);
    if ($vols) {
        $verdict[] = "";
        $verdict[] = "  WHAT TO DO — in this order.";
        $verdict[] = "";
        $verdict[] = "  1. FIRST, while the current container is still running, copy the files";
        $verdict[] = "     out. Adding a volume does NOT rescue what is already inside the";
        $verdict[] = "     container: Docker fills a new volume from the image, not from the";
        $verdict[] = "     container it replaces, so the rebuild that adds the volume still";
        $verdict[] = "     discards them.";
        $verdict[] = "";
        foreach ($report['directories'] as $d) {
            if ($d['status'] !== 'at_risk') continue;
            $verdict[] = "       docker compose cp app:" . $d['path'] . " ./backup/" . basename($d['rel']);
        }
        $verdict[] = "";
        $verdict[] = "  2. Create a NEW file next to docker-compose.yml called";
        $verdict[] = "     docker-compose.override.yml, containing exactly this:";
        $verdict[] = "";
        foreach (explode("\n", rtrim(storagePersistenceOverrideFile($report), "\n")) as $l) {
            $verdict[] = "       " . $l;
        }
        $verdict[] = "";
        $verdict[] = "     ⚠ DO NOT edit docker-compose.yml itself. It is tracked in git, so";
        $verdict[] = "       once you have changed it a future `git pull` refuses to run at";
        $verdict[] = "       all — and the usual way out of that, `git checkout --";
        $verdict[] = "       docker-compose.yml`, silently throws your volumes away and puts";
        $verdict[] = "       you back here. Compose reads the override file automatically and";
        $verdict[] = "       merges the two, and nothing in an update touches it.";
        $verdict[] = "";
        $verdict[] = "  3. Update as usual, then copy the files back in and fix ownership:";
        $verdict[] = "";
        $verdict[] = "       docker compose cp ./backup/<folder>/. app:<path>";
        $verdict[] = "       docker compose exec app chown -R www-data:www-data <path>";
    }
}
addSection($sections, "VERDICT", $verdict);

emit_and_exit($sections);
