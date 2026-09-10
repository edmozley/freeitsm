<?php
/**
 * SoftwareService — the single home for the software module's write rules.
 *
 * Only the LICENCE writes are duplicated between the UI (api/software/
 * save_licence.php, delete_licence.php) and the REST API (api/v1/resources/
 * software.php); the inventory is agent-owned/read-only and the dashboard-widget
 * + legacy-apikey endpoints are UI-only with no API twin, so they keep their own
 * code (nothing to de-duplicate there). This service therefore covers licences.
 *
 * Each caller passes an ActorContext + canonical input; this layer validates,
 * writes, and returns the affected id (plus a created flag for the save adapter)
 * or throws ServiceError. It never emits HTTP.
 *
 * Canonical behaviour = the API resource's (see docs/design/service-layer.md):
 * the target app must exist (422, not a raw FK 500), quantity/cost/dates are
 * validated (422, not silently coerced), empty optional strings normalise to
 * null, and missing currency/status fall back to GBP/Active. created_by is
 * stamped on insert and never touched on update. created_at/updated_at keep the
 * table defaults on insert; updated_at is stamped UTC on update.
 *
 * Input keys are canonical snake_case (app_id, licence_type, licence_key,
 * quantity, renewal_date, notice_period_days, portal_url, cost, currency,
 * purchase_date, vendor_contact, notes, status). The UI already sends these.
 */

require_once __DIR__ . '/../service_context.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';
require_once __DIR__ . '/../software_licence_calendar.php';   // renewals -> the calendar (#1551)

