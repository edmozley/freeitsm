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
require_once __DIR__ . '/../changes/approvals.php';
require_once __DIR__ . '/../changes/revisions.php';
require_once __DIR__ . '/../rbac.php';   // analystMayAct() - "Choose approvers" (#180 Part 3)
require_once __DIR__ . '/../changes/templates.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class ChangesService
{
    // ======================================================================
    //  Changes
    // ======================================================================

    /**
     * Create a change in the given (adapter-resolved) company. Returns the new id.
     * The adapter has already resolved + scope-checked $tenantId.
     *
     * @param array $in  title (required), lookups as name or <key>_id, category_id, people ids, dates, text bodies, risk, cab_*
     */
    public static function createChange(PDO $conn, ActorContext $ctx, int $tenantId, array $in): int
    {
        [$changeId, $after] = self::inTransaction($conn, fn() => self::applyCreate($conn, $ctx, $tenantId, $in));
        self::afterCommit($after);
        return $changeId;
    }

    /**
     * createChange()'s work, inside its transaction. Returns [the new id, the events to
     * fire once it has committed].
     *
     * @param array $in  as createChange()
     */
    private static function applyCreate(PDO $conn, ActorContext $ctx, int $tenantId, array $in): array
    {
        self::assertCanWrite($conn, $ctx);
        // Raised from an approved template (#180 §8): the version must be approved and usable
        // by this person; its fields fill in whatever the request leaves out.
        $templateVersion = null;
        if (!empty($in['template_version_id'])) {
            $templateVersion = ChangeTemplates::version($conn, (int)$in['template_version_id']);
            if (!$templateVersion || $templateVersion['state'] !== 'approved' || $templateVersion['retired_datetime'] !== null
                || !ChangeTemplates::mayUse($conn, $templateVersion['content'], $ctx->actorId)) {
                throw new ServiceError('validation', 'invalid_field', t('change-management.templates.not_usable'));
            }
            foreach ($templateVersion['content']['values'] ?? [] as $col => $val) {
                if (!array_key_exists($col, $in) && $val !== null && $val !== '') $in[$col] = $val;
            }
        }
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
        $statusStage = self::stageOf($conn, $status[0]);
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
        // "Choose approvers" (#180 Part 3): where Settings restricts it, somebody without
        // the permission can still raise a change - with nobody chosen to approve it.
        if (($cabRequired || $people['approver_id'] !== null)
            && !analystMayAct($conn, $actorId, Cap::CHANGES_CHOOSE_APPROVERS)) {
            throw new ServiceError('forbidden', 'forbidden', t('change-management.approval.cannot_choose_approvers'));
        }
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
                cab_required, cab_approval_type, created_by_id, created_datetime, modified_datetime,
                approval_datetime
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(),
                IF(?, UTC_TIMESTAMP(), NULL))"
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
            $statusStage === 'approved' ? 1 : 0,
        ]);
        $changeId = (int)$conn->lastInsertId();

        self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', null, 'Created as ' . ($status[1] ?? 'Draft'));

        // A change created now has complete text history from its first save, so it
        // never needs a baseline (includes/changes/revisions.php).
        foreach (ChangeRevisions::FIELDS as $field => $_) {
            if ($text($field) !== null) {
                ChangeRevisions::write($conn, $changeId, $field, $text($field), $actorId ?: null, $ctx->source);
            }
        }

        $event = [
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
        ];
        $after = [function () use ($event) {
            try {
                WorkflowEngine::dispatch('change.created', $event);
            } catch (Exception $wfEx) {
                error_log('Workflow dispatch error in change service (created): ' . $wfEx->getMessage());
            }
        }];

        // Created straight into an approval stage: the record starts there too. Into
        // awaiting opens a round; into approved is a hand-set approval, recorded as such.
        // Enforce mode: a change cannot be created straight into an approved-or-later
        // stage (POST /changes into Completed was a way round), and a change created
        // straight into awaiting must have somebody who can decide it. Thrown inside the
        // transaction, so the INSERT goes too.
        $cfg = changeApprovalSettings($conn);
        if ($templateVersion) {
            $conn->prepare("UPDATE changes SET template_version_id = ? WHERE id = ?")->execute([(int)$templateVersion['id'], $changeId]);
        }
        // A standard change created straight into an approval stage, its recipe untouched:
        // pre-authorised by the template's own approval (§8).
        if ($templateVersion && in_array($statusStage, ['awaiting', 'approved', 'implementing', 'done'], true)) {
            $pre = self::preAuthorise($conn, $changeId, $statusStage, $actorId, $cfg);
            if ($pre) {
                $after = array_merge($after, $pre);
                return [$changeId, $after];
            }
        }
        changeApprovalGuardMove(null, ['name' => $status[1], 'stage' => $statusStage], $cfg);
        if ($statusStage === 'awaiting' || $statusStage === 'approved') {
            $fresh = self::rawRow($conn, $changeId);
            if ($statusStage === 'awaiting') {
                changeApprovalGuardSubmit($fresh, [], $cfg);
                $roundId = changeApprovalOpenRound($conn, $fresh, self::routeFor($conn, $fresh), $actorId ?: null, $cfg);
                $after[] = self::roundEvent($conn, 'change.submitted', $fresh, $roundId);
            } else {
                changeApprovalRecordApproved($conn, $fresh, 'manual', $actorId ?: null, null, $cfg);
            }
        }

        return [$changeId, $after];
    }

    /**
     * Apply a partial set of updates to a change (a full-form save just sends
     * every key). Writes the UI's exact per-field audit rows, recomputes risk,
     * and fires change.approved on a genuine manual transition into Approved.
     * Returns void; the adapter reloads for its response.
     *
     * @param int $changeId  the change to update
     * @param array $in  the fields to change, same keys as createChange(); absent keys are left alone
     */
    public static function updateChange(PDO $conn, ActorContext $ctx, int $changeId, array $in): void
    {
        $after = self::inTransaction($conn, fn() => self::applyUpdate($conn, $ctx, $changeId, $in));
        self::afterCommit($after);
    }

    /**
     * updateChange()'s work, inside its transaction and holding the change's row lock.
     * Returns the events to fire once it has committed.
     *
     * @param int $changeId  as updateChange()
     * @param array $in  as updateChange()
     */
    private static function applyUpdate(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        self::assertCanWrite($conn, $ctx);
        self::lockRow($conn, $changeId);
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

        $newStatusId = null;
        $oldStage = $current['status_stage'];
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
                $newStatusId = $res[0];
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

        // Longtext bodies. Too long for change_audit, so each changed one keeps its
        // WHOLE new value as a revision instead (written after the UPDATE, below), and
        // the audit trail gets a one-line summary pointing at it.
        $changedLong = [];
        foreach (ChangeRevisions::FIELDS as $field => $_) {
            if (!array_key_exists($field, $in)) {
                continue;
            }
            $newVal = trim((string)$in[$field]) ?: null;
            if ($newVal !== $current[$field]) {
                $updates[] = "$field = ?";
                $args[]    = $newVal;
                $changedLong[$field] = $newVal;
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
            return []; // idempotent — nothing to write
        }

        // ---- Who approves (#180 Part 1 §9, Part 3) --------------------------------
        $cfg = changeApprovalSettings($conn);
        $targetStage = $newStatusId !== null ? self::stageOf($conn, $newStatusId) : $oldStage;
        $setupTouched =
            (array_key_exists('cab_required', $in) && (!empty($in['cab_required']) ? 1 : 0) !== (int)$current['cab_required'])
            || (array_key_exists('cab_approval_type', $in)
                && (in_array($in['cab_approval_type'], ['all', 'majority'], true) ? $in['cab_approval_type'] : 'all') !== $current['cab_approval_type'])
            || (array_key_exists('approver_id', $in)
                && (($in['approver_id'] === '' || $in['approver_id'] === null) ? null : (int)$in['approver_id'])
                   !== ($current['approver_id'] !== null ? (int)$current['approver_id'] : null));
        if ($setupTouched && !analystMayAct($conn, $actorId, Cap::CHANGES_CHOOSE_APPROVERS)) {
            throw new ServiceError('forbidden', 'forbidden', t('change-management.approval.cannot_choose_approvers'));
        }
        if ($cfg['enforce']) {
            // The approval set-up is fixed once submitted: changing who approves after
            // the votes have started is a way round the vote. Withdrawing to Draft in
            // the same save is how it is changed.
            if ($setupTouched && $oldStage !== 'draft' && $oldStage !== null && $targetStage !== 'draft') {
                throw new ServiceError('conflict', 'approval_locked', t('change-management.approval.locked'));
            }
            // The guard on the move itself runs after the save (below), still inside this
            // transaction: a standard change is judged on the fields as they will stand.
        }

        // Entering the approved stage by hand is an approval, so it records when (F1).
        // Without the stamp Watchtower counted a hand-approved change as awaiting for
        // ever, and a Projects "change approved" gate never ticked. Moving back from
        // implementing/done to approved is not a new approval, so it keeps the old date.
        $newStage = $newStatusId !== null ? self::stageOf($conn, $newStatusId) : $oldStage;
        $becameApproved = $newStatusId !== null && $newStage === 'approved'
            && !in_array($oldStage, ['approved', 'implementing', 'done'], true);
        if ($becameApproved) {
            $updates[] = 'approval_datetime = UTC_TIMESTAMP()';
        }

        $updates[] = 'modified_datetime = UTC_TIMESTAMP()';
        $args[]    = $changeId;
        $conn->prepare('UPDATE changes SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($args);

        foreach ($audits as [$actionType, $label, $old, $new]) {
            self::auditWrite($conn, $changeId, $actorId, $actionType, $label, $old, $new);
        }

        // The full text of every changed long field, in this same transaction: the
        // UPDATE and its revisions commit together or not at all.
        foreach ($changedLong as $field => $newVal) {
            ChangeRevisions::ensureBaseline($conn, $changeId, $field, $current[$field]);
            $revId = ChangeRevisions::write($conn, $changeId, $field, $newVal, $actorId ?: null, $ctx->source);
            self::auditWrite($conn, $changeId, $actorId, ChangeRevisions::AUDIT_ACTION,
                ChangeRevisions::FIELDS[$field][1], null, 'Revision #' . $revId);
        }

        // ---- The approval round follows the change (#180 Part 1) -----------------
        // In every mode the record is kept: rounds open on submission, close when the
        // change goes back, and a hand-set Approved is recorded as exactly that. What
        // the settings change is only whether an edit WITHDRAWS an approval (§6).
        $fresh = self::rawRow($conn, $changeId);
        $round = changeApprovalCurrent($conn, $changeId);
        $who = self::actorName($conn, $ctx);
        $after = [];

        // A standard change leaving Draft with its recipe untouched is pre-authorised by its
        // template's approval (§8), before the guard judges the move.
        $preAuthorised = false;
        if ($newStatusId !== null && $oldStage === 'draft' && !empty($fresh['template_version_id'])
            && in_array($newStage, ['awaiting', 'approved', 'implementing', 'done'], true)) {
            $pre = self::preAuthorise($conn, $changeId, $newStage, $actorId, $cfg);
            if ($pre) {
                $after = array_merge($after, $pre);
                $preAuthorised = true;
                $round = null;
            }
        }
        // TRAP: the guard. After the save so a template change is judged as it will stand;
        // still inside the transaction, so a refusal rolls the save back with it.
        if ($cfg['enforce'] && $newStatusId !== null && !$preAuthorised) {
            changeApprovalGuardMove(changeApprovalCurrent($conn, $changeId),
                ['name' => self::statusName($conn, $newStatusId), 'stage' => $targetStage], $cfg);
        }

        if (!$preAuthorised && $newStatusId !== null && $newStage !== $oldStage) {
            if ($newStage === 'awaiting') {
                // Submitted, or resubmitted by hand: a new request, so a standing
                // approval is superseded and the vote starts again (F2 - votes used to
                // survive on the member rows, so an old required Reject sent the
                // resubmitted change straight back to Draft on the next vote).
                changeApprovalGuardSubmit($fresh, self::requiredCabIds($conn, $changeId), $cfg);
                if ($round) {
                    changeApprovalClose($conn, (int)$round['id'], $round['state'] === 'open' ? 'withdrawn' : 'superseded', "Resubmitted by {$who}");
                }
                self::clearCabVotes($conn, $changeId, $actorId);
                $roundId = changeApprovalOpenRound($conn, $fresh, self::routeFor($conn, $fresh), $actorId ?: null, $cfg);
                $after[] = self::roundEvent($conn, $round ? 'change.resubmitted' : 'change.submitted', $fresh, $roundId, "Submitted by {$who}");
                $round = null;
            } elseif ($becameApproved) {
                // Approved by hand. Allowed in Record mode, and recorded as exactly that:
                // a 'manual' round, attributed, so the history never shows a vote that
                // did not happen.
                if ($round) {
                    changeApprovalClose($conn, (int)$round['id'], $round['state'] === 'open' ? 'withdrawn' : 'superseded', "Approved by hand by {$who}");
                }
                changeApprovalRecordApproved($conn, $fresh, 'manual', $actorId ?: null, null, $cfg);
                $round = null;
            } elseif ($round && ($round['state'] === 'open' || $newStage === 'draft')
                      // An emergency change goes ahead while its decision is pending: its
                      // round stays open into Implementing and Done (§8, Emergency).
                      && !($round['route'] === 'emergency' && $round['state'] === 'open'
                           && in_array($newStage, ['implementing', 'done'], true))) {
                // Back to Draft, stopped, or moved past the decision by hand: the
                // request is withdrawn. Back to Draft also withdraws a standing approval.
                $reason = $newStage === 'draft'
                    ? "Returned to Draft by {$who}"
                    : 'Moved to ' . self::statusName($conn, $newStatusId) . " by {$who} before a decision";
                changeApprovalClose($conn, (int)$round['id'], 'withdrawn', $reason);
                if ($round['state'] === 'approved') {
                    $conn->prepare("UPDATE changes SET approval_datetime = NULL WHERE id = ?")->execute([$changeId]);
                }
                $after[] = self::roundEvent($conn, 'change.approval_withdrawn', $fresh, (int)$round['id'], $reason);
                $round = null;
            }
        }

        // A material edit while a round stands (§6). Keep (Record's default): nothing is
        // written - the Approval panel compares the round's snapshot with the content and
        // says "approved against an earlier version". Withdraw (and always in Enforce):
        // the round is superseded and the change goes back to the board.
        if ($round && changeApprovalContentHash($current, $cfg['material']) !== changeApprovalContentHash($fresh, $cfg['material'])
            && $cfg['edit_withdraws']) {
            $reason = implode(', ', self::materialChanged($current, $fresh, $cfg)) . " edited by {$who}";
            changeApprovalClose($conn, (int)$round['id'], 'superseded', mb_substr($reason, 0, 255));
            if ($round['state'] === 'approved') {
                $awaiting = changeStatusForStage($conn, 'awaiting');
                if ($awaiting) {
                    $conn->prepare("UPDATE changes SET status_id = ?, approval_datetime = NULL, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                         ->execute([$awaiting['id'], $changeId]);
                    self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status',
                        self::statusName($conn, (int)$fresh['status_id']), $awaiting['name']);
                }
                $after[] = self::roundEvent($conn, 'change.approval_withdrawn', $fresh, (int)$round['id'], $reason);
            }
            self::clearCabVotes($conn, $changeId, $actorId);
            $fresh = self::rawRow($conn, $changeId);
            $roundId = changeApprovalOpenRound($conn, $fresh, self::routeFor($conn, $fresh), $actorId ?: null, $cfg);
            $after[] = self::roundEvent($conn, 'change.resubmitted', $fresh, $roundId, $reason);
        }

        // change.approved on a genuine manual transition into the approved stage.
        if ($becameApproved && !$preAuthorised) {
            $joined = self::loadJoined($conn, $ctx, $changeId);
            $after[] = fn() => self::approvedDispatch($changeId, $joined['title'], $joined['risk_level'],
                $joined['approver_id'] !== null ? (int)$joined['approver_id'] : null, 'manual');
        }
        return $after;
    }

    /**
     * Cancel a change: move it to the Stopped-stage status Settings names (by default the
     * last one, Cancelled on a seeded install - see changeCancelTarget()) and withdraw any
     * open round, keeping every record. This is what a change that has approval history
     * gets instead of Delete (§4) - an audit months later needs the abandoned changes as
     * much as the finished ones.
     *
     * @param int $changeId  the change
     * @param array $in  reason (optional) - why, in words; it goes on the record
     */
    public static function cancelChange(PDO $conn, ActorContext $ctx, int $changeId, array $in): void
    {
        self::assertCanWrite($conn, $ctx);
        $after = self::inTransaction($conn, function () use ($conn, $ctx, $changeId, $in) {
            self::lockRow($conn, $changeId);
            $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
            $target = changeCancelTarget($conn, changeApprovalSettings($conn));
            if (!$target) {
                throw new ServiceError('conflict', 'no_stopped_status', t('change-management.approval.no_stopped_status'));
            }
            if ($row['status_stage'] === 'stopped') {
                return [];   // already stopped - nothing to do
            }
            $reasonText = mb_substr(trim((string)($in['reason'] ?? '')), 0, 200);
            $who = self::actorName($conn, $ctx);
            $conn->prepare("UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                 ->execute([$target['id'], $changeId]);
            self::auditWrite($conn, $changeId, $ctx->actorId, 'status_change', 'Status', $row['status_name'],
                $target['name'] . ($reasonText !== '' ? ' - ' . $reasonText : ''));
            $after = [];
            $round = changeApprovalCurrent($conn, $changeId);
            if ($round && $round['state'] === 'open') {
                $reason = "Cancelled by {$who}" . ($reasonText !== '' ? ": {$reasonText}" : '');
                changeApprovalClose($conn, (int)$round['id'], 'withdrawn', mb_substr($reason, 0, 255));
                $after[] = self::roundEvent($conn, 'change.approval_withdrawn', self::rawRow($conn, $changeId), (int)$round['id'], $reason);
            }
            return $after;
        });
        self::afterCommit($after);
    }

    /**
     * The named approver decides a change awaiting their approval (route 'approver').
     * Until #180 the Approver field was a label with no button; now it is a decision,
     * recorded on the round like a CAB vote, and checked against the version on screen.
     * Returns [change_id, decision, status_changed, new_status].
     *
     * @param int $changeId  the change
     * @param array $in  decision ('approve' or 'reject'), note, approval_id, content_hash
     */
    public static function decideApproval(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        [$result, $after] = self::inTransaction($conn, function () use ($conn, $ctx, $changeId, $in) {
            self::lockRow($conn, $changeId);
            $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
            $cfg = changeApprovalSettings($conn);
            $actorId = $ctx->actorId;

            $decision = strtolower((string)($in['decision'] ?? ''));
            if (!in_array($decision, ['approve', 'reject'], true)) {
                throw new ServiceError('validation', 'invalid_field', "'decision' must be approve or reject.");
            }
            $round = changeApprovalCurrent($conn, $changeId);
            if (!self::roundAwaitsDecision($row, $round)) {
                throw new ServiceError('conflict', 'not_awaiting_approval', t('change-management.approval.not_awaiting'));
            }
            // The board decides a CAB change; the named approver decides the rest
            // (including an emergency change with no CAB).
            if (!empty($row['cab_required']) || !in_array($round['route'], ['approver', 'emergency', 'legacy'], true)) {
                throw new ServiceError('conflict', 'not_approver_route', t('change-management.approval.not_approver_route'));
            }
            if ((int)($row['approver_id'] ?? 0) !== $actorId || $actorId <= 0) {
                throw new ServiceError('forbidden', 'forbidden', t('change-management.approval.not_the_approver'));
            }
            changeApprovalCheckVersion($row, $round, $in['approval_id'] ?? null, $in['content_hash'] ?? null, $cfg);
            $independent = changeApprovalIsIndependent($row, $actorId, $cfg);
            self::guardIndependence($row, $actorId, $cfg);

            $note = trim((string)($in['note'] ?? ''));
            $who = self::actorName($conn, $ctx);
            $approve = $decision === 'approve';
            $target = $approve ? changeStatusForStage($conn, 'approved') : self::rejectTarget($conn, $cfg);
            changeApprovalClose($conn, (int)$round['id'], $approve ? 'approved' : 'rejected', null, $actorId, $note !== '' ? $note : null, $independent);
            if ($approve) {
                // The approved round stays the change's current one.
                $conn->prepare("UPDATE changes SET approval_id = ? WHERE id = ?")->execute([(int)$round['id'], $changeId]);
            }
            self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'Approver', null,
                ($approve ? 'Approved' : 'Rejected') . " by {$who}" . ($note !== '' ? ': ' . mb_substr($note, 0, 80) : ''));

            $after = [];
            $newStatus = null;
            if ($target && $row['status_stage'] === 'awaiting') {
                $conn->prepare(
                    "UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP()"
                    . ($approve ? ', approval_datetime = UTC_TIMESTAMP()' : '') . ' WHERE id = ?'
                )->execute([$target['id'], $changeId]);
                self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', $row['status_name'], $target['name']);
                $newStatus = $target['name'];
            } elseif ($approve) {
                $conn->prepare("UPDATE changes SET approval_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$changeId]);
            }
            $fresh = self::rawRow($conn, $changeId);
            if ($approve) {
                $after[] = fn() => self::approvedDispatch($changeId, $row['title'], $row['risk_level'], $actorId, 'approver');
            } else {
                $after[] = self::roundEvent($conn, 'change.rejected', $fresh, (int)$round['id'], $note, $actorId);
            }
            return [[
                'change_id'      => $changeId,
                'decision'       => $decision,
                'status_changed' => $newStatus !== null,
                'new_status'     => $newStatus,
            ], $after];
        });
        self::afterCommit($after);
        return $result;
    }

    /**
     * Delete a change permanently: attachment files + rows, cascade children.
     *
     * @param int $changeId  the change to delete, with its attachments
     */
    public static function deleteChange(PDO $conn, ActorContext $ctx, int $changeId): void
    {
        self::assertCanWrite($conn, $ctx);
        // The rows go inside the transaction; the files only once it has committed, so
        // a failed delete never leaves a change whose attachments have vanished.
        [$files, $title] = self::inTransaction($conn, function () use ($conn, $ctx, $changeId) {
            self::lockRow($conn, $changeId);
            $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
            // A change that has ever been submitted is evidence (§4). Where the settings
            // say so - and always in Enforce mode - it can be cancelled, not deleted.
            if (changeApprovalHasHistory($conn, $changeId)) {
                if (changeApprovalSettings($conn)['delete_refused']) {
                    throw new ServiceError('conflict', 'change_has_approval_history', t('change-management.approval.delete_refused'));
                }
                // TRAP: change_approvals is ON DELETE RESTRICT, so the rounds go first,
                // deliberately and here, or the DELETE below fails. Votes cascade.
                $conn->prepare("UPDATE changes SET approval_id = NULL WHERE id = ?")->execute([$changeId]);
                $conn->prepare("DELETE FROM change_approvals WHERE change_id = ?")->execute([$changeId]);
            }
            $att = $conn->prepare("SELECT file_path FROM change_attachments WHERE change_id = ?");
            $att->execute([$changeId]);
            $files = $att->fetchAll(PDO::FETCH_COLUMN);
            $conn->prepare("DELETE FROM change_attachments WHERE change_id = ?")->execute([$changeId]);
            $conn->prepare("DELETE FROM changes WHERE id = ?")->execute([$changeId]);
            return [$files, $row['title'] ?? null];
        });

        foreach ($files as $path) {
            $filePath = dirname(__DIR__, 2) . '/change-management/attachments/' . $path;
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        $dir = dirname(__DIR__, 2) . '/change-management/attachments/' . $changeId;
        if (is_dir($dir)) {
            @rmdir($dir);
        }
        WorkflowEngine::dispatch('change.deleted', ['change' => ['id' => $changeId, 'title' => $title]]);
    }

    /**
     * An administrator approves a change awaiting approval without the vote, with a
     * written reason (Settings → Approvals → Administrator override). Off by default;
     * never silent: the round says 'override', who, why, and whether it was independent.
     *
     * @param int $changeId  the change
     * @param array $in  reason (required)
     */
    public static function overrideApproval(PDO $conn, ActorContext $ctx, int $changeId, array $in): void
    {
        self::assertCanWrite($conn, $ctx);
        $after = self::inTransaction($conn, function () use ($conn, $ctx, $changeId, $in) {
            self::lockRow($conn, $changeId);
            $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
            $cfg = changeApprovalSettings($conn);
            if (!$cfg['override']) {
                throw new ServiceError('forbidden', 'override_off', t('change-management.approval.override_off'));
            }
            $adm = $conn->prepare("SELECT is_admin FROM analysts WHERE id = ? AND is_active = 1");
            $adm->execute([$ctx->actorId]);
            if ((int)$adm->fetchColumn() !== 1) {
                throw new ServiceError('forbidden', 'forbidden', t('change-management.approval.override_admin_only'));
            }
            $reason = trim((string)($in['reason'] ?? ''));
            if ($reason === '') {
                throw new ServiceError('validation', 'missing_field', t('change-management.approval.override_reason_required'));
            }
            $round = changeApprovalCurrent($conn, $changeId);
            if (!self::roundAwaitsDecision($row, $round)) {
                throw new ServiceError('conflict', 'not_awaiting_approval', t('change-management.approval.not_awaiting'));
            }
            $who = self::actorName($conn, $ctx);
            changeApprovalClose($conn, (int)$round['id'], 'withdrawn', mb_substr("Approved by override by {$who}", 0, 255));
            $fresh = self::rawRow($conn, $changeId);
            changeApprovalRecordApproved($conn, $fresh, 'override', $ctx->actorId, mb_substr($reason, 0, 2000), $cfg);
            self::auditWrite($conn, $changeId, $ctx->actorId, 'cab_vote', 'Override', null,
                "Approved by override by {$who}: " . mb_substr($reason, 0, 80));
            if ($row['status_stage'] === 'awaiting') {
                $target = changeStatusForStage($conn, 'approved');
                if ($target) {
                    $conn->prepare("UPDATE changes SET status_id = ?, approval_datetime = UTC_TIMESTAMP(), modified_datetime = UTC_TIMESTAMP() WHERE id = ?")
                         ->execute([$target['id'], $changeId]);
                    self::auditWrite($conn, $changeId, $ctx->actorId, 'status_change', 'Status', $row['status_name'], $target['name']);
                }
            } else {
                $conn->prepare("UPDATE changes SET approval_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$changeId]);
            }
            return [fn() => self::approvedDispatch($changeId, $row['title'], $row['risk_level'],
                $row['approver_id'] !== null ? (int)$row['approver_id'] : null, 'override')];
        });
        self::afterCommit($after);
    }

    // ======================================================================
    //  Comments
    // ======================================================================

    /**
     * Append an internal comment to a change. Returns the comment id.
     *
     * @param int $changeId  the change to comment on
     * @param array $in  text (required) - the comment, stored as internal
     */
    public static function createComment(PDO $conn, ActorContext $ctx, int $changeId, array $in): int
    {
        self::assertCanWrite($conn, $ctx);
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
     *
     * @param int $commentId  the change_comments row to delete
     * @param ?int $changeId  the change it must belong to, or null to look it up from the comment
     */
    public static function deleteComment(PDO $conn, ActorContext $ctx, int $commentId, ?int $changeId = null): void
    {
        self::assertCanWrite($conn, $ctx);
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

        // A deleted comment leaves the timeline, not the evidence: its text, author
        // and date are copied into the revisions first, and the deletion is audited.
        self::inTransaction($conn, function () use ($conn, $ctx, $commentId, $changeId) {
            $c = $conn->prepare("SELECT analyst_id, comment_text, created_datetime FROM change_comments WHERE id = ? AND change_id = ? FOR UPDATE");
            $c->execute([$commentId, $changeId]);
            $comment = $c->fetch(PDO::FETCH_ASSOC);
            if (!$comment) {
                throw new ServiceError('not_found', 'not_found', 'Comment not found.');
            }
            $revId = ChangeRevisions::write($conn, $changeId, 'comment', $comment['comment_text'],
                $comment['analyst_id'] !== null ? (int)$comment['analyst_id'] : null, 'deleted', false, $comment['created_datetime']);
            $conn->prepare("DELETE FROM change_comments WHERE id = ? AND change_id = ?")->execute([$commentId, $changeId]);
            $text = (string)$comment['comment_text'];
            $preview = mb_strlen($text) > 100 ? mb_substr($text, 0, 100) . '...' : $text;
            self::auditWrite($conn, $changeId, $ctx->actorId, 'comment_deleted', 'Comment', $preview, 'Revision #' . $revId);
        });
    }

    // ======================================================================
    //  CAB
    // ======================================================================

    /**
     * Replace the CAB roster (diff-sync add/remove/change-required + audit).
     *
     * @param int $changeId  the change whose CAB roster is replaced
     * @param mixed $members  the whole roster, [{analyst_id, is_required?}] - is_required defaults to true; must be an array
     */
    public static function saveCab(PDO $conn, ActorContext $ctx, int $changeId, $members): void
    {
        self::inTransaction($conn, fn() => self::applySaveCab($conn, $ctx, $changeId, $members));
    }

    /**
     * saveCab()'s work, inside its transaction and holding the change's row lock.
     *
     * @param int $changeId  as saveCab()
     * @param mixed $members  as saveCab()
     */
    private static function applySaveCab(PDO $conn, ActorContext $ctx, int $changeId, $members): void
    {
        self::assertCanWrite($conn, $ctx);
        self::lockRow($conn, $changeId);
        $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        $actorId = $ctx->actorId;
        // Enforce mode: the roster is fixed once the change is submitted (F4 - somebody
        // could add themselves as the only required member and approve by vote).
        if (changeApprovalSettings($conn)['enforce'] && $row['status_stage'] !== 'draft' && $row['status_stage'] !== null) {
            throw new ServiceError('conflict', 'approval_locked', t('change-management.approval.locked'));
        }
        if (!analystMayAct($conn, $actorId, Cap::CHANGES_CHOOSE_APPROVERS)) {
            throw new ServiceError('forbidden', 'forbidden', t('change-management.approval.cannot_choose_approvers'));
        }

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
     *
     * @param int $changeId  the change being voted on
     * @param array $in  vote ('Approve', 'Reject' or 'Abstain') and an optional comment
     */
    public static function voteCab(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        // TRAP: one transaction holding the change's row lock. Two last votes landing
        // together used to both tip the threshold and fire change.approved twice; now
        // the second waits, then sees the change already approved.
        [$result, $after] = self::inTransaction($conn, fn() => self::applyVote($conn, $ctx, $changeId, $in));
        self::afterCommit($after);
        return $result;
    }

    /**
     * voteCab()'s work, inside its transaction. Returns [the result, the events to fire
     * once it has committed].
     *
     * @param int $changeId  as voteCab()
     * @param array $in  as voteCab()
     */
    private static function applyVote(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        self::lockRow($conn, $changeId);
        $row = self::loadJoined($conn, $ctx, $changeId);   // 404 if gone
        $actorId = $ctx->actorId;
        $cfg = changeApprovalSettings($conn);

        $vote = $in['vote'] ?? '';
        if (!in_array($vote, ['Approve', 'Reject', 'Abstain'], true)) {
            throw new ServiceError('validation', 'invalid_field', "'vote' must be Approve, Reject or Abstain.");
        }
        $voteComment = trim((string)($in['comment'] ?? ''));

        // A vote needs an open round. It used to be accepted on a Draft and counted
        // later, against content that may have changed in between.
        $round = changeApprovalCurrent($conn, $changeId);
        if (!self::roundAwaitsDecision($row, $round)) {
            throw new ServiceError('conflict', 'not_awaiting_approval', t('change-management.approval.not_awaiting'));
        }

        $memberStmt = $conn->prepare("SELECT id, vote, is_required FROM change_cab_members WHERE change_id = ? AND analyst_id = ?");
        $memberStmt->execute([$changeId, $actorId]);
        $membership = $memberStmt->fetch(PDO::FETCH_ASSOC);
        if (!$membership) {
            throw new ServiceError('forbidden', 'forbidden', 'The analyst this key acts as is not a CAB member for this change.');
        }
        if ($membership['vote'] !== null) {
            throw new ServiceError('conflict', 'conflict', 'This CAB member has already voted on this change.');
        }

        // Cast against the version on screen (§5)? Refused where the settings say so;
        // otherwise recorded, with whether it was current.
        $wasCurrent = changeApprovalCheckVersion($row, $round, $in['approval_id'] ?? null, $in['content_hash'] ?? null, $cfg);
        $counted = self::voteCounts($row, $actorId, $cfg);

        $conn->prepare(
            "UPDATE change_cab_members SET vote = ?, vote_comment = ?, vote_datetime = UTC_TIMESTAMP()
             WHERE change_id = ? AND analyst_id = ?"
        )->execute([$vote, $voteComment ?: null, $changeId, $actorId]);
        $conn->prepare(
            "INSERT INTO change_approval_votes (approval_id, analyst_id, is_required, is_counted, vote, comment, content_hash, was_current, voted_datetime)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([(int)$round['id'], $actorId, (int)$membership['is_required'], $counted ? 1 : 0, $vote, $voteComment ?: null,
                    is_string($in['content_hash'] ?? null) && $in['content_hash'] !== '' ? $in['content_hash'] : null,
                    $wasCurrent === null ? null : ($wasCurrent ? 1 : 0)]);

        $analystName = self::actorName($conn, $ctx);
        $auditDisplay = "$vote by $analystName";
        if ($voteComment) {
            $preview = mb_strlen($voteComment) > 80 ? mb_substr($voteComment, 0, 80) . '...' : $voteComment;
            $auditDisplay .= ": $preview";
        }
        if (!$counted) {
            $auditDisplay .= ' (not counted: one of the change\'s own people)';
        }
        if ($wasCurrent === false) {
            $auditDisplay .= ' (cast against an earlier version)';
        }
        self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Vote', null, $auditDisplay);

        // Today's arithmetic, over THIS round's counted votes: all = every counted
        // required member approves; majority = more than half of them; one counted
        // required Reject rejects; Abstain never moves anything; optional members never
        // count. In Enforce mode with independence on, the change's own people neither
        // vote nor make up the total.
        $memStmt = $conn->prepare("SELECT analyst_id FROM change_cab_members WHERE change_id = ? AND is_required = 1");
        $memStmt->execute([$changeId]);
        $required = array_values(array_filter(array_map('intval', $memStmt->fetchAll(PDO::FETCH_COLUMN)),
            fn($aid) => self::voteCounts($row, $aid, $cfg)));
        $vStmt = $conn->prepare("SELECT analyst_id, vote FROM change_approval_votes WHERE approval_id = ? AND is_counted = 1 ORDER BY id");
        $vStmt->execute([(int)$round['id']]);
        $votes = [];
        foreach ($vStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
            $votes[(int)$v['analyst_id']] = $v['vote'];
        }
        $totalRequired = count($required);
        $approved = count(array_filter($required, fn($aid) => ($votes[$aid] ?? null) === 'Approve'));
        $rejected = count(array_filter($required, fn($aid) => ($votes[$aid] ?? null) === 'Reject'));
        $approvalType = $row['cab_approval_type'] ?: 'all';

        // TRAP: the targets come from the STAGE, never a name. Looking up 'Approved' by
        // name set status_id to NULL as soon as somebody renamed it (F3).
        $target = null;
        $isApproval = false;
        if ($rejected > 0) {
            $target = self::rejectTarget($conn, $cfg);
        } elseif ($totalRequired > 0) {
            $thresholdMet = ($approvalType === 'majority') ? ($approved > $totalRequired / 2) : ($approved === $totalRequired);
            if ($thresholdMet) {
                $target = changeStatusForStage($conn, 'approved');
                $isApproval = true;
            }
        }

        $after = [];
        $statusChanged = false;
        $newStatus = null;
        if ($rejected > 0 || $isApproval) {
            // Independent if no counted approval came from the change's own people.
            $own = changeApprovalOwnPeople($row, $cfg);
            $independent = !array_filter($required, fn($aid) => in_array($aid, $own, true) && ($votes[$aid] ?? null) === 'Approve');
            changeApprovalClose($conn, (int)$round['id'], $isApproval ? 'approved' : 'rejected', null, $actorId, null, $independent);
            if ($isApproval) {
                $conn->prepare("UPDATE changes SET approval_id = ? WHERE id = ?")->execute([(int)$round['id'], $changeId]);
            }
            // An emergency change may already be under way or done: its retrospective
            // decision is recorded without moving it back. Only an awaiting change moves.
            if ($target !== null && $row['status_stage'] === 'awaiting') {
                $conn->prepare(
                    "UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP()"
                    . ($isApproval ? ', approval_datetime = UTC_TIMESTAMP()' : '') . ' WHERE id = ?'
                )->execute([$target['id'], $changeId]);
                self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', $row['status_name'], $target['name']);
                $statusChanged = true;
                $newStatus = $target['name'];
            } elseif ($isApproval) {
                $conn->prepare("UPDATE changes SET approval_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$changeId]);
            }
            if ($isApproval) {
                $after[] = fn() => self::approvedDispatch($changeId, $row['title'] ?? null, $row['risk_level'] ?? null,
                    $row['approver_id'] !== null ? (int)$row['approver_id'] : null, 'cab');
            } else {
                $after[] = self::roundEvent($conn, 'change.rejected', self::rawRow($conn, $changeId), (int)$round['id'], $voteComment, $actorId);
            }
        }

        return [[
            'change_id'      => $changeId,
            'vote'           => $vote,
            'status_changed' => $statusChanged,
            'new_status'     => $newStatus,
            'counted'        => $counted,
            'was_current'    => $wasCurrent,
        ], $after];
    }

    // ======================================================================
    //  Incident (ticket) linking  — twin of ProblemsService::linkTicket
    // ======================================================================

    /**
     * Link an incident (ticket) to a change. Returns [change_id, ticket_id, ticket_number, linked].
     *
     * @param int $changeId  the change to link to
     * @param array $in  ticket_id (required) - the incident to link
     */
    public static function linkTicket(PDO $conn, ActorContext $ctx, int $changeId, array $in): array
    {
        self::assertCanWrite($conn, $ctx);
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

    /**
     * Unlink an incident from a change. 404 if not linked. Audited.
     *
     * @param int $changeId  the change to unlink from
     */
    public static function unlinkTicket(PDO $conn, ActorContext $ctx, int $changeId, int $ticketId): void
    {
        self::assertCanWrite($conn, $ctx);
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
    //  Attachments
    // ======================================================================

    /**
     * Store native evidence from either adapter, authorising before disk writes.
     * Reference uniqueness is per change and acting analyst (survives key rotation).
     * Reusing one requires the same filename and bytes. Owns its transaction:
     * a later caller rollback could not undo a file move. Never edits change content.
     * @return array{id:int,replayed:bool}
     */
    public static function uploadAttachment(PDO $conn, ActorContext $ctx, int $changeId, array $file, ?string $reference = null): array
    {
        self::assertCanWrite($conn, $ctx);
        if ($reference !== null && !preg_match('/\A[A-Za-z0-9._:-]{1,128}\z/D', $reference)) {
            throw new ServiceError('validation', 'invalid_field', 'external_reference must be 1-128 ASCII letters, digits, dots, underscores, colons or hyphens.');
        }
        if ($conn->inTransaction()) { throw new LogicException('Attachment uploads must own their transaction.'); }
        require_once __DIR__ . '/../uploads.php';
        $stored = null;
        $conn->beginTransaction();
        try {
            self::lockRow($conn, $changeId);
            self::loadJoined($conn, $ctx, $changeId);
            try {
                $stored = uploadStoreFile($file, dirname(__DIR__, 2) . '/change-management/attachments/' . $changeId);
            } catch (Exception $e) {
                throw new ServiceError('validation', 'invalid_file', $e->getMessage());
            }
            $sha256 = hash_file('sha256', $stored['path']);
            if ($sha256 === false) { throw new RuntimeException('Could not hash stored evidence.'); }
            if ($reference !== null) {
                $find = $conn->prepare('SELECT id, file_name, sha256 FROM change_attachments
                    WHERE change_id=? AND uploaded_by_id=? AND external_reference=?');
                $find->execute([$changeId, $ctx->actorId, $reference]);
                if ($existing = $find->fetch(PDO::FETCH_ASSOC)) {
                    if ($existing['file_name'] !== $stored['original_name'] || $existing['sha256'] !== $sha256) {
                        throw new ServiceError('conflict', 'reference_conflict', 'This external_reference already names different evidence.');
                    }
                    unlink($stored['path']); $stored = null;
                    $conn->commit();
                    return ['id'=>(int)$existing['id'], 'replayed'=>true];
                }
            }
            $conn->prepare('INSERT INTO change_attachments
                (change_id,file_name,file_path,file_size,file_type,uploaded_by_id,uploaded_datetime,sha256,external_reference)
                VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(),?,?)')->execute([
                $changeId,$stored['original_name'],$changeId . '/' . $stored['stored_name'],$stored['size'],
                $stored['mime'],$ctx->actorId,$sha256,$reference,
            ]);
            $id = (int)$conn->lastInsertId();
            self::auditAttachment($conn, $ctx, $changeId, true, $stored['original_name'], $sha256);
            $conn->commit();
            return ['id'=>$id, 'replayed'=>false];
        } catch (Throwable $e) {
            if ($conn->inTransaction()) { $conn->rollBack(); }
            if ($stored !== null) { @unlink($stored['path']); }
            throw $e;
        }
    }

    /**
     * Record that a file was attached to, or removed from, a change. Attachments are
     * evidence (the help page suggests attaching approval emails), so neither may
     * happen without a trace. The sha256 shows the file is the one that was attached.
     *
     * @param int $changeId  the change
     * @param bool $added  true for an upload, false for a delete
     * @param string $fileName  the name the person recognises
     * @param ?string $sha256  the file's sha256, when known
     */
    public static function auditAttachment(PDO $conn, ActorContext $ctx, int $changeId, bool $added, string $fileName, ?string $sha256): void
    {
        $label = $fileName . ($sha256 ? ' (sha256 ' . substr($sha256, 0, 12) . '…)' : '');
        self::auditWrite($conn, $changeId, $ctx->actorId, 'attachment', 'Attachment',
            $added ? null : $label, $added ? $label : null);
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Run $fn inside a transaction - or inside the caller's, when one is already open
     * (analyst offboarding moves many records in one go). Rolls back on ANY throwable,
     * so a failed save leaves neither the change nor its revisions half-written.
     *
     * @param callable $fn  the work; its return value is returned
     */
    private static function inTransaction(PDO $conn, callable $fn)
    {
        if ($conn->inTransaction()) {
            return $fn();
        }
        $conn->beginTransaction();
        try {
            $result = $fn();
            $conn->commit();
            return $result;
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Fire the events a write collected, once it has committed. TRAP: never inside the
     * transaction - a rolled-back change must not have sent its email.
     *
     * @param callable[] $after  closures returned by an apply*() method
     */
    private static function afterCommit(array $after): void
    {
        foreach ($after as $fn) {
            $fn();
        }
    }

    /**
     * The changes row as stored (no joins), for the approval rules.
     *
     * @param int $changeId  the change
     */
    private static function rawRow(PDO $conn, int $changeId): array
    {
        $s = $conn->prepare("SELECT * FROM changes WHERE id = ?");
        $s->execute([$changeId]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Read only access (#180 Part 3), in the service as well as the endpoints - so an API
     * key acting as an analyst who was given Read only cannot PATCH a change either, even
     * if the key holds changes.update. Only an explicit Read only refuses: an analyst
     * with no module access at all is not refused here, because the REST API has never
     * consulted it (F7) and starting to would be a separate decision.
     *
     * TRAP: not called by voteCab() or decideApproval(). Approving is identity, not a
     * write - a read-only approver must still be able to approve.
     */
    private static function assertCanWrite(PDO $conn, ActorContext $ctx): void
    {
        if ($ctx->actorId > 0 && analystModuleLevel($conn, $ctx->actorId, 'changes') === 'read') {
            throw new ServiceError('forbidden', 'read_only', 'You have read-only access to Change Management.');
        }
    }

    /**
     * Pre-authorise a standard change if its template allows it now (§8): record an approved
     * 'pre_authorised' round naming the template version and who approved it, stamp the
     * approval, and move a change entering awaiting on to the first approved status. Returns
     * the events to fire, or null when the template does not pre-authorise it - the change
     * then takes the normal route, and the page says why.
     *
     * @param int $changeId  the change, already saved
     * @param string $stage  the stage it is entering
     * @param int $actorId  who is submitting it (must be allowed to use the template)
     * @param array $cfg  changeApprovalSettings()
     */
    private static function preAuthorise(PDO $conn, int $changeId, string $stage, int $actorId, array $cfg): ?array
    {
        $row = self::rawRow($conn, $changeId);
        $chk = ChangeTemplates::check($conn, $row, $cfg, $actorId);
        if (!$chk['ok']) {
            return null;
        }
        $current = changeApprovalCurrent($conn, $changeId);
        if ($current) {
            changeApprovalClose($conn, (int)$current['id'], $current['state'] === 'open' ? 'withdrawn' : 'superseded', 'Pre-authorised by its template');
        }
        changeApprovalRecordApproved($conn, $row, 'pre_authorised', null, ChangeTemplates::decisionNote($chk['version']), $cfg);
        $conn->prepare("UPDATE changes SET approval_datetime = COALESCE(approval_datetime, UTC_TIMESTAMP()) WHERE id = ?")->execute([$changeId]);
        if ($stage === 'awaiting') {
            $target = changeStatusForStage($conn, 'approved');
            if ($target) {
                $from = self::statusName($conn, (int)$row['status_id']);
                $conn->prepare("UPDATE changes SET status_id = ?, modified_datetime = UTC_TIMESTAMP() WHERE id = ?")->execute([$target['id'], $changeId]);
                self::auditWrite($conn, $changeId, $actorId, 'status_change', 'Status', $from, $target['name']);
            }
        }
        self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'Template', null, ChangeTemplates::decisionNote($chk['version']));
        return [fn() => self::approvedDispatch($changeId, $row['title'] ?? null, $row['risk_level'] ?? null, null, 'pre_authorised')];
    }

    /**
     * The route a new round takes: 'emergency' for a type approved afterwards, otherwise
     * the board (cab), the named approver, or nobody yet (manual).
     *
     * @param array $row  the changes row
     */
    private static function routeFor(PDO $conn, array $row): string
    {
        return changeTypeIsEmergency($conn, $row) ? 'emergency' : changeApprovalRoute($row);
    }

    /**
     * Is a decision possible now? An open round, on a change awaiting approval - or an
     * emergency change, which may already be under way while its decision is pending.
     *
     * @param array $row  the joined change row
     * @param ?array $round  the current round
     */
    private static function roundAwaitsDecision(array $row, ?array $round): bool
    {
        return $round && $round['state'] === 'open'
            && ($row['status_stage'] === 'awaiting' || $round['route'] === 'emergency');
    }

    /**
     * The required CAB members of a change.
     *
     * @param int $changeId  the change
     * @return int[]
     */
    private static function requiredCabIds(PDO $conn, int $changeId): array
    {
        $s = $conn->prepare("SELECT analyst_id FROM change_cab_members WHERE change_id = ? AND is_required = 1");
        $s->execute([$changeId]);
        return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The acting analyst's display name, for the reasons written on a round.
     */
    private static function actorName(PDO $conn, ActorContext $ctx): string
    {
        if ($ctx->actorId > 0) {
            $s = $conn->prepare("SELECT full_name FROM analysts WHERE id = ?");
            $s->execute([$ctx->actorId]);
            $name = $s->fetchColumn();
            if ($name) return (string)$name;
        }
        return $ctx->actorName !== '' ? $ctx->actorName : 'System';
    }

    /**
     * A status's name by id.
     *
     * @param int $statusId  a change_statuses id
     */
    private static function statusName(PDO $conn, int $statusId): string
    {
        $s = $conn->prepare("SELECT name FROM change_statuses WHERE id = ?");
        $s->execute([$statusId]);
        return (string)($s->fetchColumn() ?: '');
    }

    /**
     * Clear the CAB members' votes for a new round (the member rows hold the current
     * round's vote only; the history is change_approval_votes).
     *
     * @param int $changeId  the change
     * @param int $actorId  who caused it, for the audit row
     */
    private static function clearCabVotes(PDO $conn, int $changeId, int $actorId): void
    {
        $clr = $conn->prepare(
            "UPDATE change_cab_members SET vote = NULL, vote_comment = NULL, vote_datetime = NULL
              WHERE change_id = ? AND vote IS NOT NULL"
        );
        $clr->execute([$changeId]);
        if ($clr->rowCount() > 0) {
            self::auditWrite($conn, $changeId, $actorId, 'cab_vote', 'CAB Vote', null,
                'Votes from the previous submission cleared (' . $clr->rowCount() . ')');
        }
    }

    /**
     * Where a rejection sends a change: the status chosen in Settings → Approvals, if it
     * is still active and in the Draft or Stopped stage, else the first Draft-stage one.
     *
     * @param array $cfg  changeApprovalSettings()
     */
    private static function rejectTarget(PDO $conn, array $cfg): ?array
    {
        if ($cfg['reject_status_id']) {
            $s = $conn->prepare("SELECT id, name FROM change_statuses WHERE id = ? AND is_active = 1 AND stage IN ('draft', 'stopped')");
            $s->execute([$cfg['reject_status_id']]);
            $r = $s->fetch(PDO::FETCH_ASSOC);
            if ($r) return ['id' => (int)$r['id'], 'name' => $r['name']];
        }
        return changeStatusForStage($conn, 'draft');
    }

    /**
     * Does this analyst's vote count? Always in Record mode (the round is marked not
     * independent instead); in Enforce mode the change's own people are recorded but
     * not counted, unless Settings allows approving your own change.
     *
     * @param array $row  the changes row
     * @param int $analystId  the voter
     * @param array $cfg  changeApprovalSettings()
     */
    private static function voteCounts(array $row, int $analystId, array $cfg): bool
    {
        return !$cfg['enforce'] || $cfg['own_allowed'] || changeApprovalIsIndependent($row, $analystId, $cfg);
    }

    /**
     * Refuse a decision by one of the change's own people where independence is
     * enforced (Enforce mode, "Approving your own change: Not allowed").
     *
     * @param array $row  the changes row
     * @param int $analystId  who is deciding
     * @param array $cfg  changeApprovalSettings()
     */
    private static function guardIndependence(array $row, int $analystId, array $cfg): void
    {
        if ($cfg['enforce'] && !$cfg['own_allowed'] && !changeApprovalIsIndependent($row, $analystId, $cfg)) {
            throw new ServiceError('forbidden', 'not_independent', t('change-management.approval.not_independent'));
        }
    }

    /**
     * The labels of the material fields that differ between two versions of a change,
     * for the reason written on a superseded round ("Test plan, Rollback plan edited by Sam").
     *
     * @param array $before  the row before the save
     * @param array $after  the row after it
     * @param array $cfg  changeApprovalSettings()
     */
    private static function materialChanged(array $before, array $after, array $cfg): array
    {
        $labels = [
            'title' => 'Title', 'description' => 'Description', 'reason_for_change' => 'Reason for change',
            'risk_evaluation' => 'Risk evaluation', 'risk_likelihood' => 'Risk likelihood', 'risk_impact_score' => 'Risk impact',
            'test_plan' => 'Test plan', 'rollback_plan' => 'Rollback plan', 'change_type_id' => 'Type', 'impact_id' => 'Impact',
            'priority_id' => 'Priority', 'assigned_to_id' => 'Assigned to', 'work_window' => 'Work window', 'outage_window' => 'Outage window',
        ];
        $a = changeApprovalMaterialValues($before, $cfg['material']);
        $b = changeApprovalMaterialValues($after, $cfg['material']);
        $out = [];
        foreach ($b as $k => $v) {
            if (($a[$k] ?? null) !== $v) $out[] = $labels[$k] ?? $k;
        }
        return $out ?: ['Content'];
    }

    /**
     * A round event for the workflow engine, as a closure to fire after the commit.
     * notify_ids is who should look: the required CAB members, or the named approver.
     *
     * @param string $event  change.submitted | change.resubmitted | change.rejected | change.approval_withdrawn
     * @param array $row  the changes row
     * @param int $roundId  the round
     * @param ?string $reason  why, in words
     * @param ?int $decidedBy  who decided (change.rejected)
     */
    private static function roundEvent(PDO $conn, string $event, array $row, int $roundId, ?string $reason = null, ?int $decidedBy = null): callable
    {
        $r = $conn->prepare("SELECT round_no, route FROM change_approvals WHERE id = ?");
        $r->execute([$roundId]);
        $round = $r->fetch(PDO::FETCH_ASSOC) ?: ['round_no' => null, 'route' => null];
        $m = $conn->prepare("SELECT analyst_id FROM change_cab_members WHERE change_id = ? AND is_required = 1");
        $m->execute([(int)$row['id']]);
        $notify = !empty($row['cab_required']) ? array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN))
                                                 : (!empty($row['approver_id']) ? [(int)$row['approver_id']] : []);
        $payload = [
            'change'     => ['id' => (int)$row['id'], 'title' => $row['title'] ?? null, 'risk' => $row['risk_level'] ?? null],
            'round'      => ['id' => $roundId, 'no' => $round['round_no'] !== null ? (int)$round['round_no'] : null, 'route' => $round['route']],
            'reason'     => $reason,
            'notify_ids' => $notify,
        ];
        if ($decidedBy !== null) {
            $payload['decided_by'] = ['id' => $decidedBy];
        }
        return function () use ($event, $payload) {
            try {
                WorkflowEngine::dispatch($event, $payload);
            } catch (Exception $wfEx) {
                error_log('Workflow dispatch error in ChangesService (' . $event . '): ' . $wfEx->getMessage());
            }
        };
    }

    /**
     * Take the change's row lock for the rest of the transaction, so two writes to the
     * same change happen one after the other. A missing row locks nothing;
     * loadJoined() then reports the 404.
     *
     * @param int $changeId  the change
     */
    private static function lockRow(PDO $conn, int $changeId): void
    {
        $conn->prepare("SELECT id FROM changes WHERE id = ? FOR UPDATE")->execute([$changeId]);
    }

    /**
     * May the actor access this row of a tenant-scoped table (by its company)?
     * Generic mirror of ProblemsService::canAccessTenantRow. $table is a
     * developer literal, never user input.
     *
     * @param string $table  a tenant-scoped table name written in code - never user input
     * @param int $rowId  the row's id in that table
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
     *
     * @param int $changeId  the change to load
     */
    private static function loadJoined(PDO $conn, ActorContext $ctx, int $changeId): array
    {
        $stmt = $conn->prepare(
            "SELECT c.*,
                    ct.name AS type_name,
                    cs.name AS status_name, cs.stage AS status_stage, cs.is_closed AS status_is_closed,
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

    /**
     * Records one action or field change in the change's audit trail.
     *
     * @param int $changeId  the change the entry belongs to
     * @param string $actionType  change_audit.action_type, e.g. 'field_change', 'status_change', 'comment', 'cab_vote'
     * @param ?string $field  the field label shown in the trail, e.g. 'Status'; null for a comment
     * @param ?string $old  the value before, as shown
     * @param ?string $new  the value after, as shown
     */
    private static function auditWrite(PDO $conn, int $changeId, int $analystId, string $actionType, ?string $field, ?string $old, ?string $new): void
    {
        $conn->prepare(
            "INSERT INTO change_audit (change_id, analyst_id, action_type, field_name, old_value, new_value, created_datetime)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([$changeId, $analystId, $actionType, $field, $old, $new]);
    }

    /**
     * The risk banding (score = likelihood × impact).
     *
     * @param ?int $score  likelihood x impact (1-25), or null when either is unset
     */
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
     *
     * @param array $in  the request fields
     * @param string $key  the input key, e.g. 'status' - '<key>_id' takes an id, '<key>' a name
     * @param string $table  the lookup table, written in code - never user input
     * @param string $extraCol  a further integer column to return as the third element (e.g. 'is_closed'); '' for none
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

    /**
     * The default row of a change lookup table: [id, name] or [null, null].
     *
     * This one never had the GH #79 name-lookup fault, but it shared the narrower
     * half: a default that has since been deactivated is absent from the dropdown,
     * so selecting it produces a change set to something nobody can choose. Same
     * ordering as the tickets fix and includes/services/tasks.php — is_active
     * filters, and falling back through display_order means there is always an
     * answer as long as one active row exists.
     *
     * @param string $table  the lookup table, written in code - never user input
     */
    private static function lookupDefault(PDO $conn, string $table): array
    {
        $row = $conn->query(
            "SELECT id, name FROM `$table` WHERE is_active = 1
             ORDER BY is_default DESC, display_order, id LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        return $row ? [(int)$row['id'], $row['name']] : [null, null];
    }

    /**
     * The stage of a status (draft/awaiting/approved/implementing/done/stopped), or
     * null when it has not been classified. See includes/changes/approvals.php.
     *
     * @param ?int $statusId  a change_statuses id
     */
    private static function stageOf(PDO $conn, ?int $statusId): ?string
    {
        if ($statusId === null) {
            return null;
        }
        $s = $conn->prepare("SELECT stage FROM change_statuses WHERE id = ?");
        $s->execute([$statusId]);
        $stage = $s->fetchColumn();
        return changeStageIsValid($stage) ? $stage : null;
    }

    /**
     * Validate a 1-5 risk input. Null/'' clears.
     *
     * @param mixed $value  the 1-5 score from the request; null or '' gives null
     * @param string $field  the request field name, used in the error message
     */
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

    /**
     * Resolve an analyst id to its name; 422 if unknown/inactive (mirrors apiResolveAnalyst).
     *
     * @param int $analystId  the analyst to look up (a requester, assignee, approver or CAB member) - not the actor
     */
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

    /**
     * Parse a date to UTC 'Y-m-d H:i:s' (mirrors the API's apiParseDate; 400 on garbage).
     *
     * @param string $value  the date or date-time from the request; read as UTC unless it carries an offset
     * @param string $field  the request field name, used in the error message
     */
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

    /**
     * Fire change.approved (best-effort; the engine swallows its own errors).
     *
     * @param int $changeId  the change that was approved
     * @param ?string $title  the change's title
     * @param ?string $riskLevel  the change's risk level, e.g. 'High'
     * @param ?int $approverId  the change's named approver, or null
     * @param string $route  how it was approved (the round's route)
     */
    private static function approvedDispatch(int $changeId, ?string $title, ?string $riskLevel, ?int $approverId, string $route = 'cab'): void
    {
        try {
            WorkflowEngine::dispatch('change.approved', [
                'change'   => ['id' => $changeId, 'title' => $title, 'risk' => $riskLevel],
                'approver' => ['id' => $approverId],
                'round'    => ['route' => $route],   // cab | approver | manual | override | pre_authorised - so a rule can tell them apart
            ]);
        } catch (Exception $wfEx) {
            error_log('Workflow dispatch error in ChangesService: ' . $wfEx->getMessage());
        }
    }
}
