<?php
/**
 * API: Create or update a business calendar including its weekly hours and
 * holiday list, all in one transaction.
 *
 * POST JSON: {
 *   id?:           int (omit to create)
 *   name:          string (required)
 *   timezone:      string (IANA zone, required, must validate)
 *   tenant_id?:    int|null (new calendars only: null = shared, else a client company the analyst can access; left out, the active company's context decides)
 *   is_default:    bool (one default per scope: one shared, at most one per company; setting this clears the others in its scope)
 *   hours:         [{ weekday: 1-7, start_time: 'HH:MM', end_time: 'HH:MM' }]   — absence of a weekday = closed
 *   holidays:      [{ holiday_date: 'YYYY-MM-DD', name?: string }]
 * }
 *
 * Hours and holidays are replaced wholesale (delete-then-insert in a transaction)
 * so the caller sends the complete desired state, not deltas.
 *
 * A shared calendar needs access to every company, a company's own needs access to that company.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/i18n.php';
require_once '../../includes/ticket_priorities.php';
I18n::initFromSession();

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();
header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_SLA);   // settings tab — see docs/design/rbac.md

try {
    $data = json_decode(file_get_contents('php://input'), true);

    $id        = isset($data['id']) ? (int)$data['id'] : null;
    $name      = trim((string)($data['name'] ?? ''));
    $timezone  = trim((string)($data['timezone'] ?? ''));
    $isDefault = !empty($data['is_default']) ? 1 : 0;
    $hours     = is_array($data['hours'] ?? null) ? $data['hours'] : [];
    $holidays  = is_array($data['holidays'] ?? null) ? $data['holidays'] : [];

    if ($name === '') throw new Exception('Name is required');
    if ($timezone === '') throw new Exception('Timezone is required');

    // Validate timezone is real before we hit the DB
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        throw new Exception('Unknown timezone: ' . $timezone);
    }

    // Validate hours rows
    $cleanHours = [];
    $seenWeekdays = [];
    foreach ($hours as $h) {
        $wd = (int)($h['weekday'] ?? 0);
        if ($wd < 1 || $wd > 7) throw new Exception('Invalid weekday');
        if (isset($seenWeekdays[$wd])) throw new Exception('Duplicate weekday in hours');
        $seenWeekdays[$wd] = true;

        $start = trim((string)($h['start_time'] ?? ''));
        $end   = trim((string)($h['end_time'] ?? ''));
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $start) || !preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $end)) {
            throw new Exception('Invalid time format (use HH:MM)');
        }
        if (strtotime($start) >= strtotime($end)) throw new Exception('End time must be after start time');
        // Normalise to HH:MM:00
        if (strlen($start) === 5) $start .= ':00';
        if (strlen($end)   === 5) $end   .= ':00';
        $cleanHours[] = ['weekday' => $wd, 'start' => $start, 'end' => $end];
    }

    // Validate holidays
    $cleanHolidays = [];
    $seenDates = [];
    foreach ($holidays as $h) {
        $d = trim((string)($h['holiday_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) throw new Exception('Invalid holiday date');
        if (isset($seenDates[$d])) continue; // silently dedupe
        $seenDates[$d] = true;
        $cleanHolidays[] = ['date' => $d, 'name' => trim((string)($h['name'] ?? ''))];
    }

    $conn = connectToDatabase();
    $ctx = ticketPriorityScopeContext($conn, (int)$_SESSION['analyst_id']);

    if ($id) {
        $cur = $conn->prepare("SELECT " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM sla_calendars WHERE id = ?");
        $cur->execute([$id]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception(t('tickets.settings.sla_calendars.not_found'));
        $scopeTenant = $row['tenant_id'] === null ? null : (int)$row['tenant_id'];
    } else {
        $scopeTenant = array_key_exists('tenant_id', $data)
            ? ($ctx['multi'] && !empty($data['tenant_id']) ? (int)$data['tenant_id'] : null)
            : $ctx['company'];
    }
    tenantScopeRequireAccess($ctx, $scopeTenant, 'tickets.settings.sla_calendars');

    // A shared name must not match any calendar, or a company with its own of that name would see two.
    // A company's name must not match its own or a shared one.
    // A company scope only exists once Database Verification has added tenant_id (ticketPriorityScopeContext()).
    $clashSql = $scopeTenant === null
        ? "SELECT id, " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM sla_calendars WHERE LOWER(name) = LOWER(?)"
        : "SELECT id, tenant_id FROM sla_calendars WHERE LOWER(name) = LOWER(?) AND (tenant_id IS NULL OR tenant_id = " . (int)$scopeTenant . ")";
    $clashParams = [$name];
    if ($id) {
        $clashSql .= " AND id <> ?";
        $clashParams[] = $id;
    }
    $clash = $conn->prepare($clashSql);
    $clash->execute($clashParams);
    $clashRow = $clash->fetch(PDO::FETCH_ASSOC);
    if ($clashRow) {
        throw new Exception(t($scopeTenant === null && $clashRow['tenant_id'] !== null
            ? 'tickets.settings.sla_calendars.name_exists_company'
            : 'tickets.settings.sla_calendars.name_exists'));
    }

    $conn->beginTransaction();

    if ($isDefault) {
        $conn->prepare("UPDATE sla_calendars SET is_default = 0 WHERE " . ticketPriorityTenantCol($conn) . " <=> ?")->execute([$scopeTenant]);
    }

    if ($id) {
        $stmt = $conn->prepare("UPDATE sla_calendars SET name = ?, timezone = ?, is_default = ? WHERE id = ?");
        $stmt->execute([$name, $timezone, $isDefault, $id]);
    } else {
        // Before Database Verification there is no tenant_id column - every calendar is shared (ticketPriorityTenancyReady()).
        if (ticketPriorityTenancyReady($conn)) {
            $stmt = $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default, tenant_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $timezone, $isDefault, $scopeTenant]);
        } else {
            $stmt = $conn->prepare("INSERT INTO sla_calendars (name, timezone, is_default) VALUES (?, ?, ?)");
            $stmt->execute([$name, $timezone, $isDefault]);
        }
        $id = (int)$conn->lastInsertId();
    }

    // Replace hours wholesale
    $conn->prepare("DELETE FROM sla_calendar_hours WHERE calendar_id = ?")->execute([$id]);
    if (!empty($cleanHours)) {
        $hStmt = $conn->prepare("INSERT INTO sla_calendar_hours (calendar_id, weekday, start_time, end_time) VALUES (?, ?, ?, ?)");
        foreach ($cleanHours as $h) {
            $hStmt->execute([$id, $h['weekday'], $h['start'], $h['end']]);
        }
    }

    // Replace holidays wholesale
    $conn->prepare("DELETE FROM sla_calendar_holidays WHERE calendar_id = ?")->execute([$id]);
    if (!empty($cleanHolidays)) {
        $holStmt = $conn->prepare("INSERT INTO sla_calendar_holidays (calendar_id, holiday_date, name) VALUES (?, ?, ?)");
        foreach ($cleanHolidays as $h) {
            $holStmt->execute([$id, $h['date'], $h['name'] !== '' ? $h['name'] : null]);
        }
    }

    $conn->commit();
    echo json_encode(['success' => true, 'id' => $id]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
