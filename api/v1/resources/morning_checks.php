<?php
/**
 * FreeITSM REST API v1 — morning-checks resource (checks, results, day board).
 *
 * Mirrors the module's internal endpoints:
 *   - checks CRUD mirrors add/update/delete_check.php (delete removes the
 *     check's results first — here inside a transaction);
 *   - GET /morning-checks/board is get_todays_checks.php: every ACTIVE check
 *     with its result for one date, orphan rows (status deleted since the
 *     result was saved) surfaced with is_orphan + the label snapshot;
 *   - POST /morning-checks/results is save_check_result.php's upsert — one
 *     result per check per date (the uq_check_date unique key), status must
 *     be active, RequiresNotes enforced. 201 on first record, 200 on
 *     overwrite.
 *
 * Deliberate differences from the UI, documented:
 *   - a malformed date is a 422 (the internal endpoints silently substitute
 *     today — a machine recording yesterday's backfill deserves the error);
 *   - an unknown check id is a 422 (the UI would hit the FK and 500);
 *   - results recorded via the API stamp CreatedBy with the key's acting
 *     analyst (the UI leaves the column NULL).
 *
 * Dates: CheckDate is a DATETIME holding a plain date (midnight, no TZ
 * semantics) — the module is a daily operational ritual, so this resource
 * accepts/returns bare YYYY-MM-DD values; "today" defaults use server-local
 * date exactly like the dashboard. CreatedDate/ModifiedDate are UTC as usual.
 * Install-wide (no company scoping — matches the UI); no audit trail exists.
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/morning_checks.php';

// ---------------------------------------------------------------------------
// Serializers + loaders
// ---------------------------------------------------------------------------

function apiSerializeMorningCheck(array $r): array {
    return [
        'id'          => (int)$r['CheckID'],
        'name'        => $r['CheckName'],
        'description' => $r['CheckDescription'],
        'is_active'   => (bool)$r['IsActive'],
        'sort_order'  => (int)$r['SortOrder'],
        'created_at'  => apiIsoDate($r['CreatedDate']),
        'modified_at' => apiIsoDate($r['ModifiedDate']),
    ];
}

function apiLoadMorningCheck(PDO $conn, int $checkId): array {
    $stmt = $conn->prepare("SELECT * FROM morningChecks_Checks WHERE CheckID = ?");
    $stmt->execute([$checkId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Check not found.');
    }
    return $row;
}

/** Result rows come from this SELECT so the status join is always present. */
function apiMorningResultSelect(): string {
    return "SELECT r.*, c.CheckName,
                   s.Label AS StatusLabel, s.Colour AS StatusColour
            FROM morningChecks_Results r
            JOIN morningChecks_Checks c ON c.CheckID = r.CheckID
            LEFT JOIN morningChecks_Statuses s ON s.StatusID = r.StatusID";
}

function apiSerializeMorningResult(array $r): array {
    $statusId = $r['StatusID'] !== null ? (int)$r['StatusID'] : null;
    // Orphan = the status this result was saved against has since been
    // deleted; the label snapshot in Status is all that remains.
    $isOrphan = ($statusId === null && $r['Status'] !== null && $r['Status'] !== '');
    return [
        'id'     => (int)$r['ResultID'],
        'check'  => ['id' => (int)$r['CheckID'], 'name' => $r['CheckName']],
        'date'   => substr($r['CheckDate'], 0, 10),
        'status' => $statusId === null ? null : [
            'id'     => $statusId,
            'label'  => $r['StatusLabel'],
            'colour' => $r['StatusColour'],
        ],
        'is_orphan'    => $isOrphan,
        'orphan_label' => $isOrphan ? $r['Status'] : null,
        'notes'        => $r['Notes'],
        'created_by'   => $r['CreatedBy'],
        'created_at'   => apiIsoDate($r['CreatedDate']),
        'modified_at'  => apiIsoDate($r['ModifiedDate']),
    ];
}

function apiLoadMorningResult(PDO $conn, int $resultId): array {
    $stmt = $conn->prepare(apiMorningResultSelect() . " WHERE r.ResultID = ?");
    $stmt->execute([$resultId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Result not found.');
    }
    return $row;
}

/** Strict YYYY-MM-DD — 422 on garbage (the UI silently substitutes today). */
function apiMorningCheckDate(string $value, string $field): string {
    $v = trim($value);
    $dt = DateTime::createFromFormat('Y-m-d', $v);
    if (!$dt || $dt->format('Y-m-d') !== $v) {
        apiError(422, 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
    }
    return $v;
}

// ---------------------------------------------------------------------------
// Checks
// ---------------------------------------------------------------------------

// GET /morning-checks/checks — all checks (settings view); ?is_active, ?q
function apiMorningChecksList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['is_active']) && $_GET['is_active'] !== '') {
        $where[] = 'IsActive = ?';
        $args[]  = $_GET['is_active'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $where[] = 'CheckName LIKE ?';
        $args[]  = '%' . trim($_GET['q']) . '%';
    }
    $stmt = $conn->prepare(
        "SELECT * FROM morningChecks_Checks WHERE " . implode(' AND ', $where) .
        " ORDER BY SortOrder, CheckName"
    );
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeMorningCheck', $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiMorningChecksGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeMorningCheck(apiLoadMorningCheck($conn, $params[0])));
}

