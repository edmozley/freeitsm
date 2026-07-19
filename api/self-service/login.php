<?php
/**
 * API: Self-Service Portal Login
 * POST { identifier | email, password }
 * Returns mfa_required: true if TOTP is enabled.
 *
 * ONE BOX, THREE OUTCOMES
 * -----------------------
 * The requester types an email address OR a username, plus a password, and this
 * decides how to check it — the same shape the analyst login has used since the
 * directory work landed (login.php), so the two behave alike:
 *
 *   a) we know them and they're pinned to a directory → LDAP bind
 *   b) we know them and they're local                 → password_verify
 *   c) we don't know them                             → ask the directories, and
 *                                                       create the account if one
 *                                                       answers (JIT)
 *
 * (c) is the point of the feature: nobody wants to hand-create a portal account
 * for every new starter, and the people who most need the portal — staff with no
 * mailbox, who can't be reached by email in the first place — are exactly the
 * ones an admin is least likely to have pre-created.
 *
 * An account pinned to an OIDC provider is refused here, as it has no password
 * to check; the login page routes those to the SSO button instead.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/ldap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

// `email` is still accepted so nothing that posts the old field name breaks.
$identifier = trim($input['identifier'] ?? $input['email'] ?? '');
$password   = $input['password'] ?? '';

if ($identifier === '' || $password === '') {
    echo json_encode(['success' => false, 'error' => 'Please enter your details and password']);
    exit;
}

// One message for every failure below. Which of "no such account", "wrong
// password" and "not allowed in" applied is not the requester's business, and
// telling them turns this endpoint into an account-existence oracle.
$genericFailure = 'Invalid details or password';

try {
    $conn = connectToDatabase();

    // Look the account up by EITHER identifier. Both columns are nullable, so
    // each side is guarded against matching on emptiness — without that, a blank
    // username would match every mailbox-less directory user at once.
    $lookup = $conn->prepare(
        "SELECT id, email, username, display_name, preferred_name, password_hash,
                totp_enabled, auth_provider_id
           FROM users
          WHERE (email    IS NOT NULL AND LOWER(email)    = LOWER(?))
             OR (username IS NOT NULL AND LOWER(username) = LOWER(?))
          LIMIT 1"
    );
    $lookup->execute([$identifier, $identifier]);
    $user = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;

    $authOk           = false;
    $ldapProviderUsed = null;
    $ldapCandidates   = [];

    $assignedProviderId = (int)($user['auth_provider_id'] ?? 0);

    if ($user && $assignedProviderId > 0) {
        // (a) Pinned. ldapGetProvider() returns null for an OIDC provider, which
        // is how an SSO account is kept off this form.
        $assigned = ldapGetProvider($conn, $assignedProviderId);
        if ($assigned && (int)$assigned['enabled'] === 1) {
            $ldapCandidates = [$assigned];
        } else {
            echo json_encode([
                'success' => false,
                'error'   => 'This account signs in with single sign-on. Please use the button on the sign-in page.',
            ]);
            exit;
        }

    } elseif ($user) {
        // (b) Local.
        $authOk = !empty($user['password_hash']) && password_verify($password, $user['password_hash']);

    } else {
        // (c) Unknown — the directories get asked. Scoped, NOT every provider:
        // see ldapPortalProviders() for why spraying a typed password across
        // every client company's domain controller is not an option.
        $ldapCandidates = ldapPortalProviders($conn, $identifier);
    }

    foreach ($ldapCandidates as $ldapProvider) {
        $res = ldapAuthenticate($ldapProvider, $identifier, $password);
        if (!$res['ok']) {
            continue;   // wrong password, or this directory doesn't know them
        }

        // A correct password alone must not grant access — the configured
        // groups decide (GitHub #47). BOTH rungs may use the portal: someone in
        // the analysts group is still a person who raises their own tickets, and
        // ldapAccessRole() returns 'analyst' for anyone in both groups, so
        // testing for 'user' alone would lock out exactly the dual-membership
        // people who are most likely to try.
        $role = ldapAccessRole($ldapProvider, $res['user']);
        if ($role === null) {
            echo json_encode([
                'success' => false,
                'error'   => 'Your account is not a member of a group that grants access.',
            ]);
            exit;
        }

        $resolved = ldapResolveUser($conn, $ldapProvider, $res['user']);
        if (!$resolved['ok']) {
            echo json_encode(['success' => false, 'error' => $resolved['error']]);
            exit;
        }

        $reload = $conn->prepare(
            "SELECT id, email, username, display_name, preferred_name, password_hash, totp_enabled
               FROM users WHERE id = ?"
        );
        $reload->execute([$resolved['user_id']]);
        $user = $reload->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($user) {
            $authOk           = true;
            $ldapProviderUsed = $ldapProvider;
        }
        break;   // the directory answered; do not try the others
    }

    if (!$user || !$authOk) {
        echo json_encode(['success' => false, 'error' => $genericFailure]);
        exit;
    }

    // The name ladder ends at the username, then the id — a directory user may
    // have no address, and "Welcome, " followed by nothing is worse than a
    // sign-in name.
    $displayName = $user['preferred_name']
        ?: ($user['display_name'] ?: ($user['username'] ?: ($user['email'] ?: ('#' . (int)$user['id']))));

    // TOTP is a LOCAL second factor. A directory bind has already been checked
    // by the directory, which enforces its own policy — and a portal user who
    // signs in with their AD password has no way to have set up TOTP here.
    if (!$ldapProviderUsed && $user['totp_enabled']) {
        $_SESSION['mfa_pending_ss_user_id'] = (int)$user['id'];
        $_SESSION['mfa_pending_ss_email']   = $user['email'];
        $_SESSION['mfa_pending_ss_name']    = $displayName;

        echo json_encode(['success' => true, 'mfa_required' => true]);
        exit;
    }

    $_SESSION['ss_user_id']    = (int)$user['id'];
    $_SESSION['ss_user_email'] = $user['email'];   // may be NULL — no mailbox
    $_SESSION['ss_user_name']  = $displayName;

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Login failed. Please try again.']);
}
