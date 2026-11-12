<?php
/**
 * CMDB impact analysis — the ONE implementation of "what breaks if this breaks".
 *
 * Lives in includes/ rather than api/cmdb/ because the internal endpoint
 * (api/cmdb/get_object_impact.php) and the public REST API
 * (api/v1/resources/cmdb.php) both need it. Before this file they each carried
 * their own copy of the descendants walk.
 *
 * Two things are computed, and they answer different questions:
 *
 *   cmdbDirectImpact()  — what is attached to this object right now, one hop out,
 *                         split into the three buckets the UI and the REST API
 *                         have always returned. Shape is frozen: /cmdb/objects/
 *                         {id}/impact is a published contract.
 *
 *   cmdbBlastRadius()   — what would ultimately be affected if this object
 *                         failed, following impact-bearing edges as far as they
 *                         go. This is the transitive view: a server takes out a
 *                         VM, which takes out an application, which takes out a
 *                         customer-facing service.
 *
 * WHICH EDGES CARRY IMPACT
 * ------------------------
 * Not every link means "a failure travels this way", and the ones that do don't
 * all point the same way. Following every edge blindly would report that a
 * server failure affects the building it is located in.
 *
 *   Containment (parent_id)  — always carries impact. Parent semantics in this
 *                              module are ontological dependency (the FK
 *                              cascade-deletes), so if the parent is gone the
 *                              children are gone. Direction: parent -> child.
 *
 *   Relationships            — only when the *type* says so, via
 *                              cmdb_relationship_types.impact_direction:
 *                                'none'    ignore it ("is located in")
 *                                'to_from' the TO object failing affects the
 *                                          FROM object ("A depends on B")
 *                                'from_to' the FROM object failing affects the
 *                                          TO object ("A hosts B")
 *
 *   object_ref properties    — only when cmdb_class_properties.spreads_impact
 *                              is set. A dependency recorded as a field rather
 *                              than a relationship, e.g. a Database whose
 *                              "Host Server" points at a Server. Direction is
 *                              always referenced -> holder.
 *
 * Everything defaults to "does not spread", so an upgraded install reports
 * exactly what it did before until someone deliberately configures it.
 *
 * MULTI-COMPANY
 * -------------
 * A CI belongs to exactly one company and the model forbids cross-company
 * links (see the CMDB wiki page). The walk still filters by the root's company
 * rather than trusting that invariant — one bad row predating the rule would
 * otherwise let a blast radius enumerate another company's estate, and the
 * caller has already authorised the root only. NULL tenant_id means the default
 * company's, so it is resolved before comparing.
 */

// Required, not guarded with function_exists(). The company filter below is a
// security rule: if these helpers were merely assumed-present and weren't, the
// guard would fail open and the walk would cross companies silently.
require_once __DIR__ . '/tenancy.php';

/** A relationship type that does not propagate failure. */
const CMDB_IMPACT_NONE    = 'none';
/** "A depends on B" — B failing affects A. */
const CMDB_IMPACT_TO_FROM = 'to_from';
/** "A hosts B" — A failing affects B. */
const CMDB_IMPACT_FROM_TO = 'from_to';

/** The values impact_direction is allowed to hold. */
function cmdbImpactDirections(): array {
    return [CMDB_IMPACT_NONE, CMDB_IMPACT_TO_FROM, CMDB_IMPACT_FROM_TO];
}

/**
 * Has anyone actually configured an edge that carries impact?
 *
 * Needed because an empty blast radius is ambiguous: "nothing depends on this"
 * and "nothing in this install is set to spread impact yet" look identical, and
 * they call for completely different responses from the reader. An install that
 * upgraded into this feature starts with everything set to 'none', so without
 * this the panel would look broken rather than unconfigured.
 */
function cmdbHasImpactEdgesConfigured(PDO $conn): bool {
    $rel = (int) $conn->query(
        "SELECT COUNT(*) FROM cmdb_relationship_types
          WHERE impact_direction <> '" . CMDB_IMPACT_NONE . "' AND COALESCE(is_active, 1) = 1"
    )->fetchColumn();
    if ($rel > 0) return true;

    $prop = (int) $conn->query(
        "SELECT COUNT(*) FROM cmdb_class_properties
          WHERE spreads_impact = 1 AND property_type = 'object_ref'"
    )->fetchColumn();
    return $prop > 0;
}

