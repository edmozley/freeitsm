<?php
/**
 * System -> Integrations -> Microsoft 365 -> Intune (GH #263).
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
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Microsoft Intune')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="<?php echo BASE_URL; ?>assets/js/i18n.js?v=4"></script>
    <?php echo Tz::scriptTag(); ?>
    <script src="<?php echo BASE_URL; ?>assets/js/tz.js?v=5"></script>
    <script src="<?php echo BASE_URL; ?>assets/js/chart.min.js"></script>
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
        <p class="ms-crumb"><?php if ($isAdmin): ?><a href="../"><?php echo htmlspecialchars(t('system.integrations.title')); ?></a> &rsaquo; <?php endif; ?><a href="./">Microsoft 365</a></p>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">Microsoft Intune</h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars(t('system.integrations.ms365_intune_subtitle')); ?></p>
        </div></div>

        <div class="settings-section">
            <div class="settings-section-header">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="12" rx="2" ry="2"></rect>
                    <line x1="8" y1="20" x2="16" y2="20"></line>
                    <line x1="12" y1="16" x2="12" y2="20"></line>
                </svg>
                <h2><?php echo htmlspecialchars(t('asset-management.settings.intune_heading')); ?></h2>
            </div>
            <div class="settings-section-body">
                <p class="settings-description">
                    <?php echo htmlspecialchars(t('asset-management.settings.intune_intro')); ?>
                </p>
                <form id="intuneForm" onsubmit="saveIntuneSettings(event)">
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
                    <?php /* GH #336: which of this install's asset types each kind of Intune device gets. */ ?>
                    <div class="form-group">
                        <label class="form-label"><?php echo htmlspecialchars(t('asset-management.settings.intune_types_label')); ?></label>
                        <div class="form-hint" style="margin-bottom:8px;"><?php echo htmlspecialchars(t('asset-management.settings.intune_types_hint')); ?></div>
                        <table id="intuneTypeMap" style="border-collapse:collapse;font-size:13px;">
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
                    <div class="form-group">
                        <label class="form-label" for="intuneAppBatchSize"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_label')); ?></label>
                        <input type="number" class="form-input" id="intuneAppBatchSize" min="1" max="500" value="30" style="max-width: 140px;">
                        <div class="form-hint"><?php echo htmlspecialchars(t('asset-management.settings.batch_size_hint')); ?></div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary" id="intuneSaveBtn"><?php echo htmlspecialchars(t('asset-management.common.save')); ?></button>
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
                </form>

                <div class="intune-software-section">
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
    
    </div>

    <script>
        const API_INTUNE   = '<?php echo BASE_URL; ?>api/intune/';
        const API_SETTINGS = '<?php echo BASE_URL; ?>api/settings/';

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // The Intune settings, as the Assets settings page read them. Secret fields
        // are left empty; the placeholder tells the user one is already saved, and
        // the save endpoint treats a blank as "keep existing".
        async function loadIntuneSettings() {
            try {
                const response = await fetch(API_SETTINGS + 'get_system_settings.php');
                const data = await response.json();
                if (data.success && data.settings) {
                    document.getElementById('intuneTenantId').value = data.settings.intune_tenant_id || '';
                    document.getElementById('intuneClientId').value = data.settings.intune_client_id || '';
                    const intSecField = document.getElementById('intuneClientSecret');
                    intSecField.value = '';
                    intSecField.placeholder = data.settings.intune_client_secret
                        ? window.t('asset-management.settings.secret_saved_placeholder')
                        : window.t('asset-management.settings.intune_secret_placeholder');
                    // batch size: default to 30 if not stored
                    const batch = parseInt(data.settings.intune_app_batch_size, 10);
                    document.getElementById('intuneAppBatchSize').value = (batch > 0 ? batch : 30);
                    if (document.getElementById('intuneCompanyId')) {
                        document.getElementById('intuneCompanyId').value = data.settings.intune_company_id || '';
                    }
                    document.getElementById('intuneSyncHostnames').checked = data.settings.intune_sync_hostnames === '1';
                    document.getElementById('intuneAssignPrimaryUser').checked = data.settings.intune_assign_primary_user === '1';
                    document.getElementById('intuneFollowPrimaryUser').checked = data.settings.intune_follow_primary_user === '1';
                    fillIntuneTypeMap();
                }
            } catch (e) {
                console.error('Error loading the Intune settings:', e);
            }
        }
        document.addEventListener('DOMContentLoaded', loadIntuneSettings);

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

        async function saveIntuneSettings(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('intuneSaveBtn');
            saveBtn.disabled = true;
            saveBtn.textContent = window.t('asset-management.settings.saving');

            try {
                const response = await fetch(API_SETTINGS + 'save_system_settings.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        settings: {
                            intune_tenant_id: document.getElementById('intuneTenantId').value.trim(),
                            intune_client_id: document.getElementById('intuneClientId').value.trim(),
                            intune_client_secret: document.getElementById('intuneClientSecret').value,
                            intune_app_batch_size: String(Math.max(1, Math.min(500, parseInt(document.getElementById('intuneAppBatchSize').value, 10) || 30))),
                            intune_company_id: document.getElementById('intuneCompanyId') ? document.getElementById('intuneCompanyId').value.trim() : '',
                            intune_sync_hostnames: document.getElementById('intuneSyncHostnames').checked ? '1' : '0',
                            intune_assign_primary_user: document.getElementById('intuneAssignPrimaryUser').checked ? '1' : '0',
                            intune_follow_primary_user: document.getElementById('intuneFollowPrimaryUser').checked ? '1' : '0',
                            // Only once the mapping has loaded - see intuneTypeMapLoaded.
                            ...(intuneTypeMapLoaded ? { intune_type_map: readIntuneTypeMap() } : {})
                        }
                    })
                });
                const data = await response.json();
                if (data.success) {
                    showToast(window.t('asset-management.settings.settings_saved'), 'success');
                    loadIntuneSettings();
                } else {
                    showToast(window.t('asset-management.toast.error', { error: data.error }), 'error');
                }
            } catch (error) {
                showToast(window.t('asset-management.settings.save_settings_failed'), 'error');
            }

            saveBtn.disabled = false;
            saveBtn.textContent = window.t('asset-management.common.save');
        }

        function toggleIntuneSecret() {
            const input = document.getElementById('intuneClientSecret');
            const btn = input.nextElementSibling;
            if (input.type === 'password') { input.type = 'text'; btn.textContent = window.t('asset-management.settings.hide'); }
            else { input.type = 'password'; btn.textContent = window.t('asset-management.settings.show'); }
        }

        // InTune sync
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
                    list.innerHTML = `<div class="form-hint" style="margin-top: 12px;">${window.t('asset-management.settings.no_app_sync_jobs')}</div>`;
                    return;
                }

                // If the latest job is still mid-flight, resume polling
                const latest = data.jobs[0];
                if (latest && (latest.status === 'pending' || latest.status === 'running')) {
                    pollAppSyncStatus(latest.id);
                }

                list.innerHTML = `
                    <table class="intune-jobs-table">
                        <thead>
                            <tr><th>${window.t('asset-management.settings.job_col_job')}</th><th>${window.t('asset-management.field.status')}</th><th>${window.t('asset-management.settings.job_col_started')}</th><th>${window.t('asset-management.settings.job_col_finished')}</th><th>${window.t('asset-management.settings.job_col_result')}</th></tr>
                        </thead>
                        <tbody>
                            ${data.jobs.map(j => `
                                <tr>
                                    <td>#${j.id}</td>
                                    <td><span class="intune-job-status ${escapeHtml(j.status)}">${escapeHtml(j.status)}</span></td>
                                    <td>${j.started_datetime ? fmtDateTime(j.started_datetime) : '-'}</td>
                                    <td>${j.finished_datetime ? fmtDateTime(j.finished_datetime) : '-'}</td>
                                    <td>${j.processed}/${j.total}${j.failed > 0 ? ` ${window.t('asset-management.settings.failed_count', { failed: j.failed })}` : ''}</td>
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
    <script src="<?php echo BASE_URL; ?>assets/js/mobile.js?v=83"></script>
</body>
</html>
