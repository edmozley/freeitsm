/**
 * Tickets -> Settings -> Ticket types: "Type for new tickets" (GH #230).
 *
 * The default type, the rules checked before it (first match wins), and a "Try it"
 * box that asks the server which type a ticket like this would get - the same
 * function every creation path calls, so the answer is the real one. Everything is
 * chosen from lists; only the subject words and requester domains are typed.
 *
 * In a client company's context the rules shown are that company's own, and the
 * shared rules that apply after them are listed underneath, read-only.
 *
 * @wiki Ticket-Types-For-New-Tickets
 */
(function () {
    'use strict';
    const API = '../../api/tickets/ticket_type_rules.php';
    const T = (k, p) => window.t('tickets.type_rules.' + k, p);
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const LIST_CRITERIA = ['channels', 'mailboxes', 'origins', 'departments', 'companies'];
    let D = null, editing = null;

    async function api(body) {
        const opts = body ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) } : {};
        const r = await fetch(API, Object.assign({ credentials: 'same-origin' }, opts));
        const d = await r.json();
        if (!d.success) throw new Error(d.error || T('error'));
        return d;
    }

    /** The options a list criterion offers: [{id, name}]. Channels are fixed keys with translated names. */
    function optionsFor(k) {
        if (k === 'channels') return D.options.channels.map(c => ({ id: c, name: T('channel_' + c) }));
        return D.options[k] || [];
    }

    /** "Arrived by Email, Web chat; Mailbox requests@" - one rule's criteria in words. */
    function summary(c) {
        const parts = [];
        LIST_CRITERIA.forEach(k => {
            if (!c[k] || !c[k].length) return;
            const names = optionsFor(k);
            const label = c[k].map(v => (names.find(o => String(o.id) === String(v)) || {}).name || ('#' + v)).join(', ');
            parts.push('<strong>' + esc(T('crit_' + k)) + '</strong> ' + esc(label));
        });
        if (c.subject && c.subject.length) parts.push('<strong>' + esc(T('crit_subject')) + '</strong> ' + esc(c.subject.map(s => '"' + s + '"').join(', ')));
        if (c.domains && c.domains.length) parts.push('<strong>' + esc(T('crit_domains')) + '</strong> ' + esc(c.domains.join(', ')));
        return parts.join('<span class="ttr-and"> ' + esc(T('and')) + ' </span>');
    }
    const typeName = id => D.type_names[id] || ('#' + id);

    async function load() {
        const body = document.getElementById('ttrBody');
        try { D = await api(); } catch (e) { body.innerHTML = '<p class="ttr-hint">' + esc(e.message) + '</p>'; return; }
        render();
    }

    function render() {
        const body = document.getElementById('ttrBody');
        const shared = D.scope.shared;
        const typeOpts = sel => D.options.types.map(tp => '<option value="' + tp.id + '"' + (String(sel) === String(tp.id) ? ' selected' : '') + '>' + esc(tp.name) + '</option>').join('');

        // The default. A company can follow the shared one, choose its own, or have none.
        let defSel, defHint = '';
        if (shared) {
            defSel = '<option value="">' + esc(T('default_none')) + '</option>' + typeOpts(D.default.install || '');
        } else {
            const inherited = D.default.install ? typeName(D.default.install) : T('default_none');
            const cur = D.default.company === null ? 'inherit' : (D.default.company === '0' || D.default.company === '' ? '' : D.default.company);
            defSel = '<option value="inherit"' + (cur === 'inherit' ? ' selected' : '') + '>' + esc(T('default_inherit', { type: inherited })) + '</option>'
                   + '<option value=""' + (cur === '' ? ' selected' : '') + '>' + esc(T('default_none')) + '</option>' + typeOpts(cur);
            defHint = '<p class="ttr-hint">' + esc(T('company_hint', { company: D.scope.company })) + '</p>';
        }

        const row = (r, i, list, readOnly) => '<tr data-id="' + r.id + '"' + (r.is_active ? '' : ' class="ttr-off"') + '>'
            + '<td class="ttr-num">' + (i + 1) + '</td>'
            + '<td><strong>' + esc(r.name) + '</strong>' + (r.is_active ? '' : ' <span class="status-badge status-inactive">' + esc(T('inactive')) + '</span>') + '<div class="ttr-when">' + summary(r.conditions) + '</div></td>'
            + '<td>' + esc(typeName(r.ticket_type_id)) + '</td>'
            + '<td class="ttr-actions">' + (readOnly ? '' :
                '<button class="action-btn" data-ttr-up title="' + esc(T('move_up')) + '" aria-label="' + esc(T('move_up')) + '"' + (i === 0 ? ' disabled' : '') + '>&uarr;</button>'
              + '<button class="action-btn" data-ttr-down title="' + esc(T('move_down')) + '" aria-label="' + esc(T('move_down')) + '"' + (i === list.length - 1 ? ' disabled' : '') + '>&darr;</button>'
              + '<button class="action-btn" data-ttr-edit>' + esc(window.t('common.edit')) + '</button>'
              + '<button class="action-btn delete" data-ttr-del>' + esc(window.t('common.delete')) + '</button>') + '</td></tr>';
        const table = (list, readOnly) => '<table class="ttr-table"><thead><tr><th>#</th><th>' + esc(T('col_rule')) + '</th><th>' + esc(T('col_type')) + '</th><th></th></tr></thead><tbody>'
            + list.map((r, i) => row(r, i, list, readOnly)).join('') + '</tbody></table>';

        let html = defHint
            + '<div class="ttr-default"><label for="ttrDefault">' + esc(T('default_label')) + '</label><select id="ttrDefault" class="form-control">' + defSel + '</select>'
            + '<button class="btn btn-primary" type="button" id="ttrDefaultSave">' + esc(window.t('common.save')) + '</button></div>'
            + '<p class="ttr-hint">' + esc(T('default_hint')) + '</p>'
            + '<div class="ttr-rules-head"><h3>' + esc(T('rules_title')) + '</h3><button class="add-btn" type="button" id="ttrAdd">' + esc(window.t('common.add')) + '</button></div>'
            + '<p class="ttr-hint">' + esc(T('rules_hint')) + '</p>'
            + (D.rules.length ? table(D.rules, false) : '<p class="ttr-empty">' + esc(T('rules_none')) + '</p>');
        if (!shared && D.shared.length) {
            html += '<h3 class="ttr-sub">' + esc(T('shared_title')) + '</h3><p class="ttr-hint">' + esc(T('shared_hint')) + '</p>' + table(D.shared, true);
        }
        html += tryBox();
        body.innerHTML = html;
    }

    /** "Try it": the facts of an imaginary new ticket, and the type it would get. */
    function tryBox() {
        const sel = (id, k, blank) => '<select id="' + id + '" class="form-control"><option value="">' + esc(blank) + '</option>'
            + optionsFor(k).map(o => '<option value="' + esc(o.id) + '">' + esc(o.name) + '</option>').join('') + '</select>';
        const f = (label, ctl) => '<label class="ttr-try-f"><span>' + esc(label) + '</span>' + ctl + '</label>';
        return '<details class="ttr-try"><summary>' + esc(T('try_title')) + '</summary><p class="ttr-hint">' + esc(T('try_hint')) + '</p><div class="ttr-try-grid">'
            + f(T('crit_channels'), sel('ttrTryChannel', 'channels', T('try_pick')))
            + f(T('crit_mailboxes'), sel('ttrTryMailbox', 'mailboxes', T('try_none')))
            + f(T('crit_origins'), sel('ttrTryOrigin', 'origins', T('try_none')))
            + f(T('crit_departments'), sel('ttrTryDept', 'departments', T('try_none')))
            + (D.options.companies.length ? f(T('crit_companies'), sel('ttrTryCompany', 'companies', T('try_default_company'))) : '')
            + f(T('try_subject'), '<input type="text" id="ttrTrySubject" class="form-control">')
            + f(T('try_email'), '<input type="email" id="ttrTryEmail" class="form-control" placeholder="name@example.com">')
            + '</div><button class="btn btn-secondary" type="button" id="ttrTryGo">' + esc(T('try_go')) + '</button><p id="ttrTryResult" class="ttr-try-result" role="status"></p></details>';
    }

    // ---------------------------------------------------------------- the editor
    function openEditor(rule) {
        editing = rule;
        document.getElementById('ttrModalTitle').textContent = rule ? T('edit_title') : T('add_title');
        document.getElementById('ttrName').value = rule ? rule.name : '';
        document.getElementById('ttrType').innerHTML = '<option value="">' + esc(T('pick_type')) + '</option>'
            + D.options.types.map(tp => '<option value="' + tp.id + '">' + esc(tp.name) + '</option>').join('');
        document.getElementById('ttrType').value = rule ? String(rule.ticket_type_id) : '';
        document.getElementById('ttrActive').checked = rule ? rule.is_active : true;
        const c = rule ? rule.conditions : {};
        let html = '';
        LIST_CRITERIA.forEach(k => {
            const opts = optionsFor(k);
            if (!opts.length) return;   // no mailboxes, no companies... nothing to offer
            const chosen = (c[k] || []).map(String);
            html += '<fieldset class="ttr-crit"><legend>' + esc(T('crit_' + k)) + '</legend><div class="ttr-checks">'
                + opts.map(o => '<label><input type="checkbox" data-crit="' + k + '" value="' + esc(o.id) + '"' + (chosen.includes(String(o.id)) ? ' checked' : '') + '> ' + esc(o.name) + '</label>').join('')
                + '</div></fieldset>';
        });
        ['subject', 'domains'].forEach(k => {
            html += '<fieldset class="ttr-crit"><legend><label for="ttrCrit_' + k + '">' + esc(T('crit_' + k)) + '</label></legend>'
                + '<textarea id="ttrCrit_' + k + '" class="form-control" rows="2" placeholder="' + esc(T('ph_' + k)) + '">' + esc((c[k] || []).join('\n')) + '</textarea>'
                + '<small>' + esc(T('help_' + k)) + '</small></fieldset>';
        });
        document.getElementById('ttrCriteria').innerHTML = html;
        document.getElementById('ttrModal').classList.add('active');
        document.getElementById('ttrName').focus();
    }
    const closeEditor = () => document.getElementById('ttrModal').classList.remove('active');

    async function saveRule() {
        const conditions = {};
        LIST_CRITERIA.forEach(k => {
            conditions[k] = [...document.querySelectorAll('#ttrCriteria input[data-crit="' + k + '"]:checked')].map(i => k === 'channels' ? i.value : +i.value);
        });
        conditions.subject = (document.getElementById('ttrCrit_subject').value || '');
        conditions.domains = (document.getElementById('ttrCrit_domains').value || '');
        try {
            await api({ action: 'save', id: editing ? editing.id : 0, name: document.getElementById('ttrName').value,
                        ticket_type_id: +document.getElementById('ttrType').value || 0, conditions, is_active: document.getElementById('ttrActive').checked });
            closeEditor();
            showToast(T('saved'), 'success');
            load();
        } catch (e) { showToast(e.message, 'error'); }
    }

    // ---------------------------------------------------------------- events
    document.addEventListener('click', async e => {
        if (!document.getElementById('ttrSection')) return;
        const tr = e.target.closest('#ttrBody tr[data-id]');
        const rule = tr && D ? D.rules.find(r => r.id === +tr.dataset.id) : null;
        try {
            if (e.target.closest('#ttrAdd')) openEditor(null);
            else if (e.target.closest('[data-ttr-edit]') && rule) openEditor(rule);
            else if (e.target.closest('[data-ttr-up]') && rule) { await api({ action: 'move', id: rule.id, dir: -1 }); load(); }
            else if (e.target.closest('[data-ttr-down]') && rule) { await api({ action: 'move', id: rule.id, dir: 1 }); load(); }
            else if (e.target.closest('[data-ttr-del]') && rule) {
                const ok = await showConfirm({ title: T('delete_title'), message: T('delete_msg', { name: rule.name }), okLabel: window.t('common.delete'), okClass: 'danger' });
                if (ok) { await api({ action: 'delete', id: rule.id }); load(); }
            }
            else if (e.target.closest('#ttrDefaultSave')) { await api({ action: 'set_default', value: document.getElementById('ttrDefault').value }); showToast(T('saved'), 'success'); load(); }
            else if (e.target.closest('#ttrSave')) saveRule();
            else if (e.target.closest('[data-ttr-close]')) closeEditor();
            else if (e.target.closest('#ttrTryGo')) {
                const v = id => (document.getElementById(id) || {}).value || '';
                const d = await api({ action: 'test', facts: { channel: v('ttrTryChannel'), mailbox_id: v('ttrTryMailbox'), origin_id: v('ttrTryOrigin'),
                    department_id: v('ttrTryDept'), company_id: v('ttrTryCompany'), subject: v('ttrTrySubject'), requester_email: v('ttrTryEmail') } });
                const p = d.pick;
                document.getElementById('ttrTryResult').innerHTML = !p ? esc(T('try_result_none'))
                    : p.rule_name ? T('try_result_rule', { type: '<strong>' + esc(p.type_name) + '</strong>', rule: '<strong>' + esc(p.rule_name) + '</strong>' })
                    : T('try_result_default', { type: '<strong>' + esc(p.type_name) + '</strong>' });
            }
        } catch (err) { showToast(err.message, 'error'); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && document.getElementById('ttrModal')?.classList.contains('active')) closeEditor(); });
    document.addEventListener('DOMContentLoaded', () => { if (document.getElementById('ttrSection')) load(); });
})();
