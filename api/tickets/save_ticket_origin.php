<?php
/**
 * Save Ticket Origin API — Create or Update, multi-tenancy aware
 * (mirrors save_ticket_type.php).
 *
 * Context decides scope: single-company / MSP-Default → a GLOBAL default origin;
 * a client company's context → a NEW origin belongs to THAT company. You may
 * edit only your own; shared defaults are managed from the Default context and
 * hidden (not edited) per company.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        throw new Exception('Invalid request data');
    }

    $id           = !empty($input['id']) ? (int)$input['id'] : null;
    $name         = trim($input['name'] ?? '');
    $description  = trim($input['description'] ?? '');
    $displayOrder = (int)($input['display_order'] ?? 0);
    $isActive     = !empty($input['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);
    $scopeTenant  = $isDefaultCtx ? null : $activeId;

    // Name must be unique within what this company actually sees.
    $clashSql = $isDefaultCtx
        ? "SELECT id FROM ticket_origins WHERE tenant_id IS NULL AND LOWER(name) = LOWER(?)"
        : "SELECT id FROM ticket_origins
             WHERE LOWER(name) = LOWER(?)
               AND ( tenant_id = " . (int)$activeId . "
                     OR ( tenant_id IS NULL
                          AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                         WHERE tenant_id = " . (int)$activeId . " AND entity_type = 'ticket_origin') ) )";
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) {
        throw new Exception('A ticket origin with that name already exists here');
    }

    if ($id) {
        $cur = $conn->prepare("SELECT tenant_id FROM ticket_origins WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Ticket origin not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

        if ($isDefaultCtx) {
            if ($owner !== null) {
                throw new Exception("That's a company's own origin — switch to that company to edit it.");
            }
        } else {
            if ($owner === null) {
                throw new Exception('Shared default origins are managed from the MSP (default) company — here you can hide them instead.');
            }
            if ($owner !== $activeId) {
                throw new Exception('That origin belongs to another company.');
            }
        }

        $stmt = $conn->prepare("UPDATE ticket_origins SET name = ?, description = ?, display_order = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$name, $description, $displayOrder, $isActive, $id]);
        wf_emit('ticket_origin', 'updated', (int)$id, $name);
        echo json_encode(['success' => true, 'message' => 'Ticket origin updated successfully', 'id' => $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO ticket_origins (name, description, display_order, is_active, tenant_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $displayOrder, $isActive, $scopeTenant]);
        $newId = (int)$conn->lastInsertId();
        wf_emit('ticket_origin', 'created', $newId, $name);
        echo json_encode(['success' => true, 'message' => 'Ticket origin created successfully', 'id' => $newId]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
