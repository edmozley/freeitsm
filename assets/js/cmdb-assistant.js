/**
 * CMDB - the AI assistant panel (GH #313).
 *
 * The same slide-in panel as the Projects assistant (the .ai-chat-* styles in
 * inbox.css), on every CMDB page through cmdb/includes/header.php, talking to
 * api/cmdb/assistant.php. The rules, the memory and what the model is told are in
 * includes/cmdb/assistant.php.
 *
 * Two views:
 *   Conversation  this analyst's private conversation about the active company.
 *                 Each fact the assistant noted in a turn is a "Noted: ..." chip with
 *                 Undo; the chips under the messages suggest a next step for the
 *                 stage (interview / design / refine), and Continue right after an
 *                 Apply (an Apply starts no turn of its own).
 *   Learned       what it knows about the company - shared by everyone who works on
 *                 it - grouped by topic: Edit in place, Delete, and "Forget everything
 *                 about this company". Works without AI set up (the Classes tab links here).
 *
 * Proposals (GH #313 slices 6 and 7): a card under the message; a suggested schema or
 * set of configuration items has Review - its preview (cmdb-schema-preview.js or
 * cmdb-object-preview.js, loaded on first use) re-resolved against the live CMDB -
 * whose Apply sends the ticked lines; and Dismiss.
 *
 *   #cmdbAssistBtn              the header's Assistant button (only when AI is set up)
 *   [data-ca-open="learned"]    any element that opens the Learned view
 *
 * @wiki CMDB-Developer-Guide
 */
