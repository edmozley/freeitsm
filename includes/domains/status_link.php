<?php
/**
 * Domains -> Service Status (3.0.0): what a domain's trouble does to the
 * services linked to it (domain_status_services). Everything here is decided
 * by Domains -> Settings -> Service Status.
 *
 *   off      nothing - the links are still shown, as information
 *   suggest  the domain's page says which services are at risk and offers one
 *            button that raises the incident; a person decides (the default)
 *   auto     the scheduled run raises it by itself
 *
 * "Trouble" is the same idea the Watchtower card and the alerts already use: a
 * domain whose status still wants alerts and which is not being deliberately
 * let go, that has EXPIRED (domain_status_on_expired) or whose live certificate
 * has expired, or is within domain_status_cert_days of it (domain_status_on_cert).
 *
 * 🔑 NEVER TWICE. domain_status_incidents holds one row per (domain, trigger,
 * fingerprint), the fingerprint being the date the trouble is about - the
 * domain_alerts_sent rule. The same lapse never raises a second incident, and
 * renewing (a new date) re-arms it. While a domain has an incident it raised
 * that is still open, nothing new is raised for it either: one incident per
 * outage, however many reasons.
 *
 * 🔑 CUSTOMERS READ THE STATUS PAGE. The opening update is INTERNAL unless
 * domain_status_public says otherwise - Service Status's own rule (#99) for any
 * caller that did not ask.
 *
 * Incidents are created and resolved through ServiceStatusService, so they get
 * the opening update, the impact snapshot and the workflow events exactly as
 * one raised by hand.
 */

require_once __DIR__ . '/../service_context.php';   // ServiceError, thrown below (GH #288)
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/links.php';
require_once __DIR__ . '/../services/service_status.php';

