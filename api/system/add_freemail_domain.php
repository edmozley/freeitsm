<?php
/**
 * API: Add a custom public / free-email domain (add-only — the built-in list
 * can't be edited, so gmail.com & friends can never be un-protected).
 * POST JSON { domain }
 *
 * Guards:
 *  - must be a plausible domain (normalised);
 *  - rejected if it's already a built-in (no-op — already treated as public);
 *  - rejected if it's already in the custom list;
 *  - rejected if it's currently mapped to a company (tenant_domains) — a real
 *    company domain and a public domain are mutually exclusive; remove it there
 *    first.
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

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

$domain = normaliseEmailDomain($data['domain'] ?? '');
if ($domain === '') {
    echo json_encode(['success' => false, 'error' => 'Enter a valid domain, e.g. example.com']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Already a built-in → nothing to do.
    if (in_array($domain, freemailBuiltinDomains(), true)) {
        echo json_encode(['success' => false, 'error' => "$domain is already treated as a public domain."]);
        exit;
    }

    // Already mapped to a company → can't also be public.
    $check = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ?");
    $check->execute([$domain]);
    $owner = $check->fetchColumn();
    if ($owner !== false) {
        $ownerRow = getTenantById($conn, (int)$owner);
        $ownerName = $ownerRow['name'] ?? 'a company';
        echo json_encode(['success' => false, 'error' => "$domain is registered to $ownerName. Remove it there first if it's really a public domain."]);
        exit;
    }

    // Insert (UNIQUE on domain makes a duplicate a clean clash).
    try {
        $stmt = $conn->prepare("INSERT INTO freemail_domains (domain) VALUES (?)");
        $stmt->execute([$domain]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            echo json_encode(['success' => false, 'error' => "$domain is already in the list."]);
            exit;
        }
        throw $e;
    }

    echo json_encode(['success' => true, 'id' => (int)$conn->lastInsertId(), 'domain' => $domain]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
