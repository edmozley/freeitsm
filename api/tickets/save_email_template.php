<?php
/**
 * API Endpoint: Create or update an email template
 * POST: { id, name, event_trigger, subject_template, body_template, is_active, display_order }
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_EMAIL_TEMPLATES);   // settings tab — see docs/design/rbac.md

$data = json_decode(file_get_contents('php://input'), true);

$id = $data['id'] ?? null;
$name = trim($data['name'] ?? '');
$eventTrigger = trim($data['event_trigger'] ?? '');
$subjectTemplate = trim($data['subject_template'] ?? '');
$bodyTemplate = trim($data['body_template'] ?? '');
$isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
$displayOrder = isset($data['display_order']) ? (int)$data['display_order'] : 0;

// 'analyst_assigned' (#148) goes to the assigned analyst, not the requester.
// includes/template_email.php's templateEventAudience() is what decides that;
// this list only controls what the editor is allowed to save.
$validEvents = ['new_ticket_email', 'ticket_assigned', 'analyst_assigned', 'ticket_closed', 'csat_request', 'csat_reminder', 'note_shared'];   // csat_reminder: GH #301

if ($name === '' || $eventTrigger === '' || $subjectTemplate === '' || $bodyTemplate === '') {
    echo json_encode(['success' => false, 'error' => 'Name, event trigger, subject, and body are required']);
    exit;
}

if (!in_array($eventTrigger, $validEvents)) {
    echo json_encode(['success' => false, 'error' => 'Invalid event trigger']);
    exit;
}

try {
    // ⚠️ VALIDATE THE RULES BEFORE ANYTHING IS WRITTEN. There is no transaction here,
    // so a rule rejected after the template UPDATE leaves the template saved and the
    // rules not — while the caller is told the save failed. Everything below this
    // point either writes or throws, and nothing that throws is allowed after it.
    $cleanRules = null;
    if (array_key_exists('rules', $data) && is_array($data['rules'])) {
        $cleanRules = [];
        foreach ($data['rules'] as $rule) {
            $type  = ($rule['match_type'] ?? '') === 'address' ? 'address' : 'domain';
            $value = strtolower(trim((string)($rule['match_value'] ?? '')));
            $value = ltrim($value, '@');            // "@a.com" typed for a domain
            if ($value === '') continue;

            if ($type === 'address') {
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('"' . $value . '" is not a valid email address.');
                }
            } else {
                // A domain with an @ still in it, or with no dot, would match nothing
                // and would look like a working rule. Refuse it rather than store it:
                // a rule that silently never fires is the failure mode this whole
                // feature is trying to avoid.
                if (str_contains($value, '@') || !str_contains($value, '.')) {
                    throw new Exception('"' . $value . '" is not a valid domain. Use the part after the @, e.g. example.com.');
                }
            }
            $cleanRules[$type . '|' . $value] = ['type' => $type, 'value' => $value];   // de-duplicates
        }
    }

    $conn = connectToDatabase();

    if ($id) {
        $sql = "UPDATE ticket_email_templates
                SET name = ?, event_trigger = ?, subject_template = ?, body_template = ?,
                    is_active = ?, display_order = ?, updated_datetime = UTC_TIMESTAMP()
                WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $eventTrigger, $subjectTemplate, $bodyTemplate, $isActive, $displayOrder, $id]);
    } else {
        $sql = "INSERT INTO ticket_email_templates
                (name, event_trigger, subject_template, body_template, is_active, display_order)
                VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $eventTrigger, $subjectTemplate, $bodyTemplate, $isActive, $displayOrder]);
        $id = $conn->lastInsertId();
    }

    // --- Sender rules (#80) -------------------------------------------------
    //
    // Replace wholesale rather than diff: the set is tiny, the editor sends the
    // whole list, and a diff is where "removed a rule that stayed anyway" comes
    // from — which on this feature means somebody keeps getting an email the
    // admin believes they stopped.
    //
    // ⚠️ An ABSENT `rules` key means "don't touch them" ($cleanRules stays null),
    // an EMPTY ARRAY means "this template applies to everyone". Those are
    // different, and collapsing them would let any older client that posts no
    // rules silently unrestrict every template it saves.
    if ($cleanRules !== null) {
        $conn->prepare("DELETE FROM ticket_email_template_rules WHERE template_id = ?")->execute([$id]);
        if ($cleanRules) {
            $ins = $conn->prepare("INSERT INTO ticket_email_template_rules (template_id, match_type, match_value)
                                   VALUES (?, ?, ?)");
            foreach ($cleanRules as $r) {
                $ins->execute([$id, $r['type'], $r['value']]);
            }
        }
    }

    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