class SoftwareService
{
    /**
     * Create (no id) or update (id present) a licence.
     * Returns ['id' => int, 'created' => bool].
     */
    public static function saveLicence(PDO $conn, ActorContext $ctx, array $in): array
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadLicenceRow($conn, $id);            // 404 if gone (before empty-body, as the API did)
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $f = self::collectFields($conn, $in, $current);
            // created_by is never touched on update — save_licence.php parity.
            $conn->prepare(
                "UPDATE software_licences SET app_id=?, licence_type=?, licence_key=?, quantity=?,
                    renewal_date=?, notice_period_days=?, portal_url=?, cost=?, currency=?,
                    purchase_date=?, vendor_contact=?, notes=?, status=?, updated_at=UTC_TIMESTAMP()
                 WHERE id=?"
            )->execute([
                $f['app_id'], $f['licence_type'], $f['licence_key'], $f['quantity'], $f['renewal_date'],
                $f['notice_period_days'], $f['portal_url'], $f['cost'], $f['currency'], $f['purchase_date'],
                $f['vendor_contact'], $f['notes'], $f['status'], $id,
            ]);
            WorkflowEngine::emitCrud('software_licence', 'updated', $id, $f['licence_type']);
            self::resyncRenewalCalendar($conn);
            return ['id' => $id, 'created' => false];
        }

        $f = self::collectFields($conn, $in);
        $conn->prepare(
            "INSERT INTO software_licences
                (app_id, licence_type, licence_key, quantity, renewal_date,
                 notice_period_days, portal_url, cost, currency, purchase_date,
                 vendor_contact, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $f['app_id'], $f['licence_type'], $f['licence_key'], $f['quantity'], $f['renewal_date'],
            $f['notice_period_days'], $f['portal_url'], $f['cost'], $f['currency'], $f['purchase_date'],
            $f['vendor_contact'], $f['notes'], $f['status'], $ctx->actorId,
        ]);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::emitCrud('software_licence', 'created', $newId, $f['licence_type']);
        self::resyncRenewalCalendar($conn);
        return ['id' => $newId, 'created' => true];
    }

    /** Delete a licence (leaf table). Returns the id. 404 if it does not exist. */
    public static function deleteLicence(PDO $conn, ActorContext $ctx, int $id): int
    {
        $row = self::loadLicenceRow($conn, $id);
        $conn->prepare("DELETE FROM software_licences WHERE id = ?")->execute([$id]);
        WorkflowEngine::emitCrud('software_licence', 'deleted', $id, $row['licence_type'] ?? null);
        self::resyncRenewalCalendar($conn);
        return $id;
    }

    // ======================================================================
    //  Internals
    // ======================================================================

    /**
     * Push renewal dates back into the calendar after any licence write (#1551).
     *
     * ⚠️ NEVER ALLOWED TO FAIL THE WRITE. The licence is the record; its calendar
     * entries are a convenience derived from it. A calendar table that is missing,
     * mid-migration or momentarily locked must not turn "save this licence" into
     * an error — the licence is already committed by the time this runs, so
     * throwing here would report a failure for something that succeeded.
     *
     * A cheap full resync, exactly as the asset warranty generator does it:
     * licence edits are rare and the event set is small, and regenerating the lot
     * is the only version with no drift in it.
     */
    private static function resyncRenewalCalendar(PDO $conn): void
    {
        try {
            if (function_exists('syncSoftwareLicenceCalendar')) {
                syncSoftwareLicenceCalendar($conn);
            }
        } catch (Throwable $e) {
            error_log('Software renewal calendar sync failed: ' . $e->getMessage());
        }
    }

    /** Load a licence row for update/delete, or throw 404. */
    private static function loadLicenceRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM software_licences WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Licence not found.');
        }
        return $row;
    }

    /**
     * Collect + validate the writable licence fields (mirrors the API resource's
     * apiSoftwareLicenceFields coercions). On update, absent keys fall back to
     * the current row; on create they fall back to the column default.
     */
    private static function collectFields(PDO $conn, array $in, array $current = []): array
    {
        $get = function (string $key, $default = null) use ($in, $current) {
            return array_key_exists($key, $in) ? $in[$key] : ($current[$key] ?? $default);
        };

        $f = [];
        $f['app_id'] = (int)($get('app_id') ?? 0);
        if ($f['app_id'] <= 0) {
            throw new ServiceError('validation', 'missing_field', "'app_id' is required.");
        }
        $appCheck = $conn->prepare("SELECT id FROM software_inventory_apps WHERE id = ?");
        $appCheck->execute([$f['app_id']]);
        if (!$appCheck->fetchColumn()) {
            throw new ServiceError('validation', 'invalid_field', "Unknown app id: {$f['app_id']}");
        }
        $f['licence_type'] = trim((string)($get('licence_type') ?? ''));
        if ($f['licence_type'] === '') {
            throw new ServiceError('validation', 'missing_field', "'licence_type' is required.");
        }

        $f['licence_key'] = ($v = trim((string)($get('licence_key') ?? ''))) !== '' ? $v : null;
        $f['quantity'] = null;
        if ($get('quantity') !== null && $get('quantity') !== '') {
            if (!is_numeric($get('quantity')) || (int)$get('quantity') < 0) {
                throw new ServiceError('validation', 'invalid_field', "'quantity' must be a non-negative integer.");
            }
            $f['quantity'] = (int)$get('quantity');
        }
        $f['renewal_date']  = self::parseDateOnly($get('renewal_date'), 'renewal_date');
        $f['purchase_date'] = self::parseDateOnly($get('purchase_date'), 'purchase_date');
        $f['notice_period_days'] = null;
        if ($get('notice_period_days') !== null && $get('notice_period_days') !== '') {
            $f['notice_period_days'] = max(0, (int)$get('notice_period_days'));
        }
        $f['portal_url'] = ($v = trim((string)($get('portal_url') ?? ''))) !== '' ? $v : null;
        $f['cost'] = null;
        if ($get('cost') !== null && $get('cost') !== '') {
            if (!is_numeric($get('cost'))) {
                throw new ServiceError('validation', 'invalid_field', "'cost' must be a number.");
            }
            $f['cost'] = (string)round((float)$get('cost'), 2);
        }
        $f['currency'] = trim((string)($get('currency') ?? '')) ?: 'GBP';
        if (mb_strlen($f['currency']) > 10) {
            throw new ServiceError('validation', 'invalid_field', "'currency' must be at most 10 characters.");
        }
        $f['vendor_contact'] = ($v = trim((string)($get('vendor_contact') ?? ''))) !== '' ? $v : null;
        $f['notes']  = ($v = trim((string)($get('notes') ?? ''))) !== '' ? $v : null;
        $f['status'] = trim((string)($get('status') ?? '')) ?: 'Active';

        return $f;
    }

    /** Validate an optional YYYY-MM-DD date (throwing version of apiParseDateOnly). */
    private static function parseDateOnly($value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('Y-m-d', (string)$value);
        if (!$d || $d->format('Y-m-d') !== (string)$value) {
            throw new ServiceError('validation', 'invalid_field', "'{$field}' must be a date in YYYY-MM-DD format.");
        }
        return (string)$value;
    }
}
