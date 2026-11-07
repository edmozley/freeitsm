<?php
/**
 * API Endpoint: send a test email from a mailbox to the analyst clicking Test (4.11.0).
 *
 * POST: { "mailbox_id": N|null, "provider": "imap", ...the mailbox modal's fields }
 *
 * It sends through mailboxSendSimple() - the same provider routes a reply uses - and is
 * recorded in the mailbox's send log as "Mailbox test". Basic IMAP is tested with the
 * form's (possibly unsaved) values; Microsoft and Google as saved. Which settings and
 * which password are used is decided by mailboxTestResolve() - read its TRAP before
 * changing it.
 *
 * Returns { success: true, to, used_saved } or { success: false, error }.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/rbac.php';
require_once '../../includes/i18n.php';
require_once '../../includes/encryption.php';
require_once '../../includes/mailbox_send.php';

// Sends an email: never on a GET, which a link on another site can send (csrf.php).
csrfRequireWriteMethod();

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
// Settings-only (Mailboxes tab), the same gate as Save and Verify.
requireModuleAccessJson('tickets');
requireCapabilityJson(Cap::TICKETS_MAILBOXES);

$form = json_decode(file_get_contents('php://input'), true);
if (!is_array($form)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Where the test goes: the analyst who clicked it, and nobody else - so the
    // button cannot be used to send mail as the company to an outside address.
    $who = $conn->prepare("SELECT email FROM analysts WHERE id = ?");
    $who->execute([(int)$_SESSION['analyst_id']]);
    $to = trim((string)$who->fetchColumn());
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'error' => 'Your analyst account has no valid email address to send the test to. An administrator can set one in System → Analysts.']);
        exit;
    }

    $stored = null;
    $mailboxId = (int)($form['mailbox_id'] ?? 0);
    if ($mailboxId > 0) {
        $stmt = $conn->prepare("SELECT * FROM target_mailboxes WHERE id = ?");
        $stmt->execute([$mailboxId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'error' => 'Mailbox not found']);
            exit;
        }
        $stored = decryptMailboxRow($row);
    }

    [$mailbox, $error, $usedSaved] = mailboxTestResolve($stored, $form);
    if ($error !== null) {
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }

    $name = (string)($mailbox['name'] ?? '') !== '' ? (string)$mailbox['name'] : (string)($mailbox['target_mailbox'] ?? '');
    $subject = t('tickets.settings.mailbox_test.subject', ['name' => $name]);
    $body = '<p>' . htmlspecialchars(t('tickets.settings.mailbox_test.body', ['name' => $name])) . '</p>'
          . '<p style="color:#666">' . htmlspecialchars(gmdate('Y-m-d H:i') . ' UTC') . '</p>';

    $err = mailboxSendSimple($conn, $mailbox, $to, $subject, $body, 'mailbox_test');
    if ($err !== null) {
        echo json_encode(['success' => false, 'error' => $err, 'used_saved' => $usedSaved]);
        exit;
    }
    echo json_encode(['success' => true, 'to' => $to, 'used_saved' => $usedSaved]);
} catch (Throwable $e) {
    error_log('test_mailbox_send: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'The test could not run: ' . $e->getMessage()]);
}
