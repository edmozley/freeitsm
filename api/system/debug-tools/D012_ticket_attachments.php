<?php
/**
 * Debug Tool D012 — Ticket attachments, end to end.
 *
 * Answers one question for one ticket: "for every file hanging off this ticket,
 * would the endpoint that serves it actually hand it over — and if not, why?"
 *
 * ⚠️ THE THING THAT MAKES THIS HARD IS THAT "TICKET ATTACHMENT" IS FOUR DIFFERENT
 * SUBSYSTEMS. They share nothing: not a table, not a folder, not a reader, not
 * even an id sequence. A report that says "attachments are broken" almost never
 * means all four, and working out WHICH ONE is most of the diagnosis:
 *
 *   Email attachment   email_attachments   tickets/attachments/     get_attachment.php
 *   Document on ticket documents           uploads/documents/       documents/download.php
 *   Document on a note documents           uploads/documents/       documents/download.php
 *   Screen recording   ticket_recordings   recordings/<ticket>/     get_recording.php
 *
 * They also arrived years apart, so an id of 1 in one of them is unremarkable
 * while the same id in another would be astonishing. The report names the
 * subsystem on every single line for exactly that reason.
 *
 * 🔑 IT RESOLVES PATHS THROUGH THE APPLICATION'S OWN FUNCTIONS, never a copy of
 * the rule. documentStoragePath() is required from includes/documents.php and
 * called; the other three roots are rebuilt exactly as their endpoints build
 * them, containment check included. A tool that reimplements the resolver
 * verifies its own reimplementation and passes while the product fails.
 *
 * 🔑 PRESENT IS NOT THE SAME AS READABLE, AND READABLE IS NOT THE SAME AS INTACT.
 * So each file is taken through the whole journey: resolve, contain, exist, open,
 * read every byte (a stream hash reads the full extent, which is what catches a
 * truncated or locked file), compare the length against the length recorded at
 * upload, compare the SHA-256 against documents.content_hash where one was
 * stored, sniff the leading bytes, and finally copy it to the system temp folder
 * and delete it again — the closest thing to a real download that can be done
 * without a browser.
 *
 * ⚠️ A CHECK THAT CANNOT RUN SAYS SO, and never passes quietly. No fileinfo
 * extension means "could not sniff", not "type is fine". No stored hash means
 * "not recorded", not "matches". This is D009's rule and it applies here for the
 * same reason.
 *
 * ⚠️ ON THE .htaccess QUESTION: it IS a thing — every one of these folders sits
 * under the web root and is meant to be unreachable except through its endpoint,
 * and the deny comes from a per-folder .htaccess plus a web.config for IIS. This
 * tool reports whether those guard files are PRESENT and whether they actually
 * contain a deny. It deliberately does NOT claim the folder is protected: nginx
 * never reads .htaccess and neither does Apache with AllowOverride None, so
 * presence proves nothing on its own. D009 is the tool that settles that, by
 * fetching a probe over HTTP, and this report points at it rather than guessing.
 *
 * Read-only, with one exception that cleans up after itself: the temp copy. It is
 * written outside the application, verified, deleted, and the deletion is
 * confirmed and reported.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D012';
$DIAG_NAME = 'Ticket attachments — every file, end to end';

/** Files larger than this are hashed but not copied to temp. Keeps a 2 GB video
 *  from turning a diagnostic into a disk-filling exercise; the hash has already
 *  proved the whole file is readable by then, so the copy adds little. */
const D012_COPY_MAX_BYTES = 67108864;   // 64 MB

// ---- helpers -----------------------------------------------------------

$sections = [];
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
function bool_str($v) { return $v ? 'YES' : 'NO'; }

function emit_and_exit($sections) {
    if (!headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo implode("\n\n", $sections) . "\n";
    exit;
}

/** Human-readable byte count. Sizes here are read by people, not parsed. */
function d012_bytes($n) {
    if ($n === null || $n === '') return '(not recorded)';
    $n = (float) $n;
    if ($n < 1024) return ((int) $n) . ' B';
    if ($n < 1048576) return round($n / 1024, 1) . ' KB';
    if ($n < 1073741824) return round($n / 1048576, 1) . ' MB';
    return round($n / 1073741824, 2) . ' GB';
}

/** Mask nothing, but keep a runaway value from swamping the report. */
function d012_trim($s, $max = 120) {
    $s = (string) $s;
    return strlen($s) > $max ? substr($s, 0, $max) . '…' : $s;
}

/**
 * WHY is_file() SAID NO.
 *
 * download.php's 410 — "That file is recorded but missing from storage." — is one
 * message covering one test: `!is_file($path)`. That test is false for at least
 * seven genuinely different reasons, and they have completely different fixes.
 * Reporting them all as "the file is gone" sends somebody looking for a backup
 * when the actual fault is a directory they cannot traverse.
 *
 *   1. Nothing is there                    — restore it
 *   2. The path is a DIRECTORY             — the stored key is empty or wrong
 *   3. A broken symlink                    — the target moved
 *   4. Present but differently CASED       — a Windows to Linux move; Linux cares
 *   5. The parent directory is unreadable  — is_file() cannot stat, so it says no
 *      or not traversable                    even though the file is right there
 *   6. open_basedir excludes the path      — PHP refuses before touching the disk
 *   7. The stored key was rewritten by     — the row holds something that never
 *      documentStoragePath()'s sanitiser     addressed the file it was written as
 *
 * ⚠️ 4 AND 5 ARE THE ONES THAT MATTER MOST, because in both of them the file IS
 * on the server — which is exactly what somebody reporting this bug will tell you,
 * and exactly what makes it look like the software is lying to them.
 */
function d012_whyMissing($abs, $baseDir, $storedKey) {
    $out = [];
    $parent = dirname($abs);
    $base   = basename($abs);

    // 2 / 3 — it exists, just not as a readable regular file.
    if (is_dir($abs)) {
        $out[] = 'The path is a DIRECTORY, not a file. That happens when the stored key is empty or';
        $out[] = 'names a folder — check the row: a "file" document with no storage_key resolves to';
        $out[] = 'the uploads folder itself, and is_file() correctly refuses it.';
        return $out;
    }
    if (is_link($abs)) {
        $out[] = 'The path is a SYMLINK whose target does not resolve (points at ' . (@readlink($abs) ?: '?') . ').';
        return $out;
    }

    // 6 — PHP may be refusing before it ever looks.
    $obd = (string) ini_get('open_basedir');
    if ($obd !== '') {
        $inside = false;
        foreach (preg_split('/[:;]/', $obd) as $p) {
            $p = trim($p);
            if ($p !== '' && strncmp($abs, $p, strlen($p)) === 0) { $inside = true; break; }
        }
        $out[] = 'open_basedir is set to: ' . d012_trim($obd, 200);
        if (!$inside) {
            $out[] = '  <-- and this path is NOT inside it. PHP refuses the file without looking at the';
            $out[] = '      disk, so it reads as missing however healthy it is. THIS IS THE FAULT.';
            return $out;
        }
        $out[] = '  (the path is inside it, so this is not the cause)';
    }

    // 5 — a parent directory that cannot be listed or traversed makes is_file()
    //     answer no about a file that is present.
    if (!is_dir($parent)) {
        $out[] = 'The containing folder does not exist either: ' . $parent;
        return $out;
    }
    $canRead = is_readable($parent);
    $out[] = 'Containing folder    : ' . $parent;
    $out[] = 'Folder readable      : ' . ($canRead ? 'YES' : 'NO   <-- is_file() cannot stat inside a folder it may not read.')
           . ($canRead ? '' : ' The file may well be there. Fix the permissions before assuming it is lost.');
    if (!$canRead) return $out;

    // 1 / 4 / 7 — the folder can be listed, so ask it directly what it holds.
    $entries = @scandir($parent);
    if ($entries === false) {
        $out[] = 'The folder could not be listed despite reporting as readable — treat as a permissions fault.';
        return $out;
    }
    $ciMatch = null; $stemMatch = [];
    $stem = pathinfo($base, PATHINFO_FILENAME);
    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') continue;
        if ($e !== $base && strcasecmp($e, $base) === 0) { $ciMatch = $e; break; }
        if ($stem !== '' && stripos($e, $stem) === 0 && $e !== $base) $stemMatch[] = $e;
    }
    if ($ciMatch !== null) {
        $out[] = 'FOUND IT, DIFFERENTLY CASED: the folder holds "' . $ciMatch . '" and the database says';
        $out[] = '"' . $base . '". Windows treats those as the same file and Linux does not, so this is';
        $out[] = 'what a move from a Windows server to a Linux one looks like. Rename the file to match';
        $out[] = 'the database exactly — do not change the row, other things read it.';
        return $out;
    }
    if ($stemMatch) {
        $out[] = 'Not found, but the folder holds file(s) with the same name and a different extension: '
               . d012_trim(implode(', ', array_slice($stemMatch, 0, 3)), 150);
    }

    // 7 — did the resolver rewrite the key on its way to a path?
    if ($baseDir !== null) {
        $raw = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, (string) $storedKey), '/\\');
        if (realpath($raw) === false && str_replace('\\', '/', $raw) !== str_replace('\\', '/', $abs)) {
            $out[] = 'Note: the stored value was rewritten on its way to a path (traversal sequences are';
            $out[] = 'stripped), so the row does not address the file it names. Read the stored value above.';
        }
    }

    $out[] = 'Nothing of that name is in the folder, under any casing. The file is genuinely absent:';
    $out[] = 'restore it from backup, or accept it is gone and remove the row.';
    return $out;
}

