<?php
/**
 * API Endpoint: everything the ticket classification feature needs, in one call
 * (#1540) — the category tree, the resolution codes, and the three switches
 * deciding whether any of it appears on a ticket.
 *
 * One endpoint rather than three because every consumer wants the same bundle:
 * a ticket form needs the categories AND whether to render them at all, and
 * fetching those separately means a page can briefly render a field the settings
 * say to hide.
 *
 * Query parameters:
 *   manage=1   the settings screen: the WHOLE list including retired categories,
 *              plus each company's switch overrides. Requires the settings cap.
 *   type_id=N  only categories on offer for that ticket type (roots with no type
 *              at all, plus that type's roots, plus everything beneath either).
 *   portal=1   only categories a requester may see.
 *
 * Without manage=1 this is a CONSUMER view: active categories only, resolved for
 * the company whose context the analyst is working in.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/ticket_categories.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

$manage = !empty($_GET['manage']);
if ($manage) {
    // The retired rows and the per-company switches are a settings view, not
    // something every analyst with the ticket list should be reading.
    requireCapabilityJson(Cap::TICKETS_CATEGORIES);
}

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    $activeId  = getActiveTenantId($conn, $analystId);
    $multi     = isMultiTenant($conn);

    $opts = ['activeOnly' => !$manage];
    if (!empty($_GET['portal']))                      $opts['portalOnly'] = true;
    if (isset($_GET['type_id']) && $_GET['type_id'] !== '') $opts['typeId'] = (int) $_GET['type_id'];

    $categories = array_values(ticketCategoriesResolved($conn, $activeId, $opts));
    $codes      = ticketResolutionCodesResolved($conn, $activeId, ['activeOnly' => !$manage]);

    // How many tickets each category and code is on. The settings screen needs it
    // to explain why something cannot be deleted; it costs one grouped scan each.
    if ($manage) {
        $useCounts = ['category' => [], 'code' => []];
        try {
            foreach ($conn->query(
                "SELECT category_id AS id, COUNT(*) AS n FROM tickets WHERE category_id IS NOT NULL GROUP BY category_id"
            ) as $r) { $useCounts['category'][(int) $r['id']] = (int) $r['n']; }
            foreach ($conn->query(
                "SELECT closure_category_id AS id, COUNT(*) AS n FROM tickets WHERE closure_category_id IS NOT NULL GROUP BY closure_category_id"
            ) as $r) {
                $id = (int) $r['id'];
                $useCounts['category'][$id] = ($useCounts['category'][$id] ?? 0) + (int) $r['n'];
            }
            foreach ($conn->query(
                "SELECT resolution_code_id AS id, COUNT(*) AS n FROM tickets WHERE resolution_code_id IS NOT NULL GROUP BY resolution_code_id"
            ) as $r) { $useCounts['code'][(int) $r['id']] = (int) $r['n']; }
        } catch (Throwable $e) { /* columns absent on a part-migrated install */ }

        foreach ($categories as &$c) { $c['in_use'] = $useCounts['category'][$c['id']] ?? 0; }
        unset($c);
        foreach ($codes as &$k)      { $k['in_use'] = $useCounts['code'][$k['id']] ?? 0; }
        unset($k);
    }

    $resp = [
        'success'      => true,
        'multi_tenant' => $multi,
        'categories'   => $categories,
        'resolution_codes' => $codes,
        'max_depth'    => TICKET_CATEGORY_MAX_DEPTH,
        // What the analyst's CURRENT company answers. A ticket form uses these.
        'settings'     => ticketClassificationSettings($conn, $activeId),
    ];

    if ($manage) {
        // The install-wide defaults, and every company that has said otherwise.
        $keys = [
            'category'         => SETTING_TICKET_CATEGORY,
            'closure_category' => SETTING_TICKET_CLOSURE_CATEGORY,
            'resolution_code'  => SETTING_TICKET_RESOLUTION_CODE,
        ];

        $default = [];
        foreach ($keys as $field => $key) {
            $default[$field] = tenantSettingOn($conn, null, $key, false);
        }

        $overrides = [];
        foreach ($keys as $field => $key) { $overrides[$field] = tenantSettingsForKey($conn, $key); }

        $companies = [];
        if ($multi) {
            foreach (getAllTenants($conn) as $t) {
                $tid = (int) $t['id'];
                $row = ['id' => $tid, 'name' => $t['name']];
                foreach ($keys as $field => $key) {
                    // null = follows the install default. NOT the same as false.
                    $row[$field] = array_key_exists($tid, $overrides[$field])
                        ? ($overrides[$field][$tid] !== '0')
                        : null;
                }
                $companies[] = $row;
            }
        }

        $resp['manage'] = [
            'default'   => $default,
            'companies' => $companies,
            // Which ticket types a category may be attached to.
            'ticket_types' => array_map(
                static fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']],
                getTenantConfigRows($conn, 'ticket_types', 'ticket_type', $activeId,
                    'id, name', 'is_active = 1', 'display_order, name')
            ),
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
