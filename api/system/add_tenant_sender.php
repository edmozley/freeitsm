<?php
/**
 * API: Map a specific sender address to a company (tenant) for shared-intake
 * routing. POST JSON { tenant_id, email }
 *
 * The address-level twin of add_tenant_domain.php. Unlike domains, freemail
 * addresses ARE allowed here — that's the whole point: route an individual
 * personal address (jane@gmail.com) to a company even though its domain can
 * never be mapped (two clients share gmail.com).
 *
 * Guards:
 *  - the company must exist;
 *  - the email must be a valid address (normalised);
 *  - an address is unique across all companies — if it's already mapped (to this
 *    or another company) we say so rather than silently moving it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$tenantId = isset($data['tenant_id']) ? (int)$data['tenant_id'] : 0;
$email    = normaliseEmailAddress($data['email'] ?? '');

if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing company id']);
    exit;
}
if ($email === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a valid email address, e.g. jane@gmail.com']);
    exit;
}

try {
    $conn = connectToDatabase();

    if (!getTenantById($conn, $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }

    // Address is UNIQUE across all companies — report a clash clearly.
    $check = $conn->prepare("SELECT tenant_id FROM tenant_sender_addresses WHERE email = ?");
    $check->execute([$email]);
    $owner = $check->fetchColumn();
    if ($owner !== false) {
        if ((int)$owner === $tenantId) {
            echo json_encode(['success' => false, 'error' => 'That address is already on this company']);
        } else {
            $ownerRow = getTenantById($conn, (int)$owner);
            $ownerName = $ownerRow['name'] ?? 'another company';
            echo json_encode(['success' => false, 'error' => "That address is already mapped to $ownerName"]);
        }
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO tenant_sender_addresses (tenant_id, email) VALUES (?, ?)");
    $stmt->execute([$tenantId, $email]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'email' => $email]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