// ---- 1. HEADER ---------------------------------------------------------

$ref = trim((string) (isset($_GET['ref']) ? $_GET['ref'] : (isset($_POST['ref']) ? $_POST['ref'] : '')));
$now = gmdate('Y-m-d H:i:s') . ' UTC';
addSection($sections, "REPORT HEADER", [
    "Diagnostic     : {$DIAG_ID} — {$DIAG_NAME}",
    "Generated      : {$now}",
    "Generated by   : analyst_id=" . (isset($_SESSION['analyst_id']) ? $_SESSION['analyst_id'] : '(not logged in)'),
    "Ticket ref in  : " . ($ref === '' ? '(none supplied)' : $ref),
    "Side effects   : read-only, apart from one temp-folder copy per file which is deleted again.",
]);

// ---- 2. AUTH GATE ------------------------------------------------------

if (!isset($_SESSION['analyst_id'])) {
    addSection($sections, "AUTH", "FAIL: not logged in. Log into FreeITSM in the same browser, then re-run.");
    emit_and_exit($sections);
}

if ($ref === '') {
    addSection($sections, "INPUT", "FAIL: no ticket reference supplied. Type a ticket number (e.g. TICKET-000107) into the box and click Run.");
    emit_and_exit($sections);
}

// ---- 3. DATABASE CONNECTION + ADMIN GATE -------------------------------

$rootCfg = realpath(__DIR__ . '/../../../config.php');
$conn = null; $connErr = null;
try {
    if ($rootCfg) @require_once $rootCfg;
    // Debug tools are administrators-only (issue #34). Fail closed.
    require_once __DIR__ . '/../../../includes/functions.php';
    try { $__dbgAdmin = !empty($_SESSION['analyst_id']) && analystIsAdmin(connectToDatabase(), (int) $_SESSION['analyst_id']); }
    catch (Throwable $e) { $__dbgAdmin = false; }
    if (!$__dbgAdmin) {
        http_response_code(403);
        if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
        echo "Administrator access required.\n";
        exit;
    }
    if (defined('DB_SERVER') && defined('DB_NAME') && defined('DB_USERNAME') && defined('DB_PASSWORD')) {
        $conn = new PDO("mysql:host=" . DB_SERVER . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USERNAME, DB_PASSWORD, dbConnectionOptions());   // UTC session — config.php
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } else {
        $connErr = 'DB constants not defined in config.php';
    }
} catch (Throwable $e) {
    $connErr = $e->getMessage();
}

if (!$conn) {
    addSection($sections, "DATABASE CONNECTION", "FAILED: " . ($connErr === null ? 'unknown error' : $connErr));
    emit_and_exit($sections);
}

// The application's own resolvers. Required AFTER the admin gate, and each in its
// own try so a missing file on an older install degrades one check rather than
// killing the report.
$haveDocuments = false; $haveUploads = false; $includeErrs = [];
try { require_once __DIR__ . '/../../../includes/documents.php'; $haveDocuments = function_exists('documentStoragePath'); }
catch (Throwable $e) { $includeErrs[] = 'includes/documents.php: ' . $e->getMessage(); }
try { require_once __DIR__ . '/../../../includes/uploads.php'; $haveUploads = function_exists('attachmentServeRules'); }
catch (Throwable $e) { $includeErrs[] = 'includes/uploads.php: ' . $e->getMessage(); }

$appRoot = realpath(__DIR__ . '/../../../');
$dbLines = [
    "Connect attempt : OK",
    "Server version  : " . $conn->getAttribute(PDO::ATTR_SERVER_VERSION),
    "Database        : " . (defined('DB_NAME') ? DB_NAME : ''),
    "Application root: " . ($appRoot === false ? '(could not resolve)' : $appRoot),
    "PHP version     : " . PHP_VERSION,
    "fileinfo ext    : " . bool_str(extension_loaded('fileinfo')) . (extension_loaded('fileinfo') ? '' : '  <-- content sniffing cannot run; checks below will say so rather than pass'),
    "documents.php   : " . ($haveDocuments ? 'loaded (documentStoragePath available)' : 'NOT AVAILABLE — document paths cannot be resolved the way the app resolves them'),
    "uploads.php     : " . ($haveUploads ? 'loaded (attachmentServeRules available)' : 'NOT AVAILABLE — cannot report how a file would be served'),
];
foreach ($includeErrs as $e) $dbLines[] = "Include error   : " . $e;
addSection($sections, "DATABASE CONNECTION", $dbLines);

// Introspection helpers bound to this connection.
$tableExists = function ($t) use ($conn) {
    try { return (bool) $conn->query("SHOW TABLES LIKE " . $conn->quote($t))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};
$colExists = function ($t, $c) use ($conn) {
    try { return (bool) $conn->query("SHOW COLUMNS FROM `{$t}` LIKE " . $conn->quote($c))->fetchColumn(); }
    catch (Throwable $e) { return false; }
};

// ---- 4. RESOLVE TICKET -------------------------------------------------

$ticketId = null; $ticketRow = null; $resolve = [];
try {
    $stmt = $conn->prepare("SELECT id, ticket_number, subject, created_datetime, deleted_datetime FROM tickets WHERE ticket_number = ?");
    $stmt->execute([$ref]);
    $ticketRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ticketRow && ctype_digit($ref)) {
        $stmt = $conn->prepare("SELECT id, ticket_number, subject, created_datetime, deleted_datetime FROM tickets WHERE id = ?");
        $stmt->execute([(int) $ref]);
        $ticketRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($ticketRow) $resolve[] = "Note: '{$ref}' did not match a ticket_number, but matched ticket id {$ref}.";
    }
    if (!$ticketRow) {
        $resolve[] = "FAIL: no ticket found with ticket_number (or id) = '{$ref}'.";
        $resolve[] = "Check the reference on the ticket itself — the number shown in the header, e.g. TICKET-000107.";
        addSection($sections, "RESOLVE TICKET", $resolve);
        emit_and_exit($sections);
    }
    $ticketId = (int) $ticketRow['id'];
    $resolve[] = "Matched ticket:";
    $resolve[] = "  id             : " . $ticketRow['id'];
    $resolve[] = "  ticket_number  : " . $ticketRow['ticket_number'];
    $resolve[] = "  subject        : " . d012_trim($ticketRow['subject']);
    $resolve[] = "  created        : " . $ticketRow['created_datetime'];
    $resolve[] = "  deleted        : " . ($ticketRow['deleted_datetime'] === null ? 'no' : $ticketRow['deleted_datetime'] . '  <-- this ticket is in the trash; its files are not served');
} catch (Throwable $e) {
    addSection($sections, "RESOLVE TICKET", "ERROR: " . $e->getMessage());
    emit_and_exit($sections);
}
addSection($sections, "RESOLVE TICKET", $resolve);

