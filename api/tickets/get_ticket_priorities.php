<?php
/**
 * API Endpoint: Get ticket priorities
 *
 * `priorities` is the active company's list: its own priorities plus the shared ones it has not hidden.
 * On a single-company install that is every priority.
 *
 * In the consolidated view `by_company` adds each company's list, so a ticket can be offered its own company's priorities rather than the page's.
 *
 * ?manage=1 is the settings screen.
 * In a client company's context it adds `scoped`: the company's own priorities and every shared one with whether this company hides it.
 * In the default company's context and the All companies view, `priorities` is the shared list.
 * The All companies view adds `company_priorities`, each company's own, for the companies the analyst can access.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_priorities.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $manage    = !empty($_GET['manage']);

    $ctx      = ticketPriorityScopeContext($conn, $analystId);
    $activeId = getActiveTenantId($conn, $analystId);

    $resp = [
        'success'      => true,
        'priorities'   => ticketPrioritiesFor($conn, $activeId, false),
        'multi_tenant' => $ctx['multi'],
    ];

    if (!$manage && isActiveTenantAll($conn)) {
        $byCompany = [];
        foreach (getAccessibleTenantIds($conn, $analystId) as $tid) {
            $byCompany[(int)$tid] = ticketPrioritiesFor($conn, (int)$tid, false);
        }
        $resp['by_company']    = $byCompany;
        $resp['all_companies'] = true;
    }

    if ($manage) {
        $resp['can_share'] = $ctx['can_share'];
        $resp['companies'] = $ctx['companies'];
    }

    if ($manage && $ctx['company'] === null) {
        $resp['priorities'] = ticketPrioritiesShared($conn);
    }

    if ($manage && $ctx['multi'] && isActiveTenantAll($conn)) {
        $resp['company_priorities'] = [];
        $own = $conn->prepare("SELECT " . TICKET_PRIORITY_COLUMNS . " FROM ticket_priorities WHERE tenant_id = ? ORDER BY display_order, name");
        foreach ($ctx['companies'] as $company) {
            $own->execute([$company['id']]);
            $rows = array_map('ticketPriorityRow', $own->fetchAll(PDO::FETCH_ASSOC));
            if ($rows) {
                $resp['company_priorities'][] = ['company' => $company, 'priorities' => $rows];
            }
        }
    }

    if ($manage && $ctx['company'] !== null) {
        $hidden = $conn->prepare("SELECT entity_id FROM tenant_config_hidden WHERE tenant_id = ? AND entity_type = 'ticket_priority'");
        $hidden->execute([$ctx['company']]);
        $hiddenIds = array_map('intval', $hidden->fetchAll(PDO::FETCH_COLUMN));

        $company = getTenantById($conn, $ctx['company']);
        $resp['scoped'] = [
            'is_default' => false,
            'company'    => ['id' => $ctx['company'], 'name' => $company['name'] ?? ''],
            'own'        => array_values(array_filter($resp['priorities'], fn(array $p): bool => $p['tenant_id'] === $ctx['company'])),
            'globals'    => array_map(
                fn(array $p): array => $p + ['hidden' => in_array($p['id'], $hiddenIds, true)],
                ticketPrioritiesShared($conn),
            ),
        ];
    }

    echo json_encode($resp);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
