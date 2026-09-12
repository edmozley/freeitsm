<?php
/**
 * API: Test a CardDAV address book's settings.
 * POST JSON { id?, carddav_url, carddav_username, carddav_password, carddav_auth }
 *
 * Proves, in one PROPFIND, that we can reach the server, that the account can
 * sign in, and that the URL is a CardDAV collection — then lists the address
 * books it can see, which is the answer to the question an operator actually
 * has: *which* book should I point this at?
 *
 * ⭐ It also reports WHICH AUTHENTICATION SCHEME the server offered. That is
 * not trivia. A stock Baikal — the standard sabre/dav server — ships Digest,
 * and Basic against it is a flat 401 that reads as a wrong password. Naming
 * the scheme turns "authentication failed" into something actionable.
 *
 * Like the LDAP test, this returns the REAL error rather than a sanitised one:
 * an admin debugging their own server needs the detail, and the endpoint is
 * already restricted to System admins.
 *
 * A blank or masked password means "use the one already stored for provider
 * `id`", so Test works without re-typing the secret.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/carddav.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode([
        'success' => false,
        'error'   => 'The PHP "curl" extension is not enabled on this server, so FreeITSM cannot reach a CardDAV server. Enable extension=curl in php.ini and restart the web server.',
    ]);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Invalid request data']);
    exit;
}

try {
    $conn = connectToDatabase();

    $password = $data['carddav_password'] ?? '';
    if (isMaskedNoChangeValue($password) && !empty($data['id'])) {
        // Reuse the stored password rather than making the admin retype it.
        $stmt = $conn->prepare("SELECT carddav_password FROM auth_providers WHERE id = ? AND protocol = 'carddav'");
        $stmt->execute([(int)$data['id']]);
        $stored = (string)($stmt->fetchColumn() ?: '');
        $password = $stored !== '' ? decryptValue($stored) : '';
    }

    $url  = trim($data['carddav_url'] ?? '');
    $user = trim($data['carddav_username'] ?? '');
    // ⚠️ Read the key ONCE. Written as
    //   in_array($data['x'] ?? 'auto', …) ? $data['x'] : 'auto'
    // the `??` guards only the condition, and the true branch then reads the
    // key again unguarded — so an absent key emitted an "Undefined array key"
    // warning. Harmless-looking, and fatal in practice: the warning is printed
    // BEFORE the JSON body, so every caller's JSON.parse() fails on a response
    // that otherwise says exactly the right thing. Same family as "a PHP fatal
    // is served as HTTP 200" — the status and the body disagree.
    $authInput = $data['carddav_auth'] ?? 'auto';
    $auth = in_array($authInput, ['auto', 'digest', 'basic'], true) ? $authInput : 'auto';

    if ($url === '') {
        echo json_encode(['success' => false, 'error' => 'Enter the address of your CardDAV server first.']);
        exit;
    }
    if (!preg_match('#^https?://#i', $url)) {
        echo json_encode([
            'success' => false,
            'error'   => 'The address must start with http:// or https://.',
        ]);
        exit;
    }

    $cfg = ['url' => $url, 'username' => $user, 'password' => $password, 'auth' => $auth];
    $res = cardDavListAddressBooks($cfg);

    if (!$res['ok']) {
        echo json_encode([
            'success'    => false,
            'error'      => $res['error'],
            'status'     => $res['status'],
            'auth_offered' => $res['auth'],
        ]);
        exit;
    }

    // ⚠️ Parsed fine but found nothing is NOT an error, and must not be
    // reported as one. It means the credentials and the URL are both right and
    // this account genuinely has no address books — or, much more likely, that
    // the URL points one level too high or too low. Say which, rather than
    // making somebody re-check a password that works.
    if (!$res['books']) {
        echo json_encode([
            'success'      => true,
            'books'        => [],
            'auth_offered' => $res['auth'],
            'warning'      => 'FreeITSM reached the server and signed in successfully, but found no address books at that address. '
                            . 'This usually means the address points at the wrong level — for a Baikal or sabre/dav server it should '
                            . 'normally end in /dav.php/addressbooks/<username>/ rather than at a single book or at the server root.',
        ]);
        exit;
    }

    $out = [
        'success'      => true,
        'books'        => $res['books'],
        'auth_offered' => $res['auth'],
        // Plain-language summary, because the caller renders this straight into
        // a status line and shouldn't have to build the sentence itself.
        'message'      => count($res['books']) === 1
            ? 'Connected, and found one address book.'
            : 'Connected, and found ' . count($res['books']) . ' address books.',
    ];

    // If a book is already chosen, look INSIDE it and report what it can be
    // scoped by — the groups and the categories it actually contains.
    //
    // ⭐ This is the point of doing it here rather than asking the operator to
    // type a group name: "a specific contact group" means three different
    // things in CardDAV, and rather than guessing which one their server uses,
    // FreeITSM reads the book and offers whatever is really in it. If the
    // answer is "no groups and no categories", that is a useful answer too —
    // it means the whole book is the only sensible scope.
    $book = trim($data['carddav_addressbook'] ?? '');
    if ($book !== '') {
        $scan = cardDavScanBook($cfg, $book);
        if ($scan['ok']) {
            $out['scan'] = [
                'contacts'   => $scan['contacts'],
                'groups'     => $scan['groups'],
                'categories' => $scan['categories'],
            ];
        } else {
            // ⚠️ A failed scan must not fail the whole test. The connection is
            // proven by this point; not being able to read one book's contents
            // is a smaller, separate problem, and reporting it as "connection
            // failed" would send somebody back to their password again.
            $out['scan_error'] = $scan['error'];
        }
    }

    echo json_encode($out);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
