<?php
/**
 * API: System -> Emailed reports (5.3.0). Administrators only.
 *
 *   GET                              the settings, the link address warning, the
 *                                    download links and recent link activity
 *   POST { action: 'save', body, attach, link, link_days }
 *                                    save how reports are emailed (at least one ticked;
 *                                    link_days 1, 7 or 30)
 *   POST { action: 'revoke', id }    revoke a download link (its file is deleted now)
 *
 * The rules live in includes/report_downloads.php; this only reads and writes them.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only
require_once '../../includes/functions.php';
require_once '../../includes/report_downloads.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

header('Content-Type: application/json');

try {
    $conn = connectToDatabase();

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        echo json_encode([
            'success'   => true,
            'settings'  => reportEmailSettings($conn),
            'day_choices' => REPORT_LINK_DAY_CHOICES,
            'links_ready' => reportDownloadsReady($conn),
            'link_warning_url' => reportLinkReachabilityWarning($conn),
            'links'     => reportDownloadsList($conn),
            'activity'  => reportDownloadsActivity($conn),
        ]);
        exit;
    }

    // Changes data: never on a GET, which a link on another site can send (csrf.php).
    csrfRequireWriteMethod();
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) throw new InvalidArgumentException('Invalid request data');

    switch ($in['action'] ?? '') {
        case 'save':
            $saved = reportEmailSaveSettings($conn, $in);
            echo json_encode(['success' => true, 'settings' => $saved]);
            break;
        case 'revoke':
            if (!reportDownloadsReady($conn)) throw new InvalidArgumentException(reportT('not_ready', 'Run Database Verification to add download links.'));
            if (!reportDownloadRevoke($conn, (int)($in['id'] ?? 0), (int)$_SESSION['analyst_id'])) {
                throw new InvalidArgumentException(reportT('revoke_missing', 'That link does not exist or is already revoked.'));
            }
            echo json_encode(['success' => true]);
            break;
        default:
            throw new InvalidArgumentException('Unknown action');
    }
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('emailed_reports: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The request could not be completed.']);
}
