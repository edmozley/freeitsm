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
        return (int)$conn->lastInsertId();
    }

    /** Delete a service + its incident links, atomically. */
    public static function deleteService(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::loadServiceRow($conn, $id);                          // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM status_incident_services WHERE service_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM status_services WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
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
        $links   = self::validateIncidentServices($conn, (isset($in['services']) && is_array($in['services'])) ? $in['services'] : []);

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "INSERT INTO status_incidents (title, status_id, comment, created_by_id, created_datetime, updated_datetime, resolved_datetime)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP(), " . ($status[2] ? "UTC_TIMESTAMP()" : "NULL") . ")"
            )->execute([$title, $status[0], $comment, $ctx->actorId]);
            $incidentId = (int)$conn->lastInsertId();
            self::replaceIncidentServices($conn, $incidentId, $links);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
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

        $links = (isset($in['services']) && is_array($in['services'])) ? self::validateIncidentServices($conn, $in['services']) : null;

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
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Delete an incident + its service links, atomically. */
    public static function deleteIncident(PDO $conn, ActorContext $ctx, int $id): void
    {
        self::loadIncidentRow($conn, $id);                         // 404 if gone
        $conn->beginTransaction();
        try {
            $conn->prepare("DELETE FROM status_incident_services WHERE incident_id = ?")->execute([$id]);
            $conn->prepare("DELETE FROM status_incidents WHERE id = ?")->execute([$id]);
            $conn->commit();
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            throw $e;
        }
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
    private static function validateIncidentServices(PDO $conn, array $services): array
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
                $name = trim((string)($s['impact_level'] ?? 'Operational'));
                $imp = $conn->prepare("SELECT id FROM service_impact_levels WHERE name = ? AND is_active = 1");
                $imp->execute([$name !== '' ? $name : 'Operational']);
            }
            $impactId = $imp->fetchColumn();
            if ($impactId === false) {
                throw new ServiceError('validation', 'invalid_field', 'Unknown or inactive impact level: ' . ($s['impact_level'] ?? $s['impact_level_id'] ?? ''));
            }
            $out[] = [$serviceId, (int)$impactId];
        }
        return $out;
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
        $conn->prepare("DELETE FROM service_incident_statuses WHERE id = ?")->execute([$id]);
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
                    "UPDATE service_impact_levels SET name=?, colour=?, is_default=?, severity_order=?, display_order=?, is_active=? WHERE id=?"
                )->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active, $id]);
            } else {
                $conn->prepare(
                    "INSERT INTO service_impact_levels (name, colour, is_default, severity_order, display_order, is_active) VALUES (?, ?, ?, ?, ?, ?)"
                )->execute([$name, $colour ?: null, $is_default, $severity_order, $display_order, $is_active]);
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
        $conn->prepare("DELETE FROM service_impact_levels WHERE id = ?")->execute([$id]);
    }
}