/**
 * Resolve the company a walk is allowed to stay inside, or null when the
 * install is single-company and there is nothing to constrain.
 *
 * @param int $rootId  cmdb_objects.id the walk starts from; its company (NULL read as the Default) is the scope
 */
function cmdbImpactTenantScope(PDO $conn, int $rootId): ?int {
    if (!isMultiTenant($conn)) {
        return null;
    }
    $stmt = $conn->prepare("SELECT tenant_id FROM cmdb_objects WHERE id = ?");
    $stmt->execute([$rootId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return ($row['tenant_id'] === null) ? (int) getDefaultTenantId($conn) : (int) $row['tenant_id'];
}

/**
 * The three one-hop buckets. Shape is a published API contract — add keys here,
 * never rename or remove them.
 *
 * @param int $id  cmdb_objects.id to look one hop out from
 */
function cmdbDirectImpact(PDO $conn, int $id): array {
    // Descendants: the whole containment subtree, with hop count from the root.
    $descendants = [];
    $stack = [['id' => $id, 'depth' => 0]];
    $seen  = [$id => true];
    $hops  = 0;
    $childStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name
           FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id
          WHERE o.parent_id = ?"
    );
    while ($stack && $hops < 1000) {
        $cur = array_pop($stack);
        $childStmt->execute([$cur['id']]);
        foreach ($childStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cid = (int) $row['id'];
            if (isset($seen[$cid])) continue;
            $seen[$cid] = true;
            $descendants[] = [
                'id'         => $cid,
                'name'       => $row['name'],
                'class_name' => $row['class_name'],
                'depth'      => $cur['depth'] + 1,
            ];
            $stack[] = ['id' => $cid, 'depth' => $cur['depth'] + 1];
        }
        $hops++;
    }

    // Objects whose object_ref property points at this one.
    $propRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, p.label AS property_label
           FROM cmdb_object_properties op
           JOIN cmdb_objects o ON o.id = op.object_id
           JOIN cmdb_classes c ON c.id = o.class_id
           JOIN cmdb_class_properties p ON p.id = op.property_id
          WHERE op.value_object_id = ?
       ORDER BY c.name, o.name"
    );
    $propRefStmt->execute([$id]);
    $referencedByProperty = $propRefStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($referencedByProperty as &$r) { $r['id'] = (int) $r['id']; }
    unset($r);

    // Incoming relationships, shown with the inverse verb so they read from
    // this object's side ("X depends on this").
    $relRefStmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, rt.verb, rt.inverse_verb
           FROM cmdb_object_relationships r
           JOIN cmdb_objects o ON o.id = r.from_object_id
           JOIN cmdb_classes c ON c.id = o.class_id
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
          WHERE r.to_object_id = ?
       ORDER BY rt.display_order, o.name"
    );
    $relRefStmt->execute([$id]);
    $referencedByRelationship = $relRefStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($referencedByRelationship as &$r2) { $r2['id'] = (int) $r2['id']; }
    unset($r2);

    return [
        'descendants'                => $descendants,
        'referenced_by_property'     => $referencedByProperty,
        'referenced_by_relationship' => $referencedByRelationship,
    ];
}

/**
 * Transitive blast radius: everything reachable from $rootId along impact-bearing
 * edges. Breadth-first, so the depth recorded against each object is the SHORTEST
 * number of hops from the root — which is what the panel groups by, and what
 * makes "2 hops away" meaningful rather than an artefact of traversal order.
 *
 * Guards: an object is claimed by the first (shortest) path that reaches it, so
 * cycles terminate; $maxNodes and $maxDepth bound pathological estates. When
 * either bites, 'truncated' comes back true — the caller must say so rather than
 * present a capped list as the whole answer.
 *
 * @param int $rootId  cmdb_objects.id whose failure is being modelled
 * @param ?int $tenantScope  company the walk stays inside (see cmdbImpactTenantScope()); null means no limit
 * @param int $maxDepth  most hops to follow from the root
 * @param int $maxNodes  most objects to collect before stopping and flagging truncated
 * @return array{nodes: array, truncated: bool, max_depth_reached: int}
 */
