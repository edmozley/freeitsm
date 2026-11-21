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
    'core', 'tickets', 'assets', 'consumables', 'knowledge', 'changes', 'calendar', 'checks',
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
    if ($module === 'consumables') { demoAfterConsumables($conn); return; }
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
 * Before a module's previous demo rows are cleared, inside the import's transaction (5.8.0).
 *
 * consumables: the history demoAfterConsumables() built hangs off the demo items with
 * RESTRICT keys, so it goes first - children before parents, the undo before the
 * movement it undoes (id DESC: an undo is always newer).
 *
 * @param string $module  the DEMO_MODULES key about to be imported
 */
function demoBeforeImport(PDO $conn, string $module): void {
    if ($module !== 'consumables') return;
    try { $conn->query("SELECT is_demo FROM consumable_movements LIMIT 0"); }
    catch (Throwable) { return; }   // before Database Verification - the import says so itself
    $conn->exec("DELETE FROM ticket_audit WHERE is_demo = 1 AND field_name LIKE 'Consumable%'");
    $conn->exec("DELETE FROM notifications WHERE entity_type = 'consumable' AND entity_id IN (SELECT id FROM consumable_items WHERE is_demo = 1)");
    $conn->exec("DELETE FROM consumable_audit WHERE is_demo = 1");
    $conn->exec("DELETE FROM consumable_count_lines WHERE is_demo = 1");
    $conn->exec("DELETE FROM consumable_movements WHERE is_demo = 1 ORDER BY id DESC");
    $conn->exec("DELETE FROM consumable_counts WHERE is_demo = 1");
    $conn->exec("DELETE FROM consumable_deliveries WHERE is_demo = 1");
    $conn->exec("DELETE FROM consumable_stock WHERE is_demo = 1");
}

/**
 * consumables (5.8.0): four months of a stock room, so every screen has something to
 * show - Deliveries, the item histories, Low stock, the stock takes and the Differences
 * report. JSON can only insert rows; a history has to ADD UP (each movement's balance,
 * the stock on hand, a stock take's expected figure), so it is played through
 * ConsumablesService - exactly what a person doing it would have written, audit and all -
 * and each step is then moved back to the day it happened.
 *
 * Stock is used on the demo tickets (Tickets demo data) and handed to the demo people
 * where those are in place; without them it is simply used, as stock on a shelf is.
 * Everything made here is stamped is_demo = 1, and demoBeforeImport() clears it.
 */
