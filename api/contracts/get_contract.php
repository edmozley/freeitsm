<?php
/**
 * API: one contract, with its supplier or customer, owner, status, payment schedule and
 * cost centre.
 *
 * A customer contract for a company the analyst cannot see is answered as "not found",
 * like one that does not exist (contractVisibilitySql).
 *
 * @input  id  the contract
 * @output {success, contract} - contract carries party_label and multi_company (show the company only when there is a choice)
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
    $id = $_GET['id'] ?? null;
    if (!$id) {
        throw new Exception('ID is required');
    }

    $conn = connectToDatabase();
    [$partyCols, $partyJoins] = contractPartySql($conn, 'c');
    [$ownCols, $ownJoins] = contractOwnerSql($conn, 'c');
    require_once '../../includes/cost_centre_assign.php';
    $ownCols .= costCentreSelectSql($conn, 'contracts', 'c');   // GH #160
    require_once '../../includes/record_locations.php';
    $ownCols .= recordLocationReady($conn, 'contracts') ? ', c.location_id' : ', NULL AS location_id';   // GH #311
    [$visSql, $visParams] = contractVisibilitySql($conn, (int)$_SESSION['analyst_id'], 'c');

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
            $ownJoins
            WHERE c.id = ?$visSql";

    // A customer contract the analyst may not see is "not found", like one that does not exist.
    require_once '../../includes/tenancy.php';
    $multiCompany = isMultiTenant($conn);
    $stmt = $conn->prepare($sql);
    $stmt->execute(array_merge([$id], $visParams));
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception('Contract not found');
    }

    $contract['is_active'] = (bool)$contract['is_active'];
    $contract['party_label'] = contractPartyLabel($contract);
    // GH #311: the site it covers, as its full path.
    $contract['location_id']   = !empty($contract['location_id']) ? (int)$contract['location_id'] : null;
    $contract['location_path'] = recordLocationPath($conn, $contract['location_id']);
    $contract['multi_company'] = $multiCompany;   // the view shows the company only when there is a choice

    echo json_encode(['success' => true, 'contract' => $contract]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
