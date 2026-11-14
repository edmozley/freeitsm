<?php
/**
 * API Endpoint: Save ticket priority (create or update)
 *
 * A new priority goes where `tenant_id` says: null for shared, or a client company the analyst can access.
 * Left out, the active company's context decides (shared in the default company's context and the All companies view).
 * Shared priorities need access to every company, a company's own need access to that company.
 * The default flag is one per scope: one shared default, and at most one per company.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_priorities.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_PRIORITIES);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id            = !empty($data['id']) ? (int)$data['id'] : null;
    $isNew         = $id === null;
    $name          = trim($data['name'] ?? '');
    $colour        = trim($data['colour'] ?? '');
    $is_default    = !empty($data['is_default']) ? 1 : 0;
    $display_order = (int)($data['display_order'] ?? 0);
    $is_active     = !empty($data['is_active']) ? 1 : 0;

    if ($name === '') {
        throw new Exception('Name is required');
    }
    if ($colour !== '' && !preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
        throw new Exception('Colour must be a #rrggbb hex code');
    }

    $conn = connectToDatabase();
    $ctx = ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']);

    if ($id) {
        $cur = $conn->prepare("SELECT " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM ticket_priorities WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception(t('tickets.settings.priorities.not_found'));
        }
        $scopeTenant = $row['tenant_id'] === null ? null : (int)$row['tenant_id'];
    } else {
        $scopeTenant = array_key_exists('tenant_id', $data)
            ? ($ctx['multi'] && !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null)
            : $ctx['company'];
    }
    ticketPriorityRequireAccess($ctx, $scopeTenant);

    // A shared name must not match any priority, or a company with its own of that name would see two.
    // A company's name must not match its own or a shared one it can see.
    // The unique key can't de-duplicate shared names.
    // A company scope only exists once Database Verification has added tenant_id (ticketPriorityScopeContext()).
    $clashSql = $scopeTenant === null
        ? "SELECT id, " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM ticket_priorities WHERE LOWER(name) = LOWER(?)"
        : "SELECT id, tenant_id FROM ticket_priorities
             WHERE LOWER(name) = LOWER(?)
               AND ( tenant_id = " . (int)$scopeTenant . "
                     OR ( tenant_id IS NULL
                          AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                         WHERE tenant_id = " . (int)$scopeTenant . " AND entity_type = 'ticket_priority') ) )";
    $clashParams = [$name];
    if ($id) {
        $clashSql .= " AND id <> ?";
        $clashParams[] = $id;
    }
    $clash = $conn->prepare($clashSql);
    $clash->execute($clashParams);
    $clashRow = $clash->fetch(PDO::FETCH_ASSOC);
    if ($clashRow) {
        throw new Exception(t($scopeTenant === null && $clashRow['tenant_id'] !== null
            ? 'tickets.settings.priorities.name_exists_company'
            : 'tickets.settings.priorities.name_exists'));
    }

    $conn->beginTransaction();

    if ($is_default) {
        $clear = $conn->prepare("UPDATE ticket_priorities SET is_default = 0 WHERE " . ticketPriorityTenantCol($conn) . " <=> ? AND id <> ?");
        $clear->execute([$scopeTenant, $id ?? 0]);
    }

    if ($id) {
        $sql = "UPDATE ticket_priorities
                   SET name = ?, colour = ?, is_default = ?, display_order = ?, is_active = ?
                 WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$name, $colour ?: null, $is_default, $display_order, $is_active, $id]);
    } else {
        // Before Database Verification there is no tenant_id column - every priority is shared (ticketPriorityTenancyReady()).
        $ready = ticketPriorityTenancyReady($conn);
        $sql = "INSERT INTO ticket_priorities (name, colour, is_default, display_order, is_active" . ($ready ? ", tenant_id" : "") . ")
                VALUES (?, ?, ?, ?, ?" . ($ready ? ", ?" : "") . ")";
        $stmt = $conn->prepare($sql);
        $params = [$name, $colour ?: null, $is_default, $display_order, $is_active];
        if ($ready) $params[] = $scopeTenant;
        $stmt->execute($params);
        $id = (int)$conn->lastInsertId();
    }

    // There is always a shared default.
    // A company needs none of its own: it falls back to the shared one.
    $hasDefault = (int) $conn->query("SELECT COUNT(*) FROM ticket_priorities WHERE " . ticketPriorityTenantCol($conn) . " IS NULL AND is_default = 1")->fetchColumn();
    if ($hasDefault === 0) {
        $conn->exec("UPDATE ticket_priorities SET is_default = 1 WHERE " . ticketPriorityTenantCol($conn) . " IS NULL ORDER BY display_order, id LIMIT 1");
    }

    $conn->commit();
    wf_emit('ticket_priority', $isNew ? 'created' : 'updated', $id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>
