<?php
/**
 * The viewer's door to a file's bytes.
 *
 *   POST {action: open, id, version?}  View on the file's folder. Audits a 'view'
 *        and returns a short-lived token plus what the viewer needs to know: the
 *        kind of preview, whether this person may download, and the watermark.
 *   GET  ?t=<token>                    the bytes, for that token only.
 *
 * WHY A TOKEN AND NOT ?id=. View-only exists (Ed, 2026-10-10): somebody may see a
 * document without taking a copy. download.php refuses them; this endpoint must
 * not become a download link by another name. So the bytes are only reachable
 * through a token that:
 *   - lives in THIS person's PHP session - a copied URL is useless in any other
 *     browser, and there is no signing secret to keep anywhere;
 *   - is for one version of one file, and expires (FILES_VIEW_TTL);
 *   - is re-checked against the folder's permissions on EVERY request, so taking
 *     someone's access away stops a viewer that is already open.
 * That is a deterrent, not DRM, and the help says so: anything a browser can
 * show, a determined person can capture. The watermark is what makes a leak
 * traceable.
 *
 * The response is never something the browser will run as our page: a safe
 * media type for images, audio and video (so <img>/<video> can use it), and
 * application/octet-stream as an attachment for everything else, with nosniff
 * and a sandbox CSP in case anyone opens the URL directly. Range is supported so
 * video can seek and pdf.js can fetch only the pages it needs.
 */
define('FILES_RAW_OUTPUT', true);
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

const FILES_VIEW_TTL = 4 * 3600;   // long enough to watch a training video, short enough to be useless tomorrow

/** Media types we hand over as themselves, so <img>/<video>/<audio> can play them. SVG is NOT here: it can carry script. */
const FILES_VIEW_INLINE_TYPES = [
    'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
    'mp4' => 'video/mp4', 'm4v' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime', 'ogv' => 'video/ogg',
    'mp3' => 'audio/mpeg', 'm4a' => 'audio/mp4', 'aac' => 'audio/aac', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg', 'flac' => 'audio/flac',
    'pdf' => 'application/pdf',
];

/** Which viewer draws a file, by extension. Anything else gets "no preview". */
function filesViewKind(string $name): string
{
    $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
    $kinds = [
        'pdf'   => ['pdf'],
        'docx'  => ['docx'],
        'sheet' => ['xlsx', 'xlsm', 'xls', 'ods', 'csv'],
        'image' => ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'],
        'video' => ['mp4', 'm4v', 'webm', 'mov', 'ogv'],
        'audio' => ['mp3', 'm4a', 'aac', 'wav', 'ogg', 'flac'],
        'zip'   => ['zip'],
        'text'  => ['txt', 'log', 'md', 'ini', 'cfg', 'conf', 'json', 'xml', 'yml', 'yaml', 'ps1', 'sh', 'bat', 'cmd', 'sql',
                    'html', 'htm', 'css', 'js', 'ts', 'php', 'py', 'cs', 'java', 'c', 'cpp', 'h', 'go', 'rb', 'csr', 'pem', 'crt'],
    ];
    foreach ($kinds as $k => $exts) if (in_array($ext, $exts, true)) return $k;
    return 'none';
}