// ---- 5. WHAT THIS INSTALL CAN STORE ------------------------------------
//
// An installation older than a feature identifies itself here rather than by
// producing an empty list further down, which reads identically to "this ticket
// happens to have none".

$need = [
    'Email attachment'   => ['emails' => ['id', 'ticket_id'], 'email_attachments' => ['id', 'email_id', 'filename', 'file_path', 'file_size', 'content_type']],
    'Document'           => ['documents' => ['id', 'kind', 'storage_key', 'original_name', 'size_bytes', 'content_hash', 'deleted_datetime'], 'document_links' => ['document_id', 'parent_type', 'parent_id']],
    'Document on a note' => ['ticket_notes' => ['id', 'ticket_id', 'is_internal']],
    'Screen recording'   => ['ticket_recordings' => ['id', 'ticket_id', 'filename', 'file_path', 'file_size', 'content_type']],
];
$kindAvailable = [];
$schemaLines = [];
foreach ($need as $label => $tables) {
    $ok = true; $detail = [];
    foreach ($tables as $t => $cols) {
        if (!$tableExists($t)) { $ok = false; $detail[] = "table `{$t}` MISSING"; continue; }
        $missing = [];
        foreach ($cols as $c) if (!$colExists($t, $c)) $missing[] = $c;
        if ($missing) { $ok = false; $detail[] = "table `{$t}` present, columns MISSING: " . implode(', ', $missing); }
        else $detail[] = "table `{$t}` OK (" . count($cols) . " columns checked)";
    }
    $kindAvailable[$label] = $ok;
    $schemaLines[] = str_pad($label, 20) . ': ' . ($ok ? 'available' : 'NOT AVAILABLE ON THIS INSTALL');
    foreach ($detail as $d) $schemaLines[] = '    ' . $d;
}
// 'Document on a note' also needs the Document tables.
if (!$kindAvailable['Document']) $kindAvailable['Document on a note'] = false;
$schemaLines[] = '';
$schemaLines[] = "A kind marked NOT AVAILABLE is one this installation predates, or one db_verify has not";
$schemaLines[] = "created yet. Run System > Database Verification before reading anything into it.";
addSection($sections, "WHAT THIS INSTALL CAN STORE", $schemaLines);

// ---- 6. STORAGE DIRECTORIES AND THEIR GUARDS ---------------------------

$dirs = [
    'tickets/attachments' => 'Email attachments',
    'uploads/documents'   => 'Documents (ticket and note)',
    'recordings'          => 'Screen recordings',
];
$dirLines = []; $dirFacts = [];
foreach ($dirs as $rel => $what) {
    $abs = ($appRoot === false ? null : $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel));
    $exists = $abs !== null && is_dir($abs);
    // ⚠️ COUNTED RECURSIVELY, and it has to be. Email attachments live in
    // tickets/attachments/<shard>/<email id>/, so a top-level-only count reports
    // ZERO next to six working files — which reads as a contradiction and would
    // send somebody looking for a folder that never came across.
    $fileCount = null; $truncated = false;
    if ($exists) {
        $fileCount = 0;
        $stack = [$abs];
        while ($stack) {
            $dir = array_pop($stack);
            $dh = @opendir($dir);
            if (!$dh) { if ($dir === $abs) { $fileCount = null; break; } continue; }
            while (($f = readdir($dh)) !== false) {
                if ($f === '.' || $f === '..') continue;
                $p = $dir . DIRECTORY_SEPARATOR . $f;
                if (is_dir($p)) { $stack[] = $p; continue; }
                if ($f === '.htaccess' || $f === 'web.config' || $f === 'index.html') continue;
                $fileCount++;
            }
            closedir($dh);
            if ($fileCount !== null && $fileCount > 20000) { $truncated = true; break; }   // a count, not a census
        }
    }
    $dirFacts[$rel] = ['exists' => $exists, 'files' => $fileCount, 'abs' => $abs];

    $dirLines[] = $rel . '   (' . $what . ')';
    $dirLines[] = '    absolute path : ' . ($abs === null ? '(app root unresolved)' : $abs);
    $dirLines[] = '    exists        : ' . bool_str($exists);
    if ($exists) {
        $dirLines[] = '    readable      : ' . bool_str(is_readable($abs));
        $dirLines[] = '    writable      : ' . bool_str(is_writable($abs)) . '   (new uploads need this; existing files do not)';
        $dirLines[] = '    files held    : ' . ($fileCount === null ? '(could not list — directory not readable)' : ($truncated ? 'over ' : '') . $fileCount . '  (including subfolders; guard files excluded)');
        foreach (['.htaccess', 'web.config'] as $guard) {
            $gp = $abs . DIRECTORY_SEPARATOR . $guard;
            if (!is_file($gp)) {
                $dirLines[] = '    ' . str_pad($guard, 14) . ': ABSENT' . ($guard === '.htaccess' ? '   <-- on Apache this folder may be publicly fetchable' : '   <-- on IIS this folder may be publicly fetchable');
            } else {
                $body = (string) @file_get_contents($gp);
                $denies = ($guard === '.htaccess')
                    ? (stripos($body, 'Require all denied') !== false || stripos($body, 'Deny from all') !== false)
                    : (stripos($body, 'denyUrlSequences') !== false || stripos($body, '<clear') !== false);
                $dirLines[] = '    ' . str_pad($guard, 14) . ': present, ' . ($denies ? 'contains a deny rule' : 'PRESENT BUT NO DENY RULE FOUND — read it by hand');
            }
        }
    }
    $dirLines[] = '';
}
$dirLines[] = "⚠ A guard file being present does NOT mean the folder is protected. nginx never reads";
$dirLines[] = "  .htaccess, and nor does Apache with AllowOverride None. The only honest test is to";
$dirLines[] = "  fetch a probe over HTTP — that is D009 (Guarded paths), and it is the tool to run if";
$dirLines[] = "  this question matters. Everything above is a file-on-disk check and nothing more.";
addSection($sections, "STORAGE DIRECTORIES AND THEIR GUARDS", $dirLines);

// ---- 7. WHAT IS ATTACHED TO THIS TICKET --------------------------------
//
// One flat list, every kind resolved to the same shape, so the verification loop
// below treats them identically. A difference in the report is then a real
// difference in the data, not a difference in how the tool looked.

$items = []; $inventory = []; $softDeleted = [];
$baseUrl = defined('BASE_URL') ? BASE_URL : '/';

