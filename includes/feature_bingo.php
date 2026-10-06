<?php
/**
 * Feature Bingo - System -> Feature Bingo.
 *
 * Most people use a fraction of what they bought. Every card here is one thing
 * FreeITSM can do - "at least one analyst", "an SLA calendar", "portal managers"
 * - with a star that lights up once it is set up, and an explainer of what it is
 * and why it is worth doing.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  A CARD IS DATA. The cards live in includes/feature_bingo/cards/<module>.php,
 *  one file per module, each returning a list of:
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   [
 *     'id'     => 'tickets.sla_calendar',     // unique, stable - "Not for us" is stored against it
 *     'module' => 'tickets',                   // a key of featureBingoModules()
 *     'tier'   => 'essential',                 // essential | recommended | extra
 *     'category' => 'governance',              // a key of featureBingoCategories() - what KIND of thing it is
 *     'title'  => 'Business hours for SLAs',   // the feature, short
 *     'what'   => 'One or two sentences: what it is.',
 *     'why'    => 'One or two sentences: why it is worth setting up.',
 *     'done'   => 'Plain words for exactly what the check counts, e.g. "At least one business calendar exists."',
 *     'link'   => 'tickets/settings/?tab=sla', // app-relative page where it is set up (no leading slash)
 *     'check'  => [...],                        // see below
 *   ]
 *
 * THE CHECK - declarative, so a card can never run anything but a read:
 *
 *   ['rows', 'table']                          at least one row
 *   ['rows', 'table', 'where sql', min]        at least `min` rows matching (min defaults to 1)
 *   ['setting', 'key', 'eq', 'value']          system_settings value equals
 *   ['setting', 'key', 'neq', 'value']         ... is set and differs (a never-saved key counts as not configured)
 *   ['setting', 'key', 'in', ['a','b']]        ... is one of
 *   ['setting', 'key', 'nonempty']             ... has any non-empty value
 *   ['sql', 'SELECT COUNT(*) ...', min]        a single number >= min (default 1). SELECT only.
 *   ['any', [check, check, ...]]               any one lights it
 *   ['all', [check, check, ...]]               all of them
 *
 * A check that errors - a table Database Verification has not created yet, a
 * column that does not exist - reads as NOT configured and is reported by
 * scripts/feature_bingo_check.php, which runs every check against a database.
 * It never breaks the page.
 *
 * Card copy is English, like System help (includes a lot of prose; see
 * system/help/_init.php for the reasoning). The page's own labels are translated.
 */

const FEATURE_BINGO_TIERS = ['essential', 'recommended', 'extra'];

/**
 * What kind of thing a card is, across modules (Ed: "AI, security, automation
 * that you can also filter by"). Every card has exactly one. key => label.
 */
function featureBingoCategories(): array
{
    return [
        'getting-started' => 'Getting started',
        'ai'              => 'AI',
        'security'        => 'Security & access',
        'automation'      => 'Automation',
        'communication'   => 'Email & notifications',
        'integrations'    => 'Integrations',
        'customer'        => 'Customer experience',
        'insight'         => 'Reporting & insight',
        'organisation'    => 'Organisation & data',
        'governance'      => 'Service levels & governance',
        'productivity'    => 'Working faster',
        'look'            => 'Look & feel',
    ];
}

/** The modules cards belong to, in the order the filter lists them. key => label. */
function featureBingoModules(): array
{
    return [
        'system'         => 'System',
        'tickets'        => 'Tickets',
        'self-service'   => 'Self-service portal',
        'assets'         => 'Asset Management',
        'people'         => 'People',
        'knowledge'      => 'Knowledge',
        'changes'        => 'Change Management',
        'problems'       => 'Problem Management',
        'cmdb'           => 'CMDB',
        'service-status' => 'Service Status',
        'workflow'       => 'Workflows',
        'tasks'          => 'Tasks',
        'projects'       => 'Projects',
        'calendar'       => 'Calendar',
        'contracts'      => 'Contracts',
        'domains'        => 'Domains',
        'software'       => 'Software',
        'forms'          => 'Forms',
        'lms'            => 'LMS',
        'checklists'     => 'Checklists',
        'morning-checks' => 'Morning Checks',
        'watchtower'     => 'Watchtower',
        'war-room'       => 'War Room',
        'reporting'      => 'Reporting',
        'process-mapper' => 'Process Mapper',
        'network-mapper' => 'Network Mapper',
        'wiki'           => 'System Wiki',
    ];
}

