/**
 * Ticket participants (GitHub #432) - end users copied on a ticket's emails.
 *
 * The Participants row in the ticket header (under the requester): each person, a
 * remove button, and Add. Reply pre-fills them in Cc (participantReplyCc(), used by
 * inbox.js's openReplyModal); Forward does not. Which automatic emails copy them is
 * Tickets -> Settings -> General. The rules live server-side
 * (includes/services/ticket_participants.php): never the requester, nobody who has
 * left, none on a confidential ticket.
 *
 * Talks to api/tickets/participants.php. Uses inbox.js's globals: API_BASE, escapeHtml,
 * linkPerson, showToast, t, currentEmail.
 */
(function () {
    const API = () => (window.API_BASE || '../api/tickets/') + 'participants.php';
    const T = (k, p) => (typeof window.t === 'function' ? window.t('tickets.participants.' + k, p) : k);
    const esc = (s) => (typeof escapeHtml === 'function' ? escapeHtml(s) : String(s ?? ''));
    const norm = (a) => String(a || '').trim().toLowerCase();
    // inbox.js's open ticket (a top-level let, so not on window).
    const cur = () => (typeof currentEmail !== 'undefined' ? currentEmail : null);

    let state = { ticketId: null, list: [], canEdit: false, ready: false, confidential: false };
    window.currentTicketParticipants = [];
    window.currentTicketRequesterAddresses = [];

    async function call(method, body, query) {
        const res = await fetch(API() + (query || ''), method === 'GET' ? {} : {
            method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
        });
        const data = await res.json().catch(() => ({ success: false }));
        if (!data.success) {
            const err = new Error(data.code ? T('err_' + data.code) : (data.error || T('failed')));
            err.code = data.code || '';
            throw err;
        }
        return data;
    }

    /** Loads the open ticket's participants and draws the row. Called by inbox.js when a ticket opens. */
    async function loadTicketParticipants(ticketId) {
        state = { ticketId, list: [], canEdit: false, ready: false, confidential: false };
        window.currentTicketParticipants = [];
        window.currentTicketRequesterAddresses = [];
        try {
            const d = await call('GET', null, '?ticket_id=' + encodeURIComponent(ticketId));
            if (state.ticketId !== ticketId) return;   // another ticket opened meanwhile
            state = { ticketId, list: d.participants || [], canEdit: !!d.can_edit, ready: d.ready !== false, confidential: !!d.confidential };
            window.currentTicketParticipants = state.confidential ? [] : state.list.slice();
            window.currentTicketRequesterAddresses = d.requester_addresses || [];
        } catch (e) { /* the row stays hidden; Reply simply pre-fills nobody */ }
        render();
    }

    function render() {
        const row = document.getElementById('ticketParticipantsRow');
        if (!row) return;
        const show = state.ready && !state.confidential && (state.list.length > 0 || state.canEdit);
        // 'flex', not [hidden] or '': the compact header lays its rows out as display:contents,
        // which would let the label wrap away from the names.
        row.style.display = show ? 'flex' : 'none';
        if (!show) return;
        const chips = state.list.map(p => {
            const name = typeof linkPerson === 'function' ? linkPerson(esc(p.name), p.email || '', p.user_id) : esc(p.name);
            const left = p.is_active ? '' : ' <span class="participant-left">' + esc(T('left')) + '</span>';
            const x = state.canEdit
                ? ' <button type="button" class="participant-remove" data-remove="' + p.user_id + '" title="' + esc(T('remove_title', { name: p.name })) + '" aria-label="' + esc(T('remove_title', { name: p.name })) + '">×</button>'
                : '';
            return '<span class="participant-chip' + (p.is_active ? '' : ' is-left') + '">' + name + left + x + '</span>';
        }).join(' ');
        const add = state.canEdit ? ' <a href="#" class="req-change-link" data-add="1">' + esc(T('add')) + '</a>' : '';
        row.querySelector('.email-meta-value').innerHTML = (chips || '<span class="participant-none">' + esc(T('none')) + '</span>') + add;
    }

    document.addEventListener('click', async (e) => {
        const rm = e.target.closest('#ticketParticipantsRow [data-remove]');
        if (rm) {
            e.preventDefault();
            try {
                await call('POST', { action: 'remove', ticket_id: state.ticketId, user_id: +rm.dataset.remove });
                await loadTicketParticipants(state.ticketId);
            } catch (err) { if (typeof showToast === 'function') showToast(err.message, 'error'); }
            return;
        }
        if (e.target.closest('#ticketParticipantsRow [data-add]')) {
            e.preventDefault();
            openAddParticipant();
        }
    });

    /** The Add dialog: pick a person from People, or type an address. */
    function openAddParticipant() {
        if (!state.ticketId) return;
        const ticketId = state.ticketId;
        const ticketCompany = (cur() && cur().tenant_id) ?? null;
        const wrap = document.createElement('div');
        wrap.className = 'modal active';
        wrap.innerHTML = `<div class="modal-content" style="max-width:520px">
            <div class="modal-header">${esc(T('add_title'))}</div>
            <div class="modal-body">
                <input type="search" class="form-input" id="tpSearch" placeholder="${esc(T('search'))}" autocomplete="off">
                <div id="tpList" style="max-height:220px;overflow:auto;margin-top:8px"></div>
                <div id="tpNote" style="margin-top:10px;color:var(--text-muted)" hidden></div>
                <small style="display:block;margin-top:10px;color:var(--text-muted)">${esc(T('add_hint'))}</small>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-tp="cancel">${esc(typeof t === 'function' ? t('common.cancel') : 'Cancel')}</button>
                <button type="button" class="btn btn-primary" data-tp="save" disabled>${esc(T('add'))}</button>
            </div></div>`;
        document.body.appendChild(wrap);
        const close = () => wrap.remove();
        const search = wrap.querySelector('#tpSearch'), list = wrap.querySelector('#tpList'),
              note = wrap.querySelector('#tpNote'), save = wrap.querySelector('[data-tp="save"]');
        let chosen = null, timer = null, results = [];
        const say = (text) => { note.textContent = text || ''; note.hidden = !text; };
        const pick = (c) => {
            chosen = c;
            save.disabled = !c;
            list.querySelectorAll('.tp-row').forEach(r => r.style.background = c && c.id && +r.dataset.id === c.id ? 'var(--accent-soft)' : '');
            if (!c) { say(''); return; }
            if (c.email && !c.id) { say(T('new_person', { email: c.email })); return; }
            const other = ticketCompany !== null && c.tenant_id !== undefined && c.tenant_id !== null && +c.tenant_id !== +ticketCompany;
            say(other ? T('other_company', { company: c.tenant_name || '' }) : '');
        };
        search.focus();
        search.addEventListener('input', () => {
            clearTimeout(timer);
            pick(null);
            timer = setTimeout(async () => {
                const q = search.value.trim();
                if (q.length < 2) { list.innerHTML = ''; return; }
                const res = await fetch((window.API_BASE || '../api/tickets/') + 'get_users.php?limit=8&search=' + encodeURIComponent(q)).then(r => r.json()).catch(() => null);
                results = (res && (res.users || res.data)) || [];
                list.innerHTML = results.length ? results.map(u => `<div class="tp-row" data-id="${+u.id}" style="padding:6px 8px;cursor:pointer;border-bottom:1px solid var(--border-soft)">
                    <strong>${esc(u.display_name || u.email || '')}</strong> <span style="color:var(--text-muted)">${esc(u.email || '')}</span></div>`).join('')
                    : `<div style="color:var(--text-muted);padding:6px">${esc(T('nobody_found'))}</div>`;
                // A typed address nobody has: it can still be added - the person is created.
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(q) && !results.some(u => norm(u.email) === norm(q))) pick({ email: q });
            }, 200);
        });
        list.addEventListener('click', (e) => {
            const row = e.target.closest('.tp-row');
            if (!row) return;
            const u = results.find(x => +x.id === +row.dataset.id);
            if (u) pick({ id: +u.id, tenant_id: u.tenant_id, tenant_name: u.tenant_name });
        });
        wrap.addEventListener('click', async (e) => {
            const b = e.target.closest('[data-tp]');
            if (!b) return;
            if (b.dataset.tp === 'cancel') { close(); return; }
            if (!chosen) return;
            save.disabled = true;
            try {
                await call('POST', chosen.id ? { action: 'add', ticket_id: ticketId, user_id: chosen.id } : { action: 'add', ticket_id: ticketId, email: chosen.email });
                close();
                await loadTicketParticipants(ticketId);
            } catch (err) {
                say(err.message);
                save.disabled = false;
            }
        });
    }

    /**
     * The Cc a Reply starts with: the active participants' main addresses, once each, and
     * nobody who is already in To under any of their addresses (#308). When the ticket has
     * participants and the requester is not in To (a reply to a participant's own message,
     * GH #306), the requester goes in Cc first so they are never left out. No participants,
     * or a confidential ticket: '' - Reply as it always was.
     *
     * @param {string} toValue  what the To box holds
     * @param {?string} requesterEmail  the ticket requester's main address
     */
    function participantReplyCc(toValue, requesterEmail) {
        const ticket = cur();
        if (state.confidential || (ticket && ticket.sensitivity === 'confidential')) return '';
        const active = (window.currentTicketParticipants || []).filter(p => p.is_active && p.email);
        if (!active.length) return '';
        const inTo = new Set(String(toValue || '').split(/[;,]/).map(s => {
            const m = s.match(/<([^>]+)>/);
            return norm(m ? m[1] : s);
        }).filter(Boolean));
        const present = (addrs) => addrs.some(a => inTo.has(norm(a)));
        const out = [];
        const push = (a) => { const n = norm(a); if (n && !inTo.has(n) && !out.includes(n)) out.push(n); };
        const reqAll = [requesterEmail].concat(window.currentTicketRequesterAddresses || []).filter(Boolean);
        if (requesterEmail && !present(reqAll)) push(requesterEmail);
        active.forEach(p => { if (!present([p.email].concat(p.addresses || []))) push(p.email); });
        return out.join('; ');
    }

    window.loadTicketParticipants = loadTicketParticipants;
    window.participantReplyCc = participantReplyCc;
})();
