<?php
/**
 * API: The triage queue — inbound-email tickets that matched no company.
 *
 * These are tickets with tenant_id IS NULL: mail that arrived at a shared-intake
 * mailbox whose sender domain matched none of the registered company domains
 * (including freemail, which is never mapped). They're the safety net — nothing
 * is lost; an analyst files each one to a company here.
 *
 * Returns one row per such ticket with its sender, sender domain and whether that
 * domain is freemail (so the UI can offer "map this domain" only when safe).
 *
 * Empty on a single-company install (triage is meaningless with one company).
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

    if (!isMultiTenant($conn)) {
        echo json_encode(['success' => true, 'tickets' => [], 'multi_tenant' => false]);
        exit;
    }

    // The initial inbound email carries the sender we route on. Pick the earliest
    // inbound email per ticket as the "origin" sender.
    $sql = "SELECT t.id AS ticket_id, t.ticket_number, t.subject, t.created_datetime,
                   e.from_address, e.from_name, e.received_datetime,
                   mb.name AS mailbox_name
            FROM tickets t
            LEFT JOIN emails e ON e.id = (
                SELECT e2.id FROM emails e2
                WHERE e2.ticket_id = t.id AND e2.direction = 'Inbound'
                ORDER BY e2.is_initial DESC, e2.received_datetime ASC, e2.id ASC
                LIMIT 1
            )
            LEFT JOIN target_mailboxes mb ON mb.id = e.mailbox_id
            WHERE t.tenant_id IS NULL
            ORDER BY COALESCE(e.received_datetime, t.created_datetime) DESC";

    $rows = $conn->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $tickets = [];
    foreach ($rows as $r) {
        $from   = (string)($r['from_address'] ?? '');
        $domain = '';
        if (strpos($from, '@') !== false) {
            $domain = strtolower(trim(substr(strrchr($from, '@'), 1)));
        }
        $tickets[] = [
            'ticket_id'    => (int)$r['ticket_id'],
            'ticket_number'=> $r['ticket_number'],
            'subject'      => $r['subject'],
            'from_address' => $from,
            'from_name'    => $r['from_name'] ?? '',
            'domain'       => $domain,
            'is_freemail'  => $domain !== '' && isFreemailDomain($conn, $domain),
            'mailbox_name' => $r['mailbox_name'] ?? '',
            'received'     => $r['received_datetime'] ?? $r['created_datetime'],
        ];
    }

    echo json_encode(['success' => true, 'tickets' => $tickets, 'multi_tenant' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