/**
 * Every card, validated. A card with a missing field or an unknown module or
 * tier is left out and reported in $problems, never shown half-formed.
 */
function featureBingoCards(?array &$problems = null): array
{
    $problems = [];
    $cards = [];
    $seen = [];
    $modules = featureBingoModules();
    $files = glob(__DIR__ . '/feature_bingo/cards/*.php') ?: [];
    sort($files);
    // Your own cards, from a folder no update touches (see featureBingoLocalDir()).
    $local = glob(featureBingoLocalDir() . '/*.php') ?: [];
    sort($local);
    $isLocal = array_fill_keys($local, true);
    $files = array_merge($files, $local);
    foreach ($files as $file) {
        $list = include $file;
        if (!is_array($list)) { $problems[] = basename($file) . ': does not return a list'; continue; }
        foreach ($list as $i => $c) {
            $where = basename($file) . '#' . $i;
            foreach (['id', 'module', 'tier', 'category', 'title', 'what', 'why', 'done', 'check'] as $k) {
                if (empty($c[$k])) { $problems[] = "$where: missing '$k'"; continue 2; }
            }
            if (!isset($modules[$c['module']]))                      { $problems[] = "$where ({$c['id']}): unknown module '{$c['module']}'"; continue; }
            if (!in_array($c['tier'], FEATURE_BINGO_TIERS, true))    { $problems[] = "$where ({$c['id']}): unknown tier '{$c['tier']}'"; continue; }
            if (!isset(featureBingoCategories()[$c['category']]))    { $problems[] = "$where ({$c['id']}): unknown category '{$c['category']}'"; continue; }
            if (isset($seen[$c['id']]))                              { $problems[] = "$where: duplicate id '{$c['id']}' (also " . $seen[$c['id']] . ')'; continue; }
            $seen[$c['id']] = $where;
            $c['link'] = (string)($c['link'] ?? '');
            $c['local'] = isset($isLocal[$file]);   // "Added here" on the page
            $cards[] = $c;
        }
    }
    return $cards;
}

/**
 * Run one check. Returns true / false; an error (missing table or column, a
 * malformed check) is false and is written to $error.
 */
function featureBingoRunCheck(PDO $conn, array $check, ?string &$error = null)
{
    $error = null;
    try {
        switch ($check[0] ?? '') {
            case 'rows':
                $table = (string)($check[1] ?? '');
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) throw new Exception("bad table name '$table'");
                $where = trim((string)($check[2] ?? ''));
                $min   = (int)($check[3] ?? 1);
                $sql = "SELECT COUNT(*) FROM `$table`" . ($where !== '' ? " WHERE $where" : '');
                featureBingoAssertReadOnly($sql);
                return (int)$conn->query($sql)->fetchColumn() >= max(1, $min);

            case 'setting':
                $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
                $st->execute([(string)$check[1]]);
                $v = $st->fetchColumn();
                $op = (string)($check[2] ?? 'nonempty');
                if ($v === false) return false;                     // never saved = not configured
                $v = (string)$v;
                if ($op === 'nonempty') return trim($v) !== '';
                if ($op === 'eq')       return $v === (string)$check[3];
                if ($op === 'neq')      return $v !== (string)$check[3];
                if ($op === 'in')       return in_array($v, array_map('strval', (array)$check[3]), true);
                throw new Exception("unknown setting operator '$op'");

            case 'sql':
                $sql = (string)($check[1] ?? '');
                featureBingoAssertReadOnly($sql);
                return (float)$conn->query($sql)->fetchColumn() >= (float)($check[2] ?? 1);

            case 'any':
            case 'all':
                $any = $check[0] === 'any';
                $errs = [];
                foreach ((array)($check[1] ?? []) as $sub) {
                    $r = featureBingoRunCheck($conn, $sub, $e);
                    if ($e) $errs[] = $e;
                    if ($any && $r) return true;
                    if (!$any && !$r) { if ($errs) $error = implode('; ', $errs); return false; }
                }
                if ($errs) $error = implode('; ', $errs);
                return !$any;
        }
        throw new Exception("unknown check type '" . ($check[0] ?? '') . "'");
    } catch (Throwable $e) {
        $error = $e->getMessage();
        return false;
    }
}

