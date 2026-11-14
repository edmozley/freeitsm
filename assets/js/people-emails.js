/**
 * People -> a person: their email addresses and history (GH #308).
 *
 * Draws api/people/emails.php into #pplEmails and #pplHistory: the main address, the linked
 * ones (mail from them is this person's), Link and Remove for an analyst who may change people,
 * and - when the address being linked is another person's MAIN address - the merge that would
 * fix it: their tickets move here, their address becomes one of this person's, and they are
 * removed (or switched off when something else still points at them).
 *
 * @wiki Linked-Email-Addresses-Developer-Guide
 */
(function () {
    'use strict';
    const box = document.getElementById('pplEmails');
    if (!box) return;
    const userId = +box.dataset.userId;
    const API = box.dataset.api;
    const T = (k, p) => window.t ? window.t('people.emails.' + k, p) : k;
    const esc = s => { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
    const post = body => fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(Object.assign({ user_id: userId }, body)) }).then(r => r.json());
    const toast = (m, k) => window.showToast ? window.showToast(m, k || 'success') : alert(m);

    async function load() {
        let d;
        try { d = await (await fetch(API + '?user_id=' + userId, { credentials: 'same-origin' })).json(); } catch (e) { return; }
        if (!d.success || !d.ready) { box.closest('section').hidden = true; return; }
        const rows = (d.main ? '<div class="ppl-addr"><span class="ppl-addr-e">' + esc(d.main) + '</span><span class="ppl-dim">' + esc(T('main')) + '</span></div>' : '')
            + d.linked.map(a => '<div class="ppl-addr"><span class="ppl-addr-e">' + esc(a.email) + '</span>'
                + '<span class="ppl-dim" title="' + esc(a.created_datetime) + '">' + esc(T('via_' + a.added_via, { who: a.added_by || '' })) + '</span>'
                + (d.can_edit ? '<button type="button" class="ppl-btn ppl-btn-sm" data-unlink="' + esc(a.email) + '">' + esc(T('remove')) + '</button>' : '') + '</div>').join('');
        box.innerHTML = rows + (d.linked.length ? '' : '<div class="ppl-dim" style="margin:4px 0">' + esc(T('none')) + '</div>')
            + (d.can_edit ? '<div class="ppl-addr-add"><input type="email" maxlength="255" placeholder="' + esc(T('placeholder')) + '" autocomplete="off"><button type="button" class="ppl-btn">' + esc(T('link')) + '</button></div>'
                + '<div class="ppl-dim" style="font-size:12px">' + esc(T('hint')) + '</div>' : '');
        const hist = document.getElementById('pplHistory');
        if (hist) {
            hist.innerHTML = d.history.length
                ? d.history.map(h => '<div class="ppl-hist"><span class="ppl-dim">' + esc(window.fmtDateTime ? window.fmtDateTime(h.created_datetime) : h.created_datetime) + '</span> '
                    + esc((h.analyst_name || T('system')) + ' - ' + T('h_' + h.field_name, { old: h.old_value || '', new: h.new_value || '' })) + '</div>').join('')
                : '<div class="ppl-dim">' + esc(T('history_none')) + '</div>';
        }
    }

    async function link(email, input) {
        const r = await post({ action: 'link', email });
        if (r.success) { input.value = ''; toast(T('linked', { email })); return load(); }
        if (r.needs_merge) return offerMerge(r.needs_merge);
        toast(r.error || T('failed'), 'error');
    }

    /** The address is another person's main address: say what merging them would do, and do it on Yes. */
    async function offerMerge(p) {
        if (!p.can) { toast(T('merge_no_' + p.reason, { name: p.from.name }), 'error'); return; }
        const msg = T('merge_body', { name: p.from.name, email: p.from.email || '', n: p.tickets, into: p.to.name })
                  + ' ' + (p.delete ? T('merge_delete') : T('merge_keep'));
        const ok = window.showConfirm ? await window.showConfirm({ title: T('merge_title', { name: p.from.name }), message: msg, okLabel: T('merge_ok') }) : confirm(msg);
        if (!ok) return;
        const r = await post({ action: 'merge', from_user_id: p.from.id });
        if (!r.success) { toast(r.error || T('failed'), 'error'); return; }
        toast(T('merged_' + r.outcome, { n: r.moved }));
        load();
    }

    box.addEventListener('click', async e => {
        const un = e.target.closest('[data-unlink]');
        if (un) {
            const r = await post({ action: 'unlink', email: un.dataset.unlink });
            r.success ? (toast(T('removed')), load()) : toast(r.error || T('failed'), 'error');
            return;
        }
        if (e.target.closest('.ppl-addr-add button')) {
            const input = box.querySelector('.ppl-addr-add input');
            if (input.value.trim()) link(input.value.trim(), input);
        }
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Enter' && e.target.matches('.ppl-addr-add input') && e.target.value.trim()) { e.preventDefault(); link(e.target.value.trim(), e.target); }
    });
    load();
})();
