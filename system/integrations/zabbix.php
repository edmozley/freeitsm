<?php
/**
 * System → Integrations → Zabbix.
 *
 * Gives the administrator everything Zabbix needs (the address, the token, the
 * webhook media type's parameters and script, each with a Copy button), the
 * choices about the incidents it raises, and the last alerts received.
 *
 * The work itself is in includes/integrations/zabbix.php; this page only reads
 * and writes api/system/zabbix_settings.php. Contributed by Carl Steynvaard
 * (Open Resilience), October 2026.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();
require_once '../../includes/functions.php';
require_once '../../includes/theme.php';
require_once '../../includes/integrations/integrations.php';

$current_page = 'integrations';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix  = '../../';
$translationNamespaces = ['common', 'system'];

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ' . $path_prefix . 'auth/login.php');
    exit;
}

require_once '../../includes/integrations/zabbix.php';
/** The webhook media type's script - one copy, shared with the import file and the tests. */
$zbxScript = zabbixMediaTypeScript();
$t = fn(string $k) => htmlspecialchars(t('system.integrations.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Zabbix')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=2">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff); }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .zbx-wrap { height:calc(100vh - 48px); overflow-y:auto; }
        .zbx-wrap .sys-title { display:flex; align-items:center; gap:10px; }
        .zbx-state { font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; }
        .zbx-state.on  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); }
        .zbx-state.off { background:var(--surface-2,#f3f4f6); color:var(--text-faint,#9ca3af); }
        .zbx-panel { background:var(--surface,#fff); border:1px solid var(--border,#e0e0e0); border-radius:8px; padding:20px; margin-bottom:18px; }
        .zbx-panel h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-dim,#888); }
        .zbx-panel > p { font-size:13px; color:var(--text-dim,#6b7280); margin:0 0 14px; line-height:1.5; }
        .zbx-field { margin-bottom:14px; max-width:520px; }
        .zbx-field label { display:block; font-size:13px; font-weight:600; color:var(--text,#333); margin-bottom:5px; }
        .zbx-field input[type=text], .zbx-field select { width:100%; padding:9px 12px; font-size:13px; box-sizing:border-box;
                    border:1px solid var(--border,#d1d5db); border-radius:6px; background:var(--surface,#fff); color:var(--text,#111827); }
        .zbx-hint { font-size:12px; color:var(--text-faint,#9ca3af); margin-top:5px; }
        .zbx-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
        .zbx-row .lbl { flex:0 0 90px; font-size:13px; font-weight:600; color:var(--text-dim,#6b7280); }
        .zbx-row code { flex:1; font-size:12.5px; background:var(--surface-2,#f6f8fa); border:1px solid var(--border,#e5e7eb);
                        border-radius:6px; padding:8px 10px; word-break:break-all; color:var(--text,#111827); }
        pre.zbx-code { background:var(--surface-2,#f6f8fa); border:1px solid var(--border,#e5e7eb); border-radius:6px;
                       padding:12px; font-size:12.5px; overflow-x:auto; margin:6px 0 8px; }
        .zbx-map { display:grid; grid-template-columns:repeat(auto-fill, minmax(200px, 1fr)); gap:12px; margin-bottom:14px; }
        .zbx-map label { display:block; font-size:12px; font-weight:600; color:var(--text-dim,#6b7280); margin-bottom:4px; }
        .zbx-map select { width:100%; padding:8px 10px; font-size:13px; border:1px solid var(--border,#d1d5db); border-radius:6px;
                          background:var(--surface,#fff); color:var(--text,#111827); }
        .zbx-check { display:flex; gap:8px; align-items:flex-start; font-size:13px; color:var(--text,#333); margin:6px 0 4px; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:6px; font-size:13px;
               font-weight:600; border:none; cursor:pointer; transition:all .15s; }
        .btn-primary { background:var(--sys-accent,#546e7a); color:var(--sys-on-accent,#fff); }
        .btn-primary:hover:not(:disabled) { background:#455a64; }
        .btn-secondary { background:var(--sys-accent-soft,#eceff1); color:#455a64; }
        .btn-small { padding:6px 12px; font-size:12px; }
        .btn:disabled { opacity:.55; cursor:progress; }
        .zbx-result { margin-top:12px; font-size:13px; padding:10px 12px; border-radius:6px; display:none; }
        .zbx-result.on { display:block; }
        .zbx-result.ok  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); border:1px solid var(--success-border,#a7f3d0); }
        .zbx-result.bad { background:var(--danger-bg,#fef2f2); color:var(--danger-text,#991b1b); border:1px solid var(--danger-border,#fecaca); }
        .zbx-warn { background:var(--warning-bg,#fef3c7); color:var(--warning-text,#92400e); border:1px solid var(--warning-border,#fcd34d);
                    border-radius:6px; padding:12px 14px; font-size:13px; margin-bottom:18px; display:none; }
        table.zbx-events { width:100%; border-collapse:collapse; font-size:13px; }
        table.zbx-events th { text-align:left; color:var(--text-dim,#6b7280); font-weight:600; border-bottom:1px solid var(--border,#e5e7eb); padding:6px 8px; }
        table.zbx-events td { border-bottom:1px solid var(--border,#e5e7eb); padding:6px 8px; color:var(--text,#111827); vertical-align:top; }
        .zbx-act-error { color:var(--danger-text,#991b1b); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
</head>
<body data-mobile-module="system" data-mobile-page="integrations-zabbix">
    <?php include '../includes/header.php'; ?>

    <div class="zbx-wrap sys-page">
        <a class="back-link" href="./">&larr; <?php echo htmlspecialchars(t('system.integrations.title')); ?></a>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">Zabbix <span class="zbx-state off" id="zbxState"><?php echo $t('not_configured'); ?></span></h1>
            <p class="sys-subtitle"><?php echo $t('zabbix_intro'); ?></p>
            <p class="sys-subtitle"><strong><?php echo $t('zabbix_direction'); ?></strong></p>
            <a class="help-link" href="./zabbix/help">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true"><circle cx="12" cy="12" r="10"></circle>
                     <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <?php echo htmlspecialchars(t('system.integrations.help_link', ['name' => 'Zabbix'])); ?>
            </a>
        </div></div>
        <div class="zbx-warn" id="notReady"><?php echo $t('zabbix_not_ready'); ?></div>

        <div class="zbx-panel">
            <h3><?php echo $t('zabbix_connect'); ?></h3>
            <p><?php echo $t('zabbix_connect_body'); ?></p>
            <div class="zbx-field" style="max-width:900px"><label for="endpoint"><?php echo $t('zabbix_endpoint'); ?></label>
                <input type="text" id="endpoint" autocomplete="off">
                <div class="zbx-hint"><?php echo $t('zabbix_reach_hint'); ?></div></div>
            <div class="zbx-row"><span class="lbl"><?php echo $t('zabbix_token'); ?></span><code id="token"></code>
                <button class="btn btn-secondary btn-small" data-copy="token" id="copyToken"><?php echo $t('zabbix_copy'); ?></button>
                <button class="btn btn-secondary btn-small" id="rotateBtn"></button></div>

            <h3 style="margin-top:20px"><?php echo $t('zabbix_import'); ?></h3>
            <p><?php echo $t('zabbix_import_body'); ?></p>
            <button class="btn btn-primary" id="downloadBtn"><?php echo $t('zabbix_import_download'); ?></button>
            <div class="zbx-hint" id="downloadNeedsToken" style="display:none"><?php echo $t('zabbix_import_needs_token'); ?></div>

            <h3 style="margin-top:26px"><?php echo $t('zabbix_by_hand'); ?></h3>
            <p style="font-weight:600;margin-bottom:6px"><?php echo $t('zabbix_params'); ?></p>
            <p><?php echo $t('zabbix_params_hint'); ?></p>
            <pre class="zbx-code" id="params"></pre>
            <button class="btn btn-secondary btn-small" data-copy="params"><?php echo $t('zabbix_copy'); ?></button>

            <h3 style="margin-top:20px"><?php echo $t('zabbix_script'); ?></h3>
            <pre class="zbx-code" id="script"><?php echo htmlspecialchars($zbxScript); ?></pre>
            <button class="btn btn-secondary btn-small" data-copy="script"><?php echo $t('zabbix_copy'); ?></button>
            <div class="zbx-result" id="copyResult"></div>
        </div>

        <div class="zbx-panel">
            <h3><?php echo $t('zabbix_tickets'); ?></h3>
            <div class="zbx-field"><label for="reqEmail"><?php echo $t('zabbix_requester'); ?></label>
                <input type="text" id="reqEmail" autocomplete="off">
                <div class="zbx-hint"><?php echo $t('zabbix_requester_hint'); ?></div></div>
            <div class="zbx-field"><label for="reqName"><?php echo $t('zabbix_requester_name'); ?></label>
                <input type="text" id="reqName" autocomplete="off"></div>
            <div class="zbx-field" id="companyField" style="display:none"><label for="company"><?php echo $t('zabbix_company'); ?></label>
                <select id="company"></select>
                <div class="zbx-hint"><?php echo $t('zabbix_company_hint'); ?></div></div>
            <div class="zbx-field"><label for="team"><?php echo $t('zabbix_team'); ?></label><select id="team"></select></div>
            <div class="zbx-field"><label for="origin"><?php echo $t('zabbix_origin'); ?></label><select id="origin"></select></div>

            <h3 style="margin-top:20px"><?php echo $t('zabbix_priority_map'); ?></h3>
            <p><?php echo $t('zabbix_priority_hint'); ?></p>
            <div class="zbx-map" id="prioMap"></div>

            <div class="zbx-field"><label for="resolveStatus"><?php echo $t('zabbix_resolve'); ?></label>
                <select id="resolveStatus"></select>
                <div class="zbx-hint"><?php echo $t('zabbix_resolve_hint'); ?></div></div>
            <label class="zbx-check"><input type="checkbox" id="linkCmdb"> <span><?php echo $t('zabbix_link_cmdb'); ?></span></label>
            <div class="zbx-hint" style="margin:0 0 16px 24px"><?php echo $t('zabbix_link_cmdb_hint'); ?></div>

            <button class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <div class="zbx-result" id="saveResult"></div>
        </div>

        <div class="zbx-panel">
            <h3><?php echo $t('zabbix_test'); ?></h3>
            <p><?php echo $t('zabbix_test_body'); ?></p>
            <p class="zbx-hint" style="margin-top:-6px"><?php echo $t('zabbix_test_limit'); ?></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn btn-primary" id="testBtn"><?php echo $t('zabbix_test_send'); ?></button>
                <button class="btn btn-secondary" id="recoverBtn" style="display:none"><?php echo $t('zabbix_test_recover'); ?></button>
            </div>
            <div class="zbx-result" id="testResult"></div>
        </div>

        <div class="zbx-panel">
            <h3><?php echo $t('zabbix_events'); ?></h3>
            <table class="zbx-events">
                <thead><tr><th><?php echo $t('zabbix_col_when'); ?></th><th><?php echo $t('zabbix_col_host'); ?></th><th><?php echo $t('zabbix_col_problem'); ?></th>
                    <th><?php echo $t('zabbix_col_severity'); ?></th><th><?php echo $t('zabbix_col_result'); ?></th><th><?php echo $t('zabbix_col_ticket'); ?></th></tr></thead>
                <tbody id="events"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function () {
        var API = '../../api/system/zabbix_settings.php';
        var T = <?php echo json_encode([
            'configured' => t('system.integrations.configured'), 'not_configured' => t('system.integrations.not_configured'),
            'none' => t('system.integrations.zabbix_none'), 'leave' => t('system.integrations.zabbix_resolve_leave'),
            'token_none' => t('system.integrations.zabbix_token_none'), 'make' => t('system.integrations.zabbix_token_make'),
            'rotate' => t('system.integrations.zabbix_token_rotate'), 'rotate_confirm' => t('system.integrations.zabbix_rotate_confirm'),
            'copied' => t('system.integrations.zabbix_copied'), 'saved' => t('system.integrations.zabbix_saved'),
            'events_none' => t('system.integrations.zabbix_events_none'), 'recovery' => t('system.integrations.zabbix_recovery'),
            'test_raised' => t('system.integrations.zabbix_test_raised'), 'test_resolved' => t('system.integrations.zabbix_test_resolved'),
            'test_failed' => t('system.integrations.zabbix_test_failed'), 'test_trigger' => t('system.integrations.zabbix_test_trigger'),
            'test_message' => t('system.integrations.zabbix_test_message'),
            'act' => [
                'created' => t('system.integrations.zabbix_act_created'), 'updated' => t('system.integrations.zabbix_act_updated'),
                'resolved' => t('system.integrations.zabbix_act_resolved'), 'recovery_unmatched' => t('system.integrations.zabbix_act_recovery_unmatched'),
                'error' => t('system.integrations.zabbix_act_error'),
            ],
        ], JSON_HEX_TAG | JSON_HEX_AMP); ?>;
        var data = null;
        var $ = function (id) { return document.getElementById(id); };

        function say(id, ok, msg) { var el = $(id); el.className = 'zbx-result on ' + (ok ? 'ok' : 'bad'); el.textContent = msg; }
        function option(value, label, selected) {
            var o = document.createElement('option');
            o.value = String(value); o.textContent = label; o.selected = !!selected;
            return o;
        }
        function fillSelect(sel, rows, current, blank) {
            sel.innerHTML = '';
            if (blank !== null) sel.appendChild(option('0', blank, !current));
            rows.forEach(function (r) { sel.appendChild(option(r.id, r.name, String(r.id) === String(current))); });
        }

        /** The media type's parameters, one "name = value" per line, ready to type into Zabbix. */
        function paramsText() {
            // From the server's list (the import file uses the same one); the endpoint is this page's field.
            return data.parameters.map(function (p) {
                return p[0] + ' = ' + (p[0] === 'endpoint' ? $('endpoint').value.trim() : p[1]);
            }).join('\n');
        }

        function render() {
            var s = data.settings;
            $('notReady').style.display = data.ready ? 'none' : 'block';
            var on = !!data.token;
            $('zbxState').className = 'zbx-state ' + (on ? 'on' : 'off');
            $('zbxState').textContent = on ? T.configured : T.not_configured;
            if (!$('endpoint').value) $('endpoint').value = data.endpoint;
            $('downloadBtn').disabled = !on;
            $('downloadNeedsToken').style.display = on ? 'none' : 'block';
            $('token').textContent = on ? data.token : T.token_none;
            $('copyToken').style.display = on ? '' : 'none';
            $('rotateBtn').textContent = on ? T.rotate : T.make;
            $('params').textContent = paramsText();

            $('reqEmail').value = s.requester_email;
            $('reqName').value = s.requester_name;
            if (data.companies.length) {
                $('companyField').style.display = '';
                fillSelect($('company'), data.companies, s.tenant_id, null);
            }
            fillSelect($('team'), data.teams, s.team_id, T.none);
            fillSelect($('origin'), data.origins, s.origin_id, T.none);
            fillSelect($('resolveStatus'), data.statuses, s.resolve_status_id, T.leave);
            $('linkCmdb').checked = !!s.link_cmdb;

            var map = $('prioMap');
            map.innerHTML = '';
            Object.keys(data.severities).forEach(function (sev) {
                var wrap = document.createElement('div');
                var lbl = document.createElement('label');
                lbl.textContent = data.severities[sev];
                var sel = document.createElement('select');
                sel.dataset.sev = sev;
                fillSelect(sel, data.priorities, s.priority_map[sev], null);
                wrap.appendChild(lbl); wrap.appendChild(sel); map.appendChild(wrap);
            });

            var body = $('events');
            body.innerHTML = '';
            if (!data.events.length) {
                var tr = document.createElement('tr'), td = document.createElement('td');
                td.colSpan = 6; td.className = 'zbx-hint'; td.textContent = T.events_none;
                tr.appendChild(td); body.appendChild(tr);
            }
            data.events.forEach(function (e) {
                var tr = document.createElement('tr');
                var when = window.fmtDateTime ? window.fmtDateTime(e.received_datetime) : e.received_datetime + ' UTC';   // tz.js: stored UTC, shown in the viewer's zone
                var problem = (e.trigger_name || '') + (String(e.event_value) === '0' ? ' (' + T.recovery + ')' : '');
                var result = (T.act[e.action] || e.action) + (e.detail ? ' - ' + e.detail : '');
                [when, e.host, problem, data.severities[e.severity] || e.severity, result].forEach(function (v, i) {
                    var td = document.createElement('td'); td.textContent = v;
                    if (i === 4 && e.action === 'error') td.className = 'zbx-act-error';
                    tr.appendChild(td);
                });
                var tdT = document.createElement('td');
                if (e.ticket_id) {
                    var a = document.createElement('a');
                    a.href = '../../tickets/?ticket_id=' + encodeURIComponent(e.ticket_id);
                    a.textContent = e.ticket_number || ('#' + e.ticket_id);
                    tdT.appendChild(a);
                }
                tr.appendChild(tdT);
                body.appendChild(tr);
            });
        }

        async function load() {
            var d = await (await fetch(API + '?action=get')).json();
            if (!d.success) { say('saveResult', false, d.error || 'Could not load.'); return; }
            data = d;
            render();
        }

        async function post(body) {
            return (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
        }

        $('rotateBtn').addEventListener('click', async function () {
            if (data.token && !(await (window.showConfirm ? showConfirm({ message: T.rotate_confirm }) : confirm(T.rotate_confirm)))) return;
            var d = await post({ action: 'rotate' });
            if (d.success) { data.token = d.token; render(); }
        });

        $('saveBtn').addEventListener('click', async function () {
            var btn = this; btn.disabled = true;
            var map = {};
            document.querySelectorAll('#prioMap select').forEach(function (s) { map[s.dataset.sev] = parseInt(s.value, 10); });
            try {
                var d = await post({
                    action: 'save', requester_email: $('reqEmail').value.trim(), requester_name: $('reqName').value.trim(),
                    tenant_id: data.companies.length ? parseInt($('company').value, 10) : 0,
                    team_id: parseInt($('team').value, 10), origin_id: parseInt($('origin').value, 10),
                    priority_map: map, resolve_status_id: parseInt($('resolveStatus').value, 10),
                    link_cmdb: $('linkCmdb').checked
                });
                if (d.success) { say('saveResult', true, T.saved); load(); }
                else say('saveResult', false, d.error || 'Could not save.');
            } finally { btn.disabled = false; }
        });

        /** Says what the test did, with a link to the ticket it touched. */
        function testSaid(ok, template, d) {
            var el = $('testResult');
            el.className = 'zbx-result on ' + (ok ? 'ok' : 'bad');
            el.textContent = '';
            if (!ok) { el.textContent = T.test_failed.replace('{error}', d.error || d.action || '?'); return; }
            var parts = template.split('{ticket}');
            var a = document.createElement('a');
            a.href = '../../tickets/?ticket_id=' + encodeURIComponent(d.ticket_id);
            a.textContent = d.ticket_number || ('#' + d.ticket_id);
            el.appendChild(document.createTextNode(parts[0] || ''));
            el.appendChild(a);
            el.appendChild(document.createTextNode(parts[1] || ''));
        }
        var testProblem = null;
        $('testBtn').addEventListener('click', async function () {
            var btn = this; btn.disabled = true;
            try {
                var d = await post({ action: 'test', step: 'problem', trigger: T.test_trigger, message: T.test_message });
                var ok = !!d.success && d.action === 'created';
                testSaid(ok, T.test_raised, d);
                testProblem = ok ? d.problem_id : null;
                $('recoverBtn').style.display = ok ? '' : 'none';
                load();
            } finally { btn.disabled = false; }
        });
        $('recoverBtn').addEventListener('click', async function () {
            var btn = this; btn.disabled = true;
            try {
                var d = await post({ action: 'test', step: 'recovery', problem_id: testProblem, trigger: T.test_trigger, message: T.test_message });
                testSaid(!!d.success && d.action === 'resolved', T.test_resolved, d);
                btn.style.display = 'none';
                testProblem = null;
                load();
            } finally { btn.disabled = false; }
        });

        $('endpoint').addEventListener('input', function () { if (data) $('params').textContent = paramsText(); });
        $('downloadBtn').addEventListener('click', function () {
            // A plain navigation: the browser saves the file the API sends.
            window.location.href = API + '?action=export&endpoint=' + encodeURIComponent($('endpoint').value.trim());
        });

        document.querySelectorAll('[data-copy]').forEach(function (b) {
            b.addEventListener('click', async function () {
                var ok = await window.copyToClipboard($(b.dataset.copy).textContent);
                say('copyResult', ok, ok ? T.copied : 'Could not copy - select the text and copy it by hand.');
            });
        });

        load();
    })();
    </script>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
