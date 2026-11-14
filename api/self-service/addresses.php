<?php
/**
 * API: the signed-in person's other email addresses (GitHub #308).
 *
 *   GET                              main address, linked addresses, pending claims
 *   POST {action: 'add', email}      email a confirmation link to the address; it is linked only when
 *                                    that link is opened (self-service/confirm-address.php)
 *   POST {action: 'remove', email}   remove a linked address, or cancel a pending one
 *
 * PRIVACY: 'add' answers the same way whatever the address is - free, somebody else's, already
 * linked to somebody - so the portal can never be used to find out who has an address. Only the
 * mailbox's owner, opening the link, learns that an address could not be linked. The person is
 * always taken from the session: there is no user id parameter.
 *
 * Linked addresses are for mail only - they never sign anyone in.
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/user_emails.php';

header('Content-Type: application/json');
if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
$userId = (int)$_SESSION['ss_user_id'];

try {
    $conn = connectToDatabase();
    if (!userEmailsReady($conn)) {
        echo json_encode(['success' => true, 'ready' => false]);
        exit;
    }
    $conn->exec("DELETE FROM user_email_claims WHERE expires_at < UTC_TIMESTAMP()");   // tidy as we go

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        $st = $conn->prepare("SELECT email FROM users WHERE id = ?");
        $st->execute([$userId]);
        $main = $st->fetchColumn();
        $p = $conn->prepare("SELECT email, expires_at FROM user_email_claims WHERE user_id = ? ORDER BY id");
        $p->execute([$userId]);
        echo json_encode(['success' => true, 'ready' => true, 'main' => $main ?: null,
            'linked' => array_map(fn($r) => ['email' => $r['email'], 'since' => $r['created_datetime']], userLinkedEmails($conn, $userId)),
            'pending' => $p->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    csrfRequireWriteMethod();
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $email = userEmailNorm($in['email'] ?? '');
    $action = (string)($in['action'] ?? '');

    if ($action === 'remove') {
        $conn->prepare("DELETE FROM user_email_claims WHERE user_id = ? AND email = ?")->execute([$userId, $email]);
        userUnlinkEmail($conn, null, $userId, $email);
        echo json_encode(['success' => true]);
        exit;
    }
    if ($action !== 'add') { echo json_encode(['success' => false, 'error' => 'Unknown action']); exit; }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        echo json_encode(['success' => false, 'error' => addrErr('invalid')]);
        exit;
    }
    // A handful of pending claims at most - this sends email on the person's behalf.
    $n = $conn->prepare("SELECT COUNT(*) FROM user_email_claims WHERE user_id = ? AND created_at > UTC_TIMESTAMP() - INTERVAL 1 HOUR");
    $n->execute([$userId]);
    if ((int)$n->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'error' => addrErr('too_many')]);
        exit;
    }
    // Their own addresses: say so (it is theirs - nothing is revealed).
    $own = userEmailOwner($conn, $email);
    if ($own && $own['user_id'] === $userId) {
        echo json_encode(['success' => false, 'error' => addrErr('already_yours')]);
        exit;
    }
    // Everything else - including an address that belongs to someone else - gets the same
    // answer. Someone else's address gets no email at all (nothing to confirm), so the owner is
    // not bothered and the person learns nothing.
    // TRAP: the claim is stored for EVERY address, so a taken one shows as "waiting for
    // confirmation" exactly like a free one - leaving it out would itself say "taken".
    $raw = bin2hex(random_bytes(32));
    $conn->prepare("DELETE FROM user_email_claims WHERE user_id = ? AND email = ?")->execute([$userId, $email]);
    $conn->prepare("INSERT INTO user_email_claims (user_id, email, token_hash, expires_at) VALUES (?, ?, ?, UTC_TIMESTAMP() + INTERVAL 24 HOUR)")
         ->execute([$userId, $email, hash('sha256', $raw)]);
    if (!$own) {
        require_once '../../includes/self_service_email.php';
        $st = $conn->prepare("SELECT COALESCE(NULLIF(preferred_name, ''), display_name, email) FROM users WHERE id = ?");
        $st->execute([$userId]);
        $name = (string)$st->fetchColumn();
        // The same host and path as the registration link (ssBuildVerifyUrl()), on the confirm page.
        $link = str_replace('/self-service/verify-email.php?', '/self-service/confirm-address.php?', ssBuildVerifyUrl($raw));
        $body = '<p>' . htmlspecialchars('Hello ' . $name . ',') . '</p>'
              . '<p>' . htmlspecialchars('You asked to add this address to your service desk account, so that emails you send from it are filed under your account and show in your portal.') . '</p>'
              . '<p><a href="' . htmlspecialchars($link) . '">' . htmlspecialchars('Confirm this address') . '</a></p>'
              . '<p>' . htmlspecialchars('The link works once and expires in 24 hours. If you did not ask for this, ignore this email - nothing changes.') . '</p>';
        if (!ssSendSystemEmail($conn, $email, 'Confirm your other email address', $body)) {
            $conn->prepare("DELETE FROM user_email_claims WHERE user_id = ? AND email = ?")->execute([$userId, $email]);
            echo json_encode(['success' => false, 'error' => addrErr('send_failed')]);
            exit;
        }
    }
    echo json_encode(['success' => true, 'pending' => true]);
} catch (Throwable $e) {
    error_log('self-service/addresses: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => addrErr('failed')]);
}

/** An error message key the portal translates (self-service.account.addr_err_*). */
function addrErr(string $key): string
{
    return 'addr_err_' . $key;
}
