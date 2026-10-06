<?php
/**
 * Domains → Settings (#154).
 *
 * Five tabs, five capabilities (domains/settings/manifest.php). Only the tabs
 * this analyst may use are rendered at all. Every value is read from and saved
 * to api/domains/settings.php, which validates it and checks the same
 * capability as its tab — the defaults shown here come from the server, so
 * the screen and the server cannot disagree about "not set yet".
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

require_once '../../includes/settings_manifest.php';
requireModuleAccess('domains');

$settingsManifest = settingsManifestFor('domains');
$visibleTabs      = settingsVisibleTabs(connectToDatabase(), (int) $_SESSION['analyst_id'], $settingsManifest);
$activeTabId      = settingsFirstTabId($visibleTabs);
if (!empty($_GET['tab']) && settingsTabVisible($visibleTabs, (string) $_GET['tab'])) {
    $activeTabId = (string) $_GET['tab'];
}

$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'domains'];
$tt = fn(string $k) => htmlspecialchars(t('domains.settings.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo BASE_URL; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName() . ' - ' . t('domains.title') . ' - ' . t('domains.nav.settings')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=25">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <link rel="stylesheet" href="../../assets/css/domains.css?v=7">
    <style>
        /* Full-width settings page. ⚠️ max-width alone is not enough: inbox.css's
           `.container { margin: 30px auto }` would keep the gutters. */
        .container { height: calc(100vh - 62px); overflow-y: auto; max-width: none; width: 100%; margin: 0; padding: 16px 30px 30px; box-sizing: border-box; }
        .tab:hover { color: var(--accent); }
        .tab.active { color: var(--accent); border-bottom-color: var(--accent); }
        .set-row { display: grid; grid-template-columns: 300px 1fr; gap: 20px; padding: 16px 0; border-bottom: 1px solid var(--border-soft, #eee); }
        .set-row:last-of-type { border-bottom: none; }
        .set-row .lbl { font-weight: 600; font-size: 14px; color: var(--text, #333); }
        .set-row .desc { font-size: 12.5px; color: var(--text-dim, #888); margin-top: 4px; line-height: 1.5; }
        .set-row select, .set-row input[type="text"], .set-row input[type="number"], .set-row textarea {
            padding: 8px 10px; border: 1px solid var(--border, #ddd); border-radius: 6px; font-size: 14px; width: 100%; max-width: 460px;
            background: var(--surface, #fff); color: var(--text, #333); box-sizing: border-box; font-family: inherit;
        }
        .set-row .dflt { font-size: 11.5px; color: var(--text-dim, #999); margin-top: 5px; }
        .set-actions { display: flex; gap: 10px; align-items: center; margin-top: 18px; }
        .set-box { background: var(--surface-2, #f8fafc); border: 1px solid var(--border-soft, #e5e7eb); border-radius: 8px; padding: 14px 16px; font-size: 13px; margin: 6px 0 16px; line-height: 1.55; }
        .set-box code { word-break: break-all; }
        .st-list .action-btn { background: none; border: 1px solid var(--border, #ddd); color: var(--text-muted, #666); cursor: pointer; padding: 6px; margin-right: 4px; border-radius: 4px; display: inline-flex; }
        .st-list .action-btn:hover { border-color: var(--accent); color: var(--accent); }
        .st-list .action-btn.delete:hover { border-color: #d13438; color: #d13438; }
        .st-list .action-btn svg { width: 16px; height: 16px; }
        @media (max-width: 900px) { .set-row { grid-template-columns: 1fr; gap: 8px; } }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=169">
</head>
<body data-mobile-module="domains" data-mobile-page="settings">
    <?php include '../includes/header.php'; ?>
    <div class="container">
        <?php if (!$visibleTabs): ?>
            <div class="dom-card"><div class="dom-empty"><h3><?php echo $tt('no_tabs_title'); ?></h3><p><?php echo $tt('no_tabs_body'); ?></p></div></div>
        <?php else: renderSettingsTabBar($visibleTabs, $activeTabId); endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'statuses')): ?>
        <div class="tab-content<?php echo $activeTabId === 'statuses' ? ' active' : ''; ?>" id="statuses-tab" data-capability="<?php echo Cap::DOMAINS_STATUSES; ?>">
            <div class="section-header" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px">
                <div><h2 style="margin:0;font-size:18px"><?php echo $tt('statuses_title'); ?></h2><div class="dom-hint" style="font-size:13px"><?php echo $tt('statuses_intro'); ?></div></div>
                <button type="button" class="dom-btn primary" id="stAdd"><?php echo htmlspecialchars(t('common.add')); ?></button>
            </div>
            <table class="st-list"><thead><tr>
                <th><?php echo $tt('st_name'); ?></th><th><?php echo $tt('st_alerts'); ?></th><th><?php echo $tt('st_active'); ?></th><th><?php echo $tt('st_in_use'); ?></th><th><?php echo $tt('st_order'); ?></th><th></th>
            </tr></thead><tbody id="stBody"></tbody></table>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'alerts')): ?>
        <div class="tab-content<?php echo $activeTabId === 'alerts' ? ' active' : ''; ?>" id="alerts-tab" data-capability="<?php echo Cap::DOMAINS_ALERTS; ?>" data-settings-tab="alerts">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('alerts_title'); ?></h2>
            <div class="set-box" id="alertsState"></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('alerts_enabled'); ?></div><div class="desc"><?php echo $tt('alerts_enabled_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_alerts_enabled"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_alerts_enabled"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('alert_days'); ?></div><div class="desc"><?php echo $tt('alert_days_desc'); ?></div></div>
                <div><input type="text" data-k="domain_alert_days"><div class="dflt" data-d="domain_alert_days"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('chase'); ?></div><div class="desc"><?php echo $tt('chase_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_alert_chase_expired"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_alert_chase_expired"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('auto_renew'); ?></div><div class="desc"><?php echo $tt('auto_renew_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_alert_auto_renew"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_alert_auto_renew"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('recipients'); ?></div><div class="desc"><?php echo $tt('recipients_desc'); ?></div></div>
                <div><select data-k="domain_alert_recipients">
                    <option value="owner"><?php echo $tt('rcp_owner'); ?></option>
                    <option value="list"><?php echo $tt('rcp_list'); ?></option>
                    <option value="both"><?php echo $tt('rcp_both'); ?></option></select><div class="dflt" data-d="domain_alert_recipients"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('emails'); ?></div><div class="desc"><?php echo $tt('emails_desc'); ?></div></div>
                <div><textarea data-k="domain_alert_emails" rows="2" placeholder="it-team@example.com, finance@example.com"></textarea></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ssl_alerts'); ?></div><div class="desc"><?php echo $tt('ssl_alerts_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_ssl_alerts"><span class="toggle-slider"></span></span></label>
                    <div style="margin-top:8px"><?php echo $tt('ssl_days'); ?> <input type="number" min="1" max="120" data-k="domain_ssl_warn_days" style="width:90px"></div><div class="dflt" data-d="domain_ssl_warn_days"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('change_alerts'); ?></div><div class="desc"><?php echo $tt('change_alerts_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_change_alerts"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_change_alerts"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('action'); ?></div><div class="desc"><?php echo $tt('action_desc'); ?></div></div>
                <div><select data-k="domain_renewal_action">
                    <option value="none"><?php echo $tt('act_none'); ?></option>
                    <option value="task"><?php echo $tt('act_task'); ?></option>
                    <option value="ticket"><?php echo $tt('act_ticket'); ?></option></select>
                    <div style="margin-top:8px"><?php echo $tt('act_days'); ?> <input type="number" min="1" max="365" data-k="domain_renewal_action_days" style="width:90px"></div><div class="dflt" data-d="domain_renewal_action"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('surface'); ?></div><div class="desc"><?php echo $tt('surface_desc'); ?></div></div>
                <div><select data-k="domain_expiry_surface">
                    <option value="off"><?php echo $tt('sf_off'); ?></option>
                    <option value="dashboard"><?php echo $tt('sf_dashboard'); ?></option>
                    <option value="calendar"><?php echo $tt('sf_calendar'); ?></option>
                    <option value="both"><?php echo $tt('sf_both'); ?></option></select><div class="dflt" data-d="domain_expiry_surface"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('cert_surface'); ?></div><div class="desc"><?php echo $tt('cert_surface_desc'); ?></div></div>
                <div><select data-k="domain_cert_surface">
                    <option value="off"><?php echo $tt('sf_off'); ?></option>
                    <option value="dashboard"><?php echo $tt('sf_dashboard'); ?></option>
                    <option value="calendar"><?php echo $tt('sf_calendar'); ?></option>
                    <option value="both"><?php echo $tt('sf_both'); ?></option></select><div class="dflt" data-d="domain_cert_surface"></div></div></div>
            <div class="set-actions">
                <button type="button" class="dom-btn primary" data-save="alerts"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button type="button" class="dom-btn" id="btnPreview"><?php echo $tt('preview'); ?></button>
                <span id="previewOut" class="dom-hint" style="font-size:13px"></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'monitoring')): ?>
        <div class="tab-content<?php echo $activeTabId === 'monitoring' ? ' active' : ''; ?>" id="monitoring-tab" data-capability="<?php echo Cap::DOMAINS_MONITORING; ?>" data-settings-tab="monitoring">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('monitoring_title'); ?></h2>
            <div class="set-box" id="schedState"></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('lookup_mode'); ?></div><div class="desc"><?php echo $tt('lookup_mode_desc'); ?></div></div>
                <div><select data-k="domain_lookup_mode">
                    <option value="off"><?php echo $tt('lm_off'); ?></option>
                    <option value="on_add"><?php echo $tt('lm_on_add'); ?></option>
                    <option value="scheduled"><?php echo $tt('lm_scheduled'); ?></option></select>
                    <div style="margin-top:8px"><?php echo $tt('refresh_days'); ?> <input type="number" min="1" max="90" data-k="domain_lookup_refresh_days" style="width:90px"></div><div class="dflt" data-d="domain_lookup_mode"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('overwrite'); ?></div><div class="desc"><?php echo $tt('overwrite_desc'); ?></div></div>
                <div><select data-k="domain_lookup_overwrite">
                    <option value="always"><?php echo $tt('ow_always'); ?></option>
                    <option value="blanks"><?php echo $tt('ow_blanks'); ?></option></select><div class="dflt" data-d="domain_lookup_overwrite"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('checks'); ?></div><div class="desc"><?php echo $tt('checks_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_checks_enabled"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_checks_enabled"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('resolver'); ?></div><div class="desc"><?php echo $tt('resolver_desc'); ?></div></div>
                <div><select data-k="domain_dns_resolver">
                    <option value="auto"><?php echo $tt('rs_auto'); ?></option>
                    <option value="system"><?php echo $tt('rs_system'); ?></option>
                    <option value="google"><?php echo $tt('rs_google'); ?></option>
                    <option value="cloudflare"><?php echo $tt('rs_cloudflare'); ?></option></select><div class="dflt" data-d="domain_dns_resolver"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ct'); ?></div><div class="desc"><?php echo $tt('ct_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_ct_watch"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_ct_watch"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('lookalike'); ?></div><div class="desc"><?php echo $tt('lookalike_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_lookalike_scan"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_lookalike_scan"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('opportunistic'); ?></div><div class="desc"><?php echo $tt('opportunistic_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_opportunistic"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_opportunistic"></div></div></div>
            <div class="set-actions">
                <button type="button" class="dom-btn primary" data-save="monitoring"><?php echo htmlspecialchars(t('common.save')); ?></button>
                <button type="button" class="dom-btn" id="btnRunNow"><?php echo $tt('run_now'); ?></button>
                <span id="runOut" class="dom-hint" style="font-size:13px"></span>
            </div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'service-status')):
            // The impact choices are Service Status's own levels (install config).
            $ssLevels = [];
            try { $ssLevels = connectToDatabase()->query("SELECT id, name FROM service_impact_levels WHERE is_active = 1 ORDER BY severity_order, display_order, id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) {}
        ?>
        <div class="tab-content<?php echo $activeTabId === 'service-status' ? ' active' : ''; ?>" id="service-status-tab" data-capability="<?php echo Cap::DOMAINS_SERVICE_STATUS; ?>" data-settings-tab="service-status">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('ss_title'); ?></h2>
            <div class="set-box"><?php echo $tt('ss_intro'); ?></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_mode'); ?></div><div class="desc"><?php echo $tt('ss_mode_desc'); ?></div></div>
                <div><select data-k="domain_status_mode">
                    <option value="off"><?php echo $tt('ss_off'); ?></option>
                    <option value="suggest"><?php echo $tt('ss_suggest'); ?></option>
                    <option value="auto"><?php echo $tt('ss_auto'); ?></option></select><div class="dflt" data-d="domain_status_mode"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_on_expired'); ?></div><div class="desc"><?php echo $tt('ss_on_expired_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_status_on_expired"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_status_on_expired"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_on_cert'); ?></div><div class="desc"><?php echo $tt('ss_on_cert_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_status_on_cert"><span class="toggle-slider"></span></span></label>
                    <div style="margin-top:8px"><?php echo $tt('ss_cert_days'); ?> <input type="number" min="0" max="60" data-k="domain_status_cert_days" style="width:90px"></div><div class="dflt" data-d="domain_status_cert_days"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_impact'); ?></div><div class="desc"><?php echo $tt('ss_impact_desc'); ?></div></div>
                <div><select data-k="domain_status_impact">
                    <option value=""><?php echo $tt('ss_impact_auto'); ?></option>
                    <?php foreach ($ssLevels as $lv): ?><option value="<?php echo (int)$lv['id']; ?>"><?php echo htmlspecialchars($lv['name']); ?></option><?php endforeach; ?>
                </select><div class="dflt" data-d="domain_status_impact"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_public'); ?></div><div class="desc"><?php echo $tt('ss_public_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_status_public"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_status_public"></div></div></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('ss_resolve'); ?></div><div class="desc"><?php echo $tt('ss_resolve_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_status_auto_resolve"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_status_auto_resolve"></div></div></div>
            <div class="set-actions"><button type="button" class="dom-btn primary" data-save="service-status"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>

        <?php if (settingsTabVisible($visibleTabs, 'auth-codes')): ?>
        <div class="tab-content<?php echo $activeTabId === 'auth-codes' ? ' active' : ''; ?>" id="auth-codes-tab" data-capability="<?php echo Cap::DOMAINS_AUTH_CODES; ?>" data-settings-tab="auth-codes">
            <h2 style="margin:0 0 4px;font-size:18px"><?php echo $tt('codes_title'); ?></h2>
            <div class="set-box"><?php echo $tt('codes_intro'); ?></div>
            <div class="set-row"><div><div class="lbl"><?php echo $tt('code_audit'); ?></div><div class="desc"><?php echo $tt('code_audit_desc'); ?></div></div>
                <div><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" data-k="domain_auth_code_audit"><span class="toggle-slider"></span></span></label><div class="dflt" data-d="domain_auth_code_audit"></div></div></div>
            <div class="set-actions"><button type="button" class="dom-btn primary" data-save="auth-codes"><?php echo htmlspecialchars(t('common.save')); ?></button></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Status editor -->
    <div class="modal" id="mStatus">
        <div class="modal-content" style="max-width:480px">
            <div class="modal-header" id="stTitle"></div>
            <div class="modal-body">
                <div class="form-group"><label for="stName"><?php echo $tt('st_name'); ?></label><input type="text" id="stName" maxlength="100"></div>
                <div class="form-group"><label for="stColour"><?php echo $tt('st_colour'); ?></label><input type="color" id="stColour" value="#16a34a" style="width:70px;height:36px;padding:2px"></div>
                <div class="form-group"><label for="stOrder"><?php echo $tt('st_order'); ?></label><input type="number" id="stOrder" value="0"></div>
                <div class="form-group"><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" id="stAlerts" checked><span class="toggle-slider"></span></span> <?php echo $tt('st_alerts_long'); ?></label></div>
                <div class="form-group"><label class="toggle-label"><span class="toggle-switch"><input type="checkbox" id="stActive" checked><span class="toggle-slider"></span></span> <?php echo $tt('st_active'); ?></label></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-close="mStatus"><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                <button type="button" class="btn btn-primary" id="stSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/domains.js?v=1"></script>
    <script>
    (function () {
        'use strict';
        const { T, esc, api } = window.Dom;
        let S = {}, defs = {};
        let editing = null;

        window.switchTab = function (tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.toggle('active', c.id === tab + '-tab'));
            history.replaceState(null, '', '?tab=' + tab);
        };

        const DFLT = v => ({ '1': T('settings.on'), '0': T('settings.off') }[v] ?? v);

        async function loadSettings() {
            const d = await api('settings.php');
            // Stored as UTC 'Y-m-d H:i:s'; shown in the viewer's own format and zone.
            if (d.last_run && window.fmtDateTime) d.last_run = fmtDateTime(d.last_run.replace(' ', 'T') + 'Z');
            S = d.settings; defs = d.definitions;
            document.querySelectorAll('[data-k]').forEach(el => {
                const v = S[el.dataset.k] ?? '';
                if (el.type === 'checkbox') el.checked = v === '1'; else el.value = v;
            });
            document.querySelectorAll('[data-d]').forEach(el => {
                const def = defs[el.dataset.d];
                if (def) el.textContent = T('settings.default_is', { v: labelFor(el.dataset.d, def.default) });
            });
            const a = document.getElementById('alertsState');
            if (a) a.innerHTML = S.domain_alerts_enabled === '1'
                ? esc(T('settings.alerts_on_state', { when: d.last_run ? d.last_run : T('settings.never') }))
                : esc(T('settings.alerts_off_state'));
            const s = document.getElementById('schedState');
            if (s) {
                const c = d.counts || {};
                s.innerHTML = '<div>' + esc(T('settings.sched_state', { total: c.total || 0, never_l: c.never_looked_up || 0, never_c: c.never_checked || 0, when: d.last_run || T('settings.never') })) + '</div>'
                    + '<div style="margin-top:8px">' + esc(T('settings.cron_intro')) + '</div>'
                    + '<div style="margin-top:6px"><code class="dom-code">php ' + esc(d.cron_path || 'cron/domains.php') + '</code></div>'
                    + (d.cron_url ? '<div style="margin-top:6px">' + esc(T('settings.cron_url')) + ' <code class="dom-code">' + esc(d.cron_url) + '</code></div>' : '')
                    + (!d.has_intl ? '<div style="margin-top:8px;color:#b45309">' + esc(T('settings.no_intl')) + '</div>' : '');
            }
        }
        function labelFor(key, v) {
            const sel = document.querySelector('select[data-k="' + key + '"]');
            if (sel) { const o = [...sel.options].find(o => o.value === v); if (o) return o.textContent; }
            return DFLT(v);
        }

        document.addEventListener('click', async e => {
            const btn = e.target.closest('[data-save]');
            if (btn) {
                const tab = btn.dataset.save;
                const settings = {};
                document.querySelectorAll('[data-settings-tab="' + tab + '"] [data-k]').forEach(el => {
                    settings[el.dataset.k] = el.type === 'checkbox' ? (el.checked ? '1' : '0') : el.value;
                });
                btn.disabled = true;
                try { await api('settings.php', { action: 'save', tab, settings }); showToast(T('settings.saved'), 'success'); await loadSettings(); }
                catch (err) { showToast(err.message, 'error'); } finally { btn.disabled = false; }
            }
            if (e.target.closest('[data-close]')) window.Dom.closeModal(e.target.closest('[data-close]').dataset.close);
        });

        const pv = document.getElementById('btnPreview');
        if (pv) pv.addEventListener('click', async () => {
            pv.disabled = true;
            try {
                const d = (await api('settings.php', { action: 'preview' })).preview;
                document.getElementById('previewOut').textContent = d.due === 0 ? T('settings.preview_none')
                    : T('settings.preview_result', { due: d.due, people: d.recipients }) + (d.would_email.length ? ' ' + d.would_email.map(w => w.email + ' (' + w.count + ')').join(', ') : '');
            } catch (err) { showToast(err.message, 'error'); } finally { pv.disabled = false; }
        });
        const rn = document.getElementById('btnRunNow');
        if (rn) rn.addEventListener('click', async () => {
            rn.disabled = true; document.getElementById('runOut').textContent = T('settings.running');
            try {
                const r = (await api('settings.php', { action: 'run_now' })).run;
                document.getElementById('runOut').textContent = T('settings.run_result', { lookups: r.lookups, checks: r.checks, events: (r.alerts || {}).events || 0, emails: (r.alerts || {}).emails || 0, secs: r.seconds })
                    + (r.out_of_time ? ' ' + T('settings.run_more') : '');
                await loadSettings();
            } catch (err) { document.getElementById('runOut').textContent = err.message; } finally { rn.disabled = false; }
        });

        // ---- statuses ------------------------------------------------------------
        const SVG_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const SVG_DEL = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';
        let statuses = [];
        async function loadStatuses() {
            const body = document.getElementById('stBody'); if (!body) return;
            statuses = (await api('statuses.php')).statuses;
            body.innerHTML = statuses.map(s => '<tr data-id="' + s.id + '"><td>' + window.Dom.status(s.name, s.colour) + '</td>'
                + '<td>' + (s.alerts_enabled ? esc(T('settings.on')) : '<span class="dom-pill amber">' + esc(T('settings.off')) + '</span>') + '</td>'
                + '<td>' + (s.is_active ? '<span class="status-badge status-active">' + esc(T('settings.on')) + '</span>' : '<span class="status-badge status-inactive">' + esc(T('settings.off')) + '</span>') + '</td>'
                + '<td>' + s.in_use + '</td><td>' + s.display_order + '</td>'
                + '<td><button type="button" class="action-btn st-edit" title="' + esc(window.t('common.edit')) + '">' + SVG_EDIT + '</button>'
                + '<button type="button" class="action-btn delete st-del" title="' + esc(window.t('common.delete')) + '">' + SVG_DEL + '</button></td></tr>').join('');
        }
        function openStatus(s) {
            editing = s ? s.id : null;
            document.getElementById('stTitle').textContent = s ? T('settings.st_edit') : T('settings.st_add');
            document.getElementById('stName').value = s ? s.name : '';
            document.getElementById('stColour').value = (s && s.colour) || '#16a34a';
            document.getElementById('stOrder').value = s ? s.display_order : (statuses.length + 1);
            document.getElementById('stAlerts').checked = s ? s.alerts_enabled : true;
            document.getElementById('stActive').checked = s ? s.is_active : true;
            window.Dom.openModal('mStatus');
        }
        const stBody = document.getElementById('stBody');
        if (stBody) {
            document.getElementById('stAdd').addEventListener('click', () => openStatus(null));
            // 🔴 closest(), never e.target.classList: the click lands on the svg.
            stBody.addEventListener('click', async e => {
                const tr = e.target.closest('tr[data-id]'); if (!tr) return;
                const s = statuses.find(x => String(x.id) === tr.dataset.id);
                if (e.target.closest('.st-edit')) openStatus(s);
                if (e.target.closest('.st-del')) {
                    if (!(await showConfirm({ title: T('settings.st_delete'), message: T('settings.st_delete_body', { name: s.name }), okLabel: window.t('common.delete'), okClass: 'danger' }))) return;
                    try { await api('statuses.php', { action: 'delete', id: s.id }); await loadStatuses(); } catch (err) { showToast(err.message, 'error'); }
                }
            });
            document.getElementById('stSave').addEventListener('click', async () => {
                try {
                    await api('statuses.php', { action: 'save', id: editing, name: document.getElementById('stName').value, colour: document.getElementById('stColour').value,
                        display_order: document.getElementById('stOrder').value, alerts_enabled: document.getElementById('stAlerts').checked ? 1 : 0, is_active: document.getElementById('stActive').checked ? 1 : 0 });
                    window.Dom.closeModal('mStatus'); await loadStatuses();
                } catch (err) { showToast(err.message, 'error'); }
            });
        }

        document.addEventListener('DOMContentLoaded', () => { loadSettings().catch(e => showToast(e.message, 'error')); loadStatuses().catch(() => {}); });
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=71"></script>
</body>
</html>
