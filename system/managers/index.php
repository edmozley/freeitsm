<?php
/**
 * System — Managers (discussion #62, step 3).
 *
 * The settings for portal managers - people in the self-service portal who may
 * see the tickets of the people they manage - and an overview of every manager.
 *
 * What is NOT here: setting up one manager's lines. That is a full page of its
 * own (Manager access, opened from the person on Tickets -> Users or Assets ->
 * Users), because on a large organisation it means searching thousands of
 * people and hundreds of departments, and a settings page is the wrong place.
 *
 * The rules live in includes/managers.php; api/system/managers.php reads and
 * writes the settings and builds the overview, a page at a time.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();
$current_page = 'managers';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];
requireModuleAccess('system');
$m = fn(string $k) => t('system.managers.' . $k);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(systemName()); ?> - <?php echo htmlspecialchars($m('heading')); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=24">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=76">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Full width, like the other System settings pages (Ed). */
        .mg-container { height: calc(100vh - 48px); overflow-y: auto; width: 100%; box-sizing: border-box; padding: 24px 32px 40px; }
        .mg-header { margin-bottom: 22px; }
        .mg-header h2 { margin: 0; font-size: 22px; color: var(--text, #333); }
        .mg-header p  { margin: 5px 0 0 0; font-size: 13px; color: var(--text-dim, #888); line-height: 1.55; max-width: 780px; }
        .mg-verify { margin-bottom: 18px; padding: 12px 14px; border-radius: 8px; font-size: 13px;
            background: var(--warning-bg, #fff4ce); color: var(--warning-text, #6b5900); border: 1px solid var(--warning-border, #f2d675); }
        .mg-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e0e0e0); border-radius: 8px; padding: 20px; margin-bottom: 18px; }
        .mg-panel h3 { margin: 0 0 4px 0; font-size: 13px; text-transform: uppercase; letter-spacing: .5px; color: var(--text-dim, #888); }
        .mg-panel > p { margin: 0 0 16px; font-size: 13px; color: var(--text-muted, #666); line-height: 1.55; max-width: 720px; }
        .mg-field { padding: 12px 0; border-top: 1px solid var(--border-soft, #f1f1f1); }
        .mg-field:first-of-type { border-top: none; padding-top: 0; }
        .mg-toggle { display: flex; align-items: flex-start; gap: 10px; cursor: pointer; }
        .mg-toggle input { margin-top: 3px; }
        .mg-label { display: block; font-size: 13.5px; font-weight: 600; color: var(--text, #333); }
        .mg-desc  { font-size: 12.5px; color: var(--text-muted, #666); line-height: 1.5; max-width: 640px; margin-top: 2px; }
        .mg-sub { margin: 10px 0 0 26px; }
        .mg-sub select { padding: 7px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); }
        .mg-radio { display: flex; gap: 10px; align-items: flex-start; padding: 8px 0; cursor: pointer; }
        .mg-radio input { margin-top: 3px; }
        .mg-disabled { opacity: .5; pointer-events: none; }
        .mg-actions { display: flex; align-items: center; gap: 12px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn:disabled { opacity: .5; cursor: default; }

        /* Overview */
        .mg-search { width: 100%; max-width: 360px; padding: 8px 10px; border: 1px solid var(--border, #cfd8dc); border-radius: 6px; font-size: 13px; background: var(--surface, #fff); color: var(--text, #333); margin-bottom: 12px; }
        .mg-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .mg-table th { text-align: left; font-size: 12px; color: var(--text-muted, #888); font-weight: 600; padding: 8px 10px; border-bottom: 1px solid var(--border-soft, #eee); }
        .mg-table td { padding: 10px; border-bottom: 1px solid var(--border-soft, #f2f2f2); color: var(--text, #444); vertical-align: top; }
        .mg-table .mg-email { font-size: 12px; color: var(--text-muted, #888); }
        .mg-table .mg-name { color: var(--accent, #0078d4); text-decoration: none; }
        .mg-table .mg-name:hover { text-decoration: underline; }
        .mg-chip { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11.5px; margin: 0 4px 3px 0;
            background: var(--surface-2, #eceff1); color: var(--text-muted, #555); }
        .mg-warn { display: block; margin-top: 4px; font-size: 12px; color: var(--warning-text, #6b5900); }
        .mg-pager { display: flex; align-items: center; gap: 10px; margin-top: 12px; font-size: 13px; color: var(--text-muted, #666); }
        .mg-empty { padding: 20px 10px; color: var(--text-muted, #888); font-size: 13px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=169">
</head>
<body data-mobile-module="system" data-mobile-page="managers">
    <?php include '../includes/header.php'; ?>
    <div class="mg-container">
        <div class="mg-header">
            <h2><?php echo htmlspecialchars($m('heading')); ?></h2>
            <p><?php echo $m('intro'); ?></p>
        </div>

        <div class="mg-verify" id="mgVerify" hidden><?php echo htmlspecialchars($m('needs_verify')); ?></div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('on_heading')); ?></h3>
            <div class="mg-field">
                <label class="mg-toggle"><input type="checkbox" id="mgEnabled">
                    <span><span class="mg-label"><?php echo htmlspecialchars($m('enabled')); ?></span>
                    <span class="mg-desc"><?php echo htmlspecialchars($m('enabled_desc')); ?></span></span></label>
            </div>
        </div>

        <div id="mgRest">
        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('source_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('source_desc')); ?></p>
            <div class="mg-field">
                <label class="mg-toggle"><input type="checkbox" id="mgDirectory">
                    <span><span class="mg-label"><?php echo htmlspecialchars($m('directory')); ?></span>
                    <span class="mg-desc"><?php echo htmlspecialchars($m('directory_desc')); ?></span></span></label>
                <div class="mg-sub" id="mgDepthRow">
                    <label class="mg-label" for="mgDepth" style="font-weight:500;margin-bottom:4px;"><?php echo htmlspecialchars($m('depth')); ?></label>
                    <select id="mgDepth">
                        <option value="direct"><?php echo htmlspecialchars($m('depth_direct')); ?></option>
                        <option value="all"><?php echo htmlspecialchars($m('depth_all')); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('can_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('can_desc')); ?></p>
            <div class="mg-field">
                <label class="mg-toggle"><input type="checkbox" id="mgCanReply"><span class="mg-label"><?php echo htmlspecialchars($m('can_reply')); ?></span></label>
            </div>
            <div class="mg-field">
                <label class="mg-toggle"><input type="checkbox" id="mgCanClose"><span class="mg-label"><?php echo htmlspecialchars($m('can_close')); ?></span></label>
            </div>
        </div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('conf_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('conf_desc')); ?></p>
            <?php foreach (['none', 'stub', 'all'] as $v): ?>
            <label class="mg-radio"><input type="radio" name="mgConf" value="<?php echo $v; ?>">
                <span><span class="mg-label"><?php echo htmlspecialchars($m('conf_' . $v)); ?></span>
                <span class="mg-desc"><?php echo htmlspecialchars($m('conf_' . $v . '_desc')); ?></span></span></label>
            <?php endforeach; ?>
        </div>

        <?php /* Telling managers about new team tickets - off by default. */ ?>
        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('notify_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('notify_desc')); ?></p>
            <?php foreach (['none', 'email', 'bell'] as $v): ?>
            <label class="mg-radio"><input type="radio" name="mgNotify" value="<?php echo $v; ?>">
                <span><span class="mg-label"><?php echo htmlspecialchars($m('notify_' . $v)); ?></span>
                <span class="mg-desc"><?php echo htmlspecialchars($m('notify_' . $v . '_desc')); ?></span></span></label>
            <?php endforeach; ?>
        </div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('leavers_heading')); ?></h3>
            <div class="mg-field">
                <label class="mg-toggle"><input type="checkbox" id="mgLeavers">
                    <span><span class="mg-label"><?php echo htmlspecialchars($m('leavers')); ?></span>
                    <span class="mg-desc"><?php echo htmlspecialchars($m('leavers_desc')); ?></span></span></label>
            </div>
        </div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('edit_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('edit_desc')); ?></p>
            <label class="mg-radio"><input type="radio" name="mgEditBy" value="admins"><span class="mg-label"><?php echo htmlspecialchars($m('edit_admins')); ?></span></label>
            <label class="mg-radio"><input type="radio" name="mgEditBy" value="people_editors"><span class="mg-label"><?php echo htmlspecialchars($m('edit_people')); ?></span></label>
        </div>
        </div>

        <div class="mg-panel mg-actions">
            <button type="button" class="btn btn-primary" id="mgSave"><?php echo htmlspecialchars(t('common.save')); ?></button>
        </div>

        <div class="mg-panel">
            <h3><?php echo htmlspecialchars($m('overview_heading')); ?></h3>
            <p><?php echo htmlspecialchars($m('overview_desc')); ?></p>
            <input type="search" class="mg-search" id="mgSearch" placeholder="<?php echo htmlspecialchars($m('search')); ?>">
            <div id="mgOverview"></div>
            <div class="mg-pager" id="mgPager" hidden>
                <button type="button" class="btn btn-secondary" id="mgPrev"><?php echo htmlspecialchars($m('prev')); ?></button>
                <span id="mgPageLabel"></span>
                <button type="button" class="btn btn-secondary" id="mgNext"><?php echo htmlspecialchars($m('next')); ?></button>
            </div>
        </div>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script>
    (function () {
        const API = '../../api/system/managers.php';
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
        // Singular and plural are separate keys (lang/en/auth.php explains why).
        const countText = (key, n) => Number(n) === 1 ? t('system.managers.' + key + '_one') : t('system.managers.' + key, { count: n });
        const toast = (m, k) => { if (typeof showToast === 'function') showToast(m, k); };
        let page = 1, searchTimer = null;

        function paint(s, status) {
            $('mgEnabled').checked   = s.enabled === '1';
            $('mgDirectory').checked = s.directory === '1';
            $('mgDepth').value       = s.directory_depth;
            $('mgCanReply').checked  = s.can_reply === '1';
            $('mgCanClose').checked  = s.can_close === '1';
            $('mgLeavers').checked   = s.leavers === '1';
            document.querySelectorAll('input[name=mgConf]').forEach(r => r.checked = r.value === s.confidential);
            document.querySelectorAll('input[name=mgEditBy]').forEach(r => r.checked = r.value === s.edit_by);
            document.querySelectorAll('input[name=mgNotify]').forEach(r => r.checked = r.value === s.notify);
            $('mgVerify').hidden = !(status && status.needs_verify);
            sync();
        }
        // The depth only means something while the Manager field counts.
        function sync() {
            $('mgDepthRow').classList.toggle('mg-disabled', !$('mgDirectory').checked);
        }
        $('mgDirectory').addEventListener('change', sync);

        async function load() {
            try {
                const d = await (await fetch(API + '?action=settings', { credentials: 'same-origin' })).json();
                if (d.success) paint(d.settings, d.status);
            } catch (e) { /* the overview says the rest */ }
        }

        $('mgSave').addEventListener('click', async function () {
            const pick = n => (document.querySelector('input[name=' + n + ']:checked') || {}).value;
            const settings = {
                enabled:         $('mgEnabled').checked ? '1' : '0',
                directory:       $('mgDirectory').checked ? '1' : '0',
                directory_depth: $('mgDepth').value,
                can_reply:       $('mgCanReply').checked ? '1' : '0',
                can_close:       $('mgCanClose').checked ? '1' : '0',
                leavers:         $('mgLeavers').checked ? '1' : '0',
                confidential:    pick('mgConf') || 'none',
                edit_by:         pick('mgEditBy') || 'admins',
                notify:          pick('mgNotify') || 'none',
            };
            this.disabled = true;
            try {
                const r = await fetch(API, {
                    method: 'POST', credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save', settings: settings })
                });
                const d = await r.json();
                if (!d.success) { toast(t('system.managers.save_failed', { error: d.error || '' }), 'error'); return; }
                paint(d.settings, d.status);
                toast(t('system.managers.saved'), 'success');
                loadOverview();   // "can see" depends on the settings
            } catch (e) {
                toast(t('system.managers.save_failed', { error: String(e.message || e) }), 'error');
            } finally {
                this.disabled = false;
            }
        });

        async function loadOverview() {
            const q = $('mgSearch').value.trim();
            const host = $('mgOverview');
            try {
                const d = await (await fetch(API + '?action=overview&page=' + page + (q ? '&search=' + encodeURIComponent(q) : ''), { credentials: 'same-origin' })).json();
                if (!d.success) throw new Error(d.error || '');
                if (d.status && d.status.needs_verify) { $('mgVerify').hidden = false; host.innerHTML = ''; $('mgPager').hidden = true; return; }
                if (!d.managers.length) {
                    host.innerHTML = '<div class="mg-empty">' + esc(t(q ? 'system.managers.none_search' : 'system.managers.none')) + '</div>';
                    $('mgPager').hidden = true;
                    return;
                }
                host.innerHTML = '<table class="mg-table"><thead><tr>'
                    + '<th>' + esc(t('system.managers.col_manager')) + '</th>'
                    + '<th>' + esc(t('system.managers.col_company')) + '</th>'
                    + '<th>' + esc(t('system.managers.col_from')) + '</th>'
                    + '<th>' + esc(t('system.managers.col_can_see')) + '</th>'
                    + '</tr></thead><tbody>' + d.managers.map(m => {
                        const from = [];
                        if (Number(m.direct_reports)) from.push(countText('from_directory', m.direct_reports));
                        if (Number(m.line_count)) from.push(countText('from_lines', m.line_count));
                        if (Number(m.exclusion_count)) from.push(countText('from_exclusions', m.exclusion_count));
                        const warns = (m.warnings || []).map(w => '<span class="mg-warn">⚠ ' + esc(w.kind === 'left'
                            ? t('system.managers.warn_left')
                            : t('system.managers.warn_department', { name: w.name })) + '</span>').join('');
                        // Their lines are changed on the full-screen Manager access page.
                        return '<tr><td><a class="mg-name" href="../../tickets/manager-access.php?user_id=' + encodeURIComponent(m.id) + '&from=system"><strong>' + esc(m.name) + '</strong></a><div class="mg-email">' + esc(m.email || '') + '</div>' + warns + '</td>'
                             + '<td>' + esc(m.company || '') + '</td>'
                             + '<td>' + from.map(f => '<span class="mg-chip">' + esc(f) + '</span>').join('') + '</td>'
                             + '<td>' + esc(countText('can_see_people', m.can_see)) + '</td></tr>';
                    }).join('') + '</tbody></table>';
                const pages = Math.max(1, Math.ceil(d.total / d.per_page));
                $('mgPager').hidden = pages <= 1;
                $('mgPageLabel').textContent = t('system.managers.page_of', { page: d.page, pages: pages });
                $('mgPrev').disabled = d.page <= 1;
                $('mgNext').disabled = d.page >= pages;
            } catch (e) {
                host.innerHTML = '<div class="mg-empty">' + esc(String(e.message || e)) + '</div>';
            }
        }
        $('mgSearch').addEventListener('input', () => { clearTimeout(searchTimer); searchTimer = setTimeout(() => { page = 1; loadOverview(); }, 300); });
        $('mgPrev').addEventListener('click', () => { if (page > 1) { page--; loadOverview(); } });
        $('mgNext').addEventListener('click', () => { page++; loadOverview(); });

        load();
        loadOverview();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=71"></script>
</body>
</html>
