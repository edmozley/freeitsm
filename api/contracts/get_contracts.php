<?php
/**
 * API: the contracts list - the active company's contracts, or every company the analyst
 * can see under "All companies".
 *
 * Newest first. The search matches contract number, title, supplier names and, once
 * customer contracts exist, the customer company or person.
 *
 * @input  search  optional text to match
 * @input  limit   optional maximum number of rows
 * @input  party   optional 'supplier' or 'customer' - only that kind of contract
 * @output {success, contracts: [...]} - each with party_label
 * @wiki Customer-Contracts-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/contract_party.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('contracts');

try {
    $conn = connectToDatabase();
    $search = $_GET['search'] ?? '';
    $limit = $_GET['limit'] ?? null;
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    [$ownCols, $ownJoins] = contractOwnerSql($conn, 'c');
    require_once '../../includes/cost_centre_assign.php';
    $ownCols .= costCentreSelectSql($conn, 'contracts', 'c');   // GH #160

    $sql = "SELECT c.id, c.contract_number, c.title, c.description, c.supplier_id, c.contract_owner_id,
                   c.contract_status_id, cs.name AS contract_status_name,
                   c.contract_start, c.contract_end, c.notice_period_days, c.notice_date,
                   c.contract_value, c.currency,
                   c.payment_schedule_id, ps.name AS payment_schedule_name,
                   c.cost_centre, c.dms_link,
                   c.terms_status, c.personal_data_transferred, c.dpia_required, c.dpia_completed_date, c.dpia_dms_link,
                   c.is_active, c.created_datetime,
                   s.legal_name AS supplier_name, s.trading_name AS supplier_trading_name,
                   a.full_name AS owner_name
                   $partyCols$ownCols
            FROM contracts c
            LEFT JOIN suppliers s ON c.supplier_id = s.id
            LEFT JOIN analysts a ON c.contract_owner_id = a.id
            LEFT JOIN contract_statuses cs ON c.contract_status_id = cs.id
            LEFT JOIN payment_schedules ps ON c.payment_schedule_id = ps.id
            $partyJoins
            $ownJoins";

    // The active company's contracts, or every company the analyst can see when
    // the switcher says "All companies" (3.5.0) - like Assets and Domains.
    [$visSql, $params] = contractListSql($conn, (int)$_SESSION['analyst_id'], 'c');
    $sql .= " WHERE 1=1" . $visSql;
    if (!empty($search)) {
        $like = '%' . $search . '%';
        if (contractPartyReady($conn)) {
            $sql .= " AND (c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ? OR s.trading_name LIKE ? OR cpt.name LIKE ? OR cpu.display_name LIKE ? OR cpu.email LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        } else {
            $sql .= " AND (c.contract_number LIKE ? OR c.title LIKE ? OR s.legal_name LIKE ?)";
            array_push($params, $like, $like, $like);
        }
    }
    $party = $_GET['party'] ?? '';
    if (contractPartyReady($conn) && in_array($party, [CONTRACT_PARTY_SUPPLIER, CONTRACT_PARTY_CUSTOMER], true)) {
        $sql .= " AND c.party_type = ?";
        $params[] = $party;
    }
    // Watchtower's Contracts card links here (discussion #388): ?expiring=N is an
    // active contract ending between today and today + N days, ?notice=N one whose
    // notice date falls in that window. Kept to the SAME conditions as the card in
    // includes/watchtower_queries.php (is_active, BETWEEN, the naive "today"), so
    // the list shows exactly the contracts the card counted.
    require_once '../../includes/timezone.php';
    foreach (['expiring' => 'c.contract_end', 'notice' => 'c.notice_date'] as $key => $col) {
        $days = (int)($_GET[$key] ?? 0);
        if ($days < 1 || $days > 366) continue;
        $today = naive_today_sql();
        $sql .= " AND c.is_active = 1 AND $col IS NOT NULL AND $col BETWEEN {$today} AND DATE_ADD({$today}, INTERVAL $days DAY)";
    }

    $sql .= " ORDER BY c.created_datetime DESC";

    if ($limit) {
        $sql .= " LIMIT " . intval($limit);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $contracts = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($contracts as &$c) {
        $c['is_active'] = (bool)$c['is_active'];
        $c['party_label'] = contractPartyLabel($c);
    }
    unset($c);

    echo json_encode(['success' => true, 'contracts' => $contracts]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
