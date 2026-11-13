<?php
/**
 * Address suggestions for the To and Cc boxes when emailing from a ticket (GitHub #307).
 *
 *   AddressSuggest::search($conn, $analystId, $ticketId, $q)  -> list of {email, name, source, detail}
 *
 * Four sources, in this order, the first to offer an address winning:
 *
 *   1. service_desk - a supplier's service desk address (suppliers.ticket_email): the
 *      address an outside provider takes tickets on, labelled "Acme service desk".
 *   2. contact      - supplier contacts with an email address.
 *   3. person       - people (requesters) of THE TICKET'S company.
 *   4. recent       - addresses this install has emailed from tickets of the ticket's
 *      company in the last 180 days.
 *
 * WHO SEES WHAT. Suppliers and their contacts are shared across companies in FreeITSM
 * (they belong to the Contracts module, not to a company), so they are offered only to
 * an analyst who can use Contracts. People and recent recipients come only from the
 * ticket's own company - an address from one company's tickets is never suggested on
 * another's - and recent recipients only from tickets in departments the analyst can see that are not confidential: who a confidential ticket was sent to is
 * part of what is confidential.
 *
 * @wiki Ticket-Address-Suggestions-Developer-Guide
 */

require_once __DIR__ . '/../tenancy.php';
require_once __DIR__ . '/../tenant_settings.php';          // ticketTenantId()
require_once __DIR__ . '/../ticket_department_scope.php';  // ticketDepartmentScopeSql()

final class AddressSuggest
{
    /** How many suggestions at most, across every source. */
    public const LIMIT = 12;
    /** How far back recent recipients are looked for. */
    public const RECENT_DAYS = 180;

    /**
     * Suggestions for what has been typed so far.
     *
     * @param int $ticketId  the ticket being emailed from; the caller has checked the analyst may open it
     * @param string $q  what has been typed after the last comma or semicolon - at least 2 characters
     * @return list<array{email:string, name:string, source:string, detail:string}>
     */
    public static function search(PDO $conn, int $analystId, int $ticketId, string $q): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) return [];
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $out = [];
        $add = function (string $email, string $name, string $source, string $detail) use (&$out): void {
            $email = trim($email);
            $key = strtolower($email);
            if ($email === '' || isset($out[$key]) || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
            $out[$key] = ['email' => $email, 'name' => trim($name), 'source' => $source, 'detail' => trim($detail)];
        };
        $full = fn() => count($out) >= self::LIMIT;

        $tenantId = (int)(ticketTenantId($conn, $ticketId) ?? getDefaultTenantId($conn));
        $contracts = analystCanAccessModule($conn, $analystId, 'contracts');

        // 1. Supplier service desks.
        if ($contracts && tenancyColumnExists($conn, 'suppliers', 'ticket_email')) {
            $st = $conn->prepare(
                "SELECT ticket_email, COALESCE(NULLIF(trading_name, ''), legal_name) AS name
                   FROM suppliers
                  WHERE is_active = 1 AND ticket_email IS NOT NULL AND ticket_email <> ''
                    AND (legal_name LIKE ? OR trading_name LIKE ? OR ticket_email LIKE ?)
                  ORDER BY name LIMIT " . self::LIMIT);
            $st->execute([$like, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['ticket_email'], $r['name'], 'service_desk', $r['name']);
        }

        // 2. Supplier contacts.
        if ($contracts && !$full()) {
            $st = $conn->prepare(
                "SELECT c.email, CONCAT(c.first_name, ' ', c.surname) AS name, COALESCE(NULLIF(s.trading_name, ''), s.legal_name) AS supplier
                   FROM contacts c LEFT JOIN suppliers s ON s.id = c.supplier_id
                  WHERE c.is_active = 1 AND c.email IS NOT NULL AND c.email <> ''
                    AND (c.email LIKE ? OR c.first_name LIKE ? OR c.surname LIKE ? OR CONCAT(c.first_name, ' ', c.surname) LIKE ?
                         OR s.legal_name LIKE ? OR s.trading_name LIKE ?)
                  ORDER BY c.first_name, c.surname LIMIT " . self::LIMIT);
            $st->execute([$like, $like, $like, $like, $like, $like]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['email'], $r['name'], 'contact', (string)$r['supplier']);
        }

        // 3. People of the ticket's company.
        if (!$full()) {
            [$tSql, $tArgs] = tenantScopeSqlFor($conn, $tenantId, 'u.tenant_id');
            $active = tenancyColumnExists($conn, 'users', 'is_active') ? ' AND u.is_active = 1' : '';
            $st = $conn->prepare(
                "SELECT u.email, COALESCE(NULLIF(u.display_name, ''), u.email) AS name, u.job_title
                   FROM users u
                  WHERE u.email IS NOT NULL AND u.email <> ''$active
                    AND (u.email LIKE ? OR u.display_name LIKE ?)$tSql
                  ORDER BY u.display_name LIMIT " . self::LIMIT);
            $st->execute(array_merge([$like, $like], $tArgs));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $add($r['email'], $r['name'], 'person', (string)$r['job_title']);
        }

        // 4. Recent recipients from the ticket's company, on tickets the analyst could open.
        if (!$full()) {
            foreach (self::recent($conn, $analystId, $tenantId, $like, $q) as $email) {
                $add($email, '', 'recent', '');
                if ($full()) break;
            }
        }
        return array_slice(array_values($out), 0, self::LIMIT);
    }

    /**
     * Addresses emailed from the company's tickets lately that match what was typed.
     * Reads the newest matching messages and pulls the addresses out of their To and Cc.
     *
     * @param string $like  the LIKE pattern for what was typed
     * @param string $q  what was typed, to keep only the addresses in a message that match it
     * @return list<string>
     */
    private static function recent(PDO $conn, int $analystId, int $tenantId, string $like, string $q): array
    {
        // The ticket's company (below) and the analyst's departments. NOT ticketScopeFilter(): its company
        // half is the analyst's ACTIVE company, which on another company's ticket would hide that
        // company's own recipients. The analyst may open this ticket, so may see its company.
        [$scopeSql, $scopeArgs] = ticketDepartmentScopeSql($conn, $analystId, 't');
        [$tSql, $tArgs] = tenantScopeSqlFor($conn, $tenantId, 't.tenant_id');
        $conf = tenancyColumnExists($conn, 'tickets', 'sensitivity') ? " AND (t.sensitivity IS NULL OR t.sensitivity <> 'confidential')" : '';
        $st = $conn->prepare(
            "SELECT e.to_recipients, e.cc_recipients
               FROM emails e JOIN tickets t ON t.id = e.ticket_id
              WHERE e.direction = 'Outbound'
                AND e.received_datetime >= UTC_TIMESTAMP() - INTERVAL " . self::RECENT_DAYS . " DAY
                AND (e.to_recipients LIKE ? OR e.cc_recipients LIKE ?)
                AND t.deleted_datetime IS NULL$conf$tSql$scopeSql
              ORDER BY e.id DESC LIMIT 200");
        $st->execute(array_merge([$like, $like], $tArgs, $scopeArgs));
        $found = [];
        $needle = strtolower($q);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            preg_match_all('/[A-Za-z0-9._%+\'-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', $r['to_recipients'] . ' ' . $r['cc_recipients'], $m);
            foreach ($m[0] as $email) {
                if (str_contains(strtolower($email), $needle)) $found[strtolower($email)] = $email;
            }
        }
        return array_values($found);
    }
}
