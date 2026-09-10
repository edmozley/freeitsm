<?php
/**
 * API Endpoint: delete a ticket category (#1540).
 *
 * ⚠️ DELETING IS THE EXCEPTION, NOT THE NORMAL WAY TO REMOVE ONE.
 *
 * A category is meant to be RETIRED — is_active = 0 — so that five years of
 * closed tickets keep the label they were closed with. Deleting is only allowed
 * while nothing points at it, and this endpoint checks three things first:
 *
 *   1. no sub-categories underneath it
 *   2. no ticket using it as its category
 *   3. no ticket using it as its CLOSURE category
 *
 * (2) and (3) are separate checks because they are separate columns and a
 * category can easily be in use as one and not the other — a category that was
 * only ever picked at close would sail past a check that looked at category_id
 * alone, and the delete would then fail on a foreign key with an error nobody
 * can act on.
 *
 * The foreign keys carry no ON DELETE action, so MySQL RESTRICTs and would stop
 * this anyway. The checks exist to say WHY, in words, before that happens.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_categories.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CATEGORIES);

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id   = !empty($data['id']) ? (int) $data['id'] : null;
    if (!$id) throw new Exception('ID is required');

    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $cur = $conn->prepare("SELECT name, tenant_id FROM ticket_categories WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('Category not found');

    $owner = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
    if ($isDefaultCtx) {
        if ($owner !== null) throw new Exception("That's a company's own category — switch to that company to delete it.");
    } else {
        if ($owner === null)      throw new Exception('Shared default categories are managed from the MSP (default) company — retire it there, or hide it from this company.');
        if ($owner !== $activeId) throw new Exception('That category belongs to another company.');
    }

    // 1. Children.
    $c = $conn->prepare("SELECT COUNT(*) FROM ticket_categories WHERE parent_id = ?");
    $c->execute([$id]);
    $kids = (int) $c->fetchColumn();
    if ($kids > 0) {
        throw new Exception($kids === 1
            ? 'This category has a sub-category underneath it. Move or delete that first.'
            : "This category has {$kids} sub-categories underneath it. Move or delete those first.");
    }

    // 2 and 3. In use, on either column.
    $u = $conn->prepare("SELECT
            (SELECT COUNT(*) FROM tickets WHERE category_id = ?)         AS as_category,
            (SELECT COUNT(*) FROM tickets WHERE closure_category_id = ?) AS as_closure");
    $u->execute([$id, $id]);
    $use = $u->fetch(PDO::FETCH_ASSOC) ?: ['as_category' => 0, 'as_closure' => 0];
    $total = (int) $use['as_category'] + (int) $use['as_closure'];
    if ($total > 0) {
        // Name the case rather than saying "in use": which of the two columns it
        // is decides where someone has to go to change it.
        $parts = [];
        if ((int) $use['as_category'] > 0) $parts[] = (int) $use['as_category'] . ' as their category';
        if ((int) $use['as_closure'] > 0)  $parts[] = (int) $use['as_closure']  . ' as their category at close';
        throw new Exception(
            'Tickets still use this category (' . implode(', ', $parts) . '). '
            . 'Switch it off instead — it stays on those tickets and stops being offered on new ones.'
        );
    }

    $conn->prepare("DELETE FROM ticket_categories WHERE id = ?")->execute([$id]);

    wf_emit('ticket_category', 'deleted', $id, $row['name']);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
