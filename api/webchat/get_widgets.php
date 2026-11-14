<?php
/**
 * API Endpoint: list website chat widgets for the settings page.
 *
 * Each widget is a messaging_channels row (channel_type='webchat') plus its
 * webchat_widgets config. The widget_key is public, so it's safe to return; the
 * embed snippet is built ready to copy-paste.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/webchat/webchat.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/ticket_priorities.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

// Web chat settings tab.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_WEBCHAT);

try {
    $conn = connectToDatabase();

    $rows = $conn->query(
        "SELECT w.id, w.channel_id, w.widget_key, w.allowed_origins, w.greeting,
                w.accent_colour, w.launcher_text, w.offline_message, w.require_email,
                w.business_calendar_id, w.email_when_away, w.ai_enabled, w.ai_mode,
                w.ai_offer_agent, w.ai_offer_email,
                w.created_datetime, c.name, c.tenant_id, c.is_active
         FROM webchat_widgets w
         JOIN messaging_channels c ON c.id = w.channel_id
         WHERE c.channel_type = 'webchat'
         ORDER BY c.name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Business-hours calendars offered in the widget's Availability dropdown: the shared ones and
    // those of companies this analyst can reach, each with its company so the dropdown can follow the widget's.
    $calendars = [];
    try {
        $accessible = isMultiTenant($conn) ? array_map('intval', getAccessibleTenantIds($conn, (int)$_SESSION['analyst_id'])) : null;
        // ticketPriorityTenantCol(): NULL (every calendar shared) before Database Verification adds the column.
        $calendars = $conn->query(
            "SELECT id, name, " . ticketPriorityTenantCol($conn) . " AS tenant_id FROM sla_calendars WHERE is_active = 1 ORDER BY is_default DESC, name ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $calendars = array_values(array_filter($calendars, fn(array $c): bool =>
            $accessible === null || $c['tenant_id'] === null || in_array((int) $c['tenant_id'], $accessible, true)));
        $calendars = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'name' => $c['name'], 'tenant_id' => $c['tenant_id'] !== null ? (int) $c['tenant_id'] : null];
        }, $calendars);
    } catch (Exception $e) { /* table missing → no calendars offered */ }

    $widgets = array_map(function ($r) use ($conn) {
        return [
            'id'              => (int) $r['id'],
            'channel_id'      => (int) $r['channel_id'],
            'name'            => $r['name'],
            'widget_key'      => $r['widget_key'],
            'allowed_origins' => (string) ($r['allowed_origins'] ?? ''),
            'greeting'        => (string) ($r['greeting'] ?? ''),
            'accent_colour'   => (string) ($r['accent_colour'] ?? ''),
            'launcher_text'   => (string) ($r['launcher_text'] ?? ''),
            'offline_message' => (string) ($r['offline_message'] ?? ''),
            'require_email'   => (bool) $r['require_email'],
            'business_calendar_id' => $r['business_calendar_id'] !== null ? (int) $r['business_calendar_id'] : null,
            'email_when_away' => (bool) $r['email_when_away'],
            'ai_enabled'      => (bool) $r['ai_enabled'],
            'ai_mode'         => $r['ai_mode'] ?: 'assist',
            'ai_offer_agent'  => (bool) $r['ai_offer_agent'],
            'ai_offer_email'  => (bool) $r['ai_offer_email'],
            'tenant_id'       => $r['tenant_id'] !== null ? (int) $r['tenant_id'] : null,
            'is_active'       => (bool) $r['is_active'],
            'created_datetime'=> $r['created_datetime'],
            'embed_snippet'   => webchatEmbedSnippet($conn, $r['widget_key']),
        ];
    }, $rows);

    echo json_encode(['success' => true, 'widgets' => $widgets, 'calendars' => $calendars]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
