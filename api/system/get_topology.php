<?php
/**
 * API: Topology / overview — the whole multi-tenant configuration in one shape,
 * for the System -> Topology tree. Company-rooted (companies are the isolation
 * boundary) with a separate "Global / shared" node for things that serve every
 * company (shared mailboxes, global SSO providers, all-access analysts).
 *
 * Read-only aggregation. Degrades gracefully if a table/column from a newer
 * migration isn't present yet (returns empty for that slice).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/encryption.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Defensive query helper — returns [] if the table/column doesn't exist yet.
    $rows = function ($sql, $params = []) use ($conn) {
        try { $s = $conn->prepare($sql); $s->execute($params); return $s->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return []; }
    };
    $groupBy = function (array $list, $key) {
        $out = [];
        foreach ($list as $r) { $out[(int)$r[$key]][] = $r; }
        return $out;
    };

    $multi = isMultiTenant($conn);

    // --- Companies ---
    $companies = $rows("SELECT id, name, is_default FROM tenants WHERE is_active = 1 ORDER BY is_default DESC, name");
    if (!$companies) {
        // Pre-tenancy install: synthesise a single Default company.
        $companies = [['id' => getDefaultTenantId($conn), 'name' => 'Default', 'is_default' => 1]];
    }

    // --- Bulk child loads, grouped by tenant_id ---
    $mailboxRows = $rows("SELECT id, name, tenant_id, is_active, provider, target_mailbox,
                                 email_folder, last_checked_datetime,
                                 CASE WHEN token_data IS NOT NULL AND token_data <> '' THEN 1 ELSE 0 END AS is_auth
                            FROM target_mailboxes ORDER BY name");
    // The mailbox address is encrypted at rest — decrypt for display (admin-only view).
    foreach ($mailboxRows as &$_m) {
        $addr = $_m['target_mailbox'] ?? '';
        try { if (function_exists('decryptValue')) $addr = decryptValue($addr); }
        catch (Throwable $e) { $addr = '(unavailable)'; }
        $_m['address'] = $addr;
    }
    unset($_m);
    $domainRows  = $rows("SELECT tenant_id, domain FROM tenant_domains ORDER BY domain");
    $senderRows  = $rows("SELECT tenant_id, email FROM tenant_sender_addresses ORDER BY email");
    $providerRows= $rows("SELECT id, display_name, enabled, tenant_id FROM auth_providers ORDER BY sort_order, display_name");
    $grantRows   = $rows("SELECT ata.tenant_id, a.id, a.full_name, a.username
                            FROM analyst_tenant_access ata JOIN analysts a ON a.id = ata.analyst_id
                           WHERE a.is_active = 1 ORDER BY a.full_name");
    $ticketRows  = $rows("SELECT tenant_id, COUNT(*) AS total, SUM(closed_datetime IS NULL) AS open
                            FROM tickets WHERE deleted_datetime IS NULL GROUP BY tenant_id");
    // Requesters approximated by email-domain → company mapping.
    $reqRows     = $rows("SELECT td.tenant_id, COUNT(u.id) AS cnt
                            FROM tenant_domains td
                            LEFT JOIN users u ON LOWER(SUBSTRING_INDEX(u.email, '@', -1)) = LOWER(td.domain)
                           GROUP BY td.tenant_id");

    $mbByTenant   = $groupBy(array_filter($mailboxRows, fn($m) => $m['tenant_id'] !== null), 'tenant_id');
    $domByTenant  = $groupBy($domainRows, 'tenant_id');
    $sndByTenant  = $groupBy($senderRows, 'tenant_id');
    $provByTenant = $groupBy(array_filter($providerRows, fn($p) => $p['tenant_id'] !== null), 'tenant_id');
    $grantByTenant= $groupBy($grantRows, 'tenant_id');
    $tktByTenant  = []; foreach ($ticketRows as $r) { $tktByTenant[(int)$r['tenant_id']] = $r; }
    $reqByTenant  = []; foreach ($reqRows as $r) { $reqByTenant[(int)$r['tenant_id']] = (int)$r['cnt']; }

    // Shared (global) mailboxes/providers and whether any can actually send.
    $sharedMailboxes = array_values(array_filter($mailboxRows, fn($m) => $m['tenant_id'] === null));
    $globalProviders = array_values(array_filter($providerRows, fn($p) => $p['tenant_id'] === null));
    $sharedSendable  = count(array_filter($sharedMailboxes, fn($m) => $m['is_active'] && $m['is_auth']));

    $allAccessAnalysts = $rows("SELECT id, full_name, username FROM analysts WHERE can_access_all_tenants = 1 AND is_active = 1 ORDER BY full_name");

    $mapMailbox = fn($m) => [
        'id' => (int)$m['id'], 'name' => $m['name'],
        'address'      => $m['address'] ?? '',
        'provider'     => $m['provider'] ?? '',
        'folder'       => $m['email_folder'] ?? '',
        'last_checked' => $m['last_checked_datetime'] ?? null,
        'is_active' => (bool)$m['is_active'], 'is_authenticated' => (bool)$m['is_auth'],
    ];
    $mapProvider = fn($p) => ['id' => (int)$p['id'], 'name' => $p['display_name'], 'enabled' => (bool)$p['enabled']];
    $mapAnalyst  = fn($a) => ['id' => (int)$a['id'], 'name' => $a['full_name'] ?: $a['username']];

    // --- Assemble per-company nodes ---
    $out = [];
    foreach ($companies as $c) {
        $tid = (int)$c['id'];
        $pinned = array_map($mapMailbox, $mbByTenant[$tid] ?? []);
        $pinnedSendable = count(array_filter($pinned, fn($m) => $m['is_active'] && $m['is_authenticated']));
        $tkt = $tktByTenant[$tid] ?? ['total' => 0, 'open' => 0];

        $out[] = [
            'id'        => $tid,
            'name'      => $c['name'],
            'is_default'=> (bool)$c['is_default'],
            'mailboxes' => $pinned,
            'shared_mailbox_count' => count($sharedMailboxes),
            'has_sendable_mailbox'  => ($pinnedSendable + $sharedSendable) > 0,
            'domains'   => array_map(fn($d) => $d['domain'], $domByTenant[$tid] ?? []),
            'senders'   => array_map(fn($s) => $s['email'], $sndByTenant[$tid] ?? []),
            'providers' => array_map($mapProvider, $provByTenant[$tid] ?? []),
            'analysts'  => array_map($mapAnalyst, $grantByTenant[$tid] ?? []),
            'requester_count' => $reqByTenant[$tid] ?? 0,
            'ticket_total'    => (int)$tkt['total'],
            'ticket_open'     => (int)($tkt['open'] ?? 0),
        ];
    }

    echo json_encode([
        'success'      => true,
        'multi_tenant' => $multi,
        'companies'    => $out,
        'global'       => [
            'mailboxes'          => array_map($mapMailbox, $sharedMailboxes),
            'providers'          => array_map($mapProvider, $globalProviders),
            'all_access_analysts'=> array_map($mapAnalyst, $allAccessAnalysts),
        ],
        'totals' => [
            'companies' => count($out),
            'mailboxes' => count($mailboxRows),
            'providers' => count($providerRows),
            'analysts'  => (int)($rows("SELECT COUNT(*) c FROM analysts WHERE is_active = 1")[0]['c'] ?? 0),
            'users'     => (int)($rows("SELECT COUNT(*) c FROM users")[0]['c'] ?? 0),
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
