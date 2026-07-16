<?php
/**
 * API: List configured authentication providers (OIDC and LDAP).
 * GET - returns every provider. Secrets are NEVER returned in plaintext; only
 * `has_secret` / `has_bind_password` flags tell the UI whether one is stored.
 *
 * Admin-gated: this returns sign-in infrastructure detail (issuer URLs,
 * directory hostnames, service-account bind DNs) that a normal analyst has no
 * reason to see. Only the admin-only System > Authentication page calls it.
 */
session_start(['read_and_close' => true]);
require_once '../../config.php';
require_once '../../includes/admin_api_guard.php'; // System admins only (issue #34)
require_once '../../includes/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    $stmt = $conn->query(
        "SELECT p.id, p.display_name, p.protocol, p.issuer_url, p.client_id, p.client_secret,
                p.scopes, p.enabled, p.auto_create_users, p.require_verified_email,
                p.default_modules, p.sort_order, p.tenant_id, t.name AS tenant_name,
                p.ldap_host, p.ldap_port, p.ldap_encryption, p.ldap_bind_dn, p.ldap_bind_password,
                p.ldap_base_dn, p.ldap_user_filter, p.ldap_attr_username, p.ldap_attr_email,
                p.ldap_attr_name, p.ldap_attr_guid,
                p.ldap_group_base_dn, p.ldap_group_filter, p.ldap_analyst_group, p.ldap_user_group
           FROM auth_providers p
           LEFT JOIN tenants t ON t.id = p.tenant_id
          ORDER BY p.sort_order, p.display_name"
    );

    $providers = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $providers[] = [
            'id'                => (int)$r['id'],
            'display_name'      => $r['display_name'],
            'protocol'          => $r['protocol'],
            'issuer_url'        => $r['issuer_url'],
            'client_id'         => $r['client_id'],
            'scopes'            => $r['scopes'],
            'enabled'           => (int)$r['enabled'],
            'auto_create_users' => (int)$r['auto_create_users'],
            'require_verified_email' => (int)$r['require_verified_email'],
            'default_modules'   => $r['default_modules'],
            'sort_order'        => (int)$r['sort_order'],
            // Which client company owns this IdP (null = global / MSP-internal).
            'tenant_id'         => isset($r['tenant_id']) ? (int)$r['tenant_id'] : null,
            'tenant_name'       => $r['tenant_name'] ?? null,
            // Boolean flag only — the encrypted secret itself never leaves the server.
            'has_secret'        => ($r['client_secret'] !== null && $r['client_secret'] !== ''),
            // --- LDAP / Active Directory ---
            'ldap_host'          => $r['ldap_host'],
            'ldap_port'          => isset($r['ldap_port']) ? (int)$r['ldap_port'] : null,
            'ldap_encryption'    => $r['ldap_encryption'],
            'ldap_bind_dn'       => $r['ldap_bind_dn'],
            'ldap_base_dn'       => $r['ldap_base_dn'],
            'ldap_user_filter'   => $r['ldap_user_filter'],
            'ldap_attr_username' => $r['ldap_attr_username'],
            'ldap_attr_email'    => $r['ldap_attr_email'],
            'ldap_attr_name'     => $r['ldap_attr_name'],
            'ldap_attr_guid'     => $r['ldap_attr_guid'],
            'ldap_group_base_dn' => $r['ldap_group_base_dn'],
            'ldap_group_filter'  => $r['ldap_group_filter'],
            'ldap_analyst_group' => $r['ldap_analyst_group'],
            'ldap_user_group'    => $r['ldap_user_group'],
            // Same rule as the OIDC secret: flag only, never the password itself.
            'has_bind_password'  => ($r['ldap_bind_password'] !== null && $r['ldap_bind_password'] !== ''),
        ];
    }

    echo json_encode(['success' => true, 'providers' => $providers]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