function cmdbBlastRadius(PDO $conn, int $rootId, ?int $tenantScope = null, int $maxDepth = 20, int $maxNodes = 1000): array {
    $nodes     = [];              // id => node
    $seen      = [$rootId => true];
    $frontier  = [$rootId];
    $depth     = 0;
    $truncated = false;

    while ($frontier && $depth < $maxDepth) {
        $depth++;
        $edges = cmdbImpactEdgesFrom($conn, $frontier, $tenantScope);
        $next  = [];
        foreach ($edges as $e) {
            $id = (int) $e['id'];
            if (isset($seen[$id])) continue;          // shortest path already claimed it
            if (count($nodes) >= $maxNodes) { $truncated = true; break 2; }
            $seen[$id] = true;
            $e['depth'] = $depth;
            $nodes[$id] = $e;
            $next[] = $id;
        }
        $frontier = $next;
    }
    if ($frontier && $depth >= $maxDepth) {
        $truncated = true;                            // more to find, depth cap bit
    }

    $out = array_values($nodes);
    usort($out, function ($a, $b) {
        return [$a['depth'], $a['class_name'], $a['name']] <=> [$b['depth'], $b['class_name'], $b['name']];
    });

    $graded = cmdbImpactGrade($conn, $rootId, $out, $tenantScope, $truncated);

    return [
        'nodes'             => $out,
        'truncated'         => $truncated,
        // GH #199 Part C. Additive: every node also carries grade, grade_via, unknown_via.
        'grades_used'       => $graded['used'],
        'grade_counts'      => $graded['counts'],
        'limits'            => $graded['limits'],
        'max_depth_reached' => $out ? max(array_column($out, 'depth')) : 0,
        // Only worth asking when the answer was empty, and it changes what the
        // empty state should say.
        'no_impact_edges_configured' => $out ? false : !cmdbHasImpactEdgesConfigured($conn),
    ];
}

/**
 * One hop out from every id in $fromIds, across all three edge kinds.
 *
 * Batched — one query per edge kind per level rather than per object, so a wide
 * estate costs a handful of queries rather than hundreds. Each row carries how
 * it was reached (via_kind / via_label / from_id / from_name) so the panel can
 * explain the path instead of just listing names.
 *
 * @param array $fromIds  cmdb_objects ids of the current frontier; cast to int and de-duplicated here
 * @param ?int $tenantScope  company the reached objects must belong to; null means no limit
 */
