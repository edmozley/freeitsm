<?php
/**
 * Self-service: confirm another email address (GitHub #308).
 *
 * Opened from the link emailed to an address a person added under My account -> Your email
 * addresses. Opening it proves they can read that mailbox, so the address is linked to their
 * account: mail from it files under them and shows in their portal. It never signs anybody in
 * (linked addresses are for mail only), so this page needs no session - the token is the proof.
 * Single use, 24 hours, stored hashed (user_email_claims).
 *
 * Only the mailbox's owner reaches this page, so telling them an address could not be linked
 * (it is another account's) reveals nothing to anyone else.
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/user_emails.php';

$heading = 'Link invalid or expired';
$message = 'This confirmation link is no longer valid. Add the address again under My account to get a fresh one.';

$token = (string)($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[0-9a-f]{64}$/i', $token)) {
    try {
        $conn = connectToDatabase();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        if (userEmailsReady($conn)) {
            $st = $conn->prepare("SELECT id, user_id, email FROM user_email_claims WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()");
            $st->execute([hash('sha256', strtolower($token))]);
            $claim = $st->fetch(PDO::FETCH_ASSOC);
            if ($claim) {
                // Single use, whatever happens next.
                $conn->prepare("DELETE FROM user_email_claims WHERE id = ?")->execute([(int)$claim['id']]);
                $r = userLinkEmail($conn, null, (int)$claim['user_id'], (string)$claim['email'], 'portal');
                if ($r['ok'] || ($r['reason'] ?? '') === 'already_yours') {
                    $heading = 'Address confirmed';
                    $message = 'Emails you send from ' . $claim['email'] . ' are now filed under your account and show in your portal. Replies still go to the address you wrote from. You still sign in with your main address.';
                } else {
                    $heading = 'This address could not be added';
                    $message = 'It already belongs to another account here. If both are yours, ask the service desk to join them.';
                }
            }
        }
    } catch (Throwable $e) {
        error_log('confirm-address: ' . $e->getMessage());
        $message = 'Something went wrong confirming this address. Please try again from My account.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm your address - FreeITSM</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f0ede8; color: #2c3e50;
               display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 14px; box-shadow: 0 8px 30px rgba(0,0,0,.1);
                max-width: 440px; width: 90%; padding: 40px 34px; text-align: center; }
        h1 { font-size: 1.4rem; margin: 0 0 10px; color: #1b4332; }
        p { color: #5a6c7d; line-height: 1.6; margin: 0 0 22px; }
        a.btn { display: inline-block; background: #2d6a4f; color: #fff; text-decoration: none;
                padding: 11px 24px; border-radius: 8px; font-weight: 600; }
    </style>
</head>
<body>
    <div class="card">
        <h1><?php echo htmlspecialchars($heading); ?></h1>
        <p><?php echo htmlspecialchars($message); ?></p>
        <a class="btn" href="index.php">Go to the portal</a>
    </div>
</body>
</html>
