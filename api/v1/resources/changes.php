<?php
/**
 * FreeITSM REST API v1 — changes resource (Change Management).
 *
 * Mirrors the module's internal endpoints so a change touched via the API is
 * indistinguishable from one touched in the UI:
 *   - create/update mirror api/change-management/save.php: per-field audit
 *     with the SAME human field labels ('Title', 'Status', 'Work Start', …),
 *     display NAMES for lookups, '(empty)' placeholders, 200-char truncation,
 *     action_type 'status_change' for status else 'field_change', longtext
 *     bodies deliberately NOT audited, and the change.approved workflow event
 *     on a genuine transition into Approved.
 *   - risk is computed server-side exactly like the UI: score = likelihood ×
 *     impact (1-5 each), banded Low ≤4 / Medium ≤9 / High ≤15 / Very High ≤20
 *     / Critical.
 *   - CAB voting mirrors submit_cab_vote.php: the key's acts-as analyst must
 *     be an un-voted member; any required Reject sends the change back to
 *     Draft; the all/majority threshold on required members auto-approves
 *     (with approval_datetime + audit + workflow dispatch).
 *   - comments are append + delete (no edit), always internal — like the UI.
 *   - DELETE removes the change, its attachment files, and cascades children.
 *
 * Changes are NOT company-scoped (no tenant_id — matches the UI), so a key's
 * company scope does not restrict these routes. CHG-#### references are
 * derived from the id, exactly as the UI renders them.
 *
 * The WRITES (create / update / delete / comments / CAB roster + vote) are
 * delegated to ChangesService — the same rules the UI's
 * api/change-management/*.php endpoints now call. The reads, serialisers, list
 * filters, CAB roster read, and reference lookups below are API-only and stay
 * here. (ChangesService requires the workflow engine for change.approved.)
 */

require_once dirname(__DIR__, 3) . '/includes/service_context.php';
require_once dirname(__DIR__, 3) . '/includes/services/changes.php';

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