function cmdbImpactEdgesFrom(PDO $conn, array $fromIds, ?int $tenantScope = null): array {
    $fromIds = array_values(array_unique(array_map('intval', $fromIds)));
    if (!$fromIds) return [];

    $ph = implode(',', array_fill(0, count($fromIds), '?'));

    // Company gate. NULL tenant_id means the default company's, so resolve
    // before comparing or a single-company estate (every row NULL) matches nothing.
    $tenantSql    = '';
    $tenantParams = [];
    // TRAP: NULL becomes the DEFAULT company, never the scope. COALESCE(o.tenant_id, scope)
    // made an object with no company match EVERY company's walk - so another company's blast
    // radius took in default-company objects it may not see (analystCanAccessCmdbObject()
    // resolves NULL the same way).
    if ($tenantScope !== null) {
        $tenantSql    = " AND COALESCE(o.tenant_id, ?) = ? ";
        $tenantParams = [(int) getDefaultTenantId($conn), $tenantScope];
    }

    $rows = [];

    // via_rel_id is the cmdb_object_relationships row an edge came from, where
    // one exists. Only relationship edges have one — containment and property
    // edges are not rows in that table — and it lets a diagram built from a
    // blast radius provenance-link its connectors the same way Network Mapper's
    // own "add related objects" flow does.

    // 1. Containment: parent fails, children go with it.
    $sql = "SELECT o.id, o.name, c.name AS class_name, o.parent_id AS via_from,
                   'child' AS via_kind, NULL AS via_label, NULL AS via_rel_id, NULL AS via_type_id
              FROM cmdb_objects o
              JOIN cmdb_classes c ON c.id = o.class_id
             WHERE o.parent_id IN ($ph) $tenantSql";
    $st = $conn->prepare($sql);
    $st->execute(array_merge($fromIds, $tenantParams));
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));

    // 2a. Relationships where the TO side failing affects the FROM side
    //     ("A depends on B" — B is in $fromIds, A is affected).
    $sql = "SELECT o.id, o.name, c.name AS class_name, r.to_object_id AS via_from,
                   'relationship' AS via_kind, rt.verb AS via_label, r.id AS via_rel_id, r.relationship_type_id AS via_type_id
              FROM cmdb_object_relationships r
              JOIN cmdb_objects o ON o.id = r.from_object_id
              JOIN cmdb_classes c ON c.id = o.class_id
              JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
             WHERE rt.impact_direction = '" . CMDB_IMPACT_TO_FROM . "'
               AND COALESCE(rt.is_active, 1) = 1
               AND r.to_object_id IN ($ph) $tenantSql";
    $st = $conn->prepare($sql);
    $st->execute(array_merge($fromIds, $tenantParams));
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));

    // 2b. Relationships where the FROM side failing affects the TO side
    //     ("A hosts B" — A is in $fromIds, B is affected). Labelled with the
    //     inverse verb, because it reads from the affected object's side.
    $sql = "SELECT o.id, o.name, c.name AS class_name, r.from_object_id AS via_from,
                   'relationship' AS via_kind, rt.inverse_verb AS via_label, r.id AS via_rel_id, r.relationship_type_id AS via_type_id
              FROM cmdb_object_relationships r
              JOIN cmdb_objects o ON o.id = r.to_object_id
              JOIN cmdb_classes c ON c.id = o.class_id
              JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
             WHERE rt.impact_direction = '" . CMDB_IMPACT_FROM_TO . "'
               AND COALESCE(rt.is_active, 1) = 1
               AND r.from_object_id IN ($ph) $tenantSql";
    $st = $conn->prepare($sql);
    $st->execute(array_merge($fromIds, $tenantParams));
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));

    // 3. object_ref properties flagged as dependencies: the referenced object
    //    failing affects whoever points at it.
    $sql = "SELECT o.id, o.name, c.name AS class_name, op.value_object_id AS via_from,
                   'property' AS via_kind, p.label AS via_label, NULL AS via_rel_id, NULL AS via_type_id
              FROM cmdb_object_properties op
              JOIN cmdb_class_properties p ON p.id = op.property_id
              JOIN cmdb_objects o ON o.id = op.object_id
              JOIN cmdb_classes c ON c.id = o.class_id
             WHERE p.spreads_impact = 1
               AND p.property_type = 'object_ref'
               AND op.value_object_id IN ($ph) $tenantSql";
    $st = $conn->prepare($sql);
    $st->execute(array_merge($fromIds, $tenantParams));
    $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC));

    // Resolve the "reached via" name in one go rather than per row.
    $viaIds = array_values(array_unique(array_filter(array_map(function ($r) {
        return (int) $r['via_from'];
    }, $rows))));
    $names = [];
    if ($viaIds) {
        $vph = implode(',', array_fill(0, count($viaIds), '?'));
        $st = $conn->prepare("SELECT id, name FROM cmdb_objects WHERE id IN ($vph)");
        $st->execute($viaIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $n) { $names[(int) $n['id']] = $n['name']; }
    }

    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['via_from']   = (int) $r['via_from'];
        $r['via_name']   = $names[$r['via_from']] ?? null;
        $r['via_rel_id'] = isset($r['via_rel_id']) && $r['via_rel_id'] !== null ? (int) $r['via_rel_id'] : null;
        $r['via_type_id'] = isset($r['via_type_id']) && $r['via_type_id'] !== null ? (int) $r['via_type_id'] : null;
    }
    unset($r);

    return $rows;
}

