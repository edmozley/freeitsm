<?php
/**
 * D008 — War room health.
 *
 * ⚠️ THIS MATTERS MORE THAN A HEALTH CHECK USUALLY DOES, because the war room is
 * a BREAK-GLASS feature. Every other module tells you it is broken the moment you
 * try to use it. This one gets opened for the first time during an incident, when
 * the usual chat is already down and nobody has any appetite for discovering that
 * the attachments folder is unwritable or that the all-hands channel was never
 * created. The entire value of this tool is that it can be run on a quiet
 * Tuesday.
 *
 * So it checks the things that fail SILENTLY:
 *   - a missing all-hands channel: the war room opens with nowhere to talk
 *   - a foreign key with the wrong delete rule: deleting one analyst quietly
 *     destroys the record of an incident, and nobody finds out until the review
 *   - an unwritable or unprotected attachments folder
 *   - files on disk with no row pointing at them (retention deletes rows; only
 *     the sweep deletes bytes)
 *   - Warbot's tools, which are the half that must work with no internet
 *
 * ⚠️ Unlike D007 this needs no probe row left behind: the end-to-end write test
 * runs inside a TRANSACTION and is rolled back. D007 could not do that because
 * MATCH...AGAINST cannot see uncommitted rows; nothing here has that problem, so
 * nothing here should flash up in somebody's open war room.
 *
 * Output: plain text, section-delimited with === HEADERS === for easy skimming.
 */

@session_start();

$DIAG_ID   = 'D008';
$DIAG_NAME = 'War room health';

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../../../includes/functions.php';

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

require_once __DIR__ . '/../../../includes/warroom.php';
require_once __DIR__ . '/../../../includes/warbot/tools.php';
require_once __DIR__ . '/../../../includes/ai_settings.php';

$sections = [];
$problems = [];
/**
 * Adds one titled section ("=== TITLE ===" and its lines) to the report; $body is a
 * string or an array of lines.
 *
 * @param mixed $sections  the report so far, a list of section strings - appended to by reference
 * @param mixed $title  the section heading, printed between === marks
 * @param mixed $body  the section text - a string, or an array of lines
 */
function addSection(&$sections, $title, $body) {
    if (is_array($body)) $body = implode("\n", $body);
    $sections[] = "=== {$title} ===\n" . rtrim($body, "\n");
}
/**
 * Formats a boolean as YES or NO for the report.
 *
 * @param mixed $v  any value, judged as true or false
 */
function yn($v) { return $v ? 'YES' : 'NO'; }
/**
 * Adds the VERDICT section - the numbered problems found, or an all-clear - then prints
 * every section as plain text and stops.
 *
 * @param mixed $sections  the report, a list of section strings as built by addSection()
 * @param mixed $problems  the problems found, as plain sentences; empty prints the all-good verdict
 */
function emit_and_exit($sections, $problems) {
    if ($problems) {
        $n = 1;
        $lines = [count($problems) . ' problem(s) found.', ''];
        foreach ($problems as $p) $lines[] = ($n++) . '. ' . $p;
        addSection($sections, 'VERDICT', $lines);
    } else {
        addSection($sections, 'VERDICT', [
            'All good — the war room is ready to use.',
            '',
            'Worth remembering: this is a fallback tool, so the best time to have run',
            'this check is before you need it. Nothing here requires the internet',
            'except Warbot\'s plain-English mode.',
        ]);
    }
    if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');
    echo implode("\n\n", $sections) . "\n";
    exit;
}

try {
    $conn   = connectToDatabase();
    $dbName = $conn->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    addSection($sections, 'FATAL', 'Could not connect to the database: ' . $e->getMessage());
    emit_and_exit($sections, ['The database is unreachable, so nothing else could be checked.']);
}

