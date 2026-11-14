<?php
/**
 * API: System → Integrations → Zabbix. Administrators only.
 *
 *   GET  ?action=get     settings, the lookups the form needs, the endpoint address,
 *                        the token, and the last 25 events received
 *   POST {action:'save', ...}    the settings - see zabbixSaveSettings()
 *   POST {action:'rotate'}       a new token; the old one stops working at once
 *
 * The token is returned in full because the administrator has to paste it into
 * Zabbix. It is encrypted at rest (ENCRYPTED_SETTING_KEYS).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_api_guard.php';   // administrators only
require_once '../../includes/public_url.php';
require_once '../../includes/integrations/zabbix.php';

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? $_GET['action'] ?? 'get');

    if ($action === 'save') {
        csrfRequireWriteMethod();
        zabbixSaveSettings($conn, $in);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'rotate') {
        csrfRequireWriteMethod();
        echo json_encode(['success' => true, 'token' => zabbixRotateToken($conn)]);
        exit;
    }
    if ($action === 'export') {
        // The media type as a Zabbix import file. A download, not a change: it
        // carries the token, which only an administrator reaches this far to see.
        $token = zabbixToken($conn);
        if ($token === '') throw new InvalidArgumentException('Make a token first.');
        $endpoint = trim((string)($_GET['endpoint'] ?? '')) ?: publicAbsoluteUrl($conn, 'api/external/zabbix/alert.php');
        if (!preg_match('#^https?://\S+$#i', $endpoint)) throw new InvalidArgumentException('The address must start with http:// or https://');
        header('Content-Type: application/x-yaml; charset=utf-8');
        header('Content-Disposition: attachment; filename="freeitsm_zabbix_media_type.yaml"');
        header('Cache-Control: no-store');
        echo zabbixMediaTypeYaml($endpoint, $token);
        exit;
    }
    if ($action === 'test') {
        // "Try it": a test problem (then its recovery) through the SAME handler a
        // real alert takes - so it proves the settings and the incident they make,
        // not a pretend path. The network half (can Zabbix reach us?) is only
        // provable from Zabbix, and the page says so.
        csrfRequireWriteMethod();
        if (!zabbixReady($conn)) throw new InvalidArgumentException('Run Database Verification first.');
        $step = (string)($in['step'] ?? 'problem');
        if ($step === 'recovery') {
            // Only a test problem this page made can be recovered from here.
            $pid = (string)($in['problem_id'] ?? '');
            if (!preg_match('/^freeitsm-test-[0-9a-f]{8}$/', $pid)) throw new InvalidArgumentException('That is not a test problem.');
        } else {
            $pid = 'freeitsm-test-' . bin2hex(random_bytes(4));
        }
        $r = zabbixHandleEvent($conn, [
            'problem_id'  => $pid,
            'event_value' => $step === 'recovery' ? '0' : '1',
            'severity'    => 4,
            'host'        => 'freeitsm-test',
            'trigger'     => mb_substr(trim((string)($in['trigger'] ?? '')) ?: 'Test alert from FreeITSM', 0, 200),
            'message'     => mb_substr(trim((string)($in['message'] ?? '')), 0, 500),
        ]);
        $number = null;
        if ($r['ticket_id']) {
            $n = $conn->prepare("SELECT ticket_number FROM tickets WHERE id = ?");
            $n->execute([$r['ticket_id']]);
            $number = $n->fetchColumn() ?: null;
        }
        echo json_encode(['success' => $r['ok'], 'action' => $r['action'], 'ticket_id' => $r['ticket_id'],
                          'ticket_number' => $number, 'problem_id' => $pid] + (isset($r['error']) ? ['error' => $r['error']] : []));
        exit;
    }
    if ($action !== 'get') {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $events = [];
    if (zabbixReady($conn)) {
        $events = $conn->query("SELECT e.id, e.problem_id, e.event_value, e.host, e.trigger_name, e.severity, e.ticket_id,
                                       e.action, e.detail, e.received_datetime, t.ticket_number
                                  FROM zabbix_events e LEFT JOIN tickets t ON t.id = e.ticket_id
                                 ORDER BY e.id DESC LIMIT 25")->fetchAll(PDO::FETCH_ASSOC);
    }
    $multi = isMultiTenant($conn);
    echo json_encode([
        'success'    => true,
        'ready'      => zabbixReady($conn),
        'settings'   => zabbixSettings($conn),
        'token'      => zabbixToken($conn),
        'endpoint'   => publicAbsoluteUrl($conn, 'api/external/zabbix/alert.php'),
        'severities' => ZABBIX_SEVERITIES,
        // The media type's parameters, from the one list the import file uses too.
        // The endpoint value is filled in by the page from its (editable) address field.
        'parameters' => zabbixMediaTypeParameters('', zabbixToken($conn)),
        // Shared priorities only: the map is install-wide, so a company's own priority is no choice here (#320).
        'priorities' => array_map(fn(array $p): array => ['id' => $p['id'], 'name' => $p['name']],
            array_values(array_filter(ticketPrioritiesShared($conn), fn(array $p): bool => $p['is_active']))),
        'statuses'   => $conn->query("SELECT id, name, is_closed FROM ticket_statuses WHERE is_active = 1 ORDER BY display_order, id")->fetchAll(PDO::FETCH_ASSOC),
        'teams'      => $conn->query("SELECT id, name FROM teams WHERE COALESCE(is_active, 1) = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'origins'    => $conn->query("SELECT id, name FROM ticket_origins WHERE COALESCE(is_active, 1) = 1 ORDER BY display_order, name")->fetchAll(PDO::FETCH_ASSOC),
        'companies'  => $multi ? $conn->query("SELECT id, name FROM tenants ORDER BY name")->fetchAll(PDO::FETCH_ASSOC) : [],
        'events'     => $events,
    ]);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('zabbix_settings: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load or save the Zabbix settings.']);
}
