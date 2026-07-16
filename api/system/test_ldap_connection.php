<?php
/**
 * API: Test an LDAP / Active Directory provider's settings.
 * POST JSON { id?, ldap_host, ldap_port, ldap_encryption, ldap_bind_dn,
 *             ldap_bind_password, ldap_base_dn, ldap_user_filter, ldap_attr_*,
 *             test_username?, test_password? }
 *
 * Proves, in order: we can reach the server, the service account can bind, and
 * the base DN is readable. If a sample username/password is supplied it also
 * runs a full end-to-end authentication and reports which attributes came back
 * — the fastest way to spot a wrong attribute name or filter.
 *
 * Unlike the login form, this returns the REAL error. That is the point: an
 * admin debugging their own directory needs the detail, and this endpoint is
 * already restricted to System admins.
 *
 * A blank/masked bind password means "use the one already stored for provider
 * `id`", so Test works without re-typing the secret.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';
require_once '../../includes/encryption.php';
require_once '../../includes/ldap.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!ldapExtensionAvailable()) {
    echo json_encode([
        'success' => false,
        'error'   => 'The PHP "ldap" extension is not enabled on this server. '
                   . 'Enable extension=ldap in php.ini and restart the web server.',
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

    $bindPassword = $data['ldap_bind_password'] ?? '';
    if (isMaskedNoChangeValue($bindPassword) && !empty($data['id'])) {
        // Reuse the stored password rather than making the admin retype it.
        $existing = ldapGetProvider($conn, (int)$data['id']);
        $bindPassword = $existing ? (string)$existing['ldap_bind_password'] : '';
    }

    $provider = [
        'ldap_host'          => trim($data['ldap_host'] ?? ''),
        'ldap_port'          => (int)($data['ldap_port'] ?? 0),
        'ldap_encryption'    => $data['ldap_encryption'] ?? 'none',
        'ldap_bind_dn'       => trim($data['ldap_bind_dn'] ?? ''),
        'ldap_bind_password' => $bindPassword,
        'ldap_base_dn'       => trim($data['ldap_base_dn'] ?? ''),
        'ldap_user_filter'   => trim($data['ldap_user_filter'] ?? ''),
        'ldap_attr_username' => trim($data['ldap_attr_username'] ?? ''),
        'ldap_attr_email'    => trim($data['ldap_attr_email'] ?? ''),
        'ldap_attr_name'     => trim($data['ldap_attr_name'] ?? ''),
        'ldap_attr_guid'     => trim($data['ldap_attr_guid'] ?? ''),
        'ldap_group_base_dn' => trim($data['ldap_group_base_dn'] ?? ''),
        'ldap_group_filter'  => trim($data['ldap_group_filter'] ?? ''),
        'ldap_analyst_group' => trim($data['ldap_analyst_group'] ?? ''),
        'ldap_user_group'    => trim($data['ldap_user_group'] ?? ''),
    ];

    if ($provider['ldap_host'] === '' || $provider['ldap_base_dn'] === '') {
        echo json_encode(['success' => false, 'error' => 'Enter a server and a base DN first.']);
        exit;
    }

    $testUser = trim($data['test_username'] ?? '');
    $testPass = (string)($data['test_password'] ?? '');

    $res = ldapTestConnection($provider, $testUser, $testPass);

    if (!$res['ok']) {
        echo json_encode(['success' => false, 'error' => $res['error']]);
        exit;
    }

    if (empty($res['found'])) {
        echo json_encode(['success' => true, 'message' => 'Connected, and the service account can read the base DN.']);
        exit;
    }

    // Report what we actually read back, so a wrong attribute name is obvious.
    // `role` is the decisive bit: a user can authenticate perfectly and still be
    // denied because they are in neither group, and that must be visible here
    // rather than discovered by a confused user at the login form.
    $u = $res['found'];
    $groupNames = [];
    foreach (($u['groups'] ?? []) as $g) {
        $groupNames[] = $g['cn'] !== '' ? $g['cn'] : $g['dn'];
    }
    echo json_encode([
        'success' => true,
        'message' => 'Authenticated ' . $u['username'] . ' successfully.',
        'found'   => [
            'dn'       => $u['dn'],
            'username' => $u['username'],
            'email'    => $u['email'],
            'name'     => $u['name'],
            'guid'     => $u['guid'],
            'groups'   => $groupNames,
            'role'     => ldapAccessRole($provider, $u),
        ],
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
