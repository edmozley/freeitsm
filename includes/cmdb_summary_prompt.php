<?php
require_once __DIR__ . '/cmdb_record_refs.php';   // GH #312: person / team / location ... names

/**
 * The user message for a CMDB object's AI summary: the object, its properties, children,
 * relationships, property references and tickets - only what is in the data.
 *
 * Lifted out of api/cmdb/generate_object_summary.php (5.1.0) so a test can read exactly what
 * the AI is told without calling one. The caller has already checked the analyst may see
 * the object (analystCanAccessCmdbObject()).
 *
 * TRAP: every relationship is told as a sentence naming both ends with the FORWARD verb,
 * source first ("VM-A runs on HOST-A"). Told with the inverse verb ("VM-A hosts this") the
 * AI wrote the dependency backwards - the bug PR #297 fixed on the object page.
 *
 * @param int $id  cmdb_objects.id
 * @throws Exception 'Object not found' when there is no such object
 */
function cmdbObjectSummaryMessage(PDO $conn, int $id): string
{
    // Pull the object + class + parent
    $stmt = $conn->prepare(
        "SELECT o.id, o.name, c.name AS class_name, c.description AS class_description,
                o.parent_id, p.name AS parent_name, pc.name AS parent_class_name,
                o.is_planned
           FROM cmdb_objects o
           JOIN cmdb_classes c ON c.id = o.class_id
      LEFT JOIN cmdb_objects p ON p.id = o.parent_id
      LEFT JOIN cmdb_classes pc ON pc.id = p.class_id
          WHERE o.id = ?"
    );
    $stmt->execute([$id]);
    $obj = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$obj) throw new Exception('Object not found');

    // Properties with type-aware values, including the linked-object name for
    // object_ref properties so the prompt has names not opaque IDs
    $propStmt = $conn->prepare(
        "SELECT p.label, p.property_type,
                op.value_text, op.value_number, op.value_date, op.value_boolean,
                " . (cmdbRecordRefsReady($conn) ? 'op.value_ref_id' : 'NULL AS value_ref_id') . ",
                refo.name AS value_object_name, refoc.name AS value_object_class
           FROM cmdb_class_properties p
      LEFT JOIN cmdb_object_properties op ON op.property_id = p.id AND op.object_id = ?
      LEFT JOIN cmdb_objects refo ON refo.id = op.value_object_id
      LEFT JOIN cmdb_classes refoc ON refoc.id = refo.class_id
          WHERE p.class_id = (SELECT class_id FROM cmdb_objects WHERE id = ?)
       ORDER BY p.display_order, p.label"
    );
    $propStmt->execute([$id, $id]);
    $propLines = [];
    // GH #312: person / team / location ... names, as the person asking may see
    // them (a company they cannot reach is left out, never named to the AI).
    $sumRows = $propStmt->fetchAll(PDO::FETCH_ASSOC);
    $sumReach = null;
    if (!empty($_SESSION['analyst_id']) && !analystHasAllTenantAccess($conn, (int)$_SESSION['analyst_id'])) {
        $sumReach = getAccessibleTenantIds($conn, (int)$_SESSION['analyst_id']);
    }
    $sumRecords = [];
    foreach ($sumRows as $r) {
        if (cmdbIsRecordType($r['property_type']) && $r['value_ref_id'] !== null) $sumRecords[$r['property_type']][] = (int)$r['value_ref_id'];
    }
    foreach ($sumRecords as $rt => $ids) $sumRecords[$rt] = cmdbRecordResolve($conn, $rt, $ids, $sumReach);
    foreach ($sumRows as $r) {
        $val = null;
        if (cmdbIsRecordType($r['property_type'])) {
            $info = $r['value_ref_id'] !== null ? ($sumRecords[$r['property_type']][(int)$r['value_ref_id']] ?? null) : null;
            if ($info && $info['name'] !== null) {
                $val = $info['name'] . ($info['state'] === 'inactive' ? ' (no longer active)' : '');
            }
        }
        switch ($r['property_type']) {
            case 'text':       $val = $r['value_text']; break;
            case 'dropdown':   $val = $r['value_text']; break;
            case 'number':     $val = $r['value_number'] !== null ? (string)$r['value_number'] : null; break;
            case 'date':       $val = $r['value_date']; break;
            case 'boolean':    $val = $r['value_boolean'] === null ? null : ($r['value_boolean'] ? 'Yes' : 'No'); break;
            case 'object_ref': $val = $r['value_object_name']
                                    ? ($r['value_object_name'] . ' (' . $r['value_object_class'] . ')')
                                    : null; break;
        }
        if ($val !== null && $val !== '') {
            $propLines[] = '- ' . $r['label'] . ': ' . $val;
        }
    }

    // Children
    $chStmt = $conn->prepare(
        "SELECT o.name, c.name AS class_name
           FROM cmdb_objects o JOIN cmdb_classes c ON c.id = o.class_id
          WHERE o.parent_id = ? ORDER BY c.name, o.name"
    );
    $chStmt->execute([$id]);
    $children = $chStmt->fetchAll(PDO::FETCH_ASSOC);

    // Outgoing relationships ("this <verb> X")
    $outStmt = $conn->prepare(
        "SELECT rt.verb, oo.name, oc.name AS class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects oo ON oo.id = r.to_object_id
           JOIN cmdb_classes oc ON oc.id = oo.class_id
          WHERE r.from_object_id = ?
       ORDER BY rt.verb, oo.name"
    );
    $outStmt->execute([$id]);
    $outgoing = $outStmt->fetchAll(PDO::FETCH_ASSOC);

    // Incoming relationships (other -> this). TRAP: told to the AI with the FORWARD verb,
    // other first ("VM-A runs on HOST-A"). The inverse verb read "VM-A hosts this" -
    // backwards - and a configured verb cannot be bent round "this" in every language
    // anyway (PR #297 fixed the same on the object page).
    $inStmt = $conn->prepare(
        "SELECT rt.verb, oo.name, oc.name AS class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects oo ON oo.id = r.from_object_id
           JOIN cmdb_classes oc ON oc.id = oo.class_id
          WHERE r.to_object_id = ?
       ORDER BY rt.verb, oo.name"
    );
    $inStmt->execute([$id]);
    $incoming = $inStmt->fetchAll(PDO::FETCH_ASSOC);

    // Impact via property-references (other objects pointing at THIS via object_ref)
    $propRefStmt = $conn->prepare(
        "SELECT o.name, c.name AS class_name, p.label AS property_label
           FROM cmdb_object_properties op
           JOIN cmdb_objects o ON o.id = op.object_id
           JOIN cmdb_classes c ON c.id = o.class_id
           JOIN cmdb_class_properties p ON p.id = op.property_id
          WHERE op.value_object_id = ?"
    );
    $propRefStmt->execute([$id]);
    $propRefs = $propRefStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build the user message
    $msg = "Object: " . $obj['name'] . "\n";
    $msg .= "Class: " . $obj['class_name'];
    if (!empty($obj['class_description'])) $msg .= " (" . $obj['class_description'] . ")";
    $msg .= "\n";
    // Flag planned objects prominently so the synthesis can mention "future state"
    // rather than describing the object as if it physically existed today.
    if ((int)($obj['is_planned'] ?? 0) === 1) {
        $msg .= "Status: PLANNED — this object does not physically exist yet. It represents a future or proposed state.\n";
    }
    if ($obj['parent_id']) {
        $msg .= "Parent: " . $obj['parent_name'] . " (" . $obj['parent_class_name'] . ")\n";
    } else {
        $msg .= "Parent: none\n";
    }

    if (!empty($propLines)) {
        $msg .= "Properties (only those with values):\n" . implode("\n", $propLines) . "\n";
    } else {
        $msg .= "Properties: none filled in yet.\n";
    }

    if (!empty($children)) {
        $msg .= "Direct children:\n";
        foreach ($children as $c) $msg .= "- " . $c['name'] . " (" . $c['class_name'] . ")\n";
    }

    // Every link as a sentence naming both ends, forward verb, source first - the same
    // reading the object page shows. Not called "what depends on this": whether a link
    // carries a failure is the relationship type's own setting, not its direction.
    if (!empty($outgoing) || !empty($incoming)) {
        $msg .= "Relationships (each reads source - verb - target):\n";
        foreach ($outgoing as $r) $msg .= "- " . $obj['name'] . " " . $r['verb'] . " " . $r['name'] . " (" . $r['class_name'] . ")\n";
        foreach ($incoming as $r) $msg .= "- " . $r['name'] . " (" . $r['class_name'] . ") " . $r['verb'] . " " . $obj['name'] . "\n";
    }

    if (!empty($propRefs)) {
        $msg .= "Objects that point at this one through a property:\n";
        foreach ($propRefs as $r) $msg .= "- " . $r['name'] . " (" . $r['class_name'] . ") - its '" . $r['property_label'] . "' property is " . $obj['name'] . "\n";
    }

    // Tickets that reference this object — give the prose some operational
    // context (e.g. "currently has 2 open tickets including a P1 backup
    // failure"). Keep the volume down so the model doesn't fixate on them.
    $openTktStmt = $conn->prepare(
        "SELECT t.ticket_number, t.subject, ts.name AS status, tp.name AS priority, t.created_datetime
           FROM ticket_cmdb_objects tco
           JOIN tickets t ON t.id = tco.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
      LEFT JOIN ticket_priorities tp ON tp.id = t.priority_id
          WHERE tco.cmdb_object_id = ? AND COALESCE(ts.is_closed, 0) = 0
       ORDER BY t.updated_datetime DESC
          LIMIT 5"
    );
    $openTktStmt->execute([$id]);
    $openTickets = $openTktStmt->fetchAll(PDO::FETCH_ASSOC);

    $closedTotalStmt = $conn->prepare(
        "SELECT COUNT(*) FROM ticket_cmdb_objects tco
           JOIN tickets t ON t.id = tco.ticket_id
      LEFT JOIN ticket_statuses ts ON ts.id = t.status_id
          WHERE tco.cmdb_object_id = ? AND COALESCE(ts.is_closed, 0) = 1"
    );
    $closedTotalStmt->execute([$id]);
    $closedTotal = (int)$closedTotalStmt->fetchColumn();

    if (!empty($openTickets) || $closedTotal > 0) {
        $msg .= "Tickets referencing this object:\n";
        if (!empty($openTickets)) {
            $msg .= "- " . count($openTickets) . " open ticket(s):\n";
            foreach ($openTickets as $t) {
                $msg .= "  - " . $t['ticket_number'] . " " . ($t['priority'] ? "[" . $t['priority'] . "] " : "") . $t['subject'] . " (" . $t['status'] . ")\n";
            }
        }
        if ($closedTotal > 0) {
            $msg .= "- " . $closedTotal . " closed ticket(s) historically\n";
        }
    }

    return $msg;
}