function apiChangeNumber(int $id): string {
    return 'CHG-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

function apiChangeSelect(): string {
    return "SELECT c.*,
                   ct.name AS type_name,
                   cs.name AS status_name, cs.is_closed AS status_is_closed,
                   cp.name AS priority_name,
                   ci.name AS impact_name,
                   cc.name AS category_name,
                   rq.full_name AS requester_name,
                   asg.full_name AS assigned_to_name,
                   ap.full_name AS approver_name,
                   cb.full_name AS created_by_name
            FROM changes c
            LEFT JOIN change_types      ct  ON ct.id = c.change_type_id
            LEFT JOIN change_statuses   cs  ON cs.id = c.status_id
            LEFT JOIN change_priorities cp  ON cp.id = c.priority_id
            LEFT JOIN change_impacts    ci  ON ci.id = c.impact_id
            LEFT JOIN change_categories cc  ON cc.id = c.category_id
            LEFT JOIN analysts          rq  ON rq.id = c.requester_id
            LEFT JOIN analysts          asg ON asg.id = c.assigned_to_id
            LEFT JOIN analysts          ap  ON ap.id = c.approver_id
            LEFT JOIN analysts          cb  ON cb.id = c.created_by_id";
}

/** Summary shape (lists). Detail adds the longtext bodies, PIR, attachments, links. */
function apiSerializeChange(array $r): array {
    $rel = function ($id, $name, array $extra = []) {
        return $id === null ? null : array_merge(['id' => (int)$id, 'name' => $name], $extra);
    };
    $category = null;
    if ($r['category_id'] !== null) {
        $category = ['id' => (int)$r['category_id'], 'name' => $r['category_name']];
    } elseif ($r['category'] !== null && $r['category'] !== '') {
        $category = ['id' => null, 'name' => $r['category']]; // legacy free-text category
    }
    return [
        'id'            => (int)$r['id'],
        'change_number' => apiChangeNumber((int)$r['id']),
        'title'         => $r['title'],
        'change_type'   => $rel($r['change_type_id'], $r['type_name']),
        'status'        => $rel($r['status_id'], $r['status_name'], ['is_closed' => (bool)($r['status_is_closed'] ?? false)]),
        'priority'      => $rel($r['priority_id'], $r['priority_name']),
        'impact'        => $rel($r['impact_id'], $r['impact_name']),
        'category'      => $category,
        'requester'     => $rel($r['requester_id'], $r['requester_name']),
        'assigned_to'   => $rel($r['assigned_to_id'], $r['assigned_to_name']),
        'approver'      => $rel($r['approver_id'], $r['approver_name']),
        'approval_at'   => apiIsoDate($r['approval_datetime']),
        'cab'           => [
            'required'      => (bool)$r['cab_required'],
            'approval_type' => $r['cab_approval_type'],
        ],
        'risk'          => [
            'likelihood' => $r['risk_likelihood'] !== null ? (int)$r['risk_likelihood'] : null,
            'impact'     => $r['risk_impact_score'] !== null ? (int)$r['risk_impact_score'] : null,
            'score'      => $r['risk_score'] !== null ? (int)$r['risk_score'] : null,
            'level'      => $r['risk_level'],
        ],
        'schedule'      => [
            'work_start_at'   => apiIsoDate($r['work_start_datetime']),
            'work_end_at'     => apiIsoDate($r['work_end_datetime']),
            'outage_start_at' => apiIsoDate($r['outage_start_datetime']),
            'outage_end_at'   => apiIsoDate($r['outage_end_datetime']),
        ],
        'created_by'    => $rel($r['created_by_id'], $r['created_by_name']),
        'created_at'    => apiIsoDate($r['created_datetime']),
        'modified_at'   => apiIsoDate($r['modified_datetime']),
    ];
}

function apiLoadChange(PDO $conn, int $changeId): array {
    $stmt = $conn->prepare(apiChangeSelect() . " WHERE c.id = ?");
    $stmt->execute([$changeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        apiError(404, 'not_found', 'Change not found.');
    }
    return $row;
}

// The change audit-write, risk banding, lookup resolution/defaults, 1-5 risk
// validation, and the change.approved dispatch now live in ChangesService
// (includes/services/changes.php) — the single home shared with the UI's
// api/change-management/*.php endpoints.

// ---------------------------------------------------------------------------
// GET /changes
// ---------------------------------------------------------------------------
function apiChangesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $where = ['1=1'];
    $args  = [];

    $state = strtolower(trim($_GET['state'] ?? 'all'));
    if ($state === 'open')   $where[] = "(cs.is_closed IS NULL OR cs.is_closed = 0)";
    if ($state === 'closed') $where[] = "cs.is_closed = 1";

    foreach ([
        'status_id'      => 'c.status_id',
        'change_type_id' => 'c.change_type_id',
        'priority_id'    => 'c.priority_id',
        'impact_id'      => 'c.impact_id',
        'category_id'    => 'c.category_id',
        'requester_id'   => 'c.requester_id',
        'assigned_to_id' => 'c.assigned_to_id',
        'approver_id'    => 'c.approver_id',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = (int)$_GET[$param];
        }
    }
    foreach ([
        'status'      => 'cs.name',
        'change_type' => 'ct.name',
        'priority'    => 'cp.name',
        'impact'      => 'ci.name',
        'risk_level'  => 'c.risk_level',
    ] as $param => $col) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col = ?";
            $args[]  = trim($_GET[$param]);
        }
    }
    if (isset($_GET['cab_required']) && $_GET['cab_required'] !== '') {
        $where[] = 'c.cab_required = ?';
        $args[]  = $_GET['cab_required'] === 'true' ? 1 : 0;
    }
    if (isset($_GET['q']) && trim($_GET['q']) !== '') {
        $q = trim($_GET['q']);
        // "CHG-0042" (or "chg42") finds the change by its reference.
        if (preg_match('/^chg-?0*(\d+)$/i', $q, $m)) {
            $where[] = '(c.title LIKE ? OR c.id = ?)';
            $args[]  = '%' . $q . '%';
            $args[]  = (int)$m[1];
        } else {
            $where[] = 'c.title LIKE ?';
            $args[]  = '%' . $q . '%';
        }
    }
    foreach ([
        'created_since'   => ['c.created_datetime',    '>='],
        'modified_since'  => ['c.modified_datetime',   '>='],
        'work_start_from' => ['c.work_start_datetime', '>='],
        'work_start_to'   => ['c.work_start_datetime', '<'],
    ] as $param => [$col, $op]) {
        if (isset($_GET[$param]) && $_GET[$param] !== '') {
            $where[] = "$col $op ?";
            $args[]  = apiParseDate($_GET[$param], $param);
        }
    }

    $sortable = [
        'id' => 'c.id', 'created_at' => 'c.created_datetime', 'modified_at' => 'c.modified_datetime',
        'title' => 'c.title', 'work_start_at' => 'c.work_start_datetime', 'risk_score' => 'c.risk_score',
        'priority' => 'c.priority_id', 'status' => 'c.status_id',
    ];
    $sortParam = trim($_GET['sort'] ?? '-created_at');
    $desc = strncmp($sortParam, '-', 1) === 0;
    $sortKey = ltrim($sortParam, '-');
    if (!isset($sortable[$sortKey])) {
        apiError(400, 'invalid_parameter', "Unknown sort field '{$sortKey}'. Sortable: " . implode(', ', array_keys($sortable)));
    }
    $orderSql = $sortable[$sortKey] . ($desc ? ' DESC' : ' ASC');

    [$page, $perPage, $offset] = apiPagination();
    $whereSql = implode(' AND ', $where);

    $countStmt = $conn->prepare(
        "SELECT COUNT(*) FROM changes c
         LEFT JOIN change_statuses cs ON cs.id = c.status_id
         LEFT JOIN change_types ct ON ct.id = c.change_type_id
         LEFT JOIN change_priorities cp ON cp.id = c.priority_id
         LEFT JOIN change_impacts ci ON ci.id = c.impact_id
         WHERE $whereSql"
    );
    $countStmt->execute($args);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $conn->prepare(apiChangeSelect() . " WHERE $whereSql ORDER BY $orderSql LIMIT $perPage OFFSET $offset");
    $stmt->execute($args);
    $changes = array_map('apiSerializeChange', $stmt->fetchAll(PDO::FETCH_ASSOC));

    apiRespond($changes, 200, [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => (int)ceil($total / $perPage),
    ]);
}

