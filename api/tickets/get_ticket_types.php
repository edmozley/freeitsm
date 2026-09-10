<?php
/**
 * API Endpoint: Get ticket types — multi-tenancy aware.
 *
 * Ticket types are a "global default + per-company add/hide" list (design §7):
 *   - global defaults  → rows with tenant_id IS NULL (shared by every company)
 *   - a company's own  → rows with tenant_id = that company
 *   - a company can hide a global default from its own lists (tenant_config_hidden)
 *
 * Two response shapes:
 *   - default (consumer, e.g. the ticket form): `ticket_types` = the RESOLVED
 *     visible list for the active company (global-not-hidden + own). On a
 *     single-company install this is simply every type — exactly as before.
 *   - ?manage=1 (the settings screen): additionally returns `scoped` describing
 *     the two groups to manage when working in a *client* company's context.
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

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $manage    = !empty($_GET['manage']);

    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);

    // Consumer-safe RESOLVED list (global-not-hidden + this company's own). On a
    // single-company / part-migrated install this returns every row, as before.
    $rows = getTenantConfigRows(
        $conn, 'ticket_types', 'ticket_type', $activeId,
        'id, name, description, is_active, display_order, tenant_id, created_datetime',
        '', 'display_order, name'
    );
    foreach ($rows as &$r) {
        $r['is_active'] = (bool)$r['is_active'];
        $r['scope']     = ($r['tenant_id'] === null) ? 'global' : 'company';
    }
    unset($r);

    $resp = ['success' => true, 'ticket_types' => $rows, 'multi_tenant' => $multi];

    // The consolidated view (#1554). The list above is resolved for ONE company,
    // which is the right answer everywhere except a board holding several
    // companies' tickets at once — there the reading pane has to offer the list
    // belonging to the TICKET's company, not the page's.
    //
    // ⚠️ Resolved per company rather than flattened into one list: a company can
    // HIDE a global default, so the same type belongs in one company's list and
    // not another's. A union would quietly re-offer what somebody hid.
    if (!$manage && isActiveTenantAll($conn)) {
        $byCompany = [];
        foreach (getTenantConfigRowsByCompany(
            $conn, $analystId, 'ticket_types', 'ticket_type',
            'id, name, description, is_active, display_order, tenant_id', '', 'display_order, name'
        ) as $tid => $tRows) {
            foreach ($tRows as &$tr) { $tr['is_active'] = (bool)$tr['is_active']; }
            unset($tr);
            $byCompany[$tid] = $tRows;
        }
        $resp['by_company']    = $byCompany;
        $resp['all_companies'] = true;
    }

    // Settings management view, only meaningful inside a *client* company context.
    if ($manage && $multi && !$isDefaultCtx) {
        $company = getTenantById($conn, $activeId);

        $hiddenIds = [];
        $hs = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_type'");
        $hs->execute([$activeId]);
        foreach ($hs->fetchAll(PDO::FETCH_COLUMN) as $eid) { $hiddenIds[(int)$eid] = true; }

        $globals = [];
        foreach ($conn->query("SELECT id, name, description, is_active, display_order FROM ticket_types WHERE tenant_id IS NULL ORDER BY display_order, name") as $g) {
            $globals[] = [
                'id'            => (int)$g['id'],
                'name'          => $g['name'],
                'description'   => $g['description'],
                'is_active'     => (bool)$g['is_active'],
                'display_order' => (int)$g['display_order'],
                'hidden'        => isset($hiddenIds[(int)$g['id']]),
            ];
        }

        $ownStmt = $conn->prepare("SELECT id, name, description, is_active, display_order FROM ticket_types WHERE tenant_id = ? ORDER BY display_order, name");
        $ownStmt->execute([$activeId]);
        $own = [];
        foreach ($ownStmt->fetchAll(PDO::FETCH_ASSOC) as $o) {
            $own[] = [
                'id'            => (int)$o['id'],
                'name'          => $o['name'],
                'description'   => $o['description'],
                'is_active'     => (bool)$o['is_active'],
                'display_order' => (int)$o['display_order'],
            ];
        }

        $resp['scoped'] = [
            'is_default' => false,
            'company'    => ['id' => $activeId, 'name' => $company['name'] ?? ''],
            'globals'    => $globals,
            'own'        => $own,
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
