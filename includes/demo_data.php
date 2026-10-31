<?php
/**
 * Demo data — the one place that knows what a demo module is made of.
 *
 * Two endpoints need the same answer to "which tables does module X put rows
 * into?": the importer, to clear its own previous rows before inserting fresh
 * ones, and the demo data page, to say whether a module has been imported.
 * They used to answer it separately, and the second answer was wrong — it
 * asked "does this table have ANY rows?", so an installation with real tickets
 * or real assets was reported as already having the demo data. Same family of
 * fault as the importer's own: nothing distinguished demo rows from real ones.
 *
 * The distinguishing mark is now the `is_demo` column, set on every row the
 * importer inserts and present on all of the tables listed here.
 */

/** Modules with a database/demo-data/{module}.json file. Order is display order. */
const DEMO_MODULES = [
    'core', 'tickets', 'assets', 'knowledge', 'changes', 'calendar', 'checks',
    'checklists',
    'contracts', 'domains', 'cost-centres', 'services', 'software', 'forms', 'software-assets',
    'dashboards', 'tasks', 'starters', 'process-mapper', 'cmdb', 'lms', 'workflow',
    'network-mapper',
];

/**
 * After a module's rows are in, inside the import's transaction (3.3.0).
 *
 * tasks: a demo project baseline is a snapshot of the plan (includes/projects/
 * control.php) holding stage and milestone IDS, which only exist once inserted,
 * so the JSON gives each baseline a recipe instead -
 *   snapshot: {"_demo": {"target_shift": -14, "budget_delta": -4500, "stage_shift": -10}}
 * - and this takes the project's plan as imported and moves it by that much:
 * the plan as it stood before the change request that moved it.
 *
 * @param string $module  the DEMO_MODULES key just imported, e.g. 'tasks'; only 'tasks' does anything
 */
function demoAfterImport(PDO $conn, string $module): void {
    if ($module === 'starters') { demoAfterStarters($conn); return; }
    if ($module !== 'tasks') return;
    try { $rows = $conn->query("SELECT id, project_id, snapshot FROM project_baselines WHERE is_demo = 1")->fetchAll(PDO::FETCH_ASSOC); }
    catch (Throwable) { return; }   // before Database Verification
    if (!$rows) return;
    require_once __DIR__ . '/projects/control.php';
    $shift = fn(?string $d, int $days) => $d ? gmdate('Y-m-d', strtotime($d . ' 00:00:00 UTC') + $days * 86400) : null;
    $up = $conn->prepare("UPDATE project_baselines SET snapshot = ?, start_date = ?, target_end_date = ?, budget_planned = ?, task_count = ?, estimate_hours = ?, must_count = ? WHERE id = ?");
    foreach ($rows as $b) {
        $recipe = json_decode((string)$b['snapshot'], true)['_demo'] ?? null;
        if (!is_array($recipe)) continue;
        $s = projectPlanSnapshot($conn, (int)$b['project_id']);
        $t = (int)($recipe['target_shift'] ?? 0); $st = (int)($recipe['stage_shift'] ?? 0);
        $s['target_end_date'] = $shift($s['target_end_date'], $t);
        foreach ($s['stages'] as &$x) { if ($x['end_date'] && $x['end_date'] >= gmdate('Y-m-d', time() - 30 * 86400)) { $x['end_date'] = $shift($x['end_date'], $st); } }
        unset($x);
        foreach ($s['milestones'] as &$x) { if ($x['due_date'] >= gmdate('Y-m-d')) $x['due_date'] = $shift($x['due_date'], $st); }
        unset($x);
        if ($s['budget_planned'] !== null) $s['budget_planned'] = round($s['budget_planned'] + (float)($recipe['budget_delta'] ?? 0), 2);
        if (isset($recipe['task_delta'])) $s['task_count'] = max(0, (int)$s['task_count'] + (int)$recipe['task_delta']);
        $up->execute([json_encode($s), $s['start_date'], $s['target_end_date'], $s['budget_planned'], $s['task_count'] ?? null, $s['estimate_hours'] ?? null, isset($s['must']) ? count($s['must']) : null, (int)$b['id']]);
    }
}

