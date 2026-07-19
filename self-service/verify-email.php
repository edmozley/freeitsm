<?php
/**
 * Self-service email verification landing page.
 *
 * Opened from the confirmation link in a registration email. Validates the
 * token, then applies the parked password to the user (creating the users row
 * if this was a brand-new email), signs them in, and sends them to the portal.
 * This is the ONLY place a self-service password gets set from registration.
 */
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/tenancy.php';

$ok = false;
$heading = 'Link invalid or expired';
$message = 'This confirmation link is no longer valid. Please register again to get a fresh one.';

$token = (string)($_GET['token'] ?? '');
if ($token !== '' && preg_match('/^[0-9a-f]{64}$/i', $token)) {
    try {
        $conn = connectToDatabase();
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $tokenHash = hash('sha256', $token);

        $stmt = $conn->prepare(
            "SELECT id, email, password_hash, display_name
             FROM user_verification_tokens
             WHERE token_hash = ? AND expires_at > UTC_TIMESTAMP()"
        );
        $stmt->execute([$tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $email = strtolower(trim($row['email']));

            // Guard against a race: if a real (password-set) account appeared in
            // the meantime, don't overwrite it.
            $u = $conn->prepare("SELECT id, password_hash, auth_provider_id FROM users WHERE email = ?");
            $u->execute([$email]);
            $existing = $u->fetch(PDO::FETCH_ASSOC);

            if ($existing && !empty($existing['password_hash'])) {
                $heading = 'Already confirmed';
                $message = 'This account is already set up. Please sign in.';
            } elseif ($existing && (int)($existing['auth_provider_id'] ?? 0) > 0) {
                // The same guard register.php applies, repeated here because the
                // token may have been issued BEFORE the account was linked to a
                // directory — a token minted yesterday must not be able to plant
                // a local password on an account that is now directory-backed.
                $heading = 'Use your work account';
                $message = 'This account now signs in with your work details. Please use those on the sign-in page.';
            } else {
                // Work out their company from the confirmed address. On the claim
                // path we only FILL A BLANK — an admin who has already filed this
                // person by hand outranks anything we can infer from a domain.
                $newTenantId = resolveTenantForNewUser($conn, $email);

                if ($existing) {
                    $conn->prepare("UPDATE users SET password_hash = ?, display_name = COALESCE(NULLIF(?, ''), display_name) WHERE id = ?")
                         ->execute([$row['password_hash'], $row['display_name'], $existing['id']]);
                    $userId = (int)$existing['id'];
                    if ($newTenantId !== null) {
                        $conn->prepare("UPDATE users SET tenant_id = ? WHERE id = ? AND tenant_id IS NULL")
                             ->execute([$newTenantId, $userId]);
                    }
                } else {
                    $conn->prepare("INSERT INTO users (email, display_name, password_hash, tenant_id, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())")
                         ->execute([$email, $row['display_name'], $row['password_hash'], $newTenantId]);
                    $userId = (int)$conn->lastInsertId();
                }

                // Consume every pending token for this email.
                $conn->prepare("DELETE FROM user_verification_tokens WHERE email = ?")->execute([$email]);

                // Sign them in (same session shape as login/register).
                $_SESSION['ss_user_id']    = $userId;
                $_SESSION['ss_user_email'] = $email;
                $_SESSION['ss_user_name']  = $row['display_name'] ?: $email;

                header('Location: index.php');
                exit;
            }
        }
    } catch (Exception $e) {
        error_log('verify-email error: ' . $e->getMessage());
        $message = 'Something went wrong confirming your account. Please try registering again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm your account - FreeITSM</title>
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
        <a class="btn" href="login.php">Go to sign in</a>
    </div>
</body>
</html>
