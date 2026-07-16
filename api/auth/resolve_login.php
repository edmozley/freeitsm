<?php
/**
 * API: Resolve an email to a login method (for the email-first login router).
 * POST JSON { email, portal? }
 *
 * portal = 'analyst' (default) resolves against the analysts table;
 * portal = 'self-service' resolves against the self-service users table.
 *
 * Returns:
 *   { mode: 'sso', provider_id, provider_name }  — route straight to one IdP
 *   { mode: 'choose', providers: [{id,name}] }   — let the user pick (self-service,
 *                                                   multi-tenant, company has 2+ IdPs)
 *   { mode: 'local' }                             — email + password
 *
 * Resolution order (self-service): a per-user pin (the account is already
 * assigned to a provider) wins; otherwise, on a multi-tenant install, route by
 * the requester's company (email domain), which may have 0, 1 or several IdPs.
 *
 * Deliberately returns 'local' for unknown emails too, so this endpoint does
 * not reveal whether a given email has an account.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/functions.php';

header('Content-Type: application/json');

$data   = json_decode(file_get_contents('php://input'), true);
$email  = strtolower(trim($data['email'] ?? ''));
$portal = ($data['portal'] ?? '') === 'self-service' ? 'self-service' : 'analyst';

$resp = ['mode' => 'local'];

if ($email !== '') {
    try {
        $conn = connectToDatabase();
        $ssoOn = (($conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'sso_enabled'")->fetchColumn()) ?: '0') === '1';
        if ($ssoOn) {
            // (1) Per-user pin — an account already assigned to a provider routes
            // straight there. (This is also what makes "mixed IdPs in one company"
            // automatic after the first login.) Each portal reads its own table.
            // protocol='oidc' throughout: only OIDC has somewhere to redirect to.
            // An account pinned to an LDAP provider deliberately falls through to
            // 'local' — they type their directory password into the ordinary form
            // and login.php checks it by bind. Routing them to 'sso' would send the
            // browser to the OIDC flow with no issuer URL to discover.
            if ($portal === 'self-service') {
                $sql = "SELECT u.auth_provider_id AS pid, p.display_name
                          FROM users u
                          JOIN auth_providers p ON p.id = u.auth_provider_id
                         WHERE LOWER(u.email) = ? AND p.enabled = 1 AND p.protocol = 'oidc'
                         LIMIT 1";
            } else {
                $sql = "SELECT a.auth_provider_id AS pid, p.display_name
                          FROM analysts a
                          JOIN auth_providers p ON p.id = a.auth_provider_id
                         WHERE LOWER(a.email) = ? AND a.is_active = 1 AND p.enabled = 1 AND p.protocol = 'oidc'
                         LIMIT 1";
            }
            $stmt = $conn->prepare($sql);
            $stmt->execute([$email]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $resp = [
                    'mode'          => 'sso',
                    'provider_id'   => (int)$row['pid'],
                    'provider_name' => $row['display_name'],
                ];
            } elseif ($portal === 'self-service') {
                // (2) Not pinned → on a multi-tenant install, route by the
                // requester's company (email domain). The company may have 0, 1
                // or several IdPs.
                require_once '../../includes/tenancy.php';
                if (isMultiTenant($conn)) {
                    $tenantId = resolveTenantIdForAddress($conn, $email);
                    if ($tenantId !== null) {
                        $ps = $conn->prepare(
                            "SELECT id, display_name FROM auth_providers
                              WHERE tenant_id = ? AND enabled = 1 AND protocol = 'oidc'
                              ORDER BY sort_order, display_name"
                        );
                        $ps->execute([$tenantId]);
                        $provs = $ps->fetchAll(PDO::FETCH_ASSOC);
                        if (count($provs) === 1) {
                            $resp = [
                                'mode'          => 'sso',
                                'provider_id'   => (int)$provs[0]['id'],
                                'provider_name' => $provs[0]['display_name'],
                            ];
                        } elseif (count($provs) > 1) {
                            $resp = [
                                'mode'      => 'choose',
                                'providers' => array_map(function ($p) {
                                    return ['id' => (int)$p['id'], 'name' => $p['display_name']];
                                }, $provs),
                            ];
                        }
                        // 0 providers for this company → fall through to local.
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Any failure -> fall back to local login.
    }
}

echo json_encode($resp);
