<?php
/**
 * Debug Tool D011 — Why can't this sign-in method import people?
 *
 * D010 asks "can anybody sign in with this?". This asks a different question of
 * the same rows: "could this bring people in from the directory, and if not,
 * what exactly is in the way?"
 *
 * They are genuinely separate. A directory can be set up purely as a source of
 * people — so staff exist to be issued equipment — with single sign-on switched
 * off entirely. Nothing in the import path consults the global SSO switch. The
 * two get confused because both live on a screen called Authentication, under a
 * heading called Sign-in methods.
 *
 * The tool walks the gates in the order the code actually applies them and names
 * THE blocker rather than listing everything that happens to be unset. It also
 * reports the one disagreement in the codebase worth knowing about: running an
 * import from the UI ignores the provider's Enabled flag, and the scheduled run
 * does not — so a directory that imports perfectly when you press the button can
 * be silently skipped every night.
 *
 * Read-only, and it does NOT contact the directory: this is about configuration,
 * not reachability, and a tool that hangs on an unreachable host answers nothing.
 * Every DN, bind account and filter value is masked so the report can be sent on.
 *
 * Output: plain text, section-delimited with === HEADERS ===.
 */

@session_start();

$DIAG_ID   = 'D011';
$DIAG_NAME = 'Directory sync readiness — what is blocking each method';

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

if (!headers_sent()) header('Content-Type: text/plain; charset=utf-8');

$sections = [];
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
 * Prints every section, separated by blank lines, and stops - the end of the report, and
 * the early exit when a check cannot go on.
 *
 * @param mixed $sections  the report, a list of section strings as built by addSection()
 */
function emit_and_exit($sections) {
    echo implode("\n\n", $sections) . "\n";
    exit;
}

// ---- masking (see D010 — same rules, same reasons) ----------------------
/**
 * Masks a value for a report that will be sent on - all stars when very short, otherwise
 * the first one or two characters (and the last, for a long value) with stars between.
 *
 * @param mixed $s  the value to mask; empty gives (empty)
 */
function mask($s) {
    $s = (string)$s;
    if ($s === '') return '(empty)';
    if (strlen($s) <= 3) return str_repeat('*', strlen($s));
    if (strlen($s) <= 8) return substr($s, 0, 1) . str_repeat('*', strlen($s) - 1);
    return substr($s, 0, 2) . str_repeat('*', 6) . substr($s, -1);
}
/**
 * Keep the attribute NAMES and the shape of the tree; mask every value.
 *
 * Shouts if handed a non-string. It was silently given an array once (a helper
 * returned ['includes'=>…,'excludes'=>…] rather than a flat list) and printed a
 * masked "Array" — i.e. "A****" — which looked like a real, if odd, DN. A
 * diagnostic that quietly mangles its own input is worse than no diagnostic.
 *
 * @param mixed $s  an LDAP distinguished name; empty gives (not set), an array or object is flagged
 */
function maskDn($s) {
    if (is_array($s) || is_object($s)) return '(UNEXPECTED ' . gettype($s) . ' — please report this)';
    $s = trim((string)$s);
    if ($s === '') return '(not set)';
    $parts = preg_split('/(?<!\\\\),/', $s);
    foreach ($parts as &$p) {
        if (!str_contains($p, '=')) { $p = mask($p); continue; }
        list($k, $v) = explode('=', $p, 2);
        $p = trim($k) . '=' . mask(trim($v));
    }
    return implode(',', $parts);
}
/**
 * Masks a newline-separated list of DNs, one maskDn() per line - ['(none)'] when it is empty.
 *
 * @param mixed $raw  DNs one per line, as stored in the provider's settings
 */
