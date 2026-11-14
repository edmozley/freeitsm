<?php
/**
 * API: the picker for a record property on a CI (GH #312) - people, analysts,
 * teams, locations, companies, suppliers and contracts a CI may point at.
 *
 * Query params:
 *   type       one of cmdbRecordTypes() (person, analyst, team, location, company, supplier, contract)
 *   q          text to match (may be empty: the first few are listed)
 *   object_id  the CI being edited - its company decides who/what may be offered
 *   asset_id   or: the asset a new linked CI is being created from (its company)
 *   tenant_id  or: the company a new CI is being created in
 *   (none)     the analyst's active company
 *
 * The same rule is enforced again when the CI is saved (cmdbRecordAllowed), so
 * this only decides what is OFFERED. Inactive records are never offered.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/cmdb_record_refs.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('cmdb');

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $type = (string)($_GET['type'] ?? '');
    if (!cmdbIsRecordType($type)) {
        echo json_encode(['success' => false, 'error' => 'Unknown record type']);
        exit;
    }

    // Which company the CI is (or will be) in. Every route checks the analyst may
    // reach it, so the picker cannot be used to list another company's people.
    $ciTenant = null;
    if (!empty($_GET['object_id'])) {
        $oid = (int)$_GET['object_id'];
        if (!analystCanAccessCmdbObject($conn, $analystId, $oid)) {
            echo json_encode(['success' => false, 'error' => 'Object not found']);
            exit;
        }
        $t = $conn->prepare("SELECT tenant_id FROM cmdb_objects WHERE id = ?");
        $t->execute([$oid]);
        $v = $t->fetchColumn();
        $ciTenant = ($v === false || $v === null) ? (int)getDefaultTenantId($conn) : (int)$v;
    } elseif (!empty($_GET['asset_id'])) {
        $aid = (int)$_GET['asset_id'];
        if (!analystCanAccessAsset($conn, $analystId, $aid)) {
            echo json_encode(['success' => false, 'error' => 'Asset not found']);
            exit;
        }
        $t = $conn->prepare("SELECT tenant_id FROM assets WHERE id = ?");
        $t->execute([$aid]);
        $v = $t->fetchColumn();
        $ciTenant = ($v === false || $v === null) ? (int)getDefaultTenantId($conn) : (int)$v;
    } elseif (!empty($_GET['tenant_id'])) {
        $ciTenant = (int)$_GET['tenant_id'];
        if (isMultiTenant($conn) && !analystCanAccessTenant($conn, $analystId, $ciTenant)) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            exit;
        }
    } else {
        $ciTenant = (int)getActiveTenantId($conn, $analystId);
    }

    $reach = analystHasAllTenantAccess($conn, $analystId) ? null : getAccessibleTenantIds($conn, $analystId);
    $results = cmdbRecordSearch($conn, $type, (string)($_GET['q'] ?? ''), $ciTenant, $reach, (int)($_GET['limit'] ?? 20));
    echo json_encode(['success' => true, 'results' => $results]);
} catch (Exception $e) {
    error_log('cmdb search_records: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Search failed']);
}