// ===========================================================================
//  Redundancy-aware grades (GH #199 Part C)
// ===========================================================================
//
// The walk above answers "what is reachable from this failure". This answers "how
// badly", for installs that mark relationship types as redundant membership (a node
// "is a member of" a cluster) or placement with failover (a highly available VM "runs
// on" a node). Without such a type every node is simply "affected", exactly as before.
//
// THE GRADES, most severe first:
//   affected            nothing in the model suggests it survives
//   may_be_interrupted  it may stop; the model shows another member it may restart on,
//                       and says nothing about whether that will work
//   redundancy_reduced  one or more members of a redundant group are lost. NOT a
//                       statement that it keeps working: quorum, witness, storage,
//                       capacity and current data are not modelled (agreed on #199)
//   unknown             the model lacks the facts to say
//
// THE PATH RULE (agreed on #199):
//   1. Each path gives a grade; along an ordinary dependency a node takes the grade of
//      what it depends on.
//   2. A node's grade is the MOST SEVERE established grade among its paths. A possible
//      failover on one path never softens a hard dependency on another.
//   3. Unknown is never hidden: a path that depends on a missing fact is listed in
//      unknown_via beside the grade; a node reached only through such paths is unknown.
//
// Grades only ever rise as more members turn out to be lost, so a fixpoint loop over
// the reached subgraph settles them.
//
// TRAP: a placement type is for HIGHLY AVAILABLE roles only. A VM that merely runs on a
//   cluster node is an ordinary dependency (affected when the node fails) - Part B maps
//   the two to different relationship types for exactly this reason.

/** The setting: what a member with no recorded state counts as - 'assume' (available, and say so) or 'unknown'. */
const CMDB_IMPACT_UNKNOWN_SETTING = 'cmdb_impact_unknown_members';

/** The grade names, from least to most severe ('unknown' is not on the ladder). */
function cmdbImpactGradeRank(): array {
    return ['redundancy_reduced' => 1, 'may_be_interrupted' => 2, 'affected' => 3];
}

/**
 * The state FreeITSM knows a CI to be in: 'up', 'down', 'unknown', or null when nothing
 * records it (a CI drawn by hand). Part B fills this from discovery for source-bound CIs.
 */
function cmdbMemberState(PDO $conn, int $ciId): ?string {
    require_once __DIR__ . '/services/virtual_estate_cmdb.php';
    return VirtualEstateCmdbService::memberState($conn, $ciId);
}

/**
 * The CIs a highly available CI may run on, when discovery knows (its possible owners), or
 * null - then every other member of its host's group counts.
 *
 * @return ?int[]
 */
function cmdbPossibleOwners(PDO $conn, int $ciId): ?array {
    require_once __DIR__ . '/services/virtual_estate_cmdb.php';
    return VirtualEstateCmdbService::possibleOwnerCis($conn, $ciId);
}

/**
 * Grade the blast radius in place: each node gains grade, grade_via (who gave it) and
 * unknown_via (names), and the result says whether redundancy rules were used, counts per
 * grade, and the limits the panel must always show when they were.
 *
 * @param array $nodes  cmdbBlastRadius()'s node list, modified in place
 * @return array{used: bool, counts: array, limits: array}
 */
