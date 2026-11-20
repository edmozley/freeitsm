<?php
/**
 * MorningChecksService — the single home for the morning-checks module's write
 * rules (checks, daily results, and the UI-only statuses / reorder / normalise).
 *
 * Shared by the UI endpoints (api/morning-checks/*.php) and the REST API
 * (api/v1/resources/morning_checks.php). Each caller passes an ActorContext and
 * canonical input; this layer validates + writes and returns the affected id(s)
 * or throws ServiceError. It never emits HTTP.
 *
 * Canonical behaviour = the API resource's (see docs/design/service-layer.md):
 * strict date validation (422, not silent-substitute-today), unknown check id
 * is a 422 (not a raw FK 500), and results are attributed to the acting analyst
 * (the old UI left CreatedBy NULL). Timestamps are UTC; CheckDate stays a bare
 * server-local date (the module is a daily ritual).
 *
 * Input keys are canonical snake_case (name, description, sort_order, is_active,
 * check_id, status_id/status, date). UI adapters map their camelCase to these.
 */

require_once __DIR__ . '/../service_context.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
require_once dirname(__DIR__) . '/morning_check_schedule.php';   // GH #383

class MorningChecksService
{
    // ======================================================================
    //  Checks
    // ======================================================================

    /**
     * Create (no id) or update (id present) a check. Returns the id.
     *
     * @param array $in  id (absent = new), name, description, sort_order, is_active, group_id, assigned_analyst_id - null or '' clears;
     *                   schedule { type, days, month_when, month_day } (GH #383; absent = unchanged, null = every day)
     */
    public static function saveCheck(PDO $conn, ActorContext $ctx, array $in): int
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadCheckRow($conn, $id);              // 404 if gone (before empty-body, as the API did)
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $name = array_key_exists('name', $in) ? trim((string)$in['name']) : $current['CheckName'];
            if ($name === '') {
                throw new ServiceError('validation', 'invalid_field', "'name' cannot be empty.");
            }
            // Grouping and routing (discussion #64). array_key_exists rather than
            // isset throughout, because clearing an assignment means sending null
            // — and isset() would read that as "not mentioned, leave it alone",
            // making it impossible to un-assign a check once assigned.
            $conn->prepare(
                "UPDATE morningChecks_Checks
                 SET CheckName = ?, CheckDescription = ?, SortOrder = ?, IsActive = ?,
                     GroupID = ?, AssignedAnalystID = ?,
                     ScheduleType = ?, ScheduleDays = ?, ScheduleMonthWhen = ?, ScheduleMonthDay = ?,
                     ModifiedDate = UTC_TIMESTAMP()
                 WHERE CheckID = ?"
            )->execute([
                $name,
                array_key_exists('description', $in) ? trim((string)$in['description']) : $current['CheckDescription'],
                array_key_exists('sort_order', $in) ? (int)$in['sort_order'] : (int)$current['SortOrder'],
                array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : (int)$current['IsActive'],
                array_key_exists('group_id', $in)
                    ? self::assertExists($conn, self::nullableId($in['group_id']), 'morningChecks_Groups', 'GroupID', 'group_id')
                    : ($current['GroupID'] ?? null),
                array_key_exists('assigned_analyst_id', $in)
                    ? self::assertExists($conn, self::nullableId($in['assigned_analyst_id']), 'analysts', 'id', 'assigned_analyst_id')
                    : ($current['AssignedAnalystID'] ?? null),
                ...array_values(array_key_exists('schedule', $in) ? self::scheduleColumns($in['schedule']) : [
                    $current['ScheduleType'] ?? null, $current['ScheduleDays'] ?? null,
                    $current['ScheduleMonthWhen'] ?? null, $current['ScheduleMonthDay'] ?? null,
                ]),
                $id,
            ]);
            WorkflowEngine::emitCrud('morning_check', 'updated', $id, $name);
            return $id;
        }

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', "'name' is required.");
        }
        $conn->prepare(
            "INSERT INTO morningChecks_Checks (CheckName, CheckDescription, SortOrder, IsActive, GroupID, AssignedAnalystID,
                                               ScheduleType, ScheduleDays, ScheduleMonthWhen, ScheduleMonthDay, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $name,
            trim((string)($in['description'] ?? '')),
            isset($in['sort_order']) ? (int)$in['sort_order'] : 0,
            isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
            self::assertExists($conn, self::nullableId($in['group_id'] ?? null), 'morningChecks_Groups', 'GroupID', 'group_id'),
            self::assertExists($conn, self::nullableId($in['assigned_analyst_id'] ?? null), 'analysts', 'id', 'assigned_analyst_id'),
            ...array_values(self::scheduleColumns($in['schedule'] ?? null)),
        ]);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::emitCrud('morning_check', 'created', $newId, $name);
        return $newId;
    }

    /**
     * A schedule from a request as its four columns (GH #383), or a validation error the
     * caller shows as it is. null or type 'daily' = every day.
     *
     * @param mixed $schedule  { type, days, month_when, month_day }
     */
    private static function scheduleColumns($schedule): array
    {
        if ($schedule !== null && !is_array($schedule)) {
            throw new ServiceError('validation', 'invalid_field', "'schedule' must be an object: { type, days, month_when, month_day }.");
        }
        try {
            return MorningCheckSchedule::columns($schedule);
        } catch (InvalidArgumentException $e) {
            throw new ServiceError('validation', 'invalid_field', $e->getMessage());
        }
    }

    /**
     * "" and 0 and "0" all mean "no assignment"; anything else is an id.
     *
     * A `<select>` with a blank option posts "", and casting that to int gives 0,
     * which would be stored as a foreign key to analyst zero. Every assignment
     * field goes through here so there is one answer rather than five.
     *
     * @param mixed $value  an id from the request; null, '', 0 or '0' means none
     */
    private static function nullableId($value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $i = (int)$value;
        return $i > 0 ? $i : null;
    }

    /**
     * Reject an id that points at nothing.
     *
     * db_verify does not create foreign keys (they live in freeitsm.sql), so an
     * upgraded install has no database-level guard here. That matters more than
     * usual for GroupID: the dashboard only shows a grouped check when its group
     * is active, so a check pointing at a group that does not exist disappears
     * from the round entirely — silently, and with nothing on screen to explain it.
     *
     * @param ?int $id  the id to check; null passes straight through
     * @param string $table  the table it should be in, written in code - never user input
     * @param string $idCol  that table's id column, written in code
     * @param string $field  the request field name, used in the error message
     */
    private static function assertExists(PDO $conn, ?int $id, string $table, string $idCol, string $field): ?int
    {
        if ($id === null) {
            return null;
        }
        $stmt = $conn->prepare("SELECT 1 FROM `$table` WHERE `$idCol` = ?");
        $stmt->execute([$id]);
        if (!$stmt->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "'$field' does not exist.");
        }
        return $id;
    }

    // ======================================================================
    //  Groups (discussion #64)
    //
    //  ⚠️ GROUPING AND ASSIGNMENT ARE PRESENTATION, NOT PERMISSION. Nothing in
    //  recordResult() consults either, and that is deliberate rather than an
    //  oversight: the request was for ownership and routing, and the morning
    //  somebody is off sick is exactly when the round still has to be done by
    //  whoever is in. Assignment drives a heading, a label and a filter.
    //
    //  The same shape as collision detection, which warns and never blocks.
    // ======================================================================

    /**
     * Groups with their routing, plus how many active checks each holds.
     *
     * @param bool $activeOnly  true to leave out groups that are switched off
     */
    public static function listGroups(PDO $conn, bool $activeOnly = false): array
    {
        $sql = "SELECT g.GroupID, g.GroupName, g.GroupDescription, g.IsActive, g.SortOrder,
                       g.AssignedTeamID, g.AssignedAnalystID,
                       t.name      AS TeamName,
                       a.full_name AS AnalystName,
                       (SELECT COUNT(*) FROM morningChecks_Checks c
                         WHERE c.GroupID = g.GroupID AND c.IsActive = 1) AS CheckCount
                  FROM morningChecks_Groups g
                  LEFT JOIN teams    t ON t.id = g.AssignedTeamID
                  LEFT JOIN analysts a ON a.id = g.AssignedAnalystID";
        if ($activeOnly) {
            $sql .= " WHERE g.IsActive = 1";
        }
        $sql .= " ORDER BY g.SortOrder, g.GroupName";
        return $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Adds or updates a morning check group, including the team or analyst it routes to,
     * and returns its id.
     *
     * @param array $in  id (absent = new), name, description, assigned_team_id, assigned_analyst_id, is_active, sort_order
     */
    public static function saveGroup(PDO $conn, ActorContext $ctx, array $in): int
    {
        $name = trim((string)($in['name'] ?? ''));
        if ($name === '' && empty($in['id'])) {
            throw new ServiceError('validation', 'missing_field', "'name' is required.");
        }

        // A group routes to a team OR an analyst. Both at once is not an error
        // worth refusing — the resolver prefers the analyst, being the more
        // specific of the two — but it is worth not pretending otherwise.
        $teamId    = self::nullableId($in['assigned_team_id'] ?? null);
        $analystId = self::nullableId($in['assigned_analyst_id'] ?? null);

        if (!empty($in['id'])) {
            $id  = (int)$in['id'];
            $cur = $conn->prepare("SELECT * FROM morningChecks_Groups WHERE GroupID = ?");
            $cur->execute([$id]);
            $current = $cur->fetch(PDO::FETCH_ASSOC);
            if (!$current) {
                throw new ServiceError('not_found', 'not_found', 'That group no longer exists.');
            }
            $conn->prepare(
                "UPDATE morningChecks_Groups
                    SET GroupName = ?, GroupDescription = ?, AssignedTeamID = ?, AssignedAnalystID = ?,
                        IsActive = ?, SortOrder = ?, ModifiedDate = UTC_TIMESTAMP()
                  WHERE GroupID = ?"
            )->execute([
                $name !== '' ? $name : $current['GroupName'],
                array_key_exists('description', $in) ? trim((string)$in['description']) : $current['GroupDescription'],
                array_key_exists('assigned_team_id', $in)    ? $teamId    : ($current['AssignedTeamID'] ?? null),
                array_key_exists('assigned_analyst_id', $in) ? $analystId : ($current['AssignedAnalystID'] ?? null),
                array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : (int)$current['IsActive'],
                array_key_exists('sort_order', $in) ? (int)$in['sort_order'] : (int)$current['SortOrder'],
                $id,
            ]);
            return $id;
        }

        $conn->prepare(
            "INSERT INTO morningChecks_Groups (GroupName, GroupDescription, AssignedTeamID, AssignedAnalystID, IsActive, SortOrder, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $name,
            trim((string)($in['description'] ?? '')),
            $teamId,
            $analystId,
            isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
            isset($in['sort_order']) ? (int)$in['sort_order'] : 0,
        ]);
        return (int)$conn->lastInsertId();
    }

    /**
     * Delete a group. Its checks are NOT deleted — they fall back to ungrouped.
     *
     * ⚠️ Deleting a grouping should never delete the work. The FK is
     * ON DELETE SET NULL and this is stated here as well because "delete group"
     * is exactly the button somebody presses expecting only the grouping to go.
     *
     * @param int $id  the morningChecks_Groups row to delete; its checks become ungrouped
     */
    public static function deleteGroup(PDO $conn, ActorContext $ctx, int $id): int
    {
        $n = $conn->prepare("SELECT COUNT(*) FROM morningChecks_Checks WHERE GroupID = ?");
        $n->execute([$id]);
        $orphaned = (int)$n->fetchColumn();

        $conn->prepare("UPDATE morningChecks_Checks SET GroupID = NULL WHERE GroupID = ?")->execute([$id]);
        $conn->prepare("DELETE FROM morningChecks_Groups WHERE GroupID = ?")->execute([$id]);
        return $orphaned;
    }

    /**
     * Who is a check routed to? Returns [analystId, label, source] — all null
     * when nobody in particular, which is the default.
     *
     * Order of precedence, most specific first:
     *   1. the check's own analyst
     *   2. its group's analyst
     *   3. its group's team
     *
     * A team is a label rather than a single person, so `analystId` stays null
     * for it — "is this mine?" then means "am I in that team?", which the
     * dashboard answers from the analyst's own team membership.
     *
     * @param array $checkRow  a check row joined to its group, with AssignedAnalystID, CheckAnalystName, GroupAnalystID,
     *     GroupAnalystName, GroupTeamID and GroupTeamName
     */
    public static function resolveAssignment(array $checkRow): array
    {
        if (!empty($checkRow['AssignedAnalystID'])) {
            return [(int)$checkRow['AssignedAnalystID'], $checkRow['CheckAnalystName'] ?? null, 'check'];
        }
        if (!empty($checkRow['GroupAnalystID'])) {
            return [(int)$checkRow['GroupAnalystID'], $checkRow['GroupAnalystName'] ?? null, 'group'];
        }
        if (!empty($checkRow['GroupTeamID'])) {
            return [null, $checkRow['GroupTeamName'] ?? null, 'team'];
        }
        return [null, null, null];
    }

    /**
     * Delete a check + its results, atomically.
     *
     * @param int $id  the check to delete, with its results
     */
    public static function deleteCheck(PDO $conn, ActorContext $ctx, int $id): void
    {
        $row = self::loadCheckRow($conn, $id);                     // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM morningChecks_Results WHERE CheckID = ?")->execute([$id]);
            $conn->prepare("DELETE FROM morningChecks_Checks WHERE CheckID = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('morning_check', 'deleted', $id, $row['CheckName'] ?? null);
    }

    /**
     * Loads one morning check; throws not found when it does not exist.
     *
     * @param int $id  the check to load
     */
    private static function loadCheckRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM morningChecks_Checks WHERE CheckID = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Check not found.');
        return $row;
    }

    // ======================================================================
    //  Results (upsert: one result per check per date)
    // ======================================================================

    /**
     * Record (upsert) a result. Returns ['id' => int, 'created' => bool]
     * (created = a new row for that check+date; false = overwrite).
     *
     * @param array $in  check_id (required), status_id or status (a label), notes, date (Y-m-d, default today)
     */
    public static function recordResult(PDO $conn, ActorContext $ctx, array $in): array
    {
        $checkId = isset($in['check_id']) ? (int)$in['check_id'] : 0;
        if ($checkId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'check_id' is required.");
        }
        $check = $conn->prepare("SELECT CheckName FROM morningChecks_Checks WHERE CheckID = ?");
        $check->execute([$checkId]);
        $checkName = $check->fetchColumn();
        if ($checkName === false) {
            throw new ServiceError('validation', 'invalid_field', "Unknown check id: {$checkId}");
        }

        // Status by id or label — must exist and be active (the dashboard's rule).
        if (isset($in['status_id']) && $in['status_id'] !== '' && $in['status_id'] !== null) {
            $stmt = $conn->prepare("SELECT StatusID, Label, RequiresNotes FROM morningChecks_Statuses WHERE StatusID = ? AND IsActive = 1");
            $stmt->execute([(int)$in['status_id']]);
        } elseif (isset($in['status']) && trim((string)$in['status']) !== '') {
            $stmt = $conn->prepare("SELECT StatusID, Label, RequiresNotes FROM morningChecks_Statuses WHERE Label = ? AND IsActive = 1");
            $stmt->execute([trim((string)$in['status'])]);
        } else {
            throw new ServiceError('validation', 'missing_field', "'status' (label) or 'status_id' is required.");
        }
        $status = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$status) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive status: ' . ($in['status'] ?? $in['status_id']));
        }

        $notes = trim((string)($in['notes'] ?? ''));
        if ((int)$status['RequiresNotes'] === 1 && $notes === '') {
            throw new ServiceError('validation', 'missing_field', "Notes are required for the '{$status['Label']}' status.");
        }

        $date = isset($in['date']) && trim((string)$in['date']) !== ''
            ? self::validateDate((string)$in['date'], 'date')
            : date('Y-m-d');

        $existing = $conn->prepare("SELECT ResultID FROM morningChecks_Results WHERE CheckID = ? AND CheckDate = ?");
        $existing->execute([$checkId, $date]);
        $resultId = $existing->fetchColumn();

        if ($resultId !== false) {
            // Overwrite — StatusID is the source of truth, clear the legacy label snapshot.
            // ⚠️ ModifiedBy is stamped on the overwrite as well as the insert.
            // Without it, whoever set the status FIRST was credited forever —
            // so an analyst covering for an absent colleague did the check and
            // the board still thanked the colleague. CreatedBy is deliberately
            // left alone: it means "who first recorded a result today", and the
            // v1 API publishes it under that meaning.
            $conn->prepare(
                "UPDATE morningChecks_Results
                 SET StatusID = ?, Status = NULL, Notes = ?, ModifiedBy = ?, ModifiedDate = UTC_TIMESTAMP()
                 WHERE ResultID = ?"
            )->execute([(int)$status['StatusID'], $notes, ($ctx->actorName !== '' ? $ctx->actorName : null), (int)$resultId]);
            self::recordedDispatch($checkId, $checkName, (int)$status['StatusID'], $status['Label'], $date);
            return ['id' => (int)$resultId, 'created' => false];
        }

        $actor = $ctx->actorName !== '' ? $ctx->actorName : null;
        $conn->prepare(
            "INSERT INTO morningChecks_Results (CheckID, CheckDate, StatusID, Status, Notes, CreatedBy, ModifiedBy, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, NULL, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$checkId, $date, (int)$status['StatusID'], $notes, $actor, $actor]);
        $newId = (int)$conn->lastInsertId();
        self::recordedDispatch($checkId, $checkName, (int)$status['StatusID'], $status['Label'], $date);
        return ['id' => $newId, 'created' => true];
    }

    /**
     * Put a check back to "not checked" for a date (discussion #64).
     *
     * ⚠️ This DELETES the result row rather than setting its status to null.
     * "Not checked" is the absence of a result — it is what every check looks
     * like before anyone touches it — so a row with a null status would be a
     * second, subtly different way of saying the same thing, and every reader
     * (the dashboard, the chart, the PDF, the API) would have to learn it.
     *
     * The consequence is honest and worth stating: clearing a mistake also
     * discards the note and the attribution that came with it. That is the right
     * trade for "I clicked green by accident" — the alternative is a half-state
     * that shows as unchecked but still credits somebody.
     *
     * Any tickets or tasks raised from that result go too, via ON DELETE CASCADE
     * on morningChecks_ResultLinks — but the tickets themselves are untouched,
     * because raising a ticket is not undone by correcting a click.
     *
     * @param int $checkId  the check to put back to 'not checked'
     * @param ?string $date  the day, Y-m-d; null means today
     */
    public static function clearResult(PDO $conn, ActorContext $ctx, int $checkId, ?string $date = null): bool
    {
        $date = $date !== null ? self::validateDate($date, 'date') : date('Y-m-d');

        $stmt = $conn->prepare("DELETE FROM morningChecks_Results WHERE CheckID = ? AND CheckDate = ?");
        $stmt->execute([$checkId, $date]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Fire morning_check.recorded (best-effort).
     *
     * @param int $checkId  the check
     * @param ?string $checkName  the check's name
     * @param int $statusId  the status recorded
     * @param ?string $statusName  that status's label
     * @param string $date  the day the result is for, Y-m-d
     */
    private static function recordedDispatch(int $checkId, ?string $checkName, int $statusId, ?string $statusName, string $date): void
    {
        WorkflowEngine::dispatch('morning_check.recorded', [
            'check'  => ['id' => $checkId, 'name' => $checkName],
            'result' => ['status_id' => $statusId, 'status_name' => $statusName, 'date' => $date],
        ]);
    }

    /**
     * Strict YYYY-MM-DD — 422 on garbage (the old UI silently substituted today).
     *
     * @param string $value  the date from the request, Y-m-d
     * @param string $field  the request field name, used in the error message
     */
    private static function validateDate(string $value, string $field): string
    {
        $v = trim($value);
        $dt = DateTime::createFromFormat('Y-m-d', $v);
        if (!$dt || $dt->format('Y-m-d') !== $v) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return $v;
    }

    // ======================================================================
    //  Statuses (UI-only — the API only lists them)
    // ======================================================================

    /**
     * Create (id null) or update (id present) a status. Returns the id.
     *
     * @param array $in  id (null or absent = new), label (up to 50), colour (#rrggbb), requires_notes, is_active, sort_order
     */
    public static function saveStatus(PDO $conn, ActorContext $ctx, array $in): int
    {
        $id            = isset($in['id']) && $in['id'] !== null ? (int)$in['id'] : null;
        $label         = isset($in['label']) ? trim((string)$in['label']) : '';
        $colour        = isset($in['colour']) ? trim((string)$in['colour']) : '';
        $requiresNotes = !empty($in['requires_notes']) ? 1 : 0;
        $isActive      = isset($in['is_active']) ? (!empty($in['is_active']) ? 1 : 0) : 1;
        $sortOrder     = isset($in['sort_order']) ? (int)$in['sort_order'] : null;

        if ($label === '')                              throw new ServiceError('validation', 'missing_field', 'Label is required');
        if (mb_strlen($label) > 50)                     throw new ServiceError('validation', 'invalid_field', 'Label too long (max 50 chars)');
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) throw new ServiceError('validation', 'invalid_field', 'Colour must be a #rrggbb hex value');

        if ($id === null) {
            if ($sortOrder === null) {
                $sortOrder = (int)$conn->query("SELECT COALESCE(MAX(SortOrder), 0) + 10 FROM morningChecks_Statuses")->fetchColumn();
            }
            $conn->prepare(
                "INSERT INTO morningChecks_Statuses (Label, Colour, RequiresNotes, SortOrder, IsActive, CreatedDate, ModifiedDate)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
            )->execute([$label, $colour, $requiresNotes, $sortOrder, $isActive]);
            $newId = (int)$conn->lastInsertId();
            WorkflowEngine::emitCrud('morning_check_status', 'created', $newId, $label);
            return $newId;
        }

        if ($sortOrder === null) {
            $sortStmt = $conn->prepare("SELECT SortOrder FROM morningChecks_Statuses WHERE StatusID = ?");
            $sortStmt->execute([$id]);
            $sortRow = $sortStmt->fetchColumn();
            if ($sortRow === false) throw new ServiceError('not_found', 'not_found', 'Status not found');
            $sortOrder = (int)$sortRow;
        }
        $conn->prepare(
            "UPDATE morningChecks_Statuses
             SET Label = ?, Colour = ?, RequiresNotes = ?, SortOrder = ?, IsActive = ?, ModifiedDate = UTC_TIMESTAMP()
             WHERE StatusID = ?"
        )->execute([$label, $colour, $requiresNotes, $sortOrder, $isActive, $id]);
        WorkflowEngine::emitCrud('morning_check_status', 'updated', $id, $label);
        return $id;
    }

    /**
     * Delete a status; snapshot its label onto affected results (turning them
     * into orphans) first. Returns ['deleted' => int, 'orphaned' => int].
     *
     * @param int $id  the morningChecks_Statuses row to delete
     */
    public static function deleteStatus(PDO $conn, ActorContext $ctx, int $id): array
    {
        if ($id <= 0) throw new ServiceError('validation', 'missing_field', 'Status ID is required');

        $conn->beginTransaction();
        try {
            $labelStmt = $conn->prepare("SELECT Label FROM morningChecks_Statuses WHERE StatusID = ?");
            $labelStmt->execute([$id]);
            $label = $labelStmt->fetchColumn();

            $orphaned = 0;
            if ($label !== false && $label !== null) {
                $snap = $conn->prepare("UPDATE morningChecks_Results SET Status = ? WHERE StatusID = ?");
                $snap->execute([$label, $id]);
                $orphaned = $snap->rowCount();
            }
            $del = $conn->prepare("DELETE FROM morningChecks_Statuses WHERE StatusID = ?");
            $del->execute([$id]);
            $deleted = $del->rowCount();
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        if ($deleted > 0) {
            WorkflowEngine::emitCrud('morning_check_status', 'deleted', $id, ($label !== false && $label !== null) ? (string)$label : null);
        }
        return ['deleted' => $deleted, 'orphaned' => $orphaned];
    }

    // ======================================================================
    //  Reorder + normalise (UI-only)
    // ======================================================================

    /**
     * Reorder checks: SortOrder becomes the array index (matches reorder_checks.php).
     *
     * @param array $order  every check id, top first; each check's SortOrder becomes its position
     */
    public static function reorderChecks(PDO $conn, ActorContext $ctx, array $order): void
    {
        $stmt = $conn->prepare("UPDATE morningChecks_Checks SET SortOrder = ?, ModifiedDate = UTC_TIMESTAMP() WHERE CheckID = ?");
        foreach ($order as $index => $checkId) {
            $stmt->execute([(int)$index, (int)$checkId]);
        }
    }

    /**
     * Reorder statuses: positions become 10, 20, 30, … (matches reorder_statuses.php), transactional.
     *
     * @param array $order  every status id, top first; SortOrder becomes 10, 20, 30...
     */
    public static function reorderStatuses(PDO $conn, ActorContext $ctx, array $order): void
    {
        $conn->beginTransaction();
        try {
            $upd = $conn->prepare("UPDATE morningChecks_Statuses SET SortOrder = ?, ModifiedDate = UTC_TIMESTAMP() WHERE StatusID = ?");
            foreach ($order as $i => $sid) {
                $upd->execute([($i + 1) * 10, (int)$sid]);
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Map orphan label strings to target StatusIDs. $mappings = [{label, statusId}, …].
     * Returns the total rows updated. Transactional; every target must be active.
     *
     * @param array $mappings  [{label, statusId}] - each old label and the active status its results should now use
     */
    public static function normaliseStatuses(PDO $conn, ActorContext $ctx, array $mappings): int
    {
        $conn->beginTransaction();
        try {
            $valid = $conn->query("SELECT StatusID FROM morningChecks_Statuses WHERE IsActive = 1")->fetchAll(PDO::FETCH_COLUMN);
            $validSet = array_flip(array_map('intval', $valid));

            $upd = $conn->prepare(
                "UPDATE morningChecks_Results
                 SET StatusID = ?, Status = NULL, ModifiedDate = UTC_TIMESTAMP()
                 WHERE StatusID IS NULL AND Status = ?"
            );
            $totalUpdated = 0;
            foreach ($mappings as $m) {
                $label = isset($m['label']) ? (string)$m['label'] : '';
                $sid   = isset($m['statusId']) ? (int)$m['statusId'] : 0;
                if ($label === '' || $sid <= 0) continue;
                if (!isset($validSet[$sid])) {
                    throw new ServiceError('validation', 'invalid_field', 'Invalid target StatusID ' . $sid . ' for label "' . $label . '"');
                }
                $upd->execute([$sid, $label]);
                $totalUpdated += $upd->rowCount();
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return $totalUpdated;
    }
}
