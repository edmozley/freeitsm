<?php
/**
 * API: Generate (or regenerate) the AI summary for a CMDB object.
 *
 * Pulls the object's full context — properties (resolved values), parent,
 * children, relationships (both directions), and impact (things that depend
 * on it) — packs it into a structured user message, asks Claude for a 2-3
 * sentence prose synthesis, and saves the result on the object so we don't
 * re-call the AI on every page load.
 *
 * The frontend hits this when the analyst clicks "Regenerate". The cached
 * summary is returned by get_object.php for free on every page render.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once __DIR__ . '/_ai_helpers.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

requireModuleAccessJson('cmdb');

try {
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $id = isset($data['id']) ? (int)$data['id'] : 0;
    if ($id <= 0) throw new Exception('id is required');

    $conn = connectToDatabase();
    $cfg = loadCmdbAiConfig($conn);

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
    foreach ($propStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $val = null;
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

    // Incoming relationships ("X <inverse_verb> this")
    $inStmt = $conn->prepare(
        "SELECT rt.inverse_verb, oo.name, oc.name AS class_name
           FROM cmdb_object_relationships r
           JOIN cmdb_relationship_types rt ON rt.id = r.relationship_type_id
           JOIN cmdb_objects oo ON oo.id = r.from_object_id
           JOIN cmdb_classes oc ON oc.id = oo.class_id
          WHERE r.to_object_id = ?
       ORDER BY rt.inverse_verb, oo.name"
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

    if (!empty($outgoing)) {
        $msg .= "Outgoing relationships (this object …):\n";
        foreach ($outgoing as $r) $msg .= "- " . $r['verb'] . " " . $r['name'] . " (" . $r['class_name'] . ")\n";
    }

    if (!empty($incoming) || !empty($propRefs)) {
        $msg .= "What depends on this object:\n";
        foreach ($incoming as $r) $msg .= "- " . $r['name'] . " (" . $r['class_name'] . ") — " . $r['inverse_verb'] . " this\n";
        foreach ($propRefs as $r) $msg .= "- " . $r['name'] . " (" . $r['class_name'] . ") — references this via its '" . $r['property_label'] . "' property\n";
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

    $systemPrompt = <<<PROMPT
You are summarising one CMDB object for an IT analyst's quick reference. Read everything provided about the object and write a short prose synthesis — 2 to 3 sentences only.

Rules:
- 2 to 3 sentences. Plain prose. No bullet points. No headings. No markdown.
- Mention what the object is (class), where it sits (parent if any), who owns it (only if there's an Owner-style property), and what depends on it (only if anything does — count children + property references + incoming relationships).
- ONLY state what's in the data. Do NOT speculate, infer, or invent details.
- Refer to the object by its name. Refer to other objects by their names too.
- Tone: factual, concise, like a senior engineer briefing a colleague.
- If many properties are missing, you may briefly note "key fields like X are not yet filled in" — but only if it's worth flagging.
- If the object has Status: PLANNED, frame the summary in future tense ("will host…", "is planned to…", "is a proposed…") and surface the planned status near the start of the first sentence so the reader knows immediately the object isn't yet in service.
PROMPT;

    if ($cfg['custom_instructions'] !== '') {
        $systemPrompt .= "\n\nAdditional admin instructions:\n" . $cfg['custom_instructions'];
    }

    $resp = callAnthropic($cfg, $systemPrompt, $msg, 400);
    $summary = anthropicResponseText($resp);
    if ($summary === '') throw new Exception('AI returned an empty summary');

    // Save on the object
    $upd = $conn->prepare(
        "UPDATE cmdb_objects SET ai_summary = ?, ai_summary_generated_at = UTC_TIMESTAMP() WHERE id = ?"
    );
    $upd->execute([$summary, $id]);

    echo json_encode([
        'success'      => true,
        'summary'      => $summary,
        'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
