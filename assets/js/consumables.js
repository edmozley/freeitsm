/**
 * Assets -> Consumables (asset-management/consumables/index.php): the list, an
 * item's panel, and receiving, issuing, returning, adjusting and undoing stock.
 *
 * Needs window.CONSUMABLES from the page: api, write (may move stock), ticket
 * (issuing against one: {id, number, subject, person_id, person_name}), reorder
 * ({form_id, map}), openItem, status. Everything is checked again on the server
 * (includes/services/consumables.php) - nothing here is a permission.
 *
 * @wiki Consumables-Developer-Guide
 */
(function () {
    'use strict';
    const CFG = window.CONSUMABLES || {};
    const T = (k, p) => window.t('asset-management.consumables.' + k, p);
    const $ = id => document.getElementById(id);
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
    // A loss reads -£12.50, not £-12.50.
    const money = v => v == null ? '' : (v < 0 ? '-' : '') + (window.Money ? Money.format(Math.abs(v), Money.def()) : Math.abs(Number(v)).toFixed(2));
    const when = s => s ? (typeof formatDateTime === 'function' ? formatDateTime(s) : String(s).slice(0, 16)) : '';
    let meta = { types: [], suppliers: [], may_adjust: false, fields_by_type: {} };
    let items = [];
    let current = null;      // the item open in the panel

    async function api(qs) {
        const r = await fetch(CFG.api + '?' + qs, { credentials: 'same-origin' });
        return r.json();
    }
    async function post(body) {
        const r = await fetch(CFG.api, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        return r.json();
    }
    function toast(msg, kind) { if (typeof window.showToast === 'function') window.showToast(msg, kind || 'success'); }

    // ------------------------------------------------------------- the list
    async function loadMeta() {
        const d = await api('action=meta');
        if (!d.success) return;
        meta = d;
        const opts = '<option value="">' + esc(T('all_types')) + '</option>' + d.types.filter(t => t.is_active).map(t => `<option value="${t.id}">${esc(t.name)}</option>`).join('');
        $('csType').innerHTML = opts;
    }

    let timer = null;
    async function loadList() {
        const q = new URLSearchParams({ action: 'list', q: $('csSearch').value.trim(), type: $('csType').value, status: $('csStatus').value, inactive: $('csInactive').checked ? '1' : '' });
        const d = await api(q.toString());
        if (!d.success) { $('csList').innerHTML = '<div class="cs-empty">' + esc(d.error) + '</div>'; return; }
        items = d.items;
        renderList();
    }

    function statusPill(s) {
        return `<span class="cs-pill cs-${s}">${esc(T('state_' + s))}</span>`;
    }

    function renderList() {
        $('csCount').textContent = T('count', { n: items.length });
        if (!items.length) {
            $('csList').innerHTML = '<div class="cs-empty">' + esc(T($('csSearch').value || $('csType').value || $('csStatus').value ? 'none_match' : 'none_yet')) + '</div>';
            return;
        }
        const issueCol = CFG.ticket && CFG.write;
        $('csList').innerHTML = `<table class="cs-table"><thead><tr>
                <th>${esc(T('name'))}</th><th>${esc(T('type'))}</th><th class="n">${esc(T('on_hand'))}</th>
                <th class="n">${esc(T('reorder_point'))}</th><th>${esc(T('status'))}</th>
                <th class="n">${esc(T('unit_cost'))}</th><th class="n">${esc(T('value'))}</th>${issueCol ? '<th></th>' : ''}</tr></thead><tbody>`
            + items.map(i => `<tr data-id="${i.id}" class="${i.is_active ? '' : 'cs-retired'}">
                <td><a href="?item=${i.id}" data-open="${i.id}" class="cs-name">${esc(i.name)}</a>${i.part_number ? `<span class="cs-sub">${esc(i.part_number)}</span>` : ''}</td>
                <td>${esc(i.type_name || '')}</td>
                <td class="n"><strong>${i.on_hand}</strong> ${esc(i.unit || '')}</td>
                <td class="n">${i.reorder_point ?? ''}</td>
                <td>${statusPill(i.status)}</td>
                <td class="n">${money(i.unit_cost)}</td>
                <td class="n">${money(i.value)}</td>
                ${issueCol ? `<td><button type="button" class="btn btn-secondary cs-row-btn" data-issue="${i.id}">${esc(T('issue'))}</button></td>` : ''}
            </tr>`).join('') + '</tbody></table>';
    }

    // ------------------------------------------------------------- one item
    async function openItem(id) {
        const d = await api('action=item&id=' + id);
        if (!d.success) { toast(d.error, 'error'); return; }
        current = d.item;
        renderPanel();
        $('csPanel').hidden = false;
        const u = new URL(location.href); u.searchParams.set('item', id); history.replaceState(null, '', u);
    }

    function closePanel() {
        $('csPanel').hidden = true;
        current = null;
        const u = new URL(location.href); u.searchParams.delete('item'); history.replaceState(null, '', u);
    }

    const KIND_SIGN = { receive: '+', return: '+' };
    function renderPanel() {
        const i = current;
        $('csPanelTitle').innerHTML = esc(i.name) + ' ' + statusPill(i.status);
        const fact = (k, v) => v === null || v === undefined || v === '' ? '' : `<dt>${esc(T(k))}</dt><dd>${v}</dd>`;
        const fields = (i.fields || []).filter(f => f.display != null && f.display !== '').map(f => `<dt>${esc(f.label)}</dt><dd>${esc(f.display)}</dd>`).join('');
        const can = CFG.write;
        const btn = (kind, label, cls) => `<button type="button" class="btn ${cls || 'btn-secondary'}" data-move="${kind}">${esc(label)}</button>`;
        $('csPanelBody').innerHTML = `
            <div class="cs-onhand"><span class="cs-big">${i.on_hand}</span> ${esc(i.unit || '')} <span class="cs-dim">${esc(T('in_stock'))}</span>
                ${i.value != null ? `<span class="cs-dim">· ${esc(T('worth', { value: money(i.value) }))}</span>` : ''}</div>
            ${can && i.is_active ? `<div class="cs-actions">
                ${btn('receive', T('receive'), 'btn-primary')}${btn('issue', T('issue'))}${btn('return', T('return'))}
                ${i.may_adjust ? btn('adjust', T('adjust')) : ''}
                <button type="button" class="btn btn-secondary" id="csEdit">${esc(T('edit'))}</button>
                ${CFG.reorder ? `<button type="button" class="btn btn-secondary" id="csReorder">${esc(T('reorder'))}</button>` : ''}
            </div>` : (can ? `<div class="cs-actions"><button type="button" class="btn btn-secondary" id="csEdit">${esc(T('edit'))}</button></div>` : '')}
            <dl class="cs-facts">
                ${fact('type', esc(i.type_name || ''))}${fact('part_number', esc(i.part_number || ''))}
                ${fact('supplier', esc(i.supplier_name || ''))}${fact('unit_cost', money(i.unit_cost))}
                ${fact('reorder_point', i.reorder_point)}${fact('min_level', i.min_level)}
                ${fields}${fact('notes', i.notes ? esc(i.notes).replace(/\n/g, '<br>') : '')}
            </dl>
            <div class="cs-tabs" role="tablist">
                <button type="button" role="tab" aria-selected="true" data-tab="moves">${esc(T('movements'))}</button>
                <button type="button" role="tab" aria-selected="false" data-tab="history">${esc(T('history'))}</button>
            </div>
            <div data-pane="moves">${movesHtml(i.movements)}</div>
            <div data-pane="history" hidden>${historyHtml(i.audit)}</div>`;
    }

    function movesHtml(rows) {
        if (!rows.length) return '<p class="cs-dim">' + esc(T('no_movements')) + '</p>';
        return '<ul class="cs-moves">' + rows.map(m => {
            const who = [m.recipient_name ? T(m.kind === 'return' ? 'from_person' : 'to_person', { name: m.recipient_name }) : '',
                         m.supplier_name ? T('from_supplier', { name: m.supplier_name }) : '',
                         m.invoice_ref ? T('invoice', { ref: m.invoice_ref }) : ''].filter(Boolean).join(' · ');
            // The delivery a receipt belongs to - its order number, its documents.
            const order = m.delivery_id ? ` · <a class="cs-move-order" href="deliveries.php?id=${m.delivery_id}">${esc(m.order_ref ? T('order', { ref: m.order_ref }) : T('delivery_n', { id: m.delivery_id }))}</a>` : '';
            const undone = m.reversed_by ? `<span class="cs-pill cs-out">${esc(T('undone'))}</span>` : '';
            const canUndo = CFG.write && !m.reversed_by && !['reverse', 'count'].includes(m.kind) && (m.kind !== 'adjust' || meta.may_adjust);
            return `<li class="${m.reversed_by ? 'cs-undone' : ''}">
                <div class="cs-move-top"><span class="cs-kind cs-k-${m.kind}">${esc(T('kind_' + m.kind))}</span>
                    <strong class="${m.quantity < 0 ? 'cs-neg' : 'cs-pos'}">${m.quantity > 0 ? '+' : ''}${m.quantity}</strong>
                    <span class="cs-dim">→ ${m.balance_after}</span> ${undone}
                    <span class="cs-when">${esc(when(m.created_datetime))}</span></div>
                <div class="cs-move-sub">${esc(m.analyst_name || T('system'))}${who ? ' · ' + esc(who) : ''}
                    ${m.ticket_id ? ` · <a href="../../tickets/?ticket_id=${m.ticket_id}">${esc(m.ticket_number || ('#' + m.ticket_id))}</a>` : ''}
                    ${m.value != null ? ' · ' + money(m.value) : ''}${order}</div>
                ${m.reason ? `<div class="cs-move-reason">${esc(m.reason)}</div>` : ''}
                ${canUndo ? `<button type="button" class="cs-link" data-undo="${m.id}">${esc(T('undo'))}</button>` : ''}
            </li>`;
        }).join('') + '</ul>';
    }

    // What a history line's field is called: the built-in ones by their label here; a
    // custom field's history already carries its own label.
    const HIST = { name: 'name', part_number: 'part_number', unit: 'unit', type_id: 'type', supplier_id: 'supplier', unit_cost: 'unit_cost',
                   notes: 'notes', is_active: 'in_use', min_level: 'min_level', reorder_point: 'reorder_point', created: 'h_created', 'count.counted': 'h_counted' };
    function historyHtml(rows) {
        if (!rows || !rows.length) return '<p class="cs-dim">' + esc(T('no_history')) + '</p>';
        return '<ul class="cs-moves">' + rows.map(a => `<li>
            <div class="cs-move-top"><strong>${esc(HIST[a.field_name] ? T(HIST[a.field_name]) : a.field_name)}</strong>
                <span class="cs-when">${esc(when(a.created_datetime))}</span></div>
            <div class="cs-move-sub">${esc(a.analyst_name || T('system'))}: ${a.old_value != null ? esc(a.old_value) + ' → ' : ''}${esc(a.new_value ?? T('cleared'))}</div>
        </li>`).join('') + '</ul>';
    }

    // ------------------------------------------------------------- dialogs
    function openModal(id) { $(id).classList.add('active'); setTimeout(() => { const f = $(id).querySelector('input:not([readonly]):not([type=hidden]), select'); if (f) f.focus(); }, 50); }
    function closeModal(id) { $(id).classList.remove('active'); }
    document.querySelectorAll('.modal [data-close]').forEach(b => b.addEventListener('click', () => closeModal(b.closest('.modal').id)));
    function showError(id, msg) { const e = $(id); e.textContent = msg || ''; e.hidden = !msg; }

    function supplierOptions(sel) {
        return '<option value="">' + esc(T('none')) + '</option>' + meta.suppliers.map(s => `<option value="${s.id}" ${s.id === sel ? 'selected' : ''}>${esc(s.name)}</option>`).join('');
    }

    // The custom fields for the chosen type (System -> Custom fields -> Consumables).
    function drawFields(typeId, values) {
        const defs = meta.fields_by_type[typeId || 0] || meta.fields_by_type['0'] || [];
        values = values || {};
        $('ciFields').innerHTML = defs.length ? '<div class="cs-grid">' + defs.map(f => {
            const v = values[f.key] ?? f.value ?? '';
            const id = 'cif_' + f.key;
            let input;
            if (f.type === 'boolean') input = `<select class="search-box" id="${id}" data-key="${esc(f.key)}"><option value=""></option><option value="1" ${v === true || v === 1 || v === '1' ? 'selected' : ''}>${esc(window.t('common.yes') || 'Yes')}</option><option value="0" ${v === false || v === 0 || v === '0' ? 'selected' : ''}>${esc(window.t('common.no') || 'No')}</option></select>`;
            else if (f.type === 'dropdown') input = `<select class="search-box" id="${id}" data-key="${esc(f.key)}"><option value=""></option>${(f.options || []).map(o => `<option ${o === v ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select>`;
            else {
                const type = { number: 'number', date: 'date', email: 'email', url: 'url' }[f.type] || 'text';
                input = `<input type="${type}" class="search-box" id="${id}" data-key="${esc(f.key)}" value="${esc(f.type === 'date' ? String(v).slice(0, 10) : v)}">`;
            }
            return `<div class="form-group"><label class="form-label" for="${id}">${esc(f.label)}${f.required_on_create ? ' *' : ''}</label>${input}${f.help_text ? `<div class="form-hint">${esc(f.help_text)}</div>` : ''}</div>`;
        }).join('') + '</div>' : '';
    }

    function openItemForm(item) {
        const i = item || {};
        $('csItemTitle').textContent = item ? T('edit_title', { name: i.name }) : T('new_title');
        $('ciName').value = i.name || '';
        $('ciType').innerHTML = '<option value="">' + esc(T('none')) + '</option>' + meta.types.filter(t => t.is_active || t.id === i.type_id).map(t => `<option value="${t.id}" ${t.id === i.type_id ? 'selected' : ''}>${esc(t.name)}</option>`).join('');
        $('ciPart').value = i.part_number || '';
        $('ciUnit').value = i.unit || '';
        $('ciUnits').innerHTML = ['each', 'box', 'pack', 'ream', 'roll', 'cartridge'].map(u => `<option value="${esc(T('unit_' + u))}">`).join('');
        $('ciSupplier').innerHTML = supplierOptions(i.supplier_id);
        $('ciCost').value = i.unit_cost ?? '';
        $('ciReorder').value = i.reorder_point ?? '';
        $('ciMin').value = i.min_level ?? '';
        $('ciNotes').value = i.notes || '';
        $('ciActive').checked = item ? !!i.is_active : true;
        $('ciActiveRow').hidden = !item;
        const vals = {};
        (i.fields || []).forEach(f => { vals[f.key] = f.value; });
        drawFields(i.type_id, vals);
        $('csItemForm').dataset.id = item ? i.id : '';
        showError('ciError', '');
        openModal('csItemModal');
    }
    $('ciType') && $('ciType').addEventListener('change', () => {
        const keep = {};
        $('ciFields').querySelectorAll('[data-key]').forEach(el => { keep[el.dataset.key] = el.value; });
        drawFields(parseInt($('ciType').value, 10) || 0, keep);
    });

    $('csItemForm') && $('csItemForm').addEventListener('submit', async e => {
        e.preventDefault();
        const fields = {};
        $('ciFields').querySelectorAll('[data-key]').forEach(el => { fields[el.dataset.key] = el.value; });
        const id = parseInt($('csItemForm').dataset.id || '0', 10);
        const d = await post({ action: 'save_item', item: {
            id, name: $('ciName').value, type_id: $('ciType').value, part_number: $('ciPart').value, unit: $('ciUnit').value,
            supplier_id: $('ciSupplier').value, unit_cost: $('ciCost').value, reorder_point: $('ciReorder').value,
            min_level: $('ciMin').value, notes: $('ciNotes').value, is_active: $('ciActive').checked, fields,
        } });
        if (!d.success) { showError('ciError', d.error); return; }
        closeModal('csItemModal');
        toast(T('saved'));
        await loadList();
        openItem(d.id);
    });

    // Receive / issue / return / adjust - one dialog, its fields chosen by the kind.
    let moveKind = '', moveItem = null, movePerson = null;
    function openMove(kind, item) {
        moveKind = kind; moveItem = item; movePerson = null;
        $('csMoveTitle').textContent = T('move_title_' + kind);
        $('csMoveWhat').textContent = T('move_what', { name: item.name, n: item.on_hand, unit: item.unit || '' });
        document.querySelectorAll('#csMoveModal [data-kinds]').forEach(el => { el.hidden = !el.dataset.kinds.split(' ').includes(kind); });
        $('cmQty').value = '';
        $('cmQty').min = kind === 'adjust' ? '' : '1';
        if (kind === 'issue') $('cmQty').max = item.on_hand; else $('cmQty').removeAttribute('max');
        $('cmQtyLabel').textContent = T(kind === 'adjust' ? 'quantity_adjust' : 'quantity');
        $('cmQtyHint').textContent = kind === 'adjust' ? T('quantity_adjust_hint') : (kind === 'issue' ? T('quantity_issue_hint', { n: item.on_hand }) : '');
        $('cmCost').value = item.unit_cost ?? '';
        $('cmSupplier').innerHTML = supplierOptions(item.supplier_id);
        $('cmInvoice').value = ''; $('cmInvoiceDate').value = ''; $('cmOrder').value = '';
        $('cmPersonLabel').textContent = T(kind === 'return' ? 'returned_by' : 'issued_to');
        $('cmPerson').value = ''; $('cmPersonResults').hidden = true;
        $('cmTicket').value = CFG.ticket ? CFG.ticket.number + ' - ' + CFG.ticket.subject : T('no_ticket');
        if (CFG.ticket && CFG.ticket.person_id && kind !== 'receive') {
            movePerson = CFG.ticket.person_id;
            $('cmPerson').value = CFG.ticket.person_name || '';
        }
        $('cmReasonLabel').textContent = T(kind === 'adjust' ? 'reason_required' : 'note');
        $('cmReason').required = kind === 'adjust';
        $('cmReason').value = '';
        $('cmSubmit').textContent = T(kind);
        showError('cmError', '');
        openModal('csMoveModal');
    }

    // Who it went to: the people the analyst can see (api ?action=people).
    let pTimer = null;
    $('cmPerson') && $('cmPerson').addEventListener('input', () => {
        movePerson = null;
        clearTimeout(pTimer);
        const q = $('cmPerson').value.trim();
        if (q.length < 2) { $('cmPersonResults').hidden = true; return; }
        pTimer = setTimeout(async () => {
            const d = await api('action=people&q=' + encodeURIComponent(q));
            const list = d.success ? d.people : [];
            $('cmPersonResults').innerHTML = list.length
                ? list.map(p => `<button type="button" data-person="${p.id}" data-name="${esc(p.name)}">${esc(p.name)}<span>${esc([p.email, p.department].filter(Boolean).join(' · '))}</span></button>`).join('')
                : '<div class="cs-dim">' + esc(T('no_people')) + '</div>';
            $('cmPersonResults').hidden = false;
        }, 200);
    });
    $('cmPersonResults') && $('cmPersonResults').addEventListener('click', e => {
        const b = e.target.closest('[data-person]');
        if (!b) return;
        movePerson = parseInt(b.dataset.person, 10);
        $('cmPerson').value = b.dataset.name;
        $('cmPersonResults').hidden = true;
    });

    $('csMoveForm') && $('csMoveForm').addEventListener('submit', async e => {
        e.preventDefault();
        if ($('cmPerson').value.trim() && !movePerson && moveKind !== 'receive' && moveKind !== 'adjust') { showError('cmError', T('pick_person')); return; }
        const body = { action: 'move', kind: moveKind, item_id: moveItem.id, quantity: $('cmQty').value, reason: $('cmReason').value };
        if (moveKind === 'receive') Object.assign(body, { unit_cost: $('cmCost').value, supplier_id: $('cmSupplier').value, invoice_ref: $('cmInvoice').value, invoice_date: $('cmInvoiceDate').value, order_ref: $('cmOrder').value });
        if (moveKind === 'issue' || moveKind === 'return') {
            body.recipient_user_id = movePerson || '';
            if (CFG.ticket) { body.ticket_id = CFG.ticket.id; body.source = 'ticket'; }
        }
        const d = await post(body);
        if (!d.success) { showError('cmError', d.error); return; }
        closeModal('csMoveModal');
        toast(T('moved_' + moveKind, { n: d.on_hand }));
        await loadList();
        openItem(moveItem.id);
    });

    let undoId = 0;
    $('csUndoForm') && $('csUndoForm').addEventListener('submit', async e => {
        e.preventDefault();
        const d = await post({ action: 'reverse', movement_id: undoId, reason: $('cuReason').value });
        if (!d.success) { showError('cuError', d.error); return; }
        closeModal('csUndoModal');
        toast(T('undone_done'));
        await loadList();
        if (current) openItem(current.id);
    });

    // Reorder: the requisition form chosen in Assets -> Settings -> Consumables, filled in.
    // The answers ride in this tab's sessionStorage (forms/fill.php reads them once).
    function reorder(item) {
        const map = CFG.reorder.map || {};
        const target = Math.max(item.reorder_point || 0, item.min_level || 0, 1) * 2;
        const suggest = Math.max(1, target - item.on_hand);
        const answers = {};
        const put = (k, v) => { if (map[k] && v != null && v !== '') answers[String(map[k])] = String(v); };
        put('item', item.name); put('part_number', item.part_number); put('supplier', item.supplier_name);
        put('quantity', suggest); put('unit_cost', item.unit_cost != null ? Number(item.unit_cost).toFixed(2) : '');
        put('note', T('reorder_note', { n: item.on_hand, unit: item.unit || '', point: item.reorder_point ?? '-' }));
        try { sessionStorage.setItem('freeitsm.formPrefill.' + CFG.reorder.form_id, JSON.stringify(answers)); } catch (e) { /* the form opens empty */ }
        location.href = '../../forms/fill.php?id=' + encodeURIComponent(CFG.reorder.form_id);
    }

    // ------------------------------------------------------------- wiring
    document.addEventListener('click', e => {
        const open = e.target.closest('[data-open]');
        if (open) { e.preventDefault(); openItem(parseInt(open.dataset.open, 10)); return; }
        const iss = e.target.closest('[data-issue]');
        if (iss) { const it = items.find(x => x.id === parseInt(iss.dataset.issue, 10)); if (it) openMove('issue', it); return; }
        const mv = e.target.closest('[data-move]');
        if (mv && current) { openMove(mv.dataset.move, current); return; }
        if (e.target.closest('#csEdit') && current) { openItemForm(current); return; }
        if (e.target.closest('#csReorder') && current) { reorder(current); return; }
        const un = e.target.closest('[data-undo]');
        if (un && current) {
            undoId = parseInt(un.dataset.undo, 10);
            const m = current.movements.find(x => x.id === undoId);
            $('cuWhat').textContent = T('undo_what', { kind: T('kind_' + m.kind), n: Math.abs(m.quantity), name: current.name });
            $('cuReason').value = '';
            showError('cuError', '');
            openModal('csUndoModal');
            return;
        }
        const tab = e.target.closest('[data-tab]');
        if (tab) {
            document.querySelectorAll('#csPanel [data-tab]').forEach(b => b.setAttribute('aria-selected', b === tab ? 'true' : 'false'));
            document.querySelectorAll('#csPanel [data-pane]').forEach(p => { p.hidden = p.dataset.pane !== tab.dataset.tab; });
        }
    });
    $('csPanelClose') && $('csPanelClose').addEventListener('click', closePanel);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !document.querySelector('.modal.active') && $('csPanel') && !$('csPanel').hidden) closePanel(); });
    $('csNew') && $('csNew').addEventListener('click', () => openItemForm(null));
    ['csType', 'csStatus', 'csInactive'].forEach(id => $(id) && $(id).addEventListener('change', loadList));
    $('csSearch') && $('csSearch').addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(loadList, 250); });

    (async function start() {
        if (!$('csList')) return;
        if (CFG.status) $('csStatus').value = CFG.status;
        await loadMeta();
        await loadList();
        if (CFG.openItem) openItem(CFG.openItem);
    })();
})();
