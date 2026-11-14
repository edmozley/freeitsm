<?php
/**
 * System → Integrations → 3CX.
 *
 * Gives the administrator everything 3CX needs (the address and the token, in a
 * CRM template to download and upload), the choices about which ticket a call
 * goes on and who writes its note, the click-to-call dial address, a "Try a
 * call" that shows what a call would do, and the last calls reported.
 *
 * The work itself is in includes/integrations/threecx.php; this page only reads
 * and writes api/system/threecx_settings.php. Contributed by Carl Steynvaard
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
$t = fn(string $k) => htmlspecialchars(t('system.integrations.' . $k));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), '3CX')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=3">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff); }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .tcx-wrap { height:calc(100vh - 48px); overflow-y:auto; }
        .tcx-wrap .sys-title { display:flex; align-items:center; gap:10px; }
        .tcx-state { font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; }
        .tcx-state.on  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); }
        .tcx-state.off { background:var(--surface-2,#f3f4f6); color:var(--text-faint,#9ca3af); }
        .tcx-panel { background:var(--surface,#fff); border:1px solid var(--border,#e0e0e0); border-radius:8px; padding:20px; margin-bottom:18px; }
        .tcx-panel h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-dim,#888); }
        .tcx-panel > p { font-size:13px; color:var(--text-dim,#6b7280); margin:0 0 14px; line-height:1.5; }
        .tcx-field { margin-bottom:14px; max-width:520px; }
        .tcx-field label { display:block; font-size:13px; font-weight:600; color:var(--text,#333); margin-bottom:5px; }
        .tcx-field input[type=text], .tcx-field select { width:100%; padding:9px 12px; font-size:13px; box-sizing:border-box;
                    border:1px solid var(--border,#d1d5db); border-radius:6px; background:var(--surface,#fff); color:var(--text,#111827); }
        .tcx-hint { font-size:12px; color:var(--text-faint,#9ca3af); margin-top:5px; line-height:1.45; }
        .tcx-row { display:flex; gap:8px; align-items:center; margin-bottom:10px; }
        .tcx-row .lbl { flex:0 0 90px; font-size:13px; font-weight:600; color:var(--text-dim,#6b7280); }
        .tcx-row code { flex:1; font-size:12.5px; background:var(--surface-2,#f6f8fa); border:1px solid var(--border,#e5e7eb);
                        border-radius:6px; padding:8px 10px; word-break:break-all; color:var(--text,#111827); }
        .tcx-check { display:flex; gap:8px; align-items:flex-start; font-size:13px; color:var(--text,#333); margin:6px 0 4px; }
        .tcx-try { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
        .tcx-try .tcx-field { margin-bottom:0; flex:1 1 200px; }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:6px; font-size:13px;
               font-weight:600; border:none; cursor:pointer; transition:all .15s; }
        .btn-primary { background:var(--sys-accent,#546e7a); color:var(--sys-on-accent,#fff); }
        .btn-primary:hover:not(:disabled) { background:#455a64; }
        .btn-secondary { background:var(--sys-accent-soft,#eceff1); color:#455a64; }
        .btn-small { padding:6px 12px; font-size:12px; }
        .btn:disabled { opacity:.55; cursor:progress; }
        .tcx-result { margin-top:12px; font-size:13px; padding:10px 12px; border-radius:6px; display:none; line-height:1.5; }
        .tcx-result.on { display:block; }
        .tcx-result.ok  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); border:1px solid var(--success-border,#a7f3d0); }
        .tcx-result.bad { background:var(--danger-bg,#fef2f2); color:var(--danger-text,#991b1b); border:1px solid var(--danger-border,#fecaca); }
        .tcx-warn { background:var(--warning-bg,#fef3c7); color:var(--warning-text,#92400e); border:1px solid var(--warning-border,#fcd34d);
                    border-radius:6px; padding:12px 14px; font-size:13px; margin-bottom:18px; display:none; }
        table.tcx-calls { width:100%; border-collapse:collapse; font-size:13px; }
        table.tcx-calls th { text-align:left; color:var(--text-dim,#6b7280); font-weight:600; border-bottom:1px solid var(--border,#e5e7eb); padding:6px 8px; }
        table.tcx-calls td { border-bottom:1px solid var(--border,#e5e7eb); padding:6px 8px; color:var(--text,#111827); vertical-align:top; }
        .tcx-act-error { color:var(--danger-text,#991b1b); }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
    <?php echo Tz::scriptTag(); ?>
</head>
<body data-mobile-module="system" data-mobile-page="integrations-threecx">
    <?php include '../includes/header.php'; ?>

    <div class="tcx-wrap sys-page">
        <a class="back-link" href="./">&larr; <?php echo htmlspecialchars(t('system.integrations.title')); ?></a>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">3CX <span class="tcx-state off" id="tcxState"><?php echo $t('not_configured'); ?></span></h1>
            <p class="sys-subtitle"><?php echo $t('threecx_intro'); ?></p>
            <p class="sys-subtitle"><strong><?php echo $t('threecx_direction'); ?></strong></p>
            <a class="help-link" href="./threecx/help">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true"><circle cx="12" cy="12" r="10"></circle>
                     <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <?php echo htmlspecialchars(t('system.integrations.help_link', ['name' => '3CX'])); ?>
            </a>
        </div></div>
        <div class="tcx-warn" id="notReady"><?php echo $t('threecx_not_ready'); ?></div>

        <div class="tcx-panel">
            <h3><?php echo $t('threecx_connect'); ?></h3>
            <p><?php echo $t('threecx_connect_body'); ?></p>
            <div class="tcx-field" style="max-width:900px"><label for="endpoint"><?php echo $t('threecx_endpoint'); ?></label>
                <input type="text" id="endpoint" autocomplete="off">
                <div class="tcx-hint"><?php echo $t('threecx_reach_hint'); ?></div></div>
            <div class="tcx-row"><span class="lbl"><?php echo $t('threecx_token'); ?></span><code id="token"></code>
                <button class="btn btn-secondary btn-small" id="copyToken"><?php echo $t('threecx_copy'); ?></button>
                <button class="btn btn-secondary btn-small" id="rotateBtn"></button></div>
            <div class="tcx-result" id="copyResult"></div>
            <p style="margin-top:16px"><?php echo $t('threecx_download_body'); ?></p>
            <button class="btn btn-primary" id="downloadBtn"><?php echo $t('threecx_download'); ?></button>
            <div class="tcx-hint" id="downloadNeedsToken" style="display:none"><?php echo $t('threecx_download_needs_token'); ?></div>
        </div>

        <div class="tcx-panel">
            <h3><?php echo $t('threecx_calls_h'); ?></h3>
            <div class="tcx-field"><label for="rule"><?php echo $t('threecx_rule'); ?></label>
                <select id="rule">
                    <option value="latest_open"><?php echo $t('threecx_rule_latest'); ?></option>
                    <option value="only_one"><?php echo $t('threecx_rule_only_one'); ?></option>
                    <option value="log_only"><?php echo $t('threecx_rule_log_only'); ?></option>
                </select>
                <div class="tcx-hint"><?php echo $t('threecx_rule_hint'); ?></div></div>
            <label class="tcx-check"><input type="checkbox" id="create"> <span><?php echo $t('threecx_create'); ?></span></label>
            <label class="tcx-check" style="margin-left:24px"><input type="checkbox" id="createUnknown"> <span><?php echo $t('threecx_create_unknown'); ?></span></label>
            <div class="tcx-hint" style="margin:0 0 16px 24px"><?php echo $t('threecx_create_hint'); ?></div>
            <div class="tcx-field"><label for="fallback"><?php echo $t('threecx_fallback'); ?></label>
                <select id="fallback"></select>
                <div class="tcx-hint"><?php echo $t('threecx_fallback_hint'); ?></div></div>
            <div class="tcx-field" id="companyField" style="display:none"><label for="company"><?php echo $t('threecx_company'); ?></label>
                <select id="company"></select>
                <div class="tcx-hint"><?php echo $t('threecx_company_hint'); ?></div></div>
            <div class="tcx-field"><label for="team"><?php echo $t('threecx_team'); ?></label><select id="team"></select></div>
            <div class="tcx-field"><label for="origin"><?php echo $t('threecx_origin'); ?></label><select id="origin"></select></div>

            <h3 style="margin-top:24px"><?php echo $t('threecx_dial_h'); ?></h3>
            <p><?php echo $t('threecx_dial_body'); ?></p>
            <div class="tcx-field" style="max-width:900px"><label for="dial"><?php echo $t('threecx_dial'); ?></label>
                <input type="text" id="dial" autocomplete="off" placeholder="tel:{number}">
                <div class="tcx-hint"><?php echo $t('threecx_dial_hint'); ?></div></div>

            <button class="btn btn-primary" id="saveBtn"><?php echo htmlspecialchars(t('common.save')); ?></button>
            <div class="tcx-result" id="saveResult"></div>
        </div>

        <div class="tcx-panel">
            <h3><?php echo $t('threecx_try'); ?></h3>
            <p><?php echo $t('threecx_try_body'); ?></p>
            <div class="tcx-try">
                <div class="tcx-field"><label for="tryNumber"><?php echo $t('threecx_try_number'); ?></label><input type="text" id="tryNumber" autocomplete="off"></div>
                <div class="tcx-field"><label for="tryType"><?php echo $t('threecx_try_type'); ?></label>
                    <select id="tryType">
                        <?php foreach (['Inbound', 'Missed', 'Outbound', 'Notanswered'] as $ct): ?>
                            <option value="<?php echo $ct; ?>"><?php echo $t('threecx_type_' . $ct); ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="tcx-field"><label for="tryAgent"><?php echo $t('threecx_try_agent'); ?></label><input type="text" id="tryAgent" autocomplete="off"></div>
                <button class="btn btn-primary" id="tryBtn"><?php echo $t('threecx_try_go'); ?></button>
            </div>
            <div class="tcx-result" id="tryResult"></div>
        </div>

        <div class="tcx-panel">
            <h3><?php echo $t('threecx_calls'); ?></h3>
            <table class="tcx-calls">
                <thead><tr><th><?php echo $t('threecx_col_when'); ?></th><th><?php echo $t('threecx_col_call'); ?></th><th><?php echo $t('threecx_col_number'); ?></th>
                    <th><?php echo $t('threecx_col_caller'); ?></th><th><?php echo $t('threecx_col_by'); ?></th><th><?php echo $t('threecx_col_result'); ?></th><th><?php echo $t('threecx_col_ticket'); ?></th></tr></thead>
                <tbody id="calls"></tbody>
            </table>
        </div>
    </div>

    <script>
    (function () {
        var API = '../../api/system/threecx_settings.php';
        var T = <?php
            $types = [];
            foreach (['Inbound', 'Missed', 'Outbound', 'Notanswered'] as $ct) $types[$ct] = t('system.integrations.threecx_type_' . $ct);
            $keys = ['token_none', 'token_make', 'token_rotate', 'rotate_confirm', 'copied', 'saved', 'nobody', 'none', 'calls_none',
                     'try_caller', 'try_nobody', 'try_several', 'try_noted', 'try_created', 'try_assignee', 'try_logged', 'try_failed'];
            $out = ['configured' => t('system.integrations.configured'), 'not_configured' => t('system.integrations.not_configured'), 'type' => $types,
                    'act' => ['noted' => t('system.integrations.threecx_act_noted'), 'created' => t('system.integrations.threecx_act_created'),
                              'logged' => t('system.integrations.threecx_act_logged'), 'error' => t('system.integrations.threecx_act_error')]];
            foreach ($keys as $k) $out[$k] = t('system.integrations.threecx_' . $k);
            echo json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP);
        ?>;
        var data = null;
        var $ = function (id) { return document.getElementById(id); };

        function say(id, ok, msg) { var el = $(id); el.className = 'tcx-result on ' + (ok ? 'ok' : 'bad'); el.textContent = msg; }
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
        function ticketLink(id, number) {
            var a = document.createElement('a');
            a.href = '../../tickets/?ticket_id=' + encodeURIComponent(id);
            a.textContent = number || ('#' + id);
            return a;
        }

        function render() {
            var s = data.settings;
            $('notReady').style.display = data.ready ? 'none' : 'block';
            var on = !!data.token;
            $('tcxState').className = 'tcx-state ' + (on ? 'on' : 'off');
            $('tcxState').textContent = on ? T.configured : T.not_configured;
            if (!$('endpoint').value) $('endpoint').value = data.endpoint;
            $('downloadBtn').disabled = !on;
            $('downloadNeedsToken').style.display = on ? 'none' : 'block';
            $('token').textContent = on ? data.token : T.token_none;
            $('copyToken').style.display = on ? '' : 'none';
            $('rotateBtn').textContent = on ? T.token_rotate : T.token_make;

            $('rule').value = s.ticket_rule;
            $('create').checked = !!s.create_ticket;
            $('createUnknown').checked = !!s.create_unknown;
            $('createUnknown').disabled = !s.create_ticket;
            fillSelect($('fallback'), data.analysts, s.fallback_analyst_id, T.nobody);
            if (data.companies.length) {
                $('companyField').style.display = '';
                fillSelect($('company'), data.companies, s.tenant_id, null);
            }
            fillSelect($('team'), data.teams, s.team_id, T.none);
            fillSelect($('origin'), data.origins, s.origin_id, T.none);
            $('dial').value = s.dial_url || '';
            if (!$('tryAgent').value) $('tryAgent').value = data.me;

            var body = $('calls');
            body.innerHTML = '';
            if (!data.calls.length) {
                var tr = document.createElement('tr'), td = document.createElement('td');
                td.colSpan = 7; td.className = 'tcx-hint'; td.textContent = T.calls_none;
                tr.appendChild(td); body.appendChild(tr);
            }
            data.calls.forEach(function (c) {
                var tr = document.createElement('tr');
                var when = window.fmtDateTime ? window.fmtDateTime(c.received_datetime) : c.received_datetime + ' UTC';   // tz.js: stored UTC, shown in the viewer's zone
                var result = (T.act[c.action] || c.action) + (c.detail ? ' - ' + c.detail : '');
                [when, T.type[c.call_type] || c.call_type, c.number, c.caller_name || '', c.analyst_name || '', result].forEach(function (v, i) {
                    var td = document.createElement('td'); td.textContent = v;
                    if (i === 5 && c.action === 'error') td.className = 'tcx-act-error';
                    tr.appendChild(td);
                });
                var tdT = document.createElement('td');
                if (c.ticket_id) tdT.appendChild(ticketLink(c.ticket_id, c.ticket_number));
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

        $('create').addEventListener('change', function () { $('createUnknown').disabled = !this.checked; });

        $('rotateBtn').addEventListener('click', async function () {
            if (data.token && !(await (window.showConfirm ? showConfirm({ message: T.rotate_confirm }) : confirm(T.rotate_confirm)))) return;
            var d = await post({ action: 'rotate' });
            if (d.success) { data.token = d.token; render(); }
        });

        $('copyToken').addEventListener('click', async function () {
            var ok = await window.copyToClipboard(data.token);
            say('copyResult', ok, ok ? T.copied : 'Could not copy - select the text and copy it by hand.');
        });

        $('saveBtn').addEventListener('click', async function () {
            var btn = this; btn.disabled = true;
            try {
                var d = await post({
                    action: 'save', ticket_rule: $('rule').value,
                    create_ticket: $('create').checked, create_unknown: $('create').checked && $('createUnknown').checked,
                    fallback_analyst_id: parseInt($('fallback').value, 10),
                    tenant_id: data.companies.length ? parseInt($('company').value, 10) : 0,
                    team_id: parseInt($('team').value, 10), origin_id: parseInt($('origin').value, 10),
                    dial_url: $('dial').value.trim()
                });
                if (d.success) { say('saveResult', true, T.saved); load(); }
                else say('saveResult', false, d.error || 'Could not save.');
            } finally { btn.disabled = false; }
        });

        /** One sentence per part of the decision; the ticket as a link. */
        $('tryBtn').addEventListener('click', async function () {
            var btn = this; btn.disabled = true;
            try {
                var d = await post({ action: 'try', number: $('tryNumber').value.trim(), call_type: $('tryType').value, agent_email: $('tryAgent').value.trim() });
                var el = $('tryResult');
                if (!d.success) { say('tryResult', false, T.try_failed.replace('{error}', d.error || '?')); return; }
                el.className = 'tcx-result on ok';
                el.textContent = '';
                var who = d.caller ? T.try_caller.replace('{name}', d.caller.name)
                        : (d.callers > 1 ? T.try_several.replace('{n}', d.callers) : T.try_nobody);
                el.appendChild(document.createTextNode(who + ' '));
                if (d.action === 'noted') {
                    var parts = T.try_noted.replace('{author}', d.author).split('{ticket}');
                    el.appendChild(document.createTextNode(parts[0]));
                    el.appendChild(ticketLink(d.ticket_id, d.ticket_number));
                    el.appendChild(document.createTextNode(parts[1] || ''));
                } else if (d.action === 'created') {
                    var who2 = d.author_is_agent ? T.try_assignee.replace('{author}', d.author) : '';
                    el.appendChild(document.createTextNode(T.try_created.replace('{assignee}', who2)));
                } else {
                    el.appendChild(document.createTextNode(T.try_logged.replace('{detail}', d.detail)));
                }
            } finally { btn.disabled = false; }
        });

        $('downloadBtn').addEventListener('click', function () {
            // A plain navigation: the browser saves the file the API sends.
            window.location.href = API + '?action=export&endpoint=' + encodeURIComponent($('endpoint').value.trim());
        });

        load();
    })();
    </script>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
