<?php
/**
 * API: War room — edit or delete one message.
 *
 * POST JSON { action: 'edit'|'delete', id: <int>, body?: "<text>" }
 *
 * WHO MAY DO WHAT, AND WHY THEY DIFFER:
 *
 *   edit    the AUTHOR only. Not an administrator, not the channel's creator.
 *           Nobody should be able to put words in somebody else's mouth in the
 *           record of an incident.
 *   delete  the author, OR somebody with war_room.manage. Removal is a different
 *           act from rewriting: the case for it is a pasted password or customer
 *           data, and waiting for the author to come back from lunch is not an
 *           acceptable answer to that.
 *
 * Both are recorded rather than silent — see warRoomEditMessage / warRoomDelete-
 * Message. The content of a deleted message really is destroyed; what survives is
 * the fact that it was removed, and by whom.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/rbac.php';
require_once '../../includes/warroom.php';

header('Content-Type: application/json');

requireModuleAccessJson('war-room');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

try {
    $conn      = connectToDatabase();
    $analystId = (int) $_SESSION['analyst_id'];
    I18n::initFromSession();

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];

    $action    = (string) ($input['action'] ?? '');
    $messageId = (int) ($input['id'] ?? 0);

    if ($action === 'edit') {
        $ok = warRoomEditMessage($conn, $analystId, $messageId, (string) ($input['body'] ?? ''));
    } elseif ($action === 'react') {
        // 5.9.0: add or take away an emoji reaction.
        $ok = warRoomReact($conn, $analystId, $messageId, (string) ($input['emoji'] ?? ''), !empty($input['on']));
    } elseif ($action === 'forward') {
        // 5.9.0: to a channel, or to a person (their direct message is opened if need be).
        $to = (int) ($input['to_channel'] ?? 0);
        if ($to <= 0 && !empty($input['to_analyst'])) {
            try { $to = warRoomOpenDm($conn, $analystId, (int) $input['to_analyst']); } catch (Throwable $e) { $to = 0; }
        }
        $newId = $to > 0 ? warRoomForward($conn, $analystId, $messageId, $to, mb_substr((string) ($input['comment'] ?? ''), 0, WARROOM_MAX_BODY)) : 0;
        if ($newId > 0) { echo json_encode(['success' => true, 'id' => $newId, 'channel_id' => $to]); exit; }
        $ok = false;
    } elseif ($action === 'pin' || $action === 'unpin') {
        // 5.9.0: pinned for everyone in the conversation.
        $res = warRoomPin($conn, $analystId, $messageId, $action === 'pin');
        if ($res === 'full') {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'pins_full', 'max' => WARROOM_MAX_PINS]);
            exit;
        }
        $ok = $res === 'ok';
    } elseif ($action === 'save' || $action === 'unsave') {
        // 5.9.0: the analyst's own bookmark.
        $ok = warRoomSave($conn, $analystId, $messageId, $action === 'save');
    } elseif ($action === 'unread') {
        // 5.9.0: move the analyst's read marker back to just before this message.
        $ok = warRoomMarkUnread($conn, $analystId, $messageId);
    } elseif ($action === 'delete') {
        $mayManage = analystHasCapability($conn, $analystId, Cap::WAR_ROOM_MANAGE);
        $ok = warRoomDeleteMessage($conn, $analystId, $messageId, $mayManage);
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
        exit;
    }

    // One answer for "no such message", "not yours" and "already deleted". Telling
    // them apart would let somebody probe which message ids exist in channels they
    // cannot read.
    if (!$ok) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'not_allowed']);
        exit;
    }

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not change that message']);
}
