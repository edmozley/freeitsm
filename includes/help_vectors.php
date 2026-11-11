<?php
/**
 * The help assistant's vectors - matching by meaning (wiki Help-Assistant-Design, step 4).
 *
 * OPTIONAL. With no embedding model set up, nothing here runs and the assistant
 * searches by words exactly as before. With one, every help section gets a vector
 * (help_section_vectors), each question gets one when it is asked, and the two
 * rankings - words and meaning - are merged (helpMerge()).
 *
 * 🔴 ONE MODEL AT A TIME. Vectors from different models mean nothing to each
 * other, so every vector carries its model's identity (aiEmbeddingModelId()) and
 * only those made by the model IN USE (the help_vectors_model setting) are ever
 * compared. Choosing another model does not switch over: a rebuild makes the new
 * set beside the old, and only when it is complete does help_vectors_model move -
 * so nothing goes dark in between, and a failed rebuild changes nothing.
 *
 * Measured on the question set (tests/help-search-questions.php), OpenAI
 * text-embedding-3-small at 512 dimensions, words at half weight:
 *   words alone  plain 43/47  exact 6/6  vague 2/6  other languages 2/6
 *   merged       plain 46/47  exact 6/6  vague 6/6  other languages 3/6
 * 512 dimensions scored the same as the full 1,536 and compare three times faster.
 */

require_once __DIR__ . '/ai_embeddings.php';
require_once __DIR__ . '/ai_settings.php';
require_once __DIR__ . '/encryption.php';

/** The AI settings namespace (includes/ai_settings.php) - its own key, so its cost shows on its own. */
const HELP_VECTORS_NS = 'help_embed';

/** OpenAI's text-embedding-3 models are asked for vectors this long: as good as full length here, three times quicker to compare. */
const HELP_VECTORS_DIMENSIONS = 512;

/** The model ready-made vectors ship for, with each release (data/help-vectors/). */
const HELP_VECTORS_SHIPPED_ID = 'openai:text-embedding-3-small@512';

/** Where the ready-made vectors live. */
const HELP_VECTORS_SHIPPED_FILE = __DIR__ . '/../data/help-vectors/openai-text-embedding-3-small-512.json.gz';

/** Word search's weight in the merge - half, because meaning ranked better on the question set. */
const HELP_MERGE_WORD_WEIGHT = 0.5;

/** "Weak", when vectors are in use: the best section's similarity below this and its word score weak too. */
const HELP_VECTORS_WEAK_SIM = 0.45;

/** "Clear winner", when vectors are in use: the best section leads the next by this much similarity. */
const HELP_VECTORS_CLEAR_LEAD = 0.10;

/**
 * Below this similarity, meaning is just noise: off-topic questions ("best pizza
 * recipe", "xyzzy plugh") reach 0.21-0.26 against the help, while the lowest real
 * question in the question set reached 0.38. Sections under it are not suggested.
 */
const HELP_VECTORS_FLOOR = 0.32;

/** Texts per request when building. */
const HELP_VECTORS_BATCH = 64;

// ─── Settings ───────────────────────────────────────────────────────────────

/** Whether matching by meaning is switched on (System -> Help assistant). */
function helpVectorsEnabled(PDO $conn): bool
{
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'help_vectors_enabled'");
    $st->execute();
    return $st->fetchColumn() === '1';
}

/**
 * The embedding configuration (decrypted key) with the dimensions to ask for.
 *
 * @return array  aiSettingsLoad() shape plus 'dimensions' (int|null)
 */
function helpVectorsConfig(PDO $conn): array
{
    $cfg = aiSettingsLoad($conn, HELP_VECTORS_NS);
    $cfg['dimensions'] = ($cfg['provider'] !== 'openai_compatible' && str_starts_with((string)$cfg['model'], 'text-embedding-3')) ? HELP_VECTORS_DIMENSIONS : null;
    return $cfg;
}

/** Whether a configuration could embed at all: a provider that can, and its key or address. */
function helpVectorsUsable(array $cfg): bool
{
    if (!in_array($cfg['provider'] ?? '', AI_EMBEDDING_PROVIDERS, true) || trim((string)($cfg['model'] ?? '')) === '') return false;
    if ($cfg['provider'] === 'openai_compatible') return trim((string)($cfg['compatible_base_url'] ?? '')) !== '';
    if ($cfg['provider'] === 'azure' && trim((string)($cfg['azure_endpoint'] ?? '')) === '') return false;
    return (string)($cfg['api_key'] ?? '') !== '';
}

