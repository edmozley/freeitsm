<?php
/**
 * API: live updates (GitHub #267) - the page's one heartbeat.
 *
 *   GET ?topics=tickets,ticket:42,tasks,task:7,notifications
 *   → { success, sig: { "tickets": "<hash>", "ticket:42": "<hash>" | null, ... } }
 *
 * assets/js/live-pulse.js calls it every 15 seconds (Preferences → Live updates) while the
 * tab is visible; each part of the page repaints when its hash moves. Read-only, cheap
 * (one indexed query per value), and says nothing but hashes - see includes/live_pulse.php.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/live_pulse.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $topics = array_filter(array_map('trim', explode(',', (string)($_GET['topics'] ?? ''))));
    $conn = connectToDatabase();
    echo json_encode(['success' => true, 'sig' => (object)livePulseSignatures($conn, (int)$_SESSION['analyst_id'], $topics)]);
} catch (Throwable $e) {
    error_log('[api/system/pulse] ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Could not check for changes.']);
}