// POST /morning-checks/checks
function apiMorningChecksCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = MorningChecksService::saveCheck($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeMorningCheck(apiLoadMorningCheck($conn, $id)), 201);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// PATCH /morning-checks/checks/{id}
function apiMorningChecksUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $id = MorningChecksService::saveCheck($conn, ActorContext::fromApiKey($apiKey), array_merge($body, ['id' => (int)$params[0]]));
        apiRespond(apiSerializeMorningCheck(apiLoadMorningCheck($conn, $id)));
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// DELETE /morning-checks/checks/{id}
function apiMorningChecksDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        MorningChecksService::deleteCheck($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
        apiRespond(['id' => $params[0], 'deleted' => true]);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// The day board
// ---------------------------------------------------------------------------

// GET /morning-checks/board?date=YYYY-MM-DD — get_todays_checks.php's view:
// every active check with its result for the date (or null if not yet done).
function apiMorningChecksBoard(PDO $conn, array $apiKey, array $params, array $body): void {
    $date = isset($_GET['date']) && trim($_GET['date']) !== ''
        ? apiMorningCheckDate($_GET['date'], 'date')
        : date('Y-m-d');

    $stmt = $conn->prepare(
        "SELECT c.CheckID, c.CheckName, c.CheckDescription, c.SortOrder,
                r.ResultID, r.StatusID, r.Status AS OrphanLabel, r.Notes,
                s.Label AS StatusLabel, s.Colour AS StatusColour
         FROM morningChecks_Checks c
         LEFT JOIN morningChecks_Results r ON r.CheckID = c.CheckID AND r.CheckDate = ?
         LEFT JOIN morningChecks_Statuses s ON s.StatusID = r.StatusID
         WHERE c.IsActive = 1
         ORDER BY c.SortOrder, c.CheckName"
    );
    $stmt->execute([$date]);

    $checks = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $result = null;
        if ($r['ResultID'] !== null) {
            $statusId = $r['StatusID'] !== null ? (int)$r['StatusID'] : null;
            $isOrphan = ($statusId === null && $r['OrphanLabel'] !== null && $r['OrphanLabel'] !== '');
            $result = [
                'id'     => (int)$r['ResultID'],
                'status' => $statusId === null ? null : [
                    'id'     => $statusId,
                    'label'  => $r['StatusLabel'],
                    'colour' => $r['StatusColour'],
                ],
                'is_orphan'    => $isOrphan,
                'orphan_label' => $isOrphan ? $r['OrphanLabel'] : null,
                'notes'        => $r['Notes'],
            ];
        }
        $checks[] = [
            'id'          => (int)$r['CheckID'],
            'name'        => $r['CheckName'],
            'description' => $r['CheckDescription'],
            'sort_order'  => (int)$r['SortOrder'],
            'result'      => $result,
        ];
    }
    apiRespond(['date' => $date, 'checks' => $checks]);
}

// ---------------------------------------------------------------------------
// Results
// ---------------------------------------------------------------------------

// GET /morning-checks/results — history; newest first
function apiMorningCheckResultsList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];
    if (isset($_GET['check_id']) && $_GET['check_id'] !== '') {
        $where[] = 'r.CheckID = ?';
        $args[]  = (int)$_GET['check_id'];
    }
    if (isset($_GET['status_id']) && $_GET['status_id'] !== '') {
        $where[] = 'r.StatusID = ?';
        $args[]  = (int)$_GET['status_id'];
    }
    if (isset($_GET['date']) && trim($_GET['date']) !== '') {
        $where[] = 'r.CheckDate = ?';
        $args[]  = apiMorningCheckDate($_GET['date'], 'date');
    }
    if (isset($_GET['from']) && trim($_GET['from']) !== '') {
        $where[] = 'r.CheckDate >= ?';
        $args[]  = apiMorningCheckDate($_GET['from'], 'from');
    }
    if (isset($_GET['to']) && trim($_GET['to']) !== '') {
        $where[] = 'r.CheckDate <= ?';
        $args[]  = apiMorningCheckDate($_GET['to'], 'to');
    }
    // Rows whose status was deleted after the result was recorded — the
    // dashboard's warning-banner set, for remapping via Settings.
    if (isset($_GET['orphans']) && $_GET['orphans'] === 'true') {
        $where[] = "r.StatusID IS NULL AND r.Status IS NOT NULL AND r.Status <> ''";
    }

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare("SELECT COUNT(*) FROM morningChecks_Results r WHERE $whereSql");
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(
        apiMorningResultSelect() . " WHERE $whereSql
         ORDER BY r.CheckDate DESC, c.SortOrder, c.CheckName
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($args);
    apiRespond(array_map('apiSerializeMorningResult', $stmt->fetchAll(PDO::FETCH_ASSOC)), 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

function apiMorningCheckResultsGet(PDO $conn, array $apiKey, array $params, array $body): void {
    apiRespond(apiSerializeMorningResult(apiLoadMorningResult($conn, $params[0])));
}

// POST /morning-checks/results — save_check_result.php's upsert: one result
// per check per date. 201 on first record of the day, 200 on overwrite.
function apiMorningCheckResultsRecord(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = MorningChecksService::recordResult($conn, ActorContext::fromApiKey($apiKey), $body);
        apiRespond(apiSerializeMorningResult(apiLoadMorningResult($conn, $res['id'])), $res['created'] ? 201 : 200);
    } catch (ServiceError $e) { apiFailFromService($e); }
}

// ---------------------------------------------------------------------------
// Reference lookup
// ---------------------------------------------------------------------------

// GET /morning-check-statuses — full list like get_statuses.php (inactive
// included so historical results can always be resolved).
function apiMorningCheckStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query(
        "SELECT StatusID, Label, Colour, RequiresNotes, SortOrder, IsActive
         FROM morningChecks_Statuses ORDER BY SortOrder, StatusID"
    )->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($s) {
        return [
            'id'             => (int)$s['StatusID'],
            'label'          => $s['Label'],
            'colour'         => $s['Colour'],
            'requires_notes' => (bool)$s['RequiresNotes'],
            'sort_order'     => (int)$s['SortOrder'],
            'is_active'      => (bool)$s['IsActive'],
        ];
    }, $rows));
}
