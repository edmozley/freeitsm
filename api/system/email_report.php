<?php
/**
 * API: email a report the analyst just built in the browser to THEMSELVES (5.3.0).
 *
 * POST JSON { subject, html?, filename, pdf_base64 }
 *   html        the HTML version of the report, for the body of the email
 *   pdf_base64  the PDF (assets/js/pdf-writer.js), base64
 *   -> { success: true, sent_to, delivered: {body, attachment, link} }
 *   -> { success: false, code, error }
 *
 * Why it exists: some installs are not reachable from the internet, so an emailed
 * "download" link cannot be opened there - the report has to travel in the email.
 * The first caller is the "what if" report before deleting a directory source; any
 * report can use it through assets/js/email-report.js.
 *
 * WHAT GOES IN THE EMAIL is decided here, not by the caller: System -> Emailed
 * reports ticks any of body / attachment / download link (includes/report_downloads.php).
 * The link needs the report_downloads table; before Database Verification has added
 * it the link is skipped, and if the link was the ONLY way ticked the PDF is attached
 * instead, so the report still arrives.
 *
 * RULES - this must never become a way to send mail to anyone:
 *   - any signed-in analyst may use it (no module: the report is theirs already),
 *     but the ONLY recipient is that analyst's own stored email address. There is
 *     no recipient field, so it cannot be used as a relay;
 *   - POST with the CSRF token (central csrfEnforce(), and checked again here);
 *   - application/pdf only: the bytes must start "%PDF-", and the name ends .pdf;
 *   - at most SYSTEM_EMAIL_ATTACH_MAX_BYTES (includes/self_service_email.php);
 *   - every send, and every failed send, is in the email send log as
 *     'report_to_self'; every link is in system_logs as 'report_download'.
 *
 * Error codes for the browser: not_signed_in, bad_request, not_pdf, too_large,
 * no_email, no_mailbox, send_failed.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/self_service_email.php';
require_once '../../includes/html_sanitise.php';
require_once '../../includes/report_downloads.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

// Sends mail: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

/**
 * Stop with a refusal the browser can map to its own words.
 *
 * @param string $code  the error code (see the file header)
 * @param string $error  the English sentence
 * @param int $status  the HTTP status
 */
function emailReportFail($code, $error, $status = 200) {
    http_response_code($status);
    echo json_encode(['success' => false, 'code' => $code, 'error' => $error]);
    exit;
}

/** Escape for the email's HTML. */
function emailReportEsc($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['analyst_id'])) {
    emailReportFail('not_signed_in', 'Not authenticated', 401);
}
// csrfEnforce() already ran from functions.php; this keeps the rule true even if
// a caller ever reaches the file without the session cookie it looks for.
csrfRequireToken();
$analystId = (int)$_SESSION['analyst_id'];

// The base64 of the largest PDF allowed, plus room for the subject and the HTML.
$maxRequest = (int)ceil(SYSTEM_EMAIL_ATTACH_MAX_BYTES * 4 / 3) + 1024 * 1024;
$tooLarge = 'The report is too large to email (the limit is '
    . round(SYSTEM_EMAIL_ATTACH_MAX_BYTES / 1048576) . ' MB). Download it instead.';
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxRequest) {
    emailReportFail('too_large', $tooLarge);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) {
    emailReportFail('bad_request', 'Invalid request data');
}

$pdf = base64_decode(preg_replace('/\s+/', '', (string)($in['pdf_base64'] ?? '')), true);
if ($pdf === false || $pdf === '') {
    emailReportFail('bad_request', 'No PDF was sent.');
}
if (strncmp($pdf, '%PDF-', 5) !== 0) {
    emailReportFail('not_pdf', 'Only a PDF can be emailed this way.');
}

$filename = ssAttachmentFilename((string)($in['filename'] ?? ''));
if (!preg_match('/\.pdf$/i', $filename)) {
    $filename = ($filename === 'attachment' ? 'report' : $filename) . '.pdf';
}
$file = ['name' => $filename, 'mime' => 'application/pdf', 'content' => $pdf];
try {
    ssSystemEmailAttachmentParts([$file]);   // the sender's own rules, said up front
} catch (InvalidArgumentException $e) {
    emailReportFail('too_large', $tooLarge);
}

