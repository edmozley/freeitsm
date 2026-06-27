<?php
/**
 * API: create or update a problem. Stamps tenant_id from the active company on
 * create, generates PRB-##### , and writes problem_audit for create + each change.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) { echo json_encode(['success' => false, 'error' => 'Not authenticated']); exit; }

/** Resolve an FK id to a display name for the audit log. */
function pmName(PDO $conn, string $table, $id): string {
    if (!$id) return '';
    $col = $table === 'analysts' ? 'full_name' : 'name';
    $s = $conn->prepare("SELECT $col FROM $table WHERE id = ?");
    $s->execute([(int) $id]);
    return (string) ($s->fetchColumn() ?: '');
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) throw new Exception('Invalid request data');
    $conn = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $id          = (int) ($data['id'] ?? 0);
    $title       = trim((string) ($data['title'] ?? ''));
    $description = (string) ($data['description'] ?? '');
    $statusId    = $data['status_id'] !== '' ? ($data['status_id'] ?? null) : null;
    $priorityId  = $data['priority_id'] !== '' ? ($data['priority_id'] ?? null) : null;
    $assignee    = $data['assigned_analyst_id'] !== '' ? ($data['assigned_analyst_id'] ?? null) : null;
    $rootCause   = (string) ($data['root_cause'] ?? '');
    $workaround  = (string) ($data['workaround'] ?? '');
    $knownError  = !empty($data['is_known_error']) ? 1 : 0;

    if ($title === '') throw new Exception('Title is required');

    // Is the chosen status a closing one? (sets/clears closed_datetime)
    $isClosed = false;
    if ($statusId) {
        $cs = $conn->prepare("SELECT is_closed FROM problem_statuses WHERE id = ?");
        $cs->execute([(int) $statusId]);
        $isClosed = (bool) $cs->fetchColumn();
    }

    if ($id > 0) {
        // ---- Update ----
        if (!analystCanAccessProblem($conn, $analystId, $id)) throw new Exception('Problem not found');
        $existing = $conn->prepare("SELECT * FROM problems WHERE id = ?");
        $existing->execute([$id]);
        $old = $existing->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception('Problem not found');

        $closedSql = $isClosed ? (empty($old['closed_datetime']) ? ', closed_datetime = UTC_TIMESTAMP()' : '') : ', closed_datetime = NULL';
        $conn->prepare("UPDATE problems SET title=?, description=?, status_id=?, priority_id=?, assigned_analyst_id=?, root_cause=?, workaround=?, is_known_error=?, updated_datetime=UTC_TIMESTAMP() $closedSql WHERE id=?")
             ->execute([$title, $description, $statusId, $priorityId, $assignee, $rootCause, $workaround, $knownError, $id]);

        // Audit each changed field (resolve FK ids to names).
        $audit = [
            'title'       => [$old['title'], $title],
            'description' => [$old['description'], $description],
            'status'      => [pmName($conn, 'problem_statuses', $old['status_id']), pmName($conn, 'problem_statuses', $statusId)],
            'priority'    => [pmName($conn, 'problem_priorities', $old['priority_id']), pmName($conn, 'problem_priorities', $priorityId)],
            'assigned_to' => [pmName($conn, 'analysts', $old['assigned_analyst_id']), pmName($conn, 'analysts', $assignee)],
            'root_cause'  => [$old['root_cause'], $rootCause],
            'workaround'  => [$old['workaround'], $workaround],
            'known_error' => [$old['is_known_error'] ? 'Yes' : 'No', $knownError ? 'Yes' : 'No'],
        ];
        $aStmt = $conn->prepare("INSERT INTO problem_audit (problem_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime) VALUES (?, ?, 'modified', ?, ?, ?, UTC_TIMESTAMP())");
        foreach ($audit as $field => [$o, $n]) {
            if ((string) $o !== (string) $n) {
                $aStmt->execute([$id, $analystId, $field, mb_substr((string) $o, 0, 1000), mb_substr((string) $n, 0, 1000)]);
            }
        }
        echo json_encode(['success' => true, 'id' => $id, 'message' => 'Problem saved']);
    } else {
        // ---- Create ----
        $tenantId = getActiveTenantId($conn, $analystId);
        if (!$statusId) {
            $statusId = $conn->query("SELECT id FROM problem_statuses WHERE is_default = 1 ORDER BY display_order LIMIT 1")->fetchColumn() ?: null;
        }
        $conn->prepare("INSERT INTO problems (tenant_id, title, description, status_id, priority_id, assigned_analyst_id, root_cause, workaround, is_known_error, created_by_id, created_datetime, updated_datetime) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())")
             ->execute([$tenantId, $title, $description, $statusId, $priorityId, $assignee, $rootCause, $workaround, $knownError, $analystId]);
        $newId = (int) $conn->lastInsertId();
        $conn->prepare("UPDATE problems SET problem_number = ? WHERE id = ?")->execute(['PRB-' . str_pad((string) $newId, 5, '0', STR_PAD_LEFT), $newId]);
        $conn->prepare("INSERT INTO problem_audit (problem_id, analyst_id, action_type, created_datetime) VALUES (?, ?, 'created', UTC_TIMESTAMP())")->execute([$newId, $analystId]);
        echo json_encode(['success' => true, 'id' => $newId, 'message' => 'Problem created']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
