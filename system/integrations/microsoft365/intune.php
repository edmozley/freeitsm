<?php
/**
 * System -> Integrations -> Microsoft 365 -> Intune -> Connection (GH #263).
 *
 * Which tenant, and which app reads it - the half of Intune that Entra ID keeps on
 * its Connection tab. What the connection brings in is intune_import.php.
 *
 * The Intune connection - the tenant and app that read it, how its devices become
 * assets, which asset type each kind of device gets - and its two syncs. It was the
 * Intune tab of Assets -> Settings; it moved here, beside Entra ID, because both read
 * the same Microsoft tenant. The setting keys, the endpoints and the permission are
 * unchanged:
 *
 *   - who may open it: an administrator, or an analyst with the Assets module and the
 *     "Configure the Intune connection" capability (Cap::ASSETS_INTUNE) - the people
 *     who could use the tab. System -> Roles still grants it under Assets, because the
 *     tab is still declared in asset-management/settings/manifest.php (with 'moved_to').
 *   - what it writes: the intune_* system settings, through api/settings/, owned by
 *     that tab; and api/intune/*, which check the same capability.
 *
 * The old link (Assets -> Settings -> ?tab=intune) is redirected here.
 */
session_start();
require_once '../../../config.php';
require_once '../../../includes/i18n.php';
require_once '../../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../../includes/functions.php';
require_once '../../../includes/theme.php';
require_once '../../../includes/rbac.php';
$current_page = 'integrations';
// The people who could use the Intune tab in Assets may use it here too.
$system_page_grant = ['module' => 'assets', 'cap' => Cap::ASSETS_INTUNE];
require_once __DIR__ . '/../../includes/page_gate.php';
$path_prefix  = '../../../';
$translationNamespaces = ['common', 'system', 'asset-management', 'reporting'];

