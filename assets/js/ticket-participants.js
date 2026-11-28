/**
 * Ticket participants (GitHub #432) - end users copied on a ticket's emails.
 *
 * The Participants row in the ticket header (under the requester): each person, a
 * remove button, and Add. Also the "+ Participant" button beside each Cc'd address
 * in the thread (inbox.js threadRecipientsHtml): shown only for someone who could be
 * added, and only ever on a click - a Cc never adds anyone by itself. Reply pre-fills them in Cc (participantReplyCc(), used by
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
        syncParticipantCcButtons();
        const row = document.getElementById('ticketParticipantsRow');
        if (!row) return;
        const show = state.ready && !state.confidential && (state.list.length > 0 || state.canEdit);
        // 'flex', like every header row (reading-pane.css), so the label never wraps away from the names.
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

    /**
     * Shows the "+ Participant" button beside a Cc'd address in the thread only when an
     * Add could work: the analyst may edit, the ticket is not confidential, and the
     * address is neither the requester's nor a participant's (main or linked). Anything
     * else the server still refuses with its own reason - a mailbox, an analyst, someone
     * who has left. Called after the thread is drawn and after every participants load.
     */
    function syncParticipantCcButtons() {
        const ticket = cur();
        const may = state.ready && state.canEdit && !state.confidential && !(ticket && ticket.sensitivity === 'confidential');
        const taken = new Set([].concat(
            (ticket && ticket.requester_email) ? [ticket.requester_email] : [],
            window.currentTicketRequesterAddresses || [],
            ...state.list.map(p => [p.email].concat(p.addresses || []))
        ).filter(Boolean).map(norm));
        document.querySelectorAll('[data-cc-add]').forEach(b => { b.hidden = !may || taken.has(norm(b.dataset.ccAdd)); });
    }

    /**
     * The note in the Reply box: who was Cc'd on the email being answered and will not
     * get this reply - everyone in that Cc who is not in this reply's To or Cc, not the
     * requester and not a participant. Each with + Participant (when the analyst may add
     * one); adding them also puts them in this reply's Cc. '' or a confidential ticket:
     * no note. Called by inbox.js's openReplyModal, and with '' by Forward and on close.
     *
     * @param {string} ccText  the answered email's cc_recipients
     */
    let replyCcText = '';
    function replyCcNote(ccText) {
        replyCcText = ccText || '';
        const box = document.getElementById('replyCcNote');
        if (!box) return;
        const ticket = cur();
        const inReply = (id) => String((document.getElementById(id) || {}).value || '').split(/[;,]/)
            .map(s => { const m = s.match(/<([^>]+)>/); return norm(m ? m[1] : s); }).filter(Boolean);
        const taken = new Set([].concat(inReply('emailTo'), inReply('emailCc'),
            (ticket && ticket.requester_email) ? [ticket.requester_email] : [],
            window.currentTicketRequesterAddresses || [],
            ...state.list.map(p => [p.email].concat(p.addresses || []))).filter(Boolean).map(norm));
        const left = (String(ccText || '').match(/[A-Z0-9._%+'-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi) || [])
            .map(norm).filter((a, i, all) => all.indexOf(a) === i && !taken.has(a));
        if (!left.length || state.confidential || (ticket && ticket.sensitivity === 'confidential')) {
            box.hidden = true; box.innerHTML = ''; return;
        }
        const may = state.ready && state.canEdit;
        // With buttons, each address is said once, beside its button; without, in the sentence.
        box.innerHTML = may
            ? '<span class="reply-cc-note-text">' + esc(T('reply_cc_note_list')) + '</span>'
              + left.map(a => ' <span class="reply-cc-note-item">' + esc(a)
                + ' <button type="button" class="thread-cc-add" data-cc-add="' + esc(a) + '" data-in-reply="1" title="' + esc(T('add_from_cc_title', { email: a })) + '">'
                + esc(T('add_from_cc')) + '</button></span>').join('')
            : '<span class="reply-cc-note-text">' + esc(T(left.length === 1 ? 'reply_cc_note_one' : 'reply_cc_note_many', { names: left.join(', ') })) + '</span>';
        box.hidden = false;
    }

    document.addEventListener('click', async (e) => {
        const plus = e.target.closest('[data-cc-add]');
        if (plus) {
            e.preventDefault();
            if (!state.ticketId) return;
            plus.disabled = true;
            try {
                await call('POST', { action: 'add', ticket_id: state.ticketId, email: plus.dataset.ccAdd });
                await loadTicketParticipants(state.ticketId);
                if (plus.dataset.inReply) {
                    // From the Reply box: they get this reply too, and come off the note.
                    const cc = document.getElementById('emailCc');
                    if (cc) cc.value = (cc.value.trim() ? cc.value.trim().replace(/[;,]\s*$/, '') + '; ' : '') + plus.dataset.ccAdd;
                    replyCcNote(replyCcText);   // drawn again: they are in Cc now, so off the note
                }
                if (typeof showToast === 'function') showToast(T('added_from_cc', { email: plus.dataset.ccAdd }), 'success');
            } catch (err) {
                plus.disabled = false;
                if (typeof showToast === 'function') showToast(err.message, 'error');
            }
            return;
        }
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
        let chosen = null, timer = null, results = [], asked = 0;
        const say = (text) => { note.textContent = text || ''; note.hidden = !text; };
        /**
         * Ask the server who an Add would put on the ticket (TicketParticipants::preview):
         * the person behind a typed address - main or linked - or a new one, and whether
         * they are in another company. The page never guesses either.
         */
        const pick = async (c) => {
            const mine = ++asked;
            chosen = null;
            save.disabled = true;
            list.querySelectorAll('.tp-row').forEach(r => r.style.background = c && c.id && +r.dataset.id === c.id ? 'var(--accent-soft)' : '');
            if (!c) { say(''); return; }
            let pv;
            try {
                pv = await call('GET', null, '?ticket_id=' + encodeURIComponent(ticketId) + '&preview=1'
                    + (c.id ? '&user_id=' + encodeURIComponent(c.id) : '&email=' + encodeURIComponent(c.email)));
            } catch (err) {
                if (mine === asked) say(err.message);
                return;
            }
            if (mine !== asked) return;   // typed on meanwhile
            chosen = pv.user_id ? { id: pv.user_id } : { email: pv.email };
            save.disabled = false;
            const lines = [];
            if (pv.new) lines.push(T('new_person', { email: pv.email }));
            else if (!c.id) lines.push(T('existing_person', { name: pv.name || pv.email || '' }));
            if (pv.other_company) lines.push(T('other_company', { company: pv.company_name || '' }));
            say(lines.join(' '));
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
                // A typed address: the server says whose it is (main or linked) or that it is new.
                if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(q)) pick({ email: q });
            }, 200);
        });
        list.addEventListener('click', (e) => {
            const row = e.target.closest('.tp-row');
            if (!row) return;
            const u = results.find(x => +x.id === +row.dataset.id);
            if (u) pick({ id: +u.id });
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
    window.syncParticipantCcButtons = syncParticipantCcButtons;
    window.replyCcNote = replyCcNote;
})();
