<?php
/**
 * FreeITSM REST API v1 - what hangs off a task (GitHub #328, the task half).
 *
 *   apiTaskRelated($conn, $taskId, $apiKey)   -> ['counts' => [...], 'links' => [...]]
 *
 * The twin of api/v1/resources/ticket_related.php, the same shape, so a client
 * reads a ticket and a task the same way: `counts` says which of the task's
 * sub-endpoints are worth calling, `links` what it is linked to. On GET /tasks/{id},
 * on GET /tasks with ?include=related (opt-in, as for tickets: a page of 100 would
 * otherwise run 100 sets of these queries), and on the task's Full record webhook.
 *
 * WHO SEES WHAT - the same rule as tickets. With an API key, a count is there only
 * if the key has `read` on that sub-endpoint's permission, and a link only if the
 * key may read that kind of record and the record is in one of the key's companies
 * (a ticket also through the key's analyst's departments). A webhook ($apiKey null)
 * gets everything: whoever set up the workflow chose to send the record. A linked
 * ticket carries its number, never its subject, as on tickets.
 *
 * @wiki REST-API-Ticket-Related-Developer-Guide
 */

/**
 * The counts and links for one task.
 *
 * @param int $taskId  a task the caller has already been allowed to read
 * @param ?array $apiKey  the authenticated key (permissions, company_scope, analyst_id), or null for a webhook
 * @return array{counts: array<string,int>|stdClass, links: list<array>}
 */
function apiTaskRelated(PDO $conn, int $taskId, ?array $apiKey): array
{
    $can = function (string $perm) use ($apiKey): bool {
        if ($apiKey === null) return true;
        return in_array('read', $apiKey['permissions'][$perm] ?? [], true);
    };
    $scalar = function (string $sql) use ($conn, $taskId): int {
        try {
            $st = $conn->prepare($sql);
            $st->execute(array_fill(0, substr_count($sql, '?'), $taskId));
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;   // a table not yet created by Database Verify counts as none
        }
    };

    // ── counts: one per task sub-endpoint, under that endpoint's own permission ──
    $counts = [];
    if ($can('task_comments'))   $counts['comments']   = $scalar("SELECT COUNT(*) FROM task_comments WHERE task_id = ?");
    if ($can('task_forms')) {
        try {
            require_once dirname(__DIR__, 3) . '/includes/services/task_forms.php';
            $counts['forms'] = TaskForms::columnsReady($conn) ? count(TaskForms::forTask($conn, $taskId, 0, false)) : 0;
        } catch (Throwable $e) { $counts['forms'] = 0; }
    }
    if ($can('task_checklists')) $counts['checklists'] = $scalar("SELECT COUNT(*) FROM task_checklists WHERE task_id = ?");

    // ── links: what the task is linked to, each under its record's permission ──
    $links = [];
    $inScope = function (string $table, int $id) use ($conn, $apiKey): bool {
        return $apiKey === null || apiKeyCanAccessTenantRow($conn, $apiKey, $table, $id);
    };
    $row = null;
    try {
        $st = $conn->prepare("SELECT ticket_id, change_id, contract_id, project_id, parent_task_id FROM tasks WHERE id = ?");
        $st->execute([$taskId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { /* project_id before Database Verification: try without it */
        $st = $conn->prepare("SELECT ticket_id, change_id, contract_id, NULL AS project_id, parent_task_id FROM tasks WHERE id = ?");
        $st->execute([$taskId]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($row) {
        // The ticket it is for - number only, as on tickets.
        if ($row['ticket_id'] && $can('tickets') && ($apiKey === null || apiKeyCanAccessTicket($conn, $apiKey, (int)$row['ticket_id']))) {
            $t = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
            $t->execute([(int)$row['ticket_id']]);
            $num = $t->fetchColumn();
            if ($num !== false) $links[] = ['type' => 'ticket', 'id' => (int)$row['ticket_id'], 'reference' => $num];
        }
        // [type, permission, table, column on the task, SQL for its name]
        foreach ([
            ['change',   'changes',   'changes',   'change_id',   "SELECT title FROM changes WHERE id = ?"],
            ['contract', 'contracts', 'contracts', 'contract_id', "SELECT title FROM contracts WHERE id = ?"],
            ['project',  'projects',  'projects',  'project_id',  "SELECT name FROM projects WHERE id = ?"],
        ] as [$type, $perm, $table, $col, $sql]) {
            $id = (int)($row[$col] ?? 0);
            if ($id < 1 || !$can($perm)) continue;
            try {
                if (!$inScope($table, $id)) continue;
                $n = $conn->prepare($sql);
                $n->execute([$id]);
                $name = $n->fetchColumn();
                if ($name !== false) $links[] = ['type' => $type, 'id' => $id, 'name' => $name];
            } catch (Throwable $e) { /* that module's tables not created yet */ }
        }
    }

    // Other tasks, from THIS task's point of view: its parent, its subtasks, what it
    // waits for and what waits for it (task_dependencies).
    if ($can('tasks')) {
        $taskLinks = [];
        if ($row && $row['parent_task_id']) $taskLinks[] = [(int)$row['parent_task_id'], 'child_of'];
        foreach ([
            ["SELECT id FROM tasks WHERE parent_task_id = ? ORDER BY board_position, id", 'parent_of'],
            ["SELECT depends_on_id FROM task_dependencies WHERE task_id = ? ORDER BY id", 'waits_on'],
            ["SELECT task_id FROM task_dependencies WHERE depends_on_id = ? ORDER BY id", 'blocks'],
        ] as [$sql, $rel]) {
            try {
                $st = $conn->prepare($sql);
                $st->execute([$taskId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $taskLinks[] = [(int)$id, $rel];
            } catch (Throwable $e) { /* task_dependencies not created yet */ }
        }
        $title = $conn->prepare("SELECT title FROM tasks WHERE id = ?");
        foreach ($taskLinks as [$id, $rel]) {
            if (!$inScope('tasks', $id)) continue;
            $title->execute([$id]);
            $name = $title->fetchColumn();
            if ($name === false) continue;
            $links[] = ['type' => 'task', 'id' => $id, 'name' => $name, 'relation' => $rel];
        }
    }

    // (object): an empty counts must still be {} in JSON, not [].
    return ['counts' => $counts ?: new stdClass(), 'links' => $links];
}