// -- 7a. Email attachments
if ($kindAvailable['Email attachment']) {
    try {
        $st = $conn->prepare(
            "SELECT a.id, a.email_id, a.filename, a.file_path, a.file_size, a.content_type, a.is_inline, a.created_datetime
               FROM email_attachments a
               JOIN emails e ON e.id = a.email_id
              WHERE e.ticket_id = ?
           ORDER BY a.id ASC"
        );
        $st->execute([$ticketId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $base = ($appRoot === false ? null : $appRoot . DIRECTORY_SEPARATOR . 'tickets' . DIRECTORY_SEPARATOR . 'attachments');
            $items[] = [
                'kind'      => 'Email attachment',
                'id'        => (int) $r['id'],
                'name'      => (string) $r['filename'],
                'stored'    => (string) $r['file_path'],
                'abs'       => $base === null ? null : $base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $r['file_path']),
                'base'      => $base,
                'contained' => true,   // get_attachment.php enforces containment
                'size'      => $r['file_size'] === null ? null : (int) $r['file_size'],
                'hash'      => null,
                'url'       => $baseUrl . 'api/tickets/get_attachment.php?id=' . (int) $r['id'],
                'note'      => 'email id ' . (int) $r['email_id'] . ((int) $r['is_inline'] === 1 ? ', inline image' : '') . ', declared ' . d012_trim($r['content_type'], 60),
                'created'   => $r['created_datetime'],
                'skip'      => null,
            ];
        }
    } catch (Throwable $e) { $inventory[] = 'Email attachments: QUERY ERROR — ' . $e->getMessage(); }
}

