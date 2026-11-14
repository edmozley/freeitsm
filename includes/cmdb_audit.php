<?php
/**
 * CMDB data-quality audit — "is this CMDB still telling you the truth?"
 *
 * A CMDB rots quietly. Nothing errors when a record goes stale, loses the field
 * that made it useful, or ends up connected to nothing; it simply stops being
 * worth opening, and by the time anyone notices, nobody trusts it. That is the
 * usual reason these modules get abandoned.
 *
 * The checks are deliberately framed around whether the data can still answer
 * the question the module exists to answer — what breaks if this breaks. A CI
 * connected to nothing is not a stylistic problem, it is a CI that can never
 * appear in anyone's blast radius.
 *
 * DESIGN
 * ------
 * Every check is derived from declarations that already exist — a property
 * marked required, a property ticked as a dependency, a relationship type set
 * to carry impact. There is no rule-builder and no new schema: the install has
 * already stated its intent, and the audit only reports where reality has
 * drifted from it. (iTop's equivalent lets you write the rules; that is a much
 * bigger feature and it can come later without breaking this one.)
 *
 * Findings are advisory. Nothing here edits, blocks or "fixes" anything — the
 * analyst is the one who knows whether a standalone CI is a mistake or simply a
 * standalone CI.
 */

require_once __DIR__ . '/tenancy.php';

/** Objects untouched for longer than this are reported as stale. */
const CMDB_AUDIT_STALE_MONTHS = 6;
/** Per-check cap on returned rows. The true total is always reported alongside. */
const CMDB_AUDIT_MAX_ITEMS = 100;

/**
 * Run every check.
 *
 * @return array{checks: array, total_findings: int, objects_examined: int}
 */
function cmdbRunAudit(PDO $conn, int $analystId): array {
    $checks = [
        cmdbAuditRequiredMissing($conn, $analystId),
        cmdbAuditBrokenReferences($conn, $analystId),
        cmdbAuditRemovedRecords($conn, $analystId),
        cmdbAuditDependencyBlank($conn, $analystId),
        cmdbAuditDisconnected($conn, $analystId),
        cmdbAuditStale($conn, $analystId),
        cmdbAuditNoImpactEdges($conn),
    ];

    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $st = $conn->prepare("SELECT COUNT(*) FROM cmdb_objects o WHERE 1=1 $tSql");
    $st->execute($tArgs);

    return [
        'checks'           => $checks,
        'total_findings'   => array_sum(array_column($checks, 'count')),
        'objects_examined' => (int) $st->fetchColumn(),
    ];
}

/**
 * Shape every check returns, so the UI can render them uniformly.
 *
 * @param string $key  the check's identifier, e.g. 'broken_reference', 'stale'
 * @param string $severity  'error', 'warning' or 'info'
 * @param int $count  the true number of findings, which may exceed the items listed
 * @param array $items  up to CMDB_AUDIT_MAX_ITEMS finding rows (object_id, object_name, class_name, detail)
 */
function cmdbAuditResult(string $key, string $severity, int $count, array $items): array {
    return [
        'key'      => $key,
        'severity' => $severity,          // error | warning | info
        'count'    => $count,             // the TRUE total
        'items'    => $items,             // capped at CMDB_AUDIT_MAX_ITEMS
        'capped'   => $count > count($items),
    ];
}

/**
 * A property the class marks required, with no value on the object.
 *
 * This is legitimately reachable without anyone doing anything wrong: mark an
 * existing property required and every object created before that moment is
 * instantly non-compliant. docs/cmdb.md lists that as an open question — block
 * the change, prompt for a default, or leave them invalid. This answers it:
 * leave them, and surface them here.
 */
function cmdbAuditRequiredMissing(PDO $conn, int $analystId): array {
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $from = "FROM cmdb_objects o
             JOIN cmdb_classes c ON c.id = o.class_id
             JOIN cmdb_class_properties p ON p.class_id = o.class_id AND p.is_required = 1
        LEFT JOIN cmdb_object_properties op ON op.object_id = o.id AND op.property_id = p.id
            WHERE (op.id IS NULL
                   OR (op.value_text IS NULL AND op.value_number IS NULL
                       AND op.value_date IS NULL AND op.value_boolean IS NULL
                       AND op.value_object_id IS NULL)) $tSql";

    $st = $conn->prepare("SELECT COUNT(*) $from");
    $st->execute($tArgs);
    $count = (int) $st->fetchColumn();

    $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                 p.label AS detail $from ORDER BY c.name, o.name LIMIT " . CMDB_AUDIT_MAX_ITEMS);
    $st->execute($tArgs);

    return cmdbAuditResult('required_missing', 'warning', $count, cmdbAuditRows($st));
}

