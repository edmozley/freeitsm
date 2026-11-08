/**
 * Directory maintenance - the three lists of issue #261, on System -> Imported people
 * (system/directory-maintenance/), for every kind of source (LDAP, CardDAV, Entra ID).
 *
 *   DirectoryMaintenance.mount(el, { kind: 'orphans' })
 *   DirectoryMaintenance.mount(el, { kind: 'stale',    provider: 7 })
 *   DirectoryMaintenance.mount(el, { kind: 'imported', provider: 7 })
 *
 * Draws the list, lets people be picked, and sends what to do with them to
 * api/system/directory_maintenance.php. It decides nothing: which list may do
 * what, who is in it right now and who is skipped are the server's
 * (includes/services/directory_maintenance.php), and the page shows its answer.
 *
 * Needs i18n.js, toast.js and confirm.js, which every System page loads.
 */
(function () {
    'use strict';

    // From where this script was loaded, so it works at any depth and under any base URL.
    const API = (document.currentScript ? document.currentScript.src : '').replace(/assets\/js\/directory-maintenance\.js.*$/, '')
        + 'api/system/directory_maintenance.php';
    const DAYS = [30, 90, 180, 365];
    const ACTIONS = { orphans: ['keep', 'left', 'delete'], stale: ['keep', 'left', 'delete'], imported: ['remove'] };

    const T = (k, p) => window.t('system.sso.maint_' + k, p);
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const toast = (text, type) => { if (typeof window.showToast === 'function') window.showToast(text, type); };

    // Its own few rules, once per page, the way confirm.js brings its own.
    function style() {
        if (document.getElementById('dmStyle')) return;
        const s = document.createElement('style');
        s.id = 'dmStyle';
        s.textContent = '.dm-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin:8px 0;}'
            + '.dm-bar select{padding:5px 8px;}'
            + '.dm-table{width:100%;border-collapse:collapse;font-size:12.5px;}'
            + '.dm-table th{text-align:left;padding:7px 9px;color:var(--text-dim,#888);font-weight:600;border-bottom:1px solid var(--border-soft,#eee);white-space:nowrap;}'
            + '.dm-table td{padding:7px 9px;border-bottom:1px solid var(--border-soft,#f4f4f4);color:var(--text,#444);}'
            + '.dm-dim{color:var(--text-dim,#888);font-size:12.5px;}'
            + '.dm-warn{padding:10px 12px;border-radius:6px;background:var(--warning-bg,#fff7e6);color:var(--warning-text,#92400e);margin:8px 0;font-size:13px;}'
            + '.dm-skipped{margin-top:10px;font-size:12.5px;}.dm-skipped li{margin:2px 0;}'
            + '.btn.dm-danger{color:var(--danger,#c62828);border-color:currentColor;}.btn[disabled]{opacity:.5;cursor:not-allowed;}';
        document.head.appendChild(s);
    }

    async function getJson(qs) {
        const r = await fetch(API + '?' + qs, { credentials: 'same-origin' });
        return r.json();
    }

    function mount(el, opts) {
        style();
        // Mounted again on the same element (another source picked): the old
        // handlers go, or every click would act twice.
        if (el._dmAbort) el._dmAbort.abort();
        const ac = new AbortController();
        el._dmAbort = ac;
        const kind = opts.kind, provider = opts.provider || 0;
        const state = { days: 90, run: 0, last: null };

        el.innerHTML = '<div class="dm-bar" data-dm="bar"></div><div data-dm="note"></div>'
            + '<div style="overflow-x:auto;" data-dm="list"></div><div data-dm="actions" class="dm-bar"></div><div data-dm="skipped"></div>';
        const part = n => el.querySelector('[data-dm="' + n + '"]');

        // The choices above the list: how long, for stale; which import, for imported.
        async function drawBar() {
            if (kind === 'stale') {
                part('bar').innerHTML = '<label>' + esc(T('days_label')) + ' <select data-dm="days">'
                    + DAYS.map(d => '<option value="' + d + '"' + (d === state.days ? ' selected' : '') + '>' + esc(T('days_n', { n: d })) + '</option>').join('')
                    + '</select></label>';
            } else if (kind === 'imported') {
                const d = await getJson('action=runs&provider=' + provider);
                const runs = d.success ? d.runs : [];
                part('bar').innerHTML = '<label>' + esc(T('run_label')) + ' <select data-dm="run"><option value="0">' + esc(T('run_all')) + '</option>'
                    + runs.map(r => '<option value="' + r.id + '">' + esc(T('run_option', { date: window.fmtDateTime ? window.fmtDateTime(r.started) : r.started, created: r.created, remaining: r.remaining })) + '</option>').join('')
                    + '</select></label>';
            }
        }

        async function load() {
            part('list').innerHTML = '<p class="dm-dim">' + esc(T('loading')) + '</p>';
            part('note').innerHTML = '';
            let qs = 'action=' + kind;
            if (kind !== 'orphans') qs += '&provider=' + provider;
            if (kind === 'stale') qs += '&days=' + state.days;
            if (kind === 'imported' && state.run) qs += '&run=' + state.run;
            const d = await getJson(qs);
            if (!d.success) { part('list').innerHTML = '<p class="dm-dim">' + esc(d.error) + '</p>'; part('actions').innerHTML = ''; return; }
            state.last = d;

            // Only with somebody to remove: with nobody, there is nothing to warn about.
            if (kind === 'imported' && d.source_imports && d.people.length) part('note').innerHTML = '<div class="dm-warn">' + esc(T('source_imports')) + '</div>';
            if (!d.people.length) {
                part('list').innerHTML = '<p class="dm-dim">' + esc(T('none_' + kind)) + '</p>';
                part('actions').innerHTML = '';
                return;
            }
            part('list').innerHTML = '<table class="dm-table"><thead><tr>'
                + '<th><input type="checkbox" data-dm="all" aria-label="' + esc(T('pick_all')) + '"></th>'
                + '<th>' + esc(T('col_name')) + '</th><th>' + esc(T('col_status')) + '</th>'
                + (kind === 'stale' ? '<th>' + esc(T('col_last_seen')) + '</th>' : '')
                + '<th>' + esc(T('col_tickets')) + '</th><th>' + esc(T('col_assets')) + '</th></tr></thead><tbody>'
                + d.people.map(p => '<tr><td><input type="checkbox" data-dm="pick" value="' + p.id + '"></td>'
                    + '<td>' + esc(p.name) + (p.email && p.email !== p.name ? ' <span class="dm-dim">' + esc(p.email) + '</span>' : '') + '</td>'
                    + '<td>' + esc(T(p.is_active ? 'status_current' : 'status_left')) + '</td>'
                    + (kind === 'stale' ? '<td>' + esc(p.last_seen ? (window.fmtDateTime ? window.fmtDateTime(p.last_seen) : p.last_seen) : T('never')) + '</td>' : '')
                    + '<td>' + p.tickets + '</td><td>' + p.assets + '</td></tr>').join('')
                + '</tbody></table>'
                + (d.total > d.people.length ? '<p class="dm-dim">' + esc(T('more', { n: d.people.length, total: d.total })) + '</p>' : '');
            part('actions').innerHTML = ACTIONS[kind].map(a =>
                '<button type="button" class="btn btn-test' + (a === 'delete' || a === 'remove' ? ' dm-danger' : '') + '" data-dm-do="' + a + '"'
                + (a === 'remove' && d.source_imports ? ' disabled' : '') + '>' + esc(T('do_' + a)) + '</button>').join('')
                + '<span class="dm-dim">' + esc(T('do_hint_' + kind)) + '</span>';
        }

        async function apply(what) {
            const ids = Array.from(el.querySelectorAll('[data-dm="pick"]:checked')).map(c => parseInt(c.value, 10));
            if (!ids.length) { toast(T('pick_first'), 'error'); return; }
            const ok = await showConfirm({
                title: T('confirm_' + what + '_title'),
                message: T('confirm_' + what, { n: ids.length }),
                okLabel: T('do_' + what),
                okClass: what === 'delete' || what === 'remove' ? 'danger' : 'primary'
            });
            if (!ok) return;
            const r = await fetch(API, {
                method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'apply', list: kind, do: what, ids, provider, days: state.days, run: state.run })
            });
            const d = await r.json();
            if (!d.success) { toast(d.error, 'error'); return; }
            toast(T('result', { done: d.done.length, skipped: d.skipped.length }), d.skipped.length ? 'warning' : 'success');
            part('skipped').innerHTML = d.skipped.length
                ? '<div class="dm-skipped"><strong>' + esc(T('skipped_heading')) + '</strong><ul>'
                    + d.skipped.map(s => '<li>' + esc(s.name || ('#' + s.id)) + ': ' + esc(s.reason) + '</li>').join('') + '</ul></div>'
                : '';
            if (kind === 'imported') await drawBar();
            const run = el.querySelector('[data-dm="run"]'); if (run) run.value = String(state.run);
            load();
        }

        el.addEventListener('change', e => {
            if (e.target.matches('[data-dm="all"]')) el.querySelectorAll('[data-dm="pick"]').forEach(c => { c.checked = e.target.checked; });
            if (e.target.matches('[data-dm="days"]')) { state.days = parseInt(e.target.value, 10); part('skipped').innerHTML = ''; load(); }
            if (e.target.matches('[data-dm="run"]')) { state.run = parseInt(e.target.value, 10); part('skipped').innerHTML = ''; load(); }
        }, { signal: ac.signal });
        el.addEventListener('click', e => {
            const b = e.target.closest('[data-dm-do]');
            if (b && !b.disabled) apply(b.dataset.dmDo);
        }, { signal: ac.signal });

        drawBar().then(load);
        return { reload: load };
    }

    window.DirectoryMaintenance = { mount };
})();