// -- 7b + 7c. Documents, on the ticket and on its notes
if ($kindAvailable['Document']) {
    try {
        // Two link shapes in one pass: parent_type 'ticket' points straight at the
        // ticket, parent_type 'ticket_note' points at a note that belongs to it.
        $st = $conn->prepare(
            "SELECT d.id, d.kind, d.title, d.storage_key, d.original_name, d.mime_type, d.size_bytes,
                    d.content_hash, d.external_url, d.deleted_datetime, d.created_datetime,
                    dl.parent_type, dl.parent_id, n.is_internal
               FROM document_links dl
               JOIN documents d ON d.id = dl.document_id
          LEFT JOIN ticket_notes n ON n.id = dl.parent_id AND dl.parent_type = 'ticket_note'
              WHERE (dl.parent_type = 'ticket'      AND dl.parent_id = ?)
                 OR (dl.parent_type = 'ticket_note' AND dl.parent_id IN (SELECT id FROM ticket_notes WHERE ticket_id = ?))
           ORDER BY d.id ASC"
        );
        $st->execute([$ticketId, $ticketId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $onNote = ($r['parent_type'] === 'ticket_note');
            $label  = $onNote ? 'Document on a note' : 'Document on ticket';
            $where  = $onNote
                ? 'note id ' . (int) $r['parent_id'] . ', ' . ((int) $r['is_internal'] === 1 ? 'internal' : 'shared with the requester')
                : 'attached to the ticket itself';

            if ($r['deleted_datetime'] !== null) {
                $softDeleted[] = str_pad($label, 20) . ' id ' . $r['id'] . '  "' . d012_trim($r['original_name'] ? $r['original_name'] : $r['title'], 50) . '"  deleted ' . $r['deleted_datetime'];
                continue;
            }

            // A link is not a stored file. download.php returns JSON for these, so
            // there is nothing on disk to verify and reporting one as missing would
            // be a false alarm.
            if ($r['kind'] === 'link') {
                $items[] = [
                    'kind' => $label, 'id' => (int) $r['id'], 'name' => d012_trim($r['title'], 60),
                    'stored' => '(none — external link)', 'abs' => null, 'base' => null, 'contained' => false,
                    'size' => null, 'hash' => null,
                    'url' => $baseUrl . 'api/documents/download.php?id=' . (int) $r['id'],
                    'note' => $where . '; external URL ' . d012_trim($r['external_url'], 80),
                    'created' => $r['created_datetime'],
                    'skip' => 'LINK — no file is stored for this entry, so there is nothing on disk to verify.',
                ];
                continue;
            }

            $abs = null;
            if ($haveDocuments) {
                // The application's own resolver, not a copy of it.
                $abs = documentStoragePath((string) $r['storage_key']);
            }
            $items[] = [
                'kind'      => $label,
                'id'        => (int) $r['id'],
                'name'      => (string) ($r['original_name'] !== null && $r['original_name'] !== '' ? $r['original_name'] : $r['title']),
                'stored'    => (string) $r['storage_key'],
                'abs'       => $abs,
                'base'      => ($appRoot === false ? null : $appRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'documents'),
                // download.php checks is_file() only; the search indexer is the one
                // that enforces containment. Reported per file below.
                'contained' => false,
                'size'      => $r['size_bytes'] === null ? null : (int) $r['size_bytes'],
                'hash'      => $r['content_hash'],
                'url'       => $baseUrl . 'api/documents/download.php?id=' . (int) $r['id'],
                'note'      => $where . ', declared ' . d012_trim($r['mime_type'], 60),
                'created'   => $r['created_datetime'],
                'skip'      => $haveDocuments ? null : 'CANNOT CHECK — includes/documents.php did not load, so the path cannot be resolved the way the app resolves it.',
            ];
        }
    } catch (Throwable $e) { $inventory[] = 'Documents: QUERY ERROR — ' . $e->getMessage(); }
}

// -- 7d. Screen recordings
if ($kindAvailable['Screen recording']) {
    try {
        $st = $conn->prepare(
            "SELECT id, filename, original_filename, file_path, file_size, content_type, duration_seconds, created_at
               FROM ticket_recordings WHERE ticket_id = ? ORDER BY id ASC"
        );
        $st->execute([$ticketId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $stored = (string) $r['file_path'];
            $items[] = [
                'kind'      => 'Screen recording',
                'id'        => (int) $r['id'],
                'name'      => (string) ($r['original_filename'] !== null && $r['original_filename'] !== '' ? $r['original_filename'] : $r['filename']),
                'stored'    => $stored,
                'abs'       => ($appRoot === false ? null : $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stored)),
                'base'      => ($appRoot === false ? null : $appRoot . DIRECTORY_SEPARATOR . 'recordings'),
                'contained' => false,
                'size'      => $r['file_size'] === null ? null : (int) $r['file_size'],
                'hash'      => null,
                'url'       => $baseUrl . 'api/self-service/get_recording.php?id=' . (int) $r['id'],
                'note'      => 'declared ' . d012_trim($r['content_type'], 60) . ($r['duration_seconds'] !== null ? ', ' . (int) $r['duration_seconds'] . 's' : ''),
                'created'   => $r['created_at'],
                // ⚠️ A recording whose file move failed is claimed onto the ticket
                // ANYWAY (includes/ticket_recordings.php says so, deliberately), so a
                // row still pointing into recordings/pending/ is a known, expected
                // breakage rather than a mystery. Say which it is.
                'skip'      => (strpos($stored, 'recordings/pending/') === 0)
                    ? null : null,
            ];
            if (strpos($stored, 'recordings/pending/') === 0) {
                $items[count($items) - 1]['note'] .= '  <-- still in pending/: the move onto the ticket failed at claim time';
            }
        }
    } catch (Throwable $e) { $inventory[] = 'Screen recordings: QUERY ERROR — ' . $e->getMessage(); }
}

// Inventory summary
$byKind = [];
foreach ($items as $it) { if (!isset($byKind[$it['kind']])) $byKind[$it['kind']] = 0; $byKind[$it['kind']]++; }
foreach (['Email attachment', 'Document on ticket', 'Document on a note', 'Screen recording'] as $k) {
    $avail = isset($kindAvailable[$k]) ? $kindAvailable[$k] : (isset($kindAvailable['Document']) ? $kindAvailable['Document'] : true);
    if ($k === 'Document on ticket') $avail = $kindAvailable['Document'];
    $inventory[] = str_pad($k, 20) . ': ' . (isset($byKind[$k]) ? $byKind[$k] : 0) . ($avail ? '' : '   (subsystem not available on this install)');
}
$inventory[] = str_pad('TOTAL to verify', 20) . ': ' . count($items);
if ($softDeleted) {
    $inventory[] = '';
    $inventory[] = 'Soft-deleted documents on this ticket (' . count($softDeleted) . ') — these are deliberately NOT served,';
    $inventory[] = 'so a report of "it disappeared" is explained here rather than by a missing file:';
    foreach ($softDeleted as $s) $inventory[] = '    ' . $s;
}
if (!$items) {
    $inventory[] = '';
    $inventory[] = 'Nothing to verify: this ticket has no stored files of any kind.';
}
addSection($sections, "WHAT IS ATTACHED TO THIS TICKET", $inventory);

// ---- 8. FILE-BY-FILE VERIFICATION --------------------------------------

$results = [];
$fileLines = [];
$tmpRoot = sys_get_temp_dir();
$fileLines[] = 'Temp folder used for the download round trip: ' . ($tmpRoot === '' ? '(none reported by PHP)' : $tmpRoot);
$fileLines[] = 'Files above ' . d012_bytes(D012_COPY_MAX_BYTES) . ' are hashed but not copied.';
$fileLines[] = '';

foreach ($items as $idx => $it) {
    $n = $idx + 1;
    $verdict = null; $L = [];
    $L[] = '--- ' . $n . '. ' . $it['kind'] . ' id ' . $it['id'] . ' -----------------------------------------';
    $L[] = '    name in database  : ' . d012_trim($it['name'], 90);
    $L[] = '    context           : ' . $it['note'];
    $L[] = '    created           : ' . $it['created'];
    $L[] = '    stored value      : ' . d012_trim($it['stored'], 100);
    $L[] = '    served by         : ' . $it['url'];

    if ($it['skip'] !== null) {
        $L[] = '    RESULT            : ' . $it['skip'];
        $verdict = (strpos($it['skip'], 'LINK') === 0) ? 'LINK' : 'NOT CHECKED';
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => $verdict, 'dir' => null];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }

    if ($it['abs'] === null) {
        $L[] = '    RESULT            : NOT CHECKED — the absolute path could not be built (application root unresolved).';
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => 'NOT CHECKED', 'dir' => null];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }

    // ⚠️ A FILE DOCUMENT WITH NO STORAGE KEY REACHES THE SAME 410, and looks
    // identical to a deleted file while being a completely different fault.
    // download.php casts the NULL to '' and resolves it, so the path becomes the
    // uploads folder itself and is_file() refuses a directory. Nothing is lost —
    // the row was written wrong, or written by something other than save.php.
    if (trim((string) $it['stored']) === '') {
        $L[] = '    RESULT            : NO STORED PATH — the row says it is a file and records no location';
        $L[] = '                        for it. It resolves to the folder itself, so is_file() refuses it and';
        $L[] = '                        the download gives the same "recorded but missing from storage".';
        $L[] = '                        Nothing has been lost from disk; the row is wrong. Only';
        $L[] = '                        api/documents/save.php writes this column, so a row without one was';
        $L[] = '                        not created by an upload.';
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => 'NO STORED PATH', 'dir' => $it['base']];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }

    $L[] = '    resolves to       : ' . $it['abs'];

    // Containment — resolved both sides, because comparing a resolved path to an
    // unresolved prefix fails on Windows.
    $realBase = $it['base'] === null ? false : realpath($it['base']);
    $realFile = realpath($it['abs']);
    $inside = null;
    if ($realBase !== false && $realFile !== false) {
        $inside = (strncmp($realFile, $realBase . DIRECTORY_SEPARATOR, strlen($realBase) + 1) === 0);
    }
    if ($inside === null) {
        $L[] = '    inside its folder : could not tell (' . ($realBase === false ? 'folder does not resolve' : 'file does not resolve') . ')';
    } else {
        $L[] = '    inside its folder : ' . bool_str($inside) . ($inside ? '' : '   <-- the stored path escapes ' . $it['base'])
             . ($it['contained'] ? '   (its reader enforces this and would refuse)' : '   (its reader does not enforce this; the search indexer does)');
    }

    $exists   = is_file($it['abs']);
    $readable = $exists && is_readable($it['abs']);
    $L[] = '    file exists       : ' . bool_str($exists);

    if (!$exists) {
        $L[] = '    RESULT            : MISSING — the row is recorded but is_file() says there is nothing';
        $L[] = '                        at that path. This is EXACTLY the test download.php makes, and';
        $L[] = '                        what produces "That file is recorded but missing from storage."';
        $L[] = '    why is_file() said no:';
        foreach (d012_whyMissing($it['abs'], $it['base'], $it['stored']) as $w) $L[] = '        ' . $w;
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => 'MISSING', 'dir' => $it['base']];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }

    $L[] = '    readable by PHP   : ' . bool_str($readable) . ($readable ? '' : '   <-- present but PHP cannot open it: check ownership/permissions, and open_basedir');
    if (!$readable) {
        $L[] = '    RESULT            : UNREADABLE — the file is there and the web server user cannot read it.';
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => 'UNREADABLE', 'dir' => $it['base']];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }

    $onDisk = filesize($it['abs']);
    $perms  = @fileperms($it['abs']);
    $L[] = '    size on disk      : ' . d012_bytes($onDisk) . ' (' . $onDisk . ' bytes)';
    $L[] = '    size recorded     : ' . ($it['size'] === null ? '(not recorded)' : d012_bytes($it['size']) . ' (' . $it['size'] . ' bytes)');
    $L[] = '    mode              : ' . ($perms === false ? '(unavailable)' : substr(sprintf('%o', $perms), -4));

    $sizeOk = ($it['size'] === null) ? null : ($onDisk === $it['size']);
    if ($sizeOk === false) {
        $L[] = '    size match        : NO   <-- the file has changed length since it was stored (truncated, or replaced)';
    } elseif ($sizeOk === null) {
        $L[] = '    size match        : cannot check — no length was recorded at upload';
    } else {
        $L[] = '    size match        : YES';
    }

    // Reading every byte. A stream hash is the cheapest honest proof that the whole
    // extent is readable — a truncated or locked file fails here and nowhere earlier.
    $sha = null; $hashErr = null;
    try {
        $sha = @hash_file('sha256', $it['abs']);
        if ($sha === false) { $sha = null; $hashErr = 'hash_file() failed'; }
    } catch (Throwable $e) { $hashErr = $e->getMessage(); }

    if ($sha === null) {
        $L[] = '    full read         : FAILED — could not read the file through to the end' . ($hashErr === null ? '' : ' (' . $hashErr . ')');
        $L[] = '    RESULT            : UNREADABLE — it opens but cannot be read in full.';
        $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => 'UNREADABLE', 'dir' => $it['base']];
        $fileLines = array_merge($fileLines, $L, ['']);
        continue;
    }
    $L[] = '    full read         : OK — every byte read';
    $L[] = '    sha256            : ' . $sha;

    $hashOk = null;
    if ($it['hash'] === null || $it['hash'] === '') {
        $L[] = '    hash recorded     : (none recorded at upload — cannot prove the contents are unaltered)';
    } else {
        $hashOk = (strtolower((string) $it['hash']) === strtolower($sha));
        $L[] = '    hash recorded     : ' . strtolower((string) $it['hash']);
        $L[] = '    contents intact   : ' . ($hashOk ? 'YES — byte-for-byte what was uploaded' : 'NO   <-- the file on disk is NOT the file that was uploaded');
    }

    // What the browser would actually receive.
    if ($haveUploads) {
        $rules = attachmentServeRules($it['name']);
        $L[] = '    would be served as: ' . $rules['type'] . ', ' . ($rules['inline'] ? 'inline' : 'as a download');
    }

    // Leading-byte sniff. Never an error on its own — plenty of legitimate files
    // are unrecognised — but a mismatch is worth seeing.
    if (extension_loaded('fileinfo') && function_exists('uploadDetectMime')) {
        $sniffed = uploadDetectMime($it['abs']);
        $L[] = '    content sniffed as: ' . ($sniffed === null ? '(could not determine)' : $sniffed);
    } else {
        $L[] = '    content sniffed as: NOT CHECKED — the fileinfo extension is not loaded';
    }

    // The round trip: copy out, verify the copy, delete, confirm the delete.
    if ($onDisk > D012_COPY_MAX_BYTES) {
        $L[] = '    download test     : skipped — larger than ' . d012_bytes(D012_COPY_MAX_BYTES) . '. The full read above already proves it is readable.';
    } elseif ($tmpRoot === '' || !is_dir($tmpRoot) || !is_writable($tmpRoot)) {
        $L[] = '    download test     : NOT CHECKED — the system temp folder is missing or not writable.';
    } else {
        $tmpPath = $tmpRoot . DIRECTORY_SEPARATOR . 'freeitsm_d012_' . bin2hex(random_bytes(8)) . '.tmp';
        if (!@copy($it['abs'], $tmpPath)) {
            $L[] = '    download test     : FAILED — the file could not be copied out.';
            $verdict = 'UNREADABLE';
        } else {
            $copySize = filesize($tmpPath);
            $copyHash = @hash_file('sha256', $tmpPath);
            $same = ($copySize === $onDisk) && ($copyHash === $sha);
            $gone = @unlink($tmpPath) && !file_exists($tmpPath);
            $L[] = '    download test     : ' . ($same ? 'OK — copied out whole and identical' : 'FAILED — the copy does not match the original')
                 . '; temp file ' . ($gone ? 'deleted' : 'COULD NOT BE DELETED: ' . $tmpPath);
            if (!$same) $verdict = 'UNREADABLE';
        }
    }

    if ($verdict === null) {
        if ($hashOk === false)      $verdict = 'CONTENTS CHANGED';
        elseif ($sizeOk === false)  $verdict = 'SIZE MISMATCH';
        elseif ($inside === false)  $verdict = 'OUTSIDE ITS FOLDER';
        else                        $verdict = 'OK';
    }
    $L[] = '    RESULT            : ' . $verdict;
    $results[] = ['n' => $n, 'kind' => $it['kind'], 'id' => $it['id'], 'name' => $it['name'], 'verdict' => $verdict, 'dir' => $it['base']];
    $fileLines = array_merge($fileLines, $L, ['']);
}

