<?php
/**
 * API Endpoint: Hide / show a shared ticket priority for the active company.
 *
 * POST JSON { priority_id, hidden: true|false }
 *
 * Only in a client company's context.
 * The shared row is never changed, so tickets that already have the priority keep it.
 * Refused while open tickets in the company use it.
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
    $data       = json_decode(file_get_contents('php://input'), true);
    $priorityId = !empty($data['priority_id']) ? (int)$data['priority_id'] : 0;
    $hidden     = !empty($data['hidden']);
    if ($priorityId <= 0) {
        throw new Exception(t('tickets.settings.priorities.not_found'));
    }

    $conn = connectToDatabase();
    $activeId = ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id'])['company'];
    if ($activeId === null) {
        throw new Exception(t('tickets.settings.priorities.hide_client_only'));
    }

    $cur = $conn->prepare("SELECT " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM ticket_priorities WHERE id = ?");
    $cur->execute([$priorityId]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        throw new Exception(t('tickets.settings.priorities.not_found'));
    }
    if ($row['tenant_id'] !== null) {
        throw new Exception(t('tickets.settings.priorities.hide_shared_only'));
    }

    if ($hidden) {
        $inUse = $conn->prepare("SELECT COUNT(*) FROM tickets WHERE tenant_id = ? AND priority_id = ? AND closed_datetime IS NULL");
        $inUse->execute([$activeId, $priorityId]);
        if ((int)$inUse->fetchColumn() > 0) {
            throw new Exception(t('tickets.settings.priorities.hide_in_use'));
        }
        $conn->prepare("INSERT IGNORE INTO tenant_config_hidden (tenant_id, entity_type, entity_id) VALUES (?, 'ticket_priority', ?)")
             ->execute([$activeId, $priorityId]);
    } else {
        // The same name rule save_ticket_priority.php applies: a company that made its own "Urgent" while the shared
        // "Urgent" was hidden would otherwise see two of them.
        $clash = $conn->prepare(
            "SELECT COUNT(*) FROM ticket_priorities own
               JOIN ticket_priorities shared ON shared.id = ? AND LOWER(shared.name) = LOWER(own.name)
              WHERE own.tenant_id = ?"
        );
        $clash->execute([$priorityId, $activeId]);
        if ((int)$clash->fetchColumn() > 0) {
            throw new Exception(t('tickets.settings.priorities.show_name_clash'));
        }
        $conn->prepare("DELETE FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_priority' AND entity_id = ?")
             ->execute([$activeId, $priorityId]);
    }

    echo json_encode(['success' => true, 'hidden' => $hidden]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
