<?php
/**
 * API Endpoint: Get all target mailboxes
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/mailbox_graph.php';
require_once '../../includes/mailbox_health.php';
require_once '../../includes/tenancy.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
requireAnyModuleAccessJson(['tickets', 'knowledge', 'system']);

try {
    $conn = connectToDatabase();

    // Confidential mailbox (discussion #62), guarded until Database Verification has run.
    require_once '../../includes/ticket_sensitivity.php';
    $sensCol = ticketSensitivityReady($conn) ? 'default_sensitivity,' : "'normal' AS default_sensitivity,";
    // A mailbox's own oversized-email rules (GH #170), once Database Verification has added the column.
    $sensCol .= $conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'oversize_policy'")->fetch() ? ' oversize_policy,' : ' NULL AS oversize_policy,';
    // Save a copy of sent mail (GH #270), once Database Verification has added the columns.
    $sensCol .= $conn->query("SHOW COLUMNS FROM target_mailboxes LIKE 'save_sent_copy'")->fetch() ? ' save_sent_copy, sent_folder,' : ' 0 AS save_sent_copy, NULL AS sent_folder,';

    $sql = "SELECT id, name, provider, azure_tenant_id, azure_client_id, azure_client_secret,
                   oauth_redirect_uri, oauth_scopes, imap_server, imap_port, imap_encryption,
                   imap_username, imap_password, smtp_server, smtp_port, smtp_encryption,
                   smtp_username, smtp_password,
                   target_mailbox, auth_mode, authenticated_as, authenticated_addresses, email_folder, max_emails_per_check, mark_as_read,
                   rejected_action, imported_action, imported_folder,
                   is_active, tenant_id, default_origin_id, $sensCol health_dismissed, created_datetime, last_checked_datetime,
                   last_error, last_error_datetime,
                   CASE WHEN token_data IS NOT NULL AND token_data != '' THEN 1 ELSE 0 END as is_authenticated
            FROM target_mailboxes
            ORDER BY name";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $mailboxes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Convert fields to proper types
    foreach ($mailboxes as &$mailbox) {
        // Decrypt encrypted columns. Guard per-row so a single undecryptable
        // value (e.g. a field that was truncated by an undersized column) can't
        // blank out the entire mailbox list — flag that row instead.
        try {
            $mailbox = decryptMailboxRow($mailbox);
            $mailbox['decrypt_error'] = false;
        } catch (Exception $e) {
            $mailbox['decrypt_error'] = true;
            $mailbox['decrypt_error_message'] = $e->getMessage();
        }

        // Convert numeric fields to integers
        $mailbox['id'] = (int)$mailbox['id'];
        $mailbox['imap_port'] = (int)$mailbox['imap_port'];
        $mailbox['smtp_port'] = (int)($mailbox['smtp_port'] ?? 587);
        $mailbox['max_emails_per_check'] = (int)$mailbox['max_emails_per_check'];
        // Multi-tenancy: the company this mailbox is pinned to (null = shared intake).
        $mailbox['tenant_id'] = ($mailbox['tenant_id'] === null) ? null : (int)$mailbox['tenant_id'];
        // The ticket origin stamped on tickets this mailbox opens (#79).
        $mailbox['default_origin_id'] = ($mailbox['default_origin_id'] === null) ? null : (int)$mailbox['default_origin_id'];

        // Convert bit fields to booleans
        $mailbox['is_active'] = (bool)$mailbox['is_active'];
        $mailbox['mark_as_read'] = (bool)$mailbox['mark_as_read'];
        $mailbox['is_authenticated'] = (bool)$mailbox['is_authenticated'];

        // Mask client secret for display (show only last 4 chars) — and then DROP the
        // plaintext. It used to be masked into a NEW field while the original was left in
        // the payload, so the OAuth client secret (Microsoft and Google alike) was shipped
        // in full to the browser of any logged-in analyst. The IMAP password below always
        // did this correctly; the client secret was simply missed.
        if (!empty($mailbox['azure_client_secret'])) {
            $mailbox['azure_client_secret_masked'] = '****' . substr($mailbox['azure_client_secret'], -4);
        } else {
            $mailbox['azure_client_secret_masked'] = '';
        }
        unset($mailbox['azure_client_secret']);

        // Mask the IMAP password and never ship the plaintext to the browser.
        $mailbox['imap_password_set'] = !empty($mailbox['imap_password']);
        unset($mailbox['imap_password']);

        // The SMTP password gets exactly the same treatment. 🔴 The USERNAME is
        // returned in full and the password is not, which is the same asymmetry as
        // the IMAP pair above: the form has to redisplay the username or saving the
        // mailbox would wipe it, and a password never needs to travel back at all.
        $mailbox['smtp_password_set'] = !empty($mailbox['smtp_password']);
        unset($mailbox['smtp_password']);

        // Default + normalise the auth mode.
        $mailbox['auth_mode'] = ($mailbox['auth_mode'] ?? 'delegated') === 'app_only' ? 'app_only' : 'delegated';

        // Compute a clear "where is this reading from?" status for the UI so it's
        // obvious which inbox a mailbox actually pulls — and flags a wrong account.
        // A target that matches the signed-in mailbox's primary OR any alias is "ok".
        $target  = strtolower(trim((string) ($mailbox['target_mailbox'] ?? '')));
        $acceptedSet = mailboxAcceptedSet($mailbox);
        if (MailboxProvider::fromMailbox($mailbox) === MailboxProvider::Imap) {
            // Basic IMAP has no OAuth sign-in — it connects straight to the target
            // inbox with the stored password. "Connected" once a password is stored.
            $mailbox['auth_status'] = !empty($mailbox['imap_password_set']) ? 'imap' : 'unauthenticated';
            $mailbox['is_authenticated'] = !empty($mailbox['imap_password_set']);
        } elseif (MailboxProvider::fromMailbox($mailbox) === MailboxProvider::Google) {
            $mailbox['auth_status'] = $mailbox['is_authenticated'] ? 'ok' : 'unauthenticated';
        } elseif ($mailbox['auth_mode'] === 'app_only') {
            $mailbox['auth_status'] = 'app_only';            // always reads the target directly
        } elseif (!$mailbox['is_authenticated']) {
            $mailbox['auth_status'] = 'unauthenticated';     // delegated, never signed in
        } elseif (empty($acceptedSet)) {
            $mailbox['auth_status'] = 'unverified';           // signed in before we recorded who
        } elseif (in_array($target, $acceptedSet, true)) {
            $mailbox['auth_status'] = 'ok';                   // reading the right inbox (primary or alias)
        } else {
            $mailbox['auth_status'] = 'mismatch';             // ⚠ reading the WRONG inbox
        }
    }
    unset($mailbox);

    // Everything that could be quietly wrong with each mailbox, for the ⚠ on the
    // row. Computed here rather than in the browser so the browser never has to
    // hold the facts (folder names, sign-in state) needed to work it out.
    $originNames = [];
    foreach ($conn->query("SELECT id, name FROM ticket_origins") as $o) {
        $originNames[(int)$o['id']] = $o['name'];
    }
    $healthContext = ['origin_names' => $originNames];
    foreach ($mailboxes as &$mailbox) {
        $mailbox['problems'] = mailboxHealthProblems($mailbox, $healthContext);
        // The mark on the row counts only what has NOT been acknowledged; the
        // modal still lists the dismissed ones so they can be put back.
        $mailbox['problem_count'] = count(array_filter($mailbox['problems'], fn($p) => empty($p['dismissed'])));
        unset($mailbox['health_dismissed']);
    }
    unset($mailbox);

    echo json_encode([
        'success' => true,
        'mailboxes' => $mailboxes
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

?>