if (!defined('DOMAIN_STATUS_LINK_LOADED')) {
    define('DOMAIN_STATUS_LINK_LOADED', true);

    /**
     * True once Database Verification has created the domain_status_incidents and domain_status_services tables; checked once per request.
     */
    function domainStatusReady(PDO $conn): bool
    {
        static $ready = null;
        if ($ready === null) {
            try {
                $conn->query("SELECT 1 FROM domain_status_incidents LIMIT 0");
                $conn->query("SELECT 1 FROM domain_status_services LIMIT 0");
                $ready = true;
            } catch (Throwable) {
                $ready = false;
            }
        }
        return $ready;
    }

    /**
     * What is wrong with this domain right now, as far as Service Status is
     * concerned. Each: kind ('expired' | 'cert'), fingerprint (the date), date.
     *
     * @param array $row  the domain row from domainStatusRow() (expiry_date, ssl_expiry_date, renewal_mode, alerts_enabled)
     */
    function domainStatusProblems(PDO $conn, array $row): array
    {
        $s = domainSettings($conn);
        if (($row['renewal_mode'] ?? '') === 'do_not_renew') return [];
        if (isset($row['alerts_enabled']) && (int)$row['alerts_enabled'] === 0) return [];
        $today = gmdate('Y-m-d');
        $out = [];
        $exp = substr((string)($row['expiry_date'] ?? ''), 0, 10);
        if ($s['domain_status_on_expired'] === '1' && $exp !== '' && $exp < $today) {
            $out[] = ['kind' => 'expired', 'fingerprint' => $exp, 'date' => $exp];
        }
        $ssl = substr((string)($row['ssl_expiry_date'] ?? ''), 0, 10);
        if ($s['domain_status_on_cert'] === '1' && $ssl !== '') {
            $limit = gmdate('Y-m-d', strtotime('+' . (int)$s['domain_status_cert_days'] . ' days'));
            $due = (int)$s['domain_status_cert_days'] === 0 ? $ssl < $today : $ssl <= $limit;
            if ($due) $out[] = ['kind' => 'cert', 'fingerprint' => $ssl, 'date' => $ssl];
        }
        return $out;
    }

    /**
     * One domain row with the fields the problem test needs (alerts_enabled from its status).
     *
     * @param int $domainId  the domain to load; a missing one gives null
     */
    function domainStatusRow(PDO $conn, int $domainId): ?array
    {
        $st = $conn->prepare(
            "SELECT d.id, d.domain_name, d.display_name, d.expiry_date, d.ssl_expiry_date, d.renewal_mode,
                    COALESCE(s.alerts_enabled, 1) AS alerts_enabled
               FROM domains d LEFT JOIN domain_statuses s ON s.id = d.status_id WHERE d.id = ?");
        $st->execute([$domainId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * The services linked to a domain, ids only (install-wide records).
     *
     * @param int $domainId  the domain whose active linked services to list
     */
    function domainStatusServiceIds(PDO $conn, int $domainId): array
    {
        $st = $conn->prepare("SELECT ds.service_id FROM domain_status_services ds JOIN status_services ss ON ss.id = ds.service_id
                               WHERE ds.domain_id = ? AND ss.is_active = 1");
        $st->execute([$domainId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The incident this domain raised that is still open, or null.
     *
     * @param int $domainId  the domain whose open, domain-raised incident to find
     */
    function domainStatusOpenIncident(PDO $conn, int $domainId): ?array
    {
        $st = $conn->prepare(
            "SELECT di.incident_id, i.title, i.created_datetime
               FROM domain_status_incidents di JOIN status_incidents i ON i.id = di.incident_id
              WHERE di.domain_id = ? AND di.resolved_datetime IS NULL AND i.resolved_datetime IS NULL
              ORDER BY di.id DESC LIMIT 1");
        $st->execute([$domainId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        return $r ? ['id' => (int)$r['incident_id'], 'title' => $r['title'], 'created' => $r['created_datetime']] : null;
    }

    /**
     * For the domain's page: the mode, the trouble, the linked services at
     * risk, an open incident if there is one, and whether this analyst may raise
     * one (Service Status module access).
     *
     * @param int $domainId  the domain whose page is being drawn
     */
    function domainStatusState(PDO $conn, int $analystId, int $domainId): array
    {
        $mode = domainSetting($conn, 'domain_status_mode');
        $row = domainStatusRow($conn, $domainId);
        $out = ['mode' => $mode, 'ready' => domainStatusReady($conn), 'problems' => [], 'at_risk' => 0, 'incident' => null,
                'can_raise' => analystCanAccessModule($conn, $analystId, 'service-status')];
        if (!$row || !$out['ready']) return $out;
        $out['problems'] = domainStatusProblems($conn, $row);
        $out['at_risk'] = $out['problems'] ? count(domainStatusServiceIds($conn, $domainId)) : 0;
        $out['incident'] = domainStatusOpenIncident($conn, $domainId);
        return $out;
    }

    /**
     * The title and opening comment, in plain words (stored data: what customers may read).
     *
     * @param array $row       the domain row from domainStatusRow() (domain_name, display_name)
     * @param array $problems  the non-empty list from domainStatusProblems(), each array{kind:string,fingerprint:string,date:string}
     */
    function domainStatusWording(array $row, array $problems): array
    {
        $name = $row['display_name'] ?: $row['domain_name'];
        $kinds = array_column($problems, 'kind');
        $title = in_array('expired', $kinds, true)
            ? "$name has expired"
            : "The certificate for $name " . (min(array_column($problems, 'date')) < gmdate('Y-m-d') ? 'has expired' : 'is about to expire');
        $lines = [];
        foreach ($problems as $p) {
            $lines[] = $p['kind'] === 'expired'
                ? "The domain registration for $name expired on {$p['date']}."
                : "The certificate served for $name expires on {$p['date']}.";
        }
        $lines[] = 'Raised from the Domains register. Services that depend on this domain may be unreachable or show security warnings until it is renewed.';
        return [$title, implode("\n\n", $lines)];
    }

    /**
     * Raise one incident for a domain's trouble on its linked services, and
     * record it against every current problem. Returns the incident id, or the
     * open one it already raised. Throws ServiceError when there is nothing to
     * raise (no trouble, or no services linked).
     *
     * @param int $domainId  the domain whose trouble to raise an incident for
     */
    function domainStatusRaise(PDO $conn, ActorContext $ctx, int $domainId): int
    {
        if (!domainStatusReady($conn)) throw new ServiceError('validation', 'not_ready', 'Run System → Database Verification first.');
        $open = domainStatusOpenIncident($conn, $domainId);
        if ($open) return $open['id'];
        $row = domainStatusRow($conn, $domainId);
        if (!$row) throw new ServiceError('not_found', 'not_found', 'Domain not found.');
        $problems = domainStatusProblems($conn, $row);
        if (!$problems) throw new ServiceError('validation', 'nothing_to_raise', 'Nothing is wrong with this domain that Service Status is set to report.');
        $services = domainStatusServiceIds($conn, $domainId);
        if (!$services) throw new ServiceError('validation', 'no_services', 'No Service Status services are linked to this domain.');

        $s = domainSettings($conn);
        $impact = domainStatusImpactId($conn, (int)$s['domain_status_impact']);
        [$title, $comment] = domainStatusWording($row, $problems);
        $incidentId = ServiceStatusService::saveIncident($conn, $ctx, [
            'title'       => $title,
            'comment'     => $comment,
            'is_internal' => $s['domain_status_public'] !== '1',
            'services'    => array_map(fn($id) => $impact > 0 ? ['service_id' => $id, 'impact_level_id' => $impact] : ['service_id' => $id], $services),
        ]);
        $ins = $conn->prepare("INSERT IGNORE INTO domain_status_incidents (domain_id, incident_id, trigger_kind, fingerprint) VALUES (?, ?, ?, ?)");
        foreach ($problems as $p) $ins->execute([$domainId, $incidentId, $p['kind'], $p['fingerprint']]);
        return $incidentId;
    }

    /**
     * The impact level to record. The chosen one if it still exists; otherwise
     * the MOST severe active level that counts as downtime.
     *
     * 🔴 Not Service Status's own default level: on a stock install that is
     * "Operational", so falling back to it would raise an incident that says
     * the service is fine.
     *
     * @param int $chosen  the domain_status_impact setting as an id; 0 means none chosen
     */
    function domainStatusImpactId(PDO $conn, int $chosen): int
    {
        if ($chosen > 0) {
            $chk = $conn->prepare("SELECT 1 FROM service_impact_levels WHERE id = ? AND is_active = 1");
            $chk->execute([$chosen]);
            if ($chk->fetchColumn()) return $chosen;
        }
        foreach (["counts_as_downtime = 1 AND is_active = 1", "is_active = 1"] as $where) {
            try {
                $id = (int)$conn->query("SELECT id FROM service_impact_levels WHERE $where ORDER BY severity_order, display_order, id LIMIT 1")->fetchColumn();
                if ($id > 0) return $id;
            } catch (Throwable) { /* counts_as_downtime before Database Verification: try without */ }
        }
        return 0;
    }

    /** The first active "resolved" incident status, by its own order. */
    function domainStatusResolvedName(PDO $conn): ?string
    {
        $n = $conn->query("SELECT name FROM service_incident_statuses WHERE is_resolved = 1 AND is_active = 1 ORDER BY display_order, id LIMIT 1")->fetchColumn();
        return $n === false ? null : (string)$n;
    }

    /**
     * The scheduled part: raise (mode auto) and resolve (domain_status_auto_resolve).
     * The actor is the system - actorId 0, which ServiceStatusService stores
     * as the creator rather than inventing a member of staff.
     */
    function domainStatusRun(PDO $conn): array
    {
        $out = ['raised' => 0, 'resolved' => 0];
        if (!domainStatusReady($conn)) return $out;
        $s = domainSettings($conn);
        $ctx = new ActorContext(actorId: 0, companyScope: null, source: 'api', actorName: 'Domains');

        if ($s['domain_status_mode'] === 'auto') {
            $ids = $conn->query("SELECT DISTINCT domain_id FROM domain_status_services")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($ids as $id) {
                $id = (int)$id;
                $row = domainStatusRow($conn, $id);
                if (!$row || domainStatusOpenIncident($conn, $id)) continue;
                $problems = domainStatusProblems($conn, $row);
                if (!$problems) continue;
                // Already raised for exactly this trouble once (and resolved by a
                // person since): never again for the same dates.
                $seen = $conn->prepare("SELECT 1 FROM domain_status_incidents WHERE domain_id = ? AND trigger_kind = ? AND fingerprint = ?");
                $fresh = array_filter($problems, function ($p) use ($seen, $id) { $seen->execute([$id, $p['kind'], $p['fingerprint']]); return !$seen->fetchColumn(); });
                if (!$fresh) continue;
                try { domainStatusRaise($conn, $ctx, $id); $out['raised']++; }
                catch (Throwable $e) { error_log("domains status raise $id: " . $e->getMessage()); }
            }
        }

        if ($s['domain_status_auto_resolve'] === '1') {
            $resolved = domainStatusResolvedName($conn);
            $open = $conn->query("SELECT DISTINCT domain_id, incident_id FROM domain_status_incidents WHERE resolved_datetime IS NULL")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($open as $o) {
                $row = domainStatusRow($conn, (int)$o['domain_id']);
                $current = $row ? array_map(fn($p) => $p['kind'] . '|' . $p['fingerprint'], domainStatusProblems($conn, $row)) : [];
                $st = $conn->prepare("SELECT trigger_kind, fingerprint FROM domain_status_incidents WHERE incident_id = ? AND resolved_datetime IS NULL");
                $st->execute([(int)$o['incident_id']]);
                $still = array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($r) => in_array($r['trigger_kind'] . '|' . $r['fingerprint'], $current, true));
                if ($still) continue;                          // something it was raised for is still true
                try {
                    $inc = $conn->prepare("SELECT resolved_datetime FROM status_incidents WHERE id = ?");
                    $inc->execute([(int)$o['incident_id']]);
                    $was = $inc->fetch(PDO::FETCH_ASSOC);
                    if ($was && $was['resolved_datetime'] === null && $resolved !== null) {
                        ServiceStatusService::saveIncident($conn, $ctx, [
                            'id' => (int)$o['incident_id'], 'status' => $resolved,
                            'comment' => 'Resolved by the Domains register: the domain is no longer expired or its certificate has been renewed.',
                            'is_internal' => $s['domain_status_public'] !== '1',
                        ]);
                        $out['resolved']++;
                    }
                    $conn->prepare("UPDATE domain_status_incidents SET resolved_datetime = UTC_TIMESTAMP() WHERE incident_id = ? AND resolved_datetime IS NULL")
                         ->execute([(int)$o['incident_id']]);
                } catch (Throwable $e) {
                    error_log('domains status resolve ' . $o['incident_id'] . ': ' . $e->getMessage());
                }
            }
        }
        return $out;
    }
}
