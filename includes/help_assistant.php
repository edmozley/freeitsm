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
require_once __DIR__ . '/help_vectors.php';

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
        // EVERY table and column the assistant uses - a later step's table missing
        // (an upgrade not yet followed by Database Verify) made System -> Help
        // assistant fail with "Something went wrong" instead of saying what to do.
        $ready = (bool)$conn->query("SHOW COLUMNS FROM analysts LIKE 'help_assistant_named'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_assistant_messages'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_sections'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_section_vectors'")->fetch()
              && (bool)$conn->query("SHOW TABLES LIKE 'help_assistant_unanswered'")->fetch();
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
 * The level the install is at: 'search' (words only, no AI) or 'search_meaning'
 * (words and vectors, step 4). Step 5 adds 'ask' when a chat AI is set up.
 *
 * @return array{level:string}
 */
function helpAssistantLevel(PDO $conn): array
{
    return ['level' => helpVectorsReady($conn) !== null ? 'search_meaning' : 'search'];
}

// ─── The reply ──────────────────────────────────────────────────────────────

/**
 * What kind of reply a set of results deserves - which decides the canned line.
 * Pure, so it is tested without a database.
 *
 *   none     nothing matched
 *   weak     the best match is weak
 *   clear    one clear winner
 *   module   several, and the best is from the module the asker is in
 *   several  several decent matches
 *
 * Words alone: weak is a top score under HELP_ASSISTANT_WEAK_SCORE; clear is
 * HELP_ASSISTANT_CLEAR_RATIO times the runner-up, or the only result.
 * With vectors (a result carries 'sim'): weak is BOTH a similarity under
 * HELP_VECTORS_WEAK_SIM and a weak word score; clear is meaning's own first
 * choice leading the next by HELP_VECTORS_CLEAR_LEAD, or first by words and by
 * meaning at once.
 *
 * @param list<array{score:float,module?:string,sim?:?float,word_rank?:?int,vec_rank?:?int,lead?:float}> $results  best first
 * @param ?string $currentModule  the module the asker is in
 */