/**
 * An object reference pointing at an object that no longer exists.
 *
 * Genuine dangling data rather than an omission, which is why this is the only
 * check rated as an error. Installs grown through Database Verification have no
 * CMDB foreign keys at all, so nothing at the database level prevents it.
 */
function cmdbAuditBrokenReferences(PDO $conn, int $analystId): array {
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $from = "FROM cmdb_object_properties op
             JOIN cmdb_class_properties p ON p.id = op.property_id AND p.property_type = 'object_ref'
             JOIN cmdb_objects o ON o.id = op.object_id
             JOIN cmdb_classes c ON c.id = o.class_id
        LEFT JOIN cmdb_objects target ON target.id = op.value_object_id
            WHERE op.value_object_id IS NOT NULL AND target.id IS NULL $tSql";

    $st = $conn->prepare("SELECT COUNT(*) $from");
    $st->execute($tArgs);
    $count = (int) $st->fetchColumn();

    $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                 p.label AS detail $from ORDER BY c.name, o.name LIMIT " . CMDB_AUDIT_MAX_ITEMS);
    $st->execute($tArgs);

    return cmdbAuditResult('broken_reference', 'error', $count, cmdbAuditRows($st));
}

/**
 * GH #312: a person / team / location ... property whose record has been deleted.
 *
 * Deleting a record a CI points at is allowed on purpose (the delete dialog says
 * which CIs point at it first), and the CI keeps the id and shows "(removed)".
 * This is where those are found and fixed. A warning, not an error: the CI's own
 * data is intact - it just names something that is gone.
 */
function cmdbAuditRemovedRecords(PDO $conn, int $analystId): array {
    require_once __DIR__ . '/cmdb_record_refs.php';
    if (!cmdbRecordRefsReady($conn)) {
        return cmdbAuditResult('record_removed', 'warning', 0, []);
    }
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $count = 0;
    $items = [];
    foreach (cmdbRecordTypes() as $type => $t) {
        $from = "FROM cmdb_object_properties op
                 JOIN cmdb_class_properties p ON p.id = op.property_id AND p.property_type = ?
                 JOIN cmdb_objects o ON o.id = op.object_id
                 JOIN cmdb_classes c ON c.id = o.class_id
            LEFT JOIN `{$t['table']}` r ON r.id = op.value_ref_id
                WHERE op.value_ref_id IS NOT NULL AND r.id IS NULL $tSql";
        $st = $conn->prepare("SELECT COUNT(*) $from");
        $st->execute(array_merge([$type], $tArgs));
        $n = (int)$st->fetchColumn();
        if (!$n) continue;
        $count += $n;
        $room = CMDB_AUDIT_MAX_ITEMS - count($items);
        if ($room > 0) {
            $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                         p.label AS detail $from ORDER BY c.name, o.name LIMIT " . (int)$room);
            $st->execute(array_merge([$type], $tArgs));
            $items = array_merge($items, cmdbAuditRows($st));
        }
    }
    return cmdbAuditResult('record_removed', 'warning', $count, $items);
}

/**
 * A property ticked "this is a dependency" that is blank on an object.
 *
 * Only meaningful because of the impact feature: the install has said this
 * field records a real dependency, so a blank one is a hole in the blast radius
 * rather than merely an empty field. Says nothing about properties nobody has
 * marked as dependencies.
 */
function cmdbAuditDependencyBlank(PDO $conn, int $analystId): array {
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $from = "FROM cmdb_objects o
             JOIN cmdb_classes c ON c.id = o.class_id
             JOIN cmdb_class_properties p ON p.class_id = o.class_id
                  AND p.spreads_impact = 1 AND p.property_type = 'object_ref'
        LEFT JOIN cmdb_object_properties op ON op.object_id = o.id AND op.property_id = p.id
            WHERE (op.id IS NULL OR op.value_object_id IS NULL) $tSql";

    $st = $conn->prepare("SELECT COUNT(*) $from");
    $st->execute($tArgs);
    $count = (int) $st->fetchColumn();

    $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                 p.label AS detail $from ORDER BY c.name, o.name LIMIT " . CMDB_AUDIT_MAX_ITEMS);
    $st->execute($tArgs);

    return cmdbAuditResult('dependency_blank', 'info', $count, cmdbAuditRows($st));
}

