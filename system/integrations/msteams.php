<?php
/**
 * System → Integrations → Microsoft Teams (channel alerts).
 *
 * One card per Teams channel: its name, its Workflows webhook address, and the
 * alerts it gets. Saving a channel makes, updates or removes its workflows - see
 * includes/integrations/msteams.php. The Teams chat BOT is a different thing,
 * set up under Tickets → Settings → Messaging; this page links to it.
 *
 * Reads and writes api/system/msteams_settings.php. Contributed by Carl
 * Steynvaard (Open Resilience), October 2026.
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
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.integrations.title'), 'Microsoft Teams')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <link rel="stylesheet" href="../../assets/css/integrations.css?v=3">
    <style>
        body { --accent: var(--sys-accent, #546e7a); --accent-hover: var(--sys-accent-hover, #37474f); --on-accent: var(--sys-on-accent, #fff); }
        /* Gutter, width and title come from .sys-page (assets/css/system-page.css). */
        .mst-wrap { height:calc(100vh - 48px); overflow-y:auto; }
        .mst-wrap .sys-title { display:flex; align-items:center; gap:10px; }
        .mst-state { font-size:12px; font-weight:600; padding:3px 10px; border-radius:20px; }
        .mst-state.on  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); }
        .mst-state.off { background:var(--surface-2,#f3f4f6); color:var(--text-faint,#9ca3af); }
        .mst-panel { background:var(--surface,#fff); border:1px solid var(--border,#e0e0e0); border-radius:8px; padding:20px; margin-bottom:18px; }
        .mst-panel h3 { margin:0 0 12px; font-size:13px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-dim,#888); }
        .mst-panel > p { font-size:13px; color:var(--text-dim,#6b7280); margin:0 0 14px; line-height:1.5; }
        .mst-bar { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
        .mst-bar h3 { margin:0; }
        .mst-ch { border:1px solid var(--border,#e5e7eb); border-radius:8px; padding:16px 18px; margin-bottom:14px; background:var(--surface-2,#fafafa); }
        .mst-row2 { display:grid; grid-template-columns:minmax(200px, 1fr) minmax(260px, 2fr); gap:14px; }
        .mst-field label { display:block; font-size:13px; font-weight:600; color:var(--text,#333); margin-bottom:5px; }
        .mst-field input[type=text], .mst-field input[type=password], .mst-field select { width:100%; padding:9px 12px; font-size:13px; box-sizing:border-box;
                    border:1px solid var(--border,#d1d5db); border-radius:6px; background:var(--surface,#fff); color:var(--text,#111827); }
        .mst-hint { font-size:12px; color:var(--text-faint,#9ca3af); margin-top:5px; line-height:1.45; }
        .mst-groups { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:16px; margin-top:14px; }
        .mst-group h4 { margin:0 0 8px; font-size:12px; text-transform:uppercase; letter-spacing:.4px; color:var(--text-dim,#6b7280); }
        .mst-check { display:flex; gap:8px; align-items:flex-start; font-size:13px; color:var(--text,#333); margin:5px 0; }
        .mst-teams { max-height:150px; overflow-y:auto; border:1px solid var(--border,#e5e7eb); border-radius:6px; padding:6px 10px; margin-bottom:8px; background:var(--surface,#fff); }
        .mst-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-top:14px; }
        .mst-on { font-size:12px; color:var(--text-dim,#6b7280); }
        .btn { display:inline-flex; align-items:center; gap:8px; padding:10px 20px; border-radius:6px; font-size:13px;
               font-weight:600; border:none; cursor:pointer; transition:all .15s; text-decoration:none; }
        .btn-primary { background:var(--sys-accent,#546e7a); color:var(--sys-on-accent,#fff); }
        .btn-primary:hover:not(:disabled) { background:#455a64; }
        .btn-secondary { background:var(--sys-accent-soft,#eceff1); color:#455a64; }
        .btn-danger { background:transparent; color:var(--danger-text,#991b1b); border:1px solid var(--danger-border,#fecaca); }
        .btn:disabled { opacity:.55; cursor:progress; }
        .mst-result { font-size:13px; padding:8px 12px; border-radius:6px; display:none; margin-top:10px; }
        .mst-result.on { display:block; }
        .mst-result.ok  { background:var(--success-bg,#ecfdf5); color:var(--success-text,#065f46); border:1px solid var(--success-border,#a7f3d0); }
        .mst-result.bad { background:var(--danger-bg,#fef2f2); color:var(--danger-text,#991b1b); border:1px solid var(--danger-border,#fecaca); }
        .mst-warn { background:var(--warning-bg,#fef3c7); color:var(--warning-text,#92400e); border:1px solid var(--warning-border,#fcd34d);
                    border-radius:6px; padding:12px 14px; font-size:13px; margin-bottom:18px; display:none; }
        @media (max-width: 700px) { .mst-row2 { grid-template-columns:1fr; } }
    </style>
    <!-- Mobile layer LAST, after this page's own <style> (Techniques §9). -->
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="integrations-msteams">
    <?php include '../includes/header.php'; ?>

    <div class="mst-wrap sys-page">
        <a class="back-link" href="./">&larr; <?php echo htmlspecialchars(t('system.integrations.title')); ?></a>
        <div class="sys-page-head"><div>
            <h1 class="sys-title">Microsoft Teams <span class="mst-state off" id="mstState"><?php echo $t('not_configured'); ?></span></h1>
            <p class="sys-subtitle"><?php echo $t('msteams_intro'); ?></p>
            <a class="help-link" href="./msteams/help">
                <svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none"
                     stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     aria-hidden="true"><circle cx="12" cy="12" r="10"></circle>
                     <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
                <?php echo htmlspecialchars(t('system.integrations.help_link', ['name' => 'Microsoft Teams'])); ?>
            </a>
        </div></div>
        <div class="mst-warn" id="notReady"><?php echo $t('msteams_not_ready'); ?></div>

        <div class="mst-panel">
            <h3><?php echo $t('msteams_how_h'); ?></h3>
            <p><?php echo $t('msteams_how_body'); ?></p>
        </div>

        <div class="mst-panel">
            <div class="mst-bar">
                <h3><?php echo $t('msteams_channels_h'); ?></h3>
                <button class="btn btn-primary" id="addBtn"><?php echo $t('msteams_add'); ?></button>
            </div>
            <p><?php echo $t('msteams_workflows_note'); ?></p>
            <div id="channels"></div>
        </div>

        <div class="mst-panel">
            <h3><?php echo $t('msteams_bot_h'); ?></h3>
            <p><?php echo $t('msteams_bot_body'); ?></p>
            <p class="mst-hint" id="botCount"></p>
            <a class="btn btn-secondary" href="../../tickets/settings/#messaging"><?php echo $t('msteams_bot_link'); ?></a>
        </div>
    </div>

    <script>
    (function () {
        var API = '../../api/system/msteams_settings.php';
        var T = <?php
            $keys = ['channels_none', 'name', 'name_ph', 'url', 'url_saved', 'url_new', 'url_clear', 'url_none', 'group_priority', 'threshold',
                     'threshold_none', 'group_teams', 'group_other', 'no_teams', 'on', 'saved', 'test', 'test_sent', 'test_failed',
                     'delete_confirm', 'needs_name', 'bot_count',
                     'alert_priority_new', 'alert_priority_raised', 'alert_team_new', 'alert_team_handover', 'alert_every_new',
                     'alert_sla_warning', 'alert_sla_breached', 'alert_status_incident', 'alert_approval_waiting'];
            $out = ['configured' => t('system.integrations.configured'), 'not_configured' => t('system.integrations.not_configured'),
                    'save' => t('common.save'), 'delete' => t('common.delete')];
            foreach ($keys as $k) $out[$k] = t('system.integrations.msteams_' . $k);
            echo json_encode($out, JSON_HEX_TAG | JSON_HEX_AMP);
        ?>;
        var data = null;
        var $ = function (id) { return document.getElementById(id); };
        var el = function (tag, attrs, text) {
            var e = document.createElement(tag);
            Object.keys(attrs || {}).forEach(function (k) { if (k === 'className') e.className = attrs[k]; else e.setAttribute(k, attrs[k]); });
            if (text !== undefined) e.textContent = text;
            return e;
        };

        async function post(body) {
            return (await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) })).json();
        }
        function say(box, ok, msg) { box.className = 'mst-result on ' + (ok ? 'ok' : 'bad'); box.textContent = msg; }

        /** A tick for one alert, checked when the channel has it. */
        function alertTick(ch, key) {
            var lab = el('label', { className: 'mst-check' });
            var cb = el('input', { type: 'checkbox', 'data-alert': key });
            cb.checked = ch.alerts.indexOf(key) !== -1;
            lab.appendChild(cb);
            lab.appendChild(el('span', {}, T['alert_' + key]));
            return lab;
        }

        /** One channel's card. ch.id 0 = not saved yet. */
        function channelCard(ch) {
            var card = el('div', { className: 'mst-ch' });
            var row = el('div', { className: 'mst-row2' });
            var f1 = el('div', { className: 'mst-field' });
            f1.appendChild(el('label', {}, T.name));
            var name = el('input', { type: 'text', maxlength: '100', placeholder: T.name_ph });
            name.value = ch.name;
            f1.appendChild(name);
            var f2 = el('div', { className: 'mst-field' });
            f2.appendChild(el('label', {}, T.url));
            var url = el('input', { type: 'password', autocomplete: 'off', placeholder: ch.has_url ? T.url_saved : T.url_new });
            f2.appendChild(url);
            var clear = null;
            if (ch.has_url) {
                var cl = el('label', { className: 'mst-check' });
                clear = el('input', { type: 'checkbox' });
                cl.appendChild(clear); cl.appendChild(el('span', {}, T.url_clear));
                f2.appendChild(cl);
            } else {
                f2.appendChild(el('div', { className: 'mst-hint' }, T.url_none));
            }
            row.appendChild(f1); row.appendChild(f2);
            card.appendChild(row);

            var groups = el('div', { className: 'mst-groups' });
            var g1 = el('div', { className: 'mst-group' });
            g1.appendChild(el('h4', {}, T.group_priority));
            var fs = el('div', { className: 'mst-field' });
            fs.appendChild(el('label', {}, T.threshold));
            var sel = el('select', {});
            sel.appendChild(el('option', { value: '0' }, T.threshold_none));
            data.priorities.forEach(function (p) {
                var o = el('option', { value: String(p.id) }, p.name);
                if (String(p.id) === String(ch.priority_threshold_id)) o.selected = true;
                sel.appendChild(o);
            });
            fs.appendChild(sel);
            g1.appendChild(fs);
            g1.appendChild(alertTick(ch, 'priority_new'));
            g1.appendChild(alertTick(ch, 'priority_raised'));

            var g2 = el('div', { className: 'mst-group' });
            g2.appendChild(el('h4', {}, T.group_teams));
            var tl = el('div', { className: 'mst-teams' });
            if (!data.teams.length) tl.appendChild(el('div', { className: 'mst-hint' }, T.no_teams));
            data.teams.forEach(function (tm) {
                var lab = el('label', { className: 'mst-check' });
                var cb = el('input', { type: 'checkbox', 'data-team': String(tm.id) });
                cb.checked = ch.team_ids.indexOf(Number(tm.id)) !== -1;
                lab.appendChild(cb); lab.appendChild(el('span', {}, tm.name));
                tl.appendChild(lab);
            });
            g2.appendChild(tl);
            g2.appendChild(alertTick(ch, 'team_new'));
            g2.appendChild(alertTick(ch, 'team_handover'));

            var g3 = el('div', { className: 'mst-group' });
            g3.appendChild(el('h4', {}, T.group_other));
            ['every_new', 'sla_warning', 'sla_breached', 'status_incident', 'approval_waiting'].forEach(function (k) { g3.appendChild(alertTick(ch, k)); });
            groups.appendChild(g1); groups.appendChild(g2); groups.appendChild(g3);
            card.appendChild(groups);

            var actions = el('div', { className: 'mst-actions' });
            var save = el('button', { className: 'btn btn-primary' }, T.save);
            actions.appendChild(save);
            var test = null;
            if (ch.id && ch.has_url) { test = el('button', { className: 'btn btn-secondary' }, T.test); actions.appendChild(test); }
            var del = el('button', { className: 'btn btn-danger' }, T.delete);
            actions.appendChild(del);
            if (ch.id) actions.appendChild(el('span', { className: 'mst-on' }, T.on.replace('{n}', ch.workflows)));
            card.appendChild(actions);
            var result = el('div', { className: 'mst-result' });
            card.appendChild(result);

            save.addEventListener('click', async function () {
                if (!name.value.trim()) { say(result, false, T.needs_name); return; }
                save.disabled = true;
                try {
                    var d = await post({
                        action: 'save', id: ch.id, name: name.value.trim(), webhook_url: url.value.trim(), clear_url: !!(clear && clear.checked),
                        priority_threshold_id: parseInt(sel.value, 10),
                        alerts: Array.prototype.map.call(card.querySelectorAll('[data-alert]:checked'), function (c) { return c.dataset.alert; }),
                        team_ids: Array.prototype.map.call(card.querySelectorAll('[data-team]:checked'), function (c) { return parseInt(c.dataset.team, 10); })
                    });
                    if (!d.success) { say(result, false, d.error || 'Could not save.'); return; }
                    await load();
                    var again = document.querySelector('[data-channel="' + d.id + '"] .mst-result');
                    if (again) say(again, true, T.saved.replace('{n}', d.alerts_on));
                } finally { save.disabled = false; }
            });
            if (test) test.addEventListener('click', async function () {
                test.disabled = true;
                try {
                    var d = await post({ action: 'test', id: ch.id });
                    if (!d.success) say(result, false, d.error || '?');
                    else say(result, d.ok, (d.ok ? T.test_sent : T.test_failed).replace('{detail}', d.detail));
                } finally { test.disabled = false; }
            });
            del.addEventListener('click', async function () {
                if (!ch.id) { card.remove(); return; }
                if (!(await (window.showConfirm ? showConfirm({ message: T.delete_confirm }) : confirm(T.delete_confirm)))) return;
                var d = await post({ action: 'delete', id: ch.id });
                if (d.success) load(); else say(result, false, d.error || '?');
            });
            card.setAttribute('data-channel', String(ch.id));
            return card;
        }

        function render() {
            $('notReady').style.display = data.ready ? 'none' : 'block';
            var on = data.channels.some(function (c) { return c.has_url && c.workflows > 0; });
            $('mstState').className = 'mst-state ' + (on ? 'on' : 'off');
            $('mstState').textContent = on ? T.configured : T.not_configured;
            $('botCount').textContent = T.bot_count.replace('{n}', data.bots);
            var box = $('channels');
            box.innerHTML = '';
            if (!data.channels.length) box.appendChild(el('p', { className: 'mst-hint' }, T.channels_none));
            data.channels.forEach(function (c) { box.appendChild(channelCard(c)); });
        }

        async function load() {
            var d = await (await fetch(API + '?action=get')).json();
            if (!d.success) { $('channels').textContent = d.error || 'Could not load.'; return; }
            data = d;
            render();
        }

        $('addBtn').addEventListener('click', function () {
            if (!data) return;
            var empty = $('channels').querySelector('p.mst-hint');
            if (empty) empty.remove();
            var card = channelCard({ id: 0, name: '', has_url: false, alerts: [], priority_threshold_id: null, team_ids: [], workflows: 0 });
            $('channels').appendChild(card);
            card.querySelector('input').focus();
        });

        load();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
