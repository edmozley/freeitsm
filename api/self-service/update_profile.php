<?php
/**
 * API: Update self-service user profile
 *
 * POST - preferred name, plus the contact details a person may maintain about
 * themselves (USER_SELF_EDITABLE_FIELDS: job title, office, phone, mobile).
 *
 * 🔴 THIS ENDPOINT IS REACHED BY A CUSTOMER, NOT AN ANALYST. It writes to
 * `users`, the same table the analyst screens and directory sync write, so the
 * field list is taken from the constant and NEVER from the request body. A
 * portal user posting {"manager_id":…} or {"employee_id":…} has those keys
 * ignored, not honoured — see includes/users.php for why those three are out.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/users.php';   // USER_SELF_EDITABLE_FIELDS, USER_DIRECTORY_OWNED

header('Content-Type: application/json');

if (!isset($_SESSION['ss_user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ⚠️ `?? []` is load-bearing, not tidiness. json_decode returns NULL on a body
// that is absent or malformed, and the `?? ''` on the next line survives that
// while `array_key_exists($f, null)` below is a TypeError in PHP 8 — a 500 on
// an empty POST, in an endpoint a customer's browser reaches.
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$preferredName = trim($input['preferred_name'] ?? '');

if (strlen($preferredName) > 100) {
    echo json_encode(['success' => false, 'error' => 'Name must be 100 characters or less']);
    exit;
}

try {
    $conn = connectToDatabase();

    // Is a directory the source of truth for this person? Same rule as the
    // analyst screens: refuse rather than accept and let the next sync revert
    // it. A save that silently does nothing is worse than one that says no —
    // and for a customer correcting their own phone number, an edit that
    // vanishes overnight is exactly the experience this feature exists to fix.
    $mStmt = $conn->prepare("SELECT is_managed FROM users WHERE id = ?");
    $mStmt->execute([$_SESSION['ss_user_id']]);
    $managedRow = $mStmt->fetchColumn();

    // ⚠️ `false` from fetchColumn means NO ROW, which is not the same as
    // is_managed = 0. The session can outlive the record. Without this the
    // UPDATE below would match nothing and still report success.
    if ($managedRow === false) {
        echo json_encode(['success' => false, 'error' => 'Account not found']);
        exit;
    }
    $isManaged = (int)$managedRow === 1;

    // 🔴 "Absent means don't touch", and preferred_name had to join that rule.
    //
    // It used to be written UNCONDITIONALLY, so a body that simply did not
    // mention it wrote NULL and silently cleared it. Harmless while the only
    // caller always sent it; a live bug the moment this endpoint accepted
    // partial payloads, because POST {"phone":"…"} then wiped the name the
    // person is addressed by in every email. Exactly the trap
    // api/tickets/save_user.php documents at length after it deleted an email
    // address the same way — found here by posting only the contact fields and
    // reading the row back, not from the response, which said success.
    $sets = [];
    $args = [];
    $nameSent = array_key_exists('preferred_name', $input);
    if ($nameSent) {
        $sets[] = 'preferred_name = ?';
        $args[] = $preferredName !== '' ? $preferredName : null;
    }

    // The contact details. Iterating the CONSTANT rather than the request body
    // is the whole guard: an unexpected key in the payload can never become a
    // column name. Absent keys are left alone, so a caller sending only
    // preferred_name does not blank somebody's telephone number.
    foreach (USER_SELF_EDITABLE_FIELDS as $f) {
        if (!array_key_exists($f, $input)) continue;
        if ($isManaged && in_array($f, USER_DIRECTORY_OWNED, true)) {
            echo json_encode([
                'success' => false,
                'error'   => 'managed',
            ]);
            exit;
        }
        $v = userPersonFieldValue($f, $input[$f]);
        // Lengths match the columns (VARCHAR(150) for job title and office,
        // VARCHAR(50) for the two numbers). Checked here rather than left to
        // MySQL, which in non-strict mode TRUNCATES silently — the user would
        // see a saved value quietly shorter than the one they typed.
        $max = in_array($f, ['phone', 'mobile'], true) ? 50 : 150;
        if ($v !== null && mb_strlen($v) > $max) {
            echo json_encode([
                'success' => false,
                'error'   => 'too_long',
                'field'   => $f,
                'max'     => $max,
            ]);
            exit;
        }
        $sets[] = "$f = ?";
        $args[] = $v;
    }

    // Nothing to write is a success, not an error: a save with no changed field
    // is a no-op, and an empty SET would be a syntax error.
    if ($sets) {
        $args[] = $_SESSION['ss_user_id'];
        $conn->prepare("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    }

    // Refresh the greeting only when the NAME was actually part of this save —
    // recomputing it on a contact-details-only save would fall into the `else`
    // branch and replace a perfectly good preferred name with the display name.
    if ($nameSent) {
        if ($preferredName !== '') {
            $_SESSION['ss_user_name'] = $preferredName;
        } else {
            // Fall back to display_name or email
            $userStmt = $conn->prepare("SELECT display_name, email FROM users WHERE id = ?");
            $userStmt->execute([$_SESSION['ss_user_id']]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);
            $_SESSION['ss_user_name'] = $user['display_name'] ?: $user['email'];
        }
    }

    echo json_encode([
        'success' => true,
        'display_name' => $_SESSION['ss_user_name']
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
