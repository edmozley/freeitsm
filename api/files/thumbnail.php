<?php
/**
 * Files thumbnails (GH #424) - see includes/files/thumbnails.php.
 *
 * GET  ?item=<id>[&s=<sha256>]   the file's thumbnail (a small WebP), or 404 - the
 *                                caller shows the type icon. A picture with none yet
 *                                is made now, once. `s` is only there so the browser
 *                                caches each version's thumbnail separately.
 * POST {action:'put', item, data}  a PDF's first page drawn by the browser (a data:
 *                                URL). Needs Upload on the folder; re-encoded here.
 * POST {action:'make', item}     "Make thumbnail" on a file's right-click menu:
 *                                a picture is made again by the server (View is
 *                                enough); a PDF answers {draw: true} and the browser
 *                                draws it and sends it with 'put' (Upload needed).
 *
 * Seeing a thumbnail needs View on the folder, exactly as opening the file does.
 * Showing thumbnails is NOT recorded in the audit trail as opening a file - a folder
 * of 100 pictures would bury the real opens; making one is recorded, once.
 *
 * TRAP: a watermarked folder serves no thumbnail - a clean picture of the page would
 *   get round the watermark.
 */
$isGet = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
if ($isGet) define('FILES_RAW_OUTPUT', true);
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';
require_once __DIR__ . '/../../includes/files/thumbnails.php';

/** The live item, its current version and the caller's level - or null when they may not see it. */
$load = function (int $itemId) use ($conn, $analystId): ?array {
    $it = filesItem($conn, $itemId);
    if (!$it) return null;
    $lvl = FilesAcl::level($conn, $analystId, (int)$it['folder_id']);
    if ($lvl < FilesAcl::VIEW) return null;
    $st = $conn->prepare('SELECT * FROM files_versions WHERE id = ? AND item_id = ?');
    $st->execute([(int)$it['current_version_id'], $itemId]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v || empty($v['sha256'])) return null;
    return ['item' => $it, 'version' => $v, 'level' => $lvl];
};

if ($isGet && !empty($_GET['source'])) {
    // ?item=<id>&source=1 - a PDF's bytes, for a browser to draw its first page. Not the
    // viewer's view.php, which records every read as the file being OPENED: drawing 100
    // thumbnails would bury the real opens. Only for someone who may send the thumbnail
    // (Upload on the folder), only while it has none, and recorded once as 'thumbnail'.
    $refuse = function (int $code, string $msg) { http_response_code($code); header('Content-Type: text/plain; charset=utf-8'); echo $msg; exit; };
    $x = $load((int)($_GET['item'] ?? 0));
    if (!$x) $refuse(404, 'File not found.');
    if ($x['level'] < FilesAcl::UPLOAD || FilesThumbs::kind($x['item']['name']) !== 'pdf'
        || FilesAcl::watermark($conn, (int)$x['item']['folder_id'])) $refuse(403, 'Not available.');
    if ((int)$x['version']['size_bytes'] > FilesThumbs::PDF_SOURCE_MAX_BYTES) $refuse(413, 'Too large to draw.');
    $row = FilesThumbs::row($conn, $x['version']['sha256']);
    if ($row && $row['status'] === 'ready' && empty($_GET['redraw'])) $refuse(409, 'Already has a thumbnail.');
    $size = (int)$x['version']['size_bytes'];
    $code = filesServeBytes($conn, $x['version'], 0, max(0, $size - 1), function () use ($size) {
        header('Content-Type: application/pdf');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
    });
    if ($code) $refuse($code, 'The file could not be read.');
    exit;
}

if ($isGet) {
    $none = function () { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'No thumbnail.'; exit; };
    try {
        if (!FilesThumbs::ready($conn) || !FilesThumbs::enabled($conn)) $none();
        $x = $load((int)($_GET['item'] ?? 0));
        if (!$x || FilesAcl::watermark($conn, (int)$x['item']['folder_id'])) $none();
        $sha = $x['version']['sha256'];
        $row = FilesThumbs::row($conn, $sha);
        if (!$row && FilesThumbs::kind($x['item']['name']) === 'image' && FilesThumbs::gd()) {
            // Made once, now - every later request (and every copy) gets the stored one.
            @set_time_limit(60);
            FilesThumbs::makeForVersion($conn, $x['version']);
            $row = FilesThumbs::row($conn, $sha);
        }
        if (!$row || $row['status'] !== 'ready' || $row['data'] === null) $none();
        FilesThumbs::serve($row);
    } catch (Throwable $e) {
        error_log('files thumbnail: ' . $e->getMessage());
        $none();
    }
}

filesApiRun(function () use ($conn, $analystId, $load) {
    if (!FilesThumbs::ready($conn)) filesApiFail('Run Database Verification first (System -> Database Verification).');
    if (!FilesThumbs::gd()) filesApiFail('This server cannot make thumbnails: PHP\'s GD extension is not installed.');
    $in = filesApiBody();
    $action = (string)($in['action'] ?? '');
    $x = $load((int)($in['item'] ?? 0));
    if (!$x) filesApiFail('File not found.');
    $it = $x['item'];
    $folderId = (int)$it['folder_id'];
    $kind = FilesThumbs::kind($it['name']);
    if ($kind === null) filesApiFail('This kind of file has no thumbnail.');
    if (FilesAcl::watermark($conn, $folderId)) filesApiFail('Files in a folder with a watermark do not show thumbnails.');
    $t = ['folder_id' => $folderId, 'item_id' => (int)$it['id'], 'version_id' => (int)$x['version']['id'], 'item_name' => $it['name']];

    if ($action === 'make' && $kind === 'image') {
        $status = FilesThumbs::makeForVersion($conn, $x['version']);
        if ($status === 'unavailable') filesApiFail(filesStoreUnavailableMessage());
        filesAudit($conn, $analystId, 'thumbnail', $t, ['made_by' => 'server', 'status' => $status]);
        filesApiOk(['status' => $status]);
    }
    if ($action === 'make') {
        // A PDF: only a browser can draw it, and only someone who could upload here may send one.
        filesNeed($conn, $analystId, $folderId, FilesAcl::UPLOAD, 'thumbnail');
        filesApiOk(['draw' => true]);
    }
    if ($action === 'put') {
        if ($kind !== 'pdf') filesApiFail('Pictures are made by the server.');
        filesNeed($conn, $analystId, $folderId, FilesAcl::UPLOAD, 'thumbnail');
        $data = (string)($in['data'] ?? '');
        if (!preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$~', $data, $m)) filesApiFail('The thumbnail is not a picture.');
        try {
            $r = FilesThumbs::fromBrowser((string)base64_decode($m[2], true));
        } catch (RuntimeException $e) {
            filesApiFail($e->getMessage());
        }
        FilesThumbs::save($conn, $x['version']['sha256'], $r, 'browser', $analystId);
        filesAudit($conn, $analystId, 'thumbnail', $t, ['made_by' => 'browser']);
        filesApiOk(['status' => 'ready']);
    }
    filesApiFail('Unknown action.');
});
