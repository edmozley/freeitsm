<?php
/**
 * The help assistant (wiki Help-Assistant-Design, step 3): who may use it, the
 * reply to a question, the saved conversation, and the index's status.
 *
 * Step 3 is the no-AI level, "Search help": the reply is a short canned line plus
 * the best help sections (includes/help_index.php). The line is chosen by how
 * good the match is - helpAssistantReplyKind() - so it never sounds sure about a
 * weak result, and it never pretends an AI read anything.
 */

require_once __DIR__ . '/help_index.php';

/**
 * "Clear winner": the top result scores at least this many times the runner-up.
 * Set from the question set (tests/help-search-questions.php), not guessed: of the
 * 22 questions at or above 1.25, 20 had a right section first.
 */
const HELP_ASSISTANT_CLEAR_RATIO = 1.25;

/** "Weak": the top result scores below this. The question set's shaky answers sit under it. */
const HELP_ASSISTANT_WEAK_SCORE = 9.0;

/** The longest question taken, in characters. */
const HELP_ASSISTANT_MAX_QUESTION = 500;

/** How many messages a conversation keeps; older ones go as new ones arrive. */
const HELP_ASSISTANT_KEEP = 200;

// ─── Who may use it ─────────────────────────────────────────────────────────

/** Whether the install has the help assistant switched on (System -> Help assistant). Off until an admin turns it on. */
function helpAssistantEnabled(PDO $conn): bool
{
    try {
        $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'help_assistant_enabled'");
        $st->execute();
        return $st->fetchColumn() === '1';
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Whether the tables and column exist yet. TRAP: they arrive with Database Verify,
 * and the header asks on every page - so before it has run, the assistant is
 * simply not there, rather than an error on every page.
 */
function helpAssistantReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $ready = (bool)$conn->query("SHOW COLUMNS FROM analysts LIKE 'help_assistant'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_assistant_messages'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_sections'")->fetch();
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Whether this analyst has the help assistant: the install has it switched on AND
 * it is on for them (an admin turns it on per analyst), and they are active.
 * Asked by the header on every page, so cached per request.
 */
function helpAssistantAllowed(PDO $conn, int $analystId): bool
{
    static $cache = [];
    if (isset($cache[$analystId])) return $cache[$analystId];
    if ($analystId <= 0 || !helpAssistantReady($conn) || !helpAssistantEnabled($conn)) return $cache[$analystId] = false;
    $st = $conn->prepare("SELECT 1 FROM analysts WHERE id = ? AND help_assistant = 1 AND COALESCE(is_active, 1) = 1");
    $st->execute([$analystId]);
    return $cache[$analystId] = (bool)$st->fetchColumn();
}

/**
 * The gate for the window's endpoints (api/help_assistant/*): signed in, and the
 * help assistant switched on for the install and for them. Sends a JSON refusal
 * and exits otherwise.
 *
 * @return array{0:PDO,1:int}  the connection and the analyst id
 */
function helpAssistantRequireJson(): array
{
    header('Content-Type: application/json');
    if (empty($_SESSION['analyst_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $conn = connectToDatabase();
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $analystId = (int)$_SESSION['analyst_id'];
    if (!helpAssistantAllowed($conn, $analystId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'The help assistant is not turned on for you.']);
        exit;
    }
    return [$conn, $analystId];
}

/**
 * The level the install is at. Step 3 knows one: 'search' (no AI). Steps 4 and 5
 * add vectors and 'ask' when their keys are set.
 *
 * @return array{level:string}
 */
function helpAssistantLevel(PDO $conn): array
{
    return ['level' => 'search'];
}

// ─── The reply ──────────────────────────────────────────────────────────────

/**
 * What kind of reply a set of results deserves - which decides the canned line.
 * Pure, so it is tested without a database.
 *
 *   none     nothing matched
 *   weak     the best match is weak (score under HELP_ASSISTANT_WEAK_SCORE)
 *   clear    one clear winner (HELP_ASSISTANT_CLEAR_RATIO times the runner-up, or the only result)
 *   module   several, and the best is from the module the asker is in
 *   several  several decent matches
 *
 * @param list<array{score:float,module?:string}> $results  best first
 * @param ?string $currentModule  the module the asker is in
 */
function helpAssistantReplyKind(array $results, ?string $currentModule = null): string
{
    if (!$results) return 'none';
    $top = (float)$results[0]['score'];
    if ($top < HELP_ASSISTANT_WEAK_SCORE) return 'weak';
    $second = isset($results[1]) ? (float)$results[1]['score'] : 0.0;
    if ($second <= 0.0 || $top >= HELP_ASSISTANT_CLEAR_RATIO * $second) return 'clear';
    if ($currentModule !== null && ($results[0]['module'] ?? null) === $currentModule) return 'module';
    return 'several';
}

/**
 * One of the canned lines for a kind of reply, at random, in the asker's
 * language. The variants are help_assistant.phrase.<kind>.1, .2, ... in the
 * language files - as many as there are; English fills any a language lacks.
 */
function helpAssistantPhrase(string $kind): string
{
    $variants = [];
    for ($i = 1; $i <= 12; $i++) {
        $key = "help_assistant.phrase.$kind.$i";
        $text = I18n::t($key);
        if ($text === $key) break;
        $variants[] = $text;
    }
    return $variants ? $variants[array_rand($variants)] : '';
}

/**
 * Answer a question at the search level: the best sections for it, the kind of
 * reply, and its canned line. Results carry what the window shows - title, the
 * page they are on, a preview and a link.
 *
 * @param string $locale  the asker's interface language
 * @param ?string $currentModule  the module they are in
 * @return array{kind:string,text:string,results:list<array{title:string,page_title:string,url:string,snippet:string,module:string}>}
 */
function helpAssistantAnswer(PDO $conn, int $analystId, string $question, string $locale, ?string $currentModule): array
{
    $sections = helpSectionsFor($conn, $analystId, $locale);
    $ranked = helpRank($sections, $question, $currentModule, 5);
    // The page each result is on, by its introduction's title ("Tickets Help").
    $pageTitles = [];
    foreach ($sections as $s) if ($s['anchor'] === '') $pageTitles[$s['page']] = $s['title'];
    $base = defined('BASE_URL') ? BASE_URL : '/';
    $results = [];
    foreach ($ranked as $r) {
        $results[] = [
            'title'      => $r['title'],
            'page_title' => $pageTitles[$r['page']] ?? '',
            'url'        => $base . $r['page'] . ($r['anchor'] !== '' ? '#' . $r['anchor'] : ''),
            'snippet'    => $r['snippet'],
            'module'     => $r['module'],
            'score'      => $r['score'],
        ];
    }
    $kind = helpAssistantReplyKind($results, $currentModule);
    return ['kind' => $kind, 'text' => helpAssistantPhrase($kind), 'results' => $results];
}

// ─── The conversation ───────────────────────────────────────────────────────

/**
 * Keep one message. Only the newest HELP_ASSISTANT_KEEP per analyst are kept.
 *
 * @param string $role  'user' or 'assistant'
 * @param ?array $reply  for an assistant message: ['kind' => ..., 'results' => [...]]
 */
function helpAssistantSave(PDO $conn, int $analystId, string $role, string $body, ?array $reply = null): void
{
    $conn->prepare("INSERT INTO help_assistant_messages (analyst_id, role, body, results, created_datetime) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
         ->execute([$analystId, $role === 'user' ? 'user' : 'assistant', $body, $reply !== null ? json_encode($reply, JSON_UNESCAPED_UNICODE) : null]);
    $cut = $conn->prepare("SELECT id FROM help_assistant_messages WHERE analyst_id = ? ORDER BY id DESC LIMIT 1 OFFSET " . HELP_ASSISTANT_KEEP);
    $cut->execute([$analystId]);
    $oldest = $cut->fetchColumn();
    if ($oldest !== false) {
        $conn->prepare("DELETE FROM help_assistant_messages WHERE analyst_id = ? AND id <= ?")->execute([$analystId, (int)$oldest]);
    }
}

/**
 * The analyst's conversation, oldest first.
 *
 * @return list<array{role:string,body:string,kind:?string,results:list<array>,at:string}>
 */
function helpAssistantHistory(PDO $conn, int $analystId): array
{
    $st = $conn->prepare("SELECT role, body, results, created_datetime FROM help_assistant_messages WHERE analyst_id = ? ORDER BY id");
    $st->execute([$analystId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $reply = $r['results'] !== null ? (json_decode($r['results'], true) ?: []) : [];
        $out[] = ['role' => $r['role'], 'body' => $r['body'], 'kind' => $reply['kind'] ?? null,
                  'results' => $reply['results'] ?? [], 'at' => $r['created_datetime']];
    }
    return $out;
}

/** Reset: the analyst's conversation goes. Returns how many messages were removed. */
function helpAssistantReset(PDO $conn, int $analystId): int
{
    $st = $conn->prepare("DELETE FROM help_assistant_messages WHERE analyst_id = ?");
    $st->execute([$analystId]);
    return $st->rowCount();
}

// ─── The index, for System -> Help assistant ────────────────────────────────

/**
 * How the index stands: entries per language, when it was last built, whether a
 * build is running, and whether the help has changed since (any help page or
 * language file newer than the last build - the quick check; the builder itself
 * compares each section's text).
 *
 * @return array{entries:array<string,int>,built:?string,running:bool,stale:bool,last:?array}
 */
function helpAssistantIndexStatus(PDO $conn): array
{
    $entries = [];
    foreach ($conn->query("SELECT locale, COUNT(*) n FROM help_sections GROUP BY locale ORDER BY locale")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $entries[$r['locale']] = (int)$r['n'];
    }
    $built = $conn->query("SELECT MAX(built_datetime) FROM help_sections")->fetchColumn() ?: null;
    $state = json_decode((string)helpAssistantSetting($conn, 'help_index_build_state'), true) ?: [];
    // A build that has said "running" for over half an hour has died.
    $running = ($state['state'] ?? '') === 'running' && strtotime(($state['started'] ?? '') . ' UTC') > time() - 1800;
    $stale = false;
    if ($built) {
        $since = strtotime($built . ' UTC');
        $root = dirname(__DIR__);
        $files = array_merge(glob("$root/*/help*.php") ?: [], glob("$root/*/*/help*.php") ?: [], glob("$root/system/help/*.php") ?: [], glob("$root/lang/*/*.php") ?: []);
        foreach ($files as $f) { if (filemtime($f) > $since) { $stale = true; break; } }
    }
    return ['entries' => $entries, 'built' => $built, 'running' => $running, 'stale' => $stale, 'last' => $state ?: null];
}

/** A system_settings value, or null. */
function helpAssistantSetting(PDO $conn, string $key): ?string
{
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
}

/** Store a system_settings value. */
function helpAssistantSetSetting(PDO $conn, string $key, string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()")
         ->execute([$key, $value]);
}

/**
 * Start a rebuild in the background - scripts/help_index_build.php, spawned the
 * way the Intune worker is (it outlives the web request). The languages are the
 * ones analysts use (helpIndexLocalesInUse()).
 */
function helpAssistantStartRebuild(PDO $conn): bool
{
    if (helpAssistantIndexStatus($conn)['running']) return true;
    require_once __DIR__ . '/intune.php';   // intuneSpawnGenericWorker(), intuneFindPhpExe()
    helpAssistantSetSetting($conn, 'help_index_build_state', json_encode(['state' => 'running', 'started' => gmdate('Y-m-d H:i:s')]));
    // The worker takes a job id; 0 means "the languages analysts use".
    return intuneSpawnGenericWorker('help_index_build.php', 0, null);
}