/**
 * A card's SQL is read-only by construction, and this makes sure of it: one
 * statement, beginning SELECT. Cards are code in the repository, not user
 * input - this guards against a mistake, not an attacker.
 */
function featureBingoAssertReadOnly(string $sql): void
{
    $s = ltrim($sql);
    // Judge the SQL, not the text inside it: `rejected_action <> 'delete'` is a
    // read, and the word inside the quotes must not trip the keyword test.
    $s = preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "''", $s);
    if (strpos($s, ';') !== false) throw new Exception('a check must be a single statement');
    if (!preg_match('/^SELECT\s/i', $s)) throw new Exception('a check must be a SELECT');
    if (preg_match('/\b(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|TRUNCATE|REPLACE|GRANT)\b/i', $s)) throw new Exception('a check may only read');
}

/** The ids an administrator has marked "Not for us". */
function featureBingoDismissed(PDO $conn): array
{
    try {
        return array_fill_keys($conn->query("SELECT card_id FROM feature_bingo_dismissed")->fetchAll(PDO::FETCH_COLUMN), true);
    } catch (Throwable $e) {
        return [];   // before Database Verification: nothing dismissed
    }
}

/**
 * Every card with its state: 'lit' (configured), 'unlit', or 'dismissed'
 * (Not for us - out of the score, whatever its check says). Plus the totals.
 */
function featureBingoEvaluate(PDO $conn): array
{
    $dismissed = featureBingoDismissed($conn);
    $out = [];
    $lit = 0; $counted = 0;
    foreach (featureBingoCards() as $c) {
        $isLit = featureBingoRunCheck($conn, $c['check'], $err);
        $state = isset($dismissed[$c['id']]) ? 'dismissed' : ($isLit ? 'lit' : 'unlit');
        if ($state !== 'dismissed') { $counted++; if ($isLit) $lit++; }
        unset($c['check']);
        $c['state'] = $state;
        $c['configured'] = $isLit;          // true even when dismissed: "you do use it after all"
        $out[] = $c;
    }
    return ['cards' => $out, 'lit' => $lit, 'total' => $counted, 'dismissed' => count(array_filter($out, fn($c) => $c['state'] === 'dismissed'))];
}

/** Has Database Verification created the "Not for us" table yet? */
function featureBingoReady(PDO $conn): bool
{
    try { $conn->query("SELECT card_id FROM feature_bingo_dismissed LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/**
 * Where an install keeps ITS OWN cards - for its own processes, or for a
 * feature it has added to its copy of FreeITSM. Same format, same checks.
 *
 * Deliberately NOT includes/feature_bingo/cards/: that folder ships with every
 * release, and in Docker the code is part of the image, so a file added there
 * is gone after the next update. `local/` is ignored by git and never shipped;
 * define FEATURE_BINGO_LOCAL_DIR in config.php to put the folder elsewhere -
 * for Docker, somewhere on a volume.
 */
function featureBingoLocalDir(): string
{
    return defined('FEATURE_BINGO_LOCAL_DIR') ? rtrim((string)FEATURE_BINGO_LOCAL_DIR, '/\\') : dirname(__DIR__) . '/local/feature_bingo';
}
