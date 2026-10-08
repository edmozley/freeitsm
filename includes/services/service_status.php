<?php
/**
 * ServiceStatusService — the single home for the service-status module's write
 * rules (services, incidents, and the UI-only incident-statuses + impact-levels).
 *
 * Both callers use it: the UI endpoints (api/service-status/*.php) and the REST
 * API (api/v1/resources/service_status.php). Each passes an ActorContext (who is
 * acting) and the input; this layer validates + writes and either returns the
 * affected id or throws ServiceError. It never emits HTTP — the adapters shape
 * the response (the API serialises + sets a status; the UI returns {success}).
 *
 * Canonical behaviour = the old API resource's behaviour (see the divergence
 * table in docs/design/service-layer.md): UTC timestamps, empty text stored as
 * NULL, strict validation of affected services, incident status by name or id.
 */

require_once __DIR__ . '/../service_context.php';
require_once __DIR__ . '/../service_impact_levels.php';
require_once dirname(__DIR__, 2) . '/workflow/includes/engine.php';

class ServiceStatusService
{
    // ======================================================================
    //  Services
    // ======================================================================

    /** Create (no id) or update (id present) a service. Returns the id. */
    public static function saveService(PDO $conn, ActorContext $ctx, array $in): int
    {
        if (!empty($in['id'])) {
            $id      = (int)$in['id'];
            $current = self::loadServiceRow($conn, $id);            // 404 if gone (checked before empty-body, as the API did)
            if (!array_diff_key($in, ['id' => true])) {
                throw new ServiceError('validation', 'missing_field', 'No fields to update.');
            }
            $get = function (string $k, $d) use ($in, $current) {
                return array_key_exists($k, $in) ? $in[$k] : ($current[$k] ?? $d);
            };
            $name = trim((string)$get('name', ''));
            if ($name === '') {
                throw new ServiceError('validation', 'invalid_field', "'name' cannot be empty.");
            }
            $desc = trim((string)($get('description', '') ?? ''));
            $conn->prepare("UPDATE status_services SET name=?, description=?, display_order=?, is_active=? WHERE id=?")
                 ->execute([$name, $desc !== '' ? $desc : null, (int)$get('display_order', 0), (int)(bool)$get('is_active', 1), $id]);
            WorkflowEngine::emitCrud('status_service', 'updated', $id, $name);
            return $id;
        }

        $name = trim((string)($in['name'] ?? ''));
        if ($name === '') {
            throw new ServiceError('validation', 'missing_field', "'name' is required.");
        }
        $desc = trim((string)($in['description'] ?? ''));
        $conn->prepare(
            "INSERT INTO status_services (name, description, display_order, is_active, created_datetime)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP())"
        )->execute([
            $name,
            $desc !== '' ? $desc : null,
            isset($in['display_order']) ? (int)$in['display_order'] : 0,
            isset($in['is_active']) ? (int)(bool)$in['is_active'] : 1,
        ]);
        $newId = (int)$conn->lastInsertId();
        WorkflowEngine::emitCrud('status_service', 'created', $newId, $name);
        return $newId;
    }

    /** Delete a service + its incident links, atomically. */
    public static function deleteService(PDO $conn, ActorContext $ctx, int $id): void
    {
        $row = self::loadServiceRow($conn, $id);                   // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM status_incident_services WHERE service_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM status_services WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('status_service', 'deleted', $id, $row['name'] ?? null);
    }

