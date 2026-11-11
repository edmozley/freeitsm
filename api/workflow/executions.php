<?php
/**
 * API: the cross-workflow execution log.
 *
 * Until now the only view of a run was the editor's "Recent runs" panel — the
 * last 20 runs of ONE workflow, no filtering, no search, no history. Which is
 * exactly the wrong shape for the question you actually ask ("what failed, and
 * why?"), because you don't know which workflow to open yet.
 *
 * GET params (all optional):
 *   workflow_id  int      one workflow
 *   status       string   running|success|failed|skipped|aborted
 *   trigger      string   exact trigger event
 *   dry_run      0|1      exclude / show only dry runs
 *   from, to     Y-m-d    date range (inclusive), on started_datetime
 *   q            string   free text over workflow name + error message
 *   page         int      1-based
 *   per_page     int      default 25, max 100
 *
 * Returns the page of rows, a total count, and the filter facets (workflow list
 * + the trigger events that actually appear in the log) so the UI's dropdowns
 * only ever offer values that will match something.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('workflow');

$VALID_STATUS = ['running', 'success', 'failed', 'skipped', 'aborted'];

$workflowId = isset($_GET['workflow_id']) ? (int)$_GET['workflow_id'] : 0;
$status     = trim((string)($_GET['status'] ?? ''));
$trigger    = trim((string)($_GET['trigger'] ?? ''));
$dryRun     = isset($_GET['dry_run']) && $_GET['dry_run'] !== '' ? (int)$_GET['dry_run'] : null;
$from       = trim((string)($_GET['from'] ?? ''));
$to         = trim((string)($_GET['to'] ?? ''));
$q          = trim((string)($_GET['q'] ?? ''));
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = min(100, max(5, (int)($_GET['per_page'] ?? 25)));

$where  = [];
$params = [];

if ($workflowId > 0) { $where[] = 'e.workflow_id = ?';   $params[] = $workflowId; }
if (in_array($status, $VALID_STATUS, true)) { $where[] = 'e.status = ?'; $params[] = $status; }
// 'Needs a look': runs where an action left a note (WorkflowEngine::executeActionList) - the
// Watchtower Workflows card links here.
if ($status === 'attention') { $where[] = "e.step_log LIKE '%\"attention\":%'"; }
if ($trigger !== '') { $where[] = 'e.trigger_event = ?'; $params[] = $trigger; }
if ($dryRun !== null) { $where[] = 'e.is_dry_run = ?';   $params[] = $dryRun ? 1 : 0; }
// Dates are compared on the DATE part so "from = to = today" means "today", not
// "the instant midnight". started_datetime is UTC; the UI sends plain dates.
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'DATE(e.started_datetime) >= ?'; $params[] = $from; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $where[] = 'DATE(e.started_datetime) <= ?'; $params[] = $to; }
if ($q !== '') {
    // workflow_name is SNAPSHOTTED on the row, so a run stays searchable by name
    // even after its workflow is deleted (workflow_id goes NULL then).
    $where[] = '(e.workflow_name LIKE ? OR e.error_message LIKE ? OR e.trigger_event LIKE ?)';
    $like = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

try {
    $conn = connectToDatabase();

    // GH #209. A run holds its whole payload, so only the runs for companies
    // this analyst can see - in the list, the facets AND the tallies, because a
    // count or a workflow's name is data too.
    require_once __DIR__ . '/../../includes/workflow_tenancy.php';
    [$eSql, $eArgs] = workflowExecutionFilter($conn, (int)$_SESSION['analyst_id'], 'e.tenant_id');
    if ($eSql !== '') {
        $where[] = substr($eSql, strlen(' AND '));
        $params  = array_merge($params, $eArgs);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM workflow_executions e $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $conn->prepare(
        "SELECT e.id, e.workflow_id, e.workflow_name, e.trigger_event, e.status, e.is_dry_run,
                e.started_datetime, e.finished_datetime, e.error_message,
                w.name AS current_name, w.is_active
           FROM workflow_executions e
           LEFT JOIN workflows w ON w.id = e.workflow_id
           $whereSql
          ORDER BY e.started_datetime DESC, e.id DESC
          LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);

    $rows = array_map(fn($r) => [
        'id'            => (int)$r['id'],
        'workflow_id'   => $r['workflow_id'] !== null ? (int)$r['workflow_id'] : null,
        // Prefer the workflow's CURRENT name (it may have been renamed); fall back
        // to the snapshot, which is all we have once the workflow is deleted.
        'workflow'      => $r['current_name'] ?: ($r['workflow_name'] ?: '(deleted workflow)'),
        'deleted'       => $r['workflow_id'] === null || $r['current_name'] === null,
        'trigger'       => $r['trigger_event'],
        'status'        => $r['status'],
        'is_dry_run'    => (int)$r['is_dry_run'],
        'started'       => $r['started_datetime'],
        'finished'      => $r['finished_datetime'],
        'error'         => $r['error_message'],
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    // Facets — only offer filter values that exist in the log, so you can't pick
    // a combination that returns nothing for a reason you can't see.
    $facet = $conn->prepare(
        "SELECT DISTINCT e.workflow_id AS id,
                COALESCE(w.name, e.workflow_name, '(deleted workflow)') AS name
           FROM workflow_executions e
           LEFT JOIN workflows w ON w.id = e.workflow_id
          WHERE e.workflow_id IS NOT NULL$eSql
          ORDER BY name"
    );
    $facet->execute($eArgs);
    $workflows = $facet->fetchAll(PDO::FETCH_ASSOC);

    $facet = $conn->prepare(
        "SELECT e.trigger_event, COUNT(*) c FROM workflow_executions e
          WHERE 1=1$eSql
          GROUP BY e.trigger_event ORDER BY e.trigger_event"
    );
    $facet->execute($eArgs);
    $triggers = $facet->fetchAll(PDO::FETCH_ASSOC);

    // Headline counts across the WHOLE log (not the current page), so the tabs
    // can show how much is wrong without you having to filter to find out.
    $tally = [];
    $tallyStmt = $conn->prepare("SELECT e.status, COUNT(*) c FROM workflow_executions e WHERE 1=1$eSql GROUP BY e.status");
    $tallyStmt->execute($eArgs);
    foreach ($tallyStmt as $r) {
        $tally[$r['status']] = (int)$r['c'];
    }

    echo json_encode([
        'success'    => true,
        'executions' => $rows,
        'total'      => $total,
        'page'       => $page,
        'per_page'   => $perPage,
        'pages'      => (int)ceil($total / $perPage),
        'facets'     => [
            'workflows' => array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name']], $workflows),
            'triggers'  => array_map(fn($t) => ['event' => $t['trigger_event'], 'count' => (int)$t['c']], $triggers),
        ],
        'tally'      => $tally,
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
