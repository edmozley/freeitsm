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
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('system.sso.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=23">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=70">
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

        /* Shown when the switches could not be read. Uses the real danger tokens —
           var(--danger) alone does not exist in theme.css, only --danger-bg/-text/-border. */
        .load-error { display: flex; align-items: center; flex-wrap: wrap; gap: 6px 10px; margin: 0 0 18px;
                      padding: 11px 14px; border-radius: 6px; font-size: 13px; line-height: 1.5;
                      background: var(--danger-bg, #fee2e2); color: var(--danger-text, #991b1b);
                      border: 1px solid var(--danger-border, #f0c2c2); }
        .load-error[hidden] { display: none; }
        .load-error strong { font-weight: 600; }
        .load-error span { flex: 1; min-width: 220px; }
        .load-error .btn { padding: 5px 12px; font-size: 12px; border-radius: 5px; cursor: pointer;
                           background: var(--surface, #fff); color: var(--danger-text, #991b1b);
                           border: 1px solid var(--danger-border, #f0c2c2); }

        .setting-row { display: flex; align-items: center; gap: 16px; margin-bottom: 16px; }
        .setting-row:last-child { margin-bottom: 0; }
        .switch input:disabled + .slider { opacity: .5; cursor: not-allowed; }
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
        /* The URI on its own line with Copy underneath and on the left, so all
           three cards on this page put their button in the same place. Side by
           side, Copy was flung to the far right by the `flex: 1` on the code
           box — which looked deliberate on a wide screen and arbitrary next to
           the other two cards.
           ⚠️ `min-width: 0` on the code element is load-bearing: it is a flex
           item that scrolls its own overflow, and without it a long URI refuses
           to shrink and pushes the card sideways. */
        .redirect-uri-box { display: flex; flex-direction: column; align-items: flex-start; gap: 10px; margin-top: 10px; }
        .redirect-uri-box code { align-self: stretch; min-width: 0; background: var(--surface, #fff); border: 1px solid var(--border, #ddd); border-radius: 4px; padding: 8px 10px; font-size: 12px; color: var(--text, #333); overflow-x: auto; white-space: nowrap; }

        /* Providers table */
        /* Stacked, not two-up: the button belongs under its own description and
           left-aligned with every other button on the page. `align-items:
           flex-start` keeps it hugging its text instead of stretching to the
           card's full width. */
        .providers-head { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; margin-bottom: 16px; }
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
        /* Row actions are ICONS, matching System → Integrations and the rest of
           the settings screens. Three words per row ("Configure Delete") read as
           prose competing with the data; three small glyphs read as controls.
           ⚠️ Icon-only means the accessible name has to come from somewhere, so
           every one carries BOTH `title` (the hover tooltip) and `aria-label`
           (what a screen reader announces) — an icon button with neither is a
           button that says nothing at all. */
        .table-action-btn { background: none; border: none; cursor: pointer; color: #607d8b; padding: 5px; font-size: 13px; border-radius: 4px;
            display: inline-flex; align-items: center; justify-content: center; vertical-align: middle; }
        .table-action-btn svg { width: 16px; height: 16px; display: block; }
        .table-action-btn:hover { background: var(--sys-accent-soft, #eceff1); color: var(--accent, #0078d4); }
        .table-action-btn.danger:hover { background: #ffebee; color: #c62828; }
        .empty-row td { text-align: center; color: var(--text-faint, #aaa); padding: 24px; font-style: italic; }

        /* Modal — namespaced (sso-) so it doesn't inherit inbox.css's global .modal
           framework, whose .modal rule sets opacity:0/visibility:hidden by default. */
        .sso-modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 2100; align-items: center; justify-content: center; }
        .sso-modal-overlay.open { display: flex; }
        /* The title and the buttons stay put; only the middle scrolls.
           `max-height: 90vh; overflow-y: auto` on the BOX scrolls the whole
           thing, header and footer included — so on a long form (CardDAV, or
           LDAP before it moved to provider.php) you lose both the title telling
           you what you are editing and the Save button.

           🔑 This is the same flex-column shape provider.php already uses one
           file over, and the same fix as the app-wide `.modal-content` — the
           header and footer are rigid flex items, and the body takes the slack
           with `min-height: 0` so it is allowed to shrink below its content and
           scroll. `overflow: hidden` on the box keeps the corners clean. */
        .sso-modal { background: var(--surface, #fff); border-radius: 10px; width: 560px; max-width: 92vw; max-height: 90vh;
            display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
        .sso-modal-header { padding: 20px 24px; border-bottom: 1px solid var(--border-soft, #eee); font-size: 16px; font-weight: 600; color: var(--text, #333); flex: 0 0 auto; }
        .sso-modal-body { padding: 20px 24px; flex: 1 1 auto; min-height: 0; overflow-y: auto; }
        .sso-modal-footer { padding: 16px 24px; border-top: 1px solid var(--border-soft, #eee); display: flex; justify-content: flex-end; gap: 10px; flex: 0 0 auto; }
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
        /* The safety brake is a REFUSAL, not a failure — amber, not red. Nothing
           is broken; we declined to act on something that looked wrong. */
        .test-result.warn { display: block; background: #fff4ce; color: #6b5900; white-space: pre-wrap; }
        .test-result.ok, .test-result.err { white-space: pre-wrap; }

        /* Recent sync runs */
        table.sync-runs { width: 100%; border-collapse: collapse; font-size: 12px; margin-top: 4px; }
        table.sync-runs th { text-align: left; padding: 5px 8px; color: var(--text-dim, #888); font-weight: 600; border-bottom: 1px solid var(--border-soft, #eee); white-space: nowrap; }
        table.sync-runs td { padding: 5px 8px; border-bottom: 1px solid var(--border-soft, #f4f4f4); color: var(--text, #444); white-space: nowrap; }
        .sync-pill { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .sync-pill.ok { background: #e8f5e9; color: #2e7d32; }
        .sync-pill.stopped { background: #fff4ce; color: #6b5900; }
        .sync-pill.bad, .sync-pill.err { background: #ffebee; color: #c62828; }
        td.sync-msg { white-space: normal; color: var(--text-muted, #666); font-size: 11px; padding-bottom: 10px; }
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
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=139">
</head>
<body data-mobile-module="system" data-mobile-page="sso">
    <?php include '../includes/header.php'; ?>

    <div class="sso-container">
        <h1 class="page-title"><?php echo htmlspecialchars(t('system.sso.title')); ?></h1>
        <p class="page-subtitle"><?php echo htmlspecialchars(t('system.sso.subtitle')); ?></p>

        <!-- Global switches -->
        <div class="settings-card">
            <h3><?php echo htmlspecialchars(t('system.sso.global_heading')); ?></h3>
            <p class="card-desc"><?php echo htmlspecialchars(t('system.sso.global_desc')); ?></p>
            <div class="load-error" id="globalLoadError" hidden>
                <strong><?php echo htmlspecialchars(t('system.sso.global_load_failed')); ?></strong>
                <span><?php echo htmlspecialchars(t('system.sso.global_load_failed_desc')); ?></span>
                <button type="button" class="btn" id="retryGlobalBtn"><?php echo htmlspecialchars(t('system.sso.retry')); ?></button>
            </div>
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
            <?php /* Heading, description, then the button underneath and on the
                     LEFT — the same order as the Global settings card above,
                     whose Save sits under its own text. Previously this row was
                     `justify-content: space-between`, which threw the button to
                     the far right and left the three cards on this page with
                     their buttons in three different places. */ ?>
            <div class="providers-head">
                <h3 style="margin:0;"><?php echo htmlspecialchars(t('system.sso.providers_heading')); ?></h3>
                <p class="card-desc" style="margin:4px 0 0;"><?php echo htmlspecialchars(t('system.sso.providers_desc')); ?></p>
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
                        <?php /* 🔑 A CardDAV address book is a CONTACT SOURCE, not a
                                 sign-in method — an address book cannot authenticate
                                 anybody. So it lives on this screen because this is
                                 where "where do our people come from" is configured,
                                 and the option label says so plainly rather than
                                 letting somebody expect a login button from it. */ ?>
                        <option value="carddav"><?php echo htmlspecialchars(t('system.sso.protocol_carddav')); ?></option>
                    </select>
                    <?php if (!extension_loaded('ldap')): ?>
                        <div class="hint" style="color:var(--danger,#c0392b);margin-top:6px;"><?php echo htmlspecialchars(t('system.sso.ldap_ext_missing')); ?></div>
                    <?php endif; ?>
                </div>
                <div class="form-field">
                    <label><?php echo htmlspecialchars(t('system.sso.field_display_name')); ?></label>
                    <?php /* The hint is swapped per protocol by syncProtocolFields():
                             "shown on the login button" is a promise a CardDAV source
                             cannot keep, since it never appears on the login page. */ ?>
                    <div class="hint" id="displayNameHint"><?php echo htmlspecialchars(t('system.sso.field_display_name_hint')); ?></div>
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

                    <!-- Directory sync lives on provider.php now: it needs a page,
                         not the bottom of a scrolling dialog. This modal is reached
                         only for OIDC providers, which have none of it. -->
                </div>

                <!-- ===== CardDAV address book ===== -->
                <?php /* Four fields and a Test. Deliberately short: everything the
                         sync itself needs — what to do about an existing person, how
                         many missed runs before somebody counts as gone, the sanity
                         brake — is POLICY that already exists and is shared with
                         directory sync, so it does not get asked twice here. */ ?>
                <div id="carddavFields" style="display:none;">
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_carddav_url')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_url_hint')); ?></div>
                        <input type="text" id="fCardDavUrl" placeholder="https://dav.example.com/dav.php/addressbooks/jsmith/">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_carddav_username')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_username_hint')); ?></div>
                        <input type="text" id="fCardDavUsername" autocomplete="off">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_carddav_password')); ?></label>
                        <div class="hint" id="cardDavPasswordHint"><?php echo htmlspecialchars(t('system.sso.field_carddav_password_hint')); ?></div>
                        <input type="password" id="fCardDavPassword" autocomplete="new-password">
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_carddav_auth')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_auth_hint')); ?></div>
                        <select id="fCardDavAuth">
                            <option value="auto"><?php echo htmlspecialchars(t('system.sso.carddav_auth_auto')); ?></option>
                            <option value="digest"><?php echo htmlspecialchars(t('system.sso.carddav_auth_digest')); ?></option>
                            <option value="basic"><?php echo htmlspecialchars(t('system.sso.carddav_auth_basic')); ?></option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.field_carddav_book')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_book_hint')); ?></div>
                        <?php /* A select rather than a text box, populated by Test
                                 connection. Asking somebody to type a DAV collection
                                 path by hand is asking for a typo they cannot debug
                                 — and the server will happily tell us the real list. */ ?>
                        <select id="fCardDavBook">
                            <option value=""><?php echo htmlspecialchars(t('system.sso.carddav_book_untested')); ?></option>
                        </select>
                    </div>
                    <div class="form-field">
                        <label><?php echo htmlspecialchars(t('system.sso.carddav_test')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.carddav_test_desc')); ?></div>
                        <div class="issuer-row">
                            <button class="btn btn-test" id="testCardDavBtn" type="button"><?php echo htmlspecialchars(t('system.sso.test')); ?></button>
                        </div>
                        <div class="test-result" id="cardDavTestResult"></div>
                    </div>

                    <?php /* WHICH records to bring in.
                             🔑 "A specific contact group" means three different
                             things in CardDAV — a separate address book, a
                             KIND:group card, or a CATEGORIES tag — and rather
                             than guess which one an operator's server uses, or
                             ask them to type a name they cannot verify,
                             FreeITSM reads the chosen book and offers whatever
                             is genuinely in it. Test connection fills both
                             lists; an option with nothing behind it is disabled
                             and says so, rather than being silently absent. */ ?>
                    <div class="form-field">
                        <label for="fCardDavScope"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope')); ?></label>
                        <div class="hint"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope_hint')); ?></div>
                        <select id="fCardDavScope">
                            <option value="all"><?php echo htmlspecialchars(t('system.sso.carddav_scope_all')); ?></option>
                            <option value="group"><?php echo htmlspecialchars(t('system.sso.carddav_scope_group')); ?></option>
                            <option value="category"><?php echo htmlspecialchars(t('system.sso.carddav_scope_category')); ?></option>
                        </select>
                    </div>
                    <div class="form-field" id="cardDavScopeValueField" style="display:none;">
                        <label for="fCardDavScopeValue" id="cardDavScopeValueLabel"><?php echo htmlspecialchars(t('system.sso.field_carddav_scope_value')); ?></label>
                        <div class="hint" id="cardDavScopeValueHint"></div>
                        <select id="fCardDavScopeValue"></select>
                    </div>
                </div><!-- /#carddavFields -->

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
                    <?php /* The description is swapped per protocol: "show this
                             provider's button on the login page" describes
                             nothing for an address book, which has no button —
                             but the checkbox itself still means something real
                             (whether the source is used at all), so it stays. */ ?>
                    <div class="cb-label"><strong><?php echo htmlspecialchars(t('system.sso.cb_enabled')); ?></strong><span id="enabledDesc"><?php echo htmlspecialchars(t('system.sso.cb_enabled_desc')); ?></span></div>
                </div>
                <?php /* Given an id so it can be hidden for CardDAV, where
                         "create the person the first time they sign in" describes
                         something that cannot happen — an address book signs
                         nobody in. */ ?>
                <div class="checkbox-field" id="autoCreateField">
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
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=2"></script>
    <script>
    const API = '<?php echo $path_prefix; ?>api/';
    const MULTI_TENANT = <?php echo $ssoMultiTenant ? 'true' : 'false'; ?>;
    const SSO_COLSPAN = <?php echo $ssoColspan; ?>;
    let providers = [];

    // ---------- Global switches ----------
    /* Both switches are plain unchecked in the markup, so a failed load looked
       EXACTLY like "single sign-on off, local login off" — and one of those is a
       lock-out. Worse, Save would then have written that guess back as fact.
       So: a failure says so, and Save refuses until the real values have arrived. */
    let globalLoaded = false;

    function setGlobalLoadFailed(failed) {
        globalLoaded = !failed;
        document.getElementById('globalLoadError').hidden = !failed;
        document.getElementById('ssoEnabled').disabled = failed;
        document.getElementById('localLoginEnabled').disabled = failed;
        document.getElementById('saveGlobalBtn').disabled = failed;
    }

    async function loadGlobal() {
        try {
            const r = await fetch(API + 'settings/get_system_settings.php');
            const d = await r.json();
            if (!d.success) throw new Error(d.error || 'unsuccessful response');
            document.getElementById('ssoEnabled').checked = d.settings.sso_enabled === '1';
            // Absent means ON — never leave the fallback login looking switched off.
            document.getElementById('localLoginEnabled').checked = d.settings.local_login_enabled !== '0';
            setGlobalLoadFailed(false);
        } catch (e) {
            console.error(e);
            setGlobalLoadFailed(true);
        }
    }
    document.getElementById('retryGlobalBtn').addEventListener('click', loadGlobal);

    document.getElementById('saveGlobalBtn').addEventListener('click', async function () {
        if (!globalLoaded) return;              // never save a state we never read
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
        this.disabled = !globalLoaded;
    });

    // ---------- Redirect URI copy ----------
    document.getElementById('copyRedirectBtn').addEventListener('click', function () {
        const txt = document.getElementById('redirectUri').textContent;
        // copyToClipboard(), not navigator.clipboard directly - see clipboard.js.
        copyToClipboard(txt).then(ok => showToast(
            ok ? window.t('system.sso.redirect_copied') : 'Could not copy - your browser blocked it.',
            ok ? 'success' : 'error'));
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

    /* Feather-style outline icons at 24px, sized down by CSS — the same set and
       the same stroke weight as System → Integrations, so the two screens do
       not look like they were built by different people. `currentColor` so the
       hover and danger states tint the glyph without a second rule. */
    const ICON_CONFIGURE = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="21" x2="4" y2="14"></line><line x1="4" y1="10" x2="4" y2="3"></line><line x1="12" y1="21" x2="12" y2="12"></line><line x1="12" y1="8" x2="12" y2="3"></line><line x1="20" y1="21" x2="20" y2="16"></line><line x1="20" y1="12" x2="20" y2="3"></line><line x1="1" y1="14" x2="7" y2="14"></line><line x1="9" y1="8" x2="15" y2="8"></line><line x1="17" y1="16" x2="23" y2="16"></line></svg>';
    const ICON_EDIT      = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
    const ICON_DELETE    = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

    function renderProviders() {
        const body = document.getElementById('providersBody');
        if (!providers.length) {
            body.innerHTML = '<tr class="empty-row"><td colspan="' + SSO_COLSPAN + '">' + window.t('system.sso.no_providers', { add: '<strong>' + window.t('system.sso.add_strong') + '</strong>' }) + '</td></tr>';
            return;
        }
        body.innerHTML = providers.map(p => {
            const isLdap    = p.protocol === 'ldap';
            const isCardDav = p.protocol === 'carddav';
            // "Issuer" is an OIDC idea; for a directory show where we connect
            // to, and for an address book show its URL.
            // ⚠️ `isLdap ? … : issuer_url` gave a CardDAV row a BLANK target and
            // an OIDC badge, because "not LDAP" quietly stopped meaning OIDC.
            const target = isLdap    ? ((p.ldap_host || '') + (p.ldap_port ? ':' + p.ldap_port : ''))
                         : isCardDav ? (p.carddav_url || '')
                         : (p.issuer_url || '');
            const badge  = isLdap    ? window.t('system.sso.ldap_badge')
                         : isCardDav ? window.t('system.sso.carddav_badge')
                         : window.t('system.sso.oidc_badge');
            return `
            <tr>
                <td><strong>${esc(p.display_name)}</strong></td>
                ${MULTI_TENANT ? `<td>${p.tenant_name ? esc(p.tenant_name) : '<span class="tenant-global">' + window.t('system.sso.global_badge') + '</span>'}</td>` : ''}
                <td><span class="proto-badge">${badge}</span></td>
                <td class="issuer-cell" title="${esc(target)}">${esc(target)}</td>
                <td><span class="status-badge ${p.enabled ? 'on' : 'off'}">${p.enabled ? window.t('system.sso.enabled') : window.t('system.sso.disabled')}</span></td>
                <?php /* "Auto-create on first sign-in" cannot apply to something
                         nobody signs in through, so a CardDAV row says "not
                         applicable" rather than "Off" — Off implies a setting
                         somebody could turn on. */ ?>
                <td>${isCardDav
                        ? '<span class="jit-off">' + window.t('system.sso.jit_na') + '</span>'
                        : (p.auto_create_users ? '<span class="badge-jit">' + window.t('system.sso.jit_on') + '</span>' : '<span class="jit-off">' + window.t('system.sso.jit_off') + '</span>')}</td>
                <td style="text-align:right;">
                    ${isLdap
                        /* A directory has a connection, a sign-in scope, group gating, an
                           import scope, attribute mapping, safety settings and a run
                           history. That is a page, not a dialog — the import section
                           ended up below the fold in the modal and people reasonably
                           concluded it was not there. OIDC keeps the dialog: an issuer,
                           a client id and a secret genuinely is a dialog's worth. */
                        ? `<a class="table-action-btn" href="provider.php?id=${p.id}" title="${esc(window.t('system.sso.configure'))}" aria-label="${esc(window.t('system.sso.configure'))}">${ICON_CONFIGURE}</a>`
                        : `<button class="table-action-btn" data-edit="${p.id}" title="${esc(window.t('system.sso.edit'))}" aria-label="${esc(window.t('system.sso.edit'))}">${ICON_EDIT}</button>`}
                    <button class="table-action-btn danger" data-del="${p.id}" title="${esc(window.t('system.sso.delete'))}" aria-label="${esc(window.t('system.sso.delete'))}">${ICON_DELETE}</button>
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
        // ⚠️ Three protocols now, so `isLdap ? a : b` is no longer a complete
        // question — written that way, choosing CardDAV showed the OIDC fields,
        // because "not LDAP" used to mean OIDC and silently stopped meaning it.
        const proto      = $('fProtocol').value;
        const isLdap     = proto === 'ldap';
        const isCardDav  = proto === 'carddav';
        const isOidc     = proto === 'oidc';
        $('ldapFields').style.display    = isLdap    ? '' : 'none';
        $('carddavFields').style.display = isCardDav ? '' : 'none';
        $('oidcFields').style.display    = isOidc    ? '' : 'none';
        // email_verified is an OIDC claim; it means nothing for a directory bind
        // and nothing at all for an address book.
        $('requireVerifiedField').style.display = isOidc ? '' : 'none';
        // 🔑 Nor does "create them on first sign-in": an address book cannot
        // sign anybody in, so offering the toggle would promise something that
        // can never happen.
        $('autoCreateField').style.display = isCardDav ? 'none' : '';
        // "Default module access for auto-created users" describes the accounts
        // JIT sign-in creates. Nothing signs in through an address book, so
        // there are none — the field is meaningless rather than merely unused.
        $('defaultModulesField').style.display = isCardDav ? 'none' : '';
        // "Shown on the login button" is false for an address book.
        $('displayNameHint').textContent = isCardDav
            ? window.t('system.sso.field_display_name_hint_carddav')
            : window.t('system.sso.field_display_name_hint');
        // Nor is "show this provider's button on the login page" — but the
        // checkbox still means "use this source at all", so it stays and only
        // its description changes.
        $('enabledDesc').textContent = isCardDav
            ? window.t('system.sso.cb_enabled_desc_carddav')
            : window.t('system.sso.cb_enabled_desc');
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
        // --- CardDAV ---
        $('fCardDavUrl').value      = p ? (p.carddav_url || '') : '';
        $('fCardDavUsername').value = p ? (p.carddav_username || '') : '';
        $('fCardDavAuth').value     = p ? (p.carddav_auth || 'auto') : 'auto';
        $('cardDavTestResult').className = 'test-result';
        $('cardDavTestResult').textContent = '';
        const cdPw = $('fCardDavPassword');
        cdPw.value = '';
        if (p && p.has_carddav_password) {
            cdPw.placeholder = window.t('system.sso.secret_stored_placeholder');
            $('cardDavPasswordHint').textContent = window.t('system.sso.carddav_password_stored_hint');
        } else {
            cdPw.placeholder = '';
            $('cardDavPasswordHint').textContent = window.t('system.sso.field_carddav_password_hint');
        }
        // ⚠️ The book picker is filled by Test connection, so on open there is
        // nothing to choose from yet. Seed it with whatever is already SAVED so
        // editing a working provider and pressing Save without re-testing does
        // not silently blank the address book it was using.
        const book = $('fCardDavBook');
        book.innerHTML = '';
        if (p && p.carddav_addressbook) {
            book.appendChild(new Option(p.carddav_addressbook, p.carddav_addressbook, true, true));
        } else {
            book.appendChild(new Option(window.t('system.sso.carddav_book_untested'), ''));
        }

        // Scope. Same reasoning as the book: seed from what is SAVED so editing
        // a working provider and saving without re-testing keeps its scope.
        // ⚠️ cardDavScan is cleared, because what is inside the book is not
        // stored — it is read live, and a stale list from the last provider
        // edited would offer groups belonging to somebody else's server.
        cardDavScan = null;
        $('fCardDavScope').value = (p && p.carddav_scope) ? p.carddav_scope : 'all';
        const scopeVal = $('fCardDavScopeValue');
        scopeVal.innerHTML = '';
        if (p && p.carddav_scope_value) {
            scopeVal.appendChild(new Option(p.carddav_scope_value, p.carddav_scope_value, true, true));
        }
        syncCardDavScope();

        // Directory sync fields live on provider.php, not in this dialog.
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
        // 🔴 `closest()`, not `e.target`. The buttons now contain an <svg>, so a
        // click lands on the svg — or on a <path> inside it — and never on the
        // button itself. Reading the attribute off e.target therefore returned
        // null for every click, and Edit and Delete would have stopped working
        // silently the moment the labels became icons. Nothing throws; the row
        // just ignores you.
        const editBtn = e.target.closest('[data-edit]');
        const delBtn  = e.target.closest('[data-del]');
        if (editBtn) openModal(providers.find(p => p.id == editBtn.getAttribute('data-edit')));
        if (delBtn)  deleteProvider(delBtn.getAttribute('data-del'));
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
            ldap_user_group: $('fLdapUserGroup').value.trim(),
            ldap_user_group: $('fLdapUserGroup').value.trim()
        };
    }



    /**
     * Test a CardDAV address book, and fill the book picker from the answer.
     *
     * ⭐ The listing is the whole point. "Which address book?" is a DAV
     * collection path, and asking somebody to type one by hand is asking for a
     * typo they have no way to debug — the server knows the real list, so it
     * gets asked and the answer becomes the dropdown.
     */
    /* What Test connection last found inside the chosen book. Held so changing
       the scope dropdown can refill the value list without another round trip. */
    let cardDavScan = null;

    /**
     * Show the right second dropdown for the chosen scope, and fill it.
     *
     * ⚠️ An option whose list is empty is DISABLED with the reason in the
     * label, not hidden. Hiding it makes "this server has no groups" look
     * identical to "FreeITSM does not support groups", and the operator cannot
     * tell which — so they go looking for a feature that is right there.
     */
    function syncCardDavScope() {
        const scope = $('fCardDavScope').value;
        const field = $('cardDavScopeValueField');
        const sel   = $('fCardDavScopeValue');
        const hint  = $('cardDavScopeValueHint');
        const label = $('cardDavScopeValueLabel');
        const opts  = $('fCardDavScope').options;

        // Label the two scoped options with what is actually available.
        if (cardDavScan) {
            opts[1].disabled = cardDavScan.groups.length === 0;
            opts[2].disabled = cardDavScan.categories.length === 0;
            opts[1].textContent = cardDavScan.groups.length
                ? window.t('system.sso.carddav_scope_group')
                : window.t('system.sso.carddav_scope_group_none');
            opts[2].textContent = cardDavScan.categories.length
                ? window.t('system.sso.carddav_scope_category')
                : window.t('system.sso.carddav_scope_category_none');
        }

        if (scope === 'all') { field.style.display = 'none'; return; }

        const list = scope === 'group'
            ? (cardDavScan ? cardDavScan.groups : [])
            : (cardDavScan ? cardDavScan.categories : []);

        label.textContent = scope === 'group'
            ? window.t('system.sso.field_carddav_group')
            : window.t('system.sso.field_carddav_category');

        const previous = sel.value;
        sel.innerHTML = '';
        if (!list.length) {
            // ⚠️ "Not tested yet" and "tested, and there are none" are different
            // facts and must not share a message — the same distinction as the
            // DAV parse failure versus an empty account. Telling somebody to
            // press Test connection when they just did reads as the button not
            // working.
            const tested = cardDavScan !== null;
            sel.appendChild(new Option(window.t(tested
                ? 'system.sso.carddav_scope_none'
                : 'system.sso.carddav_scope_untested'), ''));
            hint.textContent = window.t(tested
                ? 'system.sso.carddav_scope_none_hint'
                : 'system.sso.carddav_scope_untested_hint');
        } else {
            list.forEach(item => {
                // Count alongside the name: "itsm (2 contacts)" is the only way
                // to tell the real group from a stray tag on one card.
                const label2 = scope === 'group'
                    ? window.t('system.sso.carddav_group_option', { name: item.name, n: item.members })
                    : window.t('system.sso.carddav_category_option', { name: item.name, n: item.contacts });
                sel.appendChild(new Option(label2, scope === 'group' ? (item.uid || item.name) : item.name));
            });
            if (previous && [...sel.options].some(o => o.value === previous)) sel.value = previous;
            hint.textContent = '';
        }
        field.style.display = '';
    }
    $('fCardDavScope').addEventListener('change', syncCardDavScope);
    // Choosing a different book invalidates what we know about its contents —
    // the groups in one book say nothing about another.
    $('fCardDavBook').addEventListener('change', function () {
        cardDavScan = null;
        syncCardDavScope();
        $('cardDavTestResult').className = 'test-result';
        $('cardDavTestResult').textContent = window.t('system.sso.carddav_book_changed');
    });

    $('testCardDavBtn').addEventListener('click', async function () {
        const box  = $('cardDavTestResult');
        const book = $('fCardDavBook');
        const url  = $('fCardDavUrl').value.trim();
        if (!url) {
            box.className = 'test-result err';
            box.textContent = window.t('system.sso.carddav_url_required');
            return;
        }
        this.disabled = true;
        box.className = 'test-result';
        box.textContent = window.t('system.sso.carddav_test_running');
        try {
            const r = await fetch(API + 'system/test_carddav_connection.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id:               document.getElementById('providerId').value || null,
                    carddav_url:      url,
                    carddav_username: $('fCardDavUsername').value.trim(),
                    carddav_password: $('fCardDavPassword').value,
                    carddav_auth:     $('fCardDavAuth').value,
                    // Sending the chosen book asks the server what is INSIDE it
                    // as well, in the same round trip.
                    carddav_addressbook: $('fCardDavBook').value || ''
                })
            });
            const d = await r.json();
            box.style.whiteSpace = 'pre-line';

            if (!d.success) {
                box.className = 'test-result err';
                box.textContent = d.error || window.t('system.sso.carddav_test_failed');
                return;
            }

            // Reached the server and signed in, but nothing at that address.
            // A warning, not a tick: the credentials are fine and the URL is not.
            if (d.warning) {
                box.className = 'test-result err';
                box.textContent = d.warning;
                return;
            }

            // Keep whatever was already chosen if the server still offers it —
            // re-testing should not quietly move a working provider onto a
            // different address book.
            const previous = book.value;
            book.innerHTML = '';
            d.books.forEach(b => book.appendChild(new Option(b.name + '  (' + b.href + ')', b.href)));
            if (previous && d.books.some(b => b.href === previous)) book.value = previous;

            box.className = 'test-result ok';
            let txt = d.message;
            if (d.auth_offered) {
                txt += '\n' + window.t('system.sso.carddav_test_auth', { scheme: d.auth_offered });
            }

            // What is inside the chosen book, if one was chosen.
            cardDavScan = d.scan || null;
            if (d.scan) {
                txt += '\n' + window.t('system.sso.carddav_test_scan', {
                    contacts:   d.scan.contacts,
                    groups:     d.scan.groups.length,
                    categories: d.scan.categories.length
                });
            } else if (d.scan_error) {
                // Read the book but not its contents: say so without claiming
                // the connection failed, because it plainly did not.
                txt += '\n' + window.t('system.sso.carddav_scan_failed', { error: d.scan_error });
            } else {
                txt += '\n' + window.t('system.sso.carddav_test_pick');
            }
            syncCardDavScope();
            box.textContent = txt;
        } catch (e) {
            box.className = 'test-result err';
            box.textContent = window.t('system.sso.carddav_test_failed') + ' ' + e.message;
        } finally {
            this.disabled = false;
        }
    });

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
        // ⚠️ `isLdap ? 'ldap' : 'oidc'` was a complete answer with two protocols
        // and is a silent bug with three — it would have saved every CardDAV
        // provider as OIDC. Read the value.
        const proto     = $('fProtocol').value;
        const isLdap    = proto === 'ldap';
        const isCardDav = proto === 'carddav';
        const payload = {
            id: document.getElementById('providerId').value || 0,
            protocol: proto,
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
        if (isCardDav) {
            Object.assign(payload, {
                carddav_url:         $('fCardDavUrl').value.trim(),
                carddav_username:    $('fCardDavUsername').value.trim(),
                carddav_password:    $('fCardDavPassword').value,
                carddav_auth:        $('fCardDavAuth').value,
                carddav_addressbook: $('fCardDavBook').value,
                carddav_scope:       $('fCardDavScope').value,
                carddav_scope_value: $('fCardDavScope').value === 'all' ? '' : $('fCardDavScopeValue').value
            });
        }

        if (!payload.display_name) {
            showToast(window.t('system.sso.required_fields'), 'error');
            return;
        }
        if (isLdap) {
            if (!payload.ldap_host || !payload.ldap_base_dn || !payload.ldap_user_filter) {
                showToast(window.t('system.sso.ldap_required_fields'), 'error');
                return;
            }
        } else if (isCardDav) {
            if (!payload.carddav_url) {
                showToast(window.t('system.sso.carddav_url_required'), 'error');
                return;
            }
            // 🔑 An address book is required, and it is worth blocking rather
            // than defaulting. Saving with none means "read every book this
            // account can see", which is almost never what somebody wants —
            // the entire request behind this feature was to scope it to ONE
            // group — and a sync that quietly imported a personal address book
            // of several thousand contacts is not a mistake you can undo by
            // changing a setting afterwards.
            if (!payload.carddav_addressbook) {
                showToast(window.t('system.sso.carddav_book_required'), 'error');
                return;
            }
            // 🔑 Choosing "only this group" and then leaving the group blank
            // would fall back to importing everything — the exact outcome the
            // operator was trying to avoid by choosing a scope at all. Block it.
            if (payload.carddav_scope !== 'all' && !payload.carddav_scope_value) {
                showToast(window.t('system.sso.carddav_scope_required'), 'error');
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
        // 🔴 showConfirm() takes an OPTIONS OBJECT, not a string. Passed a
        // string it read `opts.message` off it, got undefined, and rendered an
        // EMPTY dialogue — a bare "Confirm" with Cancel and OK and no question.
        // You were being asked to delete something and told nothing about what.
        //
        // ⚠️ The `: confirm(msg)` fallback DOES take a string, so the native
        // path behaved correctly the whole time and only the real dialogue was
        // broken — which is a good way for a bug to survive a long time.
        //
        // Pre-dates the icon change that made it visible: identical at v1.7.0.
        const ok = window.showConfirm
            ? await showConfirm({
                  title:    window.t('system.sso.delete'),
                  message:  msg,
                  okLabel:  window.t('system.sso.delete'),
                  okClass:  'danger'
              })
            : confirm(msg);
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
    <script src="../../assets/js/mobile.js?v=57"></script>
</body>
</html>