/* ── 1. tables ─────────────────────────────────────────────────────────────── */
$expected = [
    'warroom_channels'        => 'the conversations themselves, of four kinds',
    'warroom_channel_members' => 'membership for private channels and DMs',
    'warroom_messages'        => 'what was said',
    'warroom_attachments'     => 'files hung off a message',
    'warroom_mentions'        => 'who was named in a message',
    'warroom_reads'           => 'how far each analyst has read',
    'warroom_presence'        => 'who is here now',
];
$lines = [];
$missing = [];
foreach ($expected as $t => $why) {
    $st = $conn->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :d AND TABLE_NAME = :t");
    $st->execute([':d' => $dbName, ':t' => $t]);
    $engine = $st->fetchColumn();
    if ($engine === false) {
        $missing[] = $t;
        $lines[] = sprintf('%-24s MISSING   (%s)', $t, $why);
    } else {
        $cnt = (int) $conn->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
        $lines[] = sprintf('%-24s present   %-8s %6d row(s)   (%s)', $t, $engine, $cnt, $why);
    }
}
addSection($sections, 'TABLES', $lines);
if ($missing) {
    $problems[] = 'Tables missing: ' . implode(', ', $missing) . '. Run System → Database Verification, which creates them.';
    emit_and_exit($sections, $problems);
}

/* ── 2. delete rules — the ones that differ ON PURPOSE ─────────────────────── */
// A wrong rule here is the most damaging thing on this page and the least
// visible: it does nothing at all until somebody deletes a team or an analyst,
// at which point the record of an incident quietly goes with them.
$wantFk = [
    'fk_warroom_messages_channel'  => ['warroom_messages',    'CASCADE',  'delete a channel and its conversation goes with it'],
    'fk_warroom_messages_analyst'  => ['warroom_messages',    'SET NULL', 'delete an ANALYST and the conversation SURVIVES — this is the important one'],
    'fk_warroom_messages_deleter'  => ['warroom_messages',    'SET NULL', 'a tombstone outlives the person who wrote it'],
    'fk_warroom_channels_team'     => ['warroom_channels',    'CASCADE',  'a team channel goes with its team'],
    'fk_warroom_channels_creator'  => ['warroom_channels',    'SET NULL', 'a channel outlives whoever opened it'],
    'fk_warroom_attachments_message' => ['warroom_attachments','CASCADE', 'attachment rows go with their message'],
    'fk_warroom_mentions_message'  => ['warroom_mentions',    'CASCADE',  'mentions go with their message'],
];
$have = [];
$st = $conn->prepare(
    "SELECT CONSTRAINT_NAME, TABLE_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
      WHERE CONSTRAINT_SCHEMA = :d AND TABLE_NAME LIKE 'warroom%'"
);
$st->execute([':d' => $dbName]);
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[$r['CONSTRAINT_NAME']] = $r['DELETE_RULE'];

$lines = [];
foreach ($wantFk as $fk => [$tbl, $rule, $why]) {
    if (!isset($have[$fk])) {
        $lines[] = sprintf('%-32s ABSENT     want ON DELETE %-8s  (%s)', $fk, $rule, $why);
        $problems[] = "Foreign key $fk is missing on $tbl. Run Database Verification.";
    } elseif ($have[$fk] !== $rule) {
        $lines[] = sprintf('%-32s WRONG      is %-8s want %-8s  (%s)', $fk, $have[$fk], $rule, $why);
        $problems[] = "Foreign key $fk deletes with " . $have[$fk] . " but should be $rule — $why.";
    } else {
        $lines[] = sprintf('%-32s ok         ON DELETE %-8s  (%s)', $fk, $rule, $why);
    }
}
addSection($sections, 'DELETE RULES (these differ deliberately)', $lines);

/* ── 3. channels ───────────────────────────────────────────────────────────── */
$lines = [];
$allHands = (int) $conn->query("SELECT COUNT(*) FROM warroom_channels WHERE kind = 'all'")->fetchColumn();
$lines[] = 'All-hands channel: ' . ($allHands === 1 ? 'present' : ($allHands === 0 ? 'MISSING' : "DUPLICATED ($allHands rows)"));
if ($allHands === 0) {
    $problems[] = 'There is no all-hands channel, so an analyst opening the war room has nowhere to talk. Database Verification creates it; it is also created on demand the next time somebody loads the page.';
} elseif ($allHands > 1) {
    $problems[] = "There are $allHands all-hands channels. Only one should exist — the extras will confuse the channel list.";
}