function maskDnList($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return ['(none)'];
    $out = [];
    foreach (preg_split('/\R/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '') $out[] = maskDn($line);
    }
    return $out ?: ['(none)'];
}

// ---- 1. HEADER ----------------------------------------------------------

$now = gmdate('Y-m-d H:i:s') . ' UTC';
addSection($sections, 'REPORT HEADER', [
    "Diagnostic   : {$DIAG_ID} — {$DIAG_NAME}",
    "Generated    : {$now}",
    "PHP          : " . PHP_VERSION . ' on ' . PHP_OS,
    "Mode         : READ-ONLY, and it does NOT contact the directory. Configuration",
    "               only. Safe to send on — DNs, bind accounts and filter values are",
    "               masked, and no secret is printed.",
]);

try {
    $conn = connectToDatabase();
} catch (Throwable $e) {
    addSection($sections, 'DATABASE', 'CANNOT CONNECT: ' . $e->getMessage());
    emit_and_exit($sections);
}

/**
 * Whether a column exists in this database - false on any error, so an older install
 * reads as not ready rather than breaking the report.
 *
 * @param PDO $c  the database connection
 * @param mixed $table  the table name to look for
 * @param mixed $col  the column name to look for
 */
function colExists(PDO $c, $table, $col) {
    try {
        $s = $c->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $s->execute([$table, $col]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}
/**
 * Whether a table exists in this database - false on any error, so an older install
 * reads as not ready rather than breaking the report.
 *
 * @param PDO $c  the database connection
 * @param mixed $table  the table name to look for
 */
function tableExists(PDO $c, $table) {
    try {
        $s = $c->prepare("SELECT COUNT(*) FROM information_schema.TABLES
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) { return false; }
}

// ---- 2. INSTALL-WIDE PREREQUISITES --------------------------------------
// These block EVERY provider at once, so they are checked before any row.

$ldapExt        = extension_loaded('ldap');
$hasSyncInclude = is_file(dirname(__DIR__, 3) . '/includes/directory_sync.php');
$hasCliScript   = is_file(dirname(__DIR__, 3) . '/cron/directory_sync.php');

$pre = [
    'PHP ldap extension            : ' . ($ldapExt ? 'LOADED' : 'MISSING  <-- nothing can import without it'),
    'includes/directory_sync.php   : ' . ($hasSyncInclude ? 'present' : 'MISSING  <-- this build predates directory sync'),
    'cron/directory_sync.php       : ' . ($hasCliScript ? 'present' : 'MISSING  <-- no scheduled/CLI import available'),
];
addSection($sections, 'INSTALL-WIDE PREREQUISITES', $pre);

// ---- 2b. FULL SCHEMA AUDIT ----------------------------------------------
/*
 * Every table and column directory sync touches, checked against the canonical
 * definition rather than a list typed out again here.
 *
 * includes/db_verify_schema.php IS that definition — it is what Database
 * Verification creates and ALTERs from, and it is already self-checked against
 * database/freeitsm.sql on every Verification run. Reading it means this tool
 * cannot drift from the real schema: a column added to the feature tomorrow is
 * checked here the same day, with nobody remembering to come back.
 *
 * A hand-kept copy would have exactly the failure this tool exists to catch —
 * quietly reporting "all present" about a list that stopped being complete.
 */
$SYNC_TABLES = [
    'auth_providers'         => 'the directory itself: connection, sign-in and import settings',
    'users'                  => 'the people who get imported',
    'user_sso_identities'    => 'links a person to their directory identity',
    'directory_sync_runs'    => 'one row per import — the history',
    'directory_sync_entries' => 'one row per person per import — what changed, and for whom',
];

$canonical = [];
$schemaFile = dirname(__DIR__, 3) . '/includes/db_verify_schema.php';
if (is_file($schemaFile)) {
    try { $canonical = require $schemaFile; } catch (Throwable $e) { $canonical = []; }
}
if (!is_array($canonical)) $canonical = [];

$missingTables  = [];
$missingColumns = [];   // table => [col, ...]
$schemaOut      = [];

if (!$canonical) {
    $schemaOut[] = 'Could not read includes/db_verify_schema.php — cannot audit the schema.';
    $schemaOut[] = 'On a build that predates directory sync this file may not exist at all.';
} else {
    foreach ($SYNC_TABLES as $table => $why) {
        $expected = array_keys($canonical[$table] ?? []);
        if (!$expected) {
            $schemaOut[] = str_pad($table, 24) . ': NOT IN THIS BUILD\'S SCHEMA — the feature is not present here';
            $missingTables[] = $table;
            continue;
        }
        if (!tableExists($conn, $table)) {
            $schemaOut[] = str_pad($table, 24) . ': TABLE MISSING  (' . $why . ')';
            $missingTables[] = $table;
            continue;
        }
        // One query per table beats one per column.
        $actual = [];
        try {
            $s = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
            $s->execute([$table]);
            foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $actual[strtolower($c)] = true;
        } catch (Throwable $e) {
            $schemaOut[] = str_pad($table, 24) . ': could not read columns — ' . $e->getMessage();
            continue;
        }
        $gone = [];
        foreach ($expected as $col) if (!isset($actual[strtolower($col)])) $gone[] = $col;

        if ($gone) {
            $missingColumns[$table] = $gone;
            $schemaOut[] = str_pad($table, 24) . ': ' . (count($expected) - count($gone)) . '/' . count($expected)
                         . ' columns — MISSING ' . count($gone) . '  (' . $why . ')';
            foreach ($gone as $c) $schemaOut[] = '    missing column: ' . $c;
        } else {
            $schemaOut[] = str_pad($table, 24) . ': OK, all ' . count($expected) . ' columns present  (' . $why . ')';
        }
    }
}

$installBlocked = null;
if (!$hasSyncInclude || $missingTables || $missingColumns || !$canonical) {
    $installBlocked = 'THE DATABASE IS NOT READY FOR DIRECTORY SYNC.';
    $schemaOut[] = '';
    $schemaOut[] = '>> ' . $installBlocked;
    if (!$hasSyncInclude || !$canonical) {
        $schemaOut[] = '>> This build predates the feature. Pull the latest FreeITSM first.';
    }
    $schemaOut[] = '>> Fix: System > Debug Tools > Database Verification (or System > Database';
    $schemaOut[] = '>>      Verification) creates every missing table and column above.';
    $schemaOut[] = '>> Until then no provider can import people, however it is configured.';
} elseif (!$ldapExt) {
    $installBlocked = 'The PHP ldap extension is not loaded.';
    $schemaOut[] = '';
    $schemaOut[] = '>> ' . $installBlocked . ' Enable extension=ldap in php.ini and restart the web server.';
} else {
    $schemaOut[] = '';
    $schemaOut[] = 'Every table and column directory sync needs is present.';
}
addSection($sections, 'DATABASE SCHEMA AUDIT', $schemaOut);

// Used further down to decide whether the per-provider sync fields are readable.
$missingSync  = $missingColumns['auth_providers'] ?? [];
$hasRunsTable = !in_array('directory_sync_runs', $missingTables, true);

// Which columns auth_providers actually HAS, so a missing column can be told
// apart from a column that exists and is empty. They mean different things and
// have different fixes, and both read as "blank" everywhere else.
$apCols = [];
try {
    $s = $conn->prepare("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'auth_providers'");
    $s->execute();
    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $c) $apCols[strtolower($c)] = true;
} catch (Throwable $e) { /* reported as missing below */ }

/**
 * Describe what is actually stored, keeping the three "blank" cases apart:
 * the column is absent, the value is NULL, or the value is an empty string.
 *
 * @param array $row  the auth_providers row as read back
 * @param string $col  the column to describe
 * @param array $apCols  the columns auth_providers really has, keyed by lower-case name
 * @param bool $maskIt  true to mask the value with mask() rather than print it
 */
function storedValue(array $row, string $col, array $apCols, bool $maskIt = false) {
    if (!isset($apCols[strtolower($col)]))  return 'COLUMN DOES NOT EXIST in this database';
    if (!array_key_exists($col, $row))      return 'column exists but was not read back';
    $v = $row[$col];
    if ($v === null)                        return 'NULL  (never set)';
    $s = (string)$v;
    if (trim($s) === '' && $s !== '')       return 'WHITESPACE ONLY  (length ' . strlen($s) . ')';
    if ($s === '')                          return 'EMPTY STRING  (set, then cleared)';
    return ($maskIt ? mask($s) : "'" . $s . "'") . '  (length ' . strlen($s) . ')';
}

// ---- 3. HOW AN IMPORT IS TRIGGGERED -------------------------------------
// Worth stating plainly, because "it imported when I pressed the button" is not
// the same claim as "it imports".

$trig = [
    'By hand   : Authentication > (directory) > Configure > Importing people > Preview / Run.',
    '            This path checks sync_enabled ONLY. It does NOT check the provider\'s',
    '            Enabled flag. (api/system/run_directory_sync.php)',
    '',
    'Scheduled : php cron/directory_sync.php --all   [--preview]',
    '            This path requires enabled = 1 AND sync_enabled = 1.',
    '',
    '>> THE TRAP: a provider with Enabled OFF and directory sync ON imports',
    '>> perfectly when you press the button, and is silently skipped by --all.',
    '>> No error is produced. If you want a directory for importing only, leave the',
    '>> provider Enabled and switch off "Enable single sign-on" globally instead —',
    '>> the import path never consults that global switch.',
    '',
    'NOTE: FreeITSM does not schedule this for you. There is no built-in timer for',
    'directory sync yet — the scheduled run means a cron entry or a Windows Scheduled',
    'Task that you create, calling the script above. If nobody has set one up, the',
    'import only ever happens when somebody presses the button.',
];
addSection($sections, 'HOW AN IMPORT IS TRIGGERED', $trig);

// ---- 4. PER-PROVIDER GATES ----------------------------------------------

if (!tableExists($conn, 'auth_providers')) {
    addSection($sections, 'PER-METHOD VERDICT', 'auth_providers table is missing — nothing to check.');
    emit_and_exit($sections);
}

try {
    $providers = $conn->query("SELECT * FROM auth_providers ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    addSection($sections, 'PER-METHOD VERDICT', 'Query failed: ' . $e->getMessage());
    emit_and_exit($sections);
}

if (!$providers) {
    addSection($sections, 'PER-METHOD VERDICT', 'No sign-in methods are configured, so there is nothing that could import people.');
    emit_and_exit($sections);
}

// ---- 3b. WHAT DECIDES 'Edit' vs 'Configure' -----------------------------
/*
 * The Actions column on the Authentication screen shows "Configure" (a link to
 * the tabbed directory page, where importing lives) or "Edit" (the small OIDC
 * dialog). ONE stored value decides it, and the same value decides the LDAP/OIDC
 * badge on the row:
 *
 *   system/sso/index.php   const isLdap = p.protocol === 'ldap';
 *
 * That comparison is strict and case-sensitive, and api/system/get_sso_providers.php
 * passes the column through untouched. So 'LDAP', 'Ldap' or 'ldap ' with a stray
 * space all evaluate FALSE: the row shows "Edit", labels itself OIDC, and the
 * import tabs become unreachable — while the database plainly says it is a
 * directory. Worth dumping the byte-for-byte value rather than trusting the eye.
 *
 * If the value here IS exactly 'ldap' and the screen still shows "Edit", the
 * build is older than the Configure link (added 2026-08-16) — update it.
 */
$dec = [
    'The rule (system/sso/index.php): isLdap = (protocol === \'ldap\')  — strict, case-sensitive.',
    'Configure = isLdap true. Edit = isLdap false. The row badge uses the same test.',
    '',
];
$anyNearMiss = false;
foreach ($providers as $p) {
    $raw     = $p['protocol'] ?? null;
    $exact   = ($raw === 'ldap');
    $looksLdap = (strtolower(trim((string)$raw)) === 'ldap');
    $dec[] = 'Provider #' . (int)$p['id'] . '  ' . (string)($p['display_name'] ?? '');
    $dec[] = '    stored protocol      : ' . storedValue($p, 'protocol', $apCols);
    $dec[] = '    === \'ldap\' (strict)   : ' . yn($exact);
    $dec[] = '    row will show        : ' . ($exact ? 'Configure  (and an LDAP badge)' : 'Edit  (and an OIDC badge)');
    if (!$exact && $looksLdap) {
        $anyNearMiss = true;
        $dec[] = '    >> MISMATCH: this IS a directory, but the stored value is not exactly';
        $dec[] = '       lower-case "ldap", so the screen treats it as OIDC and the importing';
        $dec[] = '       tabs cannot be reached. Fix with:';
        $dec[] = '         UPDATE auth_providers SET protocol = \'ldap\' WHERE id = ' . (int)$p['id'] . ';';
    }
    $dec[] = '';
}
if (!$anyNearMiss) {
    $dec[] = 'No mismatches: every row\'s stored protocol matches what the screen will show.';
    $dec[] = 'So if a directory row shows "Edit" rather than "Configure", the installation is';
    $dec[] = 'older than that link (added 2026-08-16) — pull the latest FreeITSM.';
}
addSection($sections, "WHY EACH ROW SHOWS 'Edit' OR 'Configure'", $dec);

// Use the real scope/attribute resolution where the build has it, so this tool
// cannot disagree with the importer about what "configured" means.
$haveHelpers = false;
if ($hasSyncInclude && $ldapExt) {
    try { require_once dirname(__DIR__, 3) . '/includes/directory_sync.php'; $haveHelpers = function_exists('dsyncScopes'); }
    catch (Throwable $e) { $haveHelpers = false; }
}

$out = [];
$readyCount = 0;

foreach ($providers as $p) {
    $isLdap = strtolower((string)($p['protocol'] ?? '')) === 'ldap';
    $name   = (string)($p['display_name'] ?? ('#' . (int)$p['id']));
    $gates  = [];
    $blocker = null;

    $out[] = '--- ' . $name . '  (provider #' . (int)$p['id'] . ') ' . str_repeat('-', max(1, 40 - strlen($name)));

    // GATE 1 — protocol
    if (!$isLdap) {
        $gates[] = '  [BLOCKED] Protocol is ' . strtoupper((string)($p['protocol'] ?? '?')) . ', not LDAP.';
        $blocker = 'It is an OIDC/SSO provider. Only an LDAP / Active Directory method can import people. '
                 . 'OIDC can create a person the first time they sign in (JIT), but it cannot enumerate a directory.';
        $out = array_merge($out, $gates);
        $out[] = '  >> BLOCKER: ' . $blocker;
        $out[] = '';
        continue;
    }
    $gates[] = '  [ok]      Protocol is LDAP.';

    // GATE 2/3 — install-wide
    if ($installBlocked) {
        $gates[] = '  [BLOCKED] ' . $installBlocked;
        $blocker = $installBlocked . ' See INSTALL-WIDE PREREQUISITES above.';
    }

    // GATE 4 — connection
    $host = trim((string)($p['ldap_host'] ?? ''));
    if ($host === '') {
        $gates[] = '  [BLOCKED] No host is set.';
        $blocker = $blocker ?: 'No directory host is set on the Connection tab.';
    } else {
        $gates[] = '  [ok]      Host set (' . $host . ':' . ((int)($p['ldap_port'] ?? 0) ?: 'no port') . ', '
                 . ((($p['ldap_encryption'] ?? '') !== '') ? $p['ldap_encryption'] : 'no encryption') . ').';
    }

    // GATE 5 — bind credentials (soft: anonymous bind is legal, rarely useful)
    $bindDn = trim((string)($p['ldap_bind_dn'] ?? ''));
    if ($bindDn === '' || empty($p['ldap_bind_password'])) {
        $gates[] = '  [warn]    No bind account/password — anonymous bind. Active Directory almost';
        $gates[] = '            always refuses to enumerate anonymously, so an import will find nobody.';
    } else {
        $gates[] = '  [ok]      Bind account set (' . maskDn($bindDn) . '), password stored.';
    }

    // GATE 6 — the switch itself
    if (!$missingSync) {
        if (empty($p['sync_enabled'])) {
            $gates[] = '  [BLOCKED] Directory sync is switched OFF for this method.';
            $blocker = $blocker ?: 'Directory sync is off. Turn on "Import people from this directory" '
                                 . 'on Configure > Importing people, and save.';
        } else {
            $gates[] = '  [ok]      Directory sync is switched ON.';
        }
    }

    // GATE 7 — is there anything to import FROM?
    if (!$missingSync) {
        $includesRaw = (string)($p['sync_ou_includes'] ?? '');
        $excludesRaw = (string)($p['sync_ou_excludes'] ?? '');
        $scopes = [];
        $excludes = [];
        // dsyncScopes() returns ['includes'=>[], 'excludes'=>[]] — NOT a flat list.
        if ($haveHelpers) {
            try {
                $sc = dsyncScopes($p);
                $scopes   = is_array($sc['includes'] ?? null) ? $sc['includes'] : [];
                $excludes = is_array($sc['excludes'] ?? null) ? $sc['excludes'] : [];
            } catch (Throwable $e) { $scopes = []; }
        } else {
            $fallback = trim((string)($p['sync_base_dn'] ?? '')) ?: trim((string)($p['ldap_base_dn'] ?? ''));
            if (trim($includesRaw) !== '') $scopes = preg_split('/\R/', trim($includesRaw));
            elseif ($fallback !== '')      $scopes = [$fallback];
            if (trim($excludesRaw) !== '') $excludes = preg_split('/\R/', trim($excludesRaw));
        }
        if (!$scopes) {
            $gates[] = '  [BLOCKED] No import scope. Nothing is ticked in the directory tree, and neither';
            $gates[] = '            an import base DN nor a sign-in base DN is set, so there is nowhere';
            $gates[] = '            to import FROM.';
            $blocker = $blocker ?: 'No import scope: tick a branch under Configure > Importing people > Browse directory.';
        } else {
            $gates[] = '  [ok]      Import scope resolves to ' . count($scopes) . ' branch(es):';
            foreach ($scopes as $s) $gates[] = '              + ' . maskDn($s);
            $gates[] = '            Source: ' . (trim($includesRaw) !== '' ? 'ticked branches (sync_ou_includes)'
                     : (trim((string)($p['sync_base_dn'] ?? '')) !== '' ? 'import base DN (sync_base_dn)'
                     : 'sign-in base DN (ldap_base_dn) — inherited fallback'));
            foreach ($excludes as $ex) $gates[] = '              - carve-out: ' . maskDn($ex);
        }
    }

    // GATE 8 — attributes that must resolve to something
    $flavour = $haveHelpers ? dsyncFlavour($p) : ((strtolower((string)($p['ldap_attr_username'] ?? '')) === 'uid') ? 'openldap' : 'ad');
    $gates[] = '  [ok]      Directory flavour inferred: ' . strtoupper($flavour) . ' (from the sign-in attributes).';
    foreach (['username' => 'sign-in name', 'guid' => 'unique id', 'email' => 'email'] as $field => $label) {
        $set = trim((string)($p['ldap_attr_' . $field] ?? ''));
        $eff = $haveHelpers ? dsyncAttr($p, $field) : $set;
        if ($eff === '') {
            $gates[] = '  [warn]    No ' . $label . ' attribute resolves — people may import without one.';
        } else {
            $gates[] = '  [ok]      ' . str_pad($label, 13) . ' attribute: ' . $eff . ($set === '' ? '  (flavour default)' : '');
        }
    }

    // GATE 9 — the Enabled flag: scheduled runs only. THE trap.
    if (empty($p['enabled'])) {
        $gates[] = '  [warn]    The provider is NOT Enabled.';
        $gates[] = '            Pressing Run/Preview in the interface WILL still import.';
        $gates[] = '            The scheduled run (--all) WILL SKIP IT, silently and forever.';
        if (!$blocker && !$missingSync && !empty($p['sync_enabled'])) {
            $blocker = 'Manual imports work; scheduled imports are skipped because the provider is not Enabled. '
                     . 'Set it Enabled — turning off "Enable single sign-on" globally is the way to have a '
                     . 'directory that imports without letting anyone sign in with it.';
        }
    } else {
        $gates[] = '  [ok]      Provider is Enabled, so the scheduled run will include it.';
    }

    // Safety settings + history, for context on a run that behaved oddly.
    if (!$missingSync) {
        $gates[] = '  [info]    On conflict: ' . (($p['sync_on_conflict'] ?? '') ?: 'adopt')
                 . ' | deactivate after ' . (int)($p['sync_deactivate_after'] ?? 3) . ' missed run(s)'
                 . ' | safety brake at ' . (int)($p['sync_brake_percent'] ?? 20) . '% drop';
        $gates[] = '  [info]    Filter: ' . ((trim((string)($p['sync_filter'] ?? '')) !== '') ? mask($p['sync_filter']) : '(default)');
        $gates[] = '  [info]    Last import: ' . ((($p['sync_last_run_datetime'] ?? '') ?: 'never'))
                 . (isset($p['sync_last_count']) && $p['sync_last_count'] !== null ? ('  (' . (int)$p['sync_last_count'] . ' people found)') : '');
        if ($hasRunsTable) {
            try {
                $s = $conn->prepare("SELECT COUNT(*) FROM directory_sync_runs WHERE provider_id = ?");
                $s->execute([(int)$p['id']]);
                $gates[] = '  [info]    Runs recorded in history: ' . (int)$s->fetchColumn();
            } catch (Throwable $e) { /* table shape differs on an older build */ }
        }
    }

    // Every stored value the import depends on, with NULL / empty / absent kept
    // apart — a gate saying "not set" does not tell you WHICH of the three, and
    // they have three different fixes.
    $gates[] = '  [values]  What is actually stored on this row:';
    $valueCols = [
        'protocol' => false, 'enabled' => false, 'ldap_host' => false, 'ldap_port' => false,
        'ldap_encryption' => false, 'ldap_bind_dn' => true, 'ldap_bind_password' => true,
        'ldap_base_dn' => true, 'ldap_attr_username' => false, 'ldap_attr_guid' => false,
        'ldap_attr_email' => false, 'ldap_attr_name' => false, 'ldap_attr_job_title' => false,
        'ldap_attr_department' => false, 'ldap_attr_office' => false, 'ldap_attr_phone' => false,
        'ldap_attr_mobile' => false, 'ldap_attr_employee_id' => false, 'ldap_attr_manager' => false,
        'sync_enabled' => false, 'sync_base_dn' => true, 'sync_ou_includes' => true,
        'sync_ou_excludes' => true, 'sync_filter' => true, 'sync_on_conflict' => false,
        'sync_deactivate_after' => false, 'sync_brake_percent' => false,
        'sync_last_run_datetime' => false, 'sync_last_count' => false,
    ];
    $absent = [];
    foreach ($valueCols as $col => $maskIt) {
        // The bind password is a secret. Masking is for identifiers; a secret gets
        // present/absent and nothing else — not even its length.
        if ($col === 'ldap_bind_password') {
            $val = !isset($apCols[$col]) ? 'COLUMN DOES NOT EXIST in this database'
                 : (!empty($p[$col]) ? 'SET  (encrypted, never shown)' : 'NOT SET  (anonymous bind)');
        } else {
            $val = storedValue($p, $col, $apCols, $maskIt);
        }
        $gates[] = '              ' . str_pad($col, 24) . ' = ' . $val;
        if (!isset($apCols[strtolower($col)])) $absent[] = $col;
    }
    if ($absent) {
        $gates[] = '            >> ' . count($absent) . ' column(s) DO NOT EXIST here — that is a schema problem,';
        $gates[] = '               not a settings problem. Run Database Verification.';
    }

    $out = array_merge($out, $gates);
    if ($blocker) {
        $out[] = '  >> BLOCKER: ' . $blocker;
    } else {
        $readyCount++;
        $out[] = '  >> READY. Nothing is blocking this method from importing people.';
    }
    $out[] = '';
}

addSection($sections, 'PER-METHOD VERDICT', $out);

// ---- 5. SUMMARY ---------------------------------------------------------

$sum = [];
$ldapCount = 0;
foreach ($providers as $p) if (strtolower((string)($p['protocol'] ?? '')) === 'ldap') $ldapCount++;
$sum[] = 'Sign-in methods configured        : ' . count($providers);
$sum[] = '  of which LDAP / Active Directory: ' . $ldapCount;
$sum[] = '  ready to import people          : ' . $readyCount;
$sum[] = '';
if ($installBlocked) {
    $sum[] = 'Nothing can import until the install-wide problem above is fixed.';
} elseif ($ldapCount === 0) {
    $sum[] = 'There is no LDAP / Active Directory method, so there is nothing that could import.';
    $sum[] = 'Add one with + Add on the Authentication screen, choosing LDAP as the type.';
} elseif ($readyCount === 0) {
    $sum[] = 'Every directory method is blocked — see its BLOCKER line above.';
} else {
    $sum[] = 'At least one directory is ready. Preview it first: Configure > Importing people >';
    $sum[] = 'Preview runs the identical code path and changes nothing.';
}
addSection($sections, 'SUMMARY', $sum);

emit_and_exit($sections);