// ---------------------------------------------------------------------------
// GET /changes/{id} — detail incl. bodies, PIR, attachments, linked problems
// ---------------------------------------------------------------------------
function apiChangesGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $r = apiLoadChange($conn, $params[0]);
    $change = apiSerializeChange($r);

    $change['description']        = $r['description'];
    $change['reason_for_change']  = $r['reason_for_change'];
    $change['risk']['evaluation'] = $r['risk_evaluation'];
    $change['test_plan']          = $r['test_plan'];
    $change['rollback_plan']      = $r['rollback_plan'];
    $change['pir'] = [
        'review'          => $r['post_implementation_review'],
        'was_successful'  => $r['pir_was_successful'] === null ? null : (bool)$r['pir_was_successful'],
        'actual_start_at' => apiIsoDate($r['pir_actual_start']),
        'actual_end_at'   => apiIsoDate($r['pir_actual_end']),
        'lessons_learned' => $r['pir_lessons_learned'],
        'follow_up'       => $r['pir_follow_up'],
    ];

    $att = $conn->prepare(
        "SELECT id, file_name, file_size, file_type, uploaded_datetime
         FROM change_attachments WHERE change_id = ? ORDER BY uploaded_datetime ASC"
    );
    $att->execute([$params[0]]);
    $change['attachments'] = array_map(function ($a) {
        return [
            'id'          => (int)$a['id'],
            'file_name'   => $a['file_name'],
            'file_size'   => $a['file_size'] !== null ? (int)$a['file_size'] : null,
            'file_type'   => $a['file_type'],
            'uploaded_at' => apiIsoDate($a['uploaded_datetime']),
        ];
    }, $att->fetchAll(PDO::FETCH_ASSOC));

    // Problems this change fixes (linked from the problem side; read both ways).
    $change['linked_problems'] = [];
    try {
        $pr = $conn->prepare(
            "SELECT p.id, p.problem_number, p.title, cr.relation_type
             FROM change_relations cr
             JOIN problems p ON p.id = cr.related_id
             WHERE cr.change_id = ? AND cr.related_type = 'problem'
             ORDER BY p.created_datetime DESC"
        );
        $pr->execute([$params[0]]);
        $change['linked_problems'] = array_map(function ($p) {
            return [
                'id'             => (int)$p['id'],
                'problem_number' => $p['problem_number'],
                'title'          => $p['title'],
                'relation_type'  => $p['relation_type'],
            ];
        }, $pr->fetchAll(PDO::FETCH_ASSOC));
    } catch (Exception $e) { /* problems module absent */ }

    apiRespond($change);
}