foreach (['team' => 'team channels', 'custom' => 'channels somebody created', 'dm' => 'direct message threads'] as $k => $label) {
    $n = (int) $conn->query("SELECT COUNT(*) FROM warroom_channels WHERE kind = '$k'")->fetchColumn();
    $a = (int) $conn->query("SELECT COUNT(*) FROM warroom_channels WHERE kind = '$k' AND archived_datetime IS NOT NULL")->fetchColumn();
    $lines[] = sprintf('%-28s %4d  (%d archived)', $label, $n, $a);
}

// A team with no channel is NOT a fault — channels are created on demand the
// first time a member looks — but saying so beats leaving somebody to wonder.
$noChan = (int) $conn->query(
    "SELECT COUNT(*) FROM teams t
      WHERE (t.is_active IS NULL OR t.is_active = 1)
        AND NOT EXISTS (SELECT 1 FROM warroom_channels c WHERE c.kind = 'team' AND c.team_id = t.id)"
)->fetchColumn();
$lines[] = 'Active teams with no channel yet: ' . $noChan . ($noChan ? '  (normal — created the first time a member opens the war room)' : '');

$dupDm = (int) $conn->query(
    "SELECT COUNT(*) FROM (SELECT dm_key FROM warroom_channels WHERE dm_key IS NOT NULL GROUP BY dm_key HAVING COUNT(*) > 1) x"
)->fetchColumn();
$lines[] = 'Duplicated direct-message threads: ' . $dupDm;
if ($dupDm > 0) $problems[] = "$dupDm pair(s) of analysts have more than one DM thread, so each is seeing half the conversation. The UNIQUE index on warroom_channels.dm_key is missing — run Database Verification.";

addSection($sections, 'CHANNELS', $lines);

/* ── 4. attachments: the folder, and files with no row ─────────────────────── */
$dir   = rtrim(WARROOM_ATTACH_DIR, '/\\');
$real  = is_dir($dir) ? realpath($dir) : false;
if ($real !== false) $dir = $real;      // the constant is built with /../ in it
$lines = [];
$lines[] = 'Folder: ' . $dir;
$lines[] = 'Exists: ' . yn(is_dir($dir)) . '   Writable: ' . yn(is_dir($dir) && is_writable($dir));
if (!is_dir($dir)) {
    $problems[] = 'The attachments folder does not exist. It is created on the first upload, but a folder that cannot be created means attachments will fail silently at the worst moment.';
} elseif (!is_writable($dir)) {
    $problems[] = 'The attachments folder is not writable, so attaching a file will fail.';
}

foreach (['.htaccess' => 'Apache', 'web.config' => 'IIS'] as $guard => $server) {
    $ok = is_file($dir . DIRECTORY_SEPARATOR . $guard);
    $lines[] = sprintf('%-12s (%s protection): %s', $guard, $server, $ok ? 'present' : 'MISSING');
    if (!$ok) $problems[] = "$guard is missing from the attachments folder, so on $server the uploaded files may be fetchable directly instead of only through the authorising endpoint.";
}

