<?php
/**
 * API: Register an email domain to a company (tenant) for shared-intake routing.
 * POST JSON { tenant_id, domain }
 *
 * Guards:
 *  - the company must exist;
 *  - the domain must be a plausible domain (normalised);
 *  - free-email/consumer domains (gmail.com, …) are REJECTED — two clients can
 *    share them, so they must never be mapped (they reach support via triage);
 *  - a domain is unique across all companies — if it's already mapped (to this or
 *    another company) we say so rather than silently moving it.
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
$domain   = normaliseEmailDomain($data['domain'] ?? '');

if ($tenantId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing company id']);
    exit;
}
if ($domain === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a valid domain, e.g. acme.com']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Free-email/consumer domains (built-in or admin-added) can never be mapped.
    if (isFreemailDomain($conn, $domain)) {
        echo json_encode(['success' => false, 'error' => "$domain is a public email provider and can't be mapped to a company — mail from it is filed by hand from the triage queue."]);
        exit;
    }

    if (!getTenantById($conn, $tenantId)) {
        echo json_encode(['success' => false, 'error' => 'Company not found']);
        exit;
    }

    // Domain is UNIQUE across all companies — report a clash clearly.
    $check = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ?");
    $check->execute([$domain]);
    $owner = $check->fetchColumn();
    if ($owner !== false) {
        if ((int)$owner === $tenantId) {
            echo json_encode(['success' => false, 'error' => 'That domain is already on this company']);
        } else {
            $ownerRow = getTenantById($conn, (int)$owner);
            $ownerName = $ownerRow['name'] ?? 'another company';
            echo json_encode(['success' => false, 'error' => "That domain is already mapped to $ownerName"]);
        }
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)");
    $stmt->execute([$tenantId, $domain]);

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'domain' => $domain]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