// ---------------------------------------------------------------------------
// POST /changes
// ---------------------------------------------------------------------------
function apiChangesCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $changeId = ChangesService::createChange($conn, ActorContext::fromApiKey($apiKey), $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(apiSerializeChange(apiLoadChange($conn, $changeId)), 201);
}

// ---------------------------------------------------------------------------
// PATCH /changes/{id}
// ---------------------------------------------------------------------------
function apiChangesUpdate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ChangesService::updateChange($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(apiSerializeChange(apiLoadChange($conn, $params[0])));
}

// ---------------------------------------------------------------------------
// DELETE /changes/{id} — permanent; removes attachment files, cascades children
// ---------------------------------------------------------------------------
function apiChangesDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ChangesService::deleteChange($conn, ActorContext::fromApiKey($apiKey), (int)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $params[0], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// Comments — append + delete (no edit), always internal, like the UI
// ---------------------------------------------------------------------------
function apiChangeCommentsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT cm.id, cm.comment_text, cm.created_datetime, cm.analyst_id, a.full_name AS analyst_name
         FROM change_comments cm LEFT JOIN analysts a ON a.id = cm.analyst_id
         WHERE cm.change_id = ? ORDER BY cm.created_datetime DESC, cm.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($c) {
        return [
            'id'         => (int)$c['id'],
            'text'       => $c['comment_text'],
            'analyst'    => $c['analyst_id'] === null ? null : ['id' => (int)$c['analyst_id'], 'name' => $c['analyst_name']],
            'created_at' => apiIsoDate($c['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

function apiChangeCommentsCreate(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $commentId = ChangesService::createComment($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $commentId, 'change_id' => $params[0], 'text' => trim((string)($body['text'] ?? ''))], 201);
}

function apiChangeCommentsDelete(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ChangesService::deleteComment($conn, ActorContext::fromApiKey($apiKey), (int)$params[1], (int)$params[0]);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond(['id' => $params[1], 'deleted' => true]);
}

// ---------------------------------------------------------------------------
// GET /changes/{id}/audit
// ---------------------------------------------------------------------------
function apiChangeAuditList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT au.id, au.action_type, au.field_name, au.old_value, au.new_value, au.created_datetime,
                au.analyst_id, a.full_name AS analyst_name
         FROM change_audit au LEFT JOIN analysts a ON a.id = au.analyst_id
         WHERE au.change_id = ? ORDER BY au.created_datetime DESC, au.id DESC"
    );
    $stmt->execute([$params[0]]);
    apiRespond(array_map(function ($e) {
        return [
            'id'         => (int)$e['id'],
            'action'     => $e['action_type'],
            'field'      => $e['field_name'],
            'old_value'  => $e['old_value'],
            'new_value'  => $e['new_value'],
            'analyst'    => $e['analyst_id'] === null ? null : ['id' => (int)$e['analyst_id'], 'name' => $e['analyst_name']],
            'created_at' => apiIsoDate($e['created_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC)));
}

// ---------------------------------------------------------------------------
// CAB — roster + votes
// ---------------------------------------------------------------------------
function apiChangeCabGet(PDO $conn, array $apiKey, array $params, array $body): void {
    $change = apiLoadChange($conn, $params[0]);
    $stmt = $conn->prepare(
        "SELECT m.analyst_id, a.full_name, m.is_required, m.vote, m.vote_comment, m.vote_datetime
         FROM change_cab_members m LEFT JOIN analysts a ON a.id = m.analyst_id
         WHERE m.change_id = ? ORDER BY a.full_name"
    );
    $stmt->execute([$params[0]]);
    $members = array_map(function ($m) {
        return [
            'analyst_id'  => (int)$m['analyst_id'],
            'name'        => $m['full_name'],
            'is_required' => (bool)$m['is_required'],
            'vote'        => $m['vote'],
            'vote_comment' => $m['vote_comment'],
            'voted_at'    => apiIsoDate($m['vote_datetime']),
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    $required = array_values(array_filter($members, fn($m) => $m['is_required']));
    apiRespond([
        'cab_required'  => (bool)$change['cab_required'],
        'approval_type' => $change['cab_approval_type'],
        'members'       => $members,
        'progress'      => [
            'required_total'    => count($required),
            'required_approved' => count(array_filter($required, fn($m) => $m['vote'] === 'Approve')),
            'required_rejected' => count(array_filter($required, fn($m) => $m['vote'] === 'Reject')),
        ],
    ]);
}

/** POST /changes/{id}/cab — replace the roster (diff-sync + audit, like save_cab_members.php). */
function apiChangeCabSave(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        ChangesService::saveCab($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body['members'] ?? null);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiChangeCabGet($conn, $apiKey, $params, $body);
}

/** POST /changes/{id}/cab/vote — the key's acts-as analyst votes; mirrors submit_cab_vote.php. */
function apiChangeCabVote(PDO $conn, array $apiKey, array $params, array $body): void {
    try {
        $res = ChangesService::voteCab($conn, ActorContext::fromApiKey($apiKey), (int)$params[0], $body);
    } catch (ServiceError $e) { apiFailFromService($e); }
    apiRespond($res, 201);
}

// ---------------------------------------------------------------------------
// Reference lookups
// ---------------------------------------------------------------------------
function apiChangeLookupList(PDO $conn, string $table, bool $hasIsClosed = false): void {
    $cols = 'id, name, colour, is_default, is_active' . ($hasIsClosed ? ', is_closed' : '');
    $rows = $conn->query("SELECT $cols FROM `$table` ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) use ($hasIsClosed) {
        $out = [
            'id'         => (int)$r['id'],
            'name'       => $r['name'],
            'colour'     => $r['colour'],
            'is_default' => (bool)$r['is_default'],
            'is_active'  => (bool)$r['is_active'],
        ];
        if ($hasIsClosed) {
            $out['is_closed'] = (bool)$r['is_closed'];
        }
        return $out;
    }, $rows));
}

function apiChangeStatusesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_statuses', true);
}
function apiChangeTypesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_types');
}
function apiChangePrioritiesList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_priorities');
}
function apiChangeImpactsList(PDO $conn, array $apiKey, array $params, array $body): void {
    apiChangeLookupList($conn, 'change_impacts');
}
function apiChangeCategoriesList(PDO $conn, array $apiKey, array $params, array $body): void {
    $rows = $conn->query("SELECT id, name, description, is_active FROM change_categories ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC);
    apiRespond(array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'description' => $r['description'],
            'is_active'   => (bool)$r['is_active'],
        ];
    }, $rows));
}
