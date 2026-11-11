<?php
/**
 * API: Save CSAT settings
 * Validates the four user-facing CSAT keys and writes them to system_settings.
 * The HMAC secret can only be changed via db_verify (intentionally — rotating
 * it would invalidate every outstanding survey link, which should be a
 * deliberate operator action, not a settings-page click).
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';

// Changes data: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_CSAT);   // settings tab — see docs/design/rbac.md

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$mode  = $data['mode']  ?? 'off';
$scale = $data['scale'] ?? 'stars';
$delay = (int)($data['delay_minutes'] ?? 0);
$opt   = ($data['one_per_ticket'] ?? '1') === '0' ? '0' : '1';
$inCh  = ($data['in_channel'] ?? '0') === '1' ? '1' : '0';
// GH #301: one optional reminder for an unanswered survey, after this many hours.
$rem   = ($data['reminder'] ?? '0') === '1' ? '1' : '0';
$remH  = (int)($data['reminder_hours'] ?? 48);

if (!in_array($mode, ['off','auto','manual'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid mode']);
    exit;
}
if (!in_array($scale, ['stars','emojis'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid scale']);
    exit;
}
if ($remH < 1 || $remH > 336) {
    echo json_encode(['success' => false, 'error' => 'The reminder must be 1-336 hours (two weeks) after the survey']);
    exit;
}
if ($delay < 0 || $delay > 10080) {
    echo json_encode(['success' => false, 'error' => 'Delay must be 0–1 week (10080 minutes)']);
    exit;
}

try {
    $conn = connectToDatabase();
    // Switching the reminder ON (it was off): remember when, so only surveys whose reminder
    // falls due from now on are reminded - the help promises that, and an admin ticking the
    // box must not send a burst to everybody surveyed in the last two days.
    $wasOn = (string)$conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'csat_reminder'")->fetchColumn() === '1';
    $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_datetime = CURRENT_TIMESTAMP");
    $stmt->execute(['csat_mode', $mode]);
    $stmt->execute(['csat_scale', $scale]);
    $stmt->execute(['csat_delay_minutes', (string)$delay]);
    $stmt->execute(['csat_one_per_ticket', $opt]);
    $stmt->execute(['csat_in_channel', $inCh]);
    $stmt->execute(['csat_reminder', $rem]);
    $stmt->execute(['csat_reminder_hours', (string)$remH]);
    if ($rem === '1' && !$wasOn) {
        $stmt->execute(['csat_reminder_since', gmdate('Y-m-d H:i:s')]);
    }

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