/** The model whose vectors are in use, or null. */
function helpVectorsActiveModel(PDO $conn): ?string
{
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'help_vectors_model'");
    $st->execute();
    $v = $st->fetchColumn();
    return $v === false || $v === '' ? null : (string)$v;
}

/** Store a setting (as System pages do). */
function helpVectorsSet(PDO $conn, string $key, string $value): void
{
    $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_datetime) VALUES (?, ?, UTC_TIMESTAMP())
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = UTC_TIMESTAMP()")
         ->execute([$key, $value]);
}

/**
 * The settings of the model IN USE - kept apart from the settings chosen on the
 * page, with the key encrypted.
 *
 * TRAP: a question must be embedded by the model its candidates were made with.
 * Once an admin saves a NEW model, the saved settings describe the new one, not
 * the one whose vectors are answering - so questions are embedded with these,
 * written at the moment a model is switched into use (helpVectorsBuild()). That
 * is what lets the old model keep answering while the new one is built.
 *
 * @return ?array  an aiEmbedBatch() configuration (decrypted key), or null
 */
function helpVectorsActiveConfig(PDO $conn): ?array
{
    $st = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'help_vectors_active_cfg'");
    $st->execute();
    $cfg = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($cfg)) return null;
    $cfg['api_key'] = ($cfg['api_key'] ?? '') !== '' ? (string)decryptValue($cfg['api_key']) : '';
    return $cfg;
}

/** Remember a configuration as the one in use - its key encrypted, like every stored key. */
function helpVectorsSetActiveConfig(PDO $conn, array $cfg): void
{
    $keep = array_intersect_key($cfg, array_flip(['provider', 'model', 'dimensions', 'azure_endpoint', 'azure_deployment', 'azure_api_version', 'compatible_base_url']));
    $keep['api_key'] = ($cfg['api_key'] ?? '') !== '' ? encryptValue((string)$cfg['api_key']) : '';
    helpVectorsSet($conn, 'help_vectors_active_cfg', json_encode($keep));
}

/**
 * Whether questions can be matched by meaning RIGHT NOW: switched on, the table
 * there, and a model in use whose settings still describe it. While a new model
 * is being built, the old one answers; anything else searches by words.
 *
 * Cached per request (the header asks on every page); $fresh forgets the cache,
 * for code that changes the settings within one request - the build, the tests.
 *
 * @return ?array  the configuration to embed questions with, or null
 */
function helpVectorsReady(PDO $conn, bool $fresh = false): ?array
{
    static $memo = [];
    $k = spl_object_id($conn);
    if ($fresh) unset($memo[$k]);
    if (array_key_exists($k, $memo)) return $memo[$k];
    try {
        if (!$conn->query("SHOW TABLES LIKE 'help_section_vectors'")->fetch() || !helpVectorsEnabled($conn)) return $memo[$k] = null;
        $active = helpVectorsActiveModel($conn);
        $cfg = helpVectorsActiveConfig($conn);
        if ($active === null || $cfg === null || !helpVectorsUsable($cfg) || aiEmbeddingModelId($cfg) !== $active) return $memo[$k] = null;
        return $memo[$k] = $cfg;
    } catch (Throwable $e) {
        return $memo[$k] = null;
    }
}

// ─── Status, for System -> Help assistant ───────────────────────────────────

/**
 * Everything the page shows about vectors. Never the key itself - only whether
 * there is one, and its last four characters.
 */
