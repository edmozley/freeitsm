/**
 * Starters & leavers (4.6.0) - what every page in the module shares: the API
 * call, the small renderers (kind, status, progress, "in 3 days"), a person
 * search, and the New case dialog.
 *
 * The server does the work (includes/starters/service.php, which drives Tasks,
 * Assets and Workflows); this file only asks it and draws the answer.
 */
(function () {
    'use strict';

    /** Translates a key under starters.*, or returns the key when the i18n helper is not loaded. */
    const T = (k, p) => (window.t ? window.t('starters.' + k, p) : k);

    /** HTML-escapes a value for safe insertion into markup; null and undefined become an empty string. */
    function esc(v) {
        if (v === null || v === undefined) return '';
        return String(v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    /**
     * Calls a Starters API endpoint under window.JML_API - a GET with no body, a JSON POST with one.
     * Resolves to the parsed reply; throws an Error with a readable message otherwise.
     */
    async function api(path, body) {
        const opts = body === undefined ? {} : { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) };
        let res;
        try { res = await fetch(window.JML_API + path, opts); }
        catch (e) { throw new Error('The server could not be reached.'); }
        let data;
        try { data = await res.json(); } catch (e) { throw new Error('The server sent back something unexpected.'); }
        if (!data.success) throw new Error(data.error || 'Something went wrong.');
        return data;
    }

    let lookupsPromise = null;
    /** Fetches lookups.php once per page; every caller shares the one request. */
    function lookups() {
        if (!lookupsPromise) lookupsPromise = api('lookups.php').catch(e => { lookupsPromise = null; throw e; });
        return lookupsPromise;
    }

    /** Whole days from today (local) to a Y-m-d date: 0 today, negative in the past. */
    function daysFrom(ymd) {
        if (!ymd) return null;
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(ymd);
        if (!m) return null;
        const d = new Date(+m[1], +m[2] - 1, +m[3]);
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        return Math.round((d - today) / 86400000);
    }

    /** "today", "in 3 days", "2 days ago". */
    function when(ymd) {
        const n = daysFrom(ymd);
        if (n === null) return '';
        if (n === 0) return T('when.today');
        if (n === 1) return T('when.tomorrow');
        if (n === -1) return T('when.ago_day');
        return n > 0 ? T('when.in_days', { n }) : T('when.ago_days', { n: -n });
    }

    /** A Y-m-d date in the analyst's own format. */
    function date(ymd) {
        return ymd ? (window.fmtNaiveDate ? window.fmtNaiveDate(ymd) : ymd) : '';
    }

    /** "1 step" / "5 steps". */
    const stepsLabel = n => T(n === 1 ? 'settings.steps_count_one' : 'settings.steps_count', { n });
    const kindPill   = k => '<span class="jml-kind ' + esc(k) + '">' + esc(T('kind.' + k)) + '</span>';
    const statusPill = s => '<span class="jml-status ' + esc(s) + '">' + esc(T('status.' + s)) + '</span>';

    /** A progress bar with "done of total" beside it; "No steps" when there are none. */
    function bar(done, total, label) {
        if (!total) return '<span class="jml-bar-text">' + esc(T('list.no_steps')) + '</span>';
        const pct = Math.round(done / total * 100);
        return '<div class="jml-bar"><div class="jml-bar-track"><div class="jml-bar-fill' + (done === total ? ' full' : '') + '" style="width:' + pct + '%"></div></div>'
             + '<span class="jml-bar-text">' + esc(label || T('list.steps', { done, total })) + '</span></div>';
    }

    /**
     * A type-ahead search box: draws an input into `host`, asks `fetcher(q)` for
     * results as you type, and calls `onPick(item)` with the one chosen.
     *
     * @param {HTMLElement} host  the element to draw into
     * @param {{placeholder:string, none:string, fetcher:(q:string)=>Promise<Array>, label:(item)=>string, onPick:(item)=>void}} o
     */
    function searchBox(host, o) {
        host.innerHTML = '<input type="text" autocomplete="off" placeholder="' + esc(o.placeholder) + '"><div class="jml-results" hidden></div>';
        const input = host.querySelector('input');
        const list = host.querySelector('.jml-results');
        let items = [], active = -1, timer = null, seq = 0;

        const draw = () => {
            if (!items.length) { list.innerHTML = '<div class="none">' + esc(o.none) + '</div>'; }
            else list.innerHTML = items.map((it, i) => '<button type="button" data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '>' + o.label(it) + '</button>').join('');
            list.hidden = false;
        };
        const close = () => { list.hidden = true; active = -1; };
        const pick = i => { if (items[i]) { o.onPick(items[i]); input.value = ''; close(); } };

        input.addEventListener('input', () => {
            clearTimeout(timer);
            const q = input.value.trim();
            if (q.length < 2) { close(); return; }
            timer = setTimeout(async () => {
                const mine = ++seq;
                try { const r = await o.fetcher(q); if (mine !== seq) return; items = r || []; active = items.length ? 0 : -1; draw(); }
                catch (e) { if (mine === seq) { items = []; list.innerHTML = '<div class="none">' + esc(e.message) + '</div>'; list.hidden = false; } }
            }, 220);
        });
        input.addEventListener('keydown', e => {
            if (list.hidden) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); draw(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); draw(); }
            else if (e.key === 'Enter') { e.preventDefault(); pick(active); }
            else if (e.key === 'Escape') { e.stopPropagation(); close(); }
        });
        list.addEventListener('mousedown', e => { const b = e.target.closest('button[data-i]'); if (b) { e.preventDefault(); pick(+b.dataset.i); } });
        input.addEventListener('blur', () => setTimeout(close, 150));
        return { focus: () => input.focus(), clear: () => { input.value = ''; close(); } };
    }

    /** People to open a case for or link one to - the Assets service's own scoped search. */
    async function findPeople(q) {
        return (await api('lookups.php?people=' + encodeURIComponent(q))).people;
    }
    const personLabel = p => '<b>' + esc(p.name) + '</b>' + (p.email && p.email !== p.name ? ' <span class="jml-dim">' + esc(p.email) + '</span>' : '')
                         + (p.is_active ? '' : ' <span class="jml-pill">' + esc(T('status.done')) + '</span>');

    /** Opens / closes a .modal the house way, with Escape and a click on the backdrop. */
    function modal(el) {
        const close = () => { el.classList.remove('active'); document.removeEventListener('keydown', onKey, true); };
        const onKey = e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
        el.querySelectorAll('[data-close]').forEach(b => { b.onclick = close; });
        el.onmousedown = e => { if (e.target === el) close(); };
        return {
            open() { el.classList.add('active'); document.addEventListener('keydown', onKey, true); },
            close,
        };
    }

    // ---- The New case dialog ------------------------------------------------
    const JmlNew = (function () {
        let m = null, kind = 'starter', who = 'new', person = null, data = null, search = null;
        const $ = id => document.getElementById(id);

        function setKind(k) {
            kind = k;
            document.querySelectorAll('#jmlNewKinds .jml-kind-card').forEach(b => b.classList.toggle('selected', b.dataset.kind === k));
            $('jmlNewDateLabel').textContent = T('date_label.' + k);
            // A mover or leaver is always somebody already in Users.
            const seg = $('jmlNewWho');
            seg.hidden = k !== 'starter';
            setWho(k === 'starter' ? who : 'existing');
            fillTemplates();
        }
        function setWho(w) {
            who = w;
            $('jmlNewWho').querySelectorAll('button').forEach(b => b.classList.toggle('active', b.dataset.who === w));
            $('jmlNewPersonNew').hidden = w !== 'new';
            $('jmlNewPersonExisting').hidden = w !== 'existing';
            drawPerson();
        }
        function drawPerson() {
            const host = $('jmlNewPersonSearch');
            if (person) {
                host.innerHTML = '<div class="jml-chosen"><div class="grow">' + personLabel(person) + (person.job_title ? '<div class="jml-dim">' + esc(person.job_title) + '</div>' : '') + '</div>'
                               + '<button type="button" class="jml-link" id="jmlNewPersonChange">' + esc(T('new.person_change')) + '</button></div>';
                $('jmlNewPersonChange').onclick = () => { person = null; drawPerson(); search && search.focus(); };
                return;
            }
            search = searchBox(host, { placeholder: T('new.person_search_ph'), none: T('new.person_none'), fetcher: findPeople, label: personLabel,
                                       onPick: p => { person = p; drawPerson(); } });
        }
        function fillTemplates() {
            const sel = $('jmlNewTemplate');
            const list = (data ? data.templates : []).filter(t => t.kind === kind && t.is_active);
            sel.innerHTML = list.map(t => '<option value="' + t.id + '">' + esc(t.name) + ' (' + esc(stepsLabel(t.steps.length)) + (t.form_id ? ', ' + esc(T('new.asks')) : '') + ')</option>').join('')
                          + '<option value="">' + esc(T('new.template_none')) + '</option>';
            $('jmlNewTemplateHint').textContent = list.length ? T('new.template_hint') : T('new.no_templates');
        }
        function error(msg) { const e = $('jmlNewError'); e.textContent = msg || ''; e.hidden = !msg; }

        async function open(k) {
            if (!m) {
                m = modal($('jmlNewModal'));
                document.querySelectorAll('#jmlNewKinds .jml-kind-card').forEach(b => { b.onclick = () => setKind(b.dataset.kind); });
                $('jmlNewWho').querySelectorAll('button').forEach(b => { b.onclick = () => setWho(b.dataset.who); });
                $('jmlNewCreate').onclick = create;
            }
            ['jmlNewName', 'jmlNewEmail', 'jmlNewJob', 'jmlNewNotes'].forEach(id => { $(id).value = ''; });
            $('jmlNewDate').value = '';
            person = null; who = 'new'; error('');
            try { data = await lookups(); } catch (e) { data = { templates: [], analysts: [] }; error(e.message); }
            $('jmlNewOwner').innerHTML = data.analysts.map(a => '<option value="' + a.id + '"' + (+a.id === window.JML_ME ? ' selected' : '') + '>' + esc(a.name) + '</option>').join('');
            setKind(k || 'starter');
            m.open();
            setTimeout(() => (who === 'new' ? $('jmlNewName') : document.querySelector('#jmlNewPersonSearch input'))?.focus(), 60);
        }

        async function create() {
            const btn = $('jmlNewCreate');
            const body = {
                kind, effective_date: $('jmlNewDate').value,
                template_id: $('jmlNewTemplate').value || null,
                owner_analyst_id: $('jmlNewOwner').value || null,
                notes: $('jmlNewNotes').value,
            };
            if (who === 'existing') {
                if (!person) { error(T('new.person_search_ph')); return; }
                body.user_id = person.id;
            } else {
                body.person_name = $('jmlNewName').value.trim();
                body.person_email = $('jmlNewEmail').value.trim();
                body.job_title = $('jmlNewJob').value.trim();
            }
            btn.disabled = true; error('');
            try {
                const r = await api('create.php', body);
                // The case page says so (a toast here would be lost to the navigation);
                // a step that could not be added is in the case's history.
                try { sessionStorage.setItem('jml_created', JSON.stringify({ id: r.id, warnings: r.warnings || [] })); } catch (e) { /* private mode */ }
                location.href = 'view.php?id=' + r.id;
            } catch (e) {
                error(e.message);
                btn.disabled = false;
            }
        }
        return { open };
    })();

    window.Jml = { T, esc, api, lookups, stepsLabel, when, daysFrom, date, kindPill, statusPill, bar, searchBox, findPeople, personLabel, modal };
    window.JmlNew = JmlNew;
})();
