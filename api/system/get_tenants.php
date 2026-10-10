<?php
/**
 * API: List companies (tenants).
 * GET - returns every company. "Company" is the user-facing word for a tenant;
 * the underlying table/code stays `tenants`.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_numbering.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // The email domains registered to each company (one query, grouped in PHP),
    // so the list can show them without a round-trip per company. Degrades to
    // empty if the table isn't there yet.
    $domainsByTenant = [];
    try {
        foreach ($conn->query("SELECT tenant_id, domain FROM tenant_domains ORDER BY domain") as $row) {
            $domainsByTenant[(int)$row['tenant_id']][] = $row['domain'];
        }
    } catch (Exception $e) {
        $domainsByTenant = [];
    }

    // The explicit ticket codes, read separately from getAllTenants() ON PURPOSE:
    // that helper is called from everywhere, and adding a column to it would
    // break every page on an install that has not run db_verify since this
    // column arrived. Here a missing column simply means "nobody has set one".
    $codes = [];
    try {
        foreach ($conn->query("SELECT id, ticket_code FROM tenants") as $row) {
            $codes[(int)$row['id']] = $row['ticket_code'];
        }
    } catch (Exception $e) {
        $codes = [];
    }

    // Each company's own logo (System -> Companies, shown on the Files desktop).
    // Read on its own for the same reason as the codes: a missing column on an
    // install that has not run Database Verify just means "no logos yet".
    $logos = [];
    try {
        foreach ($conn->query("SELECT id, logo_path FROM tenants WHERE logo_path IS NOT NULL") as $row) {
            $logos[(int)$row['id']] = BASE_URL . $row['logo_path'];
        }
    } catch (Exception $e) {
        $logos = [];
    }

    // ?accessible=1 → only the companies this analyst may access (for "move ticket to
    // company" pickers). Default returns every company (unchanged behaviour).
    $accessibleOnly = !empty($_GET['accessible']);
    // The full list (every company and its email domains) is only asked for by the
    // System screens, Tickets settings and Triage. Everyone else asks for ?accessible=1.
    if (!$accessibleOnly) {
        requireAnyModuleAccessJson(['system', 'tickets'], $conn);
    }
    $allowed =$accessibleOnly ? getAccessibleTenantIds($conn, (int)$_SESSION['analyst_id']) : null;

    $companies = [];
    foreach (getAllTenants($conn) as $t) {
        $id = (int)$t['id'];
        if ($allowed !== null && !in_array($id, $allowed, true)) {
            continue;
        }
        $companies[] = [
            'id'         => $id,
            'name'       => $t['name'],
            'is_default' => (bool)$t['is_default'],
            'is_active'  => (bool)$t['is_active'],
            'domains'    => $domainsByTenant[$id] ?? [],
            // Both are sent: the raw code so the field shows what was typed (and
            // stays blank when nothing was), and the effective one so the screen
            // can say what {COMPANY} would actually produce.
            'ticket_code'           => $codes[$id] ?? null,
            'effective_ticket_code' => TicketNumbering::codeFor($t + ['ticket_code' => $codes[$id] ?? null]),
            'logo_url'              => $logos[$id] ?? null,
        ];
    }

    echo json_encode(['success' => true, 'companies' => $companies]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