    private static function loadServiceRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare("SELECT * FROM status_services WHERE id = ?");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Service not found.');
        return $row;
    }

    // ======================================================================
    //  Incidents
    // ======================================================================

    /** Create (no id) or update (id present) an incident. Returns the id. */
    public static function saveIncident(PDO $conn, ActorContext $ctx, array $in): int
    {
        if (!empty($in['id'])) {
            return self::updateIncident($conn, $ctx, (int)$in['id'], $in);
        }

        $title = trim((string)($in['title'] ?? ''));
        if ($title === '') {
            throw new ServiceError('validation', 'missing_field', "'title' is required.");
        }
        // Default Investigating (like save_incident.php); by name or id.
        $status  = self::resolveIncidentStatus($conn, $in) ?? self::resolveIncidentStatus($conn, ['status' => 'Investigating']);
        $comment = trim((string)($in['comment'] ?? '')) ?: null;
        // Discussion #99: an update is INTERNAL unless it is explicitly marked
        // otherwise, matching ticket_notes. Defaulting the other way would mean
        // a caller that has never heard of this - the REST API, a workflow, an
        // older page - silently publishing to the portal.
        $isInternal = array_key_exists('is_internal', $in) ? (bool)$in['is_internal'] : true;
        $links   = self::validateIncidentServices($conn, (isset($in['services']) && is_array($in['services'])) ? $in['services'] : []);

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO status_incidents (title, status_id, comment, created_by_id, created_datetime, updated_datetime, resolved_datetime)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), " . ($status[2] ? "UTC_TIMESTAMP()" : "NULL") . ")"
            )->execute([$title, $status[0], $comment, $ctx->actorId]);
            $incidentId = (int)$conn->lastInsertId();
            self::replaceIncidentServices($conn, $incidentId, $links);
            // The opening snapshot. Written even for an incident nobody ever edits,
            // so the reader never has to special-case "one update or none".
            self::recordIncidentUpdate($conn, $ctx, $incidentId, $status[0], $comment, $links, $isInternal);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('service_status.incident_created', ['incident' => ['id' => $incidentId, 'title' => $title, 'status_id' => $status[0]]]);
        if (!empty($status[2])) {
            WorkflowEngine::dispatch('service_status.incident_resolved', ['incident' => ['id' => $incidentId, 'title' => $title, 'status_id' => $status[0]]]);
        }
        return $incidentId;
    }

    private static function updateIncident(PDO $conn, ActorContext $ctx, int $id, array $in): int
    {
        $current = self::loadIncidentRow($conn, $id);              // 404 if gone (checked before empty-body, as the API did)
        if (!array_diff_key($in, ['id' => true])) {
            throw new ServiceError('validation', 'missing_field', 'No fields to update.');
        }

        $title = array_key_exists('title', $in) ? trim((string)$in['title']) : $current['title'];
        if ($title === '') {
            throw new ServiceError('validation', 'invalid_field', "'title' cannot be empty.");
        }
        $status     = self::resolveIncidentStatus($conn, $in);
        $statusId   = $status !== null ? $status[0] : ($current['status_id'] !== null ? (int)$current['status_id'] : null);
        $isResolved = $status !== null ? (bool)$status[2] : (bool)$current['status_is_resolved'];
        $comment    = array_key_exists('comment', $in) ? (trim((string)$in['comment']) ?: null) : $current['comment'];
        // Discussion #99: an update is INTERNAL unless it is explicitly marked
        // otherwise, matching ticket_notes. Defaulting the other way would mean
        // a caller that has never heard of this - the REST API, a workflow, an
        // older page - silently publishing to the portal.
        $isInternal = array_key_exists('is_internal', $in) ? (bool)$in['is_internal'] : true;

        $links = (isset($in['services']) && is_array($in['services'])) ? self::validateIncidentServices($conn, $in['services']) : null;

        // ⚠️ Worked out BEFORE anything is written. replaceIncidentServices()
        // below overwrites the very rows this compares against, so asking
        // afterwards would always answer "unchanged".
        $statusChanged   = (string)$statusId !== (string)($current['status_id'] ?? '');
        $commentChanged  = (string)$comment  !== (string)($current['comment'] ?? '');
        $servicesChanged = $links !== null && self::incidentServicesDiffer($conn, $id, $links);

        $conn->beginTransaction();
        try {
            // resolved_datetime: stamped once on entering a resolved status
            // (original preserved via COALESCE), cleared on reopen.
            if ($isResolved) {
                $conn->prepare(
                    "UPDATE status_incidents SET title=?, status_id=?, comment=?,
                            resolved_datetime = COALESCE(resolved_datetime, UTC_TIMESTAMP()),
                            updated_datetime = UTC_TIMESTAMP() WHERE id=?"
                )->execute([$title, $statusId, $comment, $id]);
            } else {
                $conn->prepare(
                    "UPDATE status_incidents SET title=?, status_id=?, comment=?,
                            resolved_datetime = NULL, updated_datetime = UTC_TIMESTAMP() WHERE id=?"
                )->execute([$title, $statusId, $comment, $id]);
            }
            if ($links !== null) {
                self::replaceIncidentServices($conn, $id, $links);
            }
            // ⚠️ An edit appends ONLY when it has something new to say.
            //
            // This used to append on every save, full stop. That was defensible
            // while the timeline was an internal audit trail, and stopped being
            // defensible in #99 when the same rows started reaching customers:
            // opening an incident and pressing Save republished the last message
            // to the portal, and fixing a typo published the sentence twice with
            // the wrong version first. Ed found both.
            //
            // Changing only the VISIBILITY is not a new entry either — that is a
            // correction to the update that already exists, and there is now an
            // edit action for it.
            //
            // $links is null when the caller did not mention services, which
            // counts as unchanged. When it did, recordIncidentUpdate() snapshots
            // the links so the timeline stays continuous.
            if ($statusChanged || $commentChanged || $servicesChanged) {
                // ⚠️ The comment is carried onto the new entry ONLY if it changed.
                // Otherwise a status change repeats the previous sentence, and a
                // customer reads "Investigating — we are aware of delays" followed
                // by "Resolved — we are aware of delays". Recording the status
                // change on its own says the true thing: the status moved and
                // nobody added to what was already said.
                self::recordIncidentUpdate(
                    $conn, $ctx, $id, $statusId,
                    $commentChanged ? $comment : null,
                    $links, $isInternal
                );
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('service_status.incident_updated', ['incident' => ['id' => $id, 'title' => $title, 'status_id' => $statusId]]);
        if ($isResolved && !(bool)$current['status_is_resolved']) {
            WorkflowEngine::dispatch('service_status.incident_resolved', ['incident' => ['id' => $id, 'title' => $title, 'status_id' => $statusId]]);
        }
        return $id;
    }

    /** Delete an incident + its service links, atomically. */
    public static function deleteIncident(PDO $conn, ActorContext $ctx, int $id): void
    {
        $row = self::loadIncidentRow($conn, $id);                  // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM status_incidents WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::dispatch('service_status.incident_deleted', ['incident' => ['id' => $id, 'title' => $row['title'] ?? null]]);
    }

    private static function loadIncidentRow(PDO $conn, int $id): array
    {
        $stmt = $conn->prepare(
            "SELECT si.*, st.is_resolved AS status_is_resolved
             FROM status_incidents si
             LEFT JOIN service_incident_statuses st ON st.id = si.status_id
             WHERE si.id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Incident not found.');
        return $row;
    }

    /** Resolve an incident status by id or name — strict on unknown/inactive. */
    private static function resolveIncidentStatus(PDO $conn, array $in): ?array
    {
        if (isset($in['status_id']) && $in['status_id'] !== '' && $in['status_id'] !== null) {
            $stmt = $conn->prepare("SELECT id, name, is_resolved FROM service_incident_statuses WHERE id = ? AND is_active = 1");
            $stmt->execute([(int)$in['status_id']]);
        } elseif (isset($in['status']) && trim((string)$in['status']) !== '') {
            $stmt = $conn->prepare("SELECT id, name, is_resolved FROM service_incident_statuses WHERE name = ? AND is_active = 1");
            $stmt->execute([trim((string)$in['status'])]);
        } else {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive incident status: ' . ($in['status'] ?? $in['status_id']));
        }
        return [(int)$row['id'], $row['name'], (int)$row['is_resolved']];
    }

    /**
     * Validate [{service_id, impact_level|impact_level_id}] → [[service_id, impact_id]].
     * Strict: 422 on unknown service or impact (the old UI silently skipped these).
     */
    public static function validateIncidentServices(PDO $conn, array $services): array
    {
        $out = [];
        foreach ($services as $s) {
            $serviceId = isset($s['service_id']) ? (int)$s['service_id'] : 0;
            if ($serviceId <= 0) {
                throw new ServiceError('validation', 'invalid_field', "Each affected service needs a 'service_id'.");
            }
            $check = $conn->prepare("SELECT id FROM status_services WHERE id = ?");
            $check->execute([$serviceId]);
            if (!$check->fetchColumn()) {
                throw new ServiceError('validation', 'invalid_field', "Unknown service id: {$serviceId}");
            }
            if (isset($s['impact_level_id']) && $s['impact_level_id'] !== '' && $s['impact_level_id'] !== null) {
                $imp = $conn->prepare("SELECT id FROM service_impact_levels WHERE id = ? AND is_active = 1");
                $imp->execute([(int)$s['impact_level_id']]);
            } else {
                // An omitted impact falls back to the configured default level, not
                // to the literal 'Operational' — renaming that level would otherwise
                // turn every impact-less call into "Unknown or inactive impact level"
                // (GH #70).
                $fallback = defaultImpactLevel($conn)['name'];
                $name = trim((string)($s['impact_level'] ?? $fallback));
                $imp = $conn->prepare("SELECT id FROM service_impact_levels WHERE name = ? AND is_active = 1");
                $imp->execute([$name !== '' ? $name : $fallback]);
            }
            $impactId = $imp->fetchColumn();
            if ($impactId === false) {
                throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive impact level: ' . ($s['impact_level'] ?? $s['impact_level_id'] ?? ''));
            }
            $out[] = [$serviceId, (int)$impactId];
        }
        return $out;
    }

    /**
     * Do the services (and their impacts) about to be written differ from what
     * is stored? Used to decide whether a save has anything new to say.
     *
     * Order-insensitive: the dialog rebuilds its rows every time it opens, so
     * the same two services can arrive in a different order without anything
     * having actually changed — and appending on that would be the same bug in
     * a different coat.
     */
    private static function incidentServicesDiffer(PDO $conn, int $incidentId, array $links): bool
    {
        $stmt = $conn->prepare("SELECT service_id, impact_level_id FROM status_incident_services WHERE incident_id = ?");
        $stmt->execute([$incidentId]);

        $now = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $now[] = (int)$r['service_id'] . ':' . (int)$r['impact_level_id'];
        }
        $next = [];
        foreach ($links as [$serviceId, $impactId]) {
            $next[] = (int)$serviceId . ':' . (int)$impactId;
        }
        sort($now);
        sort($next);
        return $now !== $next;
    }

    /**
     * Correct an update that has already been posted (Ed, on top of #99).
     *
     * ⚠️ EDIT IN PLACE, not a new entry. Fixing a typo used to mean saving the
     * incident again, which published the sentence twice with the wrong version
     * first — and on a customer-facing timeline that reads as carelessness.
     *
     * Only the wording and the visibility can change. The status and the service
     * impacts recorded on an update are what was true at that moment, and
     * rewriting them would make the timeline a record of what somebody wishes
     * had happened.
     *
     * @throws ServiceError when there is no such update.
     */
    public static function editIncidentUpdate(PDO $conn, ActorContext $ctx, int $updateId, array $in): void
    {
        $stmt = $conn->prepare("SELECT id, incident_id, comment, is_internal FROM status_incident_updates WHERE id = ?");
        $stmt->execute([$updateId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ServiceError('not_found', 'not_found', 'Update not found.');
        }

        $comment = array_key_exists('comment', $in)
            ? (trim((string)$in['comment']) ?: null)
            : $row['comment'];
        $isInternal = array_key_exists('is_internal', $in)
            ? ((bool)$in['is_internal'] ? 1 : 0)
            : (int)$row['is_internal'];

        $conn->prepare("UPDATE status_incident_updates SET comment = ?, is_internal = ? WHERE id = ?")
             ->execute([$comment, $isInternal, $updateId]);

        // The incident's own comment mirrors the LATEST update, so correcting
        // that one has to correct the incident too or the board would still show
        // the typo the timeline no longer has.
        $last = $conn->prepare("SELECT id FROM status_incident_updates WHERE incident_id = ? ORDER BY created_datetime DESC, id DESC LIMIT 1");
        $last->execute([(int)$row['incident_id']]);
        if ((int)$last->fetchColumn() === $updateId) {
            $conn->prepare("UPDATE status_incidents SET comment = ? WHERE id = ?")
                 ->execute([$comment, (int)$row['incident_id']]);
        }
    }

    /**
     * Remove one update from the timeline.
     *
     * The incident and every other update survive it. As with editing, the
     * incident's own comment follows if the one removed was the latest.
     *
     * @throws ServiceError when there is no such update.
     */
    public static function deleteIncidentUpdate(PDO $conn, ActorContext $ctx, int $updateId): void
    {
        $stmt = $conn->prepare("SELECT incident_id FROM status_incident_updates WHERE id = ?");
        $stmt->execute([$updateId]);
        $incidentId = $stmt->fetchColumn();
        if ($incidentId === false) {
            throw new ServiceError('not_found', 'not_found', 'Update not found.');
        }
        $incidentId = (int)$incidentId;

        $conn->prepare("DELETE FROM status_incident_updates WHERE id = ?")->execute([$updateId]);

        $last = $conn->prepare(
            "SELECT comment FROM status_incident_updates WHERE incident_id = ?
              ORDER BY created_datetime DESC, id DESC LIMIT 1"
        );
        $last->execute([$incidentId]);
        $comment = $last->fetchColumn();
        $conn->prepare("UPDATE status_incidents SET comment = ? WHERE id = ?")
             ->execute([$comment === false ? null : $comment, $incidentId]);
    }

    private static function replaceIncidentServices(PDO $conn, int $incidentId, array $links): void
    {
        $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$incidentId]);
        if (!$links) return;
        $ins = $conn->prepare("INSERT INTO status_incident_services (incident_id, service_id, impact_level_id) VALUES (?, ?, ?)");
        foreach ($links as [$serviceId, $impactId]) {
            $ins->execute([$incidentId, $serviceId, $impactId]);
        }
    }

    /**
     * Record a moment in an incident's life (discussion #59, phase 2).
     *
     * ⚠️ THIS IS WHY DOWNGRADING A SERVICE NO LONGER LOSES THE EARLIER LEVEL.
     * replaceIncidentServices() above deletes and re-inserts, so
     * status_incident_services only ever holds the CURRENT impact — moving a
     * service from Major Outage to Degraded overwrote the first value and the
     * history then reported the whole incident at whatever it ended on.
     *
     * Each call appends a full SNAPSHOT of the per-service impacts, not a diff.
     * A diff would make the reader reconstruct state, and one missing row would
     * silently shift a service's entire timeline; a snapshot costs a few rows
     * and cannot drift.
     *
     * A service is "restored" either by moving it to a level that does not count
     * as downtime, or by dropping it from $links entirely. Both simply end its
     * interval at this update, which is why there is no per-service resolved flag.
     *
     * Best-effort by design: an install that has not run Database Verification
     * since this shipped has no tables to write to, and failing to save an
     * incident because its audit trail could not be appended would be a worse
     * outcome than the missing trail. The reader falls back for such incidents.
     *
     * @param array|null $links [[serviceId, impactId], …] — null means "unchanged",
     *                          in which case the current links are snapshotted.
     */
    private static function recordIncidentUpdate(PDO $conn, ActorContext $ctx, int $incidentId, ?int $statusId, ?string $comment, ?array $links, bool $isInternal = true): void
    {
        try {
            if ($links === null) {
                $cur = $conn->prepare("SELECT service_id, impact_level_id FROM status_incident_services WHERE incident_id = ?");
                $cur->execute([$incidentId]);
                $links = [];
                foreach ($cur->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $links[] = [(int)$r['service_id'], $r['impact_level_id'] !== null ? (int)$r['impact_level_id'] : null];
                }
            }

            $conn->prepare(
                "INSERT INTO status_incident_updates (incident_id, status_id, comment, is_internal, created_by_id, created_datetime)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())"
            )->execute([$incidentId, $statusId, $comment, $isInternal ? 1 : 0, $ctx->actorId]);
            $updateId = (int)$conn->lastInsertId();

            if ($links) {
                $ins = $conn->prepare("INSERT INTO status_incident_update_services (update_id, service_id, impact_level_id) VALUES (?, ?, ?)");
                foreach ($links as [$serviceId, $impactId]) {
                    $ins->execute([$updateId, $serviceId, $impactId]);
                }
            }
        } catch (Exception $e) {
            error_log('service-status: could not record the incident update — ' . $e->getMessage()
                      . ' (run Database Verification to add status_incident_updates)');
        }
    }

    // ======================================================================
    //  Incident statuses (UI-only — no API twin)
    // ======================================================================

    public static function saveIncidentStatus(PDO $conn, ActorContext $ctx, array $in): int
    {
        $name          = trim((string)($in['name'] ?? ''));
        $colour        = trim((string)($in['colour'] ?? ''));
        $is_resolved   = !empty($in['is_resolved']) ? 1 : 0;
        $is_default    = !empty($in['is_default']) ? 1 : 0;
        $display_order = (int)($in['display_order'] ?? 0);
        $is_active     = !empty($in['is_active']) ? 1 : 0;
        $id            = !empty($in['id']) ? (int)$in['id'] : null;

        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Name is required');
        if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
            throw new ServiceError('validation', 'invalid_field', 'Colour must be a #rrggbb hex code');
        }

        $conn->beginTransaction();
        try {
            if ($is_default) {
                $clearSql = "UPDATE service_incident_statuses SET is_default = 0";
                if ($id) $clearSql .= " WHERE id <> " . $id;
                $conn->exec($clearSql);
            }
            if ($id) {
                $conn->prepare(
                    "UPDATE service_incident_statuses SET name=?, colour=?, is_resolved=?, is_default=?, display_order=?, is_active=? WHERE id=?"
                )->execute([$name, $colour ?: null, $is_resolved, $is_default, $display_order, $is_active, $id]);
            } else {
                $conn->prepare(
                    "INSERT INTO service_incident_statuses (name, colour, is_resolved, is_default, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$name, $colour ?: null, $is_resolved, $is_default, $display_order, $is_active]);
                $id = (int)$conn->lastInsertId();
            }
            $hasDefault = (int)$conn->query("SELECT COUNT(*) FROM service_incident_statuses WHERE is_default = 1")->fetchColumn();
            if ($hasDefault === 0) {
                $conn->exec("UPDATE service_incident_statuses SET is_default = 1 ORDER BY display_order, id LIMIT 1");
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('incident_status', !empty($in['id']) ? 'updated' : 'created', (int)$id, $name);
        return (int)$id;
    }

    public static function deleteIncidentStatus(PDO $conn, ActorContext $ctx, int $id): void
    {
        if (!$id) throw new ServiceError('validation', 'missing_field', 'Status ID is required');

        $isDefault = (int)$conn->query("SELECT is_default FROM service_incident_statuses WHERE id = " . $id)->fetchColumn();
        if ($isDefault === 1) {
            throw new ServiceError('conflict', 'conflict', 'Cannot delete the default status. Set another status as default first.');
        }
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM status_incidents WHERE status_id = ?");
        $checkStmt->execute([$id]);
        $count = (int)$checkStmt->fetchColumn();
        if ($count > 0) {
            throw new ServiceError('conflict', 'conflict', "Cannot delete: this status is used by $count incident(s). Reassign them or set the status to inactive instead.");
        }
        $name = $conn->query("SELECT name FROM service_incident_statuses WHERE id = " . (int)$id)->fetchColumn() ?: null;
        $conn->prepare("DELETE FROM service_incident_statuses WHERE id = ?")->execute([$id]);
        WorkflowEngine::emitCrud('incident_status', 'deleted', $id, $name);
    }

    // ======================================================================
    //  Impact levels (UI-only — no API twin)
    // ======================================================================

    public static function saveImpactLevel(PDO $conn, ActorContext $ctx, array $in): int
    {
        $name           = trim((string)($in['name'] ?? ''));
        $colour         = trim((string)($in['colour'] ?? ''));
        $is_default     = !empty($in['is_default']) ? 1 : 0;
        $severity_order = (int)($in['severity_order'] ?? 99);
        $display_order  = (int)($in['display_order'] ?? 0);
        $is_active      = !empty($in['is_active']) ? 1 : 0;
        $id             = !empty($in['id']) ? (int)$in['id'] : null;
        // Uptime (discussion #59). Absent means 1 — the conservative answer for a
        // level nobody has ruled on, and it matches the column default so an old
        // client that does not send the field cannot silently zero it. Uses
        // array_key_exists rather than !empty so an explicit 0 is honoured.
        $counts_down    = array_key_exists('counts_as_downtime', $in)
            ? (!empty($in['counts_as_downtime']) ? 1 : 0)
            : 1;

        if ($name === '') throw new ServiceError('validation', 'missing_field', 'Name is required');
        if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
            throw new ServiceError('validation', 'invalid_field', 'Colour must be a #rrggbb hex code');
        }

        $conn->beginTransaction();
        try {
            if ($is_default) {
                $clearSql = "UPDATE service_impact_levels SET is_default = 0";
                if ($id) $clearSql .= " WHERE id <> " . $id;
                $conn->exec($clearSql);
            }
            if ($id) {
                $conn->prepare(
                    "UPDATE service_impact_levels SET name=?, colour=?, is_default=?, severity_order=?, display_order=?, is_active=?, counts_as_downtime=? WHERE id=?"
                )->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active, $counts_down, $id]);
            } else {
                $conn->prepare(
                    "INSERT INTO service_impact_levels (name, colour, is_default, severity_order, display_order, is_active, counts_as_downtime) VALUES (?, ?, ?, ?, ?, ?, ?)"
                )->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active, $counts_down]);
                $id = (int)$conn->lastInsertId();
            }
            $hasDefault = (int)$conn->query("SELECT COUNT(*) FROM service_impact_levels WHERE is_default = 1")->fetchColumn();
            if ($hasDefault === 0) {
                $conn->exec("UPDATE service_impact_levels SET is_default = 1 ORDER BY severity_order DESC, id LIMIT 1");
            }
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        WorkflowEngine::emitCrud('impact_level', !empty($in['id']) ? 'updated' : 'created', (int)$id, $name);
        return (int)$id;
    }

    public static function deleteImpactLevel(PDO $conn, ActorContext $ctx, int $id): void
    {
        if (!$id) throw new ServiceError('validation', 'missing_field', 'Impact level ID is required');

        $isDefault = (int)$conn->query("SELECT is_default FROM service_impact_levels WHERE id = " . $id)->fetchColumn();
        if ($isDefault === 1) {
            throw new ServiceError('conflict', 'conflict', 'Cannot delete the default impact level. Set another level as default first.');
        }
        $checkStmt = $conn->prepare("SELECT COUNT(*) FROM status_incident_services WHERE impact_level_id = ?");
        $checkStmt->execute([$id]);
        $count = (int)$checkStmt->fetchColumn();
        if ($count > 0) {
            throw new ServiceError('conflict', 'conflict', "Cannot delete: this impact level is used on $count incident-service link(s). Reassign them or set the level to inactive instead.");
        }
        $name = $conn->query("SELECT name FROM service_impact_levels WHERE id = " . (int)$id)->fetchColumn() ?: null;
        $conn->prepare("DELETE FROM service_impact_levels WHERE id = ?")->execute([$id]);
        WorkflowEngine::emitCrud('impact_level', 'deleted', $id, $name);
    }
}
