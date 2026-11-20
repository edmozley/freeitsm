<?php
/**
 * System -> Integrations -> Microsoft 365 -> Intune -> Import (GH #263).
 *
 * What the Intune connection (intune.php) brings in, in tabs as Entra ID's import
 * has them: Devices (their company, renaming, primary users, and the device sync),
 * Asset types (what each kind of device becomes) and Software (installed apps, and
 * the software sync). One Save for the tabs; it never touches the connection.
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
// Intune brings devices in as assets: with Assets switched off its endpoints refuse, so
// the page says so instead of tabs that cannot load or save.
$assetsOn = moduleIsEnabled(connectToDatabase(), 'assets');

// What it imports, in tabs - as Entra ID's import page has them.
require_once '../../../includes/settings_manifest.php';   // renderSettingsTabBar()
$tabs = [
    ['id' => 'devices',  'cap' => null, 'label' => t('system.integrations.ms365_intune_tab_devices')],
    ['id' => 'types',    'cap' => null, 'label' => t('system.integrations.ms365_intune_tab_types')],
    ['id' => 'software', 'cap' => null, 'label' => t('system.integrations.ms365_intune_tab_software')],
];
$activeTab = in_array($_GET['tab'] ?? '', ['devices', 'types', 'software'], true) ? $_GET['tab'] : 'devices';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Microsoft Intune - ' . t('system.integrations.ms365_intune_tab_import'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=4"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/chart.min.js"></script>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/theme.css?v=29">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css?v=97">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/integrations.css?v=3">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .ms-wrap { height: calc(100vh - 48px); overflow-y: auto; }
        .ms-crumb { font-size: var(--font-size-md, 13px); margin: 0 0 6px; }
        .ms-crumb a { color: var(--sys-accent, #546e7a); text-decoration: none; }
        .ms-crumb a:hover { text-decoration: underline; }

        /* As they were on Assets -> Settings, in System's colours. */
        .settings-section { background: var(--surface, #fff); border-radius: 8px; box-shadow: var(--shadow, 0 1px 4px rgba(0, 0, 0, 0.08)); margin-bottom: 25px; overflow: hidden; border: 1px solid var(--border, #e0e0e0); }
        .settings-section-header { padding: 18px 22px; border-bottom: 1px solid var(--border, #e0e0e0); background-color: var(--surface-3, #f8f9fa); display: flex; align-items: center; gap: 12px; }
        .settings-section-header svg { color: var(--sys-accent, #546e7a); flex-shrink: 0; }
        .settings-section-header h2 { margin: 0; font-size: var(--font-size-xl, 16px); font-weight: 600; color: var(--text, #333); }
        .settings-section-body { padding: 22px; }
        .settings-description { font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); margin: 0 0 20px 0; line-height: 1.5; }
        .form-group { margin-bottom: 18px; }
        .ms-off { margin: 0 0 18px; padding: 12px 16px; border-radius: 8px; font-size: var(--font-size-md, 13px);
                  background: var(--warning-bg, #fff8e1); color: var(--warning-text, #8a5a00); border: 1px solid var(--warning-border, #f3d27a); }
        .form-group:last-child { margin-bottom: 0; }
        .form-label { display: block; margin-bottom: 6px; font-weight: 500; font-size: var(--font-size-md, 13px); color: var(--text, #333); }
        .form-input { width: 100%; padding: 10px 12px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: var(--font-size-lg, 14px); box-sizing: border-box; background: var(--surface, #fff); color: var(--text, #333); transition: border-color 0.15s, box-shadow 0.15s; }
        .form-input:focus { outline: none; border-color: var(--sys-accent, #546e7a); }
        .form-hint { font-size: var(--font-size-sm, 12px); color: var(--text-dim, #888); margin-top: 4px; }
        .form-actions { display: flex; align-items: center; gap: 12px; margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border-soft, #eee); flex-wrap: wrap; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border: none; border-radius: 6px; cursor: pointer; font-size: var(--font-size-md, 13px); font-weight: 600; transition: background-color 0.15s; }
        .btn-sm { padding: 6px 12px; font-size: var(--font-size-sm, 12px); }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover:not(:disabled) { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--sys-accent-soft, #eceff1); color: var(--sys-accent-hover, #455a64); }
        .btn:disabled { opacity: .55; cursor: not-allowed; }
        .password-wrapper { position: relative; }
        .password-wrapper .form-input { padding-right: 60px; }
        .password-toggle { position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-dim, #888); font-size: var(--font-size-md, 13px); padding: 4px; }
        .password-toggle:hover { color: var(--text, #333); }
        /* Connection | Import - the two pages, as a segmented switch. */
        .ms-switch { display: inline-flex; border: 1px solid var(--border, #d6dde3); border-radius: 8px; overflow: hidden; margin: 0 0 18px; }
        .ms-switch a { padding: 8px 16px; font-size: var(--font-size-md, 13px); color: var(--text-muted, #666); text-decoration: none; background: var(--surface, #fff); }
        .ms-switch a + a { border-left: 1px solid var(--border, #d6dde3); }
        .ms-switch a.on { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .ms-save-bar { display: flex; align-items: center; gap: 12px; margin: 0 0 25px; }
        .intune-progress { margin-top: 18px; }
        .intune-progress-bar { background: var(--border, #e0e0e0); border-radius: 4px; height: 10px; overflow: hidden; }
        .intune-progress-fill { background: #107c10; height: 100%; width: 0; transition: width 0.3s ease-out; }
        .intune-progress-meta { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); margin-top: 6px; }
        .intune-progress.intune-error .intune-progress-fill { background: #d13438; }


        .intune-software-section { margin-top: 30px; padding-top: 25px; border-top: 1px solid var(--border-soft, #eee); }
        .intune-subsection-title { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 8px 0; }
        .intune-freshness-wrap { margin-top: 22px; padding: 14px 16px; background: var(--surface-3, #fafbfc); border: 1px solid var(--border-soft, #eee); border-radius: 6px; }
        .intune-freshness-title { font-size: var(--font-size-sm, 12px); color: var(--text-muted, #666); font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; margin-bottom: 8px; }
        .intune-freshness-canvas-wrap { position: relative; height: 180px; }
        .intune-jobs-list { margin-top: 18px; }
        .intune-jobs-table { width: 100%; border-collapse: collapse; font-size: var(--font-size-md, 13px); }
        .intune-jobs-table th { text-align: left; padding: 8px 10px; background: var(--surface-3, #f8f9fa); color: var(--text-muted, #666); font-weight: 600; font-size: var(--font-size-xs, 11px); text-transform: uppercase; letter-spacing: 0.3px; border-bottom: 1px solid var(--border, #e0e0e0); }
        .intune-jobs-table td { padding: 8px 10px; border-bottom: 1px solid var(--surface-hover, #f0f0f0); color: var(--text, #333); }
        .intune-jobs-table tbody tr:hover { background: var(--surface-2, #fafafa); }
        .intune-job-status { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: var(--font-size-xs, 11px); font-weight: 600; }
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
            <h1 class="sys-title">Microsoft Intune - <?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_import')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_import_subtitle')); ?></p>
        </div></div>
        <?php /* The two halves, as Entra ID has them: the connection, and what it imports. */ ?>
        <div class="ms-switch">
            <a href="intune.php"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_connection')); ?></a>
            <a href="intune_import.php" class="on"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_tab_import')); ?></a>
        </div>

        <?php if (!$assetsOn): ?>
        <p class="ms-off" role="status"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_assets_off')); ?></p>
        <?php else: ?>
        <?php renderSettingsTabBar($tabs, $activeTab, 'switchIntuneTab'); ?>

        <form id="intuneImportForm" onsubmit="saveIntuneImport(event)">
        <div class="settings-section">
            <div class="settings-section-body">
                <?php /* Devices: which company they belong to, and what each sync does to an asset. */ ?>
                <div class="tab-pane<?php echo $activeTab === 'devices' ? ' active' : ''; ?>" id="devices-pane">
                    <p class="settings-description"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_devices_intro')); ?></p>
                    <?php if (!empty($activeCompanies)): ?>
                    <div class="form-group">
                        <label class="form-label" for="intuneCompanyId"><?php echo htmlspecialchars(t('asset-management.settings.intune_company_label')); ?></label>
                        <select class="form-input" id="intuneCompanyId">
                            <option value=""><?php echo htmlspecialchars(t('asset-management.settings.default_company')); ?></option>
                            <?php foreach ($activeCompanies as $c): ?>
                                <?php if (empty($c['is_default'])): ?>
                                    <option value="<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_company_hint')); ?></div>
                    </div>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                            <label class="toggle-switch">
                                <input type="checkbox" id="intuneSyncHostnames">
                                <span class="toggle-slider"></span>
                            </label>
                            <span><?php echo htmlspecialchars(t('asset-management.settings.intune_sync_hostnames_label')); ?></span>
                        </label>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_sync_hostnames_hint')); ?></div>
                    </div>
                    <div class="form-group">
                        <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                            <label class="toggle-switch">
                                <input type="checkbox" id="intuneAssignPrimaryUser">
                                <span class="toggle-slider"></span>
                            </label>
                            <span><?php echo htmlspecialchars(t('asset-management.settings.intune_assign_primary_user_label')); ?></span>
                        </label>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_assign_primary_user_hint')); ?>
                            <?php /* PR #334 */ echo htmlspecialchars(t('asset-management.settings.intune_assign_primary_user_when')); ?></div>
                    </div>
                    <?php /* GH #335: on top of assigning - it only ever moves a holder Intune put there. */ ?>
                    <div class="form-group">
                        <label class="form-label" style="display:flex; align-items:center; gap:10px;">
                            <label class="toggle-switch">
                                <input type="checkbox" id="intuneFollowPrimaryUser">
                                <span class="toggle-slider"></span>
                            </label>
                            <span><?php echo htmlspecialchars(t('asset-management.settings.intune_follow_primary_user_label')); ?></span>
                        </label>
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.intune_follow_primary_user_hint')); ?></div>
                    </div>
                </div>

                <?php /* Asset types: which of this install's types each kind of device gets. */ ?>
                <div class="tab-pane<?php echo $activeTab === 'types' ? ' active' : ''; ?>" id="types-pane">
                    <?php /* GH #336: which of this install's asset types each kind of Intune device gets. */ ?>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('asset-management.settings.intune_types_label')); ?></label>
                        <div class="form-hint" style="margin-bottom:8px;"><?php echo htmlspecialchars(t('asset-management.settings.intune_types_hint')); ?></div>
                        <table id="intuneTypeMap" style="border-collapse:collapse;font-size:var(--font-size-md, 13px);">
                            <?php foreach (['laptop', 'desktop', 'server', 'phone', 'tablet', 'other_mobile'] as $kind): ?>
                            <tr>
                                <td style="padding:4px 14px 4px 0;"><?php echo htmlspecialchars(t('asset-management.settings.intune_kind_' . $kind)); ?></td>
                                <td style="padding:4px 0;"><select class="form-input" data-intune-kind="<?php echo $kind; ?>" style="min-width:200px;"></select></td>
                                <td style="padding:4px 0 4px 10px;" data-intune-suggest="<?php echo $kind; ?>"></td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                        <?php /* A suggestion is never preselected: it shows beside its row with a Use button, and nothing is saved until Save. The button creates what is still missing. */ ?>
                        <div style="display:flex;align-items:center;gap:10px;margin-top:8px;flex-wrap:wrap;">
                            <button type="button" class="btn btn-secondary btn-sm" id="intuneCreateTypesBtn" onclick="createMissingIntuneTypes()"><?php echo htmlspecialchars(t('asset-management.settings.intune_types_create')); ?></button>
                            <span class="form-hint" id="intuneTypesNote"></span>
                        </div>
                    </div>
                </div>

                <?php /* Software: the installed apps of each device - the second sync. */ ?>
                <div class="tab-pane<?php echo $activeTab === 'software' ? ' active' : ''; ?>" id="software-pane">
                    <div class="form-group">
                        <label class="form-label" for="intuneAppBatchSize"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_label')); ?></label>
                        <input type="number" class="form-input" id="intuneAppBatchSize" min="1" max="500" value="30" style="max-width: 140px;">
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_hint')); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="ms-save-bar">
            <button type="submit" class="btn btn-primary" id="intuneSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
            <span class="form-hint"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_save_hint')); ?></span>
        </div>
        </form>

        <?php /* The syncs: below the Save, because running one does not save anything. */ ?>
        <div class="settings-section" id="devicesSync"<?php echo $activeTab === 'devices' ? '' : ' style="display:none;"'; ?>>
            <div class="settings-section-body">
                <h3 class="intune-subsection-title"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_device_sync')); ?></h3>
                    <div class="form-actions" style="border-top: none; padding-top: 0;">
                        <button type="button" class="btn btn-secondary" id="intuneSyncBtn" onclick="startIntuneSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync')); ?></button>
                        <span id="intuneLastSync" class="form-hint" style="margin-left: auto;"></span>
                    </div>
                    <?php /* #342: both syncs are scheduled jobs now (cron/intune_sync.php, cron/intune_app_sync.php). */ ?>
                    <div class="form-hint" style="margin-top:6px;"><?php echo htmlspecialchars(t('asset-management.settings.intune_schedule_hint')); ?>
                        <a href="<?php echo BASE_URL; ?>reporting/logs/?tab=jobs"><?php echo htmlspecialchars(t('asset-management.settings.intune_schedule_link')); ?></a>
                        <?php
                        // #342: and when each last ran, however it was started, with its outcome.
                        require_once __DIR__ . '/../../../includes/intune.php';
                        $intuneLast = [];
                        try { $intuneLast = intuneJobsLastRun(connectToDatabase()); } catch (Throwable $e) { }
                        foreach (['intune_sync', 'intune_app_sync'] as $jk):
                            $lr = $intuneLast[$jk] ?? null; ?>
                        <br><?php echo htmlspecialchars($lr
                            ? t('asset-management.settings.intune_job_last', ['job' => t('reporting.jobs.name_' . $jk),
                                'when' => fmt_datetime($lr['started']), 'outcome' => t('reporting.jobs.outcome_' . $lr['outcome'])])
                            : t('asset-management.settings.intune_job_never', ['job' => t('reporting.jobs.name_' . $jk)])); ?>
                        <?php endforeach; ?></div>
                    <div id="intuneSyncProgress" class="intune-progress" style="display: none;">
                        <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneProgressFill"></div></div>
                        <div class="intune-progress-meta" id="intuneProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                    </div>
            </div>
        </div>
        <div class="settings-section" id="softwareSync"<?php echo $activeTab === 'software' ? '' : ' style="display:none;"'; ?>>
            <div class="settings-section-body">
            <div>
                <h3 class="intune-subsection-title"><?php echo htmlspecialchars(t('asset-management.settings.software_sync_heading')); ?></h3>
                <p class="settings-description">
                    <?php echo t('asset-management.settings.software_sync_intro'); ?>
                </p>
                <div class="form-actions" style="border-top: none; padding-top: 0;">
                    <button type="button" class="btn btn-secondary" id="intuneAppSyncBtn" onclick="startAppSync()"><?php echo htmlspecialchars(t('asset-management.settings.sync_software')); ?></button>
                    <span id="intuneAppEligible" class="form-hint" style="margin-left: auto;"></span>
                </div>
                <div id="intuneAppSyncProgress" class="intune-progress" style="display: none;">
                    <div class="intune-progress-bar"><div class="intune-progress-fill" id="intuneAppProgressFill"></div></div>
                    <div class="intune-progress-meta" id="intuneAppProgressMeta"><?php echo htmlspecialchars(t('asset-management.settings.starting')); ?></div>
                </div>
                <div class="intune-freshness-wrap" id="intuneFreshnessWrap" style="display: none;">
                    <div class="intune-freshness-title"><?php echo htmlspecialchars(t('asset-management.settings.inventory_freshness')); ?></div>
                    <div class="intune-freshness-canvas-wrap"><canvas id="intuneFreshnessChart"></canvas></div>
                </div>
                <div id="intuneAppJobsList" class="intune-jobs-list"></div>
            </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($assetsOn): ?>
    <script>
        const API_INTUNE   = '<?php echo BASE_URL; ?>api/intune/';
        const API_SETTINGS = '<?php echo BASE_URL; ?>api/settings/';

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // The tabs, and the sync that goes with each.
        function switchIntuneTab(id) {
            document.querySelectorAll('.tabs .tab').forEach(t => t.classList.toggle('active', t.dataset.tab === id));
            document.querySelectorAll('.tab-pane').forEach(p => p.classList.toggle('active', p.id === id + '-pane'));
            document.getElementById('devicesSync').style.display = id === 'devices' ? '' : 'none';
            document.getElementById('softwareSync').style.display = id === 'software' ? '' : 'none';
            history.replaceState(null, '', '?tab=' + id);
        }

        // What it imports. The connection's keys are not touched here.
        async function loadIntuneImport() {
            try {
                const data = await (await fetch(API_SETTINGS + 'get_system_settings.php')).json();
                if (!data.success || !data.settings) return;
                const batch = parseInt(data.settings.intune_app_batch_size, 10);
                document.getElementById('intuneAppBatchSize').value = (batch > 0 ? batch : 30);
                if (document.getElementById('intuneCompanyId')) {
                    document.getElementById('intuneCompanyId').value = data.settings.intune_company_id || '';
                }
                document.getElementById('intuneSyncHostnames').checked = data.settings.intune_sync_hostnames === '1';
                document.getElementById('intuneAssignPrimaryUser').checked = data.settings.intune_assign_primary_user === '1';
                document.getElementById('intuneFollowPrimaryUser').checked = data.settings.intune_follow_primary_user === '1';
                fillIntuneTypeMap();
            } catch (e) {
                console.error('Error loading the Intune import settings:', e);
            }
        }
        document.addEventListener('DOMContentLoaded', loadIntuneImport);

        // One Save for every tab: what the import does, never the connection.
        async function saveIntuneImport(e) {
            e.preventDefault();
            const btn = document.getElementById('intuneSaveBtn');
            btn.disabled = true;
            try {
                const data = await (await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ settings: {
                        intune_app_batch_size: String(Math.max(1, Math.min(500, parseInt(document.getElementById('intuneAppBatchSize').value, 10) || 30))),
                        intune_company_id: document.getElementById('intuneCompanyId') ? document.getElementById('intuneCompanyId').value.trim() : '',
                        intune_sync_hostnames: document.getElementById('intuneSyncHostnames').checked ? '1' : '0',
                        intune_assign_primary_user: document.getElementById('intuneAssignPrimaryUser').checked ? '1' : '0',
                        intune_follow_primary_user: document.getElementById('intuneFollowPrimaryUser').checked ? '1' : '0',
                        // Only once the mapping has loaded - see intuneTypeMapLoaded.
                        ...(intuneTypeMapLoaded ? { intune_type_map: readIntuneTypeMap() } : {})
                    } })
                })).json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntuneImport();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (err) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }

        // GH #336: the kind -> asset type mapping. The selects list the active
        // types; a mapping to a type since deleted shows as nothing.
        // TRAP: Save sends intune_type_map only once this has loaded the mapping -
        // a failed or slow load leaves every select on "No type", and saving that
        // would wipe the mapping somebody made.
        let intuneTypeMapLoaded = false;
        async function fillIntuneTypeMap(data) {
            // From the server: the active types, the saved mapping and, for each kind
            // with nothing mapped, the type it is known by ("Portátil" for laptops).
            if (!data) {
                try { data = await (await fetch(API_INTUNE + 'type_map.php')).json(); } catch (e) { data = null; }
            }
            if (!data || !data.success) return;   // keep whatever is on screen, and Save leaves the mapping alone
            const types = data.types || [];
            const map = data.map || {};
            const suggested = data.suggested || {};
            const nameOf = Object.fromEntries(types.map(t => [String(t.id), t.name]));
            let suggestedAny = false;
            document.querySelectorAll('#intuneTypeMap select[data-intune-kind]').forEach(sel => {
                const kind = sel.dataset.intuneKind;
                const want = String(map[kind] || '');
                sel.innerHTML = `<option value="">${escapeHtml(window.t('asset-management.settings.intune_type_none'))}</option>`
                    + types.map(t => `<option value="${t.id}"${String(t.id) === want ? ' selected' : ''}>${escapeHtml(t.name)}</option>`).join('');
                // A suggestion is only offered: "Suggested: Portátil" and a Use button. Nothing is
                // chosen, and nothing saved, until somebody presses Use and then Save.
                const cell = document.querySelector(`#intuneTypeMap [data-intune-suggest="${kind}"]`);
                if (!cell) return;
                const sid = suggested[kind] ? String(suggested[kind]) : '';
                if (!map[kind] && sid && nameOf[sid]) {
                    suggestedAny = true;
                    cell.innerHTML = `<span class="form-hint" style="margin-right:8px;">${escapeHtml(window.t('asset-management.settings.intune_type_suggested', { name: nameOf[sid] }))}</span>`
                        + `<button type="button" class="btn btn-secondary btn-sm">${escapeHtml(window.t('asset-management.settings.intune_type_use'))}</button>`;
                    cell.querySelector('button').addEventListener('click', () => { sel.value = sid; cell.innerHTML = ''; });
                } else {
                    cell.innerHTML = '';
                }
            });
            intuneTypeMapLoaded = true;
            const note = document.getElementById('intuneTypesNote');
            if (note) note.textContent = suggestedAny ? window.t('asset-management.settings.intune_types_suggested') : '';
        }

        // Every kind with nothing chosen gets the type it is known by, or a new one.
        async function createMissingIntuneTypes() {
            const missing = Array.from(document.querySelectorAll('#intuneTypeMap select[data-intune-kind]')).filter(s => !s.value);
            if (!missing.length) { showToast(window.t('asset-management.settings.intune_types_none_missing'), 'info'); return; }
            if (!(await showConfirm({
                title: window.t('asset-management.settings.intune_types_create_title'),
                message: window.t('asset-management.settings.intune_types_create_confirm'),
                okLabel: window.t('asset-management.settings.intune_types_create')
            }))) return;
            const btn = document.getElementById('intuneCreateTypesBtn');
            btn.disabled = true;
            try {
                const r = await fetch(API_INTUNE + 'type_map.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    // The mapping as it is on screen, so a choice not saved yet is kept.
                    body: JSON.stringify({ action: 'create_missing', map: intuneTypeMapLoaded ? JSON.parse(readIntuneTypeMap() || '{}') : undefined })
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error, 'error'); return; }
                await fillIntuneTypeMap(d);
                showToast(d.created.length
                    ? window.t('asset-management.settings.intune_types_created', { names: d.created.join(', ') })
                    : window.t('asset-management.settings.intune_types_mapped'), 'success');
            } catch (e) {
                showToast(window.t('asset-management.settings.intune_types_failed'), 'error');
            } finally {
                btn.disabled = false;
            }
        }
        function readIntuneTypeMap() {
            const map = {};
            document.querySelectorAll('#intuneTypeMap select[data-intune-kind]').forEach(sel => {
                if (sel.value) map[sel.dataset.intuneKind] = parseInt(sel.value, 10);
            });
            return Object.keys(map).length ? JSON.stringify(map) : '';
        }

        let intunePollTimer = null;

        async function startIntuneSync() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showIntuneProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'sync.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showIntuneProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    btn.disabled = false;
                    btn.textContent = window.t('asset-management.settings.sync');
                    return;
                }
                pollIntuneStatus(data.id);
            } catch (e) {
                showIntuneProgress(0, window.t('asset-management.settings.sync_start_error'), true);
                btn.disabled = false;
                btn.textContent = window.t('asset-management.settings.sync');
            }
        }

        function pollIntuneStatus(jobId) {
            clearTimeout(intunePollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'sync_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showIntuneProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetIntuneSyncButton();
                        return;
                    }
                    const job = data.job;
                    showIntuneProgress(job.percent, job.message || job.status, job.status === 'error');

                    if (job.status === 'running') {
                        intunePollTimer = setTimeout(tick, 1500);
                    } else {
                        resetIntuneSyncButton();
                        loadIntuneLastSync();
                    }
                } catch (e) {
                    showIntuneProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetIntuneSyncButton();
                }
            };
            tick();
        }

        function showIntuneProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneSyncProgress');
            const fill = document.getElementById('intuneProgressFill');
            const meta = document.getElementById('intuneProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetIntuneSyncButton() {
            const btn = document.getElementById('intuneSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync');
        }

        async function loadIntuneLastSync() {
            try {
                const response = await fetch(API_INTUNE + 'sync_status.php');
                const data = await response.json();
                const last = document.getElementById('intuneLastSync');
                if (data.success && data.job) {
                    const job = data.job;
                    if (job.status === 'running') {
                        last.textContent = '';
                        pollIntuneStatus(job.id);
                        return;
                    }
                    const when = job.finished_datetime || job.started_datetime;
                    const date = when ? fmtDateTime(when) : '';
                    last.textContent = window.t('asset-management.settings.last_sync', { date: date, status: job.status });
                } else {
                    last.textContent = '';
                }
            } catch (e) {
                document.getElementById('intuneLastSync').textContent = '';
            }
        }

        // Pull last-sync info on first load
        document.addEventListener('DOMContentLoaded', loadIntuneLastSync);

        // ─── Software (app) sync ────────────────────────────────────────────
        let appSyncPollTimer = null;

        async function startAppSync() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = true;
            btn.textContent = window.t('asset-management.settings.starting');
            showAppSyncProgress(0, window.t('asset-management.settings.starting'), false);

            try {
                const response = await fetch(API_INTUNE + 'create_app_sync_job.php', { method: 'POST' });
                const data = await response.json();
                if (!data.success) {
                    showAppSyncProgress(0, window.t('asset-management.toast.error', { error: data.error }), true);
                    resetAppSyncButton();
                    return;
                }
                const queuedMsg = data.reused ? window.t('asset-management.settings.resuming_job') : window.t('asset-management.settings.job_queued');
                showAppSyncProgress(0, window.t('asset-management.settings.job_for_assets', { msg: queuedMsg, count: data.asset_count }), false);
                pollAppSyncStatus(data.id);
            } catch (e) {
                showAppSyncProgress(0, window.t('asset-management.settings.app_sync_start_error'), true);
                resetAppSyncButton();
            }
        }

        function pollAppSyncStatus(jobId) {
            clearTimeout(appSyncPollTimer);
            const tick = async () => {
                try {
                    const response = await fetch(API_INTUNE + 'app_sync_job_status.php?id=' + encodeURIComponent(jobId));
                    const data = await response.json();
                    if (!data.success || !data.job) {
                        showAppSyncProgress(0, window.t('asset-management.settings.status_unavailable'), true);
                        resetAppSyncButton();
                        return;
                    }
                    const job = data.job;
                    const r = job.rollup || {};
                    const summary = window.t('asset-management.settings.sync_summary_done', { processed: job.processed, total: job.total }) +
                                    (job.failed > 0 ? window.t('asset-management.settings.sync_summary_failed', { failed: job.failed }) : '') +
                                    ((r.obsolete || 0) > 0 ? window.t('asset-management.settings.sync_summary_obsolete', { obsolete: r.obsolete }) : '');
                    const message = job.message ? `${job.message} (${summary})` : summary;
                    showAppSyncProgress(job.percent, message, job.status === 'error');

                    if (job.status === 'pending' || job.status === 'running') {
                        appSyncPollTimer = setTimeout(tick, 2000);
                    } else {
                        resetAppSyncButton();
                        loadAppSyncJobs();
                        loadIntuneFreshness();
                    }
                } catch (e) {
                    showAppSyncProgress(0, window.t('asset-management.settings.status_poll_error'), true);
                    resetAppSyncButton();
                }
            };
            tick();
        }

        function showAppSyncProgress(percent, message, isError) {
            const wrap = document.getElementById('intuneAppSyncProgress');
            const fill = document.getElementById('intuneAppProgressFill');
            const meta = document.getElementById('intuneAppProgressMeta');
            wrap.style.display = '';
            wrap.classList.toggle('intune-error', !!isError);
            fill.style.width = (Math.max(0, Math.min(100, percent || 0))) + '%';
            meta.textContent = message || '';
        }

        function resetAppSyncButton() {
            const btn = document.getElementById('intuneAppSyncBtn');
            btn.disabled = false;
            btn.textContent = window.t('asset-management.settings.sync_software');
        }

        async function loadAppSyncJobs() {
            try {
                const response = await fetch(API_INTUNE + 'list_app_sync_jobs.php');
                const data = await response.json();
                const list = document.getElementById('intuneAppJobsList');
                const eligible = document.getElementById('intuneAppEligible');

                if (!data.success) {
                    list.innerHTML = '';
                    eligible.textContent = '';
                    return;
                }

                eligible.textContent = data.eligible_assets > 0
                    ? window.t('asset-management.settings.eligible_for_sync', { count: data.eligible_assets })
                    : window.t('asset-management.settings.no_eligible_assets');

                if (!data.jobs || data.jobs.length === 0) {
                    list.innerHTML = `<div class="form-hint" style="margin-top: 12px;">${escapeHtml(window.t('asset-management.settings.no_app_sync_jobs'))}</div>`;
                    return;
                }

                // If the latest job is still mid-flight, resume polling
                const latest = data.jobs[0];
                if (latest && (latest.status === 'pending' || latest.status === 'running')) {
                    pollAppSyncStatus(latest.id);
                }

                // Every value escaped - the jobs come from the database, the words from a translation.
                const T = k => escapeHtml(window.t(k));
                list.innerHTML = `
                    <table class="intune-jobs-table">
                        <thead>
                            <tr><th>${T('asset-management.settings.job_col_job')}</th><th>${T('asset-management.field.status')}</th><th>${T('asset-management.settings.job_col_started')}</th><th>${T('asset-management.settings.job_col_finished')}</th><th>${T('asset-management.settings.job_col_result')}</th></tr>
                        </thead>
                        <tbody>
                            ${data.jobs.map(j => `
                                <tr>
                                    <td>#${escapeHtml(j.id)}</td>
                                    <td><span class="intune-job-status ${escapeHtml(j.status)}">${escapeHtml(j.status)}</span></td>
                                    <td>${escapeHtml(j.started_datetime ? fmtDateTime(j.started_datetime) : '-')}</td>
                                    <td>${escapeHtml(j.finished_datetime ? fmtDateTime(j.finished_datetime) : '-')}</td>
                                    <td>${escapeHtml(j.processed)}/${escapeHtml(j.total)}${j.failed > 0 ? ' ' + escapeHtml(window.t('asset-management.settings.failed_count', { failed: j.failed })) : ''}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>`;
            } catch (e) {
                console.error('Error loading app sync jobs:', e);
            }
        }

        document.addEventListener('DOMContentLoaded', loadAppSyncJobs);
        document.addEventListener('DOMContentLoaded', loadIntuneFreshness);

        // ─── Inventory freshness chart ──────────────────────────────────────
        let intuneFreshnessChart = null;

        async function loadIntuneFreshness() {
            try {
                const response = await fetch(API_INTUNE + 'app_sync_freshness.php');
                const data = await response.json();
                if (!data.success) return;

                const wrap = document.getElementById('intuneFreshnessWrap');
                const buckets = data.buckets || {};
                const labels = ['<1d', '1d', '2d', '3d', '4d', '5d', '6d', '7+d', 'never'];
                const values = labels.map(k => buckets[k] || 0);
                const total = values.reduce((s, n) => s + n, 0);

                // Hide chart entirely when there's nothing to show (e.g. no
                // Intune-eligible assets — no point rendering an empty chart).
                if (total === 0) {
                    wrap.style.display = 'none';
                    return;
                }
                wrap.style.display = '';

                // Fresh = green, ageing = amber gradient, never = red
                const colours = ['#107c10', '#3fa83f', '#76c043', '#a8c93a', '#d4c537',
                                 '#e6a82e', '#e07a26', '#d65420', '#d13438'];

                const ctx = document.getElementById('intuneFreshnessChart').getContext('2d');
                if (intuneFreshnessChart) {
                    intuneFreshnessChart.data.datasets[0].data = values;
                    intuneFreshnessChart.update();
                    return;
                }

                intuneFreshnessChart = new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: labels,
                        datasets: [{
                            label: window.t('asset-management.settings.assets_label'),
                            data: values,
                            backgroundColor: colours,
                            borderWidth: 0,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                callbacks: {
                                    label: (ctx) => window.t('asset-management.settings.asset_count', { count: ctx.parsed.y }),
                                },
                            },
                        },
                        scales: {
                            x: { grid: { display: false } },
                            y: { beginAtZero: true, ticks: { precision: 0 } },
                        },
                    },
                });
            } catch (e) {
                console.error('Error loading freshness chart:', e);
            }
        }

    </script>
    <?php endif; ?>
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=87"></script>
</body>
</html>
