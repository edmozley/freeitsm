<?php
/**
 * API Endpoint: Save asset type (create or update) — multi-tenancy aware.
 *
 * Context decides scope (mirrors save_ticket_type.php, design §7):
 *   - Single-company install, OR working in the MSP/Default company → the type is
 *     a GLOBAL default (tenant_id NULL). Exactly today's behaviour.
 *   - Working in a client company's context → a NEW type belongs to THAT company
 *     (tenant_id set). You may edit/delete only that company's own types; the
 *     shared defaults are managed from the MSP/Default context (and hidden, not
 *     edited, per company).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/services/asset_type_layout.php';
require_once '../../includes/services/asset_completeness.php';   // GH #404

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('assets');
requireCapabilityJson(Cap::ASSETS_TYPES);   // settings write — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id            = !empty($data['id']) ? (int)$data['id'] : null;
    $name          = trim($data['name'] ?? '');
    $description   = $data['description'] ?? '';
    $display_order = (int)($data['display_order'] ?? 0);
    $is_active     = !empty($data['is_active']) ? 1 : 0;
    // NULL = no icon. An empty string from the picker means "none", not 0 —
    // and 0 would fail the foreign key rather than clearing the icon.
    $icon_id       = !empty($data['icon_id']) ? (int)$data['icon_id'] : null;

    if ($name === '') {
        throw new Exception('Name is required');
    }

    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    // The scope this save targets: NULL = global default, else this company.
    $scopeTenant = $isDefaultCtx ? null : $activeId;

    // Name must be unique within what this company would actually see: its own
    // types + the global defaults it hasn't hidden. (NULL-tenant rows aren't
    // de-duped by the DB unique key, so we enforce it here.)
    $clashSql = $isDefaultCtx
        ? "SELECT id FROM asset_types WHERE tenant_id IS NULL AND LOWER(name) = LOWER(?)"
        : "SELECT id FROM asset_types
             WHERE LOWER(name) = LOWER(?)
               AND ( tenant_id = " . (int)$activeId . "
                     OR ( tenant_id IS NULL
                          AND id NOT IN (SELECT entity_id FROM tenant_config_hidden
                                         WHERE tenant_id = " . (int)$activeId . " AND entity_type = 'asset_type') ) )";
    $clashParams = [$name];
    if ($id) { $clashSql .= " AND id <> ?"; $clashParams[] = $id; }
    $cs = $conn->prepare($clashSql);
    $cs->execute($clashParams);
    if ($cs->fetch()) {
        throw new Exception('An asset type with that name already exists here');
    }

    if ($id) {
        // Editing: confirm the row exists and that this context owns it.
        $cur = $conn->prepare("SELECT tenant_id FROM asset_types WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new Exception('Asset type not found');
        }
        $owner = ($row['tenant_id'] === null) ? null : (int)$row['tenant_id'];

        if ($isDefaultCtx) {
            if ($owner !== null) {
                throw new Exception("That's a company's own type — switch to that company to edit it.");
            }
        } else {
            if ($owner === null) {
                throw new Exception('Shared default types are managed from the MSP (default) company — here you can hide them instead.');
            }
            if ($owner !== $activeId) {
                throw new Exception('That type belongs to another company.');
            }
        }

        $stmt = $conn->prepare("UPDATE asset_types SET name = ?, description = ?, display_order = ?, is_active = ?, icon_id = ? WHERE id = ?");
        $stmt->execute([$name, $description, $display_order, $is_active, $icon_id, $id]);
    } else {
        $stmt = $conn->prepare("INSERT INTO asset_types (name, description, display_order, is_active, icon_id, tenant_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $description, $display_order, $is_active, $icon_id, $scopeTenant]);
        $id = (int)$conn->lastInsertId();
        $created = true;
    }

    // The type's layout (GH #200) - only when sent, so a caller that knows nothing of layouts
    // (an older page, a script) cannot reset one by saving the name. Same ownership checks as
    // the type itself, because it is part of the type.
    if (array_key_exists('layout', $data)) {
        AssetTypeLayout::save($conn, (int)$id, AssetTypeLayout::normalise($data['layout']));
    }

    // GH #404: which fields the type expects to be filled in eventually - only when sent, like
    // the layout. Flags, never refuses: nothing about saving an asset changes.
    if (array_key_exists('expected', $data)) {
        AssetCompleteness::saveExpected($conn, (int)$id, $data['expected']);
    }

    // GH #201: the CMDB class "Create linked CI" makes for this type - only when sent, like
    // the layout. It never creates a CI by itself and never touches existing assets.
    if (array_key_exists('cmdb_class_id', $data)) {
        $classId = !empty($data['cmdb_class_id']) ? (int)$data['cmdb_class_id'] : null;
        if ($classId !== null) {
            $ok = $conn->prepare('SELECT id FROM cmdb_classes WHERE id = ? AND is_active = 1');
            $ok->execute([$classId]);
            if (!$ok->fetchColumn()) {
                throw new Exception('That CMDB class does not exist or is not active.');
            }
        }
        $conn->prepare('UPDATE asset_types SET cmdb_class_id = ? WHERE id = ?')->execute([$classId, $id]);
    }

    wf_emit('asset_type', empty($created) ? 'updated' : 'created', (int)$id, $name);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
