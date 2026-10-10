<?php
/**
 * GET ?id=<file>[&version=<version id>] - stream a file to the browser.
 *
 * Download permission on the file's folder, checked here on every request -
 * the stored path is never exposed and the storage root is denied to the web
 * server outright. Every download is audited, including which version.
 *
 * Supports HTTP Range so a 2 GB download that drops can resume, and so the
 * phase 2 video player can seek. Always sent as an attachment with nosniff:
 * nothing uploaded here is ever rendered by the browser as our own page.
 */
define('FILES_RAW_OUTPUT', true);
require_once __DIR__ . '/../../includes/files/api_bootstrap.php';

$fail = function (int $code, string $msg) {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
};

try {
    $id = (int)($_GET['id'] ?? 0);
    $it = filesItem($conn, $id);
    $lvl = $it ? FilesAcl::level($conn, $analystId, (int)$it['folder_id']) : 0;
    if (!$it || $lvl < FilesAcl::VIEW) $fail(404, 'File not found.');
    if ($lvl < FilesAcl::DOWNLOAD) {
        filesAudit($conn, $analystId, 'denied', ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'item_name' => $it['name']],
                   ['wanted' => 'download', 'held' => FilesAcl::levelKey($lvl)]);
        $fail(403, 'You can view this file but not download it.');
    }

    $versionId = (int)($_GET['version'] ?? 0) ?: (int)$it['current_version_id'];
    $st = $conn->prepare("SELECT * FROM files_versions WHERE id = ? AND item_id = ?");
    $st->execute([$versionId, $id]);
    $v = $st->fetch(PDO::FETCH_ASSOC);
    if (!$v) $fail(404, 'File not found.');

    $abs = filesAbsolutePath($conn, $v['storage_path']);
    if ($abs === null || !is_file($abs)) {
        error_log("files download: stored bytes missing for version {$v['id']} ({$v['storage_path']})");
        $fail(410, 'The stored copy of this file is missing from the server. Tell your administrator.');
    }
    $size = filesize($abs);

    // Range: bytes=start-end (one range only, which is all browsers send for this).
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

    // One audit row per download, not per range request: a resumed or seeking
    // download sends many, and only the one starting at 0 is a new download.
    if ($start === 0) {
        filesAudit($conn, $analystId, 'download',
                   ['folder_id' => (int)$it['folder_id'], 'item_id' => $id, 'version_id' => (int)$v['id'], 'item_name' => $it['name']],
                   ['version' => (int)$v['version_no'], 'size' => $size]);
    }

    while (ob_get_level()) ob_end_clean();
    @set_time_limit(0);
    $safe = str_replace(['"', "\r", "\n"], '', $it['name']) ?: 'file';
    header('Content-Type: application/octet-stream');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="' . $safe . "\"; filename*=UTF-8''" . rawurlencode($safe));
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, no-store');
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
    error_log('files download: ' . $e->getMessage());
    if (!headers_sent()) $fail(500, 'The file could not be sent.');
}
