<?php
/**
 * API: Resolve a triage ticket — file it to a company.
 * POST JSON {
 *   ticket_id:        int     the triage ticket being resolved (required),
 *   tenant_id:        int     target company (0/absent → create a new one),
 *   new_company_name: string  name for the new company (when tenant_id is 0),
 *   domain:           string  the sender's domain (for mapping/sweeping),
 *   map_domain:       bool    also register `domain` to the company so this and
 *                             all other queued mail from it route automatically
 * }
 *
 * "Mapping a domain sweeps both ways" (design §6): registering the domain both
 * re-files every queued ticket from it AND auto-routes future mail. Free-email
 * domains are never mapped (two clients can share them) — those are filed one
 * ticket at a time.
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

$ticketId  = isset($data['ticket_id']) ? (int)$data['ticket_id'] : 0;
$tenantId  = isset($data['tenant_id']) ? (int)$data['tenant_id'] : 0;
$newName   = trim($data['new_company_name'] ?? '');
$domain    = normaliseEmailDomain($data['domain'] ?? '');
$mapDomain = !empty($data['map_domain']);

if ($ticketId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing ticket']);
    exit;
}

try {
    $conn = connectToDatabase();

    // --- Resolve the target company (existing, or create a new one) ---
    if ($tenantId <= 0) {
        if ($newName === '') {
            echo json_encode(['success' => false, 'error' => 'Choose a company or enter a name for a new one']);
            exit;
        }
        $ins = $conn->prepare("INSERT INTO tenants (name, is_default, is_active, created_datetime) VALUES (?, 0, 1, UTC_TIMESTAMP())");
        $ins->execute([$newName]);
        $tenantId = (int)$conn->lastInsertId();
        $createdCompany = true;
    } else {
        if (!getTenantById($conn, $tenantId)) {
            echo json_encode(['success' => false, 'error' => 'Company not found']);
            exit;
        }
        $createdCompany = false;
    }

    // Free-email domains can never be mapped (shared across clients).
    $freemail = $domain !== '' && isFreemailDomain($conn, $domain);
    $domainMapped = false;

    if ($mapDomain && $domain !== '' && !$freemail) {
        // Domain is unique across companies — only map if free, or already ours.
        $check = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ?");
        $check->execute([$domain]);
        $owner = $check->fetchColumn();
        if ($owner === false) {
            $conn->prepare("INSERT INTO tenant_domains (tenant_id, domain) VALUES (?, ?)")->execute([$tenantId, $domain]);
            $domainMapped = true;
        } elseif ((int)$owner === $tenantId) {
            $domainMapped = true; // already mapped to this company
        } else {
            $ownerRow = getTenantById($conn, (int)$owner);
            echo json_encode(['success' => false, 'error' => "That domain is already mapped to " . ($ownerRow['name'] ?? 'another company') . " — pick that company, or assign this ticket without mapping the domain."]);
            exit;
        }
    }

    // --- Assign tickets ---
    if ($domainMapped) {
        // Sweep: every queued ticket whose origin sender is on this domain.
        $sweep = $conn->prepare(
            "UPDATE tickets t
             JOIN emails e ON e.id = (
                 SELECT e2.id FROM emails e2
                 WHERE e2.ticket_id = t.id AND e2.direction = 'Inbound'
                 ORDER BY e2.is_initial DESC, e2.received_datetime ASC, e2.id ASC
                 LIMIT 1
             )
             SET t.tenant_id = ?
             WHERE t.tenant_id IS NULL
               AND LOWER(SUBSTRING_INDEX(e.from_address, '@', -1)) = ?"
        );
        $sweep->execute([$tenantId, $domain]);
        $assigned = $sweep->rowCount();
        // Safety net: make sure the ticket the analyst clicked is filed even if its
        // sender domain didn't match (e.g. odd From header).
        $one = $conn->prepare("UPDATE tickets SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL");
        $one->execute([$tenantId, $ticketId]);
        $assigned += $one->rowCount();
    } else {
        $one = $conn->prepare("UPDATE tickets SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL");
        $one->execute([$tenantId, $ticketId]);
        $assigned = $one->rowCount();
    }

    echo json_encode([
        'success'         => true,
        'tenant_id'       => $tenantId,
        'assigned_count'  => $assigned,
        'domain_mapped'   => $domainMapped,
        'created_company' => $createdCompany,
        'freemail'        => $freemail,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
