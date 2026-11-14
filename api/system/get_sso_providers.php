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
require_once '../../includes/sso_identity.php';

header('Content-Type: application/json');

if (!isset($_SESSION['analyst_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $conn = connectToDatabase();

    // 🔴 The #155 columns only once Database Verification has added them. A
    // query naming a missing column fails, and this page reads a failure as
    // "No providers yet". Until then, report what the old single switch meant.
    $jitCols = ssoJitColumnsReady($conn)
        ? 'p.auto_create_analysts, p.analyst_fallback_mode, p.profile_sync_mode'
        : "p.auto_create_users AS auto_create_analysts, 'block' AS analyst_fallback_mode, 'never' AS profile_sync_mode";
    $claimColsJit = ssoJitCompanyReady($conn) ? 'p.jit_company_access' : "'all' AS jit_company_access";
    // And the claim-mapping columns (3.5.0).
    require_once '../../includes/sso_claims.php';
    $claimCols = ssoClaimsReady($conn)
        ? 'p.oidc_group_claim, p.oidc_analyst_group, p.oidc_admin_group, p.oidc_groups_last_seen'
        : 'NULL AS oidc_group_claim, NULL AS oidc_analyst_group, NULL AS oidc_admin_group, NULL AS oidc_groups_last_seen';
    // Same for the GH #147 portal routing columns.
    $portalCols = ssoPortalRoutingColumnsReady($conn)
        ? 'p.portal_email_domains, p.portal_show_button'
        : 'NULL AS portal_email_domains, 1 AS portal_show_button';

    $stmt = $conn->query(
        "SELECT p.id, p.display_name, p.protocol, p.issuer_url, p.client_id, p.client_secret,
                p.scopes, p.enabled, p.auto_create_users, $jitCols, $portalCols, $claimCols, $claimColsJit, p.require_verified_email,
                p.default_modules, p.sort_order, p.tenant_id, t.name AS tenant_name,
                p.ldap_host, p.ldap_port, p.ldap_encryption, p.ldap_bind_dn, p.ldap_bind_password,
                p.ldap_base_dn, p.ldap_user_filter, p.ldap_attr_username, p.ldap_attr_email,
                p.ldap_attr_name, p.ldap_attr_guid,
                p.ldap_group_base_dn, p.ldap_group_filter, p.ldap_analyst_group, p.ldap_user_group,
                p.carddav_url, p.carddav_username, p.carddav_password,
                p.carddav_addressbook, p.carddav_auth,
                p.carddav_scope, p.carddav_scope_value, p.carddav_write_back,
                -- What deleting it would release: the people it still manages (#261).
                (SELECT COUNT(*) FROM users pu WHERE pu.auth_provider_id = p.id AND pu.is_managed = 1) AS people_count
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
            'auto_create_analysts' => (int)($r['auto_create_analysts'] ?? 0),
            'analyst_fallback_mode' => $r['analyst_fallback_mode'] ?? 'confirm',
            'require_verified_email' => (int)$r['require_verified_email'],
            'profile_sync_mode'      => $r['profile_sync_mode'] ?? 'never',
            // GH #147: newline-separated, as stored; the dialog shows one per line.
            'portal_email_domains'   => $r['portal_email_domains'] ?? null,
            'portal_show_button'     => (int)($r['portal_show_button'] ?? 1),
            'default_modules'   => $r['default_modules'],
            'jit_company_access'    => $r['jit_company_access'] ?: 'all',
            // SSO claim mapping (3.5.0) - see includes/sso_claims.php.
            'oidc_group_claim'      => $r['oidc_group_claim'],
            'oidc_analyst_group'    => $r['oidc_analyst_group'],
            'oidc_admin_group'      => $r['oidc_admin_group'],
            // Group -> team rows, and every group seen at sign-in for the dropdowns.
            'team_mappings'         => $r['protocol'] === 'oidc' ? ssoTeamMappings($conn, (int)$r['id']) : [],
            'groups_seen'           => $r['protocol'] === 'oidc' ? ssoSeenGroups($conn, (int)$r['id']) : [],
            'oidc_groups_last_seen' => $r['oidc_groups_last_seen'] ? json_decode($r['oidc_groups_last_seen'], true) : null,
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

            // --- CardDAV address book ---
            'carddav_url'         => $r['carddav_url'],
            'carddav_username'    => $r['carddav_username'],
            'carddav_addressbook' => $r['carddav_addressbook'],
            'carddav_auth'        => $r['carddav_auth'],
            'carddav_scope'       => $r['carddav_scope'],
            'carddav_scope_value' => $r['carddav_scope_value'],
            'carddav_write_back'  => (int)$r['carddav_write_back'] === 1,
            // ⚠️ And the same rule again for the third secret. The encrypted
            // value is selected above only so this flag can be computed — it
            // must not be added to the response, here or later.
            'has_carddav_password' => ($r['carddav_password'] !== null && $r['carddav_password'] !== ''),
            'people_count'         => (int)$r['people_count'],
        ];
    }

    echo json_encode(['success' => true, 'providers' => $providers]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