/**
 * An object attached to nothing: no parent, no children, no relationships.
 *
 * Not an error — plenty of things legitimately stand alone — but it can never
 * appear in any blast radius and nobody will reach it by navigating, so it is
 * the single best indicator of a CMDB drifting into a write-only list.
 *
 * Ticket links deliberately do NOT rescue an object here. A CI referenced by a
 * ticket is being used, but it still contributes nothing to impact analysis,
 * which is what this check is about.
 */
function cmdbAuditDisconnected(PDO $conn, int $analystId): array {
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $from = "FROM cmdb_objects o
             JOIN cmdb_classes c ON c.id = o.class_id
            WHERE o.parent_id IS NULL
              AND NOT EXISTS (SELECT 1 FROM cmdb_objects ch WHERE ch.parent_id = o.id)
              AND NOT EXISTS (SELECT 1 FROM cmdb_object_relationships r
                               WHERE r.from_object_id = o.id OR r.to_object_id = o.id)
              AND NOT EXISTS (SELECT 1 FROM cmdb_object_properties op
                               JOIN cmdb_class_properties p ON p.id = op.property_id
                                    AND p.property_type = 'object_ref'
                               WHERE op.object_id = o.id AND op.value_object_id IS NOT NULL)
              AND NOT EXISTS (SELECT 1 FROM cmdb_object_properties op2
                               WHERE op2.value_object_id = o.id) $tSql";

    $st = $conn->prepare("SELECT COUNT(*) $from");
    $st->execute($tArgs);
    $count = (int) $st->fetchColumn();

    $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                 NULL AS detail $from ORDER BY c.name, o.name LIMIT " . CMDB_AUDIT_MAX_ITEMS);
    $st->execute($tArgs);

    return cmdbAuditResult('disconnected', 'info', $count, cmdbAuditRows($st));
}

/** Nothing has touched this object in CMDB_AUDIT_STALE_MONTHS. */
function cmdbAuditStale(PDO $conn, int $analystId): array {
    [$tSql, $tArgs] = activeTenantFilter($conn, $analystId, 'o');
    $from = "FROM cmdb_objects o
             JOIN cmdb_classes c ON c.id = o.class_id
            WHERE COALESCE(o.updated_datetime, o.created_datetime) < DATE_SUB(UTC_TIMESTAMP(), INTERVAL "
                  . CMDB_AUDIT_STALE_MONTHS . " MONTH) $tSql";

    $st = $conn->prepare("SELECT COUNT(*) $from");
    $st->execute($tArgs);
    $count = (int) $st->fetchColumn();

    $st = $conn->prepare("SELECT o.id AS object_id, o.name AS object_name, c.name AS class_name,
                                 DATE(COALESCE(o.updated_datetime, o.created_datetime)) AS detail
                          $from ORDER BY COALESCE(o.updated_datetime, o.created_datetime) ASC
                          LIMIT " . CMDB_AUDIT_MAX_ITEMS);
    $st->execute($tArgs);

    return cmdbAuditResult('stale', 'info', $count, cmdbAuditRows($st));
}

/**
 * Install-level: nothing at all is configured to carry impact, so every blast
 * radius on every object is empty regardless of how good the data is.
 *
 * Not per-object, so it reports 1 or 0 rather than a list. Worth its own check
 * because it makes every other finding here moot — there is no point tidying
 * dependencies that nothing will ever follow.
 */
function cmdbAuditNoImpactEdges(PDO $conn): array {
    require_once __DIR__ . '/cmdb_impact.php';
    $configured = cmdbHasImpactEdgesConfigured($conn);
    return cmdbAuditResult('no_impact_edges', 'warning', $configured ? 0 : 1, []);
}

/**
 * Normalise ids to ints for the JSON.
 *
 * @param PDOStatement $st  an executed finding query whose rows include an object_id column
 */
function cmdbAuditRows(PDOStatement $st): array {
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) { $r['object_id'] = (int) $r['object_id']; }
    unset($r);
    return $rows;
}
