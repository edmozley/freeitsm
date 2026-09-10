<?php
/**
 * API Endpoint: create or update a resolution code (#1540).
 *
 * POST { id?, name, description?, is_active?, display_order? }
 *
 * A resolution code answers HOW a ticket ended — "Training given", "No fault
 * found" — which is not the same question as what it was about. Flat on
 * purpose: the moment this grows a hierarchy it has become a second category
 * tree and the two will drift apart.
 *
 * ⚠️ The unique key is (tenant_id, name), and MySQL allows unlimited NULLs in a
 * unique key — so two GLOBAL codes with the same name would slip past it. Name
 * uniqueness is enforced in the query below, same as ticket_types.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';

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
    $isActive    = array_key_exists('is_active', $data) ? (!empty($data['is_active']) ? 1 : 0) : 1;
    $order       = (int) ($data['display_order'] ?? 0);

    if ($name === '')           throw new Exception('Name is required');
    if (mb_strlen($name) > 100) throw new Exception('Name is too long (100 characters maximum)');

    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    $scopeTenant = $isDefaultCtx ? null : $activeId;

    $clashSql = "SELECT id FROM ticket_resolution_codes
                  WHERE LOWER(name) = LOWER(?)
                    AND " . ($scopeTenant === null ? "tenant_id IS NULL" : "tenant_id = " . (int) $scopeTenant);
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) throw new Exception('A resolution code with that name already exists here');

    if ($id) {
        $cur = $conn->prepare("SELECT tenant_id FROM ticket_resolution_codes WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception('Resolution code not found');

        $owner = $row['tenant_id'] === null ? null : (int) $row['tenant_id'];
        if ($isDefaultCtx) {
            if ($owner !== null) throw new Exception("That's a company's own resolution code — switch to that company to edit it.");
        } else {
            if ($owner === null)      throw new Exception('Shared default resolution codes are managed from the MSP (default) company.');
            if ($owner !== $activeId) throw new Exception('That resolution code belongs to another company.');
        }

        $conn->prepare(
            "UPDATE ticket_resolution_codes SET name = ?, description = ?, is_active = ?, display_order = ? WHERE id = ?"
        )->execute([$name, $description, $isActive, $order, $id]);
        $savedId = $id;
    } else {
        $conn->prepare(
            "INSERT INTO ticket_resolution_codes (name, description, is_active, display_order, tenant_id) VALUES (?,?,?,?,?)"
        )->execute([$name, $description, $isActive, $order, $scopeTenant]);
        $savedId = (int) $conn->lastInsertId();
    }

    wf_emit('ticket_resolution_code', $id ? 'updated' : 'created', $savedId, $name);
    echo json_encode(['success' => true, 'id' => $savedId]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
