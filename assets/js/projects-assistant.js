/**
 * Projects - the AI project assistant panel (3.3.0): "Ask AI" on a project.
 *
 * The same slide-in panel as Knowledge's Ask AI (the .ai-chat-* styles in
 * inbox.css / mobile.css), talking to api/projects/assistant_chat.php. The rules,
 * the memory and what the model is told are in includes/projects/assistant_chat.php.
 *
 *   PrjAssistant.open()    the header's Ask AI button
 *   PrjAssistant.bind(ctx) from projects-view.js after each redraw - ctx.refresh redraws
 *                          the project once proposals are applied
 *
 * Opening the panel loads the conversation, then asks the server to 'open' it:
 * a greeting for an empty conversation, a "since we last spoke" after a while,
 * or nothing. An assistant message with proposals gets a card: a box per change
 * (all ticked), Apply and Dismiss; each line then shows applied / dismissed / why it failed.
 */
(function () {
    'use strict';
    const P = window.Prj;
    const T = (k, p) => P.T('assistant.' + k, p);
    const esc = P.esc;
    let ctx = null, state = null, busy = false, opened = false;

    const $ = id => document.getElementById(id);
    const md = s => (window.PrjReports && window.PrjReports.md) ? window.PrjReports.md(s) : esc(s).replace(/\n/g, '<br>');
    const call = body => P.api('assistant_chat.php', Object.assign({ project_id: ctx.projectId }, body));

    function aiError(r) {
        if (!r || !r.ai_error) return false;
        note(r.ai_error === 'not_configured' ? T('not_configured') : T('unreachable', { detail: r.detail || '' }), true);
        return true;
    }
    function note(text, bad) {
        const box = $('paMessages');
        const d = document.createElement('div');
        d.className = bad ? 'ai-chat-error' : 'prj-pa-note';
        d.textContent = text;
        box.appendChild(d);
        box.scrollTop = box.scrollHeight;
    }

    function chips() {
        const m = state && state.maturity;
        if (!m || !state.ai_ready) return [];
        if (m.stage === 'blank') return ['chip_setup', 'chip_explain'];
        if (m.stage === 'early') return ['chip_next', 'chip_plan', 'chip_risks'];
        if (m.stage === 'finished') return ['chip_close', 'chip_lessons'];
        return ['chip_attention', 'chip_overdue', 'chip_risks', 'chip_week'];
    }

    function proposalCard(msg) {
        const props = msg.proposals || [];
        const pending = props.some(p => p.status === 'pending');
        return '<div class="prj-pa-card" data-pa-msg="' + msg.id + '"><div class="prj-pa-card-h">' + P.icon('sparkles', 14) + ' ' + esc(T('card_title', { count: props.length })) + '</div>'
            + '<ul>' + props.map((p, i) => '<li class="pa-' + esc(p.status) + '"><label>'
                + (p.status === 'pending' ? '<input type="checkbox" data-pa-item="' + i + '" checked>' : '<span class="prj-pa-state">' + esc(T('st_' + p.status)) + '</span>')
                + '<span>' + esc(p.summary) + (p.error ? '<small class="prj-pa-err">' + esc(p.error) + '</small>' : '') + '</span></label></li>').join('') + '</ul>'
            + (pending && state.can_change ? '<div class="prj-pa-card-a"><button type="button" class="btn btn-secondary" data-pa-dismiss>' + esc(T('dismiss')) + '</button>'
                + '<button type="button" class="btn btn-primary prj-btn" data-pa-apply>' + esc(T('apply')) + '</button></div>' : '')
            + '</div>';
    }

    function draw() {
        const box = $('paMessages');
        if (!state) { box.innerHTML = ''; return; }
        if (!state.ready) { box.innerHTML = '<div class="ai-chat-welcome"><p>' + esc(T('not_ready')) + '</p></div>'; return; }
        if (!state.ai_ready) {
            box.innerHTML = '<div class="ai-chat-welcome"><p>' + esc(T('not_configured')) + '</p>'
                + (state.can_set_up ? '<p><a href="' + esc(window.PRJ_BASE + 'projects/settings/?tab=ai') + '">' + esc(T('set_up')) + '</a></p>' : '') + '</div>';
            $('paInput').disabled = true;
            return;
        }
        $('paInput').disabled = false;
        const msgs = state.messages || [];
        let html = msgs.length ? '' : '<div class="ai-chat-welcome"><p>' + esc(T('welcome')) + '</p></div>';
        let day = '';
        msgs.forEach(m => {
            const d = String(m.created_at || '').slice(0, 10);
            if (d && d !== day) { day = d; html += '<div class="prj-pa-day">' + esc(P.fmtDate(d)) + '</div>'; }
            html += '<div class="ai-chat-message ' + (m.role === 'user' ? 'user' : 'assistant') + '">'
                + '<div class="ai-chat-bubble">' + (m.role === 'user' ? esc(m.content) : '<div class="prj-md">' + md(m.content) + '</div>') + '</div>'
                + (m.role === 'user' && state.shared && m.analyst_name ? '<div class="ai-chat-meta">' + esc(m.analyst_name) + '</div>' : '')
                + (m.looked_at && m.looked_at.length ? '<div class="ai-chat-meta">' + esc(T('looked_at', { what: m.looked_at.map(x => T('tool_' + x)).join(', ') })) + '</div>' : '')
                + '</div>';
            if (m.proposals && m.proposals.length) html += proposalCard(m);
        });
        if (busy) html += '<div class="ai-chat-thinking"><span></span><span></span><span></span> ' + esc(T('thinking')) + '</div>';
        box.innerHTML = html;
        $('paChips').innerHTML = busy ? '' : chips().map(k => '<button type="button" class="prj-pa-chip" data-pa-chip="' + k + '">' + esc(T(k)) + '</button>').join('');
        $('paMemory').hidden = !state.remembers;
        $('paShared').hidden = !state.shared;
        box.scrollTop = box.scrollHeight;
    }

    async function load() {
        try { state = await P.api('assistant_chat.php?project_id=' + ctx.projectId); } catch (e) { state = null; P.toast(e.message, 'error'); }
        draw();
    }

    async function openTurn() {
        if (!state || !state.ready || !state.ai_ready) return;
        busy = true; draw();
        try {
            const r = await call({ action: 'open' });
            if (!aiError(r)) { state.messages = r.messages; state.maturity = r.maturity; }
        } catch (e) { note(e.message, true); }
        busy = false; draw();
    }

    async function send(text) {
        text = String(text || '').trim();
        if (!text || busy) return;
        $('paInput').value = '';
        state.messages = (state.messages || []).concat([{ id: 0, role: 'user', content: text, created_at: new Date().toISOString().slice(0, 19).replace('T', ' ') }]);
        busy = true; draw();
        try {
            const r = await call({ action: 'send', text: text });
            if (!aiError(r)) { state.messages = r.messages; state.maturity = r.maturity; }
        } catch (e) { note(e.message, true); }
        busy = false; draw();
        $('paInput').focus();
    }

    async function act(card, dismiss) {
        const id = Number(card.dataset.paMsg);
        const items = [...card.querySelectorAll('[data-pa-item]:checked')].map(c => Number(c.dataset.paItem));
        const all = [...card.querySelectorAll('[data-pa-item]')].map(c => Number(c.dataset.paItem));
        if (!items.length && !dismiss) { P.toast(T('tick_one'), 'error'); return; }
        card.querySelectorAll('button').forEach(b => { b.disabled = true; });
        try {
            const r = await call({ action: dismiss ? 'dismiss' : 'apply', message_id: id, items: dismiss ? all : items });
            const m = state.messages.find(x => x.id === id);
            if (m) m.proposals = r.proposals;
            state.maturity = r.maturity;
            if (!dismiss) {
                const ok = r.proposals.filter((p, i) => items.includes(i) && p.status === 'applied').length;
                const bad = r.proposals.filter((p, i) => items.includes(i) && p.status === 'failed').length;
                P.toast(bad ? T('applied_some', { ok: ok, bad: bad }) : T('applied', { count: ok }), bad ? 'error' : undefined);
                if (ok && ctx.refresh) await ctx.refresh();
            }
        } catch (e) { P.toast(e.message, 'error'); }
        draw();
    }

    async function clear() {
        const ok = await window.showConfirm({ title: T('clear_title'), message: T(state.shared ? 'clear_body_shared' : 'clear_body'), okLabel: T('clear'), okClass: 'danger' });
        if (!ok) return;
        try { await call({ action: 'clear' }); state.messages = []; state.remembers = false; draw(); openTurn(); } catch (e) { P.toast(e.message, 'error'); }
    }

    async function open() {
        if (!ctx) return;
        $('paOverlay').classList.add('active');
        $('paPanel').classList.add('active');
        if (!opened) { opened = true; await load(); await openTurn(); }
        else draw();
        setTimeout(() => $('paInput').focus(), 50);
    }
    function close() {
        $('paOverlay').classList.remove('active');
        $('paPanel').classList.remove('active');
    }

    function wire() {
        $('paClose').addEventListener('click', close);
        $('paOverlay').addEventListener('click', close);
        $('paSend').addEventListener('click', () => send($('paInput').value));
        $('paInput').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send($('paInput').value); } });
        $('paClear').addEventListener('click', clear);
        $('paPanel').addEventListener('click', e => {
            const c = e.target.closest('[data-pa-chip]');
            if (c) { send(T(c.dataset.paChip + '_ask')); return; }
            const a = e.target.closest('[data-pa-apply]');
            if (a) { act(a.closest('[data-pa-msg]'), false); return; }
            const d = e.target.closest('[data-pa-dismiss]');
            if (d) act(d.closest('[data-pa-msg]'), true);
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && $('paPanel').classList.contains('active')) close(); });
    }

    document.addEventListener('DOMContentLoaded', () => { if ($('paPanel')) wire(); });

    window.PrjAssistant = {
        open: open,
        /** projects-view.js, after every redraw: the project id and how to redraw it. */
        bind(c) { const first = !ctx; ctx = c; if (first && /[?&]ask=1\b/.test(location.search)) open(); },
    };
})();
