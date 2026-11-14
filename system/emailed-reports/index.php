<?php
/**
 * System - Emailed reports (5.3.0)
 *
 * How a report an analyst emails to themselves reaches them: in the body, as an
 * attachment, as a download link - any combination, at least one. And the list
 * of download links sent, with Revoke, and the recent link activity (the audit
 * rows in system_logs, type 'report_download').
 *
 * The settings are rendered SERVER-SIDE into the ticks, as on Date and time
 * formats: a page that loads its state over the wire shows plausible defaults
 * when the fetch fails, and Save then writes the guess back as fact.
 *
 * The rules: includes/report_downloads.php. The API: api/system/emailed_reports.php.
 *
 * @wiki Outbound-Email-Attachments-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/timezone.php';
require_once '../../includes/theme.php';
require_once '../../includes/report_downloads.php';
I18n::initFromSession();
Tz::init();

$current_page = 'emailed-reports';
require_once __DIR__ . '/../includes/page_gate.php';
$path_prefix = '../../';
$translationNamespaces = ['common', 'system'];

$conn = connectToDatabase();
$settings = reportEmailSettings($conn);
$linksReady = reportDownloadsReady($conn);
$linkWarning = reportLinkReachabilityWarning($conn);
$rT = function ($k, $p = []) { return t('system.emailed_reports.' . $k, $p); };
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('system', $rT('title'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=79">
    <style>
        body {
            --accent: var(--sys-accent, #546e7a);
            --accent-hover: var(--sys-accent-hover, #37474f);
            --on-accent: var(--sys-on-accent, #fff);
        }
        /* Page layout, title and panels: assets/css/system-page.css (.sys-page, .sys-panel). */
        .er-panel h3 { font-size: 15px; font-weight: 600; color: var(--text, #333); margin: 0 0 4px; }
        .er-panel .er-desc { font-size: 13px; color: var(--text-dim, #888); margin: 0 0 14px; line-height: 1.5; }
        .er-how { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
        .er-how label { display: flex; gap: 10px; align-items: flex-start; cursor: pointer; font-size: 14px; color: var(--text, #333); }
        .er-how input { margin-top: 3px; }
        .er-how small { display: block; font-size: 12px; color: var(--text-dim, #888); }
        .er-warn { margin: 4px 0 0 26px; padding: 8px 12px; border-radius: 6px; font-size: 12px;
                   background: var(--warning-bg, #fff8e1); color: var(--warning-text, #8a6d00); border: 1px solid var(--warning-border, #ffe08a); word-break: break-all; }
        .er-days { display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--text, #333); margin-bottom: 16px; }
        .er-days select { padding: 6px 8px; border: 1px solid var(--border, #ddd); border-radius: 6px; background: var(--surface, #fff); color: var(--text, #333); }
        .er-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .er-table th { text-align: left; font-weight: 600; color: var(--text-dim, #888); padding: 8px; border-bottom: 1px solid var(--border, #e0e0e0); }
        .er-table td { padding: 8px; border-bottom: 1px solid var(--border, #eee); color: var(--text, #333); vertical-align: middle; }
        .er-table td.num { font-variant-numeric: tabular-nums; }
        .er-empty { font-size: 13px; color: var(--text-dim, #888); }
        .er-status { font-size: 12px; padding: 2px 8px; border-radius: 10px; background: var(--surface-2, #f0f2f5); }
        .er-status.active { background: var(--success-bg, #e6f4ea); color: var(--success-text, #1e7e34); }
        .er-scroll { overflow-x: auto; }
        .er-panel .btn-small { padding: 4px 10px; font-size: 12px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="system" data-mobile-page="emailed-reports">
    <?php include '../includes/header.php'; ?>

    <div class="sys-page">
        <div class="sys-page-head"><div>
            <h1 class="sys-title"><?php echo htmlspecialchars($rT('title')); ?></h1>
            <p class="sys-subtitle"><?php echo htmlspecialchars($rT('subtitle')); ?></p>
        </div></div>

        <form id="erForm" class="sys-panel er-panel">
            <h3><?php echo htmlspecialchars($rT('how_heading')); ?></h3>
            <div class="er-how">
                <label><input type="checkbox" name="body" <?php echo $settings['body'] ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($rT('how_body')); ?><small><?php echo htmlspecialchars($rT('how_body_hint')); ?></small></span></label>
                <label><input type="checkbox" name="attach" <?php echo $settings['attach'] ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($rT('how_attach')); ?><small><?php echo htmlspecialchars($rT('how_attach_hint')); ?></small></span></label>
                <label><input type="checkbox" name="link" <?php echo $settings['link'] ? 'checked' : ''; ?>>
                    <span><?php echo htmlspecialchars($rT('how_link')); ?><small><?php echo htmlspecialchars($rT('how_link_hint')); ?></small></span></label>
                <?php if (!$linksReady): ?>
                    <div class="er-warn"><?php echo htmlspecialchars($rT('not_ready')); ?></div>
                <?php endif; ?>
                <?php if ($linkWarning !== ''): ?>
                    <div class="er-warn" id="erLinkWarn"<?php echo $settings['link'] ? '' : ' hidden'; ?>><?php echo htmlspecialchars($rT('link_warning', ['url' => $linkWarning])); ?></div>
                <?php endif; ?>
            </div>
            <div class="er-days">
                <label for="erDays"><?php echo htmlspecialchars($rT('link_days')); ?></label>
                <select id="erDays" name="link_days">
                    <?php foreach (REPORT_LINK_DAY_CHOICES as $d): ?>
                        <option value="<?php echo $d; ?>" <?php echo $d === $settings['link_days'] ? 'selected' : ''; ?>><?php
                            echo htmlspecialchars($d === 1 ? $rT('days_1') : $rT('days_n', ['n' => $d])); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button>
        </form>

        <div class="sys-panel er-panel">
            <h3><?php echo htmlspecialchars($rT('links_heading')); ?></h3>
            <p class="er-desc"><?php echo htmlspecialchars($rT('links_desc')); ?></p>
            <div class="er-scroll" id="erLinks"></div>
        </div>

        <div class="sys-panel er-panel">
            <h3><?php echo htmlspecialchars($rT('activity_heading')); ?></h3>
            <div class="er-scroll" id="erActivity"></div>
        </div>
    </div>

    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <script src="../../assets/js/toast.js"></script>
    <script src="../../assets/js/confirm.js?v=7"></script>
    <script>
    (function () {
        const API = '<?php echo $path_prefix; ?>api/system/emailed_reports.php';
        const T = (k, p) => window.t('system.emailed_reports.' + k, p);
        const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        const when = s => s ? (window.fmtDateTime ? window.fmtDateTime(s) : s) : '';
        const form = document.getElementById('erForm');

        form.link.addEventListener('change', () => {
            const w = document.getElementById('erLinkWarn');
            if (w) w.hidden = !form.link.checked;
        });

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!form.body.checked && !form.attach.checked && !form.link.checked) {
                showToast(T('none_ticked'), 'error');
                return;
            }
            const btn = form.querySelector('button[type="submit"]');
            btn.disabled = true;
            try {
                const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'save', body: form.body.checked, attach: form.attach.checked,
                                           link: form.link.checked, link_days: parseInt(form.link_days.value, 10) }) });
                const d = await r.json();
                showToast(d.success ? T('saved') : (d.error || T('save_failed')), d.success ? 'success' : 'error');
            } catch (err) {
                showToast(T('save_failed'), 'error');
            }
            btn.disabled = false;
        });

        function status(l) {
            if (l.revoked_at) return ['revoked', T('status_revoked')];
            if (window.parseUTCDate && window.parseUTCDate(l.expires_at) <= new Date()) return ['expired', T('status_expired')];
            return ['active', T('status_active')];
        }

        function renderLinks(links) {
            const box = document.getElementById('erLinks');
            if (!links.length) { box.innerHTML = '<p class="er-empty">' + esc(T('links_empty')) + '</p>'; return; }
            box.innerHTML = '<table class="er-table"><thead><tr>'
                + ['col_owner', 'col_file', 'col_created', 'col_expires', 'col_downloads', 'col_status', ''].map(k => '<th>' + (k ? esc(T(k)) : '') + '</th>').join('')
                + '</tr></thead><tbody>' + links.map(l => {
                    const [cls, label] = status(l);
                    return '<tr><td>' + esc(l.owner_name || ('#' + l.analyst_id)) + '</td><td>' + esc(l.filename) + '</td>'
                        + '<td>' + esc(when(l.created_at)) + '</td><td>' + esc(when(l.expires_at)) + '</td>'
                        + '<td class="num">' + esc(l.download_count) + '</td>'
                        + '<td><span class="er-status ' + cls + '">' + esc(label) + '</span></td>'
                        + '<td>' + (cls === 'active' ? '<button type="button" class="btn btn-secondary btn-small" data-revoke="' + esc(l.id)
                            + '" data-file="' + esc(l.filename) + '">' + esc(T('revoke')) + '</button>' : '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }

        function eventLabel(d) {
            const a = d.action || '';
            if (a === 'refused') return T('event_refused', { why: T('why_' + (d.outcome || 'unknown')) });
            return T('event_' + a);
        }

        function renderActivity(rows) {
            const box = document.getElementById('erActivity');
            if (!rows.length) { box.innerHTML = '<p class="er-empty">' + esc(T('activity_empty')) + '</p>'; return; }
            box.innerHTML = '<table class="er-table"><thead><tr>'
                + ['col_when', 'col_who', 'col_event', 'col_file', 'col_ip'].map(k => '<th>' + esc(T(k)) + '</th>').join('')
                + '</tr></thead><tbody>' + rows.map(r => {
                    const d = r.details || {};
                    return '<tr><td>' + esc(when(r.created_datetime)) + '</td><td>' + esc(r.analyst_name || (r.analyst_id ? '#' + r.analyst_id : T('nobody')))
                        + '</td><td>' + esc(eventLabel(d)) + '</td><td>' + esc(d.filename || '') + '</td><td>' + esc(d.ip_address || '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }

        async function load() {
            try {
                const d = await (await fetch(API)).json();
                if (!d.success) throw new Error(d.error);
                renderLinks(d.links || []);
                renderActivity(d.activity || []);
            } catch (e) {
                document.getElementById('erLinks').innerHTML = '<p class="er-empty">' + esc(T('load_failed')) + '</p>';
            }
        }

        document.getElementById('erLinks').addEventListener('click', async (e) => {
            const b = e.target.closest('[data-revoke]');
            if (!b) return;
            const ok = await showConfirm({ title: T('revoke_title'), message: T('revoke_message', { file: b.dataset.file }),
                                           okLabel: T('revoke'), okClass: 'danger' });
            if (!ok) return;
            try {
                const r = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'revoke', id: parseInt(b.dataset.revoke, 10) }) });
                const d = await r.json();
                showToast(d.success ? T('revoked') : (d.error || T('save_failed')), d.success ? 'success' : 'error');
            } catch (err) {
                showToast(T('save_failed'), 'error');
            }
            load();
        });

        load();
    })();
    </script>
    <script src="../../assets/js/mobile.js?v=83"></script>
</body>
</html>
