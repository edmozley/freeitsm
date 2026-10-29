/**
 * Domains — the register page (domains/index.php).
 *
 * The whole scoped register is loaded once (a few hundred rows is nothing) and
 * filtered, sorted and counted in the browser, so every sidebar click is
 * instant and the counts beside each view are always the counts of what you
 * would see. The server already removed anything outside the analyst's
 * companies — nothing here hides rows for security, only for convenience.
 *
 * @wiki Domains-Developer-Guide
 */
(function () {
    'use strict';
    const { T, esc, api, fmtDate, grade, gradeOrHealth, daysPill, status, locks, purpose, renewal, tags, money } = window.Dom;

    let rows = [];
    let L = {};                    // lookups
    let multi = false;
    let view = 'all';
    let sort = { key: 'expiry', dir: 1 };
    let accountFilter = null;      // ?account= from Domains → Accounts
    const selected = new Set();

    // ---- views: the questions people ask --------------------------------------
    const VIEWS = [
        ['all',        r => true],
        ['attention',  r => r.attention.length > 0],
        ['expiring30', r => r.days_left !== null && r.days_left >= 0 && r.days_left <= 30],
        ['expiring90', r => r.days_left !== null && r.days_left >= 0 && r.days_left <= 90],
        ['expired',    r => r.days_left !== null && r.days_left < 0],
        ['unlocked',   r => r.transfer_lock === false],
        ['certificate',r => r.ssl_days_left !== null && r.ssl_days_left <= 21],
        ['weak',       r => r.scope !== 'internal' && ['C', 'D', 'F'].includes(r.security_grade)],
        ['lookalikes', r => r.lookalike_count > 0 || r.new_certificate_count > 0],
        ['unchecked',  r => !r.last_check_datetime],
    ];
    const ALERT_VIEWS = ['attention', 'expired', 'unlocked', 'certificate'];

    // ---- columns ---------------------------------------------------------------
    // GH #203: what an internal domain does not have - registration, renewal, locks.
    const NA = '<span class="dom-sub">—</span>';
    const COLS = [
        { key: 'cb', nosort: true, head: () => '<input type="checkbox" id="cbAll">', cell: r => '<input type="checkbox" class="cbRow" data-id="' + r.id + '"' + (selected.has(r.id) ? ' checked' : '') + '>' },
        { key: 'name', head: () => T('col.domain'), val: r => (r.display_name || r.domain_name),
          cell: r => '<div class="dom-name">' + esc(r.display_name || r.domain_name) + '</div>'
                   + '<div class="dom-sub">' + (multi && r.company_name ? esc(r.company_name) + ' · ' : '') + esc(r.scope === 'internal' ? T('scope.internal') : purpose(r.purpose))
                   + (r.display_name ? ' · ' + esc(r.domain_name) : '') + '</div>' + (r.tags ? '<div>' + tags(r.tags) + '</div>' : '') },
        { key: 'status', head: () => T('col.status'), val: r => r.status_name || '', cell: r => status(r.status_name, r.status_colour) },
        { key: 'expiry', head: () => T('col.expires'), val: r => r.days_left === null ? 1e9 : r.days_left,
          cell: r => r.scope === 'internal' ? NA : daysPill(r.days_left) + (r.expiry_date ? '<div class="dom-sub">' + esc(fmtDate(r.expiry_date)) + '</div>' : '') },
        { key: 'renewal', head: () => T('col.renewal'), val: r => r.renewal_mode, cell: r => r.scope === 'internal' ? NA : esc(renewal(r.renewal_mode)) },
        { key: 'registrar', head: () => T('col.registrar'), val: r => (r.supplier_name || r.registrar_name || ''),
          cell: r => esc(r.supplier_name || r.registrar_name || '—') + (r.account_name ? '<div class="dom-sub">' + esc(r.account_name) + '</div>' : '') },
        { key: 'grade', head: () => T('col.grade'), val: r => r.scope === 'internal' ? -2 : (r.security_score === null ? -1 : r.security_score), cell: r => gradeOrHealth(r) },
        { key: 'locks', head: () => T('col.protection'), val: r => (r.transfer_lock ? 2 : 0) + (r.registry_lock ? 1 : 0), cell: r => r.scope === 'internal' ? NA : locks(r) },
        { key: 'ssl', head: () => T('col.certificate'), val: r => r.ssl_days_left === null ? 1e9 : r.ssl_days_left,
          cell: r => r.ssl_days_left === null ? '<span class="dom-sub">—</span>' : daysPill(r.ssl_days_left, 21) },
        { key: 'owner', head: () => T('col.owner'), val: r => r.owner_name || '', cell: r => esc(r.owner_name || '—') },
    ];

    document.addEventListener('DOMContentLoaded', init);

    /**
     * Starts the register: applies ?view=, ?q= and ?account= from the address, loads the domains,
     * opens Add when ?add=1 is set and fires the no-cron scheduled tick.
     */
    async function init() {
        const p = new URLSearchParams(location.search);
        if (p.get('view') && VIEWS.some(v => v[0] === p.get('view'))) view = p.get('view');
        if (p.get('q')) document.getElementById('domSearch').value = p.get('q');
        if (p.get('account')) accountFilter = p.get('account');
        wire();
        await load(true);
        if (p.get('add') === '1') openAdd();
        window.Dom.tick();
    }

    /**
     * Reloads the whole register (with the lookups on the first call), drops selected ids that no longer
     * exist, and redraws.
     */
    async function load(withLookups) {
        try {
            const d = await api('list.php' + (withLookups || !L.statuses ? '?with_lookups=1' : ''));
            rows = d.domains || [];
            multi = !!d.multi_company;
            if (d.lookups && d.lookups.statuses) { L = d.lookups; fillLookups(); }
            const alive = new Set(rows.map(r => r.id));
            [...selected].forEach(id => { if (!alive.has(id)) selected.delete(id); });
            render();
        } catch (e) {
            document.getElementById('domBody').innerHTML = '<tr><td class="dom-empty" colspan="10">' + esc(e.message) + '</td></tr>';
        }
    }

    /**
     * Fills every filter and form drop-down from the lookups - the sidebar filters, Add, Add many, Import
     * and Bulk edit - and shows the company pickers only when the analyst works across several companies.
     */
    function fillLookups() {
        const F = window.Dom.fillSelect;
        const purposes = (L.purposes || []).map(p => ({ id: p, name: purpose(p) }));
        const modes = (L.renewal_modes || []).map(m => ({ id: m, name: renewal(m) }));
        F('fStatus', L.statuses, { blank: T('list.any_status') });
        // GH #203: Scope, offered only once the server knows it (Database Verification).
        const scopes = ['public', 'internal'].map(s => ({ id: s, name: T('scope.' + s) }));
        F('fScope', scopes, { blank: T('list.any_scope') });
        F('aScope', scopes); F('mScope', scopes); F('bScope', scopes, { blank: T('bulk.no_change') });
        F('fPurpose', purposes, { blank: T('list.any_purpose') });
        F('fOwner', [{ id: '0', name: T('list.no_owner') }].concat(L.analysts || []), { blank: T('list.any_owner') });
        F('fRegistrar', L.suppliers, { blank: T('list.any_registrar') });
        const allTags = {};
        rows.forEach(r => String(r.tags || '').split(',').map(x => x.trim()).filter(Boolean).forEach(x => { allTags[x.toLowerCase()] = allTags[x.toLowerCase()] || x; }));
        F('fTag', Object.values(allTags).sort().map(x => ({ id: x, name: x })), { blank: T('list.any_tag') });
        if (multi && (L.companies || []).length > 1) {
            document.getElementById('fCompany').style.display = '';
            F('fCompany', L.companies, { blank: T('list.any_company') });
        }
        // Forms
        F('aPurpose', purposes); F('mPurpose', purposes); F('bPurpose', purposes, { blank: T('bulk.no_change') });
        F('aStatus', L.statuses.filter(s => s.is_active == 1)); F('mStatus', L.statuses.filter(s => s.is_active == 1));
        F('bStatus', L.statuses, { blank: T('bulk.no_change') });
        F('aOwner', L.analysts, { blank: T('field.no_owner') }); F('mOwner', L.analysts, { blank: T('field.no_owner') });
        F('bOwner', [{ id: '0', name: T('bulk.clear_owner') }].concat(L.analysts), { blank: T('bulk.no_change') });
        F('aRenewal', modes); F('bRenewal', modes, { blank: T('bulk.no_change') });
        F('bRegistrar', L.suppliers, { blank: T('bulk.no_change') });
        if (multi && (L.companies || []).length > 1) {
            ['aCompany', 'mCompany', 'impCompany'].forEach(id => { F(id, L.companies); document.getElementById(id).value = String(L.active_company || ''); });
            ['aCompanyWrap', 'mCompanyWrap', 'impCompanyWrap'].forEach(id => { document.getElementById(id).style.display = ''; });
        }
        document.getElementById('aRenewal').value = 'unknown';
    }

    // ---- filtering + rendering --------------------------------------------------
    /** Returns the rows that pass the current view, account filter, search box and sidebar filters. */
    function filtered() {
        const q = document.getElementById('domSearch').value.trim().toLowerCase();
        /** Reads the value of a filter control by id. */
        const v = (id) => document.getElementById(id).value;
        const fn = VIEWS.find(x => x[0] === view)[1];
        return rows.filter(r => {
            if (!fn(r)) return false;
            if (accountFilter && String(r.registrar_account_id) !== accountFilter) return false;
            if (q && ![r.domain_name, r.display_name, r.registrar_name, r.supplier_name, r.tags, r.company_name, r.owner_name, r.status_name]
                .some(x => x && String(x).toLowerCase().includes(q))) return false;
            if (v('fStatus') && String(r.status_id) !== v('fStatus')) return false;
            if (v('fScope') && r.scope !== v('fScope')) return false;
            if (v('fPurpose') && r.purpose !== v('fPurpose')) return false;
            if (v('fOwner') === '0' && r.owner_analyst_id) return false;
            if (v('fOwner') && v('fOwner') !== '0' && String(r.owner_analyst_id) !== v('fOwner')) return false;
            if (v('fRegistrar') && String(r.registrar_supplier_id) !== v('fRegistrar')) return false;
            if (v('fTag') && !String(r.tags || '').toLowerCase().split(',').map(x => x.trim()).includes(v('fTag').toLowerCase())) return false;
            // A domain with no company belongs to the Default company.
            if (v('fCompany') && String(r.tenant_id === null ? defaultCompanyId() : r.tenant_id) !== v('fCompany')) return false;
            return true;
        });
    }
    /** Returns the id of the Default company, which a domain with no company belongs to for the company filter. */
    function defaultCompanyId() {
        const d = (L.companies || []).find(c => c.is_default);
        return d ? d.id : '';
    }

    /** Redraws the views, stats, table head and sorted body, the row count and the select-all box. */
    function render() {
        renderViews();
        renderStats();
        const list = filtered();
        const col = COLS.find(c => c.key === sort.key) || COLS[3];
        list.sort((a, b) => {
            const x = col.val(a), y = col.val(b);
            return (x < y ? -1 : x > y ? 1 : 0) * sort.dir || String(a.domain_name).localeCompare(b.domain_name);
        });
        document.getElementById('domHead').innerHTML = COLS.map(c =>
            '<th class="' + (c.key === 'cb' ? 'cb nosort' : '') + '" data-key="' + c.key + '">' + c.head()
            + (c.key === sort.key ? '<span class="arrow">' + (sort.dir > 0 ? '▲' : '▼') + '</span>' : '') + '</th>').join('');
        const body = document.getElementById('domBody');
        if (!rows.length) {
            body.innerHTML = '<tr><td colspan="' + COLS.length + '"><div class="dom-empty"><h3>' + esc(T('list.empty_title')) + '</h3>'
                + '<p>' + esc(T('list.empty_body')) + '</p><button type="button" class="dom-btn primary" onclick="document.getElementById(\'btnAdd\').click()">'
                + esc(T('list.add')) + '</button></div></td></tr>';
        } else if (!list.length) {
            body.innerHTML = '<tr><td colspan="' + COLS.length + '" class="dom-empty">' + esc(T('list.no_match')) + '</td></tr>';
        } else {
            body.innerHTML = list.map(r => '<tr data-id="' + r.id + '" class="' + (selected.has(r.id) ? 'selected' : '') + '">'
                + COLS.map(c => '<td class="' + (c.key === 'cb' ? 'cb' : '') + '">' + c.cell(r) + '</td>').join('') + '</tr>').join('');
        }
        document.getElementById('domCount').textContent = list.length === rows.length
            ? T('list.count', { n: rows.length }) : T('list.count_of', { n: list.length, total: rows.length });
        const all = document.getElementById('cbAll');
        if (all) all.checked = list.length > 0 && list.every(r => selected.has(r.id));
        renderSelBar();
    }

    /** Draws the sidebar view buttons with their counts, flagging alert views that have anything in them. */
    function renderViews() {
        document.getElementById('domViews').innerHTML = VIEWS.map(([k, fn]) => {
            const n = rows.filter(fn).length;
            const alert = ALERT_VIEWS.includes(k) && n > 0 && k !== 'all';
            return '<button type="button" data-view="' + k + '" class="' + (view === k ? 'active' : '') + '">'
                + esc(T('view.' + k)) + '<span class="count' + (alert ? ' alert' : '') + '">' + n + '</span></button>';
        }).join('');
    }

    /**
     * Draws the clickable stat tiles (total, attention, expiring, expired, unlocked) and the annual cost
     * when any domain has one.
     */
    function renderStats() {
        /** Counts the rows that match a test. */
        const c = fn => rows.filter(fn).length;
        const cost = rows.reduce((s, r) => s + (r.annual_cost || 0), 0);
        const cur = (rows.find(r => r.currency) || {}).currency || null;
        const stats = [
            [rows.length, T('stat.total'), 'accent', 'all'],
            [c(r => r.attention.length > 0), T('stat.attention'), c(r => r.attention.length > 0) ? 'red' : 'green', 'attention'],
            [c(r => r.days_left !== null && r.days_left >= 0 && r.days_left <= 30), T('stat.expiring30'), 'amber', 'expiring30'],
            [c(r => r.days_left !== null && r.days_left < 0), T('stat.expired'), c(r => r.days_left !== null && r.days_left < 0) ? 'red' : 'green', 'expired'],
            [c(r => r.transfer_lock === false), T('stat.unlocked'), c(r => r.transfer_lock === false) ? 'red' : 'green', 'unlocked'],
        ];
        let html = stats.map(([v, l, cls, vw]) => '<div class="dom-stat clickable ' + cls + '" data-view="' + vw + '"><div class="v">' + v + '</div><div class="l">' + esc(l) + '</div></div>').join('');
        if (cost > 0) html += '<div class="dom-stat accent"><div class="v" style="font-size:20px">' + esc(money(cost, cur)) + '</div><div class="l">' + esc(T('stat.annual_cost')) + '</div></div>';
        document.getElementById('domStats').innerHTML = html;
    }

    /** Shows or hides the selection bar and updates its count. */
    function renderSelBar() {
        const bar = document.getElementById('domSelBar');
        bar.classList.toggle('show', selected.size > 0);
        document.getElementById('domSelCount').textContent = T('list.selected', { n: selected.size });
    }

    // ---- events -----------------------------------------------------------------
    /**
     * Wires the register's controls once: search, filters, views, stats, sorting, row selection and clicks,
     * the right-click menu, and the Add, Add many, Import and bulk buttons.
     */
    function wire() {
        document.getElementById('domSearch').addEventListener('input', render);
        ['fStatus', 'fScope', 'fPurpose', 'fOwner', 'fRegistrar', 'fTag', 'fCompany'].forEach(id => document.getElementById(id).addEventListener('change', render));
        // Purpose does not apply to an internal domain (GH #203): hidden while Internal is chosen.
        const scopeToggle = (sel, wrap, hint) => document.getElementById(sel).addEventListener('change', () => {
            const internal = document.getElementById(sel).value === 'internal';
            document.getElementById(wrap).style.display = internal ? 'none' : '';
            if (hint) document.getElementById(hint).style.display = internal ? '' : 'none';
        });
        scopeToggle('aScope', 'aPurposeWrap', 'aScopeHint'); scopeToggle('mScope', 'mPurposeWrap', null);
        document.getElementById('domClearFilters').addEventListener('click', () => {
            ['fStatus', 'fScope', 'fPurpose', 'fOwner', 'fRegistrar', 'fTag', 'fCompany'].forEach(id => { document.getElementById(id).value = ''; });
            document.getElementById('domSearch').value = ''; view = 'all'; accountFilter = null; render();
        });
        document.getElementById('domViews').addEventListener('click', e => {
            const b = e.target.closest('button[data-view]'); if (!b) return;
            view = b.dataset.view; render();
        });
        document.getElementById('domStats').addEventListener('click', e => {
            const b = e.target.closest('[data-view]'); if (!b) return;
            view = b.dataset.view; render();
        });
        document.getElementById('domHead').addEventListener('click', e => {
            if (e.target.id === 'cbAll') {
                const list = filtered();
                if (e.target.checked) list.forEach(r => selected.add(r.id)); else list.forEach(r => selected.delete(r.id));
                render(); return;
            }
            const th = e.target.closest('th[data-key]'); if (!th || th.classList.contains('nosort')) return;
            sort = { key: th.dataset.key, dir: sort.key === th.dataset.key ? -sort.dir : 1 }; render();
        });
        wireCtx();
        document.getElementById('domBody').addEventListener('click', e => {
            const cb = e.target.closest('.cbRow');
            if (cb) {
                const id = parseInt(cb.dataset.id, 10);
                if (cb.checked) selected.add(id); else selected.delete(id);
                cb.closest('tr').classList.toggle('selected', cb.checked);
                renderSelBar(); return;
            }
            if (e.target.closest('td.cb, a, button')) return;
            const tr = e.target.closest('tr[data-id]');
            if (tr) location.href = 'view.php?id=' + tr.dataset.id;
        });
        document.querySelectorAll('[data-close]').forEach(b => b.addEventListener('click', () => window.Dom.closeModal(b.dataset.close)));
        document.getElementById('btnAdd').addEventListener('click', openAdd);
        document.getElementById('aSave').addEventListener('click', saveAdd);
        document.getElementById('aName').addEventListener('keydown', e => { if (e.key === 'Enter') saveAdd(); });
        document.getElementById('btnAddMany').addEventListener('click', openMany);
        document.getElementById('mSave').addEventListener('click', saveMany);
        document.getElementById('btnImport').addEventListener('click', openImport);
        document.getElementById('impFile').addEventListener('change', readCsv);
        document.getElementById('impGo').addEventListener('click', runImport);
        document.getElementById('btnClearSel').addEventListener('click', () => { selected.clear(); render(); });
        document.getElementById('btnBulkEdit').addEventListener('click', () => window.Dom.openModal('mBulk'));
        document.getElementById('bSave').addEventListener('click', saveBulk);
        document.getElementById('btnBulkRefresh').addEventListener('click', () => processIds([...selected], T('progress.refreshing')));
        document.getElementById('btnBulkDelete').addEventListener('click', bulkDelete);
    }

    // ---- add one ------------------------------------------------------------------
    /** Clears and opens the Add domain dialog, focusing the name box. */
    function openAdd() {
        document.getElementById('aName').value = '';
        document.getElementById('aTags').value = '';
        window.Dom.openModal('mAdd');
        setTimeout(() => document.getElementById('aName').focus(), 80);
    }

    /**
     * Saves one new domain (the server looks it up as it saves) and opens its page, warning when the lookup failed.
     */
    async function saveAdd() {
        const btn = document.getElementById('aSave');
        const name = document.getElementById('aName').value.trim();
        if (!name) { showToast(T('add.need_name'), 'error'); return; }
        btn.disabled = true; btn.textContent = T('add.looking_up');
        try {
            const body = {
                domain_name: name,
                scope: document.getElementById('aScope').value,
                purpose: document.getElementById('aPurpose').value,
                status_id: document.getElementById('aStatus').value || null,
                owner_analyst_id: document.getElementById('aOwner').value || null,
                renewal_mode: document.getElementById('aRenewal').value,
                tags: document.getElementById('aTags').value,
            };
            if (multi && document.getElementById('aCompanyWrap').style.display !== 'none') body.company_id = document.getElementById('aCompany').value;
            const d = await api('save.php', body);
            if (d.lookup && d.lookup.ok === false) showToast(T('add.lookup_failed', { error: d.lookup.error }), 'warning');
            location.href = 'view.php?id=' + d.id;
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            btn.disabled = false; btn.textContent = T('list.add');
        }
    }

    // ---- add many -----------------------------------------------------------------
    /** Resets and opens the Add many dialog. */
    function openMany() {
        document.getElementById('mText').value = '';
        document.getElementById('manyForm').style.display = '';
        document.getElementById('manyProgress').style.display = 'none';
        document.getElementById('mSave').style.display = '';
        window.Dom.openModal('mMany');
        setTimeout(() => document.getElementById('mText').focus(), 80);
    }

    /**
     * Sends the pasted list to bulk_add.php with the chosen defaults, then shows what was added and skipped
     * and looks the new domains up in batches.
     */
    async function saveMany() {
        const text = document.getElementById('mText').value;
        if (!text.trim()) { showToast(T('many.need_text'), 'error'); return; }
        const btn = document.getElementById('mSave');
        btn.disabled = true;
        try {
            const defaults = { scope: document.getElementById('mScope').value, purpose: document.getElementById('mPurpose').value };
            if (document.getElementById('mStatus').value) defaults.status_id = document.getElementById('mStatus').value;
            if (document.getElementById('mOwner').value) defaults.owner_analyst_id = document.getElementById('mOwner').value;
            if (document.getElementById('mTags').value.trim()) defaults.tags = document.getElementById('mTags').value;
            const body = { text, defaults };
            if (multi && document.getElementById('mCompanyWrap').style.display !== 'none') body.company_id = document.getElementById('mCompany').value;
            const d = await api('bulk_add.php', body);
            document.getElementById('manyForm').style.display = 'none';
            btn.style.display = 'none';
            await showAddResult('manyProgress', d);
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            btn.disabled = false;
        }
    }

    /** "Added 42, skipped 3" then the lookups, in batches, with a progress bar. */
    async function showAddResult(boxId, d) {
        const box = document.getElementById(boxId);
        box.style.display = '';
        const skipped = (d.skipped || []).map(s => '<div><strong>' + esc(s.input) + '</strong> — ' + esc(s.reason) + '</div>').join('');
        box.innerHTML = '<p><strong>' + esc(T('many.added', { n: d.added.length })) + '</strong>'
            + (d.skipped.length ? ' ' + esc(T('many.skipped', { n: d.skipped.length })) : '') + '</p>'
            + (skipped ? '<div class="dom-skip-list">' + skipped + '</div>' : '')
            + '<div id="' + boxId + 'Bar"></div>';
        await load(false);
        if (d.added.length) await processIds(d.added.map(a => a.id), T('progress.looking_up'), boxId + 'Bar');
    }

    /**
     * Look up + check domains in batches of five, drawing progress. Anything
     * not finished (the tab closed, the network dropped) is simply picked up
     * by the scheduled run — a never-looked-up domain is first in its queue.
     */
    async function processIds(ids, label, holderId) {
        if (!ids.length) return;
        let holder = holderId ? document.getElementById(holderId) : null;
        let toast = null;
        if (!holder) {
            toast = document.createElement('div');
            toast.className = 'dom-card';
            toast.style.cssText = 'position:fixed;right:20px;bottom:20px;z-index:2100;padding:14px 16px;width:320px';
            document.body.appendChild(toast);
            holder = toast;
        }
        let done = 0, failed = 0;
        /** Redraws the progress line and bar. */
        const draw = () => {
            holder.innerHTML = '<div style="font-size:13px">' + esc(label) + ' ' + done + ' / ' + ids.length + (failed ? ' · ' + esc(T('progress.failed', { n: failed })) : '') + '</div>'
                + '<div class="dom-progress"><div style="width:' + Math.round(done / ids.length * 100) + '%"></div></div>';
        };
        draw();
        for (let i = 0; i < ids.length; i += 5) {
            try {
                const d = await api('process.php', { ids: ids.slice(i, i + 5) });
                (d.results || []).forEach(r => { if (r.error || r.lookup_ok === false) failed++; });
            } catch (e) { failed += Math.min(5, ids.length - i); }
            done = Math.min(ids.length, i + 5);
            draw();
        }
        holder.innerHTML += '<div class="dom-hint">' + esc(T('progress.done')) + '</div>';
        if (toast) setTimeout(() => toast.remove(), 4000);
        await load(false);
    }

    // ---- CSV import ---------------------------------------------------------------
    const IMPORT_FIELDS = [
        ['domain_name', ['domain', 'domain name', 'name', 'fqdn', 'url']],
        ['expiry_date', ['expiry', 'expiry date', 'expires', 'expiration', 'expiration date', 'renewal date', 'renew by']],
        ['registration_date', ['registered', 'registration date', 'created', 'creation date', 'reg. date', 'reg date']],
        ['registrar', ['registrar', 'provider']],
        ['status', ['status']],
        ['owner', ['owner', 'responsible', 'contact', 'tech', 'technical contact']],
        ['scope', ['scope']],   // GH #203: public | internal
        ['purpose', ['purpose', 'use', 'type']],
        ['renewal_mode', ['renewal', 'renewal mode', 'auto renew', 'auto-renew', 'autorenew']],
        ['tags', ['tags', 'tag', 'group', 'category', 'client', 'brand']],
        ['cost', ['cost', 'price', 'renewal cost', 'annual cost']],
        ['currency', ['currency']],
        ['cost_centre', ['cost centre', 'cost center']],
        ['registrant_name', ['registrant', 'registrant name', 'holder']],
        ['dns_provider', ['dns', 'dns provider', 'nameserver provider']],
        ['hosting_provider', ['hosting', 'host', 'hosting provider', 'web host']],
        ['notes', ['notes', 'comments', 'description']],
    ];
    let csv = { head: [], rows: [] };

    /** Resets the CSV import dialog to its first step and opens it. */
    function openImport() {
        document.getElementById('impFile').value = '';
        document.getElementById('impStep1').style.display = '';
        document.getElementById('impStep2').style.display = 'none';
        document.getElementById('impProgress').style.display = 'none';
        document.getElementById('impGo').style.display = 'none';
        window.Dom.openModal('mImport');
    }

    /** RFC 4180-ish: quoted fields, doubled quotes, commas or semicolons. */
    function parseCsv(text) {
        text = text.replace(/^﻿/, '');
        const firstLine = text.split(/\r?\n/)[0] || '';
        const sep = (firstLine.split(';').length > firstLine.split(',').length) ? ';' : ',';
        const out = []; let row = [], field = '', q = false;
        for (let i = 0; i < text.length; i++) {
            const c = text[i];
            if (q) {
                if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
                else if (c === '"') q = false;
                else field += c;
            } else if (c === '"') q = true;
            else if (c === sep) { row.push(field); field = ''; }
            else if (c === '\n' || c === '\r') {
                if (c === '\r' && text[i + 1] === '\n') i++;
                row.push(field); field = '';
                if (row.some(x => x.trim() !== '')) out.push(row);
                row = [];
            } else field += c;
        }
        row.push(field);
        if (row.some(x => x.trim() !== '')) out.push(row);
        return out;
    }

    /** Reads the chosen CSV file, splits it into a header and rows, and moves on to column mapping. */
    function readCsv() {
        const f = document.getElementById('impFile').files[0];
        if (!f) return;
        const reader = new FileReader();
        reader.onload = () => {
            const all = parseCsv(String(reader.result));
            if (all.length < 2) { showToast(T('import.too_short'), 'error'); return; }
            csv = { head: all[0].map(h => h.trim()), rows: all.slice(1) };
            drawMapping();
        };
        reader.readAsText(f);
    }

    /**
     * Draws a column picker for each import field, pre-choosing a column whose header matches a known name,
     * and a preview of the first five rows.
     */
    function drawMapping() {
        const opts = '<option value="">' + esc(T('import.ignore')) + '</option>' + csv.head.map((h, i) => '<option value="' + i + '">' + esc(h || ('#' + (i + 1))) + '</option>').join('');
        document.getElementById('impMap').innerHTML = IMPORT_FIELDS.map(([key, guesses]) => {
            const guess = csv.head.findIndex(h => guesses.includes(h.toLowerCase()));
            return '<div class="form-group"><label>' + esc(T('import.field.' + key)) + (key === 'domain_name' ? ' *' : '') + '</label>'
                + '<select data-field="' + key + '">' + opts.replace('value="' + guess + '"', 'value="' + guess + '" selected') + '</select></div>';
        }).join('');
        const sample = csv.rows.slice(0, 5);
        document.getElementById('impPreview').innerHTML = '<table class="dom-table"><thead><tr>' + csv.head.map(h => '<th class="nosort">' + esc(h) + '</th>').join('') + '</tr></thead><tbody>'
            + sample.map(r => '<tr>' + csv.head.map((_, i) => '<td>' + esc(r[i] || '') + '</td>').join('') + '</tr>').join('') + '</tbody></table>'
            + '<div class="dom-hint">' + esc(T('import.rows', { n: csv.rows.length })) + '</div>';
        document.getElementById('impStep1').style.display = 'none';
        document.getElementById('impStep2').style.display = '';
        document.getElementById('impGo').style.display = '';
    }

    /**
     * Builds one object per CSV row from the chosen mapping, sends them to bulk_add.php, then shows the result
     * and looks the new domains up. Needs the domain column mapped.
     */
    async function runImport() {
        const map = {};
        document.querySelectorAll('#impMap select').forEach(s => { if (s.value !== '') map[s.dataset.field] = parseInt(s.value, 10); });
        if (map.domain_name === undefined) { showToast(T('import.need_domain'), 'error'); return; }
        const payload = csv.rows.map(r => {
            const o = {};
            Object.entries(map).forEach(([k, i]) => { const v = (r[i] || '').trim(); if (v !== '') o[k] = v; });
            return o;
        });
        const btn = document.getElementById('impGo');
        btn.disabled = true;
        try {
            const body = { rows: payload };
            if (multi && document.getElementById('impCompanyWrap').style.display !== 'none') body.company_id = document.getElementById('impCompany').value;
            const d = await api('bulk_add.php', body);
            document.getElementById('impStep2').style.display = 'none';
            btn.style.display = 'none';
            await showAddResult('impProgress', d);
        } catch (e) {
            showToast(e.message, 'error');
        } finally { btn.disabled = false; }
    }

    // ---- bulk edit + delete ---------------------------------------------------------
    /**
     * Applies the Bulk edit fields that were changed to every selected domain through bulk_update.php.
     *
     * Owner "0" clears the owner; a blank field is left as it is.
     */
    async function saveBulk() {
        const fields = {};
        /** Adds a field to the update when its control is not blank. */
        const put = (id, key) => { const v = document.getElementById(id).value; if (v !== '') fields[key] = v; };
        put('bStatus', 'status_id'); put('bScope', 'scope'); put('bPurpose', 'purpose'); put('bRenewal', 'renewal_mode'); put('bRegistrar', 'registrar_supplier_id'); put('bMonitoring', 'monitoring_enabled');
        const o = document.getElementById('bOwner').value;
        if (o === '0') fields.owner_analyst_id = null; else if (o !== '') fields.owner_analyst_id = o;
        if (document.getElementById('bTags').value.trim()) fields.tags = document.getElementById('bTags').value;
        if (!Object.keys(fields).length) { showToast(T('bulk.nothing'), 'error'); return; }
        try {
            const d = await api('bulk_update.php', { ids: [...selected], fields });
            showToast(T('bulk.done', { n: d.updated }) + (d.failed.length ? ' ' + T('bulk.some_failed', { n: d.failed.length }) : ''), d.failed.length ? 'warning' : 'success');
            window.Dom.closeModal('mBulk');
            await load(false);
        } catch (e) { showToast(e.message, 'error'); }
    }

    /** Deletes every selected domain after a confirm, then clears the selection and reloads. */
    async function bulkDelete() {
        const ok = await showConfirm({ title: T('bulk.delete_title'), message: T('bulk.delete_body', { n: selected.size }), okLabel: T('bulk.delete_ok'), okClass: 'danger' });
        if (!ok) return;
        try {
            const d = await api('delete.php', { ids: [...selected] });
            showToast(T('bulk.deleted', { n: d.deleted }), 'success');
            selected.clear();
            await load(false);
        } catch (e) { showToast(e.message, 'error'); }
    }

    // ---------------------------------------------------------------- right-click (3.0.0)
    // Right-click a domain for what you would otherwise open it to do. One
    // menu element, built once; submenus fly out and flip at the screen edge -
    // the Tasks menu's shape (assets/js/tasks-ctx-menu.js). Every action goes
    // through the same endpoints the domain's own page uses, so the rules and
    // the history rows are the same.
    let ctxId = null;
    const ICON = {
        open: '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
        edit: '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>',
        refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
        check: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/>',
        status: '<circle cx="12" cy="12" r="9"/>',
        owner: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
        links: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        globe: '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        trash: '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/>',
    };
    /** Wraps one of the menu's icon paths in an SVG. */
    const svg = k => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ICON[k] + '</svg>';

    /** Returns the right-click menu element, creating it and its click handler the first time. */
    function ctxMenu() {
        let m = document.getElementById('domCtx');
        if (m) return m;
        m = document.createElement('div');
        m.id = 'domCtx';
        m.className = 'dom-ctx';
        m.setAttribute('role', 'menu');
        m.hidden = true;
        document.body.appendChild(m);
        m.addEventListener('click', onCtxClick);
        return m;
    }

    /** Builds one right-click menu item for an action. */
    function item(action, icon, label, extra) {
        return '<button type="button" class="dom-ctx-item' + (extra || '') + '" role="menuitem" data-ctx="' + action + '">' + svg(icon) + '<span class="dom-ctx-label">' + esc(label) + '</span></button>';
    }

    /** Builds a menu row that flies out a submenu of options. */
    function sub(label, icon, opts) {
        return '<div class="dom-ctx-parent" role="none"><button type="button" class="dom-ctx-item" role="menuitem" aria-haspopup="true">' + svg(icon)
            + '<span class="dom-ctx-label">' + esc(label) + '</span><span class="dom-ctx-arrow">&rsaquo;</span></button><div class="dom-ctx-sub" role="menu">' + opts + '</div></div>';
    }

    /** Builds one submenu choice that sets a field to a value, ticked when it is the current value. */
    function opt(field, value, label, current, swatch) {
        return '<button type="button" class="dom-ctx-item' + (current ? ' current' : '') + '" role="menuitemradio" aria-checked="' + (current ? 'true' : 'false') + '" data-field="' + field + '" data-value="' + esc(value) + '">'
            + (swatch ? '<span class="dom-ctx-swatch" style="background:' + esc(swatch) + '"></span>' : '') + '<span class="dom-ctx-label">' + esc(label) + '</span>'
            + (current ? '<span class="dom-ctx-check">✓</span>' : '') + '</button>';
    }

    /**
     * Opens the right-click menu for a domain at the pointer, kept on screen, with submenus flipped near
     * the right or bottom edge.
     */
    function openCtx(e, id) {
        const r = rows.find(x => x.id === id);
        if (!r) return;
        e.preventDefault();
        ctxId = id;
        const name = r.display_name || r.domain_name;
        const statuses = (L.statuses || []).filter(s => s.is_active == 1 || String(s.id) === String(r.status_id));
        const m = ctxMenu();
        m.innerHTML = '<div class="dom-ctx-head">' + esc(name) + '</div>'
            + item('open', 'open', T('ctx.open')) + item('open_new', 'open', T('ctx.open_new')) + item('edit', 'edit', T('ctx.edit'))
            + '<div class="dom-ctx-sep"></div>'
            + item('refresh', 'refresh', T('ctx.refresh')) + item('check', 'check', T('ctx.check'))
            + '<div class="dom-ctx-sep"></div>'
            + sub(T('ctx.status'), 'status', statuses.map(s => opt('status_id', s.id, s.name, String(s.id) === String(r.status_id), s.colour)).join(''))
            + sub(T('ctx.owner'), 'owner', opt('owner_analyst_id', '', T('ctx.no_owner'), !r.owner_analyst_id)
                + (L.analysts || []).map(a => opt('owner_analyst_id', a.id, a.name, String(a.id) === String(r.owner_analyst_id))).join(''))
            + (window.DOM_ME && String(window.DOM_ME) !== String(r.owner_analyst_id) ? item('assign_me', 'owner', T('ctx.assign_me')) : '')
            + '<div class="dom-ctx-sep"></div>'
            + item('connections', 'links', T('ctx.connections')) + item('visit', 'globe', T('ctx.visit')) + item('copy', 'copy', T('ctx.copy'))
            + '<div class="dom-ctx-sep"></div>'
            + item('delete', 'trash', T('ctx.delete'), ' danger');
        m.hidden = false;
        const mw = m.offsetWidth, mh = m.offsetHeight;
        m.style.left = Math.max(6, Math.min(e.clientX, window.innerWidth - mw - 6)) + 'px';
        m.style.top = Math.max(6, Math.min(e.clientY, window.innerHeight - mh - 6)) + 'px';
        m.classList.toggle('flip-sub', e.clientX + mw + 220 > window.innerWidth);
        m.classList.toggle('flip-sub-v', e.clientY > window.innerHeight * 0.55);
        const first = m.querySelector('.dom-ctx-item');
        if (first) first.focus({ preventScroll: true });
    }

    /** Hides the right-click menu and forgets which domain it was for. */
    function closeCtx() {
        const m = document.getElementById('domCtx');
        if (m) m.hidden = true;
        ctxId = null;
    }

    /**
     * Carries out the right-click menu choice: a status or owner change, open, edit, connections, visit,
     * copy, assign to me, refresh, check or delete - through the same endpoints the domain's page uses.
     */
    async function onCtxClick(e) {
        const b = e.target.closest('.dom-ctx-item');
        if (!b || ctxId === null) return;
        if (b.getAttribute('aria-haspopup')) return;               // a submenu's own row
        const id = ctxId, r = rows.find(x => x.id === id);
        if (!r) return;
        const name = r.display_name || r.domain_name;
        closeCtx();
        try {
            if (b.dataset.field) {
                const v = b.dataset.value === '' ? null : Number(b.dataset.value);
                await api('bulk_update.php', { ids: [id], fields: { [b.dataset.field]: v } });
                showToast(T('ctx.updated', { name }), 'success');
                await load(false);
                return;
            }
            switch (b.dataset.ctx) {
                case 'open':        location.href = 'view.php?id=' + id; return;
                case 'open_new':    window.open('view.php?id=' + id, '_blank', 'noopener'); return;
                case 'edit':        location.href = 'view.php?id=' + id + '&edit=1'; return;
                case 'connections': location.href = 'view.php?id=' + id + '&tab=connections'; return;
                case 'visit':       window.open('https://' + r.domain_name, '_blank', 'noopener'); return;
                case 'copy':
                    if (await window.copyToClipboard(r.domain_name)) showToast(T('ctx.copied', { name: r.domain_name }), 'success');
                    return;
                case 'assign_me':
                    await api('bulk_update.php', { ids: [id], fields: { owner_analyst_id: Number(window.DOM_ME) } });
                    showToast(T('ctx.updated', { name }), 'success');
                    await load(false);
                    return;
                case 'refresh':
                case 'check': {
                    showToast(T('ctx.working', { name }), 'info');
                    const lookup = b.dataset.ctx === 'refresh';
                    const d = await api('process.php', { ids: [id], lookup, check: !lookup });
                    const res = (d.results || [])[0] || {};
                    if (lookup && res.lookup_ok === false) showToast(res.lookup_error, 'warning');
                    else showToast(T('ctx.updated', { name }), 'success');
                    await load(false);
                    return;
                }
                case 'delete': {
                    const ok = await showConfirm({ title: T('page.delete_title'), message: T('page.delete_body', { name: r.domain_name }), okLabel: T('bulk.delete_ok'), okClass: 'danger' });
                    if (!ok) return;
                    await api('delete.php', { id });
                    selected.delete(id);
                    await load(false);
                    return;
                }
            }
        } catch (err) { showToast(err.message, 'error'); }
    }

    /**
     * Wires the right-click menu: opening it on a row, closing it on an outside click, Escape, scroll or
     * resize, and arrow-key movement in and out of submenus.
     */
    function wireCtx() {
        document.getElementById('domBody').addEventListener('contextmenu', e => {
            const tr = e.target.closest('tr[data-id]');
            if (!tr) return;
            openCtx(e, parseInt(tr.dataset.id, 10));
        });
        document.addEventListener('click', e => { if (!e.target.closest('#domCtx')) closeCtx(); });
        document.addEventListener('keydown', e => {
            const m = document.getElementById('domCtx');
            if (!m || m.hidden) return;
            if (e.key === 'Escape') { closeCtx(); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                // Only the top level and the submenu the focus is in.
                const scope = document.activeElement && document.activeElement.closest('.dom-ctx-sub') || m;
                const items = [...scope.querySelectorAll(':scope > .dom-ctx-item, :scope > .dom-ctx-parent > .dom-ctx-item')];
                const i = items.indexOf(document.activeElement);
                const n = items[(i + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length];
                if (n) { e.preventDefault(); n.focus(); }
            }
            if (e.key === 'ArrowRight' && document.activeElement && document.activeElement.getAttribute('aria-haspopup')) {
                const f = document.activeElement.parentElement.querySelector('.dom-ctx-sub .dom-ctx-item');
                if (f) { e.preventDefault(); f.focus(); }
            }
            if (e.key === 'ArrowLeft' && document.activeElement && document.activeElement.closest('.dom-ctx-sub')) {
                e.preventDefault(); document.activeElement.closest('.dom-ctx-parent').querySelector(':scope > .dom-ctx-item').focus();
            }
        });
        document.addEventListener('scroll', closeCtx, true);
        window.addEventListener('resize', closeCtx);
    }
})();
