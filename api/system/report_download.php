<?php
/**
 * Download an emailed report by its link (5.3.0): GET ?t=<64 hex token>.
 *
 * The link is in an email, so this is opened by a person in a browser:
 *   - not signed in: remembered in a short-lived cookie, sent to sign in, and
 *     brought back here afterwards (index.php reads the cookie);
 *   - signed in as anyone but the analyst it was sent to: 403;
 *   - unknown, revoked or expired: a plain page saying so (404 / 410);
 * and every answer, allowed or refused, is audited in system_logs
 * ('report_download') with the analyst, IP address and browser
 * (reportDownloadAuthorise(), includes/report_downloads.php).
 *
 * A GET that writes - the download count and the audit row - because a link in an
 * email can only be a GET. It is a log of a read, as api/documents/download.php's is,
 * and it hands the file only to its owner.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/report_downloads.php';
I18n::initFromSession();

/** The cookie that brings a signed-out visitor back here after signing in (read by index.php). */
const REPORT_DL_RETURN_COOKIE = 'freeitsm_report_return';

$token = strtolower(trim((string)($_GET['t'] ?? '')));
$base = defined('BASE_URL') ? BASE_URL : '/';

/**
 * A plain page with one message, and stop.
 *
 * @param int $status  the HTTP status
 * @param string $title  the heading
 * @param string $message  the sentence under it
 */
function reportDownloadPage($status, $title, $message) {
    global $base;
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title></head>'
       . '<body style="font:15px/1.6 system-ui,Segoe UI,Arial,sans-serif;color:#2c3e50;background:#f5f7fa;margin:0;padding:16px">'
       . '<div style="max-width:520px;margin:60px auto;background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 4px rgba(0,0,0,.08)">'
       . '<h1 style="font-size:18px;margin:0 0 8px">' . htmlspecialchars($title) . '</h1>'
       . '<p style="margin:0 0 16px">' . htmlspecialchars($message) . '</p>'
       . '<a href="' . htmlspecialchars($base) . '" style="color:#546e7a">' . htmlspecialchars(t('system.report_download.home')) . '</a>'
       . '</div></body></html>';
    exit;
}

if (empty($_SESSION['analyst_id'])) {
    // Only a well-formed token is remembered; anything else has nothing to come back to.
    if (preg_match('/^[0-9a-f]{64}$/', $token)) {
        setcookie(REPORT_DL_RETURN_COOKIE, $token, [
            'expires'  => time() + 900,
            'path'     => $base,
            'secure'   => function_exists('requestIsHttps') ? requestIsHttps() : !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    header('Location: ' . $base . 'auth/login.php');
    exit;
}

try {
    $conn = connectToDatabase();
    $r = reportDownloadAuthorise($conn, $token, (int)$_SESSION['analyst_id']);
} catch (Throwable $e) {
    error_log('report_download: ' . $e->getMessage());
    reportDownloadPage(500, t('system.report_download.unavailable_title'), t('system.report_download.unavailable'));
}

if (!$r['ok']) {
    switch ($r['outcome']) {
        case 'wrong_analyst':
            reportDownloadPage(403, t('system.report_download.not_yours_title'), t('system.report_download.not_yours'));
        case 'expired':
            reportDownloadPage(410, t('system.report_download.expired_title'), t('system.report_download.expired'));
        case 'revoked':
        case 'missing_file':
            reportDownloadPage(410, t('system.report_download.revoked_title'), t('system.report_download.revoked'));
        default:   // unknown, not_ready
            reportDownloadPage(404, t('system.report_download.unknown_title'), t('system.report_download.unknown'));
    }
}

$name = reportDownloadSafeName((string)$r['row']['filename']);
header('Content-Type: ' . (preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#i', (string)$r['row']['mime']) ? $r['row']['mime'] : 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . preg_replace('/[^\x20-\x7E]/', '_', $name) . '"; filename*=UTF-8\'\'' . rawurlencode($name));
header('Content-Length: ' . filesize($r['path']));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($r['path']);
exit;

/**
 * The stored name made safe for a header: no path, quotes, backslashes or control characters.
 *
 * @param string $name  the filename stored on the link
 */
function reportDownloadSafeName($name) {
    $name = preg_replace('/[\x00-\x1F\x7F"\\\\\/]/', '', $name);
    return $name !== '' ? $name : 'report.pdf';
}
