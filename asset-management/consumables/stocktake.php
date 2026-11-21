<?php
/**
 * Assets -> Consumables -> Stock take (5.8.0).
 *
 * Start one, walk the shelves, type what is actually there. Each line keeps what the
 * books expected AT THAT MOMENT, what was counted, the difference and its value - so
 * "expected 10, found 8" is recorded as -2 and -2 x the price (Ed, 2026-11-21).
 * Finishing posts each difference to the ledger; it needs "Adjust consumable stock"
 * (System -> Roles). Cancelling posts nothing; what was counted stays on record.
 *
 *   ?id=N   a past stock take, read only
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
$mayWrite = analystCanWriteModule($conn, (int)$_SESSION['analyst_id'], 'assets');
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.consumables.stocktake'))); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=29">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=97">
    <link rel="stylesheet" href="../../assets/css/consumables.css?v=2">
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
                <h1><?php echo htmlspecialchars(t('asset-management.consumables.stocktake')); ?></h1>
                <p class="cs-intro"><?php echo htmlspecialchars(t('asset-management.consumables.stocktake_intro')); ?></p>
            </div>
            <div class="cs-head-actions">
                <a class="btn btn-secondary" href="./"><?php echo htmlspecialchars(t('asset-management.consumables.title')); ?></a>
                <a class="btn btn-secondary" href="report.php"><?php echo htmlspecialchars(t('asset-management.consumables.report')); ?></a>
            </div>
        </div>
<?php if (!$ready): ?>
        <div class="cs-empty"><?php echo htmlspecialchars(t('asset-management.consumables.needs_verify')); ?></div>
<?php else: ?>
        <div id="stHost"></div>
<?php endif; ?>
    </div>
<?php if ($ready): ?>
    <script>
    (function () {
        'use strict';
        const API = '../../api/assets/consumables.php';
        const WRITE = <?php echo $mayWrite ? 'true' : 'false'; ?>;
        const VIEW = <?php echo isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0; ?>;
        const T = (k, p) => window.t('asset-management.consumables.' + k, p);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const money = v => v == null ? '' : (v < 0 ? '-' : '') + (window.Money ? Money.format(Math.abs(v), Money.def()) : Math.abs(Number(v)).toFixed(2));
        const when = s => s ? (typeof formatDateTime === 'function' ? formatDateTime(s) : String(s).slice(0, 16)) : '';
        const host = document.getElementById('stHost');
        const get = qs => fetch(API + '?' + qs, { credentials: 'same-origin' }).then(r => r.json());
        const post = b => fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(r => r.json());
        const toast = (m, k) => { if (typeof window.showToast === 'function') window.showToast(m, k || 'success'); };
        let count = null;

        async function start() {
            if (VIEW) return showCount(VIEW);
            const meta = await get('action=meta');
            if (meta.success && meta.open_count) return showCount(meta.open_count.id);
            const d = await get('action=counts');
            host.innerHTML = (WRITE ? `<div class="cs-bar"><input type="text" id="stNote" maxlength="500" placeholder="${esc(T('stocktake_note_ph'))}">
                    <button type="button" class="btn btn-primary" id="stStart">${esc(T('stocktake_start'))}</button></div>` : '')
                + `<h2 class="cs-h2">${esc(T('stocktake_past'))}</h2>` + pastHtml(d.success ? d.counts : []);
            const b = document.getElementById('stStart');
            if (b) b.onclick = async () => {
                const r = await post({ action: 'count_start', note: document.getElementById('stNote').value });
                if (!r.success) { toast(r.error, 'error'); return; }
                showCount(r.id);
            };
        }

        function pastHtml(rows) {
            if (!rows.length) return `<div class="cs-empty">${esc(T('stocktake_none'))}</div>`;
            return `<table class="cs-table"><thead><tr><th>#</th><th>${esc(T('stocktake_started'))}</th><th>${esc(T('stocktake_finished'))}</th>
                <th>${esc(T('status'))}</th><th class="n">${esc(T('units_lost'))}</th><th class="n">${esc(T('units_found'))}</th>
                <th class="n">${esc(T('value_lost'))}</th><th class="n">${esc(T('value_found'))}</th></tr></thead><tbody>`
                + rows.map(c => `<tr><td><a class="cs-name" href="?id=${c.id}">#${c.id}</a>${c.note ? `<span class="cs-sub">${esc(c.note)}</span>` : ''}</td>
                    <td>${esc(when(c.started_datetime))}<span class="cs-sub">${esc(c.started_by || '')}</span></td>
                    <td>${esc(when(c.finished_datetime))}<span class="cs-sub">${esc(c.finished_by || '')}</span></td>
                    <td>${esc(T('count_' + c.status))}</td>
                    <td class="n cs-neg">${Number(c.units_lost) || ''}</td><td class="n cs-pos">${Number(c.units_found) ? '+' + Number(c.units_found) : ''}</td>
                    <td class="n cs-neg">${Number(c.value_lost) ? money(c.value_lost) : ''}</td><td class="n cs-pos">${Number(c.value_found) ? money(c.value_found) : ''}</td></tr>`).join('')
                + '</tbody></table>';
        }

        async function showCount(id) {
            const d = await get('action=count&id=' + id);
            if (!d.success) { host.innerHTML = `<div class="cs-empty">${esc(d.error)}</div>`; return; }
            count = d.count;
            render();
        }

        function totals() {
            let lost = 0, found = 0, vLost = 0, vFound = 0, done = 0;
            count.lines.forEach(l => {
                if (l.counted === null) return;
                done++;
                if (l.difference < 0) { lost += l.difference; vLost += l.value_difference || 0; }
                if (l.difference > 0) { found += l.difference; vFound += l.value_difference || 0; }
            });
            return { lost, found, vLost, vFound, done };
        }

        function render() {
            const open = count.status === 'open';
            const edit = open && WRITE;
            const t = totals();
            host.innerHTML = `
                <p class="cs-dim">${esc(T('stocktake_head', { id: count.id, when: when(count.started_datetime), who: count.started_by || '' }))}${count.note ? ' - ' + esc(count.note) : ''}
                    · <strong>${esc(T('count_' + count.status))}</strong></p>
                <div class="cs-totals" id="stTotals">${totalsHtml(t)}</div>
                ${edit ? `<div class="cs-bar"><input type="search" id="stFilter" placeholder="${esc(T('search_ph'))}">
                    <span class="cs-count">${esc(T('stocktake_hint'))}</span></div>` : ''}
                <table class="cs-table" id="stTable"><thead><tr><th>${esc(T('name'))}</th><th>${esc(T('type'))}</th>
                    <th class="n">${esc(T('expected'))}</th><th class="n">${esc(T('counted'))}</th><th class="n">${esc(T('difference'))}</th>
                    <th class="n">${esc(T('value'))}</th><th>${esc(T('counted_by'))}</th></tr></thead><tbody>`
                + count.lines.map(l => `<tr data-item="${l.item_id}" data-name="${esc((l.name + ' ' + (l.part_number || '') + ' ' + (l.type_name || '')).toLowerCase())}">
                    <td><strong>${esc(l.name)}</strong>${l.part_number ? `<span class="cs-sub">${esc(l.part_number)}</span>` : ''}</td>
                    <td>${esc(l.type_name || '')}</td>
                    <td class="n">${l.expected ?? (open ? l.on_hand : '')} ${esc(l.unit || '')}</td>
                    <td class="n">${edit ? `<input type="number" min="0" step="1" class="cs-count-input" value="${l.counted ?? ''}" aria-label="${esc(T('counted') + ': ' + l.name)}">` : (l.counted ?? '')}</td>
                    <td class="n ${l.difference < 0 ? 'cs-neg' : (l.difference > 0 ? 'cs-pos' : '')}" data-diff>${l.difference ? (l.difference > 0 ? '+' : '') + l.difference : (l.counted !== null ? '0' : '')}</td>
                    <td class="n ${l.value_difference < 0 ? 'cs-neg' : ''}" data-val>${l.value_difference ? money(l.value_difference) : ''}</td>
                    <td><span class="cs-sub">${esc(l.counted_by || '')}</span></td></tr>`).join('')
                + `</tbody></table>
                ${edit ? `<div class="cs-actions" style="margin-top:14px">
                    ${count.may_finish ? `<button type="button" class="btn btn-primary" id="stFinish">${esc(T('stocktake_finish'))}</button>` : `<span class="cs-dim">${esc(T('stocktake_finish_needs'))}</span>`}
                    <button type="button" class="btn btn-secondary" id="stCancel">${esc(T('stocktake_cancel'))}</button></div>` : ''}
                ${!open ? `<p><a class="cs-name" href="stocktake.php">${esc(T('stocktake_back'))}</a></p>` : ''}`;
            wire();
        }

        function totalsHtml(t) {
            return `<span>${esc(T('stocktake_counted_n', { n: t.done, total: count.lines.length }))}</span>
                <span class="cs-neg">${esc(T('units_lost'))}: <strong>${t.lost}</strong> (${money(t.vLost)})</span>
                <span class="cs-pos">${esc(T('units_found'))}: <strong>+${t.found}</strong> (${money(t.vFound)})</span>`;
        }

        function wire() {
            const f = document.getElementById('stFilter');
            if (f) f.oninput = () => {
                const q = f.value.trim().toLowerCase();
                document.querySelectorAll('#stTable tbody tr').forEach(tr => { tr.hidden = q && !tr.dataset.name.includes(q); });
            };
            // Saved as each figure is typed and the field is left - a long count survives a dropped connection.
            document.querySelectorAll('.cs-count-input').forEach(inp => inp.addEventListener('change', async () => {
                const tr = inp.closest('tr');
                const itemId = parseInt(tr.dataset.item, 10);
                if (inp.value === '') return;
                const r = await post({ action: 'count_line', count_id: count.id, item_id: itemId, counted: inp.value });
                if (!r.success) { toast(r.error, 'error'); inp.focus(); return; }
                const l = count.lines.find(x => x.item_id === itemId);
                Object.assign(l, { expected: r.expected, counted: r.counted, difference: r.difference, value_difference: r.value_difference });
                const dc = tr.querySelector('[data-diff]');
                dc.textContent = r.difference ? (r.difference > 0 ? '+' : '') + r.difference : '0';
                dc.className = 'n ' + (r.difference < 0 ? 'cs-neg' : (r.difference > 0 ? 'cs-pos' : ''));
                tr.querySelector('[data-val]').textContent = r.value_difference ? money(r.value_difference) : '';
                tr.children[2].firstChild.textContent = r.expected + ' ';
                inp.classList.add('cs-saved');
                document.getElementById('stTotals').innerHTML = totalsHtml(totals());
            }));
            const fin = document.getElementById('stFinish');
            if (fin) fin.onclick = async () => {
                const t = totals();
                if (!confirm(T('stocktake_finish_confirm', { n: t.done, lost: t.lost, found: t.found }))) return;
                const r = await post({ action: 'count_finish', count_id: count.id });
                if (!r.success) { toast(r.error, 'error'); return; }
                toast(T('stocktake_finished_done', { n: r.differences }));
                showCount(count.id);
            };
            const can = document.getElementById('stCancel');
            if (can) can.onclick = async () => {
                if (!confirm(T('stocktake_cancel_confirm'))) return;
                const r = await post({ action: 'count_cancel', count_id: count.id });
                if (!r.success) { toast(r.error, 'error'); return; }
                location.href = 'stocktake.php';
            };
        }
        start();
    })();
    </script>
<?php endif; ?>
</body>
</html>
