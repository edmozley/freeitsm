<?php
/**
 * API: System → Integrations → Microsoft Teams (channel alerts). Administrators only.
 *
 *   GET  ?action=get            the channels (never their addresses), the alerts
 *                               a channel can tick, priorities, teams, and how many
 *                               Teams BOT channels exist (the page links to them)
 *   POST {action:'save', ...}   one channel - see msteamsSaveChannel(); its
 *                               workflows are made, updated or removed to match
 *   POST {action:'delete', id}  a channel and the workflows it made
 *   POST {action:'test', id}    a test card to that channel, through the same
 *                               request builder and transport a real alert uses
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/admin_api_guard.php';   // administrators only
require_once '../../includes/integrations/msteams.php';
require_once '../../includes/ticket_priorities.php';

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = (string)($in['action'] ?? $_GET['action'] ?? 'get');

    if (in_array($action, ['save', 'delete', 'test'], true)) {
        csrfRequireWriteMethod();
        if (!msteamsReady($conn)) throw new InvalidArgumentException('Run Database Verification first.');
    }
    if ($action === 'save') {
        echo json_encode(['success' => true] + msteamsSaveChannel($conn, (int)$_SESSION['analyst_id'], $in));
        exit;
    }
    if ($action === 'delete') {
        msteamsDeleteChannel($conn, (int)($in['id'] ?? 0));
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action === 'test') {
        $who = (string)($conn->query("SELECT full_name FROM analysts WHERE id = " . (int)$_SESSION['analyst_id'])->fetchColumn() ?: 'an administrator');
        echo json_encode(['success' => true] + msteamsSendTest($conn, (int)($in['id'] ?? 0), $who));
        exit;
    }
    if ($action !== 'get') {
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    $ready = msteamsReady($conn);
    $bots = 0;
    try { $bots = (int)$conn->query("SELECT COUNT(*) FROM messaging_channels WHERE provider = 'teams'")->fetchColumn(); } catch (Exception $e) {}
    echo json_encode([
        'success'    => true,
        'ready'      => $ready,
        'channels'   => $ready ? msteamsChannels($conn) : [],
        'alerts'     => array_map(fn($k, $a) => ['key' => $k, 'needs' => $a['needs']], array_keys(MSTEAMS_ALERTS), MSTEAMS_ALERTS),
        // Shared priorities only: a channel's threshold is install-wide, so a company's own priority is no choice here (#320).
        'priorities' => $conn->query("SELECT id, name FROM ticket_priorities WHERE is_active = 1 AND " . ticketPriorityTenantCol($conn) . " IS NULL ORDER BY display_order DESC, id")->fetchAll(PDO::FETCH_ASSOC),
        'teams'      => $conn->query("SELECT id, name FROM teams WHERE COALESCE(is_active, 1) = 1 ORDER BY name")->fetchAll(PDO::FETCH_ASSOC),
        'bots'       => $bots,
    ]);
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Exception $e) {
    error_log('msteams_settings: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not load or save the Teams settings.']);
}
