<?php
/**
 * Assets -> Consumables -> Deliveries (5.8.0; Ed, 2026-11-21).
 *
 * One delivery is one order: its supplier, order number (optional), invoice and
 * documents, with a line per item - "10 mice, 10 toners and 5 keyboards from one
 * company is one order number with one document". Booking it in receives every line
 * into stock; the documents hang off the delivery through the shared Documents panel.
 *
 *   (no id)   the list, and New
 *   ?id=N     one delivery: header (editable, every change recorded), lines with Undo,
 *             Undo delivery, documents, history
 *
 * Reads and writes go through api/assets/consumables.php -> ConsumablesService.
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
$viewId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$delivery = null;
if ($ready && $viewId) {
    try { $delivery = ConsumablesService::getDelivery($conn, ActorContext::fromSession($conn), $viewId); }
    catch (ServiceError $e) { $delivery = null; }
}
$T = fn(string $k, array $p = []) => htmlspecialchars(t('asset-management.consumables.' . $k, $p));
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('assets', t('asset-management.consumables.deliveries'))); ?></title>
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
                <h1><?php echo $viewId && $delivery ? $T('delivery_title', ['ref' => $delivery['order_ref'] ?: ('#' . $delivery['id'])]) : $T('deliveries'); ?></h1>
                <p class="cs-intro"><?php echo $T('deliveries_intro'); ?></p>
            </div>
            <div class="cs-head-actions">
                <a class="btn btn-secondary" href="./"><?php echo $T('title'); ?></a>
                <?php if ($viewId): ?><a class="btn btn-secondary" href="deliveries.php"><?php echo $T('deliveries'); ?></a><?php endif; ?>
                <?php if ($ready && $mayWrite && !$viewId): ?><button type="button" class="btn btn-primary" id="dvNew"><?php echo htmlspecialchars(t('common.action_row.new')); ?></button><?php endif; ?>
            </div>
        </div>
<?php if (!$ready): ?>
        <div class="cs-empty"><?php echo $T('needs_verify'); ?></div>
<?php elseif ($viewId && !$delivery): ?>
        <div class="cs-empty"><?php echo $T('delivery_not_found'); ?></div>
<?php elseif ($viewId): ?>
        <div class="cs-delivery">
            <div class="cs-delivery-main">
                <form id="dvHead" class="cs-grid cs-card-box">
                    <div class="form-group"><label class="form-label" for="dhOrder"><?php echo $T('order_ref'); ?></label>
                        <input type="text" class="search-box" id="dhOrder" maxlength="100" value="<?php echo htmlspecialchars((string)$delivery['order_ref']); ?>" <?php echo $mayWrite ? '' : 'readonly'; ?>></div>
                    <div class="form-group"><label class="form-label" for="dhSupplier"><?php echo $T('supplier'); ?></label>
                        <select class="search-box" id="dhSupplier" <?php echo $mayWrite ? '' : 'disabled'; ?>></select></div>
                    <div class="form-group"><label class="form-label" for="dhInvoice"><?php echo $T('invoice_ref'); ?></label>
                        <input type="text" class="search-box" id="dhInvoice" maxlength="100" value="<?php echo htmlspecialchars((string)$delivery['invoice_ref']); ?>" <?php echo $mayWrite ? '' : 'readonly'; ?>></div>
                    <div class="form-group"><label class="form-label" for="dhInvoiceDate"><?php echo $T('invoice_date'); ?></label>
                        <input type="date" class="search-box" id="dhInvoiceDate" value="<?php echo htmlspecialchars((string)$delivery['invoice_date']); ?>" <?php echo $mayWrite ? '' : 'readonly'; ?>></div>
                    <div class="form-group"><label class="form-label" for="dhReceived"><?php echo $T('received_date'); ?></label>
                        <input type="date" class="search-box" id="dhReceived" value="<?php echo htmlspecialchars((string)$delivery['received_date']); ?>" <?php echo $mayWrite ? '' : 'readonly'; ?>></div>
                    <div class="form-group"><label class="form-label" for="dhNote"><?php echo $T('notes'); ?></label>
                        <input type="text" class="search-box" id="dhNote" maxlength="500" value="<?php echo htmlspecialchars((string)$delivery['note']); ?>" <?php echo $mayWrite ? '' : 'readonly'; ?>></div>
                    <div class="cs-span2 cs-dim"><?php echo $T('delivery_booked', ['who' => $delivery['created_by'] ?: t('asset-management.consumables.system')]); ?> <span data-when="<?php echo htmlspecialchars($delivery['created_datetime']); ?>"></span></div>
                    <?php if ($mayWrite): ?><div class="cs-span2"><button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('common.save')); ?></button></div><?php endif; ?>
                </form>
                <h2 class="cs-h2"><?php echo $T('delivery_lines'); ?></h2>
                <div id="dvLines"></div>
                <?php if ($mayWrite): ?><p><button type="button" class="btn btn-secondary" id="dvUndoAll"><?php echo $T('delivery_undo'); ?></button></p><?php endif; ?>
                <h2 class="cs-h2"><?php echo $T('history'); ?></h2>
                <div id="dvHistory"></div>
            </div>
            <aside class="cs-delivery-docs">
                <?php require_once '../../includes/documents_panel.php'; renderDocumentsPanel('consumable_delivery', $viewId, '../../'); ?>
            </aside>
        </div>
<?php else: ?>
        <div class="cs-bar">
            <input type="search" id="dvSearch" placeholder="<?php echo $T('deliveries_search'); ?>" aria-label="<?php echo $T('deliveries_search'); ?>">
            <span class="cs-count" id="dvCount"></span>
        </div>
        <div id="dvList"></div>
<?php endif; ?>
    </div>

<?php if ($ready && !$viewId && $mayWrite): ?>
    <div class="modal" id="dvModal">
        <div class="modal-content cs-modal-wide">
            <div class="modal-header"><span><?php echo $T('delivery_new'); ?></span></div>
            <form id="dvForm">
                <div class="modal-body">
                    <div class="cs-grid">
                        <div class="form-group"><label class="form-label" for="dnSupplier"><?php echo $T('supplier'); ?></label><select class="search-box" id="dnSupplier"></select></div>
                        <div class="form-group"><label class="form-label" for="dnOrder"><?php echo $T('order_ref'); ?></label>
                            <input type="text" class="search-box" id="dnOrder" maxlength="100" autocomplete="off" placeholder="<?php echo $T('optional'); ?>"></div>
                        <div class="form-group"><label class="form-label" for="dnInvoice"><?php echo $T('invoice_ref'); ?></label><input type="text" class="search-box" id="dnInvoice" maxlength="100" autocomplete="off"></div>
                        <div class="form-group"><label class="form-label" for="dnInvoiceDate"><?php echo $T('invoice_date'); ?></label><input type="date" class="search-box" id="dnInvoiceDate"></div>
                        <div class="form-group"><label class="form-label" for="dnReceived"><?php echo $T('received_date'); ?></label><input type="date" class="search-box" id="dnReceived"></div>
                        <div class="form-group"><label class="form-label" for="dnNote"><?php echo $T('notes'); ?></label><input type="text" class="search-box" id="dnNote" maxlength="500" autocomplete="off"></div>
                    </div>
                    <table class="cs-table cs-lines"><thead><tr>
                        <th><?php echo $T('name'); ?></th><th class="n"><?php echo $T('quantity'); ?></th><th class="n"><?php echo $T('unit_cost'); ?></th><th></th>
                    </tr></thead><tbody id="dnLines"></tbody></table>
                    <p><button type="button" class="btn btn-secondary" id="dnAddLine"><?php echo $T('delivery_add_line'); ?></button>
                        <span class="cs-dim" id="dnTotal"></span></p>
                    <p class="cs-dim"><?php echo $T('delivery_docs_hint'); ?></p>
                    <div class="cs-error" id="dnError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo $T('receive'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if ($ready && $viewId && $delivery && $mayWrite): ?>
    <div class="modal" id="dvUndoModal">
        <div class="modal-content">
            <div class="modal-header"><span><?php echo $T('undo_title'); ?></span></div>
            <form id="dvUndoForm">
                <div class="modal-body">
                    <p id="duWhat"></p>
                    <div class="form-group">
                        <label class="form-label" for="duReason"><?php echo $T('undo_reason'); ?></label>
                        <input type="text" class="search-box" id="duReason" maxlength="500" required autocomplete="off">
                    </div>
                    <div class="cs-error" id="duError" hidden></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-close><?php echo htmlspecialchars(t('common.cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo $T('undo'); ?></button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>
<?php if ($ready && ($viewId ? $delivery : true)): ?>
    <script>
    (function () {
        'use strict';
        const API = '../../api/assets/consumables.php';
        const WRITE = <?php echo $mayWrite ? 'true' : 'false'; ?>;
        const VIEW = <?php echo $delivery ? (int)$delivery['id'] : 0; ?>;
        const SUPPLIER = <?php echo json_encode($delivery['supplier_id'] ?? null); ?>;
        const T = (k, p) => window.t('asset-management.consumables.' + k, p);
        const $ = id => document.getElementById(id);
        const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        const money = v => v == null ? '' : (v < 0 ? '-' : '') + Money.format(Math.abs(v), Money.def());
        const when = s => s ? (typeof formatDateTime === 'function' ? formatDateTime(s) : String(s).slice(0, 16)) : '';
        const get = qs => fetch(API + '?' + qs, { credentials: 'same-origin' }).then(r => r.json());
        const post = b => fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(b) }).then(r => r.json());
        const toast = (m, k) => { if (typeof window.showToast === 'function') window.showToast(m, k || 'success'); };
        let meta = { suppliers: [] }, items = [];
        document.querySelectorAll('[data-when]').forEach(el => { el.textContent = when(el.dataset.when); });
        const supplierOptions = sel => '<option value="">' + esc(T('none')) + '</option>' + meta.suppliers.map(s => `<option value="${s.id}" ${s.id === sel ? 'selected' : ''}>${esc(s.name)}</option>`).join('');

        // ------------------------------------------------------------- the list
        async function loadList() {
            const d = await get('action=deliveries&q=' + encodeURIComponent($('dvSearch').value.trim()));
            if (!d.success) { $('dvList').innerHTML = `<div class="cs-empty">${esc(d.error)}</div>`; return; }
            $('dvCount').textContent = T('deliveries_count', { n: d.deliveries.length });
            $('dvList').innerHTML = d.deliveries.length ? `<table class="cs-table"><thead><tr><th>${esc(T('received_date'))}</th><th>${esc(T('order_ref'))}</th>
                    <th>${esc(T('supplier'))}</th><th>${esc(T('invoice_ref'))}</th><th class="n">${esc(T('delivery_lines'))}</th><th class="n">${esc(T('value'))}</th><th>${esc(T('documents'))}</th></tr></thead><tbody>`
                + d.deliveries.map(r => `<tr><td>${esc(r.received_date)}</td>
                    <td><a class="cs-name" href="?id=${r.id}">${esc(r.order_ref || ('#' + r.id))}</a>${r.undone ? ` <span class="cs-pill cs-out">${esc(T(r.undone >= r.line_count ? 'undone' : 'partly_undone'))}</span>` : ''}</td>
                    <td>${esc(r.supplier_name || '')}</td><td>${esc(r.invoice_ref || '')}</td>
                    <td class="n">${r.line_count}</td><td class="n">${money(r.value)}</td><td>${r.documents ? '📎 ' + r.documents : ''}</td></tr>`).join('') + '</tbody></table>'
                : `<div class="cs-empty">${esc(T($('dvSearch').value ? 'none_match' : 'deliveries_none'))}</div>`;
        }

        // ------------------------------------------------------------- new delivery
        function lineRow() {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td><select class="search-box" data-f="item"><option value=""></option>${items.map(i => `<option value="${i.id}">${esc(i.name + (i.part_number ? ' (' + i.part_number + ')' : ''))}</option>`).join('')}</select></td>
                <td class="n"><input type="number" class="search-box cs-num" data-f="qty" min="1" step="1"></td>
                <td class="n"><input type="number" class="search-box cs-num" data-f="cost" min="0" step="0.01"></td>
                <td><button type="button" class="cs-x" data-remove aria-label="${esc(T('delivery_remove_line'))}">&times;</button></td>`;
            tr.querySelector('[data-f=item]').addEventListener('change', e => {
                const it = items.find(i => String(i.id) === e.target.value);
                if (it && tr.querySelector('[data-f=cost]').value === '' && it.unit_cost != null) tr.querySelector('[data-f=cost]').value = Number(it.unit_cost).toFixed(2);
                total();
            });
            tr.querySelectorAll('input').forEach(i => i.addEventListener('input', total));
            tr.querySelector('[data-remove]').addEventListener('click', () => { tr.remove(); total(); });
            $('dnLines').appendChild(tr);
        }
        function total() {
            let t = 0, n = 0;
            $('dnLines').querySelectorAll('tr').forEach(tr => {
                const q = parseInt(tr.querySelector('[data-f=qty]').value, 10) || 0, c = parseFloat(tr.querySelector('[data-f=cost]').value) || 0;
                if (tr.querySelector('[data-f=item]').value) { n++; t += q * c; }
            });
            $('dnTotal').textContent = n ? T('delivery_total', { n, value: money(t) }) : '';
        }
        async function openNew() {
            const d = await get('action=list');
            items = d.success ? d.items.filter(i => i.is_active) : [];
            $('dnSupplier').innerHTML = supplierOptions(null);
            ['dnOrder', 'dnInvoice', 'dnInvoiceDate', 'dnNote'].forEach(id => { $(id).value = ''; });
            $('dnReceived').value = new Date().toISOString().slice(0, 10);
            $('dnLines').innerHTML = ''; lineRow(); lineRow(); lineRow();
            total();
            $('dnError').hidden = true;
            $('dvModal').classList.add('active');
        }

        async function start() {
            const m = await get('action=meta');
            if (m.success) meta = m;
            if (VIEW) return showDelivery();
            loadList();
            let timer;
            $('dvSearch').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadList, 250); });
            if ($('dvNew')) $('dvNew').onclick = openNew;
            if ($('dvModal')) {
                $('dvModal').querySelector('[data-close]').onclick = () => $('dvModal').classList.remove('active');
                $('dnAddLine').onclick = lineRow;
                $('dvForm').onsubmit = async e => {
                    e.preventDefault();
                    const lines = [];
                    $('dnLines').querySelectorAll('tr').forEach(tr => {
                        const item = tr.querySelector('[data-f=item]').value;
                        if (item) lines.push({ item_id: item, quantity: tr.querySelector('[data-f=qty]').value, unit_cost: tr.querySelector('[data-f=cost]').value });
                    });
                    const r = await post({ action: 'receive_delivery', delivery: { supplier_id: $('dnSupplier').value, order_ref: $('dnOrder').value, invoice_ref: $('dnInvoice').value,
                        invoice_date: $('dnInvoiceDate').value, received_date: $('dnReceived').value, note: $('dnNote').value, lines } });
                    if (!r.success) { $('dnError').textContent = r.error; $('dnError').hidden = false; return; }
                    // Straight to the delivery, where its documents go.
                    location.href = 'deliveries.php?id=' + r.delivery_id;
                };
            }
            const u = new URLSearchParams(location.search);
            if (u.get('new') === '1' && $('dvNew')) openNew();
        }

        // ------------------------------------------------------------- one delivery
        async function showDelivery() {
            if ($('dhSupplier')) $('dhSupplier').innerHTML = supplierOptions(SUPPLIER);
            const d = await get('action=delivery&id=' + VIEW);
            if (!d.success) { $('dvLines').innerHTML = `<div class="cs-empty">${esc(d.error)}</div>`; return; }
            const del = d.delivery;
            $('dvLines').innerHTML = `<table class="cs-table"><thead><tr><th>${esc(T('name'))}</th><th>${esc(T('status'))}</th><th class="n">${esc(T('quantity'))}</th>
                    <th class="n">${esc(T('unit_cost'))}</th><th class="n">${esc(T('value'))}</th><th></th></tr></thead><tbody>`
                + del.lines.slice().reverse().map(m => `<tr class="${m.reversed_by ? 'cs-undone' : ''}">
                    <td><a class="cs-name" href="./?item=${m.item_id}">${esc(m.item_name)}</a>${m.reason ? `<span class="cs-sub">${esc(m.reason)}</span>` : ''}</td>
                    <td>${esc(T('kind_' + m.kind))}${m.reversed_by ? ` <span class="cs-pill cs-out">${esc(T('undone'))}</span>` : ''}</td>
                    <td class="n ${m.quantity < 0 ? 'cs-neg' : 'cs-pos'}">${m.quantity > 0 ? '+' : ''}${m.quantity} ${esc(m.unit || '')}</td>
                    <td class="n">${money(m.unit_cost)}</td><td class="n">${money(m.value)}</td>
                    <td>${WRITE && m.kind === 'receive' && !m.reversed_by ? `<button type="button" class="cs-link" data-undo="${m.id}">${esc(T('undo'))}</button>` : ''}</td></tr>`).join('')
                + `</tbody><tfoot><tr><td colspan="4"><strong>${esc(T('value'))}</strong></td><td class="n"><strong>${money(del.value)}</strong></td><td></td></tr></tfoot></table>`;
            const HIST = { 'delivery.created': 'h_delivery_created', 'delivery.order_ref': 'order_ref', 'delivery.supplier_id': 'supplier', 'delivery.invoice_ref': 'invoice_ref',
                           'delivery.invoice_date': 'invoice_date', 'delivery.received_date': 'received_date', 'delivery.note': 'notes', 'delivery.undone': 'delivery_undo' };
            $('dvHistory').innerHTML = del.audit.length ? '<ul class="cs-moves">' + del.audit.map(a => `<li><div class="cs-move-top"><strong>${esc(HIST[a.field_name] ? T(HIST[a.field_name]) : a.field_name)}</strong>
                    <span class="cs-when">${esc(when(a.created_datetime))}</span></div>
                    <div class="cs-move-sub">${esc(a.analyst_name || T('system'))}: ${a.old_value != null ? esc(a.old_value) + ' → ' : ''}${esc(a.new_value ?? T('cleared'))}</div></li>`).join('') + '</ul>'
                : `<p class="cs-dim">${esc(T('no_history'))}</p>`;
            const allUndone = !del.lines.some(m => m.kind === 'receive' && !m.reversed_by);
            if ($('dvUndoAll')) $('dvUndoAll').hidden = allUndone;
        }
        // Undo a line or the whole delivery: a reason, always (the house dialog, as on an item).
        let undoBody = null;
        function undo(body, what) {
            undoBody = body;
            $('duWhat').textContent = what;
            $('duReason').value = '';
            $('duError').hidden = true;
            $('dvUndoModal').classList.add('active');
            setTimeout(() => $('duReason').focus(), 50);
        }
        if ($('dvUndoModal')) {
            $('dvUndoModal').querySelector('[data-close]').onclick = () => $('dvUndoModal').classList.remove('active');
            $('dvUndoForm').onsubmit = async e => {
                e.preventDefault();
                const r = await post(Object.assign({}, undoBody, { reason: $('duReason').value }));
                if (!r.success) { $('duError').textContent = r.error; $('duError').hidden = false; return; }
                $('dvUndoModal').classList.remove('active');
                toast(T('undone_done'));
                showDelivery();
            };
        }
        if (VIEW) {
            document.addEventListener('click', e => {
                const u = e.target.closest('[data-undo]');
                if (u) undo({ action: 'reverse', movement_id: parseInt(u.dataset.undo, 10) }, T('delivery_undo_line'));
            });
            if ($('dvUndoAll')) $('dvUndoAll').onclick = () => undo({ action: 'reverse_delivery', id: VIEW }, T('delivery_undo_what'));
            if ($('dvHead') && WRITE) $('dvHead').onsubmit = async e => {
                e.preventDefault();
                const r = await post({ action: 'save_delivery', id: VIEW, delivery: { order_ref: $('dhOrder').value, supplier_id: $('dhSupplier').value, invoice_ref: $('dhInvoice').value,
                    invoice_date: $('dhInvoiceDate').value, received_date: $('dhReceived').value, note: $('dhNote').value } });
                if (!r.success) { toast(r.error, 'error'); return; }
                toast(T('saved'));
                showDelivery();
            };
        }
        start();
    })();
    </script>
<?php endif; ?>
</body>
</html>
