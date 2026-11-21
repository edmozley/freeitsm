<?php
/**
 * API: War room — post a message, with or without attachments.
 *
 * POST application/json           { channel_id: <int>, body: "<text>" }
 * POST multipart/form-data        channel_id, body, files[]
 *
 * Both shapes land here rather than in two endpoints, because a message with a
 * screenshot on it is still one message: splitting them would mean a half-sent
 * message existing whenever the second request failed.
 *
 * Returns the message id. The page does not render the response — it lets the
 * next poll bring the message back, so what you see is what everyone else sees
 * rather than an optimistic local copy that might not have saved.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
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

    $isMultipart = !empty($_FILES) || isset($_POST['channel_id']);
    if ($isMultipart) {
        $channelId = (int) ($_POST['channel_id'] ?? 0);
        $body      = (string) ($_POST['body'] ?? '');
        $quoteOf   = (int) ($_POST['quote_of_id'] ?? 0);
        $threadOf  = (int) ($_POST['thread_root_id'] ?? 0);
        $alsoIn    = !empty($_POST['also_in_channel']);
    } else {
        $input     = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) $input = [];
        $channelId = (int) ($input['channel_id'] ?? 0);
        $body      = isset($input['body']) ? (string) $input['body'] : '';
        $quoteOf   = (int) ($input['quote_of_id'] ?? 0);
        $threadOf  = (int) ($input['thread_root_id'] ?? 0);   // 5.9.0: a reply in a thread
        $alsoIn    = !empty($input['also_in_channel']);
    }

    // Same check as the read path, and it covers archived channels too: posting
    // into a channel you cannot see would otherwise be a way to talk to a team
    // you are not in.
    if (!warRoomCanPostChannel($conn, $analystId, $channelId)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No access to that channel']);
        exit;
    }

    // Normalise $_FILES['files'] into one entry per file. PHP delivers a multi
    // upload as parallel arrays rather than an array of files, which is a classic
    // place to accidentally validate only the first one.
    $files = [];
    if (isset($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $count = min(count($_FILES['files']['name']), WARROOM_MAX_ATTACHMENTS);
        for ($i = 0; $i < $count; $i++) {
            if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            $files[] = [
                'name'     => $_FILES['files']['name'][$i],
                'type'     => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error'    => $_FILES['files']['error'][$i],
                'size'     => $_FILES['files']['size'][$i],
            ];
        }
    }

    // A message with only files needs a body of some sort, since the feed renders
    // the text and hangs the files under it. Rather than invent placeholder text,
    // an empty body is allowed WHEN there are files.
    if (trim($body) === '' && !$files) {
        echo json_encode(['success' => false, 'error' => 'Empty message']);
        exit;
    }

    $id = warRoomSend($conn, $analystId, $channelId, trim($body) === '' ? ' ' : $body, $quoteOf, $threadOf, $alsoIn);
    if ($id === 0) {
        echo json_encode(['success' => false, 'error' => 'Empty message']);
        exit;
    }

    // Attachments are stored AFTER the message exists, so a file that fails
    // validation loses the file rather than the message. The rejected names are
    // reported back so the sender is told which one, and why, rather than
    // silently posting a message that is missing the screenshot they meant.
    $stored = [];
    $rejected = [];
    foreach ($files as $file) {
        try {
            $stored[] = warRoomStoreAttachment($conn, $id, $file);
        } catch (Throwable $e) {
            $rejected[] = ['name' => (string) ($file['name'] ?? '?'), 'reason' => $e->getMessage()];
        }
    }

    echo json_encode([
        'success'     => true,
        'id'          => $id,
        'attachments' => $stored,
        'rejected'    => $rejected,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Could not send']);
}
