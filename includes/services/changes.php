<?php
/**
 * ChangesService — the shared write rules for Change Management: create/update
 * a change (with the per-field audit trail + the change.approved workflow
 * event), delete, comments (append/remove), and the CAB roster + voting
 * (with the auto-transition into Approved / back to Draft).
 *
 * Shared by the UI endpoints (api/change-management/*.php) and the REST API
 * (api/v1/resources/changes.php). Each adapter distils its caller into an
 * ActorContext + canonical input; this layer validates + writes and returns the
 * affected id(s) / a small result array, or throws ServiceError. It never emits
 * HTTP.
 *
 * Canonical behaviour = the API resource's, so the API stays byte-identical
 * while the UI's looser writes converge to it:
 *   - people (Requester/Assigned To/Approver) are audited by NAME, not raw id;
 *   - a category_id change is audited (the UI only audited the legacy free-text
 *     category);
 *   - the creation audit reads "Created as <status>" (was hard-coded "Draft");
 *   - lookups are strict (unknown name/id → 422 rather than a silent fall-back);
 *   - analysts must exist and be active; dates are validated;
 *   - a missing change / comment is a 404 (was a silent success).
 *
 * Two UI conveniences map onto the same methods: the single-field inline edit
 * (update_field.php) is just updateChange() with one key, and delete_comment.php
 * deletes unscoped (no change id) via the optional $changeId argument.
 *
 * TENANCY (Phase 3) — changes are company-scoped like problems. Every by-id
 * write goes through loadJoined(), which gates on the actor's company scope
 * (ctx->companyScope; null = all companies) and 404s a change outside scope
 * (NULL tenant_id normalises to the Default company). The "acting company" for
 * a create is resolved by the adapter (the API's explicit company_id / key
 * default, the UI's active company) and passed in as $tenantId, like actorId.
 * All no-ops at N=1 (isMultiTenant() false).
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../tenancy.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class ChangesService
{
    // ======================================================================
    //  Changes
    // ======================================================================

    /**
     * Create a change in the given (adapter-resolved) company. Returns the new id.
     * The adapter has already resolved + scope-checked $tenantId.
     */
    public static function createChange(PDO $conn, ActorContext $ctx, int $tenantId, array $in): int
    {
        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        $actorId = $ctx->actorId;

        // Lookups: explicit (strict) or the module defaults.
        $type   = self::resolveLookup($conn, $in, 'change_type', 'change_types') ?? self::lookupDefault($conn, 'change_types');
        $status = self::resolveLookup($conn, $in, 'status', 'change_statuses', 'is_closed');
        if ($status === null) {
            $d = self::lookupDefault($conn, 'change_statuses');
            $status = [$d[0], $d[1], 0];
        }
        $priority = self::resolveLookup($conn, $in, 'priority', 'change_priorities') ?? self::lookupDefault($conn, 'change_priorities');
        $impact   = self::resolveLookup($conn, $in, 'impact', 'change_impacts') ?? self::lookupDefault($conn, 'change_impacts');

        $categoryId = null;
        if (isset($in['category_id']) && $in['category_id'] !== '' && $in['category_id'] !== null) {
            $categoryId = (int)$in['category_id'];
            $cStmt = $conn->prepare("SELECT id FROM change_categories WHERE id = ?");
            $cStmt->execute([$categoryId]);
            if (!$cStmt->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown category id: {$categoryId}");
            }
        }

        $people = [];
        foreach (['requester_id', 'assigned_to_id', 'approver_id'] as $field) {
            $people[$field] = null;
            if (isset($in[$field]) && $in[$field] !== '' && $in[$field] !== null) {
                $people[$field] = (int)$in[$field];
                self::resolveAnalyst($conn, $people[$field]);
            }
        }

        $dates = [];
        foreach (['work_start_at' => 'work_start_datetime', 'work_end_at' => 'work_end_datetime',
                  'outage_start_at' => 'outage_start_datetime', 'outage_end_at' => 'outage_end_datetime',
                  'pir_actual_start_at' => 'pir_actual_start', 'pir_actual_end_at' => 'pir_actual_end'] as $key => $col) {
            $dates[$col] = isset($in[$key]) && $in[$key] !== '' && $in[$key] !== null
                ? self::parseDate((string)$in[$key], $key) : null;
        }

        $riskLikelihood = self::riskInput($in['risk_likelihood'] ?? null, 'risk_likelihood');
        $riskImpact     = self::riskInput($in['risk_impact_score'] ?? null, 'risk_impact_score');
        $riskScore = ($riskLikelihood !== null && $riskImpact !== null) ? $riskLikelihood * $riskImpact : null;
        $riskLevel = self::riskLevel($riskScore);

        $cabRequired = !empty($in['cab_required']) ? 1 : 0;
        $cabType = $in['cab_approval_type'] ?? 'all';
        if (!in_array($cabType, ['all', 'majority'], true)) {
            $cabType = 'all';
        }

        $text = function ($key) use ($in) {
            $v = trim((string)($in[$key] ?? ''));
            return $v === '' ? null : $v;
        };

        $ins = $conn->prepare(
            "INSERT INTO changes (
                tenant_id, title, change_type_id, status_id, priority_id, impact_id, category, category_id,
                requester_id, assigned_to_id, approver_id,
                work_start_datetime, work_end_datetime, outage_start_datetime, outage_end_datetime,
                description, reason_for_change, risk_evaluation, test_plan, rollback_plan,
                post_implementation_review, risk_likelihood, risk_impact_score, risk_score, risk_level,
                pir_actual_start, pir_actual_end, pir_lessons_learned, pir_follow_up,
                cab_required, cab_approval_type, created_by_id, created_datetime, modified_datetime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $ins->execute([
            $tenantId, $title, $type[0], $status[0], $priority[0], $impact[0], $text('category'), $categoryId,
            $people['requester_id'], $people['assigned_to_id'], $people['approver_id'],
            $dates['work_start_datetime'], $dates['work_end_datetime'],
            $dates['outage_start_datetime'], $dates['outage_end_datetime'],
            $text('description'), $text('reason_for_change'), $text('risk_evaluation'),
            $text('test_plan'), $text('rollback_plan'), $text('post_implementation_review'),
            $riskLikelihood, $riskImpact, $riskScore, $riskLevel,
            $dates['pir_actual_start'], $dates['pir_actual_end'],
            $text('pir_lessons_learned'), $text('pir_follow_up'),
            $cabRequired, $cabType, $actorId,
        ]);
        $changeId = (int)$conn->lastInsertId();

        self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', null, 'Created as ' . ($status[1] ?? 'Draft'));

        try {
            WorkflowEngine::dispatch('change.created', [
                'change' => [
                    'id'             => $changeId,
                    'title'          => $title,
                    'status_id'      => $status[0],
                    'priority_id'    => $priority[0],
                    'type_id'        => $type[0],
                    'risk'           => $riskLevel,
                    'assigned_to_id' => $people['assigned_to_id'],
                    'company_id'     => $tenantId,
                ],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in change service (created): ' . $wfEx->getMessage());
        }

        return $changeId;
    }

    /**
     * Apply a partial set of updates to a change (a full-form save just sends
     * every key). Writes the UI's exact per-field audit rows, recomputes risk,
     * and fires change.approved on a genuine manual transition into Approved.
     * Returns void; the adapter reloads for its response.
     */
    public static function updateChange(PDO $conn, ActorContext $ctx, int $changeId, array $in): void
    {
        $current = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        if (!$in) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }
        $actorId = $ctx->actorId;

        $updates = [];
        $args    = [];
        $audits  = [];   // [actionType, label, old, new]

        $auditVal = function ($v) {
            $s = ($v === null || $v === '') ? '(empty)' : (string)$v;
            return strlen($s) > 200 ? substr($s, 0, 200) . '...' : $s;
        };
        $queueAudit = function (string $label, $old, $new, string $actionType = 'field_change') use (&$audits, $auditVal) {
            $audits[] = [$actionType, $label, $auditVal($old), $auditVal($new)];
        };

        if (array_key_exists('title', $in)) {
            $title = trim((string)$in['title']);
            if ($title === '') {
                throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
            }
            if ($title !== $current['title']) {
                $updates[] = 'title = ?';
                $args[]    = $title;
                $queueAudit('Title', $current['title'], $title);
            }
        }

        $newStatusName = null;
        $wasApproved = ($current['status_name'] === 'Approved');
        foreach ([
            ['change_type', 'change_types',      'change_type_id', 'Type',     'type_name'],
            ['status',      'change_statuses',   'status_id',      'Status',   'status_name'],
            ['priority',    'change_priorities', 'priority_id',    'Priority', 'priority_name'],
            ['impact',      'change_impacts',    'impact_id',      'Impact',   'impact_name'],
        ] as [$key, $table, $col, $label, $currentNameKey]) {
            $res = self::resolveLookup($conn, $in, $key, $table);
            if ($res === null || $res[0] === ((int)($current[$col] ?? 0) ?: null)) {
                continue;
            }
            $updates[] = "$col = ?";
            $args[]    = $res[0];
            $queueAudit($label, $current[$currentNameKey], $res[1], $key === 'status' ? 'status_change' : 'field_change');
            if ($key === 'status') {
                $newStatusName = $res[1];
            }
        }

        if (array_key_exists('category_id', $in)) {
            $newCatId = ($in['category_id'] === '' || $in['category_id'] === null) ? null : (int)$in['category_id'];
            $newCatName = null;
            if ($newCatId !== null) {
                $cStmt = $conn->prepare("SELECT name FROM change_categories WHERE id = ?");
                $cStmt->execute([$newCatId]);
                $newCatName = $cStmt->fetchColumn();
                if ($newCatName === false) {
                    throw new ServiceError('validation', 'invalid_field', "Unknown category id: {$newCatId}");
                }
            }
            if ($newCatId !== ($current['category_id'] !== null ? (int)$current['category_id'] : null)) {
                $updates[] = 'category_id = ?';
                $args[]    = $newCatId;
                $queueAudit('Category', $current['category_name'], $newCatName);
            }
        }
        if (array_key_exists('category', $in)) {
            $newCat = trim((string)$in['category']) ?: null;
            if ($newCat !== $current['category']) {
                $updates[] = 'category = ?';
                $args[]    = $newCat;
                $queueAudit('Category', $current['category'], $newCat);
            }
        }

        foreach ([
            'requester_id'   => ['Requester',   'requester_name'],
            'assigned_to_id' => ['Assigned To', 'assigned_to_name'],
            'approver_id'    => ['Approver',    'approver_name'],
        ] as $field => [$label, $currentNameKey]) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newId = ($in[$field] === '' || $in[$field] === null) ? null : (int)$in[$field];
            $newName = $newId !== null ? self::resolveAnalyst($conn, $newId) : null;
            if ($newId !== ($current[$field] !== null ? (int)$current[$field] : null)) {
                $updates[] = "$field = ?";
                $args[]    = $newId;
                $queueAudit($label, $current[$currentNameKey], $newName);
            }
        }

        foreach ([
            'work_start_at'       => ['work_start_datetime',    'Work Start'],
            'work_end_at'         => ['work_end_datetime',      'Work End'],
            'outage_start_at'     => ['outage_start_datetime',  'Outage Start'],
            'outage_end_at'       => ['outage_end_datetime',    'Outage End'],
            'pir_actual_start_at' => ['pir_actual_start',       'PIR Actual Start'],
            'pir_actual_end_at'   => ['pir_actual_end',         'PIR Actual End'],
        ] as $key => [$col, $label]) {
            if (!array_key_exists($key, $in)) {
                continue;
            }
            $newVal = ($in[$key] === null || $in[$key] === '') ? null : self::parseDate((string)$in[$key], $key);
            if ($newVal !== $current[$col]) {
                $updates[] = "$col = ?";
                $args[]    = $newVal;
                $queueAudit($label, $current[$col], $newVal);
            }
        }

        // Longtext bodies — updated but NOT audited (same as the UI).
        foreach (['description', 'reason_for_change', 'risk_evaluation', 'test_plan',
                  'rollback_plan', 'post_implementation_review', 'pir_lessons_learned', 'pir_follow_up'] as $field) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newVal = trim((string)$in[$field]) ?: null;
            if ($newVal !== $current[$field]) {
                $updates[] = "$field = ?";
                $args[]    = $newVal;
            }
        }

        // Risk inputs — recompute score + level from the merged pair.
        $riskTouched = array_key_exists('risk_likelihood', $in) || array_key_exists('risk_impact_score', $in);
        if ($riskTouched) {
            $newLikelihood = array_key_exists('risk_likelihood', $in)
                ? self::riskInput($in['risk_likelihood'], 'risk_likelihood')
                : ($current['risk_likelihood'] !== null ? (int)$current['risk_likelihood'] : null);
            $newImpact = array_key_exists('risk_impact_score', $in)
                ? self::riskInput($in['risk_impact_score'], 'risk_impact_score')
                : ($current['risk_impact_score'] !== null ? (int)$current['risk_impact_score'] : null);
            $newScore = ($newLikelihood !== null && $newImpact !== null) ? $newLikelihood * $newImpact : null;
            $newLevel = self::riskLevel($newScore);

            $oldLikelihood = $current['risk_likelihood'] !== null ? (int)$current['risk_likelihood'] : null;
            $oldImpact     = $current['risk_impact_score'] !== null ? (int)$current['risk_impact_score'] : null;
            if ($newLikelihood !== $oldLikelihood) {
                $updates[] = 'risk_likelihood = ?';
                $args[]    = $newLikelihood;
                $queueAudit('Risk Likelihood', $oldLikelihood, $newLikelihood);
            }
            if ($newImpact !== $oldImpact) {
                $updates[] = 'risk_impact_score = ?';
                $args[]    = $newImpact;
                $queueAudit('Risk Impact Score', $oldImpact, $newImpact);
            }
            if ($newLikelihood !== $oldLikelihood || $newImpact !== $oldImpact) {
                if ($newLevel !== $current['risk_level']) {
                    $queueAudit('Risk Level', $current['risk_level'], $newLevel);
                }
                $updates[] = 'risk_score = ?';
                $args[]    = $newScore;
                $updates[] = 'risk_level = ?';
                $args[]    = $newLevel;
            }
        }

        if (array_key_exists('pir_was_successful', $in)) {
            $newVal = ($in['pir_was_successful'] === null || $in['pir_was_successful'] === '') ? null : (int)(bool)$in['pir_was_successful'];
            $oldVal = $current['pir_was_successful'] !== null ? (int)$current['pir_was_successful'] : null;
            if ($newVal !== $oldVal) {
                $updates[] = 'pir_was_successful = ?';
                $args[]    = $newVal;
                $queueAudit('PIR Successful', $oldVal, $newVal);
            }
        }
        if (array_key_exists('cab_required', $in)) {
            $newVal = !empty($in['cab_required']) ? 1 : 0;
            if ($newVal !== (int)$current['cab_required']) {
                $updates[] = 'cab_required = ?';
                $args[]    = $newVal;
                $queueAudit('CAB Required', (int)$current['cab_required'], $newVal);
            }
        }
        if (array_key_exists('cab_approval_type', $in)) {
            $newVal = in_array($in['cab_approval_type'], ['all', 'majority'], true) ? $in['cab_approval_type'] : 'all';
            if ($newVal !== $current['cab_approval_type']) {
                $updates[] = 'cab_approval_type = ?';
                $args[]    = $newVal;
                $queueAudit('CAB Approval Type', $current['cab_approval_type'], $newVal);
            }
        }

        if (!$updates) {
            return; // idempotent — nothing to write
        }

        $updates[] = 'modified_datetime = UTC_TIMESTAMP()';
        $args[]    = $changeId;
        $conn->prepare('UPDATE changes SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        foreach ($audits as [$actionType, $label, $old, $new]) {
            self::auditWrite($conn, $changeId, $actorId, $actionType, $label, $old, $new);
        }

        // change.approved on a genuine manual transition into Approved.
        if ($newStatusName === 'Approved' && !$wasApproved) {
            $fresh = self::loadJoined($conn, $ctx, $changeId);
            self::approvedDispatch($changeId, $fresh['title'], $fresh['risk_level'],
                $fresh['approver_id'] !== null ? (int)$fresh['approver_id'] : null);
        }
    }

    /** Delete a change permanently: attachment files + rows, cascade children. */
    public static function deleteChange(PDO $conn, ActorContext $ctx, int $changeId): void
    {
        $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone

        $att = $conn->prepare("SELECT file_path FROM change_attachments WHERE change_id = ?");
        $att->execute([$changeId]);
        foreach ($att->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $filePath = dirname(__DIR__, 2) . '/change-management/attachments/' . $a['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $conn->prepare("DELETE FROM change_attachments WHERE change_id = ?")->execute([$changeId]);
        $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$changeId]);
        $dir = dirname(__DIR__, 2) . '/change-management/attachments/' . $changeId;
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        WorkflowEngine::dispatch('change.deleted', ['change' => ['id' => $changeId, 'title' => $row['title'] ?? null]]);
    }

    // ======================================================================
    //  Comments
    // ======================================================================

    /** Append an internal comment to a change. Returns the comment id. */
    public static function createComment(PDO $conn, ActorContext $ctx, int $changeId, array $in): int
    {
        self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        $text = trim((string)($in['text'] ?? ''));
        if ($text === '') {
            throw new ServiceError('validation', 'missing_field', "'text' is required.");
        }
        $actorId = $ctx->actorId;
        $conn->prepare(
            "INSERT INTO change_comments (change_id, analyst_id, comment_text, is_internal, created_datetime)
             VALUES (?, ?, ?, 1, UTC_TIMESTAMP())"
        )->execute([$changeId, $actorId, $text]);
        $commentId = (int)$conn->lastInsertId();

        $preview = mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '...' : $text;
        self::auditWrite($conn, $changeId, $actorId, 'comment', null, null, $preview);

        return $commentId;
    }

    /**
     * Delete a comment. $changeId scopes the delete (API route); pass null to
     * delete by comment id alone (the UI's delete_comment.php has no change id).
     */
    public static function deleteComment(PDO $conn, ActorContext $ctx, int $commentId, ?int $changeId = null): void
    {
        // Resolve the owning change so company scope is enforced even when the
        // caller only has the comment id (the UI's delete_comment.php).
        if ($changeId === null) {
            $lk = $conn->prepare("SELECT change_id FROM change_comments WHERE id = ?");
            $lk->execute([$commentId]);
            $cid = $lk->fetchColumn();
            if ($cid === false) {
                throw new ServiceError('not_found', 'not_found', 'Comment not found.');
            }
            $changeId = (int)$cid;
        }
        self::loadJoined($conn, $ctx, $changeId);   // 404 if the change is gone / out of scope
        $stmt = $conn->prepare("DELETE FROM change_comments WHERE id = ? AND change_id = ?");
        $stmt->execute([$commentId, $changeId]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', 'Comment not found.');
        }
    }

    // ======================================================================
    //  CAB
    // ======================================================================

    /** Replace the CAB roster (diff-sync add/remove/change-required + audit). */
    public static function saveCab(PDO $conn, ActorContext $ctx, int $changeId, $members): void
    {
        self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        $actorId = $ctx->actorId;

        if (!is_array($members)) {
            throw new ServiceError('validation', 'missing_field', "'members' is required: [{\"analyst_id\": 1, \"is_required\": true}, …].");
        }
        $wanted = [];
        foreach ($members as $m) {
            $aid = isset($m['analyst_id']) ? (int)$m['analyst_id'] : 0;
            if ($aid <= 0) {
                throw new ServiceError('validation', 'invalid_field', "Each member needs an 'analyst_id'.");
            }
            self::resolveAnalyst($conn, $aid);
            $wanted[$aid] = array_key_exists('is_required', $m) ? (bool)$m['is_required'] : true;
        }

        $exStmt = $conn->prepare("SELECT analyst_id, is_required FROM change_cab_members WHERE change_id = ?");
        $exStmt->execute([$changeId]);
        $existing = [];
        foreach ($exStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[(int)$row['analyst_id']] = (bool)$row['is_required'];
        }

        $nameOf = function (int $aid) use ($conn) {
            $s = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $s->execute([$aid]);
            return $s->fetchColumn() ?: (string)$aid;
        };

        foreach ($wanted as $aid => $isRequired) {
            if (!isset($existing[$aid])) {
                $conn->prepare(
                    "INSERT INTO change_cab_members (change_id, analyst_id, is_required, added_by_id, added_datetime)
                     VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
                )->execute([$changeId, $aid, $isRequired ? 1 : 0, $actorId]);
                self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null,
                    'Added: ' . $nameOf($aid) . ' (' . ($isRequired ? 'Required' : 'Optional') . ')');
            } elseif ($existing[$aid] !== $isRequired) {
                $conn->prepare("UPDATE change_cab_members SET is_required = ? WHERE change_id = ? AND analyst_id = ?")
                     ->execute([$isRequired ? 1 : 0, $changeId, $aid]);
                self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null,
                    $nameOf($aid) . ': ' . ($isRequired ? 'Optional → Required' : 'Required → Optional'));
            }
        }
        foreach ($existing as $aid => $isRequired) {
            if (!isset($wanted[$aid])) {
                $conn->prepare("DELETE FROM change_cab_members WHERE change_id = ? AND analyst_id = ?")
                     ->execute([$changeId, $aid]);
                self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Member', null, 'Removed: ' . $nameOf($aid));
            }
        }
    }

    /**
     * The acting analyst casts a CAB vote; any required Reject sends the change
     * back to Draft, the all/majority threshold on required members auto-approves.
     * Returns [change_id, vote, status_changed, new_status].
     */
    public static function voteCab(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        $actorId = $ctx->actorId;

        $vote = $in['vote'] ?? '';
        if (!in_array($vote, ['Approve', 'Reject', 'Abstain'], true)) {
            throw new ServiceError('validation', 'invalid_field', "'vote' must be Approve, Reject or Abstain.");
        }
        $voteComment = trim((string)($in['comment'] ?? ''));

        $memberStmt = $conn->prepare("SELECT id, vote FROM change_cab_members WHERE change_id = ? AND analyst_id = ?");
        $memberStmt->execute([$changeId, $actorId]);
        $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$membership) {
            throw new ServiceError('forbidden', 'forbidden', 'The analyst this key acts as is not a CAB member for this change.');
        }
        if ($membership['vote'] !== null) {
            throw new ServiceError('conflict', 'conflict', 'This CAB member has already voted on this change.');
        }

        $conn->prepare(
            "UPDATE change_cab_members SET vote = ?, vote_comment = ?, vote_datetime = UTC_TIMESTAMP()
             WHERE change_id = ? AND analyst_id = ?"
        )->execute([$vote, $voteComment ?: null, $changeId, $actorId]);

        $nameStmt = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
        $nameStmt->execute([$actorId]);
        $analystName = $nameStmt->fetchColumn() ?: 'Unknown';

        $auditDisplay = "$vote by $analystName";
        if ($voteComment) {
            $preview = mb_strlen($voteComment) > 80 ? mb_substr($voteComment, 0, 80) . '...' : $voteComment;
            $auditDisplay .= ": $preview";
        }
        self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Vote', null, $auditDisplay);

        $statusChanged = false;
        $newStatus = null;

        $changeStmt = $conn->prepare(
            "SELECT c.cab_approval_type, c.title, c.risk_level, c.approver_id, cs.name AS status
             FROM changes c LEFT JOIN change_statuses cs ON cs.id = c.status_id WHERE c.id = ?"
        );
        $changeStmt->execute([$changeId]);
        $changeRow = $changeStmt->fetch(PDO::FETCH_ASSOC);

        $statusIdFor = function ($name) use ($conn) {
            $s = $conn->prepare("SELECT id FROM change_statuses WHERE name = ? LIMIT 1");
            $s->execute([$name]);
            return $s->fetchColumn() ?: null;
        };

        if ($changeRow && $changeRow['status'] === 'Pending Approval') {
            $approvalType = $changeRow['cab_approval_type'] ?: 'all';
            $reqStmt = $conn->prepare("SELECT vote FROM change_cab_members WHERE change_id = ? AND is_required = 1");
            $reqStmt->execute([$changeId]);
            $reqVotes = $reqStmt->fetchAll(PDO::FETCH_COLUMN);

            $totalRequired = count($reqVotes);
            $approved = count(array_filter($reqVotes, fn($v) => $v === 'Approve'));
            $rejected = count(array_filter($reqVotes, fn($v) => $v === 'Reject'));

            if ($rejected > 0) {
                $conn->prepare("UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$statusIdFor('Draft'), $changeId]);
                self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', 'Pending Approval', 'Draft');
                $statusChanged = true;
                $newStatus = 'Draft';
            } elseif ($totalRequired > 0) {
                $thresholdMet = ($approvalType === 'majority') ? ($approved > $totalRequired / 2) : ($approved === $totalRequired);
                if ($thresholdMet) {
                    $conn->prepare("UPDATE changes SET status_id = ?, approval_datetime = UTC_TIMESTAMP(), modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                         ->execute([$statusIdFor('Approved'), $changeId]);
                    self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', 'Pending Approval', 'Approved');
                    $statusChanged = true;
                    $newStatus = 'Approved';
                }
            }
        }

        if ($statusChanged && $newStatus === 'Approved') {
            self::approvedDispatch($changeId, $changeRow['title'] ?? null, $changeRow['risk_level'] ?? null,
                isset($changeRow['approver_id']) && $changeRow['approver_id'] !== null ? (int)$changeRow['approver_id'] : null);
        }

        return [
            'change_id'      => $changeId,
            'vote'           => $vote,
            'status_changed' => $statusChanged,
            'new_status'     => $newStatus,
        ];
    }

    // ======================================================================
    //  Incident (ticket) linking  — twin of ProblemsService::linkTicket
    // ======================================================================

    /** Link an incident (ticket) to a change. Returns [change_id, ticket_id, ticket_number, linked]. */
    public static function linkTicket(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        $change = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone / out of scope
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
            $cTid = $change['tenant_id'] === null ? $default : (int)$change['tenant_id'];
            $tTid = $ticket['tenant_id'] === null ? $default : (int)$ticket['tenant_id'];
            if ($cTid !== $tTid) {
                throw new ServiceError('validation', 'invalid_field', 'That incident belongs to a different company than this change.');
            }
        }

        $dup = $conn->prepare("SELECT id FROM change_tickets WHERE change_id = ? AND ticket_id = ?");
        $dup->execute([$changeId, $ticketId]);
        if ($dup->fetchColumn()) {
            throw new ServiceError('conflict', 'conflict', 'This incident is already linked to this change.');
        }

        $conn->prepare("INSERT INTO change_tickets (change_id, ticket_id, created_by_id, created_datetime) VALUES (?, ?, ?, UTC_TIMESTAMP())")
             ->execute([$changeId, $ticketId, $actorId]);
        self::auditWrite($conn, $changeId, $actorId, 'field_change', 'Linked incident', null, $ticket['ticket_number']);
        $conn->prepare("UPDATE changes SET modified_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$changeId]);

        return ['change_id' => $changeId, 'ticket_id' => $ticketId, 'ticket_number' => $ticket['ticket_number'], 'linked' => true];
    }

    /** Unlink an incident from a change. 404 if not linked. Audited. */
    public static function unlinkTicket(PDO $conn, ActorContext $ctx, int $changeId, int $ticketId): void
    {
        self::loadJoined($conn, $ctx, $changeId);   // 404 if gone / out of scope
        // Ticket number for the audit trail (before the link is removed).
        $tn = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
        $tn->execute([$ticketId]);
        $ticketNumber = $tn->fetchColumn() ?: ('#' . $ticketId);

        $stmt = $conn->prepare("DELETE FROM change_tickets WHERE change_id = ? AND ticket_id = ?");
        $stmt->execute([$changeId, $ticketId]);
        if ($stmt->rowCount() === 0) {
            throw new ServiceError('not_found', 'not_found', 'Link not found.');
        }
        self::auditWrite($conn, $changeId, $ctx->actorId, 'field_change', 'Linked incident', $ticketNumber, null);
        $conn->prepare("UPDATE changes SET modified_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$changeId]);
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * May the actor access this row of a tenant-scoped table (by its company)?
     * Generic mirror of ProblemsService::canAccessTenantRow. $table is a
     * developer literal, never user input.
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

    /**
     * Load the joined change row (with lookup + people display names), enforcing
     * the actor's company scope; 404 if the change is unknown OR outside scope
     * (indistinguishable, by design). NULL tenant_id normalises to the Default
     * company. No-op at N=1.
     */
    private static function loadJoined(PDO $conn, ActorContext $ctx, int $changeId): array
    {
        $stmt = $conn->prepare(
            "SELECT c.*,
                    ct.name AS type_name,
                    cs.name AS status_name, cs.is_closed AS status_is_closed,
                    cp.name AS priority_name,
                    ci.name AS impact_name,
                    cc.name AS category_name,
                    rq.full_name AS requester_name,
                    asg.full_name AS assigned_to_name,
                    ap.full_name AS approver_name
             FROM changes c
             LEFT JOIN change_types      ct  ON ct.id = c.change_type_id
             LEFT JOIN change_statuses   cs  ON cs.id = c.status_id
             LEFT JOIN change_priorities cp  ON cp.id = c.priority_id
             LEFT JOIN change_impacts    ci  ON ci.id = c.impact_id
             LEFT JOIN change_categories cc  ON cc.id = c.category_id
             LEFT JOIN analysts          rq  ON rq.id = c.requester_id
             LEFT JOIN analysts          asg ON asg.id = c.assigned_to_id
             LEFT JOIN analysts          ap  ON ap.id = c.approver_id
             WHERE c.id = ?"
        );
        $stmt->execute([$changeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Change not found.');
        }
        if ($ctx->companyScope !== null && isMultiTenant($conn)) {
            $tid = $row['tenant_id'] === null ? getDefaultTenantId($conn) : (int)$row['tenant_id'];
            if (!in_array($tid, $ctx->companyScope, true)) {
                throw new ServiceError('not_found', 'not_found', 'Change not found.');
            }
        }
        return $row;
    }

    private static function auditWrite(PDO $conn, int $changeId, int $analystId, string $actionType, ?string $field, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$changeId, $analystId, $actionType, $field, $old, $new]);
    }

    /** The risk banding (score = likelihood × impact). */
    private static function riskLevel(?int $score): ?string
    {
        if ($score === null) return null;
        if ($score <= 4)  return 'Low';
        if ($score <= 9)  return 'Medium';
        if ($score <= 15) return 'High';
        if ($score <= 20) return 'Very High';
        return 'Critical';
    }

    /**
     * Resolve a change lookup (type/status/priority/impact) by name or id from
     * input keys "<key>" / "<key>_id". Strict: unknown values are a 422. Returns
     * [id, name, extraCol?] or null when neither key was sent.
     */
    private static function resolveLookup(PDO $conn, array $in, string $key, string $table, string $extraCol = ''): ?array
    {
        $cols = 'id, name' . ($extraCol !== '' ? ", $extraCol" : '');
        if (isset($in[$key . '_id']) && $in[$key . '_id'] !== '' && $in[$key . '_id'] !== null) {
            $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE id = ? LIMIT 1");
            $stmt->execute([(int)$in[$key . '_id']]);
        } elseif (isset($in[$key]) && trim((string)$in[$key]) !== '') {
            $stmt = $conn->prepare("SELECT $cols FROM `$table` WHERE name = ? LIMIT 1");
            $stmt->execute([trim((string)$in[$key])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', "Unknown $key: " . ($in[$key] ?? $in[$key . '_id']));
        }
        $out = [(int)$row['id'], $row['name']];
        if ($extraCol !== '') {
            $out[] = (int)$row[$extraCol];
        }
        return $out;
    }

    /** The default (is_default=1) row of a change lookup table: [id, name] or [null, null]. */
    private static function lookupDefault(PDO $conn, string $table): array
    {
        $row = $conn->query("SELECT id, name FROM `$table` WHERE is_default = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ? [(int)$row['id'], $row['name']] : [null, null];
    }

    /** Validate a 1-5 risk input. Null/'' clears. */
    private static function riskInput($value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $v = (int)$value;
        if ($v < 1 || $v > 5) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be between 1 and 5.");
        }
        return $v;
    }

    /** Resolve an analyst id to its name; 422 if unknown/inactive (mirrors apiResolveAnalyst). */
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

    /** Parse a date to UTC 'Y-m-d H:i:s' (mirrors the API's apiParseDate; 400 on garbage). */
    private static function parseDate(string $value, string $field): string
    {
        $v = trim($value);
        try {
            $dt = new DateTimeImmutable($v, new DateTimeZone('UTC'));
            return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new ServiceError('bad_request', 'invalid_parameter', "'{$field}' is not a valid date/time. Use ISO 8601, e.g. 2026-07-02T09:00:00Z.");
        }
    }

    /** Fire change.approved (best-effort; the engine swallows its own errors). */
    private static function approvedDispatch(int $changeId, ?string $title, ?string $riskLevel, ?int $approverId): void
    {
        try {
            WorkflowEngine::dispatch('change.approved', [
                'change'   => ['id' => $changeId, 'title' => $title, 'risk' => $riskLevel],
                'approver' => ['id' => $approverId],
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in ChangesService: ' . $wfEx->getMessage());
        }
    }
}