// The files themselves: the war-room store's own listing - this folder, or S3 when
// System -> Storage keeps the store there (the folder then only stages uploads).
require_once __DIR__ . '/../../../includes/storage/stores.php';
$stored = null;
try {
    $lines[] = 'Stored in: ' . (storeLocation($conn, 'warroom') === 's3' ? 'S3-compatible storage' : 'this folder')
             . (storePrevious($conn, 'warroom') !== null ? ' (a move is running)' : '');
    $stored = storeList($conn, 'warroom');
} catch (Throwable $e) {
    $lines[] = 'Stored files: could not be listed - ' . $e->getMessage();
    $problems[] = 'The war-room store cannot be listed (' . $e->getMessage() . '), so attachments cannot be checked.';
}
if ($stored !== null) {
    $known = [];
    foreach ($conn->query("SELECT stored_name FROM warroom_attachments") as $r) $known[$r['stored_name']] = true;
    $onDisk = 0; $orphans = 0; $bytes = 0;
    foreach ($stored as $f => $o) {
        $onDisk++; $bytes += (int) $o['size'];
        if (!isset($known[$f])) $orphans++;
    }
    $lines[] = sprintf('Files on disk: %d (%s)   Rows in the database: %d', $onDisk, warroomD008Bytes($bytes), count($known));
    $lines[] = 'Files with no row: ' . $orphans . ($orphans ? '  (the hourly sweep removes these on the next message sent)' : '');
    // Not a "problem" unless it is a lot — a handful is just the sweep not having
    // run yet, and the sweep only runs when somebody posts.
    if ($orphans > 20) $problems[] = "$orphans attachment files have no database row. The sweep runs when a message is sent, so a quiet war room will accumulate them; they are safe to delete.";

    $missingFiles = 0;
    foreach (array_keys($known) as $n) if (!isset($stored[$n])) $missingFiles++;
    $lines[] = 'Rows with no file: ' . $missingFiles;
    if ($missingFiles > 0) $problems[] = "$missingFiles attachment(s) are recorded in the database but the file is gone from disk — those will 404 when somebody clicks them.";
}
addSection($sections, 'ATTACHMENTS', $lines);

/* ── 5. retention ──────────────────────────────────────────────────────────── */
$days  = warRoomRetentionDays($conn);
$lines = [];
$lines[] = 'Setting: ' . ($days === 0 ? 'keep forever' : "keep for $days day(s)");
$lines[] = 'Applied: on write (when a message is sent) — there is no cron job to set up.';
if ($days > 0) {
    $st = $conn->prepare("SELECT COUNT(*) FROM warroom_messages WHERE created_datetime < DATE_SUB(UTC_TIMESTAMP(), INTERVAL :d DAY)");
    $st->bindValue(':d', $days, PDO::PARAM_INT);
    $st->execute();
    $overdue = (int) $st->fetchColumn();
    $lines[] = 'Messages older than the setting: ' . $overdue . ($overdue ? '  (removed in batches as new messages arrive)' : '');
    // Pruning happens 500 at a time on send, so a backlog is expected on a quiet
    // room and only worth reporting when it is large enough to look stuck.
    if ($overdue > 5000) $problems[] = "$overdue messages are past the retention setting. Pruning happens 500 at a time when a message is sent, so a room this quiet will not catch up on its own.";
}
addSection($sections, 'RETENTION', $lines);

/* ── 6. Warbot ─────────────────────────────────────────────────────────────── */
$lines = [];
$tools = warbotTools();
$lines[] = count($tools) . ' tool(s) registered: ' . implode(', ', array_keys($tools));
$lines[] = 'All are read-only and run local SQL — they need no internet.';

// 🔑 THE CHECK THAT MATTERS. Actually RUN one, rather than reporting that it is
// registered. A tool whose SQL references a column that does not exist returns
// no rows rather than an error, so "registered" proves nothing at all.
try {
    $probe = warbotRunTool($conn, (int) $_SESSION['analyst_id'], 'service_status', []);
    $ok = trim($probe) !== '';
    $lines[] = 'Live tool run (service_status): ' . ($ok ? 'answered' : 'RETURNED NOTHING');
    $lines[] = '  ' . str_replace("\n", "\n  ", mb_substr(trim($probe), 0, 300));
    if (!$ok) $problems[] = 'A Warbot tool returned nothing at all, which usually means its query references a column that does not exist. Check the server error log.';
} catch (Throwable $e) {
    $lines[] = 'Live tool run: FAILED — ' . $e->getMessage();
    $problems[] = 'Warbot\'s tools are not working: ' . $e->getMessage();
}

