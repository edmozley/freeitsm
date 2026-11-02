<?php
/**
 * API: the type a new ticket gets when nobody chose one (GH #230).
 *
 * GET                       the rules to manage here, the shared rules behind them,
 *                           the default type, and the lists the rule editor offers
 * POST {action:'save', id?, name, ticket_type_id, conditions:{...}, is_active}
 * POST {action:'delete', id}
 * POST {action:'move', id, dir: -1 | 1}
 * POST {action:'set_default', value: '' (none) | 'inherit' (company follows the shared one) | type id}
 * POST {action:'test', facts:{channel, mailbox_id, origin_id, department_id, subject, requester_email}}
 *
 * Context decides scope, as for ticket types themselves: a single-company install,
 * or the MSP/Default company on a multi-company one, manages the SHARED rules
 * (tenant_id NULL) and the install-wide default; a client company's context
 * manages that company's own rules - checked before the shared ones - and its own
 * default. Needs the Ticket types settings permission.
 *
 * @wiki Ticket-Types-For-New-Tickets
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();
require_once '../../includes/rbac.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/tenant_settings.php';
require_once '../../includes/ticket_type_rules.php';

header('Content-Type: application/json');
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_TICKET_TYPES);

/** Stops with a JSON error. */
function ttrFail(string $msg, int $code = 200): never
{
    if ($code !== 200) http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    $conn = connectToDatabase();
    if (!ticketTypeRulesReady($conn)) ttrFail(t('tickets.type_rules.not_ready'));
    $analystId    = (int)$_SESSION['analyst_id'];
    $multi        = isMultiTenant($conn);
    $activeId     = getActiveTenantId($conn, $analystId);
    $defaultId    = getDefaultTenantId($conn);
    $isDefaultCtx = (!$multi || $activeId === $defaultId);
    $scopeTenant  = $isDefaultCtx ? null : $activeId;
    // The company whose view of types, origins and mailboxes the editor offers.
    $viewTenant   = $isDefaultCtx ? ($multi ? null : $defaultId) : $activeId;
    $usable       = ticketTypeUsable($conn, $viewTenant);

    $own = function (int $id) use ($conn, $scopeTenant): array {
        $s = $conn->prepare("SELECT * FROM ticket_type_rules WHERE id = ? AND tenant_id <=> ?");
        $s->execute([$id, $scopeTenant]);
        $r = $s->fetch(PDO::FETCH_ASSOC);
        if (!$r) ttrFail(t('tickets.type_rules.not_found'), 404);
        return $r;
    };
    $shape = fn(array $r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'ticket_type_id' => (int)$r['ticket_type_id'],
                              'is_active' => (bool)$r['is_active'], 'conditions' => $r['conditions'] ?? ticketTypeRuleConditions(json_decode((string)$r['conditions_json'], true))];

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $all = ticketTypeRulesFor($conn, $scopeTenant, true);
        $mine = array_values(array_filter($all, fn($r) => $r['tenant_id'] === $scopeTenant));
        $shared = $scopeTenant !== null ? array_values(array_filter($all, fn($r) => $r['tenant_id'] === null && $r['is_active'])) : [];

        $installDefault = (int)tenantSetting($conn, null, TICKET_TYPE_DEFAULT_KEY, '');
        $companyOwn = null;
        if ($scopeTenant !== null) {
            $s = $conn->prepare("SELECT setting_value FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?");
            $s->execute([$scopeTenant, TICKET_TYPE_DEFAULT_KEY]);
            $v = $s->fetchColumn();
            $companyOwn = $v === false ? null : (string)$v;
        }

        $mailboxes = [];
        // The name only: target_mailbox is stored encrypted, so it would show as ENC:...
        $mbSql = "SELECT id, name FROM target_mailboxes WHERE is_active = 1" . ($scopeTenant !== null ? " AND (tenant_id IS NULL OR tenant_id = " . (int)$scopeTenant . ")" : "") . " ORDER BY name";
        foreach ($conn->query($mbSql) as $m) $mailboxes[] = ['id' => (int)$m['id'], 'name' => (string)$m['name']];
        $origins = array_map(fn($o) => ['id' => (int)$o['id'], 'name' => $o['name']],
            $viewTenant !== null ? getTenantConfigRows($conn, 'ticket_origins', 'ticket_origin', $viewTenant, 'id, name', 'is_active = 1')
                                 : $conn->query("SELECT id, name FROM ticket_origins WHERE is_active = 1 AND tenant_id IS NULL ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC));
        $departments = array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name']],
            $conn->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC));
        $companies = ($multi && $isDefaultCtx) ? array_map(fn($c) => ['id' => (int)$c['id'], 'name' => $c['name']], getAllTenants($conn, true)) : [];
        $types = [];
        foreach ($usable as $id => $name) $types[] = ['id' => $id, 'name' => $name];
        // A shared rule may name a type only some companies use; show its name anyway.
        $typeNames = $usable + array_column($conn->query("SELECT id, name FROM ticket_types")->fetchAll(PDO::FETCH_ASSOC), 'name', 'id');

        echo json_encode([
            'success'   => true,
            'scope'     => ['shared' => $scopeTenant === null, 'multi' => $multi,
                            'company' => $scopeTenant !== null ? (getTenantById($conn, $scopeTenant)['name'] ?? '') : null],
            'rules'     => array_map($shape, $mine),
            'shared'    => array_map($shape, $shared),
            'default'   => ['install' => $installDefault ?: null, 'company' => $companyOwn],
            'type_names'=> (object)array_map('strval', $typeNames),
            'options'   => ['types' => $types, 'channels' => TICKET_TYPE_CHANNELS, 'mailboxes' => $mailboxes, 'origins' => $origins,
                            'departments' => $departments, 'companies' => $companies],
        ]);
        exit;
    }

    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    switch ((string)($in['action'] ?? '')) {
        case 'save':
            $id = (int)($in['id'] ?? 0);
            $name = trim((string)($in['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100) ttrFail(t('tickets.type_rules.name_needed'));
            $typeId = (int)($in['ticket_type_id'] ?? 0);
            if (!isset($usable[$typeId])) ttrFail(t('tickets.type_rules.type_needed'));
            $c = ticketTypeRuleConditions($in['conditions'] ?? []);
            if (!($multi && $isDefaultCtx)) $c['companies'] = [];   // only a shared rule can pick companies
            if (!ticketTypeRuleHasCriteria($c)) ttrFail(t('tickets.type_rules.criteria_needed'));
            $active = !empty($in['is_active']) ? 1 : 0;
            if ($id) {
                $own($id);
                $conn->prepare("UPDATE ticket_type_rules SET name = ?, ticket_type_id = ?, conditions_json = ?, is_active = ?, updated_datetime = UTC_TIMESTAMP() WHERE id = ?")
                     ->execute([$name, $typeId, json_encode($c), $active, $id]);
            } else {
                $s = $conn->prepare("SELECT COALESCE(MAX(display_order), 0) FROM ticket_type_rules WHERE tenant_id <=> ?");
                $s->execute([$scopeTenant]);
                $conn->prepare("INSERT INTO ticket_type_rules (tenant_id, name, ticket_type_id, conditions_json, display_order, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                     ->execute([$scopeTenant, $name, $typeId, json_encode($c), (int)$s->fetchColumn() + 10, $active, $analystId]);
                $id = (int)$conn->lastInsertId();
            }
            echo json_encode(['success' => true, 'id' => $id]);
            exit;

        case 'delete':
            $r = $own((int)($in['id'] ?? 0));
            $conn->prepare("DELETE FROM ticket_type_rules WHERE id = ?")->execute([(int)$r['id']]);
            echo json_encode(['success' => true]);
            exit;

        case 'move':
            // Swap with the neighbour, after renumbering 10, 20, 30... so equal
            // orders (or gaps) can never make a move do nothing.
            $r = $own((int)($in['id'] ?? 0));
            $s = $conn->prepare("SELECT id FROM ticket_type_rules WHERE tenant_id <=> ? ORDER BY display_order, id");
            $s->execute([$scopeTenant]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            $i = array_search((int)$r['id'], $ids, true);
            $j = $i + ((int)($in['dir'] ?? 0) < 0 ? -1 : 1);
            if ($j >= 0 && $j < count($ids)) { [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]]; }
            $u = $conn->prepare("UPDATE ticket_type_rules SET display_order = ? WHERE id = ?");
            foreach ($ids as $k => $rid) $u->execute([($k + 1) * 10, $rid]);
            echo json_encode(['success' => true]);
            exit;

        case 'set_default':
            $v = (string)($in['value'] ?? '');
            if ($v !== '' && $v !== 'inherit' && !isset($usable[(int)$v])) ttrFail(t('tickets.type_rules.type_needed'));
            if ($scopeTenant === null) {
                $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
                     ->execute([TICKET_TYPE_DEFAULT_KEY, $v === 'inherit' ? '' : $v]);
            } else {
                // 'inherit' removes the company's own answer; '' is "none for this company".
                setTenantSetting($conn, $scopeTenant, TICKET_TYPE_DEFAULT_KEY, $v === 'inherit' ? null : ($v === '' ? '0' : $v));
            }
            tenantSettingForget();
            echo json_encode(['success' => true]);
            exit;

        case 'test':
            $f = (array)($in['facts'] ?? []);
            $tenant = $scopeTenant ?? (!empty($f['company_id']) ? (int)$f['company_id'] : $defaultId);
            $pick = ticketTypeAutoPick($conn, $tenant, [
                'channel' => (string)($f['channel'] ?? ''),
                'mailbox_id' => !empty($f['mailbox_id']) ? (int)$f['mailbox_id'] : null,
                'origin_id' => !empty($f['origin_id']) ? (int)$f['origin_id'] : null,
                'department_id' => !empty($f['department_id']) ? (int)$f['department_id'] : null,
                'subject' => (string)($f['subject'] ?? ''), 'requester_email' => (string)($f['requester_email'] ?? ''),
            ]);
            echo json_encode(['success' => true, 'pick' => $pick]);
            exit;
    }
    ttrFail('Unknown action.');
} catch (Throwable $e) {
    error_log('ticket_type_rules: ' . $e->getMessage());
    ttrFail(t('tickets.type_rules.error'));
}
