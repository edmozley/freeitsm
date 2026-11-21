<?php
/**
 * War room lists (5.9.0) - wiki War-Room-Message-Actions-Design.
 *
 *   GET ?list=pins&channel_id=N   the channel's pinned messages (for anyone who can read it)
 *   GET ?list=saved               the caller's own saved messages, from conversations they can still read
 *
 * Read-only. Every row is checked through includes/warroom.php's access rules.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/warroom.php';

header('Content-Type: application/json');

requireModuleAccessJson('war-room');

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    I18n::initFromSession();

    $list = (string) ($_GET['list'] ?? '');
    if ($list === 'pins') {
        $channelId = (int) ($_GET['channel_id'] ?? 0);
        if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'No access to that channel']);
            exit;
        }
        echo json_encode(['success' => true, 'items' => warRoomPins($conn, $channelId)]);
    } elseif ($list === 'seen') {
        // 5.9.0: who has read a message, and when.
        $seen = warRoomSeenBy($conn, $analystId, (int) ($_GET['id'] ?? 0));
        echo json_encode($seen === null ? ['success' => false, 'error' => 'not_available'] : ['success' => true] + $seen);
    } elseif ($list === 'saved') {
        echo json_encode(['success' => true, 'items' => warRoomSavedList($conn, $analystId)]);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown list']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load the list']);
}
