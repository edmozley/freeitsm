<?php
/**
 * API: Get all data for the SLA settings tab in one round-trip.
 *
 * Returns:
 *   settings:    { sla_enforce_from, sla_priority_change_behaviour, ... }
 *   priorities:  [{ id, name, sla_response_minutes, sla_resolution_minutes, sla_calendar_id, company, editable }]
 *                One company selected: the shared ones it has not hidden, then its own.
 *                Default company: the shared ones. All companies: the shared ones, then each accessible company's own.
 *                `company` is the owning company's name, null when shared.
 *                `editable` is false for a shared one when the analyst cannot reach every company.
 *   multi_tenant: whether to show the company of each priority
 *   priorities also carry `calendar_options`: the calendar ids that priority may run on.
 *   calendars:   [{ id, name, timezone, is_default, hours: [...], holiday_count, company, editable }]
 *                Chosen as the priorities are: one company selected, the shared ones then its own.
 *   can_share, companies, company: for the calendar modal's Company field (see ticketPriorityScopeContext())
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_priorities.php';
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');

try {
    $conn = connectToDatabase();

    // Pull the 7 SLA system_settings rows
    $settingKeys = [
        'sla_enforce_from',
        'sla_priority_change_behaviour',
        'sla_reopen_behaviour',
        'sla_warning_threshold_percent',
        'sla_notify_assignee_at_warning',
        'sla_notify_lead_at_breach',
        'sla_first_response_definition',
    ];
    $placeholders = implode(',', array_fill(0, count($settingKeys), '?'));
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingKeys);
    $settings = [];
    foreach ($settingKeys as $k) $settings[$k] = null; // default to null
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $ctx = ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']);
    $companyNames = array_column($ctx['companies'], 'name', 'id');

    if ($ctx['company'] !== null) {
        $own = ticketPrioritiesFor($conn, $ctx['company']);
        $priorities = array_merge(
            array_filter($own, fn(array $p): bool => $p['tenant_id'] === null),
            array_filter($own, fn(array $p): bool => $p['tenant_id'] !== null),
        );
    } else {
        $priorities = ticketPrioritiesShared($conn);
        if ($ctx['multi'] && isActiveTenantAll($conn)) {
            foreach ($ctx['companies'] as $company) {
                $priorities = array_merge($priorities, ticketPrioritiesOwn($conn, $company['id']));
            }
        }
    }
    $priorities = array_values(array_filter($priorities, fn(array $p): bool => $p['is_active']));
    $optionsFor = [];
    foreach (array_unique(array_map(fn(array $p): int => $p['tenant_id'] ?? 0, $priorities)) as $tid) {
        $optionsFor[$tid] = slaCalendarOptionIds($conn, $tid ?: null);
    }
    $priorities = array_map(fn(array $p): array => $p + [
        'company'          => $p['tenant_id'] === null ? null : ($companyNames[$p['tenant_id']] ?? ''),
        'editable'         => $p['tenant_id'] !== null || !$ctx['multi'] || $ctx['can_share'],
        'calendar_options' => $optionsFor[$p['tenant_id'] ?? 0],
    ], $priorities);

    $calendars = slaCalendarsOwnedBy($conn, null);
    if ($ctx['company'] !== null) {
        $calendars = array_merge($calendars, slaCalendarsOwnedBy($conn, $ctx['company']));
    } elseif ($ctx['multi'] && isActiveTenantAll($conn)) {
        foreach ($ctx['companies'] as $company) {
            $calendars = array_merge($calendars, slaCalendarsOwnedBy($conn, $company['id']));
        }
    }
    $calendars = array_map(fn(array $c): array => $c + [
        'company'  => $c['tenant_id'] === null ? null : ($companyNames[$c['tenant_id']] ?? ''),
        'editable' => $c['tenant_id'] !== null || !$ctx['multi'] || $ctx['can_share'],
    ], $calendars);

    if (!empty($calendars)) {
        $calIds = array_column($calendars, 'id');
        $ph = implode(',', array_fill(0, count($calIds), '?'));

        $hStmt = $conn->prepare("SELECT calendar_id, weekday, start_time, end_time FROM sla_calendar_hours WHERE calendar_id IN ($ph) ORDER BY weekday");
        $hStmt->execute($calIds);
        $hoursByCal = [];
        foreach ($hStmt->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $hoursByCal[$h['calendar_id']][] = ['weekday' => (int)$h['weekday'], 'start_time' => substr($h['start_time'], 0, 5), 'end_time' => substr($h['end_time'], 0, 5)];
        }

        $cStmt = $conn->prepare("SELECT calendar_id, COUNT(*) AS cnt FROM sla_calendar_holidays WHERE calendar_id IN ($ph) GROUP BY calendar_id");
        $cStmt->execute($calIds);
        $countsByCal = [];
        foreach ($cStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $countsByCal[$c['calendar_id']] = (int)$c['cnt'];
        }

        foreach ($calendars as &$cal) {
            $cal['hours'] = $hoursByCal[$cal['id']] ?? [];
            $cal['holiday_count'] = $countsByCal[$cal['id']] ?? 0;
        }
        unset($cal);
    }

    echo json_encode([
        'success'    => true,
        'settings'   => $settings,
        'priorities'   => $priorities,
        'calendars'    => $calendars,
        'multi_tenant' => $ctx['multi'],
        'can_share'    => $ctx['can_share'],
        'companies'    => $ctx['companies'],
        'company'      => $ctx['company'] === null ? null : ['id' => $ctx['company'], 'name' => $companyNames[$ctx['company']] ?? ''],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
