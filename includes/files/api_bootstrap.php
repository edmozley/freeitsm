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

function filesApiOk(array $data = []): void
{
    echo json_encode(['success' => true] + $data);
    exit;
}

function filesApiFail(string $message, int $status = 200, array $extra = []): void
{
    if ($status !== 200) http_response_code($status);
    echo json_encode(['success' => false, 'error' => $message] + $extra);
    exit;
}

function filesHasCap(PDO $conn, int $analystId, string $cap): bool
{
    try { return analystHasCapability($conn, $analystId, $cap); } catch (Throwable $e) { return false; }
}

/**
 * Refuse unless the caller holds $need on the folder. Below View the folder does
 * not exist for them, so the answer is "not found" - the same words a deleted or
 * made-up id gets. A refusal at a level they CAN see is audited as 'denied'.
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

/** A live file row, or null. */
function filesItem(PDO $conn, int $itemId): ?array
{
    $st = $conn->prepare("SELECT * FROM files_items WHERE id = ? AND deleted_datetime IS NULL");
    $st->execute([$itemId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    // A file in a deleted folder is gone too.
    if (!isset(FilesAcl::tree($conn)[(int)$r['folder_id']])) return null;
    return $r;
}

/** A file the caller holds $need on, or "not found". */
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
 * Is a name already used by a live folder or file in this folder? (case-insensitive)
 *
 * ⚠️ Only folders the CALLER can see count. Otherwise "that name is taken" would
 * confirm a hidden folder exists - at the top level, or a subfolder that stopped
 * inheriting. Two people may therefore end up with same-named folders they cannot
 * see each other's of; that is the price of not leaking, and it is harmless.
 */
function filesNameTaken(PDO $conn, ?int $parentId, string $name, string $exceptType = '', int $exceptId = 0): bool
{
    $visible = FilesAcl::levels($conn, (int)($GLOBALS['analystId'] ?? 0));
    $st = $conn->prepare("SELECT id FROM files_folders WHERE " . ($parentId === null ? "parent_id IS NULL" : "parent_id = ?") .
                         " AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
    $st->execute($parentId === null ? [$name] : [$parentId, $name]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
        if (!isset($visible[(int)$id])) continue;
        if (!($exceptType === 'folder' && (int)$id === $exceptId)) return true;
    }
    if ($parentId !== null) {
        $st = $conn->prepare("SELECT id FROM files_items WHERE folder_id = ? AND deleted_datetime IS NULL AND LOWER(name) = LOWER(?)");
        $st->execute([$parentId, $name]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!($exceptType === 'item' && (int)$id === $exceptId)) return true;
        }
    }
    return false;
}

/** "Report.pdf" -> "Report (2).pdf", the first free one. */
function filesFreeName(PDO $conn, ?int $parentId, string $name): string
{
    if (!filesNameTaken($conn, $parentId, $name)) return $name;
    $ext  = pathinfo($name, PATHINFO_EXTENSION);
    $base = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
    for ($i = 2; $i < 1000; $i++) {
        $try = $base . " ($i)" . ($ext !== '' ? ".$ext" : '');
        if (!filesNameTaken($conn, $parentId, $try)) return $try;
    }
    return $base . ' (' . bin2hex(random_bytes(3)) . ')' . ($ext !== '' ? ".$ext" : '');
}

/** Run an endpoint body and turn any failure into the UI's {success:false} shape. */
function filesApiRun(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log('files api: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        filesApiFail('Something went wrong: ' . $e->getMessage());
    }
}
