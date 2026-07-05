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

class MorningChecksService
{
    // ======================================================================
    //  Checks
    // ======================================================================

    /** Create (no id) or update (id present) a check. Returns the id. */
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
            $conn->prepare(
                "UPDATE morningChecks_Checks
                 SET CheckName = ?, CheckDescription = ?, SortOrder = ?, IsActive = ?, ModifiedDate = UTC_TIMESTAMP()
                 WHERE CheckID = ?"
            )->execute([
                $name,
                array_key_exists('description', $in) ? trim((string)$in['description']) : $current['CheckDescription'],
                array_key_exists('sort_order', $in) ? (int)$in['sort_order'] : (int)$current['SortOrder'],
                array_key_exists('is_active', $in) ? (int)(bool)$in['is_active'] : (int)$current['IsActive'],
                $id,
            ]);
            return $id;
        }

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', "'name' is required.");
        }
        $conn->prepare(
            "INSERT INTO morningChecks_Checks (CheckName, CheckDescription, SortOrder, IsActive, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([
            $name,
            trim((string)($in['description'] ?? '')),
            isset($in['sort_order']) ? (int)$in['sort_order'] : 0,
            isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
        ]);
        return (int)$conn->lastInsertId();
    }

    /** Delete a check + its results, atomically. */
    public static function deleteCheck(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::loadCheckRow($conn, $id);                            // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM morningChecks_Results WHERE CheckID = ?")->execute([$id]);
            $conn->prepare("DELETE FROM morningChecks_Checks WHERE CheckID = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
    }

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
     */
    public static function recordResult(PDO $conn, ActorContext $ctx, array $in): array
    {
        $checkId = isset($in['check_id']) ? (int)$in['check_id'] : 0;
        if ($checkId <= 0) {
            throw new ServiceError('validation', 'missing_field', "'check_id' is required.");
        }
        $check = $conn->prepare("SELECT CheckID FROM morningChecks_Checks WHERE CheckID = ?");
        $check->execute([$checkId]);
        if (!$check->fetchColumn()) {
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
            $conn->prepare(
                "UPDATE morningChecks_Results
                 SET StatusID = ?, Status = NULL, Notes = ?, ModifiedDate = UTC_TIMESTAMP()
                 WHERE ResultID = ?"
            )->execute([(int)$status['StatusID'], $notes, (int)$resultId]);
            return ['id' => (int)$resultId, 'created' => false];
        }

        $conn->prepare(
            "INSERT INTO morningChecks_Results (CheckID, CheckDate, StatusID, Status, Notes, CreatedBy, CreatedDate, ModifiedDate)
             VALUES (?, ?, ?, NULL, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        )->execute([$checkId, $date, (int)$status['StatusID'], $notes, ($ctx->actorName !== '' ? $ctx->actorName : null)]);
        return ['id' => (int)$conn->lastInsertId(), 'created' => true];
    }

    /** Strict YYYY-MM-DD — 422 on garbage (the old UI silently substituted today). */
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

    /** Create (id null) or update (id present) a status. Returns the id. */
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
            return (int)$conn->lastInsertId();
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
        return $id;
    }

    /**
     * Delete a status; snapshot its label onto affected results (turning them
     * into orphans) first. Returns ['deleted' => int, 'orphaned' => int].
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
        return ['deleted' => $deleted, 'orphaned' => $orphaned];
    }

    // ======================================================================
    //  Reorder + normalise (UI-only)
    // ======================================================================

    /** Reorder checks: SortOrder becomes the array index (matches reorder_checks.php). */
    public static function reorderChecks(PDO $conn, ActorContext $ctx, array $order): void
    {
        $stmt = $conn->prepare("UPDATE morningChecks_Checks SET SortOrder = ?, ModifiedDate = UTC_TIMESTAMP() WHERE CheckID = ?");
        foreach ($order as $index => $checkId) {
            $stmt->execute([(int)$index, (int)$checkId]);
        }
    }

    /** Reorder statuses: positions become 10, 20, 30, … (matches reorder_statuses.php), transactional. */
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