try {
    $cfg = aiSettingsLoad($conn, 'warroom_ai');
    $has = ($cfg['api_key'] ?? '') !== '';
    $lines[] = '';
    $lines[] = 'AI provider (shared with the situation report): ' . ($has
        ? $cfg['provider'] . ' / ' . $cfg['model']
        : 'not configured');
    $lines[] = $has
        ? 'Warbot can answer plain-English questions, when the internet is available.'
        : 'Warbot answers slash commands only (/p1, /status, /changes, /oncall, /asset, /impact, /kb).';
    // Deliberately NOT a problem. Leaving it unset is a legitimate choice, and a
    // war room that works without the internet is the point of the module.
    $lines[] = 'This is not a fault either way — the commands above work regardless.';
} catch (Throwable $e) {
    $lines[] = 'AI provider: could not be read — ' . $e->getMessage();
}
addSection($sections, 'WARBOT', $lines);

/* ── 7. end-to-end, and rolled back ────────────────────────────────────────── */
// Proves the whole write path — insert, mention resolution, read-back, presence —
// without leaving anything behind for somebody with the page open to see.
$lines = [];
try {
    $chId = (int) $conn->query("SELECT id FROM warroom_channels WHERE kind = 'all' LIMIT 1")->fetchColumn();
    $me   = (int) $_SESSION['analyst_id'];
    $conn->beginTransaction();

    $id = warRoomSend($conn, $me, $chId, 'D008 probe — rolled back, never visible.');
    $lines[] = 'Post a message: ' . ($id > 0 ? "ok (id $id)" : 'FAILED');
    if ($id <= 0) $problems[] = 'Posting a message failed. The war room cannot be used.';

    $back = warRoomMessages($conn, $chId, $id - 1, 5);
    $found = false;
    foreach ($back as $m) if ((int)$m['id'] === $id) $found = true;
    $lines[] = 'Read it back: ' . ($found ? 'ok' : 'FAILED');
    if (!$found) $problems[] = 'A message was written but could not be read back — the channel read path is broken.';

    warRoomTouchPresence($conn, $me, $chId);
    $p = warRoomPresent($conn, 0, $chId);
    $lines[] = 'Presence records and reads back: ok (' . (count($p['here']) + count($p['elsewhere'])) . ' other analyst(s) currently seen)';

    $conn->rollBack();
    $lines[] = 'Rolled back — nothing was left in the channel.';

    $still = (int) $conn->query("SELECT COUNT(*) FROM warroom_messages WHERE id = " . (int)$id)->fetchColumn();
    $lines[] = 'Probe message still present afterwards: ' . yn($still > 0);
    if ($still > 0) $problems[] = 'The probe message survived the rollback, which means these tables are not transactional. Check that they are InnoDB.';
} catch (Throwable $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $lines[] = 'FAILED — ' . $e->getMessage();
    $problems[] = 'The end-to-end test failed: ' . $e->getMessage();
}
addSection($sections, 'END-TO-END TEST (written inside a transaction, then rolled back)', $lines);

/* ── 8. translations ───────────────────────────────────────────────────────── */
$lines = [];
$langDir = __DIR__ . '/../../../lang';
$locales = array_values(array_filter(scandir($langDir), function ($d) use ($langDir) {
    return $d !== '.' && $d !== '..' && is_dir($langDir . '/' . $d);
}));
$withFile = array_values(array_filter($locales, function ($l) use ($langDir) {
    return is_file($langDir . '/' . $l . '/war-room.php');
}));
$lines[] = count($withFile) . ' of ' . count($locales) . ' locale(s) have a war-room translation.';
$lines[] = 'Missing: ' . (implode(', ', array_values(array_diff($locales, $withFile))) ?: 'none');
$lines[] = 'A missing file falls back to English rather than erroring, so this is';
$lines[] = 'incomplete work rather than a fault.';
addSection($sections, 'TRANSLATIONS', $lines);

emit_and_exit($sections, $problems);

/**
 * Formats a byte count as B, KB or MB for the report.
 *
 * @param int $n  a size in bytes
 */
function warroomD008Bytes(int $n): string
{
    if ($n < 1024) return $n . ' B';
    if ($n < 1048576) return round($n / 1024) . ' KB';
    return round($n / 1048576, 1) . ' MB';
}