function helpVectorsStatus(PDO $conn): array
{
    $cfg = helpVectorsConfig($conn);
    $chosen = aiEmbeddingModelId($cfg);
    $active = helpVectorsActiveModel($conn);
    $count = function (string $model) use ($conn): array {
        $st = $conn->prepare("SELECT COUNT(*) total, SUM(v.id IS NOT NULL AND v.text_hash = s.text_hash) current
                                FROM help_sections s LEFT JOIN help_section_vectors v ON v.section_id = s.id AND v.model = ?");
        $st->execute([$model]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['total' => (int)($r['total'] ?? 0), 'current' => (int)($r['current'] ?? 0)];
    };
    $key = (string)$cfg['api_key'];
    return [
        'enabled'   => helpVectorsEnabled($conn),
        'provider'  => in_array($cfg['provider'], AI_EMBEDDING_PROVIDERS, true) ? $cfg['provider'] : 'openai',
        'model'     => $cfg['model'],
        'has_key'   => $key !== '',
        // TRAP: asterisks, not bullets - isMaskedNoChangeValue() treats a value starting
        // with * as "unchanged", so this hint sent back can never be saved as the key.
        'key_hint'  => $key !== '' ? '****' . substr($key, -4) : '',
        'azure_endpoint' => $cfg['azure_endpoint'], 'azure_deployment' => $cfg['azure_deployment'], 'azure_api_version' => $cfg['azure_api_version'],
        'compatible_base_url' => $cfg['compatible_base_url'],
        'usable'    => helpVectorsUsable($cfg),
        'chosen'    => $chosen,
        'active'    => $active,
        'in_use'    => helpVectorsReady($conn) !== null,
        'chosen_counts' => $count($chosen),
        'active_counts' => $active ? $count($active) : null,
        'shipped'   => ['id' => HELP_VECTORS_SHIPPED_ID, 'available' => is_file(HELP_VECTORS_SHIPPED_FILE), 'matches' => $chosen === HELP_VECTORS_SHIPPED_ID],
    ];
}

// ─── Building ───────────────────────────────────────────────────────────────

/**
 * Bring the configured model's vectors up to date, then make it the model in use.
 *
 * 1. If it is the shipped model, take the ready-made vectors for every section
 *    whose text is unchanged (free).
 * 2. Embed every section that still has no current vector, HELP_VECTORS_BATCH at
 *    a time.
 * 3. Only when every section has one: help_vectors_model = this model, and the
 *    other models' vectors are removed. Until then the old model keeps answering.
 *
 * @param ?callable $log  fn(string $line)
 * @return array{model:string,imported:int,embedded:int,missing:int,switched:bool,error:?string}
 */
function helpVectorsBuild(PDO $conn, ?callable $log = null): array
{
    $log ??= fn(string $s) => null;
    $cfg = helpVectorsConfig($conn);
    $model = aiEmbeddingModelId($cfg);
    $out = ['model' => $model, 'imported' => 0, 'embedded' => 0, 'missing' => 0, 'switched' => false, 'error' => null];
    if (!helpVectorsEnabled($conn) || !helpVectorsUsable($cfg)) { $out['error'] = 'Matching by meaning is not set up.'; return $out; }

    if ($model === HELP_VECTORS_SHIPPED_ID) $out['imported'] = helpVectorsImportShipped($conn);

    $need = $conn->prepare("SELECT s.id, s.title, s.body, s.text_hash FROM help_sections s
                              LEFT JOIN help_section_vectors v ON v.section_id = s.id AND v.model = ?
                             WHERE v.id IS NULL OR v.text_hash <> s.text_hash ORDER BY s.id");
    $need->execute([$model]);
    $rows = $need->fetchAll(PDO::FETCH_ASSOC);
    $save = $conn->prepare("INSERT INTO help_section_vectors (section_id, model, text_hash, vector, source, source_version, built_datetime)
                            VALUES (?, ?, ?, ?, 'built', NULL, UTC_TIMESTAMP())
                            ON DUPLICATE KEY UPDATE text_hash = VALUES(text_hash), vector = VALUES(vector), source = 'built', source_version = NULL, built_datetime = UTC_TIMESTAMP()");
    foreach (array_chunk($rows, HELP_VECTORS_BATCH) as $chunk) {
        $r = aiEmbedBatch($cfg, array_map(fn($s) => $s['title'] . "\n\n" . $s['body'], $chunk));
        if ($r['error'] !== null) { $out['error'] = $r['error']; break; }
        foreach ($chunk as $i => $s) $save->execute([$s['id'], $model, $s['text_hash'], aiVectorPack($r['vectors'][$i])]);
        $out['embedded'] += count($chunk);
        $log(sprintf('  vectors: %d of %d', $out['embedded'], count($rows)));
    }

    $miss = $conn->prepare("SELECT COUNT(*) FROM help_sections s LEFT JOIN help_section_vectors v ON v.section_id = s.id AND v.model = ?
                             WHERE v.id IS NULL OR v.text_hash <> s.text_hash");
    $miss->execute([$model]);
    $out['missing'] = (int)$miss->fetchColumn();
    if ($out['missing'] === 0 && $out['error'] === null) {
        // The switch: this model's settings become the ones questions are embedded
        // with, then the other models' vectors go.
        helpVectorsSetActiveConfig($conn, $cfg);
        helpVectorsSet($conn, 'help_vectors_model', $model);
        $conn->prepare("DELETE FROM help_section_vectors WHERE model <> ?")->execute([$model]);
        helpVectorsReady($conn, true);
        $out['switched'] = true;
    }
    return $out;
}

/**
 * Take the ready-made vectors shipped with the release, for every section whose
 * text still matches what they were made from. A section changed here (an edited
 * language file) is left for helpVectorsBuild() to embed.
 *
 * @return int  how many were taken
 */
function helpVectorsImportShipped(PDO $conn): int
{
    $pack = helpVectorsReadShipped();
    if (!$pack || ($pack['model'] ?? '') !== HELP_VECTORS_SHIPPED_ID) return 0;
    $rows = $conn->query("SELECT id, section_key, locale, text_hash FROM help_sections")->fetchAll(PDO::FETCH_ASSOC);
    $have = $conn->prepare("SELECT text_hash FROM help_section_vectors WHERE section_id = ? AND model = ?");
    $save = $conn->prepare("INSERT INTO help_section_vectors (section_id, model, text_hash, vector, source, source_version, built_datetime)
                            VALUES (?, ?, ?, ?, 'release', ?, UTC_TIMESTAMP())
                            ON DUPLICATE KEY UPDATE text_hash = VALUES(text_hash), vector = VALUES(vector), source = 'release', source_version = VALUES(source_version), built_datetime = UTC_TIMESTAMP()");
    $n = 0;
    foreach ($rows as $r) {
        $v = $pack['vectors'][$r['section_key'] . '|' . $r['locale']] ?? null;
        if (!$v || $v[0] !== $r['text_hash']) continue;
        $have->execute([$r['id'], HELP_VECTORS_SHIPPED_ID]);
        if ($have->fetchColumn() === $r['text_hash']) continue;
        $save->execute([$r['id'], HELP_VECTORS_SHIPPED_ID, $r['text_hash'], $v[1], (string)($pack['version'] ?? '')]);
        $n++;
    }
    return $n;
}

/** The shipped file, decoded, or null. */
function helpVectorsReadShipped(): ?array
{
    if (!is_file(HELP_VECTORS_SHIPPED_FILE)) return null;
    $raw = @gzdecode((string)file_get_contents(HELP_VECTORS_SHIPPED_FILE));
    $pack = $raw !== false ? json_decode($raw, true) : null;
    return is_array($pack) ? $pack : null;
}

/**
 * Write the shipped file from this install's vectors for the shipped model - a
 * release step (scripts/help_vectors_export.php), run with the help as released.
 *
 * @param string $version  the FreeITSM version they are for
 * @return int  how many vectors were written
 */
function helpVectorsExportShipped(PDO $conn, string $version): int
{
    $st = $conn->prepare("SELECT s.section_key, s.locale, v.text_hash, v.vector FROM help_section_vectors v
                            JOIN help_sections s ON s.id = v.section_id WHERE v.model = ? AND v.text_hash = s.text_hash AND s.locale = 'en'
                           ORDER BY s.section_key");   // English ships; other languages are built where they are used
    $st->execute([HELP_VECTORS_SHIPPED_ID]);
    $vectors = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $vectors[$r['section_key'] . '|' . $r['locale']] = [$r['text_hash'], $r['vector']];
    if (!is_dir(dirname(HELP_VECTORS_SHIPPED_FILE))) mkdir(dirname(HELP_VECTORS_SHIPPED_FILE), 0775, true);
    file_put_contents(HELP_VECTORS_SHIPPED_FILE, gzencode(json_encode(['model' => HELP_VECTORS_SHIPPED_ID, 'version' => $version,
        'built' => gmdate('Y-m-d H:i:s'), 'vectors' => $vectors], JSON_UNESCAPED_SLASHES), 9));
    return count($vectors);
}

// ─── Searching ──────────────────────────────────────────────────────────────

/**
 * Rank the analyst's sections by meaning: the question embedded by the model in
 * use, compared with every candidate's vector.
 *
 * @param list<array> $sections  helpSectionsFor() rows (with id)
 * @return ?array{ranked:list<array{section_key:string,sim:float}>,lead:float}  null when meaning cannot be used now
 */
function helpVectorRank(PDO $conn, array $sections, string $question, int $limit = 20): ?array
{
    $cfg = helpVectorsReady($conn);
    if ($cfg === null || !$sections) return null;
    $q = aiEmbedBatch($cfg, [$question]);
    if ($q['error'] !== null) { error_log('help assistant: question not embedded, searching by words: ' . $q['error']); return null; }
    $qv = $q['vectors'][0];
    // Only the candidates' vectors - one language and English, not all of them.
    $ids = array_map(fn($s) => (int)$s['id'], $sections);
    $st = $conn->prepare("SELECT section_id, vector FROM help_section_vectors WHERE model = ? AND section_id IN (" . implode(',', $ids) . ")");
    $st->execute([aiEmbeddingModelId($cfg)]);
    $vec = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    $sims = [];
    foreach ($sections as $s) {
        if (isset($vec[$s['id']])) $sims[$s['section_key']] = aiCosineSimilarity($qv, aiVectorUnpack($vec[$s['id']]));
    }
    // Nothing above the floor: the question is not about anything in the help, and
    // meaning has nothing to add - words alone decide (often "nothing found").
    $sims = array_filter($sims, fn($s) => $s >= HELP_VECTORS_FLOOR);
    if (!$sims) return null;
    arsort($sims);
    $vals = array_values($sims);
    $ranked = [];
    foreach (array_slice($sims, 0, $limit, true) as $k => $sim) $ranked[] = ['section_key' => $k, 'sim' => $sim];
    return ['ranked' => $ranked, 'lead' => $vals[0] - ($vals[1] ?? 0.0)];
}

/**
 * Merge the word ranking and the meaning ranking - reciprocal rank fusion: each
 * list adds weight / (60 + position), so a section near the top of both comes
 * first. Words count half (HELP_MERGE_WORD_WEIGHT). Pure, so it is tested
 * without a database.
 *
 * @param list<array> $word  helpRank() results (section rows with score, snippet), best first
 * @param ?array $vec  helpVectorRank()'s result, or null for words alone
 * @param array<string,array> $byKey  every candidate section by section_key - for one only meaning found
 * @return list<array>  the sections, best first, each with score (words, 0 if none), sim (or null), word_rank and vec_rank (or null)
 */
function helpMerge(array $word, ?array $vec, array $byKey, int $limit = 5): array
{
    if ($vec === null) {
        return array_map(fn($r, $i) => $r + ['sim' => null, 'word_rank' => $i, 'vec_rank' => null], array_slice($word, 0, $limit), array_keys(array_slice($word, 0, $limit)));
    }
    $fused = []; $wordAt = []; $vecAt = []; $sim = [];
    foreach ($word as $i => $r) { $k = $r['section_key']; $wordAt[$k] = $i; $fused[$k] = ($fused[$k] ?? 0) + HELP_MERGE_WORD_WEIGHT / (60 + $i + 1); }
    foreach ($vec['ranked'] as $i => $r) { $k = $r['section_key']; $vecAt[$k] = $i; $sim[$k] = $r['sim']; $fused[$k] = ($fused[$k] ?? 0) + 1 / (60 + $i + 1); }
    arsort($fused);
    $wordRow = [];
    foreach ($word as $r) $wordRow[$r['section_key']] = $r;
    $out = [];
    foreach (array_slice(array_keys($fused), 0, $limit) as $k) {
        $row = $wordRow[$k] ?? (isset($byKey[$k]) ? $byKey[$k] + ['score' => 0.0, 'snippet' => helpSnippet($byKey[$k]['body'], [])] : null);
        if ($row === null) continue;
        $out[] = $row + ['sim' => $sim[$k] ?? null, 'word_rank' => $wordAt[$k] ?? null, 'vec_rank' => $vecAt[$k] ?? null, 'lead' => $vec['lead']];
    }
    return $out;
}
