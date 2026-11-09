<?php
/**
 * Files — the start of every api/files/*.php endpoint.
 *
 * Signed in, allowed into the module, JSON out, a connection. Kept here rather
 * than repeated in every endpoint so a guard cannot be forgotten on the next one.
 * Folder permissions are checked by each endpoint, after this, with
 * filesNeed() - which answers "not found" for anything the caller cannot see.
 *
 * Lives in includes/ so it cannot be requested on its own.
 */

session_start(['read_and_close' => true]);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../rbac.php';
require_once __DIR__ . '/acl.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/names.php';

if (!defined('FILES_RAW_OUTPUT')) header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireModuleAccessJson('files');

$conn      = connectToDatabase();
$analystId = (int)$_SESSION['analyst_id'];

/** The decoded JSON body (never null). */
function filesApiBody(): array
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    }
    return $b;
}

/**
 * Sends a success JSON reply ({success: true} plus $data) and ends the request.
 *
 * @param array $data  extra keys merged into the reply beside success
 */
function filesApiOk(array $data = []): never
{
    echo json_encode(['success' => true] + $data);
    exit;
}

/**
 * Sends a failure JSON reply ({success: false, error}) with an optional HTTP status and
 * extra keys, and ends the request.
 *
 * @param string $message  the error text shown to the user
 * @param int $status      the HTTP status; 200 (the default) leaves it unchanged
 * @param array $extra     extra keys merged into the reply beside success and error
 */
function filesApiFail(string $message, int $status = 200, array $extra = []): never
{
    if ($status !== 200) http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message] + $extra);
    exit;
}

/**
 * True when the analyst holds the capability; a failed lookup counts as no.
 *
 * @param Cap $cap  the capability, such as Cap::FILES_FOLDERS
 */
function filesHasCap(PDO $conn, int $analystId, Cap $cap): bool
{
    try { return analystHasCapability($conn, $analystId, $cap); } catch (Throwable) { return false; }
}

/**
 * Refuse unless the caller holds $need on the folder. Below View the folder does
 * not exist for them, so the answer is "not found" - the same words a deleted or
 * made-up id gets. A refusal at a level they CAN see is audited as 'denied'.
 *
 * @param int $folderId  the files_folders id being acted on
 * @param int $need      the lowest level the action needs, one of FilesAcl::VIEW to FilesAcl::FULL
 * @param string $what   the action being attempted ('rename', 'upload' ...), recorded as 'doing' in the audit row
 */
function filesNeed(PDO $conn, int $analystId, int $folderId, int $need, string $what = ''): int
{
    $lvl = FilesAcl::level($conn, $analystId, $folderId);
    if ($lvl < FilesAcl::VIEW) filesApiFail('Folder not found.');
    if ($lvl < $need) {
        filesAudit($conn, $analystId, 'denied', ['folder_id' => $folderId],
                   ['wanted' => FilesAcl::levelKey($need), 'held' => FilesAcl::levelKey($lvl), 'doing' => $what]);
        filesApiFail('You do not have permission to do that here.');
    }
    return $lvl;
}

/**
 * A file the caller holds $need on, or "not found".
 *
 * @param int $itemId  the files_items id being acted on
 * @param int $need    the lowest level the action needs on the file's folder, FilesAcl::VIEW to FilesAcl::FULL
 * @param string $what the action being attempted, recorded as 'doing' in any 'denied' audit row
 */
function filesNeedItem(PDO $conn, int $analystId, int $itemId, int $need, string $what = ''): array
{
    $it = filesItem($conn, $itemId);
    if (!$it || FilesAcl::level($conn, $analystId, (int)$it['folder_id']) < FilesAcl::VIEW) {
        filesApiFail('File not found.');
    }
    filesNeed($conn, $analystId, (int)$it['folder_id'], $need, $what);
    return $it;
}

/**
 * Run an endpoint body and turn any failure into the UI's {success:false} shape.
 *
 * @param callable $fn  the endpoint's work, called with no arguments; it replies itself via filesApiOk()
 */
function filesApiRun(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('files api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        filesApiFail('Something went wrong: ' . $e->getMessage());
    }
}
