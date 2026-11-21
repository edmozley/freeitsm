<?php
/**
 * Assets -> Consumables -> Differences (5.8.0): what finished stock takes found
 * missing or extra, month by month, in units or money, and the items that go
 * missing most - "a report that visualises differences and also the cash value"
 * (Ed, 2026-11-21). Losses and gains are drawn apart, so a gain of 3 can never
 * hide a loss of 3. Read with Assets access. Data: ConsumablesService::differences().
 *
 * @wiki Consumables-Developer-Guide
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
require_once '../../includes/money.php';
require_once '../../includes/services/consumables.php';
I18n::initFromSession();
Tz::init();
requireModuleAccess('assets');

$conn = connectToDatabase();
$current_page = 'consumables';
$path_prefix = '../../';
$translationNamespaces = ['common', 'asset-management'];
$ready = ConsumablesService::ready($conn);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.consumables.report'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <link rel="stylesheet" href="../../assets/css/consumables.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <?php echo Money::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=5"></script>
    <script src="../../assets/js/i18n.js?v=4"></script>
    <script src="../../assets/js/money.js?v=1"></script>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=196">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    <div class="cs-wrap">
        <div class="cs-head">
            <div>
                <h1><?php echo htmlspecialchars(t('asset-management.consumables.report')); ?></h1>
                <p class="cs-intro"><?php echo htmlspecialchars(t('asset-management.consumables.report_intro')); ?></p>
            </div>
            <div class="cs-head-actions">
                <a class="btn btn-secondary" href="./"><?php echo htmlspecialchars(t('asset-management.consumables.title')); ?></a>
                <a class="btn btn-secondary" href="deliveries.php"><?php echo htmlspecialchars(t('asset-management.consumables.deliveries')); ?></a>
                <a class="btn btn-secondary" href="stocktake.php"><?php echo htmlspecialchars(t('asset-management.consumables.stocktake')); ?></a>
            </div>
        </div>
<?php if (!$ready): ?>
        <div class="cs-empty"><?php echo htmlspecialchars(t('asset-management.consumables.needs_verify')); ?></div>
<?php else: ?>
        <div class="cs-bar">
            <select id="rpMonths" aria-label="<?php echo htmlspecialchars(t('asset-management.consumables.report_period')); ?>">
                <?php foreach ([3, 6, 12, 24] as $m): ?>
                <option value="<?php echo $m; ?>" <?php echo $m === 12 ? 'selected' : ''; ?>><?php echo htmlspecialchars(t('asset-management.consumables.report_months', ['n' => $m])); ?></option>
                <?php endforeach; ?>
            </select>
            <div class="cs-seg" role="group" aria-label="<?php echo htmlspecialchars(t('asset-management.consumables.report_show')); ?>">
                <button type="button" data-measure="value" aria-pressed="true"><?php echo htmlspecialchars(t('asset-management.consumables.report_value')); ?></button>
                <button type="button" data-measure="units" aria-pressed="false"><?php echo htmlspecialchars(t('asset-management.consumables.report_units')); ?></button>
            </div>
        </div>
        <div id="rpHost"></div>
<?php endif; ?>
    </div>
<?php if ($ready): ?>
    <script>
    (function () {
        'use strict';
        const API = '../../api/assets/consumables.php';
        const T = (k, p) => window.t('asset-management.consumables.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const money = v => { v = v || 0; return (v < 0 ? '-' : '') + Money.format(Math.abs(v), Money.def()); };
        let report = null, measure = 'value';

        async function load() {
            const d = await fetch(API + '?action=differences&months=' + document.getElementById('rpMonths').value, { credentials: 'same-origin' }).then(r => r.json());
            if (!d.success) { document.getElementById('rpHost').innerHTML = `<div class="cs-empty">${esc(d.error)}</div>`; return; }
            report = d.report;
            render();
        }

        // Losses down from the line, gains up from it, per month - drawn as SVG, no library.
        function chart() {
            const s = report.series;
            const lossOf = r => measure === 'value' ? -r.value_lost : -r.units_lost;
            const gainOf = r => measure === 'value' ? r.value_found : r.units_found;
            const max = Math.max(1, ...s.map(lossOf), ...s.map(gainOf));
            const W = 1100, H = 250, padL = 60, padB = 26, top = 10, mid = top + (H - top - padB) / 2, half = (H - top - padB) / 2;
            const bw = (W - padL - 10) / s.length;
            const fmt = v => measure === 'value' ? money(v) : String(v);
            let g = `<line class="cs-axis" x1="${padL}" x2="${W - 5}" y1="${mid}" y2="${mid}"/>`
                + `<text x="${padL - 6}" y="${top + 8}" text-anchor="end">${esc(fmt(max))}</text>`
                + `<text x="${padL - 6}" y="${mid + 4}" text-anchor="end">0</text>`
                + `<text x="${padL - 6}" y="${H - padB}" text-anchor="end">-${esc(fmt(max))}</text>`;
            s.forEach((r, i) => {
                const x = padL + i * bw + bw * 0.18, w = bw * 0.64;
                const lh = lossOf(r) / max * half, gh = gainOf(r) / max * half;
                const tip = T('report_tip', { month: r.month, lost: fmt(lossOf(r)), found: fmt(gainOf(r)) });
                if (gh > 0) g += `<rect class="cs-bar-gain" x="${x}" y="${mid - gh}" width="${w}" height="${gh}"><title>${esc(tip)}</title></rect>`;
                if (lh > 0) g += `<rect class="cs-bar-loss" x="${x}" y="${mid}" width="${w}" height="${lh}"><title>${esc(tip)}</title></rect>`;
                if (s.length <= 12 || i % 2 === 0) g += `<text x="${x + w / 2}" y="${H - 6}" text-anchor="middle">${esc(r.month.slice(2))}</text>`;
            });
            return `<div class="cs-chart"><svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(T('report_chart_label'))}">${g}</svg>
                <div class="cs-legend"><span><i class="cs-bar-gain" style="background:var(--success-accent, #16a34a)"></i>${esc(T('report_found'))}</span>
                <span><i style="background:var(--danger-accent, #dc2626)"></i>${esc(T('report_lost'))}</span></div></div>`;
        }

        function render() {
            const sum = (k) => report.series.reduce((a, r) => a + r[k], 0);
            const counts = report.counts.filter(c => c.status === 'finished');
            document.getElementById('rpHost').innerHTML = `
                <div class="cs-cards">
                    <div class="cs-card"><div class="cs-card-n cs-neg">${esc(money(sum('value_lost')))}</div><div class="cs-card-l">${esc(T('report_value_lost'))}</div></div>
                    <div class="cs-card"><div class="cs-card-n cs-pos">${esc(money(sum('value_found')))}</div><div class="cs-card-l">${esc(T('report_value_found'))}</div></div>
                    <div class="cs-card"><div class="cs-card-n">${sum('units_lost')}</div><div class="cs-card-l">${esc(T('units_lost'))}</div></div>
                    <div class="cs-card"><div class="cs-card-n">+${sum('units_found')}</div><div class="cs-card-l">${esc(T('units_found'))}</div></div>
                    <div class="cs-card"><div class="cs-card-n">${counts.length}</div><div class="cs-card-l">${esc(T('report_counts'))}</div></div>
                </div>
                ${chart()}
                <h2 class="cs-h2">${esc(T('report_items'))}</h2>
                ${report.items.length ? `<table class="cs-table"><thead><tr><th>${esc(T('name'))}</th><th class="n">${esc(T('report_times'))}</th>
                    <th class="n">${esc(T('units_lost'))}</th><th class="n">${esc(T('units_found'))}</th><th class="n">${esc(T('value_lost'))}</th><th class="n">${esc(T('value_found'))}</th></tr></thead><tbody>`
                    + report.items.map(r => `<tr><td><a class="cs-name" href="./?item=${r.id}">${esc(r.name)}</a></td><td class="n">${r.times}</td>
                        <td class="n cs-neg">${r.units_lost || ''}</td><td class="n cs-pos">${r.units_found ? '+' + r.units_found : ''}</td>
                        <td class="n cs-neg">${r.value_lost ? esc(money(r.value_lost)) : ''}</td><td class="n cs-pos">${r.value_found ? esc(money(r.value_found)) : ''}</td></tr>`).join('')
                    + '</tbody></table>' : `<div class="cs-empty">${esc(T('report_none'))}</div>`}`;
        }

        document.getElementById('rpMonths').addEventListener('change', load);
        document.querySelectorAll('[data-measure]').forEach(b => b.addEventListener('click', () => {
            measure = b.dataset.measure;
            document.querySelectorAll('[data-measure]').forEach(x => x.setAttribute('aria-pressed', x === b ? 'true' : 'false'));
            if (report) render();
        }));
        load();
    })();
    </script>
<?php endif; ?>
</body>
</html>
