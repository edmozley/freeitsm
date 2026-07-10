<?php
/**
 * API Endpoint: list mailboxes an analyst can SEND a reply FROM for the company
 * they're currently working in. Used to populate the "Send replies from" picker
 * on the New Ticket modal so a manually-created ticket is paired with a mailbox.
 *
 * "Relevant" = active + authenticated mailboxes that are either pinned to the
 * active company OR shared intake (tenant_id IS NULL, which serves everyone).
 * At N=1 there's no company concept, so it's simply all active+authenticated
 * mailboxes. (Kept separate from get_mailboxes.php, which the inbound poll uses
 * and which must return every mailbox.)
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

try {
    $conn = connectToDatabase();
    $analystId = (int)$_SESSION['analyst_id'];
    $multi     = isMultiTenant($conn);
    $tenantId  = getActiveTenantId($conn, $analystId);

    $tenantName = null;
    if ($multi) {
        $ts = $conn->prepare("SELECT name FROM tenants WHERE id = ?");
        $ts->execute([$tenantId]);
        $tenantName = $ts->fetchColumn() ?: null;
    }

    // Only mailboxes that can actually send: active + either holding an OAuth token
    // (Microsoft/Google) or a basic IMAP mailbox (sends via stored SMTP credentials).
    $canSend = "(provider = 'imap' OR (token_data IS NOT NULL AND token_data <> ''))";
    if ($multi) {
        // Pinned to this company first, then shared intake.
        $sql = "SELECT id, name, tenant_id
                  FROM target_mailboxes
                 WHERE is_active = 1 AND $canSend
                   AND (tenant_id = ? OR tenant_id IS NULL)
                 ORDER BY (tenant_id IS NULL), name";
        $st = $conn->prepare($sql);
        $st->execute([$tenantId]);
    } else {
        $sql = "SELECT id, name, tenant_id
                  FROM target_mailboxes
                 WHERE is_active = 1 AND $canSend
                 ORDER BY name";
        $st = $conn->prepare($sql);
        $st->execute();
    }

    $mailboxes = array_map(function ($r) {
        return [
            'id'        => (int)$r['id'],
            'name'      => $r['name'],
            'tenant_id' => $r['tenant_id'] === null ? null : (int)$r['tenant_id'],
            'pinned'    => $r['tenant_id'] !== null,   // pinned to a company vs shared intake
        ];
    }, $st->fetchAll(PDO::FETCH_ASSOC));

    echo json_encode([
        'success'      => true,
        'multi_tenant' => $multi,
        'tenant_id'    => $tenantId,
        'tenant_name'  => $tenantName,
        'mailboxes'    => $mailboxes,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
