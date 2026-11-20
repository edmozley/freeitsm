<?php
/**
 * System - Maintenance - Locations in use (GH #311).
 *
 * Deleting a location that assets, people, tickets, contracts or projects point
 * at is refused (Ed, 15-16 Nov 2026). This is where that is resolved: pick a location,
 * see everything that uses it, then move it all to another location, or
 * clear it. Each record is checked against its own company; one the new
 * location is not open to is left as it was and counted.
 *
 * The same people as the locations list itself may use it: administrators,
 * and anyone with Assets plus the permission to manage locations (the Assets
 * -> Settings -> Locations editor sends them here). Which location each may
 * work on is recordLocationManageError() (includes/record_locations.php).
 *
 * Design: wiki Locations-On-Records-Design.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/rbac.php';
I18n::initFromSession();

$current_page = 'locations-in-use';
// The locations editor's own people (Assets -> Settings -> Locations) may use it too.
$system_page_grant = ['module' => 'assets', 'cap' => Cap::ASSETS_LOCATIONS];
require_once __DIR__ . '/../includes/page_gate.php';
require_once '../../includes/record_locations.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$options = [];
$failed = false;
try {
    $conn = connectToDatabase();
    $options = recordLocationOptions($conn, getActiveTenantId($conn, (int)$_SESSION['analyst_id']));
} catch (Throwable $e) {
    error_log('locations-in-use: ' . $e->getMessage());
    $failed = true;
}
$preselect = (int)($_GET['id'] ?? 0);
$e = function (string $k, array $p = []): string { return htmlspecialchars(t('system.locations_in_use.' . $k, $p)); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', t('system.locations_in_use.heading'))); ?></title>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/theme.css?v=27">
    <link rel="stylesheet" href="<?php echo $path_prefix; ?>assets/css/inbox.css?v=96">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Page layout, title and subtitle: assets/css/system-page.css (.sys-page on .liu-wrap). */
        .liu-wrap { height: calc(100vh - 48px); overflow-y: auto; background: var(--app-bg, #f5f7fa); }
        .liu-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: var(--sys-accent, #546e7a); text-decoration: none; margin: 0 0 12px; }
        .liu-back:hover { text-decoration: underline; }
        .liu-pick { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0 0 18px; }
        .liu-pick label { font-size: 13px; font-weight: 600; color: var(--text, #444); }
        .liu-pick select { min-width: 280px; max-width: 100%; padding: 7px 10px; border: 1px solid var(--border, #d1d5db); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; }
        .liu-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e5e7eb); border-radius: 10px; padding: 16px; margin: 0 0 16px; }
        .liu-counts { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 14px; }
        .liu-count { background: var(--surface-3, #f9fafb); border: 1px solid var(--border, #e5e7eb); border-radius: 8px; padding: 8px 12px; font-size: 13px; color: var(--text, #444); }
        .liu-count strong { font-size: 16px; margin-right: 4px; }
        .liu-kind { margin: 10px 0 0; }
        .liu-kind h3 { font-size: 13px; margin: 0 0 6px; color: var(--text, #444); }
        .liu-kind ul { margin: 0; padding-left: 18px; font-size: 13px; }
        .liu-kind a { color: var(--sys-accent, #546e7a); }
        .liu-more { font-size: 12px; color: var(--text-dim, #888); }
        .liu-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .liu-actions select { min-width: 240px; max-width: 100%; padding: 7px 10px; border: 1px solid var(--border, #d1d5db); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); font-size: 13px; }
        .liu-empty { background: var(--success-bg, #ecfdf5); border: 1px solid var(--success-border, #b7e1c4); color: var(--success-text, #065f46); border-radius: 10px; padding: 16px; font-size: 14px; }
        .liu-error { color: var(--danger-text, #c0392b); font-size: 13px; }
        .liu-note { font-size: 12px; color: var(--text-faint, #999); margin-top: 10px; }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: 6px; font-size: 13px; font-weight: 600; border: none; cursor: pointer; }
        .btn-primary { background: var(--sys-accent, #546e7a); color: var(--sys-on-accent, #fff); }
        .btn-primary:hover { background: var(--sys-accent-hover, #455a64); }
        .btn-secondary { background: var(--surface-2, #eceff1); color: var(--text, #333); }
        .btn-secondary:hover { background: var(--border, #cfd8dc); }
        .btn:disabled { opacity: .55; cursor: default; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="locations-in-use">
    <?php include '../includes/header.php'; ?>

    <div class="liu-wrap sys-page">
        <a class="liu-back" href="../maintenance/">&larr; <?php echo htmlspecialchars(t('system.maintenance.back')); ?></a>
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo $e('heading'); ?></h1>
            <p class="sys-subtitle"><?php echo $e('intro'); ?></p>
        </div></div>

        <?php if ($failed): ?>
            <p class="liu-error"><?php echo $e('load_failed'); ?></p>
        <?php elseif (!$options): ?>
            <div class="liu-empty"><?php echo $e('no_locations'); ?></div>
        <?php else: ?>
            <div class="liu-pick">
                <label for="liuLocation"><?php echo $e('pick'); ?></label>
                <select id="liuLocation">
                    <option value=""></option>
                    <?php foreach ($options as $o): ?>
                        <option value="<?php echo (int)$o['id']; ?>"<?php echo $preselect === (int)$o['id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars($o['path']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="liuResult"></div>
        <?php endif; ?>
    </div>

    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js?v=7"></script>
    <script>
    (function () {
        'use strict';
        var T = function (k, p) { return window.t('system.locations_in_use.' + k, p); };
        var API = '../../api/locations/';
        var KINDS = ['assets', 'people', 'tickets', 'contracts', 'projects'];
        var pick = document.getElementById('liuLocation');
        var out = document.getElementById('liuResult');
        if (!pick) return;
        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function otherOptions(except) {
            return Array.prototype.slice.call(pick.options)
                .filter(function (o) { return o.value && o.value !== String(except); })
                .map(function (o) { return '<option value="' + esc(o.value) + '">' + esc(o.text) + '</option>'; }).join('');
        }

        async function show() {
            var id = pick.value;
            if (!id) { out.innerHTML = ''; return; }
            out.innerHTML = '';
            var d;
            try {
                d = await (await fetch(API + 'in_use.php?id=' + encodeURIComponent(id), { credentials: 'same-origin' })).json();
            } catch (e) { out.innerHTML = '<p class="liu-error">' + esc(T('load_failed')) + '</p>'; return; }
            if (!d.success) { out.innerHTML = '<p class="liu-error">' + esc(d.error || T('load_failed')) + '</p>'; return; }
            if (!d.can_manage) { out.innerHTML = '<p class="liu-error">' + esc(d.reason) + '</p>'; return; }
            var total = KINDS.reduce(function (n, k) { return n + ((d.counts || {})[k] || 0); }, 0);
            if (!total) { out.innerHTML = '<div class="liu-empty">' + esc(T('unused', { path: d.path })) + '</div>'; return; }

            var html = '<div class="liu-panel"><div class="liu-counts">';
            KINDS.forEach(function (k) {
                if (d.counts[k] === undefined) return;
                html += '<span class="liu-count"><strong>' + Number(d.counts[k]) + '</strong>' + esc(T('kind_' + k)) + '</span>';
            });
            html += '</div>';
            KINDS.forEach(function (k) {
                var list = (d.records || {})[k] || [];
                if (!list.length) return;
                html += '<div class="liu-kind"><h3>' + esc(T('kind_' + k)) + '</h3><ul>'
                    + list.map(function (r) { return '<li><a href="../../' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.label || ('#' + r.id)) + '</a></li>'; }).join('')
                    + '</ul>' + (d.counts[k] > list.length ? '<div class="liu-more">' + esc(T('more', { n: d.counts[k] - list.length })) + '</div>' : '') + '</div>';
            });
            html += '</div><div class="liu-panel"><div class="liu-actions">'
                + '<label for="liuTo">' + esc(T('move_to')) + '</label>'
                + '<select id="liuTo"><option value=""></option>' + otherOptions(id) + '</select>'
                + '<button type="button" class="btn btn-primary" id="liuMove">' + esc(T('move')) + '</button>'
                + '<button type="button" class="btn btn-secondary" id="liuClear">' + esc(T('clear')) + '</button>'
                + '</div><p class="liu-note">' + esc(T('note')) + '</p></div>';
            out.innerHTML = html;
            document.getElementById('liuMove').addEventListener('click', function () {
                var to = document.getElementById('liuTo');
                if (!to.value) { window.showToast(T('pick_target'), 'error'); return; }
                run(id, to.value, T('confirm_move', { from: d.path, to: to.options[to.selectedIndex].text, n: total }));
            });
            document.getElementById('liuClear').addEventListener('click', function () {
                run(id, null, T('confirm_clear', { from: d.path, n: total }));
            });
        }

        async function run(from, to, message) {
            var ok = await window.showConfirm({ title: T('heading'), message: message, okLabel: to ? T('move') : T('clear'), okClass: to ? '' : 'danger' });
            if (!ok) return;
            try {
                var r = await fetch(API + 'move.php', {
                    method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ from: Number(from), to: to ? Number(to) : null })
                });
                var d = await r.json();
                if (!d.success) { window.showToast(d.error || T('failed'), 'error'); return; }
                var moved = KINDS.reduce(function (n, k) { return n + ((d.moved || {})[k] || 0); }, 0);
                var skipped = KINDS.reduce(function (n, k) { return n + ((d.skipped || {})[k] || 0); }, 0);
                window.showToast(skipped ? T('done_skipped', { n: moved, skipped: skipped }) : T('done', { n: moved }), skipped ? 'warning' : 'success');
                show();
            } catch (e) { window.showToast(T('failed'), 'error'); }
        }

        pick.addEventListener('change', show);
        if (pick.value) show();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