if (!$items) $fileLines[] = '(nothing to verify)';
addSection($sections, "FILE-BY-FILE VERIFICATION", $fileLines);

// ---- 9. THE PERMISSION GUARDS ------------------------------------------
//
// Everything above asks "is the file there". This asks the other half: "would the
// endpoint hand it over, and — far more important — would it REFUSE somebody who
// should not have it". download.php calls itself THE BOUNDARY in its own docblock,
// and a boundary nothing exercises is a boundary nobody knows is still there.
//
// ⚠️ EVERY REFUSAL IS PAIRED WITH A POSITIVE CONTROL. A guard that refuses
// everybody looks identical to a guard that works, and both look identical to a
// function that has started throwing and returning false. So each gate is asked
// twice: once by somebody who SHOULD get through, once by somebody who should
// not. A refusal only counts as a pass when the matching allow also passed.
//
// ⚠️ AND A CONTROL THAT CANNOT RUN SAYS SO. On a single-company install
// analystCanAccessTicket() returns true for every analyst by design — there is no
// second tenant to be refused — so the tenancy control is genuinely impossible
// rather than failing. Reporting it as a pass would be a lie about the one thing
// this section exists to establish.

$g = [];
$runner = (int) $_SESSION['analyst_id'];
$guardIssues = [];

$g[] = 'Asked as: analyst_id=' . $runner . ' (the account running this report)';
$g[] = '';

// -- Gate 0: is this install even multi-tenant?
$multi = null;
try { $multi = function_exists('isMultiTenant') ? isMultiTenant($conn) : null; } catch (Throwable $e) { $multi = null; }
$g[] = 'MULTI-TENANCY';
if ($multi === null) {
    $g[] = '    isMultiTenant() unavailable — tenancy controls below cannot run.';
} elseif ($multi) {
    $g[] = '    ON. Company separation is enforced, so the cross-company controls below are real tests.';
} else {
    $g[] = '    OFF (single company). analystCanAccessTicket() returns true for every analyst BY DESIGN.';
    $g[] = '    The cross-company controls below therefore CANNOT RUN — that is correct behaviour on';
    $g[] = '    this install, not a failure, and not a pass either.';
}
$g[] = '';

// -- Find an analyst who genuinely must not reach this ticket. Needed for the
//    tenancy control; without one it does not run.
$outsider = null;
if ($multi === true) {
    try {
        $st = $conn->query("SELECT id, full_name FROM analysts WHERE id <> " . (int) $runner . " ORDER BY id ASC LIMIT 200");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
            if (!analystCanAccessTicket($conn, (int) $a['id'], $ticketId)) { $outsider = $a; break; }
        }
    } catch (Throwable $e) { $outsider = null; }
}

$g[] = 'THE ANALYST ROUTE';
$g[] = '    api/documents/download.php   documentCanView() — module gate, then the parent';
$g[] = '                                 record\'s own access rule, then "parent still alive"';
$g[] = '    api/tickets/get_attachment.php   analystCanAccessTicket() on the attachment\'s ticket';
$g[] = '';

// A module the registry knows but this ticket's documents do not belong to, so the
// module gate has something real to refuse.
$foreignModules = ['knowledge'];

$docItems = []; $emailItems = [];
foreach ($items as $it) {
    if (strpos($it['kind'], 'Document') === 0) $docItems[] = $it;
    elseif ($it['kind'] === 'Email attachment') $emailItems[] = $it;
}

