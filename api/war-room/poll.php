<?php
/**
 * API: War room — fetch new messages, register presence, refresh the sidebar.
 *
 * GET  ?channel_id=<int>&since_id=<int>&read=<0|1>
 *   since_id = the newest id the caller already holds; 0 asks for the tail.
 *   read=1   = also mark the channel read up to the newest message returned.
 *
 * This one request does four jobs — messages, presence in, presence out, and the
 * channel list with its unread counts — because it is the request the page makes
 * every few seconds anyway. Separate endpoints would multiply the traffic during
 * exactly the incident the module exists for.
 *
 * POLLING, NOT SSE, deliberately. Apache + mod_php holds one process per open
 * connection, so thirty analysts each keeping an EventSource open would sit on
 * thirty workers indefinitely — during an incident, when the rest of the app is
 * busiest. A short poll is stateless and degrades gracefully.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/warroom.php';

header('Content-Type: application/json');

// Analysts only — the war room is deliberately not exposed to portal users.
requireModuleAccessJson('war-room');

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    I18n::initFromSession();

    $channelId = isset($_GET['channel_id']) ? (int) $_GET['channel_id'] : 0;
    $sinceId   = isset($_GET['since_id']) ? max(0, (int) $_GET['since_id']) : 0;
    // A permanent link (5.9.0): load around this message instead of the latest.
    $aroundId  = isset($_GET['around']) ? max(0, (int) $_GET['around']) : 0;
    $markRead  = !empty($_GET['read']);

    // ⚠️ Checked on every read, not just when the channel list is rendered.
    // Guessing a channel id you are not entitled to must not work.
    if (!warRoomCanAccessChannel($conn, $analystId, $channelId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No access to that channel']);
        exit;
    }

    warRoomTouchPresence($conn, $analystId, $channelId);

    // The viewer's language (5.9.0): stored translations come with the messages, so a
    // conversation already translated shows in your language straight away.
    $viewerLang = warRoomTranslateEnabled($conn) ? I18n::getLocale() : '';
    $messages = warRoomMessages($conn, $channelId, $sinceId, 200, $analystId, $sinceId > 0 ? 0 : $aroundId, $viewerLang);

    // An open thread (5.9.0) rides on the same poll - no second request every 3 seconds.
    $threadRoot  = isset($_GET['thread_root']) ? max(0, (int) $_GET['thread_root']) : 0;
    $threadSince = isset($_GET['thread_since']) ? max(0, (int) $_GET['thread_since']) : 0;
    $thread = null;
    if ($threadRoot > 0) {
        $tr = warRoomMessageRow($conn, $threadRoot);
        if ($tr !== null && (int) $tr['channel_id'] === $channelId) {
            $thread = warRoomMessages($conn, $channelId, $threadSince, 500, $analystId, 0, $viewerLang, $threadRoot);
        }
    }
    // A link to a reply opens its thread.
    $focusRoot = ($aroundId > 0 && $sinceId === 0) ? warRoomThreadRootOf($conn, $aroundId) : 0;

    // Marked read AFTER fetching, using the newest id actually returned, so a
    // message that arrives between the two is still unread rather than skipped.
    if ($markRead && $messages) {
        warRoomMarkRead($conn, $analystId, $channelId, (int) end($messages)['id']);
    }

    // Mention counts are merged onto the channel list rather than sent separately:
    // being NAMED is a different event from having missed something, and the
    // sidebar has to be able to show one without hiding the other.
    $mentions = warRoomMentionCounts($conn, $analystId);
    $channels = warRoomChannelList($conn, $analystId);
    foreach ($channels as &$c) {
        $c['mentions'] = $mentions[$c['id']] ?? 0;
    }
    unset($c);

    echo json_encode([
        'success'  => true,
        'messages' => $messages,
        'present'  => warRoomPresent($conn, $analystId, $channelId),
        'pins'     => warRoomPinCount($conn, $channelId),   // 5.9.0: the pinned bar
        'thread'   => $thread,                              // 5.9.0: the open thread's new or changed messages
        'focus_root' => $focusRoot,                         // 5.9.0: the linked message is in this thread
        'channels' => $channels,
        'me'       => $analystId,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not load messages']);
}
