<?php
/**
 * API Endpoint: Upload file attachment to a change
 *
 * ⚠️ SECURITY-CRITICAL, and it was wrong. This endpoint used to accept any file
 * with no validation, keep the caller's own filename, and write it into
 * change-management/attachments/ — a folder Apache serves. Uploading `x.php`
 * and then requesting it over HTTP executed it. Verified, not theorised.
 *
 * Two rules now, and both matter:
 *
 *   1. every byte goes through uploadStoreFile(), which whitelists extension AND
 *      content type, invents its own filename, and drops execution protection
 *      into the directory;
 *   2. the ACCESS CHECK RUNS FIRST. It used to run after move_uploaded_file(),
 *      so an analyst with no rights to a change could still land a file on the
 *      server — the check only stopped the database row, not the write.
 *
 * Downloads already go through get_attachment.php, which authorises. Nothing
 * here is ever meant to be fetched by direct URL.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/tenancy.php';
require_once '../../includes/uploads.php';
require_once '../../includes/services/changes.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('changes');
requireModuleWriteJson('changes');   // Read only access (#180) changes nothing here

$analystId = (int)$_SESSION['analyst_id'];
$changeId  = (int)($_POST['change_id'] ?? 0);

if (!$changeId) {
    echo json_encode(['success' => false, 'error' => 'Change ID required']);
    exit;
}

try {
    $conn = connectToDatabase();

    // ⚠️ BEFORE the file is written, not after. Same wording as a missing change
    // so this cannot be used to discover which changes exist.
    if (!analystCanAccessChange($conn, $analystId, $changeId)) {
        echo json_encode(['success' => false, 'error' => 'Change not found']);
        exit;
    }

    if (!isset($_FILES['file'])) {
        echo json_encode(['success' => false, 'error' => 'No file was chosen.']);
        exit;
    }

    $result = ChangesService::uploadAttachment($conn, ActorContext::fromSession($conn), $changeId, $_FILES['file']);
    $attachmentId = $result['id'];

    echo json_encode([
        'success'       => true,
        'attachment_id' => $attachmentId,
        'message'       => 'File uploaded successfully',
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
