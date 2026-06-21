<?php
/**
 * API: Inbound-email routing dry-run (diagnostic).
 *
 * Given a sender address and the mailbox an email would arrive at, work out
 * where a NEW ticket would be filed — which company, or triage — and WHICH
 * routing rule decided it. Creates nothing; reads only.
 *
 * The authoritative answer comes from resolveTicketTenantForEmail() in
 * includes/tenancy.php — the very function the live importer uses — so this
 * tool can never drift from real behaviour. Around that we assemble a
 * human-readable step trace from the same evidence (the mailbox's pinned/shared
 * state and the sender-domain lookup) purely to explain the result.
 *
 * The "reply to an existing ticket inherits its company" rule (step 1 of the
 * design's ordered routing) happens upstream of resolveTicketTenantForEmail()
 * and depends on whether the subject carries a ticket reference — so it can't be
 * simulated from a From + mailbox alone; it's shown as "not evaluated here".
 *
 * GET ?mailbox_id=N&from_address=jane@acme.com
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

$mailboxId = isset($_GET['mailbox_id']) ? (int)$_GET['mailbox_id'] : 0;
$fromAddress = trim($_GET['from_address'] ?? '');

if ($mailboxId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Pick a mailbox']);
    exit;
}

try {
    $conn = connectToDatabase();

    // The mailbox the mail arrives at (the "To" identity).
    $stmt = $conn->prepare(
        "SELECT id, name, target_mailbox, tenant_id, is_active,
                CASE WHEN token_data IS NOT NULL AND token_data != '' THEN 1 ELSE 0 END AS is_authenticated
         FROM target_mailboxes WHERE id = ?"
    );
    $stmt->execute([$mailboxId]);
    $mbRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$mbRow) {
        echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
        exit;
    }

    $mbAddress = '';
    if (!empty($mbRow['target_mailbox'])) {
        try { $mbAddress = decryptValue($mbRow['target_mailbox']); } catch (Exception $e) { $mbAddress = ''; }
    }
    $mbTenantId = ($mbRow['tenant_id'] === null) ? null : (int)$mbRow['tenant_id'];
    $isPinned   = ($mbTenantId !== null);

    $tenantName = function (PDO $conn, ?int $id): ?string {
        if ($id === null) return null;
        $t = getTenantById($conn, $id);
        return $t ? $t['name'] : ('#' . $id);
    };

    // If they typed a bare domain (no "@"), synthesise a full address so the
    // real routing function — which only extracts a domain when an "@" is
    // present — sees the same thing our trace does, and the two can't diverge.
    $fromForRouting = $fromAddress;
    if ($fromAddress !== '' && strpos($fromAddress, '@') === false) {
        $bare = normaliseEmailDomain($fromAddress);
        if ($bare !== '') $fromForRouting = 'test@' . $bare;
    }

    // Sender domain — extracted exactly as resolveTicketTenantForEmail() does.
    $fromDomain = '';
    if (strpos($fromForRouting, '@') !== false) {
        $fromDomain = strtolower(trim(substr(strrchr($fromForRouting, '@'), 1)));
    }
    $isFreemail = $fromDomain !== '' && isFreemailDomain($conn, $fromDomain);

    // Which company (if any) owns the sender's domain.
    $domainTenantId = null;
    if ($fromDomain !== '') {
        try {
            $ds = $conn->prepare("SELECT tenant_id FROM tenant_domains WHERE domain = ? LIMIT 1");
            $ds->execute([$fromDomain]);
            $v = $ds->fetchColumn();
            if ($v !== false && $v !== null) $domainTenantId = (int)$v;
        } catch (Exception $e) { /* table missing → no match */ }
    }

    $multi = isMultiTenant($conn);

    // ---- Authoritative result: the real importer function. ----
    $resultTenantId = resolveTicketTenantForEmail($conn, $mailboxId, $fromForRouting);
    $resultIsTriage = ($resultTenantId === null);

    // ---- Decide which rule fired (to label the result). ----
    if (!$multi) {
        $rule = 'single_company';
    } elseif ($isPinned) {
        $rule = 'pinned';
    } elseif ($domainTenantId !== null) {
        $rule = 'domain_match';
    } else {
        $rule = 'triage';
    }

    // ---- Build the step trace for display. ----
    $steps = [];

    // Step 1 — reply match (can't be simulated here).
    $steps[] = ['key' => 'reply', 'status' => 'not_evaluated'];

    if (!$multi) {
        // Single-company install: everything collapses to Default.
        $steps[] = ['key' => 'single_company', 'status' => 'fired',
            'company' => $tenantName($conn, $resultTenantId)];
    } else {
        // Step 2 — pinned mailbox.
        if ($isPinned) {
            $steps[] = ['key' => 'pinned', 'status' => 'fired',
                'mailbox' => $mbAddress ?: $mbRow['name'],
                'company' => $tenantName($conn, $mbTenantId)];
        } else {
            $steps[] = ['key' => 'pinned', 'status' => 'skipped',
                'mailbox' => $mbAddress ?: $mbRow['name']];

            // Step 3 — sender-domain match (only reached when not pinned).
            if ($fromDomain === '') {
                $steps[] = ['key' => 'domain', 'status' => 'no_domain'];
            } elseif ($domainTenantId !== null) {
                $steps[] = ['key' => 'domain', 'status' => 'fired',
                    'domain' => $fromDomain, 'company' => $tenantName($conn, $domainTenantId)];
            } else {
                $steps[] = ['key' => 'domain', 'status' => $isFreemail ? 'freemail' : 'no_match',
                    'domain' => $fromDomain];
            }

            // Step 4 — triage (only when domain didn't resolve).
            if ($resultIsTriage) {
                $steps[] = ['key' => 'triage', 'status' => 'fired'];
            }
        }
    }

    echo json_encode([
        'success'   => true,
        'multi'     => $multi,
        'mailbox'   => [
            'id'            => (int)$mbRow['id'],
            'name'          => $mbRow['name'],
            'address'       => $mbAddress,
            'type'          => $isPinned ? 'pinned' : 'shared',
            'tenant_id'     => $mbTenantId,
            'tenant_name'   => $tenantName($conn, $mbTenantId),
            'is_active'     => (bool)$mbRow['is_active'],
            'authenticated' => (bool)$mbRow['is_authenticated'],
        ],
        'from_address' => $fromAddress,
        'from_domain'  => $fromDomain,
        'is_freemail'  => $isFreemail,
        'rule'         => $rule,
        'result' => [
            'is_triage'   => $resultIsTriage,
            'tenant_id'   => $resultTenantId,
            'tenant_name' => $resultIsTriage ? null : $tenantName($conn, $resultTenantId),
        ],
        'steps' => $steps,
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