$fail = function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    filesApiRun(function () use ($conn, $analystId) {
        $in = filesApiBody();

        // Something inside a zip was opened or saved. The browser did the work
        // (the zip's bytes are already there), so this only records it - but it
        // re-checks the same permission the action needed.
        if (($in['action'] ?? '') === 'entry') {
            $id = (int)($in['id'] ?? 0);
            $what = ($in['what'] ?? '') === 'download' ? 'download' : 'view';
            $it = filesNeedItem($conn, $analystId, $id, $what === 'download' ? FilesAcl::DOWNLOAD : FilesAcl::VIEW, $what);
            filesAudit($conn, $analystId, $what,
                       ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'version_id' => (int)($in['version'] ?? 0) ?: null, 'item_name' => $it['name']],
                       ['zip_entry' => mb_substr((string)($in['entry'] ?? ''), 0, 500)]);
            filesApiOk();
        }

        if (($in['action'] ?? '') !== 'open') filesApiFail('Unknown action.');
        $id = (int)($in['id'] ?? 0);
        $it = filesNeedItem($conn, $analystId, $id, FilesAcl::VIEW, 'view');
        $lvl = FilesAcl::level($conn, $analystId, (int)$it['folder_id']);

        $versionId = (int)($in['version'] ?? 0) ?: (int)$it['current_version_id'];
        $st = $conn->prepare("SELECT id, version_no, size_bytes, mime_type FROM files_versions WHERE id = ? AND item_id = ?");
        $st->execute([$versionId, $id]);
        $v = $st->fetch(PDO::FETCH_ASSOC);
        if (!$v) filesApiFail('File not found.');

        // The token goes into the session - which the bootstrap opened read-only.
        session_start();
        $now = time();
        $tokens = array_filter($_SESSION['files_view'] ?? [], fn($t) => $t['exp'] > $now);
        if (count($tokens) > 40) $tokens = array_slice($tokens, -40, null, true);   // a long day of viewing stays small
        $token = bin2hex(random_bytes(16));
        $tokens[$token] = ['item' => $id, 'version' => (int)$v['id'], 'exp' => $now + FILES_VIEW_TTL];
        $_SESSION['files_view'] = $tokens;
        session_write_close();

        $watermark = FilesAcl::watermark($conn, (int)$it['folder_id']);
        filesAudit($conn, $analystId, 'view',
                   ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'version_id' => (int)$v['id'], 'item_name' => $it['name']],
                   ['version' => (int)$v['version_no'], 'watermark' => $watermark, 'can_download' => $lvl >= FilesAcl::DOWNLOAD]);

        $name = $_SESSION['analyst_name'] ?? '';
        filesApiOk([
            'url'          => 'view.php?t=' . $token,
            'name'         => $it['name'],
            'kind'         => filesViewKind($it['name']),
            'size'         => (int)$v['size_bytes'],
            'version_id'   => (int)$v['id'],
            'version_no'   => (int)$v['version_no'],
            'is_current'   => (int)$v['id'] === (int)$it['current_version_id'],
            'can_download' => $lvl >= FilesAcl::DOWNLOAD,
            'level'        => $lvl,
            'folder_id'    => (int)$it['folder_id'],
            // The words stamped across the page. Built here so the client cannot choose them.
            'watermark'    => $watermark ? trim($name . '  ' . gmdate('Y-m-d H:i') . ' UTC  ' . ($_SERVER['REMOTE_ADDR'] ?? '')) : null,
        ]);
    });
}

// ── GET: the bytes ──
try {
    $token = (string)($_GET['t'] ?? '');
    $t = preg_match('/^[0-9a-f]{32}$/', $token) ? ($_SESSION['files_view'][$token] ?? null) : null;
    if (!$t || $t['exp'] < time()) $fail(404, 'This view has expired. Open the file again.');

    // Permission is checked again on every request, not just when the token was made.
    $it = filesItem($conn, (int)$t['item']);
    if (!$it || FilesAcl::level($conn, $analystId, (int)$it['folder_id']) < FilesAcl::VIEW) $fail(404, 'File not found.');
    $st = $conn->prepare("SELECT * FROM files_versions WHERE id = ? AND item_id = ?");
    $st->execute([(int)$t['version'], (int)$t['item']]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    $abs = $v ? filesAbsolutePath($conn, $v['storage_path']) : null;
    if ($abs === null || !is_file($abs)) $fail(410, 'The stored copy of this file is missing from the server.');
    $size = filesize($abs);

    $start = 0;
    $end = $size - 1;
    $partial = false;
    if (!empty($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/', trim($_SERVER['HTTP_RANGE']), $m) && ($m[1] !== '' || $m[2] !== '')) {
        if ($m[1] === '') { $start = max(0, $size - (int)$m[2]); }
        else { $start = (int)$m[1]; if ($m[2] !== '') $end = min((int)$m[2], $size - 1); }
        if ($start > $end || $start >= $size) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $partial = true;
    }

    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    $ext = strtolower((string)pathinfo($it['name'], PATHINFO_EXTENSION));
    $type = FILES_VIEW_INLINE_TYPES[$ext] ?? null;
    header('Content-Type: ' . ($type ?: 'application/octet-stream'));
    // No filename: "Save as" on this response offers a meaningless name.
    header('Content-Disposition: ' . ($type ? 'inline' : 'attachment'));
    header('X-Content-Type-Options: nosniff');
    header("Content-Security-Policy: sandbox; default-src 'none'; media-src 'self'; img-src 'self'");
    header('Cache-Control: private, no-store');
    header('Accept-Ranges: bytes');
    if ($partial) {
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));

    $fh = fopen($abs, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $buf = fread($fh, (int)min(1024 * 1024, $left));
        if ($buf === false) break;
        echo $buf;
        flush();
        $left -= strlen($buf);
    }
    fclose($fh);
} catch (Throwable $e) {
    error_log('files view: ' . $e->getMessage());
    if (!headers_sent()) $fail(500, 'The file could not be shown.');
}