(function () {
    'use strict';
    /** Translates a key under cmdb.assistant.*. */
    const T = (k, p) => (window.t ? window.t('cmdb.assistant.' + k, p) : k);
    /** Escapes text for HTML, quotes included (safe in attributes too). */
    const esc = s => String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    /** Shorthand for document.getElementById. */
    const $ = id => document.getElementById(id);
    let panel = null, state = null, busy = false, loaded = false, opened = false, view = 'chat', editing = 0;
    /** Set by an Apply in a preview, cleared by the next message: the Continue chip shows meanwhile. */
    let carryOn = false;

    /** The assistant's Markdown, escaped first: "## " headings, "- " bullets, **bold**. */
    function md(text) {
        const out = []; let list = false;
        String(text || '').split(/\r?\n/).forEach(line => {
            const bold = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            const li = line.match(/^\s*[-*]\s+(.*)$/);
            if (li) { if (!list) { out.push('<ul>'); list = true; } out.push('<li>' + bold(li[1]) + '</li>'); return; }
            if (list) { out.push('</ul>'); list = false; }
            const h = line.match(/^#{1,4}\s+(.*)$/);
            if (h) out.push('<h4>' + bold(h[1]) + '</h4>');
            else if (line.trim() !== '') out.push('<p>' + bold(line) + '</p>');
        });
        if (list) out.push('</ul>');
        return out.join('');
    }

    /** GET the state (or, with query, another read), or POST an action; resolves to the JSON (throws on a refusal). */
    async function api(body, query) {
        const opts = { credentials: 'same-origin' };
        if (body) Object.assign(opts, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
        const r = await fetch(panel.dataset.api + (query ? '?' + new URLSearchParams(query).toString() : ''), opts);
        let d = null;
        try { d = await r.json(); } catch (e) { d = null; }
        if (!d || !d.success) throw new Error((d && d.error) || T('err_general'));
        return d;
    }
    /** Takes the conversation, facts and coverage from an answer. */
    function take(d) {
        ['messages', 'facts', 'coverage', 'remembers'].forEach(k => { if (k in d) state[k] = d[k]; });
    }

    /** A short message at the bottom of the conversation; bad = an error. */
    function note(text, bad) {
        const box = $('caMessages');
        const d = document.createElement('div');
        d.className = bad ? 'ai-chat-error' : 'ca-note';
        d.textContent = text;
        box.appendChild(d);
        box.scrollTop = box.scrollHeight;
    }
    /** A brief status line at the top of the Learned view. */
    function flash(text, bad) {
        const el = document.querySelector('#caLearnedView .ca-flash');
        if (!el) return;
        el.textContent = text;
        el.className = 'ca-flash' + (bad ? ' bad' : '');
        el.hidden = false;
        clearTimeout(flash.t);
        flash.t = setTimeout(() => { el.hidden = true; }, 3000);
    }
    /** Shows the not-configured or unreachable note when an answer carries an AI error; true when it did. */
    function aiError(d) {
        if (!d || !d.ai_error) return false;
        note(d.ai_error === 'not_configured' ? T('not_configured') : T('unreachable', { detail: d.detail || '' }), true);
        return true;
    }

    /**
     * Has something just been applied that the assistant has not had a turn since? An Apply
     * starts no turn, so "I'll propose the CIs once you've applied it" would never happen
     * without the Continue chip. True after an Apply here, or when the last message is the
     * assistant's with an applied proposal (the panel reopened before carrying on).
     */
    function justApplied() {
        if (carryOn) return true;
        const msgs = state.messages || [];
        const m = msgs[msgs.length - 1];
        return !!(m && m.role === 'assistant' && (m.proposals || []).some(p => p.status === 'applied'));
    }
    /** The suggestion chips for the stage - Continue first right after an Apply. */
    function chips() {
        if (!state || !state.ready || !state.ai_ready || busy) return [];
        const c = state.coverage || {};
        const facts = (state.facts || []).length;
        const stage = c.stage === 'interview' ? (facts ? ['chip_interview', 'chip_learned'] : ['chip_interview']) : ['chip_learned', 'chip_review'];
        return justApplied() ? ['chip_continue'].concat(stage) : stage;
    }

    /** The Noted chips under an assistant message. */
    function learnedChips(m) {
        return '<div class="ca-noted">' + m.learned.map(l => '<span class="ca-noted-chip' + (l.undone ? ' undone' : '') + '">'
            + '<span>' + esc(T('noted', { subject: l.subject, statement: l.statement })) + '</span>'
            + (l.undone ? '<em>' + esc(T('undone')) + '</em>'
                : '<button type="button" data-ca-undo="' + Number(l.id) + '" data-ca-msg="' + Number(m.id) + '">' + esc(T('undo')) + '</button>')
            + '</span>').join('') + '</div>';
    }
    /** Proposal types applied through a preview dialog (Review), not straight from the card. */
    const REVIEWED = { schema: 1, objects: 1 };
    /** The card for a message's proposals: a reviewed one has Review and Dismiss of its own. */
    function proposalCard(m) {
        const open = p => p.status === 'pending' || p.status === 'failed';
        const plain = m.proposals.some(p => p.status === 'pending' && !REVIEWED[p.type]);
        return '<div class="ca-card" data-ca-card="' + Number(m.id) + '"><div class="ca-card-h">' + esc(T('card_title', { count: m.proposals.length })) + '</div><ul>'
            + m.proposals.map((p, i) => {
                const reviewed = REVIEWED[p.type] && open(p);
                return '<li class="ca-' + esc(p.status) + '"><label>'
                    + (p.status === 'pending' && !REVIEWED[p.type] ? '<input type="checkbox" data-ca-item="' + i + '" checked>'
                        : p.status === 'pending' ? '' : '<span class="ca-state">' + esc(T('st_' + p.status)) + '</span>')
                    + '<span>' + esc(p.summary) + (p.error ? '<small class="ca-err">' + esc(p.error) + '</small>' : '') + '</span></label>'
                    + (reviewed ? '<div class="ca-card-a"><button type="button" class="btn btn-secondary" data-ca-dismiss-one="' + i + '">' + esc(T('dismiss')) + '</button>'
                        + '<button type="button" class="btn btn-primary" data-ca-review="' + i + '">' + esc(T('review')) + '</button></div>' : '')
                    + '</li>';
            }).join('')
            + '</ul>' + (plain ? '<div class="ca-card-a"><button type="button" class="btn btn-secondary" data-ca-dismiss>' + esc(T('dismiss')) + '</button>'
                + '<button type="button" class="btn btn-primary" data-ca-apply>' + esc(T('apply')) + '</button></div>' : '') + '</div>';
    }

    /** Loads one of the preview dialogs' scripts the first time it is needed. */
    function need(global, file) {
        if (window[global]) return Promise.resolve();
        return new Promise((ok, no) => {
            const s = document.createElement('script');
            s.src = panel.dataset.base + 'assets/js/' + file + '?v=1';
            s.onload = () => ok();
            s.onerror = () => no(new Error(T('err_general')));
            document.head.appendChild(s);
        });
    }
    /** After an apply: the page's own lists show what was made (Classes table, class list, objects). */
    function refreshPage(type) {
        const has = id => !!document.getElementById(id);
        try {
            if (has('classesTableBody') && typeof window.loadClasses === 'function') window.loadClasses();
            if (type === 'schema' && has('relTypesTableBody') && typeof window.loadRelTypes === 'function') window.loadRelTypes();
            if (has('classList') && typeof window.loadClasses === 'function') window.loadClasses();
            if (type === 'objects' && has('objectList') && typeof window.loadObjects === 'function') window.loadObjects();
        } catch (e) { /* the page's own refresh - never the apply's problem */ }
    }
    /** What an apply made, as a toast. */
    function appliedToast(type, p) {
        const c = (p.result && p.result.counts) || {};
        let text;
        if (type === 'schema') {
            const total = (c.classes || 0) + (c.properties || 0) + (c.relationship_types || 0);
            text = total ? window.t('cmdb.plan.applied', { classes: c.classes || 0, properties: c.properties || 0, rel_types: c.relationship_types || 0 }) : window.t('cmdb.plan.applied_nothing');
        } else {
            text = (c.objects || 0) + (c.relationships || 0) ? T('applied_objects', { objects: c.objects || 0, relationships: c.relationships || 0 }) : T('applied_nothing');
        }
        if (typeof window.showToast === 'function') window.showToast(text, 'success'); else note(text);
    }
    /** Review: the proposal re-resolved against the live CMDB, in its preview; Apply there applies the ticked lines. */
    async function review(messageId, index) {
        let d;
        try { d = await api(null, { action: 'preview', message_id: messageId, index: index }); }
        catch (e) { note(T('review_failed', { message: e.message }), true); return; }
        const schema = d.type === 'schema';
        try { await need(schema ? 'CmdbSchemaPreview' : 'CmdbObjectPreview', schema ? 'cmdb-schema-preview.js' : 'cmdb-object-preview.js'); }
        catch (e) { note(e.message, true); return; }
        const onApply = async selection => {
            const r = await api({ action: 'apply', message_id: messageId, items: [index], selection: selection });
            const p = (r.proposals || [])[index] || {};
            take(r);
            if (p.status === 'applied') carryOn = true;
            draw();
            if (p.status !== 'applied') throw new Error(p.error || T('err_apply'));
            appliedToast(d.type, p);
            refreshPage(d.type);
        };
        if (schema) {
            window.CmdbSchemaPreview.open({ resolved: d.resolved, title: T('schema_title'), intro: T('schema_intro'),
                multiCompany: !!d.multi_company, canRelTypes: !!(d.can && d.can.relationship_types), onApply: onApply });
        } else {
            window.CmdbObjectPreview.open({ resolved: d.resolved, title: T('objects_title'), intro: T('objects_intro'), onApply: onApply });
        }
    }

    /** Redraws the header bits, the tabs and the current view. */
    function draw() {
        if (!state) return;
        const co = state.company || {};
        $('caCompany').hidden = !(co.multi && co.name);
        $('caCompany').textContent = co.name ? T('company', { company: co.name }) : '';
        $('caFactCount').textContent = (state.facts || []).length ? String(state.facts.length) : '';
        const stage = state.coverage && state.coverage.stage;
        $('caStage').hidden = !(state.ready && state.ai_ready && stage);
        if (stage) $('caStage').textContent = T('stage_' + stage);
        panel.querySelectorAll('[data-ca-view]').forEach(b => b.classList.toggle('active', b.dataset.caView === view));
        $('caChatView').hidden = view !== 'chat';
        $('caLearnedView').hidden = view !== 'learned';
        $('caClear').hidden = !(state.ready && view === 'chat' && (state.messages || []).length);
        if (view === 'chat') drawChat(); else drawLearned();
    }

    /** The Conversation view. */
    function drawChat() {
        const box = $('caMessages');
        const input = $('caInput');
        if (!state.ready) { box.innerHTML = '<div class="ai-chat-welcome"><p>' + esc(T('not_ready')) + '</p></div>'; input.disabled = true; $('caChips').innerHTML = ''; return; }
        if (!state.ai_ready) {
            box.innerHTML = '<div class="ai-chat-welcome"><p>' + esc(T('not_configured')) + '</p>'
                + (state.can_set_up ? '<p><a href="' + esc(panel.dataset.setup) + '">' + esc(T('set_up')) + '</a></p>' : '') + '</div>';
            input.disabled = true; $('caChips').innerHTML = '';
            return;
        }
        input.disabled = false;
        const msgs = state.messages || [];
        let html = msgs.length ? '' : '<div class="ai-chat-welcome"><p>' + esc(T('welcome')) + '</p></div>';
        msgs.forEach(m => {
            html += '<div class="ai-chat-message ' + (m.role === 'user' ? 'user' : 'assistant') + '">'
                + '<div class="ai-chat-bubble">' + (m.role === 'user' ? esc(m.content).replace(/\n/g, '<br>') : '<div class="ca-md">' + md(m.content) + '</div>') + '</div>'
                + (m.looked_at && m.looked_at.length ? '<div class="ai-chat-meta">' + esc(T('looked_at', { what: m.looked_at.map(x => T('tool_' + x)).join(', ') })) + '</div>' : '')
                + '</div>';
            if (m.learned && m.learned.length) html += learnedChips(m);
            if (m.proposals && m.proposals.length) html += proposalCard(m);
        });
        if (busy) html += '<div class="ai-chat-thinking"><span class="dots"><span></span><span></span><span></span></span> ' + esc(T('thinking')) + '</div>';
        box.innerHTML = html;
        $('caChips').innerHTML = chips().map(k => '<button type="button" class="ca-chip" data-ca-chip="' + esc(k) + '">' + esc(T(k)) + '</button>').join('');
        $('caMemory').hidden = !state.remembers;
        box.scrollTop = box.scrollHeight;
    }

    /** The topic choices for the edit form. */
    function topicOptions(sel) {
        return (state.topics || []).map(t => '<option value="' + esc(t) + '"' + (t === sel ? ' selected' : '') + '>' + esc(T('topic_' + t)) + '</option>').join('');
    }
    /** The Learned view: facts grouped by topic, edit in place, delete, forget all. */
    function drawLearned() {
        const box = $('caLearnedView');
        if (!state.ready) { box.innerHTML = '<div class="ai-chat-welcome"><p>' + esc(T('not_ready')) + '</p></div>'; return; }
        const facts = state.facts || [];
        const co = (state.company && state.company.name) || '';
        let html = '<div class="ca-flash" hidden></div><p class="ca-intro">' + esc(T('learned_intro', { company: co })) + '</p>';
        if (!facts.length) html += '<div class="ai-chat-welcome"><p>' + esc(T('learned_empty')) + '</p></div>';
        (state.topics || []).forEach(topic => {
            const rows = facts.filter(f => f.topic === topic);
            if (!rows.length) return;
            html += '<section class="ca-topic"><h4>' + esc(T('topic_' + topic)) + '</h4>';
            rows.forEach(f => {
                if (editing === f.id) {
                    html += '<div class="ca-fact editing" data-ca-fact="' + Number(f.id) + '">'
                        + '<label>' + esc(T('topic')) + '<select data-ca-f="topic">' + topicOptions(f.topic) + '</select></label>'
                        + '<label>' + esc(T('subject')) + '<input type="text" maxlength="200" data-ca-f="subject" value="' + esc(f.subject) + '"></label>'
                        + '<label>' + esc(T('statement')) + '<textarea rows="3" maxlength="1000" data-ca-f="statement">' + esc(f.statement) + '</textarea></label>'
                        + '<div class="ca-fact-a"><button type="button" class="btn btn-secondary" data-ca-cancel>' + esc(T('cancel')) + '</button>'
                        + '<button type="button" class="btn btn-primary" data-ca-save>' + esc(T('save')) + '</button></div></div>';
                    return;
                }
                html += '<div class="ca-fact" data-ca-fact="' + Number(f.id) + '"><div class="ca-fact-t"><strong>' + esc(f.subject) + '</strong> ' + esc(f.statement) + '</div>'
                    + '<div class="ca-fact-m"><span>' + esc(T('learned_by', { name: f.updated_by || '-', when: String(f.updated_at || '').slice(0, 10) })) + '</span>'
                    + '<button type="button" class="ca-link" data-ca-edit>' + esc(T('edit')) + '</button>'
                    + '<button type="button" class="ca-link danger" data-ca-delete>' + esc(T('delete')) + '</button></div></div>';
            });
            html += '</section>';
        });
        if (facts.length) html += '<div class="ca-forget"><button type="button" class="btn btn-danger" data-ca-forget-all>' + esc(T('forget_all')) + '</button></div>';
        box.innerHTML = html;
    }

    /** Loads the state (reads only). */
    async function load() {
        try { state = await api(null); loaded = true; } catch (e) { state = null; }
        if (!state) { $('caMessages').innerHTML = ''; note(T('err_general'), true); return; }
        draw();
    }
    /** Asks the server to open the conversation: a greeting, a catch-up, or nothing. */
    async function openTurn() {
        if (!state || !state.ready || !state.ai_ready) return;
        busy = true; draw();
        try { const d = await api({ action: 'open' }); if (!aiError(d)) take(d); } catch (e) { busy = false; draw(); note(e.message, true); return; }
        busy = false; draw();
    }
    /** Sends a message, showing it straight away. */
    async function send(text) {
        text = String(text || '').trim();
        if (!text || busy || !state) return;
        $('caInput').value = '';
        state.messages = (state.messages || []).concat([{ id: 0, role: 'user', content: text, learned: [], proposals: [] }]);
        carryOn = false;
        busy = true; draw();
        let err = null;
        try { const d = await api({ action: 'send', text: text }); if (d.ai_error) err = d; else take(d); } catch (e) { err = { message: e.message }; }
        busy = false; draw();
        if (err) { if (!aiError(err)) note(err.message, true); }
        $('caInput').focus();
    }
    /** Runs a write action and redraws with the answer; returns whether it worked. */
    async function act(body, done) {
        try { take(await api(body)); draw(); if (done) flash(done); return true; }
        catch (e) { draw(); if (view === 'chat') note(e.message, true); else flash(e.message, true); return false; }
    }
    /** Asks first with confirm.js; falls back to window.confirm. */
    async function ask(o) {
        return window.showConfirm ? window.showConfirm(o) : window.confirm(o.message);
    }

    /** Opens the panel on a view, loading it the first time. */
    async function open(which) {
        view = which || view;
        $('caOverlay').classList.add('active');
        panel.classList.add('active');
        if (!loaded) await load(); else draw();
        if (state && !state.ai_ready && !which) { view = 'learned'; draw(); }
        if (view === 'chat' && !opened && state && state.ready && state.ai_ready) { opened = true; await openTurn(); }
        if (view === 'chat') setTimeout(() => { if (!$('caInput').disabled) $('caInput').focus(); }, 50);
    }
    /** Closes the panel. */
    function close() {
        $('caOverlay').classList.remove('active');
        panel.classList.remove('active');
    }

    /** Wires the panel's controls. */
    function wire() {
        panel = $('caPanel');
        const btn = $('cmdbAssistBtn');
        if (btn) btn.addEventListener('click', () => open('chat'));
        document.addEventListener('click', e => {
            const o = e.target.closest('[data-ca-open]');
            if (o) { e.preventDefault(); open(o.dataset.caOpen); }
        });
        $('caClose').addEventListener('click', close);
        $('caOverlay').addEventListener('click', close);
        $('caSend').addEventListener('click', () => send($('caInput').value));
        $('caInput').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send($('caInput').value); } });
        $('caClear').addEventListener('click', async () => {
            if (!(await ask({ title: T('clear_title'), message: T('clear_body'), okLabel: T('clear'), okClass: 'danger' }))) return;
            if (await act({ action: 'clear' })) { state.remembers = false; carryOn = false; opened = true; openTurn(); }
        });
        panel.addEventListener('click', async e => {
            const tab = e.target.closest('[data-ca-view]');
            if (tab) { view = tab.dataset.caView; editing = 0; draw(); if (view === 'chat' && !opened && state && state.ready && state.ai_ready) { opened = true; openTurn(); } return; }
            const chip = e.target.closest('[data-ca-chip]');
            if (chip) { send(T(chip.dataset.caChip + '_ask')); return; }
            const undo = e.target.closest('[data-ca-undo]');
            if (undo) { undo.disabled = true; act({ action: 'undo', message_id: Number(undo.dataset.caMsg), fact_id: Number(undo.dataset.caUndo) }); return; }
            const card = e.target.closest('[data-ca-card]');
            const rv = e.target.closest('[data-ca-review]');
            if (card && rv) { review(Number(card.dataset.caCard), Number(rv.dataset.caReview)); return; }
            const one = e.target.closest('[data-ca-dismiss-one]');
            if (card && one) { one.disabled = true; act({ action: 'dismiss', message_id: Number(card.dataset.caCard), items: [Number(one.dataset.caDismissOne)] }); return; }
            if (card && e.target.closest('[data-ca-apply], [data-ca-dismiss]')) {
                const dismiss = !!e.target.closest('[data-ca-dismiss]');
                const items = [...card.querySelectorAll('[data-ca-item]' + (dismiss ? '' : ':checked'))].map(c => Number(c.dataset.caItem));
                if (!items.length) { note(T('tick_one'), true); return; }
                act({ action: dismiss ? 'dismiss' : 'apply', message_id: Number(card.dataset.caCard), items: items });
                return;
            }
            const fact = e.target.closest('[data-ca-fact]');
            if (fact && e.target.closest('[data-ca-edit]')) { editing = Number(fact.dataset.caFact); draw(); return; }
            if (fact && e.target.closest('[data-ca-cancel]')) { editing = 0; draw(); return; }
            if (fact && e.target.closest('[data-ca-save]')) {
                const v = k => fact.querySelector('[data-ca-f="' + k + '"]').value;
                const id = Number(fact.dataset.caFact);
                editing = 0;
                if (!(await act({ action: 'fact_save', fact_id: id, topic: v('topic'), subject: v('subject'), statement: v('statement') }, T('saved')))) { editing = id; draw(); }
                return;
            }
            if (fact && e.target.closest('[data-ca-delete]')) {
                const f = (state.facts || []).find(x => x.id === Number(fact.dataset.caFact));
                if (!(await ask({ title: T('delete_title'), message: T('delete_body', { subject: f ? f.subject : '' }), okLabel: T('delete'), okClass: 'danger' }))) return;
                act({ action: 'fact_delete', fact_id: Number(fact.dataset.caFact) }, T('deleted'));
                return;
            }
            if (e.target.closest('[data-ca-forget-all]')) {
                const co = (state.company && state.company.name) || '';
                if (!(await ask({ title: T('forget_all_title'), message: T('forget_all_body', { company: co, count: (state.facts || []).length }), okLabel: T('forget_all_ok'), okClass: 'danger' }))) return;
                act({ action: 'forget_all' }, T('forgotten_all'));
            }
        });
        document.addEventListener('keydown', e => { if (e.key === 'Escape' && panel.classList.contains('active')) close(); });
    }

    /** Starts once the page is there; opens straight away with ?assistant=1 (or =learned). */
    function start() {
        if (!$('caPanel')) return;
        wire();
        const m = location.search.match(/[?&]assistant=(1|learned)\b/);
        if (m) open(m[1] === 'learned' ? 'learned' : 'chat');
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();

    window.CmdbAssistant = { open: open };
})();