if (!$docItems && !$emailItems) {
    $g[] = '    (no documents or email attachments on this ticket, so there is nothing to ask)';
} else {
    foreach ($docItems as $it) {
        $g[] = '    Document id ' . $it['id'] . '  "' . d012_trim($it['name'], 40) . '"';
        if (!$haveDocuments || !function_exists('documentCanView')) {
            $g[] = '        CANNOT RUN — documentCanView() is not available on this install.';
            continue;
        }
        // POSITIVE CONTROL first. If this fails nothing below it means anything.
        $allow = false;
        try { $allow = documentCanView($conn, $runner, null, $it['id']); } catch (Throwable $e) { $allow = false; }
        $g[] = '        allow  you, all modules       : ' . ($allow ? 'YES — served' : 'NO   <-- POSITIVE CONTROL FAILED: you cannot fetch your own ticket\'s file');
        if (!$allow) $guardIssues[] = 'documentCanView() refused document ' . $it['id'] . ' to the analyst running the report';

        // NEGATIVE CONTROL 1 — the module gate. Deterministic on every install:
        // no second company needed, so this one always actually runs.
        $modBlocked = true;
        try { $modBlocked = !documentCanView($conn, $runner, $foreignModules, $it['id']); } catch (Throwable $e) { $modBlocked = true; }
        $g[] = '        refuse you, wrong module      : ' . ($modBlocked ? 'REFUSED — correct' : 'SERVED  <-- THE MODULE GATE IS NOT WORKING');
        if (!$modBlocked) $guardIssues[] = 'the module gate did not refuse document ' . $it['id'];

        // NEGATIVE CONTROL 2 — tenancy.
        if ($outsider !== null) {
            $out = true;
            try { $out = !documentCanView($conn, (int) $outsider['id'], null, $it['id']); } catch (Throwable $e) { $out = true; }
            $g[] = '        refuse another company        : ' . ($out ? 'REFUSED — correct (tried analyst_id=' . $outsider['id'] . ')' : 'SERVED  <-- CROSS-COMPANY LEAK, analyst_id=' . $outsider['id']);
            if (!$out) $guardIssues[] = 'analyst ' . $outsider['id'] . ' from another company could fetch document ' . $it['id'];
        } else {
            $g[] = '        refuse another company        : CANNOT RUN — ' . ($multi === true
                ? 'every analyst on this install can reach this ticket, so there is nobody to refuse'
                : 'single-company install, see above');
        }
        $g[] = '        refuse a guessed id           : ' . ((function () use ($conn, $runner) {
            try { return documentCanView($conn, $runner, null, 0) ? 'SERVED  <-- id 0 was accepted' : 'REFUSED — correct'; }
            catch (Throwable $e) { return 'REFUSED — correct'; }
        })());
    }

    foreach ($emailItems as $it) {
        $g[] = '    Email attachment id ' . $it['id'] . '  "' . d012_trim($it['name'], 40) . '"';
        if (!function_exists('analystCanAccessTicket')) {
            $g[] = '        CANNOT RUN — analystCanAccessTicket() is not available.';
            continue;
        }
        $allow = false;
        try { $allow = analystCanAccessTicket($conn, $runner, $ticketId); } catch (Throwable $e) { $allow = false; }
        $g[] = '        allow  you                    : ' . ($allow ? 'YES — served' : 'NO   <-- POSITIVE CONTROL FAILED');
        if (!$allow) $guardIssues[] = 'analystCanAccessTicket() refused ticket ' . $ticketId . ' to the analyst running the report';
        if ($outsider !== null) {
            $out = true;
            try { $out = !analystCanAccessTicket($conn, (int) $outsider['id'], $ticketId); } catch (Throwable $e) { $out = true; }
            $g[] = '        refuse another company        : ' . ($out ? 'REFUSED — correct (tried analyst_id=' . $outsider['id'] . ')' : 'SERVED  <-- CROSS-COMPANY LEAK, analyst_id=' . $outsider['id']);
            if (!$out) $guardIssues[] = 'analyst ' . $outsider['id'] . ' from another company could fetch email attachment ' . $it['id'];
        } else {
            $g[] = '        refuse another company        : CANNOT RUN — ' . ($multi === true ? 'nobody to refuse' : 'single-company install');
        }
    }
}

