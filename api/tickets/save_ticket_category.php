<?php
/**
 * API Endpoint: create or update a ticket category (#1540).
 *
 * POST { id?, name, description?, parent_id?, ticket_type_id?,
 *        is_portal_visible?, is_active?, display_order? }
 *
 * ---------------------------------------------------------------------------
 * THE THREE RULES THE SCHEMA CANNOT ENFORCE
 * ---------------------------------------------------------------------------
 * 1. DEPTH. Capped at TICKET_CATEGORY_MAX_DEPTH. An unbounded tree is
 *    unreportable — nobody reads a chart with two hundred leaves and the roll-up
 *    has no level to stop at.
 *
 * 2. THE TYPE LINK LIVES ON THE ROOT. `ticket_type_id` is refused on a child,
 *    which inherits its root's. If a sub-category could name its own type,
 *    "Hardware → Printer" could be an Incident while "Hardware" was a Service
 *    request, and neither answer would be wrong.
 *
 * 3. NO CYCLES. Re-parenting a category under its own descendant makes every
 *    path unresolvable and every roll-up infinite.
 *
 * ⚠️ And one the DB only half-covers: the unique key is
 * (tenant_id, parent_id, name), but MySQL allows unlimited NULLs in a unique
 * key — so two GLOBAL roots with the same name would both slip straight through
 * it. Sibling-name uniqueness is enforced here, in the query below. The same
 * split ticket_types has always lived with.
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
    if (!is_array($data)) throw new Exception('Invalid request');

    $id          = !empty($data['id']) ? (int) $data['id'] : null;
    $name        = trim((string) ($data['name'] ?? ''));
    $description = $data['description'] ?? null;
    $parentId    = !empty($data['parent_id']) ? (int) $data['parent_id'] : null;
    $typeId      = !empty($data['ticket_type_id']) ? (int) $data['ticket_type_id'] : null;
    $portal      = array_key_exists('is_portal_visible', $data) ? (!empty($data['is_portal_visible']) ? 1 : 0) : 1;
    $isActive    = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
    $order       = (int) ($data['display_order'] ?? 0);

    if ($name === '')            throw new Exception('Name is required');
    if (mb_strlen($name) > 100)  throw new Exception('Name is too long (100 characters maximum)');

    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    // Scope: NULL = a global default, else this company's own. Same model as
    // ticket types — a client company adds its own, the shared list is edited
    // from the MSP/Default context.
    $scopeTenant = $isDefaultCtx ? null : $activeId;

    // ---- The parent, if any -------------------------------------------------
    if ($parentId !== null) {
        $ps = $conn->prepare("SELECT id, tenant_id, parent_id FROM ticket_categories WHERE id = ?");
        $ps->execute([$parentId]);
        $parent = $ps->fetch(PDO::FETCH_ASSOC);
        if (!$parent) throw new Exception('That parent category no longer exists');

        // A company's own category may sit under a shared global one, but not
        // under ANOTHER company's — that would leak one client's list into another.
        $parentOwner = $parent['tenant_id'] === null ? null : (int) $parent['tenant_id'];
        if ($parentOwner !== null && $parentOwner !== $scopeTenant) {
            throw new Exception('That parent category belongs to another company.');
        }

        // ⚠️ THE CYCLE CHECK COMES FIRST, before depth. Dropping a category under
        // its own descendant is ALSO too deep, so a depth check placed first
        // catches it — and then reports "3 levels deep" for what is actually a
        // loop. Right refusal, wrong reason, and the wrong thing to go and fix.
        if ($id && ticketCategoryWouldCycle($conn, $id, $parentId)) {
            throw new Exception('A category cannot be moved underneath itself, or underneath one of its own sub-categories.');
        }

        $depth = ticketCategoryDepthUnder($conn, $parentId);
        if ($depth > TICKET_CATEGORY_MAX_DEPTH) {
            throw new Exception('Categories can go ' . TICKET_CATEGORY_MAX_DEPTH . ' levels deep. This one would be level ' . $depth . '.');
        }

        // Rule 2: only a root carries the type link.
        if ($typeId !== null) {
            throw new Exception('Only a top-level category can be tied to a ticket type — a sub-category follows the one above it.');
        }
    }

    // ---- The ticket type, if any -------------------------------------------
    if ($typeId !== null) {
        $ts = $conn->prepare("SELECT id FROM ticket_types WHERE id = ?");
        $ts->execute([$typeId]);
        if (!$ts->fetch()) throw new Exception('That ticket type no longer exists');
    }

    // ---- Sibling name uniqueness (see the header note) ----------------------
    $clashSql = "SELECT id FROM ticket_categories
                  WHERE LOWER(name) = LOWER(?)
                    AND " . ($scopeTenant === null ? "tenant_id IS NULL" : "tenant_id = " . (int) $scopeTenant) . "
                    AND " . ($parentId === null ? "parent_id IS NULL" : "parent_id = " . (int) $parentId);
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) {
        throw new Exception($parentId === null
            ? 'A top-level category with that name already exists here'
            : 'That parent already has a sub-category with that name');
    }

    if ($id) {
        $cur = $conn->prepare("SELECT tenant_id, parent_id FROM ticket_categories WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Category not found');

        $owner = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
        if ($isDefaultCtx) {
            if ($owner !== null) throw new Exception("That's a company's own category — switch to that company to edit it.");
        } else {
            if ($owner === null)      throw new Exception('Shared default categories are managed from the MSP (default) company.');
            if ($owner !== $activeId) throw new Exception('That category belongs to another company.');
        }

        // Rule 3 is checked in the parent block above, before the depth cap, so
        // that a loop is reported as a loop rather than as "too deep".

        // Moving a category takes its whole subtree with it, so the deepest
        // descendant is what decides whether the move fits inside the cap.
        if ($parentId !== null && (int) ($row['parent_id'] ?? 0) !== $parentId) {
            $newRootDepth = ticketCategoryDepthUnder($conn, $parentId);
            $subtreeDepth = ticketCategorySubtreeDepth($conn, $id);
            if ($newRootDepth + $subtreeDepth - 1 > TICKET_CATEGORY_MAX_DEPTH) {
                throw new Exception('Moving this category would push its sub-categories past ' . TICKET_CATEGORY_MAX_DEPTH . ' levels.');
            }
        }

        $conn->prepare(
            "UPDATE ticket_categories
                SET name = ?, description = ?, parent_id = ?, ticket_type_id = ?,
                    is_portal_visible = ?, is_active = ?, display_order = ?
              WHERE id = ?"
        )->execute([$name, $description, $parentId, $typeId, $portal, $isActive, $order, $id]);

        $savedId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO ticket_categories
                (name, description, parent_id, ticket_type_id, is_portal_visible, is_active, display_order, tenant_id)
             VALUES (?,?,?,?,?,?,?,?)"
        )->execute([$name, $description, $parentId, $typeId, $portal, $isActive, $order, $scopeTenant]);
        $savedId = (int) $conn->lastInsertId();
    }

    wf_emit('ticket_category', $id ? 'updated' : 'created', $savedId, $name);
    echo json_encode(['success' => true, 'id' => $savedId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