function cmdbImpactGrade(PDO $conn, int $rootId, array &$nodes, ?int $tenantScope, bool $truncated): array {
    $types = [];
    try {
        foreach ($conn->query("SELECT id, redundancy, impact_direction FROM cmdb_relationship_types
                                WHERE redundancy IN ('membership', 'placement') AND COALESCE(is_active, 1) = 1")->fetchAll(PDO::FETCH_ASSOC) as $t) {
            $types[(int) $t['id']] = ['kind' => $t['redundancy'], 'direction' => $t['impact_direction']];
        }
    } catch (PDOException $e) {
        $types = [];   // before Database Verification adds the column: no redundancy rules
    }
    $counts = ['affected' => 0, 'may_be_interrupted' => 0, 'redundancy_reduced' => 0, 'unknown' => 0];
    if (!$types || !$nodes) {
        foreach ($nodes as &$n) { $n['grade'] = 'affected'; $n['grade_via'] = null; $n['unknown_via'] = []; }
        unset($n);
        $counts['affected'] = count($nodes);
        return ['used' => false, 'counts' => $counts, 'limits' => []];
    }

    $assume = true;
    try {
        $st = $conn->prepare('SELECT setting_value FROM system_settings WHERE setting_key = ?');
        $st->execute([CMDB_IMPACT_UNKNOWN_SETTING]);
        $assume = $st->fetchColumn() !== 'unknown';
    } catch (Throwable $e) { /* default: assume, and say so */ }

    // Every impact edge inside the reached subgraph, labelled ordinary / membership / placement.
    $reached = [$rootId => true];
    $name = [$rootId => null];
    foreach ($nodes as $n) { $reached[$n['id']] = true; $name[$n['id']] = $n['name']; }
    $incoming = [];
    $used = false;
    foreach (cmdbImpactEdgesFrom($conn, array_keys($reached), $tenantScope) as $e) {
        if (!isset($reached[$e['id']]) || $e['id'] === $rootId) { continue; }
        $kind = ($e['via_type_id'] !== null && isset($types[$e['via_type_id']])) ? $types[$e['via_type_id']]['kind'] : 'ordinary';
        if ($kind !== 'ordinary') { $used = true; }
        $incoming[$e['id']][] = ['from' => $e['via_from'], 'kind' => $kind, 'label' => $e['via_label'], 'from_name' => $e['via_name']];
    }
    if ($name[$rootId] === null) {
        $st = $conn->prepare('SELECT name FROM cmdb_objects WHERE id = ?');
        $st->execute([$rootId]);
        $name[$rootId] = (string) $st->fetchColumn();
    }

    // Who is a member of which redundant group - from ALL membership relationships, not only
    // the reached ones: a group's other members are exactly the ones the failure did not reach.
    $memberTypes = array_keys(array_filter($types, fn($t) => $t['kind'] === 'membership'));
    $membersOf = []; $groupsOf = [];
    if ($memberTypes) {
        $in = implode(',', $memberTypes);
        // NULL = the default company (the same TRAP as cmdbImpactEdgesFrom()); `a` is the member object.
        $tenantSql = $tenantScope !== null ? ' AND COALESCE(a.tenant_id, ' . (int) getDefaultTenantId($conn) . ') = ' . (int) $tenantScope : '';
        foreach ($conn->query("SELECT r.from_object_id, r.to_object_id, rt.impact_direction FROM cmdb_object_relationships r
                JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
                JOIN cmdb_objects a ON a.id = r.from_object_id
                WHERE r.relationship_type_id IN ($in)$tenantSql")->fetchAll(PDO::FETCH_ASSOC) as $m) {
            // "member of" points member -> group when the member failing affects the group.
            [$member, $group] = $m['impact_direction'] === CMDB_IMPACT_TO_FROM
                ? [(int) $m['to_object_id'], (int) $m['from_object_id']] : [(int) $m['from_object_id'], (int) $m['to_object_id']];
            $membersOf[$group][$member] = true;
            $groupsOf[$member][$group] = true;
        }
    }
    $memberNames = [];
    $nameOf = function (int $id) use ($conn, &$name, &$memberNames): string {
        if (isset($name[$id])) { return (string) $name[$id]; }
        if (!isset($memberNames[$id])) {
            $st = $conn->prepare('SELECT name FROM cmdb_objects WHERE id = ?');
            $st->execute([$id]);
            $memberNames[$id] = (string) $st->fetchColumn();
        }
        return $memberNames[$id];
    };

    $rank = cmdbImpactGradeRank();
    $grade = [$rootId => 'affected'];
    $assumed = [];
    // Of these members, which may still be up? 'up', 'assumed' (no recorded state, and the
    // setting assumes) or 'unknown'. A member graded affected is down.
    $alternatives = function (array $members) use ($conn, &$grade, $assume, &$assumed, $nameOf): array {
        $out = ['up' => [], 'assumed' => [], 'unknown' => []];
        foreach ($members as $m) {
            if (($grade[$m] ?? null) === 'affected') { continue; }
            $state = cmdbMemberState($conn, $m);
            if ($state === 'down') { continue; }
            if ($state === 'up') { $out['up'][] = $m; }
            elseif ($state === null && $assume) { $out['assumed'][] = $m; }
            else { $out['unknown'][] = $m; }
        }
        return $out;
    };

    $result = [];
    for ($round = 0; $round < 50; $round++) {
        $changed = false;
        foreach ($incoming as $target => $edges) {
            $best = null; $bestVia = null; $unknownVia = [];
            foreach ($edges as $e) {
                $g = $grade[$e['from']] ?? null;
                if ($g === null) { continue; }
                if ($g === 'unknown') { $unknownVia[$e['from']] = true; continue; }
                $via = ['from' => $e['from'], 'name' => $e['from_name'], 'label' => $e['label'], 'kind' => $e['kind']];
                $got = $g;
                if ($e['kind'] === 'placement' && $g === 'affected') {
                    // The host failed: is there another member of its group(s) to restart on?
                    $others = [];
                    foreach (array_keys($groupsOf[$e['from']] ?? []) as $grp) {
                        foreach (array_keys($membersOf[$grp] ?? []) as $m) { if ($m !== $e['from']) { $others[$m] = true; } }
                    }
                    // Narrowed to the nodes the cluster may run it on, when discovery knows them.
                    $owners = cmdbPossibleOwners($conn, $target);
                    if ($owners !== null) { $others = array_intersect_key($others, array_flip($owners)); }
                    $alt = $alternatives(array_keys($others));
                    if ($alt['up'] || $alt['assumed']) {
                        $got = 'may_be_interrupted';
                        foreach ($alt['assumed'] as $m) { if (!$alt['up']) { $assumed[$m] = $nameOf($m); } }
                    } elseif ($alt['unknown']) { $got = 'unknown'; }
                    else { $got = 'affected'; }
                } elseif ($e['kind'] === 'membership') {
                    if ($g === 'redundancy_reduced') { continue; }   // a member with less spare capacity does not reduce the group
                    $alt = $alternatives(array_values(array_diff(array_keys($membersOf[$target] ?? []), [$e['from']])));
                    $lost = $g === 'affected' ? 'affected' : 'may_be_interrupted';   // the whole group, if this was its last member
                    if (!$alt['up'] && !$alt['assumed'] && !$alt['unknown']) { $got = $lost; }
                    elseif (!$alt['up'] && !$alt['assumed']) { $got = 'unknown'; }
                    else {
                        $got = 'redundancy_reduced';
                        foreach ($alt['assumed'] as $m) { if (!$alt['up']) { $assumed[$m] = $nameOf($m); } }
                    }
                }
                if ($got === 'unknown') { $unknownVia[$e['from']] = true; continue; }
                if ($best === null || $rank[$got] > $rank[$best]) { $best = $got; $bestVia = $via; }
            }
            $new = $best ?? ($unknownVia ? 'unknown' : null);
            if ($new !== null && ($grade[$target] ?? null) !== $new) {
                $grade[$target] = $new;
                $changed = true;
            }
            $result[$target] = ['grade' => $new ?? 'unknown', 'via' => $bestVia,
                'unknown_via' => array_values(array_map(fn($id) => $nameOf((int) $id), array_keys($unknownVia)))];
        }
        if (!$changed) { break; }
    }

    foreach ($nodes as &$n) {
        $r = $result[$n['id']] ?? ['grade' => 'affected', 'via' => null, 'unknown_via' => []];
        $n['grade'] = $r['grade'];
        $n['grade_via'] = $r['via'];
        $n['unknown_via'] = $r['unknown_via'];
        $counts[$r['grade']]++;
    }
    unset($n);

    $limits = [];
    if ($used) {
        // What the model does not know - always shown when a redundancy rule was used.
        // placement_rules: possible owners ARE applied to discovered VMs (cmdbPossibleOwners);
        // preferred owners, anti-affinity and drawn-by-hand CIs are not.
        $limits = ['quorum', 'storage', 'capacity', 'placement_rules'];
        if ($assumed) { $limits[] = ['code' => 'assumed_available', 'names' => array_values($assumed)]; }
    }
    if ($used && $truncated) { $limits[] = 'truncated'; }
    return ['used' => $used, 'counts' => $counts, 'limits' => $limits];
}