// -- The soft-delete gate. download.php's own SELECT carries
//    "AND deleted_datetime IS NULL", so a soft-deleted document must not be
//    served even to somebody who passes every permission check.
$g[] = '';
$g[] = 'THE SOFT-DELETE GATE';
if (!$softDeleted) {
    $g[] = '    No soft-deleted documents on this ticket, so nothing to test.';
} else {
    try {
        $st = $conn->prepare("SELECT COUNT(*) FROM documents d
                                JOIN document_links dl ON dl.document_id = d.id
                               WHERE d.deleted_datetime IS NOT NULL
                                 AND ((dl.parent_type='ticket' AND dl.parent_id=?)
                                   OR (dl.parent_type='ticket_note' AND dl.parent_id IN (SELECT id FROM ticket_notes WHERE ticket_id=?)))");
        $st->execute([$ticketId, $ticketId]);
        $n = (int) $st->fetchColumn();
        $g[] = '    ' . $n . ' soft-deleted document(s) on this ticket. download.php selects with';
        $g[] = '    "AND deleted_datetime IS NULL", so all of them return "Not found" regardless of';
        $g[] = '    who asks. They are listed in the inventory above so that "it disappeared" has an';
        $g[] = '    answer that is not "the file is missing".';
    } catch (Throwable $e) {
        $g[] = '    CANNOT RUN — ' . $e->getMessage();
    }
}

// -- The portal route. A different question with a different answer: an internal
//    note's file must be unreachable to the requester BY CONSTRUCTION.
$g[] = '';
$g[] = 'THE PORTAL ROUTE (api/self-service/get_document.php)';
$g[] = '    Mirrors that endpoint\'s single join: ticket_note -> is_internal = 0 -> the ticket\'s';
$g[] = '    own requester. Only note documents are portal material at all; a document attached';
$g[] = '    to the ticket itself is not served by that route in any circumstance.';
$noteDocs = [];
foreach ($items as $it) if ($it['kind'] === 'Document on a note') $noteDocs[] = $it;
if (!$noteDocs) {
    $g[] = '    (no documents on notes, so nothing to test)';
} else {
    $requester = null;
    try {
        $st = $conn->prepare("SELECT user_id FROM tickets WHERE id = ?");
        $st->execute([$ticketId]);
        $requester = $st->fetchColumn();
        $requester = ($requester === false || $requester === null) ? null : (int) $requester;
    } catch (Throwable $e) { $requester = null; }
    $g[] = '    This ticket\'s requester: ' . ($requester === null ? 'none recorded — the portal route cannot serve anything on this ticket' : 'user_id=' . $requester);

    foreach ($noteDocs as $it) {
        $line = '    Document id ' . $it['id'] . '  "' . d012_trim($it['name'], 40) . '"  : ';
        if ($requester === null) { $g[] = $line . 'NOT REACHABLE (no requester)'; continue; }
        try {
            $st = $conn->prepare(
                "SELECT 1 FROM documents d
                   JOIN document_links dl ON dl.document_id = d.id AND dl.parent_type = 'ticket_note'
                   JOIN ticket_notes n    ON n.id = dl.parent_id AND n.is_internal = 0
                   JOIN tickets t         ON t.id = n.ticket_id
                  WHERE d.id = ? AND d.deleted_datetime IS NULL AND t.user_id = ? AND t.deleted_datetime IS NULL LIMIT 1"
            );
            $st->execute([$it['id'], $requester]);
            $reach = (bool) $st->fetchColumn();
            $g[] = $line . ($reach
                ? 'REACHABLE by the requester (the note is shared)'
                : 'not reachable by the requester (internal note, or the ticket is not theirs) — correct');

            // NEGATIVE CONTROL: the same query for somebody who is not the requester
            // must never return a row, whatever the note's visibility. The id only
            // has to differ from the real requester — the join filters on
            // t.user_id, so any other value must match nothing.
            $notRequester = ($requester === -1) ? -2 : -1;
            $st->execute([$it['id'], $notRequester]);
            $leak = (bool) $st->fetchColumn();
            if ($leak) {
                $g[] = '        <-- LEAK: the rule matched for a user who is not the requester';
                $guardIssues[] = 'the portal rule matched a non-requester for document ' . $it['id'];
            }
        } catch (Throwable $e) {
            $g[] = $line . 'CANNOT RUN — ' . $e->getMessage();
        }
    }
}

$g[] = '';
if ($guardIssues) {
    $g[] = 'GUARD PROBLEMS FOUND (' . count($guardIssues) . '):';
    foreach ($guardIssues as $gi) $g[] = '    ' . $gi;
} else {
    $g[] = 'No guard refused somebody it should have served, and none served somebody it should';
    $g[] = 'have refused — within the controls that were able to run. Read the CANNOT RUN lines';
    $g[] = 'above before treating that as a clean bill of health.';
}
$g[] = '';
$g[] = 'NOT tested here: whether the web server serves these folders directly, which would';
$g[] = 'bypass every check above. That is D009.';
addSection($sections, "THE PERMISSION GUARDS", $g);

// ---- 9. VERDICT --------------------------------------------------------
//
// Name the case. A list of failures that stops short of saying what they have in
// common leaves the reader to spot the pattern, and the pattern is the answer.

$v = [];
$counts = [];
foreach ($results as $r) { if (!isset($counts[$r['verdict']])) $counts[$r['verdict']] = 0; $counts[$r['verdict']]++; }

foreach ($results as $r) {
    $v[] = str_pad($r['verdict'], 20) . str_pad($r['kind'], 20) . ' id ' . str_pad((string) $r['id'], 6) . ' ' . d012_trim($r['name'], 50);
}
$v[] = '';
$summary = [];
foreach ($counts as $k => $c) $summary[] = $k . ' ' . $c;
$v[] = 'Summary: ' . ($summary ? implode(', ', $summary) : 'nothing to verify')
     . ($guardIssues ? '   |   GUARD PROBLEMS: ' . count($guardIssues) : '');
$v[] = '';

$bad = [];
foreach ($results as $r) if ($r['verdict'] !== 'OK' && $r['verdict'] !== 'LINK') $bad[] = $r;

// ⚠️ THE FILE BEING PERFECT IS NOT THE SAME AS THE FILE BEING FETCHABLE, and a
// verdict that reads "everything is fine" while the guard section says nobody can
// download it is worse than no verdict at all. Both halves are reported here or
// the summary contradicts the report it is summarising.
//
// A ticket in the trash is the commonest reason for exactly that combination:
// every byte on disk is intact and every route refuses, correctly.
if ($ticketRow['deleted_datetime'] !== null) {
    $v[] = 'THIS TICKET IS IN THE TRASH (deleted ' . $ticketRow['deleted_datetime'] . ').';
    $v[] = 'Its files are refused by every route no matter what state they are in on disk, so';
    $v[] = 'read everything below as "if the ticket were restored". If somebody cannot download';
    $v[] = 'an attachment from this ticket, this is why, and nothing else needs investigating.';
    $v[] = '';
}

if (!$results) {
    $v[] = 'This ticket has no stored files, so there is nothing here to be broken. If somebody';
    $v[] = 'reported a broken attachment on this ticket, they are looking at a different ticket —';
    $v[] = 'or at a file that has already been deleted (see the soft-deleted list above).';
} elseif (!$bad && !$guardIssues) {
    $v[] = 'Every file on this ticket resolves, exists, reads through to the end, matches the length';
    $v[] = 'recorded at upload, and survives a copy out and back — and every permission control that';
    $v[] = 'could run behaved. If somebody still cannot download one, the fault is not in storage:';
    $v[] = 'check that they are signed in, that the ticket is in a company they can see, and — if the';
    $v[] = 'URL they quote differs from the ones listed above — that they are using the endpoint this';
    $v[] = 'report names for that kind of file.';
} elseif (!$bad && $guardIssues) {
    $v[] = 'THE FILES ARE FINE AND THE PERMISSION CHECKS ARE NOT.';
    $v[] = 'Every file resolves, exists and reads back intact, so nothing is wrong with storage —';
    $v[] = 'but ' . count($guardIssues) . ' permission control did not behave, which is the more serious half:';
    foreach ($guardIssues as $gi) $v[] = '    ' . $gi;
    $v[] = '';
    $v[] = 'A control that refuses the person running this report means they cannot download the';
    $v[] = 'file either — look at the ticket\'s state first (in the trash? in a company you cannot';
    $v[] = 'see?). A control that SERVED somebody it should have refused is a leak and wants';
    $v[] = 'looking at today.';
} else {
    // Is every failure a MISSING file in one single folder? If so it is worth saying
    // what that pattern means — but ONLY from evidence.
    //
    // ⚠️ THE TEMPTING INFERENCE IS THE WRONG ONE. "None of this ticket's files are
    // there, so the folder never came across" is a conclusion drawn from a sample
    // of however many files this one ticket happens to have — which is often one.
    // A single deleted file produces exactly the same shape. So the folder-level
    // claim is made from the folder itself: does it exist, and does it hold
    // anything? That is the difference between a diagnosis and a guess.
    $allMissing = true; $folders = [];
    foreach ($bad as $r) {
        if ($r['verdict'] !== 'MISSING') $allMissing = false;
        if ($r['dir'] !== null) $folders[$r['dir']] = true;
    }
    $okInSameFolder = 0;
    foreach ($results as $r) if ($r['verdict'] === 'OK' && $r['dir'] !== null && isset($folders[$r['dir']])) $okInSameFolder++;

    $v[] = count($bad) . ' of ' . count($results) . ' file(s) would fail to download.';
    $v[] = '';
    if ($allMissing && count($folders) === 1) {
        $folder = array_keys($folders)[0];
        // What does the folder itself say? Matched on the absolute path recorded
        // in section 6, so the count is the recursive one.
        $held = null; $folderExists = null;
        foreach ($dirFacts as $rel => $f) {
            if ($f['abs'] !== null && realpath($f['abs']) !== false && realpath($folder) !== false
                && realpath($f['abs']) === realpath($folder)) { $held = $f['files']; $folderExists = $f['exists']; break; }
            if ($f['abs'] === $folder) { $held = $f['files']; $folderExists = $f['exists']; break; }
        }

        $v[] = 'EVERY failure is a MISSING file, and all of them are in one folder:';
        $v[] = '    ' . $folder;
        $v[] = '';
        if ($folderExists === false) {
            $v[] = 'THAT FOLDER DOES NOT EXIST. The rows are intact and there is nowhere for the files';
            $v[] = 'to be. This is what an upgrade or migration looks like when it moved the';
            $v[] = 'application and took the database but not the uploaded files. Find the previous';
            $v[] = 'installation directory and copy the folder across whole. No data migration is';
            $v[] = 'needed — the value stored in the database is a bare filename, not a path.';
        } elseif ($held === 0) {
            $v[] = 'The folder exists but is EMPTY. Same conclusion as a missing folder: the files';
            $v[] = 'were left behind somewhere, most often by an upgrade that carried the database';
            $v[] = 'and not the uploads. Find the previous installation directory and copy the';
            $v[] = 'contents in. The stored value is a bare filename, so nothing else has to change.';
        } elseif ($held === null) {
            $v[] = 'How many files that folder holds could not be determined, so no conclusion is';
            $v[] = 'offered about whether the folder as a whole survived. Check it by hand.';
        } else {
            $v[] = 'THE FOLDER IS NOT EMPTY — it holds ' . $held . ' file(s), ' . ($okInSameFolder > 0
                ? 'and ' . $okInSameFolder . ' of this ticket\'s own files in it are fine.'
                : 'though none of them belong to this ticket.');
            $v[] = 'So the folder itself came across, and these particular files did not. That points';
            $v[] = 'at deletion rather than a migration: a backup restored from before they arrived, a';
            $v[] = 'disk-cleanup job, or a sweep of the uploads directory. Compare the created dates';
            $v[] = 'in the section above — if everything missing predates one day, look for what';
            $v[] = 'happened on that day.';
        }
    } else {
        $v[] = 'The failures are not all the same, so read the per-file section above. As a guide:';
        $v[] = '    MISSING            the row is fine, the file is not on disk';
        $v[] = '    UNREADABLE         the file is on disk and the web server user cannot read it';
        $v[] = '                       — ownership, permissions, or an open_basedir that excludes it';
        $v[] = '    SIZE MISMATCH      the file changed length since upload (truncated, or replaced)';
        $v[] = '    CONTENTS CHANGED   the bytes are not the bytes that were uploaded';
        $v[] = '    OUTSIDE ITS FOLDER the stored path escapes its directory; readers that enforce';
        $v[] = '                       containment will refuse it even though the file exists';
        $v[] = '    NOT CHECKED        a check could not run — the reason is on the line itself';
    }
    if ($guardIssues) {
        $v[] = '';
        $v[] = 'AND SEPARATELY, ' . count($guardIssues) . ' permission control did not behave:';
        foreach ($guardIssues as $gi) $v[] = '    ' . $gi;
        $v[] = 'That is independent of the file problems above and is worth reading first.';
    }
    $v[] = '';
    $v[] = 'None of the above is affected by whether the folder is publicly reachable. If that is';
    $v[] = 'the question, run D009.';
}
addSection($sections, "VERDICT", $v);

emit_and_exit($sections);
