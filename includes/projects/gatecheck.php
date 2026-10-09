<?php
/**
 * Projects - stage gate checklists (3.3.0): what must be true before a "go".
 *
 * Each stage's gate can carry items of four kinds:
 *   check     a plain tick, by anybody who may change the project
 *   document  ticked by choosing one of the project's attached documents
 *             (Documents module, parent type 'project'); open again if that
 *             document is later taken off the project
 *   signoff   a named analyst signs off - ONLY that analyst; they are told on
 *             the bell (project.signoff_requested) when the item is added
 *   change    a linked change (Changes module) that must be APPROVED - worked
 *             out from the change on every read, never stored as done
 *
 * A gate is 'standard' or 'golive' (project_stages.gate_kind). Making it go-live
 * adds the go-live starter items - UAT sign-off, backout plan, change approved,
 * support handover - each unless the gate already has one by that name (a gate
 * can need several sign-offs); every one editable or removable.
 *
 * 🔑 project_gate_checklist says what an open item does to a go or go-with-
 * conditions: block (refused, naming what is open) or warn (allowed, and what
 * was open is written into the gate's notes, so the record says so). A stop is
 * never blocked. A gate with no items behaves exactly as before 3.3.0.
 */

require_once __DIR__ . '/settings.php';

const PROJECT_GATE_ITEM_KINDS = ['check', 'document', 'signoff', 'change'];

function projectGateItemsReady(PDO $conn): bool
{
    static $ready = null;
    if ($ready === null) {
        try { $conn->query("SELECT 1 FROM project_gate_items LIMIT 0"); $ready = true; }
        catch (Throwable $e) { $ready = false; }
    }
    return $ready;
}

/** The project's attached documents as {id, title} - what a document item may point at. */
function projectGateDocuments(PDO $conn, int $projectId): array
{
    try {
        $st = $conn->prepare("SELECT d.id, d.title FROM document_links l JOIN documents d ON d.id = l.document_id
                               WHERE l.parent_type = 'project' AND l.parent_id = ? ORDER BY d.title");
        $st->execute([$projectId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => $r['title']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/** The project's linked changes with whether each is approved - what a change item may point at. */
function projectGateChanges(PDO $conn, int $projectId): array
{
    try {
        $st = $conn->prepare("SELECT c.id, c.title, c.approval_datetime FROM project_changes pc JOIN changes c ON c.id = pc.change_id WHERE pc.project_id = ? ORDER BY c.id");
        $st->execute([$projectId]);
        return array_map(fn($r) => ['id' => (int)$r['id'], 'label' => 'CHG-' . str_pad((string)$r['id'], 4, '0', STR_PAD_LEFT), 'title' => $r['title'],
            'approved' => $r['approval_datetime'] !== null], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) { return []; }
}

/**
 * Every gate item of a project, each with `done` worked out, grouped by stage id.
 * $showChanges = this analyst may open Changes (otherwise a change's title is left out).
 */
function projectGateItems(PDO $conn, int $projectId, bool $showChanges = true): array
{
    if (!projectGateItemsReady($conn)) return [];
    $st = $conn->prepare("SELECT i.*, a.full_name AS analyst_name, b.full_name AS done_by_name
                            FROM project_gate_items i
                       LEFT JOIN analysts a ON a.id = i.analyst_id
                       LEFT JOIN analysts b ON b.id = i.done_by_id
                           WHERE i.project_id = ? ORDER BY i.stage_id, i.position, i.id");
    $st->execute([$projectId]);
    $docs = array_column(projectGateDocuments($conn, $projectId), 'title', 'id');
    $changes = [];
    foreach (projectGateChanges($conn, $projectId) as $c) $changes[$c['id']] = $c;
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $kind = $r['kind'];
        $docId = $r['document_id'] !== null ? (int)$r['document_id'] : null;
        $chgId = $r['change_id'] !== null ? (int)$r['change_id'] : null;
        $done = match ($kind) {
            'document' => $docId !== null && isset($docs[$docId]),
            'change'   => $chgId !== null && !empty($changes[$chgId]['approved']),
            default    => $r['done_datetime'] !== null,
        };
        $out[(int)$r['stage_id']][] = [
            'id' => (int)$r['id'], 'kind' => $kind, 'title' => $r['title'], 'done' => $done,
            'analyst_id' => $r['analyst_id'] !== null ? (int)$r['analyst_id'] : null, 'analyst_name' => $r['analyst_name'],
            'document_id' => $docId, 'document_title' => $docId !== null ? ($docs[$docId] ?? null) : null,
            'change_id' => $chgId, 'change_label' => $chgId ? ($changes[$chgId]['label'] ?? 'CHG-' . str_pad((string)$chgId, 4, '0', STR_PAD_LEFT)) : null,
            'change_title' => $chgId && $showChanges ? ($changes[$chgId]['title'] ?? null) : null,
            'done_by_name' => $r['done_by_name'], 'done_datetime' => $r['done_datetime'], 'notes' => $r['notes'],
        ];
    }
    return $out;
}

/** The items still open at one stage's gate: [{id, kind, title}]. */
function projectGateOpenItems(PDO $conn, int $projectId, int $stageId): array
{
    $items = projectGateItems($conn, $projectId)[$stageId] ?? [];
    return array_values(array_map(fn($i) => ['id' => $i['id'], 'kind' => $i['kind'], 'title' => $i['title']], array_filter($items, fn($i) => !$i['done'])));
}

/** The go-live starter checklist: [kind, title]. The sign-off goes to the project manager. */
function projectGoLiveStarter(): array
{
    $t = fn(string $k, string $en) => function_exists('t') && ($s = t('projects.gatecheck.' . $k)) !== 'projects.gatecheck.' . $k ? $s : $en;
    return [
        ['signoff',  $t('seed_uat', 'User acceptance testing signed off')],
        ['document', $t('seed_backout', 'Backout plan')],
        ['change',   $t('seed_change', 'Change approved')],
        ['check',    $t('seed_support', 'Support handover agreed')],
    ];
}