function demoAfterConsumables(PDO $conn): void {
    require_once __DIR__ . '/services/consumables.php';
    require_once __DIR__ . '/services/notifications.php';
    $ctx = ActorContext::fromSession($conn);
    if ($ctx->actorId <= 0) {   // run from a script: the first administrator acts
        $ctx->actorId = (int)$conn->query("SELECT id FROM analysts WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
    }
    $item = [];
    foreach ($conn->query("SELECT id, part_number FROM consumable_items WHERE is_demo = 1")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $item[$r['part_number']] = (int)$r['id'];
    }
    if (count($item) < 15) return;
    $default = getDefaultTenantId($conn);
    $store = ConsumablesService::storeFor($conn, $default);

    // The demo supplier from the Contracts demo, if it is here.
    $supplier = $conn->query("SELECT id FROM suppliers WHERE is_demo = 1 AND trading_name = 'TechDirect' ORDER BY id LIMIT 1")->fetchColumn() ?: null;
    if ($supplier) $conn->prepare("UPDATE consumable_items SET supplier_id = ? WHERE is_demo = 1")->execute([$supplier]);

    // People to hand things to, and tickets to use things on - the default company's.
    $people = $conn->query("SELECT id FROM users WHERE is_demo = 1 ORDER BY id LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
    $tq = $conn->prepare("SELECT id, user_id, created_datetime FROM tickets
                           WHERE is_demo = 1 AND deleted_datetime IS NULL AND COALESCE(tenant_id, ?) = ? ORDER BY created_datetime");
    $tq->execute([$default, $default]);
    $tickets = $tq->fetchAll(PDO::FETCH_ASSOC);

    // [part number => [reorder point, minimum level]]
    $levels = ['CF226A' => [4, 2], 'W2211A' => [2, 1], 'W2213A' => [2, 1], 'W2212A' => [1, 1], 'TN2420' => [2, 1],
               '910-002238' => [5, 3], '920-002479' => [4, 2], '23089-999-979' => [2, 1], '492-BBUS' => [1, 1],
               'CAT6-2M-BL' => [15, 10], 'HDMI-2M' => [5, 3], 'USBC-HDMI-4K' => [3, 2], 'AA-LR6-4PK' => [4, 2],
               'CR2032' => [5, 3], 'DTX/32GB' => [8, 5]];
    $ins = $conn->prepare("INSERT INTO consumable_stock (item_id, store_id, on_hand, reorder_point, min_level) VALUES (?, ?, 0, ?, ?)
                           ON DUPLICATE KEY UPDATE reorder_point = VALUES(reorder_point), min_level = VALUES(min_level)");
    foreach ($levels as $pn => [$rp, $min]) $ins->execute([$item[$pn], $store, $rp, $min]);

    // Where each step's rows start, so they can be dated and stamped afterwards.
    $tables = ['consumable_movements' => ['created_datetime'], 'consumable_audit' => ['created_datetime'],
               'consumable_deliveries' => ['created_datetime'], 'consumable_counts' => ['started_datetime', 'finished_datetime'],
               'consumable_count_lines' => ['counted_datetime'], 'ticket_audit' => ['created_datetime']];
    $max = fn(string $t) => (int)$conn->query("SELECT COALESCE(MAX(id), 0) FROM `$t`")->fetchColumn();
    $first = $mark = [];
    foreach ($tables as $t => $_) $first[$t] = $mark[$t] = $max($t);
    $seq = 0;
    $dated = function (int $daysAgo) use ($conn, $tables, &$mark, $max, &$seq) {
        $when = gmdate('Y-m-d H:i:s', strtotime(gmdate('Y-m-d', time() - $daysAgo * 86400) . ' 09:00:00 UTC') + 60 * (13 * $seq++ % 420));
        foreach ($tables as $t => $cols) {
            $set = implode(', ', array_map(fn($c) => "`$c` = IF(`$c` IS NULL, NULL, '$when')", $cols));
            $conn->exec("UPDATE `$t` SET $set WHERE id > " . (int)$mark[$t]);
            $mark[$t] = $max($t);
        }
    };
    $day = fn(int $daysAgo) => gmdate('Y-m-d', time() - $daysAgo * 86400);

    // Deliveries: [days ago, order number, invoice, supplier?, note, [part => [qty, unit cost]]]
    $deliveries = [
        [120, 'PO-1042', 'INV-55810', true, null, ['CF226A' => [10, 62.50], 'W2211A' => [4, 58.00], 'W2213A' => [4, 58.00], 'W2212A' => [4, 58.00],
            'TN2420' => [6, 45.90], '910-002238' => [20, 12.99], '920-002479' => [15, 9.49], 'CAT6-2M-BL' => [50, 1.85], 'HDMI-2M' => [20, 4.20], 'AA-LR6-4PK' => [12, 3.60]]],
        [95, null, 'Card receipt 0412', false, 'Bought over the counter on the company card - no purchase order.',
            ['23089-999-979' => [6, 64.00], '492-BBUS' => [5, 39.00], 'USBC-HDMI-4K' => [8, 14.50], 'CR2032' => [20, 0.80], 'DTX/32GB' => [25, 5.75]]],
        [60, 'PO-1107', 'INV-56977', true, 'Black toner has gone up.', ['CF226A' => [8, 64.00], '910-002238' => [10, 12.99], 'CAT6-2M-BL' => [30, 1.85], '920-002479' => [5, 9.49]]],
        [4, 'PO-1163', 'INV-58120', true, null, ['W2211A' => [2, 58.00], 'W2213A' => [2, 58.00], 'AA-LR6-4PK' => [10, 3.60]]],
    ];
    // Use: [days ago, part, quantity]
    $uses = [
        [118, '910-002238', 2], [116, '920-002479', 1], [115, 'TN2420', 1], [112, 'CAT6-2M-BL', 4], [110, 'CF226A', 1], [109, 'AA-LR6-4PK', 2],
        [108, '910-002238', 1], [106, 'HDMI-2M', 1], [105, 'W2211A', 1], [103, '920-002479', 1], [101, 'CAT6-2M-BL', 3], [100, 'CF226A', 1],
        [99, 'AA-LR6-4PK', 2], [98, 'TN2420', 1], [97, '910-002238', 1], [96, 'HDMI-2M', 2], [94, '920-002479', 1], [93, 'CAT6-2M-BL', 5],
        [92, '910-002238', 1], [91, '23089-999-979', 1], [89, '492-BBUS', 1], [88, 'CF226A', 1], [87, 'USBC-HDMI-4K', 1], [86, 'CR2032', 2],
        [85, '910-002238', 1], [85, 'W2213A', 1], [84, '23089-999-979', 1], [83, 'DTX/32GB', 2], [82, 'CAT6-2M-BL', 6], [81, 'TN2420', 1],
        [80, 'W2211A', 1], [79, '920-002479', 1], [77, '910-002238', 1], [76, 'HDMI-2M', 1], [75, 'CF226A', 1], [74, 'AA-LR6-4PK', 2],
        [73, '23089-999-979', 1], [72, 'DTX/32GB', 3], [71, '920-002479', 1], [70, 'CF226A', 1], [70, 'W2212A', 1], [69, '910-002238', 2],
        [68, 'CR2032', 3], [67, 'CAT6-2M-BL', 4], [66, 'CF226A', 1], [65, '920-002479', 1], [64, 'TN2420', 1], [63, '910-002238', 1],
        [62, 'USBC-HDMI-4K', 1], [61, 'HDMI-2M', 2], [59, 'DTX/32GB', 2], [58, '492-BBUS', 1], [55, '910-002238', 1], [54, 'CAT6-2M-BL', 5],
        [53, 'AA-LR6-4PK', 2], [52, '920-002479', 1], [51, 'CR2032', 2], [50, 'W2211A', 1], [49, 'HDMI-2M', 1], [48, '910-002238', 1],
        [47, 'TN2420', 1], [46, 'DTX/32GB', 2], [45, 'CF226A', 1], [44, 'CAT6-2M-BL', 3], [43, '920-002479', 1], [41, '910-002238', 1],
        [40, 'W2213A', 1], [39, 'CR2032', 2], [38, 'USBC-HDMI-4K', 1], [37, '492-BBUS', 1], [36, 'HDMI-2M', 1], [35, 'CF226A', 1],
        [34, 'DTX/32GB', 3], [33, '910-002238', 2], [31, 'CAT6-2M-BL', 6], [30, 'W2211A', 1], [29, '920-002479', 1], [28, 'TN2420', 1],
        [27, 'AA-LR6-4PK', 1], [26, '910-002238', 1], [25, 'CR2032', 1], [23, 'HDMI-2M', 1], [22, 'W2212A', 1], [21, 'CAT6-2M-BL', 4],
        [20, 'CF226A', 1], [19, '920-002479', 1], [18, '910-002238', 1], [17, 'DTX/32GB', 1], [16, 'USBC-HDMI-4K', 1], [11, '910-002238', 1],
        [10, 'CR2032', 2], [9, '920-002479', 1], [8, 'CF226A', 1], [7, 'CAT6-2M-BL', 2], [6, '910-002238', 1], [5, 'HDMI-2M', 1],
        [3, '910-002238', 1], [2, 'CF226A', 1], [2, 'AA-LR6-4PK', 1], [1, 'W2211A', 1],
    ];
    // Stock takes: [days ago, note, [part => how many were on the shelf against what was expected]]
    $counts = [
        [90, 'Quarter-end count of the basement store', ['CAT6-2M-BL' => -3, 'AA-LR6-4PK' => -2, '910-002238' => 1]],
        [14, 'Basement store count', ['CF226A' => -2, 'HDMI-2M' => -1, 'CR2032' => -4, 'DTX/32GB' => -3]],
    ];
    // Hardware goes out against a ticket; the rest is used off the shelf.
    $againstTicket = ['910-002238', '920-002479', '23089-999-979', '492-BBUS', 'USBC-HDMI-4K'];

    // One timeline, oldest first; a day's deliveries come in before its use goes out.
    $events = [];
    foreach ($deliveries as $d) $events[] = [$d[0], 0, 'delivery', $d];
    foreach ($counts as $c) $events[] = [$c[0], 1, 'count', $c];
    foreach ($uses as $i => $u) $events[] = [$u[0], 2, 'use', $u, $i];
    $events[] = [30, 3, 'adjust', ['23089-999-979', -1, 'Dropped in the car park - the headband snapped. Thrown away.']];
    $events[] = [24, 3, 'mistake', '23089-999-979'];
    usort($events, fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

    $mayAdjust = analystHasCapability($conn, $ctx->actorId, Cap::ASSETS_CONSUMABLES_ADJUST);
    NotificationsService::duringBulk(function () use ($conn, $ctx, $events, $item, $store, $supplier, $people, $tickets, $againstTicket, $mayAdjust, $dated, $day) {
        $n = 0;
        foreach ($events as $e) {
            [$ago, , $kind, $x] = $e;
            if ($kind === 'delivery') {
                [, $order, $invoice, $fromSupplier, $note, $lines] = $x;
                ConsumablesService::receiveDelivery($conn, $ctx, [
                    'supplier_id' => $fromSupplier ? $supplier : null, 'order_ref' => $order, 'invoice_ref' => $invoice,
                    'invoice_date' => $day($ago + 2), 'received_date' => $day($ago), 'note' => $note,
                    'lines' => array_map(fn($pn, $l) => ['item_id' => $item[$pn], 'quantity' => $l[0], 'unit_cost' => $l[1]], array_keys($lines), $lines),
                ]);
            } elseif ($kind === 'use') {
                [, $pn, $qty] = $x;
                $in = ['quantity' => $qty];
                $when = $day($ago) . ' 23:59:59';
                $ticket = null;
                if (in_array($pn, $againstTicket, true)) {
                    foreach ($tickets as $t) if ($t['created_datetime'] <= $when) $ticket = $t;   // the latest raised by then
                }
                if ($ticket && $n % 2 === 0) { $in['ticket_id'] = (int)$ticket['id']; $in['recipient_user_id'] = $ticket['user_id'] ? (int)$ticket['user_id'] : null; }
                elseif ($people && $pn !== 'CAT6-2M-BL' && !str_starts_with($pn, 'W2') && !in_array($pn, ['CF226A', 'TN2420'], true)) {
                    $in['recipient_user_id'] = (int)$people[$n % count($people)];
                }
                $n++;
                ConsumablesService::move($conn, $ctx, 'issue', $item[$pn], $in);
            } elseif ($kind === 'adjust' && $mayAdjust) {
                ConsumablesService::move($conn, $ctx, 'adjust', $item[$x[0]], ['quantity' => $x[1], 'reason' => $x[2]]);
            } elseif ($kind === 'mistake') {
                // Given to the wrong person, put right: the undo sits beside the mistake.
                $to = $people ? (int)$people[0] : null;
                $m = ConsumablesService::move($conn, $ctx, 'issue', $item[$x], ['quantity' => 1, 'recipient_user_id' => $to]);
                ConsumablesService::reverse($conn, $ctx, (int)$m['movement_id'], 'Booked to the wrong person - it went to the Leeds office.');
                ConsumablesService::move($conn, $ctx, 'issue', $item[$x], ['quantity' => 1, 'recipient_user_id' => $people ? (int)$people[count($people) - 1] : null]);
            } elseif ($kind === 'count' && $mayAdjust) {
                // Started straight into the default company's store: startCount() takes the
                // analyst's active company, which on a multi-company install may be another.
                [, $note, $diffs] = $x;
                $conn->prepare("INSERT INTO consumable_counts (store_id, status, note, started_by_analyst_id, started_datetime) VALUES (?, 'open', ?, ?, UTC_TIMESTAMP())")
                     ->execute([$store, $note, $ctx->actorId]);
                $cid = (int)$conn->lastInsertId();
                $onHand = $conn->prepare("SELECT on_hand FROM consumable_stock WHERE item_id = ? AND store_id = ?");
                foreach ($item as $pn => $iid) {
                    $onHand->execute([$iid, $store]);
                    $have = (int)$onHand->fetchColumn();
                    ConsumablesService::countLine($conn, $ctx, $cid, $iid, max(0, $have + ($diffs[$pn] ?? 0)));
                }
                ConsumablesService::finishCount($conn, $ctx, $cid);
            }
            $dated($ago);
        }
    });

    // Stamp it all as demo data - the next import clears exactly this.
    foreach ($first as $t => $from) {
        $where = $t === 'ticket_audit' ? " AND field_name LIKE 'Consumable%'" : '';
        $conn->exec("UPDATE `$t` SET is_demo = 1 WHERE id > " . (int)$from . $where);
    }
    $conn->prepare("UPDATE consumable_stock SET is_demo = 1 WHERE store_id = ? AND item_id IN (SELECT id FROM consumable_items WHERE is_demo = 1)")->execute([$store]);
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

    // GH #366: the clash can be inside the module's OWN data - two of its rows on one
    // unique key. This runs before the rollback and the import cleared the table's
    // demo rows first, so a demo row holding the clashing value was written by this
    // run. MySQL names the key and joins the clashing values with '-', so look for
    // one. Without this the message blamed a record "not marked as demo data" that
    // the person never had.
    if (demoDuplicateIsOwnData($conn, $table, $dbMessage)) {
        return "The " . ucfirst($module) . " demo data could not be added: two of its own records clash in "
             . "`$table`{$value}. That is a fault in FreeITSM's demo data, not in your data - please report it. "
             . "Nothing has been changed.";
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
 * Did a demo import's duplicate key clash with a row the same import wrote (GH #366)?
 * Reads the key name and value from MySQL's message ("Duplicate entry 'a-b' for key
 * 'table.key'"), the key's columns from the generated index list, and looks for a
 * row with is_demo = 1 whose columns, joined with '-', give that value. Any doubt
 * (no key name, an unknown key, an error) answers no, and the older wording stands.
 */
function demoDuplicateIsOwnData(PDO $conn, string $table, string $dbMessage): bool {
    if (!preg_match("/Duplicate entry '((?:[^']|'')*)' for key '(?:[^'.]+\\.)?([^']+)'/", $dbMessage, $m)) return false;
    [$value, $keyName] = [str_replace("''", "'", $m[1]), $m[2]];
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $cols = null;
    foreach (require __DIR__ . '/db_verify_indexes.php' as $ix) {
        if ($ix[0] === $table && $ix[1] === $keyName) {
            $cols = array_map(fn($c) => trim($c, '` '), explode(',', trim($ix[3], '()')));
            break;
        }
    }
    if (!$cols || array_filter($cols, fn($c) => !preg_match('/^[A-Za-z0-9_]+$/', $c))) return false;
    try {
        $joined = 'CONCAT_WS(\'-\', ' . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ')';
        $st = $conn->prepare("SELECT 1 FROM `$table` WHERE is_demo = 1 AND $joined = ? LIMIT 1");
        $st->execute([$value]);
        return (bool)$st->fetchColumn();
    } catch (Throwable) {
        return false;
    }
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
