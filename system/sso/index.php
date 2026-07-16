<?php
/**
 * System - Single Sign-On (SSO / OIDC)
 * Configure OpenID Connect identity providers + global SSO switches.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/tenancy.php';

$current_page = 'sso';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

// On a multi-tenant install a provider can be owned by a client company.
$ssoTenants = [];
$ssoMultiTenant = false;
try {
    $ssoAdminConn = connectToDatabase();
    $ssoMultiTenant = isMultiTenant($ssoAdminConn);
    if ($ssoMultiTenant) {
        $ssoTenants = $ssoAdminConn->query("SELECT id, name FROM tenants WHERE is_active = 1 ORDER BY is_default DESC, name")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) { $ssoTenants = []; $ssoMultiTenant = false; }
$ssoColspan = $ssoMultiTenant ? 7 : 6; // providers table column count (Company col only at N>1; Type always)

// The redirect URI the admin must register in their IdP. Built from the
// deployment's BASE_URL so it's correct whatever path the app is served at.
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$redirectUri = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL . 'api/auth/oidc_callback.php';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.sso.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=22">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <style>
        /* System module accent (blue-grey) — pin the generic --accent to it. */
        body {
            /* System is the FIRST module whose DARK accent is a LIGHT colour (#90a4ae).
               inbox.css renders .btn-primary/.add-btn as background:var(--accent) +
               color:var(--on-accent) — and the global --on-accent stays WHITE in dark.
               So pinning --accent alone would put white text on a light button. Pin
               --on-accent too: it flips to near-black in dark. */
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }

        .sso-container { height: calc(100vh - 48px); overflow-y: auto; padding: 30px 20px; }
        .page-title { font-size: 22px; font-weight: 600; color: var(--text, #333); margin: 0 0 6px 0; }
        .page-subtitle { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 30px 0; }

        .settings-card { background: var(--surface, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px var(--shadow, rgba(0,0,0,0.08)); margin-bottom: 24px; }
        .settings-card h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px 0; }
        .settings-card .card-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 20px 0; line-height: 1.5; }

        .setting-row { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
        .setting-row:last-child { margin-bottom: 0; }
        .setting-label { flex: 1; font-size: 13px; color: var(--text-muted, #555); }
        .setting-label strong { display: block; color: var(--text, #333); margin-bottom: 2px; }

        /* Toggle switch */
        .switch { position: relative; display: inline-block; width: 44px; height: 24px; flex: none; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .switch .slider { position: absolute; cursor: pointer; inset: 0; background: #ccc; border-radius: 24px; transition: .2s; }
        .switch .slider:before { content: ""; position: absolute; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: .2s; }
        .switch input:checked + .slider { background: var(--sys-accent, #546e7a); }
        .switch input:checked + .slider:before { transform: translateX(20px); }

        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; transition: all 0.15s; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: #455a64; }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: #455a64; }
        .btn-secondary:hover { background: #cfd8dc; }
        .btn-test { background: var(--surface, #fff); color: var(--sys-accent, #546e7a); border: 1px solid #cfd8dc; }
        .btn-test:hover { background: #f5f7fa; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .save-area { margin-top: 24px; }

        .info-note { background: #f5f7fa; border: 1px solid var(--border, #e0e0e0); border-radius: 6px; padding: 14px 16px; font-size: 12px; color: var(--text-muted, #666); line-height: 1.6; }
        .info-note strong { color: var(--text, #333); }
        .redirect-uri-box { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
        .redirect-uri-box code { flex: 1; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 8px 10px; font-size: 12px; color: var(--text, #333); overflow-x: auto; white-space: nowrap; }

        /* Providers table */
        .providers-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .add-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); border: none; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .add-btn:hover { background: #455a64; }
        table.providers { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.providers th { text-align: left; color: var(--text-dim, #888); font-weight: 600; font-size: 12px; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        table.providers td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: middle; }
        table.providers tr:last-child td { border-bottom: none; }
        .issuer-cell { color: var(--text-dim, #888); font-size: 12px; max-width: 320px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .status-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .status-badge.on { background: #e8f5e9; color: #2e7d32; }
        .status-badge.off { background: #f0f0f0; color: #999; }
        .badge-jit { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #e3f2fd; color: #1565c0; }
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 4px 8px; font-size: 13px; border-radius: 4px; }
        .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); }
        .table-action-btn.danger:hover { background: #ffebee; color: #c62828; }
        .empty-row td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }

        /* Modal — namespaced (sso-) so it doesn't inherit inbox.css's global .modal
           framework, whose .modal rule sets opacity:0/visibility:hidden by default. */
        .sso-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .sso-modal-overlay.open { display: flex; }
        .sso-modal { background: var(--surface, #fff); border-radius: 10px; width: 560px; max-width: 92vw; max-height: 90vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .sso-modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .sso-modal-body { padding: 20px 24px; }
        .sso-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; }
        /* The four attribute mappings sit two-up; they collapse to one column on narrow screens. */
        /* Encryption + port share a row: the select flexes, the port stays narrow.
           Needed because .form-field select is width:100%, which would otherwise
           squash the port box to nothing. */
        .enc-row { display: flex; gap: 10px; align-items: center; }
        .enc-row select { flex: 1 1 auto; }
        .enc-row input[type=number] { flex: 0 0 110px; width: 110px; }
        .ldap-attr-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; }
        .ldap-attr-grid .hint { display: block; margin-bottom: 2px; }
        .proto-badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 11px; font-weight: 600; background: var(--surface-alt, #eef1f4); color: var(--text-muted, #667); }
        .form-field { margin-bottom: 16px; }
        .form-field label { display: block; font-size: 13px; font-weight: 600; color: var(--text, #444); margin-bottom: 4px; }
        .form-field .hint { font-size: 12px; color: var(--text-faint, #999); font-weight: 400; margin-bottom: 6px; }
        .form-field input[type=text], .form-field input[type=password], .form-field input[type=number], .form-field select { width: 100%; padding: 9px 11px; border: 1px solid var(--border, #ddd); border-radius: 5px; font-size: 13px; font-family: inherit; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); }
        .form-field input:focus, .form-field select:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .issuer-row { display: flex; gap: 8px; }
        .issuer-row input { flex: 1; }
        .checkbox-field { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 14px; }
        .checkbox-field input { margin-top: 3px; }
        .checkbox-field .cb-label { font-size: 13px; color: var(--text, #444); }
        .checkbox-field .cb-label strong { display: block; }
        .checkbox-field .cb-label span { color: var(--text-faint, #999); font-size: 12px; }
        .test-result { margin-top: 6px; font-size: 12px; padding: 8px 10px; border-radius: 5px; display: none; }
        .test-result.ok { display: block; background: #e8f5e9; color: #2e7d32; }
        .test-result.err { display: block; background: #ffebee; color: #c62828; }
        .jit-off { color: #bbb; }
        .tenant-global { color: var(--text-faint, #999); }

        /* ---------- Dark mode overrides ----------
           Light values above are deliberately unchanged; these only apply in dark. */
        [data-theme-mode="dark"] .switch .slider { background: #4a5058; }
        [data-theme-mode="dark"] .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .btn-secondary { color: #cfd8dc; }
        [data-theme-mode="dark"] .btn-secondary:hover { background: #37474f; }
        [data-theme-mode="dark"] .btn-test { border-color: #455a64; }
        [data-theme-mode="dark"] .btn-test:hover { background: #2a3039; }
        [data-theme-mode="dark"] .info-note { background: #20242b; }
        [data-theme-mode="dark"] .add-btn:hover { background: var(--sys-accent-hover, #455a64); }
        [data-theme-mode="dark"] .status-badge.on { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .status-badge.off { background: #2a3039; color: #8b95a3; }
        [data-theme-mode="dark"] .badge-jit { background: #14324a; color: #7fc4f5; }
        [data-theme-mode="dark"] .jit-off { color: #6b7280; }
        [data-theme-mode="dark"] .table-action-btn { color: #90a4ae; }
        [data-theme-mode="dark"] .table-action-btn.danger:hover { background: #3a1a1d; color: #fca5a5; }
        [data-theme-mode="dark"] .test-result.ok { background: #16331f; color: #86efac; }
        [data-theme-mode="dark"] .test-result.err { background: #3a1a1d; color: #fca5a5; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="sso-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.sso.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.sso.subtitle')); ?></p>

        <!-- Global switches -->
        <div class="settings-card">
            <h3><?php echo htmlspecialchars(t('system.sso.global_heading')); ?></h3>
            <p class="card-desc"><?php echo htmlspecialchars(t('system.sso.global_desc')); ?></p>
            <div class="setting-row">
                <div class="setting-label">
                    <strong><?php echo htmlspecialchars(t('system.sso.enable_sso')); ?></strong>
                    <?php echo htmlspecialchars(t('system.sso.enable_sso_desc')); ?>
                </div>
                <label class="switch"><input type="checkbox" id="ssoEnabled"><span class="slider"></span></label>
            </div>
            <div class="setting-row">
                <div class="setting-label">
                    <strong><?php echo htmlspecialchars(t('system.sso.allow_local')); ?></strong>
                    <?php echo htmlspecialchars(t('system.sso.allow_local_desc')); ?>
                </div>
                <label class="switch"><input type="checkbox" id="localLoginEnabled"><span class="slider"></span></label>
            </div>
            <div class="save-area"><button class="btn btn-primary" id="saveGlobalBtn"><?php echo htmlspecialchars(t('system.sso.save')); ?></button></div>
        </div>

        <!-- Redirect URI -->
        <div class="settings-card">
            <h3><?php echo htmlspecialchars(t('system.sso.redirect_heading')); ?></h3>
            <p class="card-desc"><?php echo htmlspecialchars(t('system.sso.redirect_desc')); ?></p>
            <div class="redirect-uri-box">
                <code id="redirectUri"><?php echo htmlspecialchars($redirectUri); ?></code>
                <button class="btn btn-secondary" id="copyRedirectBtn"><?php echo htmlspecialchars(t('system.sso.copy')); ?></button>
            </div>
        </div>

        <!-- Providers -->
        <div class="settings-card">
            <div class="providers-head">
                <div>
                    <h3 style="margin:0;"><?php echo htmlspecialchars(t('system.sso.providers_heading')); ?></h3>
                    <p class="card-desc" style="margin:4px 0 0;"><?php echo htmlspecialchars(t('system.sso.providers_desc')); ?></p>
                </div>
                <button class="add-btn" id="addProviderBtn"><?php echo htmlspecialchars(t('system.sso.add')); ?></button>
            </div>
            <table class="providers">
                <thead>
                    <tr><th><?php echo htmlspecialchars(t('system.sso.col_name')); ?></th><?php if ($ssoMultiTenant): ?><th><?php echo htmlspecialchars(t('system.sso.col_company')); ?></th><?php endif; ?><th><?php echo htmlspecialchars(t('system.sso.col_type')); ?></th><th><?php echo htmlspecialchars(t('system.sso.col_issuer')); ?></th><th><?php echo htmlspecialchars(t('system.sso.col_status')); ?></th><th><?php echo htmlspecialchars(t('system.sso.col_auto_create')); ?></th><th style="text-align:right;"><?php echo htmlspecialchars(t('system.sso.col_actions')); ?></th></tr>
                </thead>
                <tbody id="providersBody">
                    <tr class="empty-row"><td colspan="<?php echo $ssoColspan; ?>"><?php echo htmlspecialchars(t('system.sso.loading')); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Add/Edit modal -->
    <div class="sso-modal-overlay" id="providerModal">
        <div class="sso-modal">
            <div class="sso-modal-header" id="modalTitle"><?php echo htmlspecialchars(t('system.sso.modal_add_title')); ?></div>
            <div class="sso-modal-body">
                <input type="hidden" id="providerId">
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_protocol')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_protocol_hint')); ?></div>
                    <select id="fProtocol">
                        <option value="oidc"><?php echo htmlspecialchars(t('system.sso.protocol_oidc')); ?></option>
                        <option value="ldap"><?php echo htmlspecialchars(t('system.sso.protocol_ldap')); ?></option>
                    </select>
                    <?php if (!extension_loaded('ldap')): ?>
                        <div class="hint" style="color:var(--danger,#c0392b);margin-top:6px;"><?php echo htmlspecialchars(t('system.sso.ldap_ext_missing')); ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_display_name')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_display_name_hint')); ?></div>
                    <input type="text" id="fDisplayName" placeholder="<?php echo htmlspecialchars(t('system.sso.field_display_name_placeholder')); ?>">
                </div>

                <!-- ===== LDAP / Active Directory ===== -->
                <div id="ldapFields" style="display:none;">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.ldap_preset')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.ldap_preset_hint')); ?></div>
                        <div class="issuer-row">
                            <button class="btn btn-secondary" id="presetAdBtn" type="button"><?php echo htmlspecialchars(t('system.sso.ldap_preset_ad')); ?></button>
                            <button class="btn btn-secondary" id="presetOpenldapBtn" type="button"><?php echo htmlspecialchars(t('system.sso.ldap_preset_openldap')); ?></button>
                        </div>
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_host')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_host_hint')); ?></div>
                        <input type="text" id="fLdapHost" placeholder="dc1.example.local">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_encryption')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.ldap_enc_hint')); ?></div>
                        <div class="enc-row">
                            <select id="fLdapEncryption">
                                <option value="none"><?php echo htmlspecialchars(t('system.sso.ldap_enc_none')); ?></option>
                                <option value="starttls"><?php echo htmlspecialchars(t('system.sso.ldap_enc_starttls')); ?></option>
                                <option value="ldaps"><?php echo htmlspecialchars(t('system.sso.ldap_enc_ldaps')); ?></option>
                            </select>
                            <input type="number" id="fLdapPort" placeholder="389" aria-label="<?php echo htmlspecialchars(t('system.sso.field_ldap_port')); ?>">
                        </div>
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_dn')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_dn_hint')); ?></div>
                        <input type="text" id="fLdapBindDn" placeholder="svc-freeitsm@example.local">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_password')); ?></label>
                        <div class="hint" id="bindPasswordHint"><?php echo htmlspecialchars(t('system.sso.field_ldap_bind_password_hint')); ?></div>
                        <input type="password" id="fLdapBindPassword" autocomplete="new-password">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_base_dn')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_base_dn_hint')); ?></div>
                        <input type="text" id="fLdapBaseDn" placeholder="DC=example,DC=local">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_ldap_filter')); ?></label>
                        <div class="hint"><?php echo t('system.sso.field_ldap_filter_hint', ['token' => '<code>%s</code>']); ?></div>
                        <input type="text" id="fLdapFilter" placeholder="(&amp;(objectClass=user)(|(sAMAccountName=%s)(mail=%s)))">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.ldap_attrs_heading')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.ldap_attrs_desc')); ?></div>
                        <div class="ldap-attr-grid">
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_attr_username')); ?></span>
                                <input type="text" id="fLdapAttrUsername" placeholder="sAMAccountName">
                            </div>
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_attr_email')); ?></span>
                                <input type="text" id="fLdapAttrEmail" placeholder="mail">
                            </div>
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_attr_name')); ?></span>
                                <input type="text" id="fLdapAttrName" placeholder="displayName">
                            </div>
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_attr_guid')); ?></span>
                                <input type="text" id="fLdapAttrGuid" placeholder="objectGUID">
                            </div>
                        </div>
                        <div class="hint" style="margin-top:6px;"><?php echo t('system.sso.field_ldap_attr_guid_hint', ['ad' => '<code>objectGUID</code>', 'openldap' => '<code>entryUUID</code>']); ?></div>
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.ldap_groups_heading')); ?></label>
                        <div class="hint"><?php echo t('system.sso.ldap_groups_desc', ['strong' => '<strong>' . htmlspecialchars(t('system.sso.ldap_groups_desc_strong')) . '</strong>']); ?></div>
                        <div class="ldap-attr-grid">
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_analyst_group')); ?></span>
                                <input type="text" id="fLdapAnalystGroup" placeholder="ITSM-Analysts">
                            </div>
                            <div>
                                <span class="hint"><?php echo htmlspecialchars(t('system.sso.field_ldap_user_group')); ?></span>
                                <input type="text" id="fLdapUserGroup" placeholder="ITSM-Users">
                            </div>
                        </div>
                        <div class="hint" style="margin-top:8px;"><?php echo htmlspecialchars(t('system.sso.field_ldap_group_filter')); ?></div>
                        <input type="text" id="fLdapGroupFilter" placeholder="(&amp;(objectClass=group)(member=%s))">
                        <div class="hint" style="margin-top:8px;"><?php echo htmlspecialchars(t('system.sso.field_ldap_group_base_dn')); ?></div>
                        <input type="text" id="fLdapGroupBaseDn" placeholder="<?php echo htmlspecialchars(t('system.sso.field_ldap_group_base_dn_placeholder')); ?>">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.ldap_test_heading')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.ldap_test_desc')); ?></div>
                        <div class="issuer-row">
                            <input type="text" id="fLdapTestUser" placeholder="<?php echo htmlspecialchars(t('system.sso.ldap_test_user')); ?>">
                            <input type="password" id="fLdapTestPass" placeholder="<?php echo htmlspecialchars(t('system.sso.ldap_test_pass')); ?>" autocomplete="new-password">
                            <button class="btn btn-test" id="testLdapBtn" type="button"><?php echo htmlspecialchars(t('system.sso.test')); ?></button>
                        </div>
                        <div class="test-result" id="ldapTestResult"></div>
                    </div>
                </div>

                <!-- ===== OpenID Connect ===== -->
                <div id="oidcFields">
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_issuer')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_issuer_hint')); ?></div>
                    <div class="issuer-row">
                        <input type="text" id="fIssuerUrl" placeholder="<?php echo htmlspecialchars(t('system.sso.field_issuer_placeholder')); ?>">
                        <button class="btn btn-test" id="testDiscoveryBtn" type="button"><?php echo htmlspecialchars(t('system.sso.test')); ?></button>
                    </div>
                    <div class="test-result" id="testResult"></div>
                </div>
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_client_id')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_client_id_hint')); ?></div>
                    <input type="text" id="fClientId" placeholder="freeitsm-app">
                </div>
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_client_secret')); ?></label>
                    <div class="hint" id="secretHint"><?php echo htmlspecialchars(t('system.sso.field_client_secret_hint')); ?></div>
                    <input type="password" id="fClientSecret" placeholder="" autocomplete="new-password">
                </div>
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_scopes')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_scopes_hint')); ?></div>
                    <input type="text" id="fScopes" value="openid email profile">
                </div>
                </div><!-- /#oidcFields -->
                <div class="checkbox-field">
                    <input type="checkbox" id="fEnabled" checked>
                    <div class="cb-label"><strong><?php echo htmlspecialchars(t('system.sso.cb_enabled')); ?></strong><span><?php echo htmlspecialchars(t('system.sso.cb_enabled_desc')); ?></span></div>
                </div>
                <div class="checkbox-field">
                    <input type="checkbox" id="fAutoCreate">
                    <div class="cb-label"><strong><?php echo htmlspecialchars(t('system.sso.cb_autocreate')); ?></strong><span><?php echo htmlspecialchars(t('system.sso.cb_autocreate_desc')); ?></span></div>
                </div>
                <!-- OIDC-only: LDAP has no email_verified claim to require. -->
                <div class="checkbox-field" id="requireVerifiedField">
                    <input type="checkbox" id="fRequireVerified">
                    <div class="cb-label"><strong><?php echo htmlspecialchars(t('system.sso.cb_verified')); ?></strong><span><?php echo t('system.sso.cb_verified_desc', ['claim' => '<code>email_verified: true</code>', 'claim_false' => '<code>email_verified: false</code>']); ?></span></div>
                </div>
                <div class="form-field" id="defaultModulesField">
                    <label><?php echo htmlspecialchars(t('system.sso.field_default_modules')); ?></label>
                    <div class="hint"><?php echo t('system.sso.field_default_modules_hint', ['example' => '<code>tickets, knowledge</code>', 'strong' => '<strong>' . htmlspecialchars(t('system.sso.field_default_modules_strong')) . '</strong>']); ?></div>
                    <input type="text" id="fDefaultModules" placeholder="<?php echo htmlspecialchars(t('system.sso.field_default_modules_placeholder')); ?>">
                </div>
                <?php if ($ssoMultiTenant): ?>
                <div class="form-field" id="tenantField">
                    <label><?php echo htmlspecialchars(t('system.sso.field_company')); ?></label>
                    <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_company_hint')); ?></div>
                    <select id="fTenant">
                        <option value=""><?php echo htmlspecialchars(t('system.sso.field_company_global')); ?></option>
                        <?php foreach ($ssoTenants as $tn): ?>
                            <option value="<?php echo (int)$tn['id']; ?>"><?php echo htmlspecialchars($tn['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="sso-modal-footer">
                <button class="btn btn-secondary" id="cancelModalBtn" type="button"><?php echo htmlspecialchars(t('system.sso.cancel')); ?></button>
                <button class="btn btn-primary" id="saveProviderBtn" type="button"><?php echo htmlspecialchars(t('system.sso.save')); ?></button>
            </div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    const MULTI_TENANT = <?php echo $ssoMultiTenant ? 'true' : 'false'; ?>;
    const SSO_COLSPAN = <?php echo $ssoColspan; ?>;
    let providers = [];

    // ---------- Global switches ----------
    async function loadGlobal() {
        try {
            const r = await fetch(API + 'settings/get_system_settings.php');
            const d = await r.json();
            if (d.success) {
                document.getElementById('ssoEnabled').checked = d.settings.sso_enabled === '1';
                document.getElementById('localLoginEnabled').checked = d.settings.local_login_enabled !== '0';
            }
        } catch (e) { console.error(e); }
    }
    document.getElementById('saveGlobalBtn').addEventListener('click', async function () {
        this.disabled = true;
        const settings = {
            sso_enabled: document.getElementById('ssoEnabled').checked ? '1' : '0',
            local_login_enabled: document.getElementById('localLoginEnabled').checked ? '1' : '0'
        };
        try {
            const r = await fetch(API + 'settings/save_system_settings.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ settings })
            });
            const d = await r.json();
            showToast(d.success ? window.t('system.sso.global_saved') : window.t('system.sso.error', { error: d.error }), d.success ? 'success' : 'error');
        } catch (e) { showToast(window.t('system.sso.save_failed'), 'error'); }
        this.disabled = false;
    });

    // ---------- Redirect URI copy ----------
    document.getElementById('copyRedirectBtn').addEventListener('click', function () {
        const txt = document.getElementById('redirectUri').textContent;
        navigator.clipboard.writeText(txt).then(() => showToast(window.t('system.sso.redirect_copied'), 'success'));
    });

    // ---------- Providers list ----------
    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    async function loadProviders() {
        try {
            const r = await fetch(API + 'system/get_sso_providers.php');
            const d = await r.json();
            providers = d.success ? d.providers : [];
        } catch (e) { providers = []; }
        renderProviders();
    }

    function renderProviders() {
        const body = document.getElementById('providersBody');
        if (!providers.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="' + SSO_COLSPAN + '">' + window.t('system.sso.no_providers', { add: '<strong>' + window.t('system.sso.add_strong') + '</strong>' }) + '</td></tr>';
            return;
        }
        body.innerHTML = providers.map(p => {
            const isLdap = p.protocol === 'ldap';
            // "Issuer" is an OIDC idea; for a directory show where we connect to.
            const target = isLdap
                ? ((p.ldap_host || '') + (p.ldap_port ? ':' + p.ldap_port : ''))
                : (p.issuer_url || '');
            return `
            <tr>
                <td><strong>${esc(p.display_name)}</strong></td>
                ${MULTI_TENANT ? `<td>${p.tenant_name ? esc(p.tenant_name) : '<span class="tenant-global">' + window.t('system.sso.global_badge') + '</span>'}</td>` : ''}
                <td><span class="proto-badge">${isLdap ? window.t('system.sso.ldap_badge') : window.t('system.sso.oidc_badge')}</span></td>
                <td class="issuer-cell" title="${esc(target)}">${esc(target)}</td>
                <td><span class="status-badge ${p.enabled ? 'on' : 'off'}">${p.enabled ? window.t('system.sso.enabled') : window.t('system.sso.disabled')}</span></td>
                <td>${p.auto_create_users ? '<span class="badge-jit">' + window.t('system.sso.jit_on') + '</span>' : '<span class="jit-off">' + window.t('system.sso.jit_off') + '</span>'}</td>
                <td style="text-align:right;">
                    <button class="table-action-btn" data-edit="${p.id}">${window.t('system.sso.edit')}</button>
                    <button class="table-action-btn danger" data-del="${p.id}">${window.t('system.sso.delete')}</button>
                </td>
            </tr>`;
        }).join('');
    }

    // ---------- Modal ----------
    const modal = document.getElementById('providerModal');
    const $ = id => document.getElementById(id);

    // Defaults per directory flavour. These mirror ldapFlavourDefaults() in
    // includes/ldap.php — if you change one, change the other.
    const LDAP_PRESETS = {
        ad: {
            port: 389, encryption: 'none',
            filter: '(&(objectClass=user)(|(sAMAccountName=%s)(userPrincipalName=%s)(mail=%s)))',
            username: 'sAMAccountName', email: 'mail', name: 'displayName', guid: 'objectGUID',
            // LDAP_MATCHING_RULE_IN_CHAIN: walks NESTED groups, which memberOf cannot.
            groupFilter: '(&(objectClass=group)(member:1.2.840.113556.1.4.1941:=%s))'
        },
        openldap: {
            port: 389, encryption: 'none',
            filter: '(&(objectClass=inetOrgPerson)(|(uid=%s)(mail=%s)))',
            username: 'uid', email: 'mail', name: 'cn', guid: 'entryUUID',
            groupFilter: '(&(objectClass=groupOfNames)(member=%s))'
        }
    };
    function applyPreset(which) {
        const d = LDAP_PRESETS[which];
        $('fLdapPort').value = d.port;
        $('fLdapEncryption').value = d.encryption;
        $('fLdapFilter').value = d.filter;
        $('fLdapAttrUsername').value = d.username;
        $('fLdapAttrEmail').value = d.email;
        $('fLdapAttrName').value = d.name;
        $('fLdapAttrGuid').value = d.guid;
        $('fLdapGroupFilter').value = d.groupFilter;
        showToast(window.t('system.sso.ldap_preset_applied'), 'success');
    }
    $('presetAdBtn').addEventListener('click', () => applyPreset('ad'));
    $('presetOpenldapBtn').addEventListener('click', () => applyPreset('openldap'));

    /** Show only the fields that belong to the selected protocol. */
    function syncProtocolFields() {
        const isLdap = $('fProtocol').value === 'ldap';
        $('ldapFields').style.display = isLdap ? '' : 'none';
        $('oidcFields').style.display = isLdap ? 'none' : '';
        // email_verified is an OIDC claim; it means nothing for a directory bind.
        $('requireVerifiedField').style.display = isLdap ? 'none' : '';
    }
    $('fProtocol').addEventListener('change', syncProtocolFields);

    function openModal(p) {
        document.getElementById('testResult').className = 'test-result';
        $('ldapTestResult').className = 'test-result';
        $('ldapTestResult').textContent = '';
        document.getElementById('modalTitle').textContent = p ? window.t('system.sso.modal_edit_title') : window.t('system.sso.modal_add_title');
        document.getElementById('providerId').value = p ? p.id : '';
        document.getElementById('fDisplayName').value = p ? p.display_name : '';
        document.getElementById('fIssuerUrl').value = p ? p.issuer_url : '';

        // --- LDAP ---
        $('fProtocol').value = p ? (p.protocol || 'oidc') : 'oidc';
        $('fLdapHost').value = p ? (p.ldap_host || '') : '';
        $('fLdapPort').value = p ? (p.ldap_port || '') : '';
        $('fLdapEncryption').value = p ? (p.ldap_encryption || 'none') : 'none';
        $('fLdapBindDn').value = p ? (p.ldap_bind_dn || '') : '';
        $('fLdapBaseDn').value = p ? (p.ldap_base_dn || '') : '';
        $('fLdapFilter').value = p ? (p.ldap_user_filter || '') : '';
        $('fLdapAttrUsername').value = p ? (p.ldap_attr_username || '') : '';
        $('fLdapAttrEmail').value = p ? (p.ldap_attr_email || '') : '';
        $('fLdapAttrName').value = p ? (p.ldap_attr_name || '') : '';
        $('fLdapAttrGuid').value = p ? (p.ldap_attr_guid || '') : '';
        $('fLdapAnalystGroup').value = p ? (p.ldap_analyst_group || '') : '';
        $('fLdapUserGroup').value = p ? (p.ldap_user_group || '') : '';
        $('fLdapGroupFilter').value = p ? (p.ldap_group_filter || '') : '';
        $('fLdapGroupBaseDn').value = p ? (p.ldap_group_base_dn || '') : '';
        $('fLdapTestUser').value = '';
        $('fLdapTestPass').value = '';
        const bindPw = $('fLdapBindPassword');
        bindPw.value = '';
        if (p && p.has_bind_password) {
            bindPw.placeholder = window.t('system.sso.secret_stored_placeholder');
            $('bindPasswordHint').textContent = window.t('system.sso.bind_password_stored_hint');
        } else {
            bindPw.placeholder = '';
            $('bindPasswordHint').textContent = window.t('system.sso.field_ldap_bind_password_hint');
        }
        // A brand-new LDAP provider starts from the AD preset (the common case);
        // an existing one keeps whatever is stored.
        if (!p) { const d = LDAP_PRESETS.ad; $('fLdapFilter').value = d.filter; $('fLdapAttrUsername').value = d.username;
                  $('fLdapAttrEmail').value = d.email; $('fLdapAttrName').value = d.name; $('fLdapAttrGuid').value = d.guid;
                  $('fLdapPort').value = d.port; $('fLdapGroupFilter').value = d.groupFilter; }
        syncProtocolFields();
        document.getElementById('fClientId').value = p ? p.client_id : '';
        document.getElementById('fScopes').value = p ? (p.scopes || 'openid email profile') : 'openid email profile';
        document.getElementById('fEnabled').checked = p ? !!p.enabled : true;
        document.getElementById('fAutoCreate').checked = p ? !!p.auto_create_users : false;
        document.getElementById('fRequireVerified').checked = p ? !!p.require_verified_email : false;
        document.getElementById('fDefaultModules').value = p ? (p.default_modules || '') : '';
        const tenantSel = document.getElementById('fTenant');
        if (tenantSel) tenantSel.value = (p && p.tenant_id) ? String(p.tenant_id) : '';
        const secret = document.getElementById('fClientSecret');
        secret.value = '';
        if (p && p.has_secret) {
            secret.placeholder = window.t('system.sso.secret_stored_placeholder');
            document.getElementById('secretHint').textContent = window.t('system.sso.secret_stored_hint');
        } else {
            secret.placeholder = '';
            document.getElementById('secretHint').textContent = window.t('system.sso.field_client_secret_hint');
        }
        modal.classList.add('open');
    }
    function closeModal() { modal.classList.remove('open'); }

    document.getElementById('addProviderBtn').addEventListener('click', () => openModal(null));
    document.getElementById('cancelModalBtn').addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });

    document.getElementById('providersBody').addEventListener('click', function (e) {
        const editId = e.target.getAttribute('data-edit');
        const delId = e.target.getAttribute('data-del');
        if (editId) openModal(providers.find(p => p.id == editId));
        if (delId) deleteProvider(delId);
    });

    // ---------- Test discovery ----------
    document.getElementById('testDiscoveryBtn').addEventListener('click', async function () {
        const issuer = document.getElementById('fIssuerUrl').value.trim();
        const box = document.getElementById('testResult');
        if (!issuer) { box.className = 'test-result err'; box.textContent = window.t('system.sso.enter_issuer'); return; }
        this.disabled = true; box.className = 'test-result'; box.textContent = '';
        try {
            const r = await fetch(API + 'system/test_oidc_discovery.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ issuer_url: issuer })
            });
            const d = await r.json();
            if (d.success) {
                box.className = 'test-result ok';
                box.textContent = window.t('system.sso.discovery_ok', { issuer: d.issuer });
            } else {
                box.className = 'test-result err';
                box.textContent = window.t('system.sso.discovery_err', { error: d.error });
            }
        } catch (e) {
            box.className = 'test-result err'; box.textContent = window.t('system.sso.request_failed');
        }
        this.disabled = false;
    });

    // ---------- Test LDAP ----------
    function ldapPayload() {
        return {
            id: $('providerId').value || 0,
            ldap_host: $('fLdapHost').value.trim(),
            ldap_port: parseInt($('fLdapPort').value, 10) || 0,
            ldap_encryption: $('fLdapEncryption').value,
            ldap_bind_dn: $('fLdapBindDn').value.trim(),
            ldap_bind_password: $('fLdapBindPassword').value,
            ldap_base_dn: $('fLdapBaseDn').value.trim(),
            ldap_user_filter: $('fLdapFilter').value.trim(),
            ldap_attr_username: $('fLdapAttrUsername').value.trim(),
            ldap_attr_email: $('fLdapAttrEmail').value.trim(),
            ldap_attr_name: $('fLdapAttrName').value.trim(),
            ldap_attr_guid: $('fLdapAttrGuid').value.trim(),
            ldap_group_base_dn: $('fLdapGroupBaseDn').value.trim(),
            ldap_group_filter: $('fLdapGroupFilter').value.trim(),
            ldap_analyst_group: $('fLdapAnalystGroup').value.trim(),
            ldap_user_group: $('fLdapUserGroup').value.trim()
        };
    }

    $('testLdapBtn').addEventListener('click', async function () {
        const box = $('ldapTestResult');
        const body = ldapPayload();
        if (!body.ldap_host || !body.ldap_base_dn) {
            box.className = 'test-result err';
            box.textContent = window.t('system.sso.ldap_required_fields');
            return;
        }
        body.test_username = $('fLdapTestUser').value.trim();
        body.test_password = $('fLdapTestPass').value;
        this.disabled = true;
        box.className = 'test-result';
        box.textContent = window.t('system.sso.ldap_test_running');
        try {
            const r = await fetch(API + 'system/test_ldap_connection.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(body)
            });
            const d = await r.json();
            if (d.success) {
                let txt = d.message;
                if (d.found) {
                    txt += ' ' + window.t('system.sso.ldap_test_found', { name: d.found.name || d.found.username, email: d.found.email || '—' });
                    if (d.found.groups) {
                        txt += '\n' + window.t('system.sso.ldap_test_groups', { groups: d.found.groups.length ? d.found.groups.join(', ') : '—' });
                    }
                    if (d.found.role !== undefined) {
                        const label = d.found.role === 'analyst' ? window.t('system.sso.ldap_role_analyst')
                                    : d.found.role === 'user'   ? window.t('system.sso.ldap_role_user')
                                    : window.t('system.sso.ldap_role_none');
                        txt += '\n' + window.t('system.sso.ldap_test_role', { role: label });
                    }
                }
                // A user who authenticates but is in no group is a SUCCESSFUL bind
                // and a DENIED login — show it as a warning, not a green tick.
                box.className = (d.found && d.found.role === null) ? 'test-result err' : 'test-result ok';
                box.style.whiteSpace = 'pre-line';
                box.textContent = txt;
            } else {
                box.className = 'test-result err';
                box.textContent = d.error;
            }
        } catch (e) {
            box.className = 'test-result err';
            box.textContent = window.t('system.sso.request_failed');
        }
        this.disabled = false;
    });

    // ---------- Save provider ----------
    document.getElementById('saveProviderBtn').addEventListener('click', async function () {
        const isLdap = $('fProtocol').value === 'ldap';
        const payload = {
            id: document.getElementById('providerId').value || 0,
            protocol: isLdap ? 'ldap' : 'oidc',
            display_name: document.getElementById('fDisplayName').value.trim(),
            issuer_url: document.getElementById('fIssuerUrl').value.trim(),
            client_id: document.getElementById('fClientId').value.trim(),
            client_secret: document.getElementById('fClientSecret').value,
            scopes: document.getElementById('fScopes').value.trim(),
            enabled: document.getElementById('fEnabled').checked ? 1 : 0,
            auto_create_users: document.getElementById('fAutoCreate').checked ? 1 : 0,
            require_verified_email: document.getElementById('fRequireVerified').checked ? 1 : 0,
            default_modules: document.getElementById('fDefaultModules').value.trim(),
            tenant_id: (document.getElementById('fTenant') ? (document.getElementById('fTenant').value || null) : null)
        };
        if (isLdap) Object.assign(payload, ldapPayload(), { id: payload.id });

        if (!payload.display_name) {
            showToast(window.t('system.sso.required_fields'), 'error');
            return;
        }
        if (isLdap) {
            if (!payload.ldap_host || !payload.ldap_base_dn || !payload.ldap_user_filter) {
                showToast(window.t('system.sso.ldap_required_fields'), 'error');
                return;
            }
        } else if (!payload.issuer_url || !payload.client_id) {
            showToast(window.t('system.sso.required_fields'), 'error');
            return;
        }
        this.disabled = true;
        try {
            const r = await fetch(API + 'system/save_sso_provider.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const d = await r.json();
            if (d.success) {
                showToast(window.t('system.sso.provider_saved'), 'success');
                closeModal();
                loadProviders();
            } else {
                showToast(window.t('system.sso.error', { error: d.error }), 'error');
            }
        } catch (e) { showToast(window.t('system.sso.save_failed'), 'error'); }
        this.disabled = false;
    });

    // ---------- Delete provider ----------
    async function deleteProvider(id) {
        const p = providers.find(x => x.id == id);
        const msg = window.t('system.sso.delete_confirm', { name: p ? p.display_name : window.t('system.sso.delete_this') });
        const ok = window.showConfirm ? await showConfirm(msg) : confirm(msg);
        if (!ok) return;
        try {
            const r = await fetch(API + 'system/delete_sso_provider.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
            const d = await r.json();
            if (d.success) { showToast(window.t('system.sso.provider_deleted'), 'success'); loadProviders(); }
            else showToast(window.t('system.sso.error', { error: d.error }), 'error');
        } catch (e) { showToast(window.t('system.sso.delete_failed'), 'error'); }
    }

    loadGlobal();
    loadProviders();
    </script>
</body>
</html>