function helpAssistantReplyKind(array $results, ?string $currentModule = null): string
{
    if (!$results) return 'none';
    $first = $results[0];
    $top = (float)$first['score'];
    if (array_key_exists('sim', $first) && $first['sim'] !== null) {
        if ((float)$first['sim'] < HELP_VECTORS_WEAK_SIM && $top < HELP_ASSISTANT_WEAK_SCORE) return 'weak';
        $meaningFirst = ($first['vec_rank'] ?? null) === 0;
        if (($meaningFirst && (float)($first['lead'] ?? 0) >= HELP_VECTORS_CLEAR_LEAD) || ($meaningFirst && ($first['word_rank'] ?? null) === 0)) return 'clear';
    } else {
        if ($top < HELP_ASSISTANT_WEAK_SCORE) return 'weak';
        $second = isset($results[1]) ? (float)$results[1]['score'] : 0.0;
        if ($second <= 0.0 || $top >= HELP_ASSISTANT_CLEAR_RATIO * $second) return 'clear';
    }
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
    // Words, and - when an embedding model is set up and its vectors are ready -
    // meaning, merged. Any trouble with the embedding call falls back to words.
    $byKey = [];
    foreach ($sections as $s) $byKey[$s['section_key']] = $s;
    $ranked = helpMerge(helpRank($sections, $question, $currentModule, 20), helpVectorRank($conn, $sections, $question, 20), $byKey, 5);
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
            'sim'        => $r['sim'] ?? null,
            'word_rank'  => $r['word_rank'] ?? null,
            'vec_rank'   => $r['vec_rank'] ?? null,
            'lead'       => $r['lead'] ?? null,
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

// ─── Unanswered questions (step 6) ──────────────────────────────────────────

/**
 * Whether a reply means the help could not answer: nothing found, or only weak
 * matches. (Step 5 adds the AI saying so.)
 */
function helpAssistantShouldRecord(string $kind): bool
{
    return in_array($kind, ['none', 'weak'], true);
}

/** Whether an admin chose to record this analyst's name with their unanswered questions. */
function helpAssistantNamed(PDO $conn, int $analystId): bool
{
    try {
        $st = $conn->prepare("SELECT help_assistant_named FROM analysts WHERE id = ?");
        $st->execute([$analystId]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
        return false;   // before Database Verify: never record a name
    }
}

/**
 * Keep a question the help could not answer, and tell the administrators.
 *
 * - The asker's name is kept ONLY when an admin turned on Record who asked for
 *   them (and their window says so); otherwise the question is kept without it.
 * - The bell goes to every active administrator EXCEPT the asker - rule 1 of
 *   NotificationsService::notify(), the asker being the actor. Every question is
 *   keyed to the same entity, so they coalesce into one row with a count.
 *
 * Never throws: a failure here must not cost the asker their answer.
 *
 * @param array $reply  helpAssistantAnswer()'s result
 * @return ?int  the new row's id, or null when not recorded
 */
function helpAssistantRecordUnanswered(PDO $conn, int $analystId, string $question, string $locale, ?string $module, array $reply): ?int
{
    if (!helpAssistantShouldRecord((string)($reply['kind'] ?? ''))) return null;
    try {
        // Is the help really missing, or just out of THIS analyst's reach? Asked
        // again across every module (as an administrator would see it): when that
        // finds a good answer, the help exists - they cannot open that module - and
        // it is not "help worth writing". Only on a miss, so it costs nothing usually.
        $everyone = helpIndexRenderAnalyst($conn);
        if ($everyone !== null && $everyone !== $analystId) {
            $wide = helpAssistantAnswer($conn, $everyone, $question, $locale, $module);
            if (!helpAssistantShouldRecord($wide['kind'])) return null;
        }
        $named = helpAssistantNamed($conn, $analystId);
        $conn->prepare("INSERT INTO help_assistant_unanswered (question, locale, module, kind, best_title, analyst_id, created_datetime)
                        VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())")
             ->execute([mb_substr($question, 0, 500), mb_substr($locale, 0, 10), $module !== null ? mb_substr($module, 0, 50) : null,
                        $reply['kind'], isset($reply['results'][0]['title']) ? mb_substr((string)$reply['results'][0]['title'], 0, 255) : null,
                        $named ? $analystId : null]);
        $id = (int)$conn->lastInsertId();

        require_once __DIR__ . '/services/notifications.php';
        $actorName = null;
        if ($named) {
            $n = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $n->execute([$analystId]);
            $actorName = (string)$n->fetchColumn() ?: null;
        }
        $admins = $conn->query("SELECT id FROM analysts WHERE is_admin = 1 AND COALESCE(is_active, 1) = 1")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            NotificationsService::notify($conn, [
                'analyst_id'  => (int)$adminId,
                'event_type'  => 'help.unanswered',
                'entity_type' => 'help_unanswered',
                'entity_id'   => 1,
                'title'       => 'Help assistant',
                'body'        => mb_substr($question, 0, 200),
                'actor_id'    => $analystId,   // rule 1: never the asker
                'actor_name'  => $actorName,
            ]);
        }
        return $id;
    } catch (Throwable $e) {
        error_log('help assistant: could not record an unanswered question: ' . $e->getMessage());
        return null;
    }
}

/**
 * The unanswered questions, newest first, with names where they were recorded.
 *
 * @param string $status  'new' (not dealt with), 'done' or 'all'
 * @return list<array{id:int,question:string,locale:string,module:?string,kind:string,best_title:?string,asker:?string,at:string,dealt_at:?string,dealt_by:?string}>
 */
function helpAssistantUnanswered(PDO $conn, string $status = 'new', int $limit = 200): array
{
    $where = ['new' => 'u.dealt_datetime IS NULL', 'done' => 'u.dealt_datetime IS NOT NULL'][$status] ?? '1=1';
    $rows = $conn->query("SELECT u.id, u.question, u.locale, u.module, u.kind, u.best_title, u.created_datetime, u.dealt_datetime,
                                 a.full_name asker, d.full_name dealt_name
                            FROM help_assistant_unanswered u
                            LEFT JOIN analysts a ON a.id = u.analyst_id
                            LEFT JOIN analysts d ON d.id = u.dealt_by
                           WHERE $where ORDER BY u.id DESC LIMIT " . max(1, min(500, $limit)))->fetchAll(PDO::FETCH_ASSOC);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'question' => $r['question'], 'locale' => $r['locale'], 'module' => $r['module'],
        'kind' => $r['kind'], 'best_title' => $r['best_title'], 'asker' => $r['asker'], 'at' => $r['created_datetime'],
        'dealt_at' => $r['dealt_datetime'], 'dealt_by' => $r['dealt_name']], $rows);
}

/** How many are waiting to be dealt with. */
function helpAssistantUnansweredCount(PDO $conn): int
{
    try {
        return (int)$conn->query("SELECT COUNT(*) FROM help_assistant_unanswered WHERE dealt_datetime IS NULL")->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Mark one dealt with (the help now covers it), or not. */
function helpAssistantMarkUnanswered(PDO $conn, int $id, bool $dealt, int $byAnalystId): bool
{
    if ($dealt) {
        $st = $conn->prepare("UPDATE help_assistant_unanswered SET dealt_datetime = UTC_TIMESTAMP(), dealt_by = ? WHERE id = ?");
        $st->execute([$byAnalystId, $id]);
    } else {
        $st = $conn->prepare("UPDATE help_assistant_unanswered SET dealt_datetime = NULL, dealt_by = NULL WHERE id = ?");
        $st->execute([$id]);
    }
    return $st->rowCount() > 0;
}
