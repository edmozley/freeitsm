/**
 * Starters & leavers - one case (starters/view.php).
 *
 * Draws api/starters/get.php: the hero (who, when, how far), the steps (tasks
 * in Tasks), the person, the kit (Assets custody), the account (Users), the
 * documents panel and the history. Every change goes back through an endpoint
 * that calls StartersService, which calls the module that owns the data.
 */
(function () {
    'use strict';
    const { T, esc, api, lookups, when, daysFrom, date, kindPill, statusPill, bar, searchBox, findPeople, personLabel, modal } = window.Jml;
    const id = window.JML_CASE_ID;
    const base = window.JML_BASE;
    const root = () => document.getElementById('jmlCase');
    let data = null;
    let docsMounted = false;

    const toast = (msg, type) => window.showToast && window.showToast(msg, type || 'success');
    const ask = o => (window.showConfirm ? window.showConfirm(o) : Promise.resolve(window.confirm(o.title + '\n\n' + (o.message || ''))));
    const link = (url, text, cls, tip) => url ? '<a href="' + esc(base + url) + '"' + (cls ? ' class="' + cls + '"' : '') + (tip ? ' title="' + esc(tip) + '"' : '') + '>' + text + '</a>' : '<span' + (cls ? ' class="' + cls + '"' : '') + '>' + text + '</span>';

    /** Runs a change, reloads the case, and says what happened. */
    async function act(fn, done) {
        try { await fn(); if (done) toast(done); } catch (e) { toast(e.message, 'error'); }
        await load();
    }

    // ---- hero ---------------------------------------------------------------
    function hero(c, can, links) {
        const buttons = [];
        if (links.task)   buttons.push(link(links.task, esc(T('view.open_task')), 'jml-btn', T('view.open_task_tip')));
        if (links.person) buttons.push(link(links.person, esc(T('view.open_person')), 'jml-btn', T('view.open_person_tip')));
        if (c.status === 'open') {
            buttons.push('<button type="button" class="jml-btn primary" data-act="done">' + esc(T('view.done')) + '</button>');
            if (can.change_status) buttons.push('<button type="button" class="jml-btn danger" data-act="cancel">' + esc(T('view.cancel')) + '</button>');
        } else if (can.change_status) {
            buttons.push('<button type="button" class="jml-btn" data-act="reopen">' + esc(T('view.reopen')) + '</button>');
        }
        const sub = [c.person_email, c.job_title].filter(Boolean).map(esc).join(' &middot; ');
        const facts = [
            [T('date_label.' + c.kind), esc(date(c.effective_date)) + (c.status === 'open' ? ' <span class="jml-dim">(' + esc(when(c.effective_date)) + ')</span>' : '')],
            [T('view.template'), c.template_id && can.templates ? link('starters/settings/?template=' + c.template_id, esc(c.template_name || '-'), 'jml-link') : esc(c.template_name || '-')],
            [T('view.owner'), esc(c.owner_name || '-')],
        ];
        if (data.multi && c.company_name) facts.push([T('view.company'), esc(c.company_name)]);
        return '<section class="jml-hero ' + esc(c.kind) + '">'
             + '<div class="jml-hero-top"><div class="jml-hero-who">' + kindPill(c.kind) + ' ' + statusPill(c.status)
             + '<h1>' + esc(c.person_name) + '</h1>' + (sub ? '<div class="jml-hero-sub">' + sub + '</div>' : '') + '</div>'
             + '<div class="jml-hero-actions">' + buttons.join('') + '</div></div>'
             + '<div class="jml-facts">' + facts.map(([l, v]) => '<div class="jml-fact"><div class="l">' + esc(l) + '</div><div class="v">' + v + '</div></div>').join('') + '</div>'
             + (c.progress.total ? bar(c.progress.done, c.progress.total, T('view.progress', c.progress)) : '')
             + (c.notes ? '<div class="jml-notes">' + esc(c.notes) + '</div>' : '')
             + '</section>';
    }

    // ---- steps --------------------------------------------------------------
    function steps(c) {
        const tick = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
        const items = c.steps.map(s => {
            const d = daysFrom(s.due_date);
            const late = !s.is_closed && d !== null && d < 0;
            const who = [s.team_name, s.analyst_name].filter(Boolean).map(esc).join(' &middot; ') || esc(T('view.step_nobody'));
            return '<li class="jml-step' + (s.is_closed ? ' closed' : '') + '"><span class="jml-tick">' + (s.is_closed ? tick : '') + '</span>'
                 + '<div class="jml-step-body">' + link(s.url, esc(s.title), 'jml-step-title')
                 + '<div class="jml-step-meta"><span>' + who + '</span>'
                 + (s.due_date ? '<span' + (late ? ' class="late"' : '') + '>' + esc(late ? T('view.step_overdue') + ' - ' + date(s.due_date) : T('view.step_due', { date: date(s.due_date) })) + '</span>' : '')
                 + (s.status_name ? '<span class="jml-pill"' + (s.status_colour ? ' style="background:' + esc(s.status_colour) + '22;color:' + esc(s.status_colour) + '"' : '') + '>' + esc(s.status_name) + '</span>' : '')
                 + '</div></div></li>';
        });
        let body;
        if (c.steps_pending) {
            // The template asks questions first; its steps arrive with the answers.
            body = '<div class="jml-wait">' + esc(T('view.pending', { form: c.form_title || '' }))
                 + (data.links.task && c.task_forms_on ? ' ' + link(data.links.task, esc(T('view.pending_go')), 'jml-link') : '')
                 + (c.task_forms_on ? '' : '<div class="jml-dim" style="margin-top:6px">' + esc(T('view.pending_off')) + '</div>') + '</div>';
        } else if (!c.template_id && c.status === 'open') {
            // Opened without one, or by a form no template matched: choose one now.
            body = '<p class="jml-muted" style="margin-top:0">' + esc(T('view.no_template')) + '</p>'
                 + '<div class="jml-use"><select id="jmlUseTpl"></select><button type="button" class="jml-btn primary" id="jmlUseTplGo">' + esc(T('view.use')) + '</button></div>';
        } else {
            body = items.length ? '<ul class="jml-steps">' + items.join('') + '</ul>' : '<p class="jml-muted">' + esc(T('view.steps_none')) + '</p>';
        }
        return panel(T('view.steps'), T('view.steps_intro'), body);
    }

    // ---- the answers (Forms) ----------------------------------------------------
    function answers(c) {
        const rows = c.answers.filter(a => String(a.value || '').trim() !== '');
        if (!rows.length) return '';
        const copy = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';
        return panel(T('view.answers'), T('view.answers_intro'), '<dl class="jml-answers">' + rows.map((a, i) =>
            '<dt>' + esc(a.label) + '</dt><dd><span>' + esc(a.value) + '</span><button type="button" class="jml-copy" data-copy="' + i + '" title="' + esc(T('view.copy')) + '" aria-label="' + esc(T('view.copy') + ': ' + a.label) + '">' + copy + '</button></dd>').join('') + '</dl>');
    }

    // ---- person, kit, account -------------------------------------------------
    function person(c) {
        if (c.user_id) return '';
        return panel(T('view.person'), null,
            '<p class="jml-muted" style="margin-top:0">' + esc(T('view.person_unlinked')) + '</p><div class="jml-search" id="jmlLinkPerson"></div>');
    }

    function kit(c, can, links) {
        let body;
        if (!can.assets) body = '<p class="jml-muted">' + esc(T('view.kit_no_access')) + '</p>';
        else if (!c.user_id) body = '<p class="jml-muted">' + esc(T('view.kit_needs_person')) + '</p>';
        else {
            const items = c.kit.map(a => {
                const name = a.hostname || [a.manufacturer, a.model].filter(Boolean).join(' ') || ('#' + a.id);
                const meta = [a.asset_type, a.model && a.hostname ? a.model : null, a.service_tag].filter(Boolean).map(esc).join(' &middot; ');
                return '<li><div class="grow">' + link(a.url, esc(name)) + '<div class="jml-dim">' + meta
                     + (a.assigned_datetime ? ' &middot; ' + esc(T('view.kit_since', { date: window.fmtDate ? fmtDate(a.assigned_datetime) : a.assigned_datetime })) : '') + '</div></div>'
                     + (c.status === 'open' ? '<button type="button" class="jml-btn small" data-recover="' + a.id + '" data-name="' + esc(name) + '">' + esc(T('view.kit_recover')) + '</button>' : '')
                     + '</li>';
            });
            body = (items.length ? '<ul class="jml-kit">' + items.join('') + '</ul>' : '<p class="jml-muted" style="margin-top:0">' + esc(T('view.kit_none')) + '</p>')
                 + (c.status === 'open' ? '<div class="jml-search" id="jmlIssue"></div>' : '');
        }
        const extra = links.handover && c.kit.length ? link(links.handover, esc(T('view.kit_handover')), 'jml-btn small', T('view.kit_handover_tip')) : '';
        return panel(T('view.kit'), T('view.kit_intro'), body, extra);
    }

    function account(c) {
        if (c.kind !== 'leaver' || !c.person) return '';
        const p = c.person;
        let body;
        if (!p.is_active) body = '<p class="jml-muted" style="margin:0">' + esc(T('view.account_left', { date: p.deactivated_datetime ? (window.fmtDate ? fmtDate(p.deactivated_datetime) : p.deactivated_datetime) : '' })) + '</p>';
        else if (p.is_managed) body = '<div class="jml-note">' + esc(T('view.account_managed')) + '</div>';
        else body = '<button type="button" class="jml-btn danger" data-act="left">' + esc(T('view.mark_left')) + '</button>';
        return panel(T('view.account'), T('view.account_intro'), body);
    }

    // ---- history --------------------------------------------------------------
    function history(c) {
        const line = h => {
            const p = { old: h.old_value || '', new: h.new_value || '', value: h.new_value || '', field: h.field_name };
            if (h.field_name === 'status') { p.old = T('status.' + p.old); p.new = T('status.' + p.new); }
            const key = 'view.h_' + h.field_name;
            const text = T(key, p);
            return text === 'starters.' + key || text === key ? T('view.h_other', p) : text;
        };
        const items = c.history.map(h => '<li><span class="who">' + esc(h.analyst_name || T('view.system')) + '</span> ' + esc(line(h))
                                       + '<span class="at">' + esc(window.fmtDateTime ? fmtDateTime(h.created_datetime) : h.created_datetime) + '</span></li>');
        return panel(T('view.history'), null, items.length ? '<ul class="jml-history">' + items.join('') + '</ul>' : '<p class="jml-muted">' + esc(T('view.history_none')) + '</p>');
    }

    function panel(title, intro, body, extra) {
        return '<section class="jml-panel"><div class="jml-panel-h"><h2>' + esc(title) + '</h2><span class="grow"></span>' + (extra || '') + '</div>'
             + (intro ? '<p class="jml-panel-intro">' + esc(intro) + '</p>' : '') + body + '</section>';
    }

    // ---- draw + wire ------------------------------------------------------------
    function draw() {
        const { case: c, can, links } = data;
        document.title = c.person_name + ' - ' + T('title');
        root().innerHTML = hero(c, can, links)
            + '<div class="jml-grid"><div class="jml-col">' + steps(c) + history(c) + '</div>'
            + '<div class="jml-col">' + answers(c) + person(c) + kit(c, can, links) + account(c)
            + '<section class="jml-panel"><div class="jml-panel-h"><h2>' + esc(T('view.documents')) + '</h2></div><div id="jmlDocs"></div></section>'
            + '</div></div>';
        wire(c);
    }

    function wire(c) {
        root().querySelectorAll('[data-act]').forEach(b => { b.onclick = () => onAct(b.dataset.act, c); });
        root().querySelectorAll('[data-recover]').forEach(b => { b.onclick = () => recover(c, +b.dataset.recover, b.dataset.name); });

        // Copy an answer - the shared clipboard helper, which also works over plain HTTP.
        const shown = c.answers.filter(a => String(a.value || '').trim() !== '');
        root().querySelectorAll('[data-copy]').forEach(b => {
            b.onclick = async () => {
                const text = String(shown[+b.dataset.copy].value);
                const ok = window.copyToClipboard ? await window.copyToClipboard(text) : false;
                if (!ok) { toast(T('view.copy_failed'), 'error'); return; }
                b.classList.add('done');
                setTimeout(() => b.classList.remove('done'), 1200);
            };
        });

        // A case with no template: choose one (only this kind's, switched on).
        const useSel = document.getElementById('jmlUseTpl');
        if (useSel) {
            lookups().then(l => {
                const list = (l.templates || []).filter(t => t.kind === c.kind && t.is_active);
                useSel.innerHTML = list.length ? list.map(t => '<option value="' + t.id + '">' + esc(t.name) + '</option>').join('') : '<option value="">' + esc(T('new.template_none')) + '</option>';
                document.getElementById('jmlUseTplGo').disabled = !list.length;
            }).catch(() => {});
            document.getElementById('jmlUseTplGo').onclick = () => act(async () => {
                const r = await api('use_template.php', { id, template_id: +useSel.value });
                (r.warnings || []).forEach(w => toast(w, 'error'));
            }, T('view.used'));
        }

        const linkHost = document.getElementById('jmlLinkPerson');
        if (linkHost) searchBox(linkHost, {
            placeholder: T('new.person_search_ph'), none: T('new.person_none'), fetcher: findPeople, label: personLabel,
            onPick: p => act(() => api('link_user.php', { id, user_id: p.id }), T('view.person_linked', { name: p.name })),
        });

        const issueHost = document.getElementById('jmlIssue');
        if (issueHost) searchBox(issueHost, {
            placeholder: T('view.kit_issue_ph'), none: T('view.kit_issue_none'),
            // The asset list's own search - the Assets module's endpoint, scoped as it scopes it.
            fetcher: async q => {
                const r = await fetch(base + 'api/assets/get_assets.php?search=' + encodeURIComponent(q)).then(x => x.json());
                if (!r.success) throw new Error(r.error || 'Something went wrong.');
                const held = new Set(c.kit.map(a => a.id));
                return (r.assets || []).filter(a => !held.has(+a.id)).slice(0, 20);
            },
            label: a => '<b>' + esc(a.hostname || [a.manufacturer, a.model].filter(Boolean).join(' ')) + '</b> <span class="jml-dim">'
                      + esc([a.asset_type_name || a.asset_type, a.service_tag].filter(Boolean).join(' · '))
                      + (+a.user_count ? ' · ' + esc(T('view.kit_held_by', { n: a.user_count })) : '') + '</span>',
            onPick: a => act(() => api('kit.php', { id, action: 'issue', asset_id: +a.id }),
                             T('view.kit_issued', { name: a.hostname || a.model || ('#' + a.id) })),
        });

        if (!docsMounted && window.FreeITSMDocuments) {
            window.FreeITSMDocuments.mount(document.getElementById('jmlDocs'), { parentType: 'jml_case', parentId: id, apiBase: base + 'api/documents/', canEdit: true });
        }
    }

    async function onAct(what, c) {
        if (what === 'done') {
            const open = c.progress.total - c.progress.done;
            if (!await ask({ title: T('view.done_confirm_title'), message: open ? T('view.done_confirm_open', { n: open }) : T('view.done_confirm_body'), okLabel: T('view.done') })) return;
            return act(() => api('status.php', { id, status: 'done' }));
        }
        if (what === 'cancel') {
            if (!await ask({ title: T('view.cancel_confirm_title'), message: T('view.cancel_confirm_body'), okLabel: T('view.cancel'), okClass: 'danger' })) return;
            return act(() => api('status.php', { id, status: 'cancelled' }));
        }
        if (what === 'reopen') return act(() => api('status.php', { id, status: 'open' }));
        if (what === 'left') {
            if (!await ask({ title: T('view.mark_left_title', { name: c.person_name }), message: T('view.mark_left_body'), okLabel: T('view.mark_left'), okClass: 'danger' })) return;
            return act(() => api('mark_left.php', { id }), T('view.mark_left_done'));
        }
    }

    let recoverModal = null;
    async function recover(c, assetId, name) {
        const $ = x => document.getElementById(x);
        if (!recoverModal) recoverModal = modal($('jmlRecoverModal'));
        $('jmlRecoverTitle').textContent = T('view.kit_recover_title', { name });
        $('jmlRecoverBody').textContent = T('view.kit_recover_body', { person: c.person_name });
        $('jmlRecoverError').hidden = true;
        let statuses = [];
        try { statuses = (await lookups()).asset_statuses || []; } catch (e) { /* the status is optional */ }
        $('jmlRecoverStatus').innerHTML = '<option value="">' + esc(T('view.kit_keep_status')) + '</option>'
            + statuses.map(s => '<option value="' + s.id + '">' + esc(s.name) + '</option>').join('');
        $('jmlRecoverStatusField').hidden = !statuses.length;
        $('jmlRecoverGo').onclick = async () => {
            $('jmlRecoverGo').disabled = true;
            try {
                await api('kit.php', { id, action: 'recover', asset_id: assetId, status_id: $('jmlRecoverStatus').value ? +$('jmlRecoverStatus').value : null });
                recoverModal.close();
                toast(T('view.kit_recovered', { name }));
                await load();
            } catch (e) {
                $('jmlRecoverError').textContent = e.message; $('jmlRecoverError').hidden = false;
            }
            $('jmlRecoverGo').disabled = false;
        };
        recoverModal.open();
    }

    async function load() {
        try {
            data = await api('get.php?id=' + id);
        } catch (e) {
            root().innerHTML = '<div class="jml-panel"><p class="jml-muted" style="margin:0">' + esc(e.message === 'Case not found.' ? T('view.not_found') : e.message) + '</p></div>';
            return;
        }
        // Keep the documents panel across redraws: it holds its own state.
        const docs = document.getElementById('jmlDocs');
        const keep = docs && docs.firstChild ? docs : null;
        draw();
        if (keep) { document.getElementById('jmlDocs').replaceWith(keep); }
        docsMounted = true;
    }

    document.addEventListener('DOMContentLoaded', () => {
        load().then(() => {
            let created = null;
            try { created = JSON.parse(sessionStorage.getItem('jml_created') || 'null'); sessionStorage.removeItem('jml_created'); } catch (e) { /* private mode */ }
            if (created && +created.id === id) {
                const n = data ? data.case.steps.length : 0;
                toast(n ? T('new.created_steps', { n }) : T('new.created'));
                (created.warnings || []).forEach(w => toast(w, 'error'));
            }
        });
    });
})();