$activeCompanies = [];
try {
    $activeCompanies = connectToDatabase()->query(
        "SELECT id, name, is_default FROM tenants WHERE is_active = 1 ORDER BY is_default DESC, name ASC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Single-tenant
}
$isAdmin = analystIsAdmin(connectToDatabase(), (int)$_SESSION['analyst_id']);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Microsoft Intune - ' . t('system.integrations.ms365_intune_tab_connection'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=4"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/integrations.css?v=3">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .ms-wrap { height: calc(100vh - 48px); overflow-y: auto; }
        .ms-crumb { font-size: 13px; margin: 0 0 6px; }
        .ms-crumb a { color: var(--sys-accent, #546e7a); text-decoration: none; }
        .ms-crumb a:hover { text-decoration: underline; }

        /* As they were on Assets -> Settings, in System's colours. */
        .settings-section { background: var(--surface, #fff); border-radius: 8px; box-shadow: var(--shadow, 0 1px 4px rgba(0, 0, 0, 0.08)); margin-bottom: 25px; overflow: hidden; border: 1px solid var(--border, #e0e0e0); }
        .settings-section-header { padding: 18px 22px; border-bottom: 1px solid var(--border, #e0e0e0); background-color: var(--surface-3, #f8f9fa); display: flex; align-items: center; gap: 12px; }
        .settings-section-header svg { color: var(--sys-accent, #546e7a); flex-shrink: 0; }
        .settings-section-header h2 { margin: 0; font-size: 16px; font-weight: 600; color: var(--text, #333); }
        .settings-section-body { padding: 22px; }
        .settings-description { font-size: 13px; color: var(--text-muted, #666); margin: 0 0 20px 0; line-height: 1.5; }
        .form-group { margin-bottom: 18px; max-width: 720px; }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: 13px; color: var(--text, #333); }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 14px; box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); transition: border-color 0.15s, box-shadow 0.15s; }
        .form-input:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .form-hint { font-size: 12px; color: var(--text-dim, #888); margin-top: 4px; }
        .form-actions { display: flex; align-items: center; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-soft, #eee); flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 600; transition: background-color 0.15s; }
        .btn-sm { padding: 6px 12px; font-size: 12px; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: var(--sys-accent-hover, #455a64); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 60px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-dim, #888); font-size: 13px; padding: 4px; }
        .password-toggle:hover { color: var(--text, #333); }
        /* Connection | Import - the two pages, as a segmented switch. */
        .ms-switch { display: inline-flex; border: 1px solid var(--border, #d6dde3); border-radius: 8px; overflow: hidden; margin: 0 0 18px; }
        .ms-switch a { padding: 8px 16px; font-size: 13px; color: var(--text-muted, #666); text-decoration: none; background: var(--surface, #fff); }
        .ms-switch a + a { border-left: 1px solid var(--border, #d6dde3); }
        .ms-switch a.on { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .ms-save-bar { display: flex; align-items: center; gap: 12px; margin: 0 0 25px; }
        .intune-progress { margin-top: 18px; }
        .intune-progress-bar { background: var(--border, #e0e0e0); border-radius: 4px; height: 10px; overflow: hidden; }
        .intune-progress-fill { background: #107c10; height: 100%; width: 0; transition: width 0.3s ease-out; }
        .intune-progress-meta { font-size: 12px; color: var(--text-muted, #666); margin-top: 6px; }
        .intune-progress.intune-error .intune-progress-fill { background: #d13438; }


        .intune-software-section { margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--border-soft, #eee); }
        .intune-subsection-title { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px 0; }
        .intune-freshness-wrap { margin-top: 22px; padding: 14px 16px; background: var(--surface-3, #fafbfc); border: 1px solid var(--border-soft, #eee); border-radius: 6px; }
        .intune-freshness-title { font-size: 12px; color: var(--text-muted, #666); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
        .intune-freshness-canvas-wrap { position: relative; height: 180px; }
        .intune-jobs-list { margin-top: 18px; }
        .intune-jobs-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .intune-jobs-table th { text-align: left; padding: 8px 10px; background: var(--surface-3, #f8f9fa); color: var(--text-muted, #666); font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--border, #e0e0e0); }
        .intune-jobs-table td { padding: 8px 10px; border-bottom: 1px solid var(--surface-hover, #f0f0f0); color: var(--text, #333); }
        .intune-jobs-table tbody tr:hover { background: var(--surface-2, #fafafa); }
        .intune-job-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
        .intune-job-status.pending { background: #fff3e0; color: #e65100; }
        .intune-job-status.running { background: #e3f2fd; color: #1565c0; }
        .intune-job-status.done    { background: #e8f5e9; color: #2e7d32; }
        .intune-job-status.error   { background: #ffebee; color: #c62828; }
    </style>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/mobile.css?v=196">
</head>
<body>
    <?php include __DIR__ . '/../../includes/header.php'; ?>

    <div class="ms-wrap sys-page">
        <p class="ms-crumb"><?php if ($isAdmin): ?><a href="../"><?php echo htmlspecialchars(t('system.integrations.title')); ?></a> &rsaquo; <?php endif; ?><a href="./">Microsoft 365</a> &rsaquo; Microsoft Intune</p>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">Microsoft Intune - <?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_connection')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_conn_subtitle')); ?></p>
        </div></div>
        <?php /* The two halves, as Entra ID has them: the connection, and what it imports. */ ?>
        <div class="ms-switch">
            <a href="intune.php" class="on"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_connection')); ?></a>
            <a href="intune_import.php"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_import')); ?></a>
        </div>

        <div class="settings-section">
            <div class="settings-section-body">
                <p class="settings-description">
                    <?php echo htmlspecialchars(t('asset-management.settings.intune_intro')); ?>
                </p>
                <form id="intuneConnForm" onsubmit="saveIntuneConnection(event)">
                    <div class="form-group">
                        <label class="form-label" for="intuneTenantId"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_id')); ?></label>
                        <input type="text" class="form-input" id="intuneTenantId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_placeholder')); ?>">
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_tenant_hint')); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="intuneClientId"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_id')); ?></label>
                        <input type="text" class="form-input" id="intuneClientId" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_client_id_placeholder')); ?>">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="intuneClientSecret"><?php echo htmlspecialchars(t('asset-management.settings.intune_client_secret')); ?></label>
                        <div class="password-wrapper">
                            <input type="password" class="form-input" id="intuneClientSecret" placeholder="<?php echo htmlspecialchars(t('asset-management.settings.intune_secret_placeholder')); ?>">
                            <button type="button" class="password-toggle" onclick="toggleIntuneSecret()"><?php echo htmlspecialchars(t('asset-management.settings.show')); ?></button>
                        </div>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_secret_hint')); ?></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="intuneSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
                        <a class="btn btn-secondary" href="intune_import.php"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_next_import')); ?></a>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        const API_SETTINGS = '<?php echo BASE_URL; ?>api/settings/';

        // The connection: which tenant, and which app reads it. The secret is left
        // empty; the placeholder says one is saved, and a blank keeps it.
        async function loadIntuneConnection() {
            try {
                const data = await (await fetch(API_SETTINGS + 'get_system_settings.php')).json();
                if (!data.success || !data.settings) return;
                document.getElementById('intuneTenantId').value = data.settings.intune_tenant_id || '';
                document.getElementById('intuneClientId').value = data.settings.intune_client_id || '';
                const sec = document.getElementById('intuneClientSecret');
                sec.value = '';
                sec.placeholder = data.settings.intune_client_secret
                    ? window.t('asset-management.settings.secret_saved_placeholder')
                    : window.t('asset-management.settings.intune_secret_placeholder');
            } catch (e) {
                console.error('Error loading the Intune connection:', e);
            }
        }
        document.addEventListener('DOMContentLoaded', loadIntuneConnection);

        // Saves the connection's three keys and nothing else - the import has its own page.
        async function saveIntuneConnection(e) {
            e.preventDefault();
            const btn = document.getElementById('intuneSaveBtn');
            btn.disabled = true;
            try {
                const data = await (await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        intune_tenant_id: document.getElementById('intuneTenantId').value.trim(),
                        intune_client_id: document.getElementById('intuneClientId').value.trim(),
                        intune_client_secret: document.getElementById('intuneClientSecret').value,
                    } })
                })).json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntuneConnection();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (err) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }

        function toggleIntuneSecret() {
            const input = document.getElementById('intuneClientSecret');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        // InTune sync
    </script>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
