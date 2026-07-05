<?php
/**
 * ProblemsService — the shared write rules for Problem Management: create/update
 * a problem (with the per-field audit trail + PRB-##### numbering), delete, the
 * append-only notes journal, and incident/change linking with the same-company
 * rule.
 *
 * Shared by the UI endpoints (api/problem-management/*.php) and the REST API
 * (api/v1/resources/problems.php).
 *
 * TENANCY — this is the first tenant-scoped module on the service layer. Every
 * by-id read/write is gated by the actor's company scope via ctx->companyScope
 * (null = all companies, else the accessible tenant ids). The generic
 * canAccessTenantRow() mirrors apiKeyCanAccessTenantRow() / analystCanAccessX()
 * exactly (NULL tenant_id normalises to the Default company), so a problem or
 * ticket outside the caller's scope is a 404 for BOTH transports. The "acting
 * company" for a *create* is resolved by the adapter (the API's explicit
 * company_id / key default, the UI's active tenant) and passed in as $tenantId —
 * it is auth-adjacent, like actorId.
 *
 * Canonical behaviour = the API resource's, so the API stays byte-identical
 * while the UI's looser writes converge: unknown status/priority/analyst → 422,
 * empty text → null, a duplicate link → 409, and an unlink / edit of something
 * gone → 404 (the UI silently succeeded).
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class ProblemsService
{
    // ======================================================================
    //  Problems
    // ======================================================================

    /**
     * Create a problem in the given (adapter-resolved) tenant. Returns the id.
     * The adapter has already resolved + scope-checked $tenantId.
     */
    public static function createProblem(PDO $conn, ActorContext $ctx, int $tenantId, array $in): int
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        $actorId = $ctx->actorId;

        $statusRes = self::resolveStatus($conn, $in);
        if ($statusRes === null) {
            $row = $conn->query(
                "SELECT id, name, is_closed FROM problem_statuses WHERE is_default = 1 ORDER BY display_order LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            $statusRes = $row ? [(int)$row['id'], $row['name'], (int)$row['is_closed']] : [null, null, 0];
        }
        $priorityRes = self::resolvePriority($conn, $in) ?? [null, null];

        $analystId = null;
        if (isset($in['assigned_analyst_id']) && $in['assigned_analyst_id'] !== '') {
            $analystId = (int)$in['assigned_analyst_id'];
            self::resolveAnalyst($conn, $analystId);
        }

        $description  = trim((string)($in['description'] ?? '')) ?: null;
        $rootCause    = trim((string)($in['root_cause'] ?? '')) ?: null;
        $workaround   = trim((string)($in['workaround'] ?? '')) ?: null;
        $isKnownError = !empty($in['is_known_error']) ? 1 : 0;

        $conn->prepare(
            "INSERT INTO problems (
                tenant_id, title, description, status_id, priority_id, assigned_analyst_id,
                root_cause, workaround, is_known_error, created_by_id,
                created_datetime, updated_datetime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $tenantId, $title, $description, $statusRes[0], $priorityRes[0], $analystId,
            $rootCause, $workaround, $isKnownError, $actorId,
        ]);
        $problemId = (int)$conn->lastInsertId();

        $conn->prepare("UPDATE problems SET problem_number = ? WHERE id = ?")
             ->execute(['PRB-' . str_pad((string)$problemId, 5, '0', STR_PAD_LEFT), $problemId]);

        self::auditWrite($conn, $problemId, $actorId, 'created', null, null, null);

        try {
            WorkflowEngine::dispatch('problem.created', [
                'problem' => [
                    'id'                  => $problemId,
                    'problem_number'      => 'PRB-' . str_pad((string)$problemId, 5, '0', STR_PAD_LEFT),
                    'title'               => $title,
                    'status_id'           => $statusRes[0],
                    'priority_id'         => $priorityRes[0],
                    'assigned_analyst_id' => $analystId,
                    'is_known_error'      => $isKnownError,
                    'company_id'          => $tenantId,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in problem service (created): ' . $wfEx->getMessage());
        }

        return $problemId;
    }

    /** Apply a partial set of updates to a problem. Returns void; adapter reloads. */
    public static function updateProblem(PDO $conn, ActorContext $ctx, int $problemId, array $in): void
    {
        $current = self::loadProblem($conn, $ctx, $problemId);   // 404 if gone / out of scope
        if (!$in) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }
        $actorId = $ctx->actorId;

        $updates = [];
        $args    = [];
        $audits  = [];   // [field_key, old, new]

        if (array_key_exists('title', $in)) {
            $title = trim((string)$in['title']);
            if ($title === '') {
                throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
            }
            if ($title !== $current['title']) {
                $updates[] = 'title = ?';
                $args[]    = $title;
                $audits[]  = ['title', $current['title'], $title];
            }
        }
        if (array_key_exists('description', $in)) {
            $description = trim((string)$in['description']) ?: null;
            if ($description !== $current['description']) {
                $updates[] = 'description = ?';
                $args[]    = $description;
                $audits[]  = ['description', $current['description'], $description];
            }
        }

        // Status — closed_datetime transitions mirror save.php.
        $oldIsClosed = (int)($current['status_is_closed'] ?? 0);
        $statusChangedTo = null;
        $statusRes = self::resolveStatus($conn, $in);
        if ($statusRes !== null && $statusRes[0] !== (int)$current['status_id']) {
            [$newStatusId, $newStatusName, $newIsClosed] = $statusRes;
            $updates[] = 'status_id = ?';
            $args[]    = $newStatusId;
            if ($newIsClosed && !$oldIsClosed) $updates[] = 'closed_datetime = UTC_TIMESTAMP()';
            if (!$newIsClosed && $oldIsClosed) $updates[] = 'closed_datetime = NULL';
            $audits[] = ['status', $current['status_name'], $newStatusName];
            $statusChangedTo = $newStatusId;
        }

        $priorityRes = self::resolvePriority($conn, $in);
        if ($priorityRes !== null) {
            [$newPriorityId, $newPriorityName] = $priorityRes;
            if ($newPriorityId !== ($current['priority_id'] !== null ? (int)$current['priority_id'] : null)) {
                $updates[] = 'priority_id = ?';
                $args[]    = $newPriorityId;
                $audits[]  = ['priority', $current['priority_name'], $newPriorityName];
            }
        }

        if (array_key_exists('assigned_analyst_id', $in)) {
            $newAnalystId = ($in['assigned_analyst_id'] === '' || $in['assigned_analyst_id'] === null)
                ? null : (int)$in['assigned_analyst_id'];
            $newAnalystName = $newAnalystId !== null ? self::resolveAnalyst($conn, $newAnalystId) : null;
            $oldAnalystId = $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null;
            if ($newAnalystId !== $oldAnalystId) {
                $updates[] = 'assigned_analyst_id = ?';
                $args[]    = $newAnalystId;
                $audits[]  = ['assigned_to', $current['analyst_name'], $newAnalystName];
            }
        }

        foreach (['root_cause', 'workaround'] as $field) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newVal = trim((string)$in[$field]) ?: null;
            if ($newVal !== $current[$field]) {
                $updates[] = "$field = ?";
                $args[]    = $newVal;
                $audits[]  = [$field, $current[$field], $newVal];
            }
        }

        if (array_key_exists('is_known_error', $in)) {
            $newKE = !empty($in['is_known_error']) ? 1 : 0;
            if ($newKE !== (int)$current['is_known_error']) {
                $updates[] = 'is_known_error = ?';
                $args[]    = $newKE;
                $audits[]  = ['known_error', ((int)$current['is_known_error']) ? 'Yes' : 'No', $newKE ? 'Yes' : 'No'];
            }
        }

        if (!$updates) {
            return; // idempotent
        }

        $updates[] = 'updated_datetime = UTC_TIMESTAMP()';
        $args[]    = $problemId;
        $conn->prepare('UPDATE problems SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        foreach ($audits as [$field, $old, $new]) {
            self::auditWrite($conn, $problemId, $actorId, 'modified', $field, $old, $new);
        }

        if ($statusChangedTo !== null) {
            try {
                WorkflowEngine::dispatch('problem.status_changed', [
                    'problem' => [
                        'id'                  => $problemId,
                        'problem_number'      => $current['problem_number'],
                        'title'               => array_key_exists('title', $in) ? trim((string)$in['title']) : $current['title'],
                        'status_id'           => $statusChangedTo,
                        'priority_id'         => $current['priority_id'] !== null ? (int)$current['priority_id'] : null,
                        'assigned_analyst_id' => $current['assigned_analyst_id'] !== null ? (int)$current['assigned_analyst_id'] : null,
                        'company_id'          => $current['tenant_id'] !== null ? (int)$current['tenant_id'] : null,
                    ],
                ]);
            } catch (Exception $wfEx) {
                error_log('Workflow dispatch error in problem service (status_changed): ' . $wfEx->getMessage());
            }
        }
    }

    /** Delete a problem permanently (tidies change_relations, cascades children). */
    public static function deleteProblem(PDO $conn, ActorContext $ctx, int $problemId): void
    {
        self::loadProblem($conn, $ctx, $problemId);   // 404 if gone / out of scope
        try {
            $conn->prepare("DELETE FROM change_relations WHERE related_type = 'problem' AND related_id = ?")
                 ->execute([$problemId]);
        } catch (Exception $e) { /* change module absent */ }
        $conn->prepare("DELETE FROM problems WHERE id = ?")->execute([$problemId]);
    }

    // ======================================================================
    //  Notes
    // ======================================================================

    /** Append a journal note. Returns the note id (read AFTER touch, mirroring the API). */
    public static function addNote(PDO $conn, ActorContext $ctx, int $problemId, array $in): int
    {
        self::loadProblem($conn, $ctx, $problemId);   // 404 if gone / out of scope
        $note = trim((string)($in['note'] ?? ''));
        if ($note === '') {
            throw new ServiceError('validation', 'missing_field', "'note' is required.");
        }
        $conn->prepare("INSERT INTO problem_notes (problem_id, analyst_id, note, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$problemId, $ctx->actorId, $note]);
        self::touch($conn, $problemId);
        return (int)$conn->lastInsertId();
    }

    // ======================================================================
    //  Incident (ticket) linking
    // ======================================================================

    /** Link an incident. Returns [problem_id, ticket_id, ticket_number, linked]. */
    public static function linkTicket(PDO $conn, ActorContext $ctx, int $problemId, array $in): array
    {
        $problem = self::loadProblem($conn, $ctx, $problemId);   // 404
        $actorId = $ctx->actorId;

        $ticketId = isset($in['ticket_id']) ? (int)$in['ticket_id'] : 0;
        if ($ticketId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'ticket_id' is required.");
        }
        // The actor must be able to see the ticket too (its company scope).
        if (!self::canAccessTenantRow($conn, $ctx, 'tickets', $ticketId)) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }
        $tStmt = $conn->prepare("SELECT ticket_number, tenant_id FROM tickets WHERE id = ? AND deleted_datetime IS NULL");
        $tStmt->execute([$ticketId]);
        $ticket = $tStmt->fetch(PDO::FETCH_ASSOC);
        if (!$ticket) {
            throw new ServiceError('not_found', 'not_found', 'Ticket not found.');
        }

        // Same-company rule: NULL normalises to Default.
        if (isMultiTenant($conn)) {
            $default = getDefaultTenantId($conn);
            $pTid = $problem['tenant_id'] === null ? $default : (int)$problem['tenant_id'];
            $tTid = $ticket['tenant_id'] === null ? $default : (int)$ticket['tenant_id'];
            if ($pTid !== $tTid) {
                throw new ServiceError('validation', 'invalid_field', 'That incident belongs to a different company than this problem.');
            }
        }

        $dup = $conn->prepare("SELECT id FROM problem_tickets WHERE problem_id = ? AND ticket_id = ?");
        $dup->execute([$problemId, $ticketId]);
        if ($dup->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This incident is already linked to this problem.');
        }

        $conn->prepare("INSERT INTO problem_tickets (problem_id, ticket_id, created_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$problemId, $ticketId, $actorId]);
        self::auditWrite($conn, $problemId, $actorId, 'modified', 'linked_incident', null, $ticket['ticket_number']);
        self::touch($conn, $problemId);

        return ['problem_id' => $problemId, 'ticket_id' => $ticketId, 'ticket_number' => $ticket['ticket_number'], 'linked' => true];
    }

    /** Unlink an incident. 404 if not linked. */
    public static function unlinkTicket(PDO $conn, ActorContext $ctx, int $problemId, int $ticketId): void
    {
        self::loadProblem($conn, $ctx, $problemId);   // 404
        $stmt = $conn->prepare("DELETE FROM problem_tickets WHERE problem_id = ? AND ticket_id = ?");
        $stmt->execute([$problemId, $ticketId]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', 'Link not found.');
        }
        // No audit row on unlink — parity with the UI.
        self::touch($conn, $problemId);
    }

    // ======================================================================
    //  Change linking (shared change_relations table, related_type='problem')
    // ======================================================================

    /** Link the change that fixes a problem. Returns [problem_id, change_id, title, linked]. */
    public static function linkChange(PDO $conn, ActorContext $ctx, int $problemId, array $in): array
    {
        self::loadProblem($conn, $ctx, $problemId);   // 404
        $actorId = $ctx->actorId;

        $changeId = isset($in['change_id']) ? (int)$in['change_id'] : 0;
        if ($changeId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'change_id' is required.");
        }
        try {
            $cStmt = $conn->prepare("SELECT id, title FROM changes WHERE id = ?");
            $cStmt->execute([$changeId]);
            $change = $cStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            throw new ServiceError('validation', 'invalid_field', 'Change Management is not available on this install.');
        }
        if (!$change) {
            throw new ServiceError('validation', 'invalid_field', "Unknown change id: {$changeId}");
        }

        $dup = $conn->prepare("SELECT id FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?");
        $dup->execute([$changeId, $problemId]);
        if ($dup->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This change is already linked to this problem.');
        }

        $conn->prepare(
            "INSERT INTO change_relations (change_id, related_type, related_id, relation_type, created_by_id, created_datetime)
             VALUES (?, 'problem', ?, 'fixes', ?, UTC_TIMESTAMP())"
        )->execute([$changeId, $problemId, $actorId]);
        self::auditWrite($conn, $problemId, $actorId, 'modified', 'linked_change', null, 'Change #' . $changeId);
        self::touch($conn, $problemId);

        return ['problem_id' => $problemId, 'change_id' => $changeId, 'title' => $change['title'], 'linked' => true];
    }

    /** Unlink a change. 404 if not linked. */
    public static function unlinkChange(PDO $conn, ActorContext $ctx, int $problemId, int $changeId): void
    {
        self::loadProblem($conn, $ctx, $problemId);   // 404
        try {
            $stmt = $conn->prepare("DELETE FROM change_relations WHERE change_id = ? AND related_type = 'problem' AND related_id = ?");
            $stmt->execute([$changeId, $problemId]);
        } catch (Exception $e) {
            throw new ServiceError('validation', 'invalid_field', 'Change Management is not available on this install.');
        }
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', 'Link not found.');
        }
        // No audit row on unlink — parity with the UI.
        self::touch($conn, $problemId);
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Load the joined problem row, enforcing the actor's company scope; 404 if
     * the problem is unknown OR outside scope (indistinguishable, by design).
     */
    private static function loadProblem(PDO $conn, ActorContext $ctx, int $problemId): array
    {
        $stmt = $conn->prepare(
            "SELECT p.*,
                    ps.name AS status_name, ps.is_closed AS status_is_closed,
                    pp.name AS priority_name,
                    a.full_name AS analyst_name
             FROM problems p
             LEFT JOIN problem_statuses   ps ON ps.id = p.status_id
             LEFT JOIN problem_priorities pp ON pp.id = p.priority_id
             LEFT JOIN analysts           a  ON a.id  = p.assigned_analyst_id
             WHERE p.id = ?"
        );
        $stmt->execute([$problemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Problem not found.');
        }
        if ($ctx->companyScope !== null && isMultiTenant($conn)) {
            $tid = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            if (!in_array($tid, $ctx->companyScope, true)) {
                throw new ServiceError('not_found', 'not_found', 'Problem not found.');
            }
        }
        return $row;
    }

    /**
     * May the actor access this row of a tenant-scoped table (by its company)?
     * Generic mirror of apiKeyCanAccessTenantRow / analystCanAccessTicket. $table
     * is a developer literal, never user input.
     */
    private static function canAccessTenantRow(PDO $conn, ActorContext $ctx, string $table, int $rowId): bool
    {
        if ($rowId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("SELECT tenant_id FROM {$table} WHERE id = ?");
        $stmt->execute([$rowId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        if ($ctx->companyScope === null || !isMultiTenant($conn)) {
            return true;
        }
        $tid = ($row['tenant_id'] === null) ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
        return in_array($tid, $ctx->companyScope, true);
    }

    private static function auditWrite(PDO $conn, int $problemId, int $analystId, string $actionType, ?string $field, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO problem_audit (problem_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $problemId, $analystId, $actionType, $field,
            $old !== null ? mb_substr($old, 0, 1000) : null,
            $new !== null ? mb_substr($new, 0, 1000) : null,
        ]);
    }

    private static function touch(PDO $conn, int $problemId): void
    {
        $conn->prepare("UPDATE problems SET updated_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$problemId]);
    }

    /** Resolve a problem status by name or id. Returns [id, name, is_closed] or null. */
    private static function resolveStatus(PDO $conn, array $in): ?array
    {
        if (isset($in['status_id']) && $in['status_id'] !== '' && $in['status_id'] !== null) {
            $stmt = $conn->prepare("SELECT id, name, is_closed FROM problem_statuses WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in['status_id']]);
        } elseif (isset($in['status']) && trim((string)$in['status']) !== '') {
            $stmt = $conn->prepare("SELECT id, name, is_closed FROM problem_statuses WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in['status'])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown problem status: ' . ($in['status'] ?? $in['status_id']));
        }
        return [(int)$row['id'], $row['name'], (int)$row['is_closed']];
    }

    /** Resolve a problem priority by name or id. Explicit null/'' clears. */
    private static function resolvePriority(PDO $conn, array $in): ?array
    {
        if (array_key_exists('priority_id', $in)) {
            if ($in['priority_id'] === '' || $in['priority_id'] === null) {
                return [null, null];
            }
            $stmt = $conn->prepare("SELECT id, name FROM problem_priorities WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in['priority_id']]);
        } elseif (isset($in['priority']) && trim((string)$in['priority']) !== '') {
            $stmt = $conn->prepare("SELECT id, name FROM problem_priorities WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in['priority'])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown problem priority: ' . ($in['priority'] ?? $in['priority_id']));
        }
        return [(int)$row['id'], $row['name']];
    }

    /** Validate an analyst id (active); returns full_name. */
    private static function resolveAnalyst(PDO $conn, int $analystId): string
    {
        $stmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ? AND is_active = 1");
        $stmt->execute([$analystId]);
        $name = $stmt->fetchColumn();
        if ($name === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown or inactive analyst id: {$analystId}");
        }
        return $name;
    }
}