$subject = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', (string)($in['subject'] ?? '')));
if ($subject === '') $subject = 'Report';
if (mb_strlen($subject, 'UTF-8') > 200) $subject = mb_substr($subject, 0, 200, 'UTF-8');

$link = null;
try {
    $conn = connectToDatabase();
    $stmt = $conn->prepare('SELECT email FROM analysts WHERE id = ? AND COALESCE(is_active, 1) = 1');
    $stmt->execute([$analystId]);
    $to = trim((string)$stmt->fetchColumn());
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        // Said plainly: nothing is wrong with the mail setup, there is nowhere to send it.
        emailReportFail('no_email', 'Your account has no valid email address. Add one in My Account, or download the report instead.');
    }

    // What goes in the email: the administrators' choice, not the caller's.
    $how = reportEmailSettings($conn);
    $wantBody = $how['body'];
    // The HTML comes from the browser, so it goes through the same allow-list as
    // a customer's - even though it is only ever sent to the person who made it.
    $report = $wantBody ? sanitiseUserHtml((string)($in['html'] ?? '')) : '';
    $hasReport = trim(strip_tags($report)) !== '';
    $wantLink = $how['link'] && reportDownloadsReady($conn);
    // The PDF is attached when ticked - and also when nothing else ticked would
    // carry the report: the link was the only way and is not available yet, or the
    // body was and the caller sent no HTML version.
    $wantAttach = $how['attach'] || (!$wantLink && !$hasReport);

    $parts = [];
    if ($hasReport) $parts[] = $report;
    if ($wantLink) {
        $link = reportDownloadCreate($conn, $analystId, $filename, 'application/pdf', $pdf, $how['link_days']);
        $parts[] = '<p>' . emailReportEsc(reportT('mail_link', 'Download the report (PDF):')) . ' ' . '<a href="' . emailReportEsc($link['url']) . '">'
                 . emailReportEsc($filename) . '</a><br><span style="font-size:12px;color:#5a6c7d">'
                 . emailReportEsc(reportT('mail_link_note', 'The link opens only for you, signed in, until {date} UTC.', ['date' => gmdate('j M Y H:i', strtotime($link['expires_at'] . ' UTC'))]))
                 . '</span></p>';
    }
    if (!$hasReport) {
        // No report in the body (not ticked, or no HTML version sent): one line
        // saying where the report is, above the link if there is one.
        array_unshift($parts, '<p>' . emailReportEsc($wantAttach
            ? reportT('mail_attached', 'The report "{name}" is attached.', ['name' => $filename])
            : reportT('mail_use_link', 'Download the report "{name}" with the link below.', ['name' => $filename])) . '</p>');
    } elseif ($wantAttach) {
        $parts[] = '<p style="font-size:12px;color:#5a6c7d">' . emailReportEsc(reportT('mail_also_attached', 'The report is also attached as a PDF.')) . '</p>';
    }
    $html = '<div style="font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#2c3e50;line-height:1.5">'
          . implode("\n", $parts) . '</div>';

    if (!ssSendSystemEmail($conn, $to, $subject, $html, 'report_to_self', $wantAttach ? [$file] : [])) {
        // A link nobody was sent must not stay usable.
        if ($link) reportDownloadRevoke($conn, $link['id'], $analystId);
        // ssSendSystemEmail() has written the reason to the send log.
        if (!ssGetSendingMailbox($conn)) {
            emailReportFail('no_mailbox', 'Email is not set up on this system - no mailbox is able to send. Download the report instead.');
        }
        emailReportFail('send_failed', 'The email could not be sent. The reason is in the email send log.');
    }

    echo json_encode(['success' => true, 'sent_to' => $to,
                      'delivered' => ['body' => $wantBody, 'attachment' => $wantAttach, 'link' => $link !== null]]);
} catch (Exception $e) {
    error_log('email_report: ' . $e->getMessage());
    if ($link && isset($conn)) reportDownloadRevoke($conn, $link['id'], $analystId);
    emailReportFail('send_failed', 'The email could not be sent.');
}