/**
 * starters (4.6.0): a case's work and answers live in two OTHER demo modules - the
 * case tasks in Tasks, the "New starter request" answers in Forms - because a demo
 * import clears every is_demo row of each table it writes, so two modules must
 * never write the same table. The JSON cannot point across with an @ref (another
 * file's refs are not in this import), and a _skip_insert match on the task title
 * would stop matching once the title carries its date. So each case finds its own:
 *   - its parent task: the demo top-level task titled "<kind>: <name>..." - matched on
 *     ": <name>" alone, because a re-import finds the title already rewritten in the
 *     case's language ("Eintritt: ...");
 *   - its answers: the demo submission whose "Full name" is the case's person;
 * then the task is given the title and description opening the case would have
 * written (StartersService::refreshTaskText) - with the date of THIS import.
 * The starter templates are pointed at the demo form here too, not by a title match in
 * the JSON: an install whose demo data predates is_demo has an untagged "New Starter
 * Request" of its own, and MySQL compares titles without regard to case.
 * Missing Tasks or Forms demo data leaves the link empty, as a real case can be.
 */
function demoAfterStarters(PDO $conn): void {
    require_once __DIR__ . '/starters/service.php';
    $form = $conn->query("SELECT id FROM forms WHERE is_demo = 1 AND title = 'New starter request' ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
    $conn->prepare("UPDATE jml_templates SET form_id = ? WHERE is_demo = 1 AND kind = 'starter'")->execute([$form]);
    $cases = $conn->query("SELECT c.id, c.kind, c.person_name, t.form_id FROM jml_cases c
                             LEFT JOIN jml_templates t ON t.id = c.template_id WHERE c.is_demo = 1")->fetchAll(PDO::FETCH_ASSOC);
    $task = $conn->prepare("SELECT id FROM tasks WHERE is_demo = 1 AND parent_task_id IS NULL AND title LIKE ? ORDER BY id LIMIT 1");
    $sub = $conn->prepare("SELECT d.submission_id FROM form_submission_data d
                             JOIN form_fields f ON f.id = d.field_id JOIN form_submissions s ON s.id = d.submission_id
                            WHERE s.is_demo = 1 AND LOWER(f.label) = 'full name' AND d.field_value = ? ORDER BY s.id DESC LIMIT 1");
    $link = $conn->prepare("UPDATE jml_cases SET parent_task_id = ?, form_submission_id = ? WHERE id = ?");
    foreach ($cases as $c) {
        $task->execute(['%: ' . addcslashes((string)$c['person_name'], '%_') . '%']);
        $submission = null;
        if ($c['form_id']) {
            $sub->execute([$c['person_name']]);
            $submission = $sub->fetchColumn() ?: null;
        }
        $link->execute([$task->fetchColumn() ?: null, $submission, (int)$c['id']]);
        StartersService::refreshTaskText($conn, (int)$c['id']);
    }
}

/**
 * Returns the path of a module's demo data file, database/demo-data/{module}.json.
 *
 * @param string $module  a DEMO_MODULES key, e.g. 'tickets' (trusted - put straight into the path)
 */
function demoDataPath(string $module): string {
    return __DIR__ . "/../database/demo-data/{$module}.json";
}

/**
 * The tables a parsed demo module actually INSERTS into.
 *
 * A table listed only with `_skip_insert` records is excluded: those records
 * match an existing row to resolve a reference (the admin analyst, the CMDB
 * icons) and the importer never creates or removes them. Getting this wrong in
 * the clearing direction is what makes an importer destructive, so it is
 * deliberately the narrow reading.
 *
 * @param array $demoData  a decoded demo-data JSON file: tier1..tier5 => [table => [record, ...]]
 */
function demoTablesWithInserts(array $demoData): array {
    $tables = [];
    foreach (['tier1', 'tier2', 'tier3', 'tier4', 'tier5'] as $tierKey) {
        if (!isset($demoData[$tierKey])) continue;
        foreach ($demoData[$tierKey] as $tableName => $records) {
            foreach ($records as $record) {
                if (empty($record['_skip_insert'])) {
                    if (!in_array($tableName, $tables, true)) $tables[] = $tableName;
                    break;
                }
            }
        }
    }
    return $tables;
}

/**
 * As above, but reads the module's JSON from disk. Returns [] if unreadable.
 *
 * @param string $module  a DEMO_MODULES key, e.g. 'tickets'
 */
function demoModuleTables(string $module): array {
    $path = demoDataPath($module);
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? demoTablesWithInserts($data) : [];
}

/**
 * How many demo rows a module currently has in the database.
 *
 * Counts only rows marked is_demo = 1, so real data is never mistaken for
 * demo data. A missing table or column counts as zero rather than throwing —
 * a module whose feature was never set up simply has no demo rows.
 *
 * @param string $module  a DEMO_MODULES key, e.g. 'tickets'
 */
function demoRowCount(PDO $conn, string $module): int {
    $total = 0;
    foreach (demoModuleTables($module) as $table) {
        try {
            $total += (int)$conn->query("SELECT COUNT(*) FROM `$table` WHERE is_demo = 1")->fetchColumn();
        } catch (Exception) {
            // table or column absent on this installation — nothing to count
        }
    }
    return $total;
}

/**
 * Which other demo modules are holding a table down.
 *
 * Clearing a module's rows runs with foreign key checks ON (turning them off is
 * what orphaned the SSO links in #1296), so a demo row another module still
 * points at cannot be deleted — re-importing Core while the Tickets demo data
 * exists fails, because demo tickets reference demo requesters.
 *
 * Refusing is correct; refusing without saying what is in the way is not. This
 * finds the demo tables that reference $table and still hold demo rows, and
 * names the modules they belong to so the message can tell the administrator
 * what to deal with first.
 *
 * @param string $table  the table whose demo rows could not be cleared
 * @param string $exceptModule  the DEMO_MODULES key being imported, left out of the answer
 */
function demoBlockingModules(PDO $conn, string $table, string $exceptModule): array {
    $blocking = [];
    try {
        $stmt = $conn->prepare(
            "SELECT DISTINCT TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?"
        );
        $stmt->execute([$table]);
        $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception) {
        return [];
    }

    foreach (DEMO_MODULES as $module) {
        if ($module === $exceptModule) continue;
        foreach (demoModuleTables($module) as $moduleTable) {
            if (!in_array($moduleTable, $children, true)) continue;
            try {
                $n = (int)$conn->query("SELECT COUNT(*) FROM `$moduleTable` WHERE is_demo = 1")->fetchColumn();
            } catch (Exception) {
                continue;
            }
            if ($n > 0 && !in_array($module, $blocking, true)) $blocking[] = $module;
        }
    }
    return $blocking;
}

/**
 * Explain a unique-key collision during an import in terms of its actual cause.
 *
 * On an installation whose demo data predates the is_demo mark, the importer
 * cannot recognise the old sample rows, so it does not remove them, and then
 * collides with them: `Duplicate entry 'jsmith' for key analysts.uq_...`. That
 * message is true and useless — it names a row the administrator never created
 * and says nothing about what to do. It is also the likeliest failure on
 * exactly the installations most at risk, so it is worth spelling out.
 *
 * @param string $module  the DEMO_MODULES key being imported
 * @param string $table  the table the insert collided in
 * @param string $dbMessage  the database error text; the "Duplicate entry '...'" value is quoted from it
 */
function demoDuplicateExplanation(PDO $conn, string $module, string $table, string $dbMessage): string {
    $value = '';
    if (preg_match("/Duplicate entry '([^']*)'/", $dbMessage, $m)) {
        $value = " (\"{$m[1]}\")";
    }

    if (demoHasUntaggedImport($conn)) {
        return "This installation already has demo data that was imported before demo rows were "
             . "marked as such, so it cannot be recognised or replaced automatically. The "
             . ucfirst($module) . " import stopped because one of its records already exists in "
             . "`$table`{$value}. Remove the old sample records by hand first, or import into a "
             . "fresh installation. Nothing has been changed.";
    }

    return "The " . ucfirst($module) . " demo data could not be added: a record in `$table`{$value} "
         . "already exists and is not marked as demo data, so the importer will not touch it. "
         . "Nothing has been changed.";
}

/**
 * Does this installation hold demo data from BEFORE the rows were tagged?
 *
 * Installations that imported demo data prior to #1297 have is_demo = 0 on all
 * of it, so the importer cannot recognise it and a re-import would lay a second
 * copy alongside the first rather than replacing it. There is no way to tag it
 * retrospectively — nothing recorded which rows came from where — so the honest
 * move is to detect the case and say so.
 *
 * The probe is the demo analyst 'jsmith', which is what the demo data page used
 * as its "core has been imported" test before this existed. Present but not
 * tagged means an untagged import.
 */
function demoHasUntaggedImport(PDO $conn): bool {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM analysts WHERE username = 'jsmith' AND is_demo = 0");
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    } catch (Exception) {
        return false;
    }
}
