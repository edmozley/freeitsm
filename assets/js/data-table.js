/**
 * FreeITSM shared data-table engine
 *
 * One implementation of the full-screen, Excel-style table view used by the
 * asset, tasks, calendar and change-management modules. Everything that used to
 * be copy-pasted per module lives here: column show/hide + drag-reorder
 * (persisted per-user via user_preferences), click-to-sort, search across
 * visible columns, per-column tickbox filter, CSV (and optional PDF) export,
 * and optional inline cell editing.
 *
 * A module supplies only what's actually module-specific via createDataTable():
 *   - its COLUMNS catalogue (the single source of truth for the grid)
 *   - how to load() its rows
 *   - the accent colour + a preference key + the export filename
 *   - optionally: onSaveCell (inline editing), onRowClick, getLookups, pdf
 *
 * Styling is shared (assets/css/data-table.css); the accent is injected at
 * runtime onto :root as --dt-accent so even body-appended popovers pick it up.
 *
 * Usage:
 *   const table = createDataTable({ ...config });
 *   // table.reload(), table.render(), table.findRow(id), table.getViewRows()
 *
 * Column shape:
 *   { key, label, type: 'string'|'number'|'date', defaultVisible, defaultOrder,
 *     value?: row => comparable,   // sort key (default row[key])
 *     display?: row => string,     // shown / searched / filtered / exported
 *     format?: raw => string,      // legacy single-arg formatter (asset compat)
 *     editable?: false | { kind, ... } }
 *
 * editable kinds: 'text' | 'date' | 'datetime' | 'bool' |
 *   { kind:'lookup', listKey, valueKey, labelKey, allowNull?, nullLabel?, colourKey? }
 *
 * @wiki Saved-Table-Views-Developer-Guide
 */
(function () {
    'use strict';

    const DEFAULT_ELS = {
        search: 'dtSearch',
        columnsBtn: 'dtColumnsBtn',
        resetBtn: 'dtResetBtn',
        csvBtn: 'dtCsvBtn',
        pdfBtn: 'dtPdfBtn',
        count: 'dtCount',
        head: 'dtHead',
        body: 'dtBody',
        table: 'dtTable',
    };

    /**
     * Builds one data table from a module's config and wires it up when the page has loaded.
     *
     * Exposed as window.createDataTable. Holds the column, sort, filter and search state in a closure.
     * @return object  reload, render, findRow(id), getViewRows and getAllRows
     */
    function createDataTable(config) {
        const els = Object.assign({}, DEFAULT_ELS, config.els || {});
        const columns = config.columns;
        const colByKey = Object.fromEntries(columns.map(c => [c.key, c]));
        const prefApi = config.prefApi || '../../api/system/';
        const prefKey = config.prefKey;
        const noun = config.noun || 'row';
        const defaultSort = config.defaultSort || { key: columns[0].key, dir: 'asc' };
        const rowId = config.rowId || (r => r.id);
        const editable = typeof config.onSaveCell === 'function';

        // --- State ----------------------------------------------------
        let allRows = [];
        let columnState = [];
        let sort = { key: defaultSort.key, dir: defaultSort.dir };
        // A module may enter the table from an already selected queue/folder.
        // Include that scope in the first request; a saved default view can
        // still replace it through the usual preference-loading path.
        let filters = Object.fromEntries(Object.entries(config.initialFilters || {}).map(([key, values]) => [key, new Set(values)]));
        let searchTerm = '';
        let openPopover = null;
        const server = config.server || null;
        let page = 1, pageSize = 50, total = 0, facets = {}, generation = 0, signature = '', started = null, booting = false;
        let exportController = null;
        const T = key => window.t('common.table_server.' + key);

        // Inject the accent so shared CSS + body-appended popovers pick it up.
        if (config.accent) document.documentElement.style.setProperty('--dt-accent', config.accent);

        // --- Boot -----------------------------------------------------
        if (!config.manual) document.addEventListener('DOMContentLoaded', start);
        function start() { return started || (started = boot()); }

        /**
         * Starts the table on DOMContentLoaded: columns, saved preferences, default view, rows, toolbar.
         *
         * The order matters - a default view is applied after preferences so it wins over them.
         */
        async function boot() {
            booting = true;
            columnState = columns.slice()
                .sort((a, b) => a.defaultOrder - b.defaultOrder)
                .map(c => ({ key: c.key, visible: c.defaultVisible }));

            const tableEl = byId(els.table);
            if (tableEl && editable) tableEl.classList.add('dt-editable');

            await loadPreferences();
            await applyDefaultView();
            booting = false;
            await reload();
            wireToolbar();
        }

        /**
         * Open the table with whichever view this analyst made their default.
         *
         * ⚠️ AFTER loadPreferences, so a default view wins over the loose column
         * state — picking a view is the more deliberate act of the two.
         *
         * A default pointing at a view that has since been deleted, or shared
         * with a team the reader has left, simply does not load: they get the
         * table's own defaults, which is what they would have had anyway. Better
         * than an error every morning about a view they cannot do anything about.
         */
        async function applyDefaultView() {
            if (!viewsKey) return;
            try {
                const views = await loadViews('');
                if (!viewDefaultId) return;
                const view = views.find(v => Number(v.id) === Number(viewDefaultId));
                if (!view) return;
                applyViewConfig(typeof view.config === 'string' ? JSON.parse(view.config) : view.config);
                activeViewId = Number(view.id);
            } catch (e) { /* the table's own defaults are a fine answer */ }
        }

        /** Shorthand for document.getElementById. */
        function byId(id) { return document.getElementById(id); }

        // --- Data -----------------------------------------------------
        /** Fetches the rows again through the module's load() and redraws; a failed load shows an empty table. */
        async function reload() {
            if (server) return reloadPage();
            try {
                allRows = (await config.load()) || [];
            } catch (e) {
                console.error('data-table load:', e);
                allRows = [];
            }
            render();
        }

        function query() {
            return {page, pageSize, sort:{...sort}, search:searchTerm,
                filters:Object.fromEntries(Object.entries(filters).map(([key, values]) => [key, [...values]])),
                context:config.getContext ? config.getContext() : config.context};
        }

        function querySignature() { const q=query(); delete q.page; return JSON.stringify(q); }

        function serverNotice(message) {
            let node = byId(els.table).parentElement.querySelector('.dt-server-error');
            if (!message) { node?.remove(); return; }
            if (!node) { node=document.createElement('div'); node.className='dt-server-error'; node.setAttribute('role','alert'); byId(els.table).before(node); }
            node.replaceChildren(document.createTextNode(message + ' '));
            const retry=document.createElement('button'); retry.type='button'; retry.textContent=T('retry'); retry.addEventListener('click',reload); node.append(retry);
        }

        async function reloadPage() {
            const current = ++generation;
            signature = querySignature();
            const table=byId(els.table);
            table.inert=true; table.setAttribute('aria-busy','true');
            config.onLoading?.();
            try {
                const result=await server.load(query());
                if (current !== generation) return;
                if (!result || !Array.isArray(result.rows) || !Number.isInteger(result.total) || result.total < 0) throw Error('Invalid table page');
                allRows=result.rows; total=result.total; page=result.page; pageSize=result.pageSize; facets=result.facets || {};
                table.inert=false; table.classList.remove('dt-stale'); serverNotice(null);
                renderHead(); renderBody(); renderPager();
            } catch (error) {
                if (current !== generation) return;
                table.classList.add('dt-stale'); serverNotice(T('failed'));
                config.onLoadError?.();
            } finally { if(current===generation) table.removeAttribute('aria-busy'); }
        }

        function renderPager() {
            let pager=byId(els.table).parentElement.querySelector('.dt-pagination');
            if(!pager){pager=document.createElement('div');pager.className='dt-pagination';byId(els.table).after(pager);}
            pager.replaceChildren();
            for(const [label, next, disabled] of [[T('previous'),page-1,page<=1],[T('next'),page+1,page*pageSize>=total]]) {
                const button=document.createElement('button');button.type='button';button.textContent=label;button.disabled=disabled;
                button.addEventListener('click',()=>{page=next;void reloadPage();});pager.append(button);
            }
            const status=document.createElement('span');status.textContent=page+' / '+Math.max(1,Math.ceil(total/pageSize));pager.append(status);
            const size=document.createElement('select');size.setAttribute('aria-label',T('page_size'));
            [25,50,100].forEach(n=>{const option=new Option(String(n),String(n));option.selected=n===pageSize;size.add(option);});
            size.addEventListener('change',()=>{pageSize=Number(size.value);page=1;void reloadPage();});pager.append(size);
        }

        async function setQuery(next) {
            if(next.sort) sort={...next.sort};
            if(next.search!==undefined) searchTerm=next.search;
            if(next.filters) filters=Object.fromEntries(Object.entries(next.filters).map(([k,v])=>[k,new Set(v)]));
            page=next.page || 1;
            if([25,50,100].includes(next.pageSize)) pageSize=next.pageSize;
            return reload();
        }

        // --- Preferences ----------------------------------------------
        /**
         * Restore column order and visibility from a saved list.
         *
         * ⚠️ ONE implementation, shared by preferences and saved views. Both
         * restore the same thing, and two copies would drift the first time a
         * column was added — which is exactly what the merge below is for: keys
         * that no longer exist are dropped, and columns the saved list has never
         * heard of are appended in their default order rather than vanishing.
         * A view saved last year still works after a new column ships.
         */
        function applyColumnConfig(cols) {
            if (!Array.isArray(cols)) return;
            const known  = new Set(columns.map(c => c.key));
            const seen   = new Set();
            const merged = [];
            cols.forEach(c => {
                if (known.has(c.k)) {
                    merged.push({ key: c.k, visible: c.v !== 0 });
                    seen.add(c.k);
                }
            });
            columns.slice().sort((a, b) => a.defaultOrder - b.defaultOrder).forEach(c => {
                if (!seen.has(c.key)) merged.push({ key: c.key, visible: c.defaultVisible });
            });
            columnState = merged;
        }

        /** Restores the sort from a saved {k, d} pair, ignoring a column that no longer exists. */
        function applySortConfig(s) {
            if (s && colByKey[s.k]) {
                sort = { key: s.k, dir: s.d === 'desc' ? 'desc' : 'asc' };
            }
        }

        /**
         * Reads this analyst's saved column layout and sort for the table from their user preferences.
         *
         * Any failure leaves the column defaults in place.
         */
        async function loadPreferences() {
            try {
                const res = await fetch(`${prefApi}get_user_preference.php?key=${encodeURIComponent(prefKey)}`);
                const data = await res.json();
                if (!data.success || !data.value) return;
                const parsed = JSON.parse(data.value);
                applyColumnConfig(parsed.cols);
                applySortConfig(parsed.sort);
            } catch (e) { /* defaults */ }
        }

        let saveTimer = null;
        /**
         * Saves the column layout and sort to the analyst's user preferences, 400ms after the last change.
         *
         * Debounced so dragging or toggling several columns sends one request, not one per click.
         */
        function savePreferences() {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => {
                const payload = JSON.stringify({
                    cols: columnState.map(c => ({ k: c.key, v: c.visible ? 1 : 0 })),
                    sort: { k: sort.key, d: sort.dir },
                });
                fetch(prefApi + 'set_user_preference.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ key: prefKey, value: payload }),
                }).catch(e => console.error('save prefs:', e));
            }, 400);
        }


        // ══ Saved views (discussion #96) ═══════════════════════════════
        //
        // A view is a saved way of LOOKING at this table: which columns, in what
        // order, sorted how, filtered to what. Built here rather than in any one
        // module, so all four tables that run this engine get it.
        //
        // ⚠️ The free-text SEARCH is deliberately not saved. A filter is how you
        // like to look at things; a search is a question you asked once. Saving
        // it would mean opening "Servers" tomorrow and seeing three rows because
        // of what you typed last Tuesday.
        const viewsKey = config.viewsKey || null;          // null = views off for this table
        // Derived from prefApi rather than set again per module. The four host
        // pages sit at different depths ('../api/…' vs '../../api/…') and a
        // second copy of that path is a second chance to get it wrong — which
        // would show up as a Views button that silently does nothing.
        const viewsApi = config.viewsApi
            || String(prefApi).replace(/api\/system\/?$/, 'api/table-views/');
        let   viewTeams = [];
        let   viewDefaultId = null;
        let   activeViewId = null;
        let   viewLayout = 'list';                          // 'list' | 'cards'

        /**
         * Translate, falling back to English — the engine has no i18n of its own.
         *
         * ⚠️ The FALLBACK is interpolated too. Without that a missing key renders
         * the placeholder itself — "Created {d}" — which is how the first run of
         * this looked: every date in the library was the literal word {d}. A
         * safety net that produces visible nonsense is not a safety net.
         */
        function vt(key, fallback, params) {
            /**
             * Replaces {name} placeholders in a string with the matching params, leaving unknown ones as they are.
             */
            const fill = s => String(s).replace(/\{(\w+)\}/g,
                (m, k) => (params && params[k] !== undefined) ? params[k] : m);

            if (typeof window.t !== 'function') return fill(fallback);
            const out = window.t('common.table_views.' + key, params || {});
            // i18n.js hands back the key itself when it has nothing; never show that.
            return (!out || out.indexOf('common.table_views.') === 0) ? fill(fallback) : out;
        }

        /** The current state, as a view's config. Filters are Sets, so unpack them. */
        function captureViewConfig() {
            const f = {};
            Object.keys(filters).forEach(k => {
                if (filters[k] && filters[k].size) f[k] = Array.from(filters[k]);
            });
            return {
                cols: columnState.map(c => ({ k: c.key, v: c.visible ? 1 : 0 })),
                ...(server ? {context:query().context} : {}),
                sort: { k: sort.key, d: sort.dir },
                filters: f,
            };
        }

        /**
         * Put a saved config back on the table.
         *
         * Filters go back into Sets because that is what the filtering code
         * expects, and a filter naming a value that no longer exists simply
         * matches nothing — an empty table rather than an error.
         */
        function applyViewConfig(cfg) {
            if (!cfg) return;
            if (server && cfg.context && config.applyContext) config.applyContext(cfg.context);
            applyColumnConfig(cfg.cols);
            applySortConfig(cfg.sort);
            filters = {};
            if (cfg.filters && typeof cfg.filters === 'object') {
                Object.keys(cfg.filters).forEach(k => {
                    if (colByKey[k] && Array.isArray(cfg.filters[k]) && cfg.filters[k].length) {
                        filters[k] = new Set(cfg.filters[k]);
                    }
                });
            }
            searchTerm = '';
            const s = byId(els.search);
            if (s) s.value = '';
            render();
        }

        /** Calls one of the table-views API endpoints and returns its JSON. */
        async function viewsFetch(path, opts) {
            const res = await fetch(viewsApi + path, opts);
            return res.json();
        }

        /** Every view this analyst can see on this table, plus their teams and default. */
        async function loadViews(q) {
            const data = await viewsFetch('list.php?table_key=' + encodeURIComponent(viewsKey)
                                          + '&q=' + encodeURIComponent(q || ''));
            if (!data.success) return [];
            viewTeams     = data.teams || [];
            viewDefaultId = data.default_id || null;
            return data.views || [];
        }

        /**
         * Applies a saved view from an already-loaded list, marks it as the active view and stamps it as used.
         *
         * The used stamp is fire and forget, so a failed request never stops the view showing.
         */
        async function applyViewById(id, rows) {
            const view = (rows || []).find(v => Number(v.id) === Number(id));
            if (!view) return;
            try {
                applyViewConfig(typeof view.config === 'string' ? JSON.parse(view.config) : view.config);
            } catch (e) {
                console.error('view config:', e);
                return;
            }
            activeViewId = Number(id);
            markActiveView();
            // Stamp it used. Fire and forget: a failed stamp must not stop
            // somebody looking at their table.
            viewsFetch('use.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: Number(id) }),
            }).catch(() => {});
        }

        /** The toolbar button shows which view is on, or that none is. */
        function markActiveView(name) {
            const btn = byId('dtViewsBtn');
            if (!btn) return;
            const label = btn.querySelector('.dt-views-label');
            if (label) label.textContent = name || vt('button', 'Views');
            btn.classList.toggle('dt-views-on', !!activeViewId);
        }

        // --- The library ----------------------------------------------
        let viewsModalEl = null;

        /** Closes the saved views library and drops its Escape key listener. */
        function closeViewsModal() {
            if (viewsModalEl) { viewsModalEl.remove(); viewsModalEl = null; }
            document.removeEventListener('keydown', viewsEsc);
        }
        /** Closes the saved views library when Escape is pressed. */
        function viewsEsc(e) { if (e.key === 'Escape') closeViewsModal(); }

        /**
         * Opens the saved views library - a modal with a search box, a list/cards layout switch and the views.
         *
         * The chosen layout is remembered in localStorage (dtViewLayout); the search reloads the list after 200ms.
         */
        async function openViewsLibrary() {
            closePopover();
            closeViewsModal();

            viewsModalEl = document.createElement('div');
            viewsModalEl.className = 'dt-modal-overlay';
            viewsModalEl.addEventListener('click', e => { if (e.target === viewsModalEl) closeViewsModal(); });
            viewsModalEl.innerHTML = `
                <div class="dt-modal dt-views-modal">
                    <div class="dt-modal-head">
                        <h3>${esc(vt('heading', 'Saved views'))}</h3>
                        <button type="button" class="dt-modal-x" data-act="close">&times;</button>
                    </div>
                    <div class="dt-views-bar">
                        <input type="text" class="dt-views-search" id="dtViewsSearch" autocomplete="off"
                               placeholder="${esc(vt('search_placeholder', 'Search views by name, description or who made them'))}">
                        <div class="dt-views-layout" role="group">
                            <button type="button" class="dt-views-layout-btn" data-layout="list" title="${esc(vt('layout_list', 'List'))}">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                            </button>
                            <button type="button" class="dt-views-layout-btn" data-layout="cards" title="${esc(vt('layout_cards', 'Cards'))}">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="dt-views-body" id="dtViewsBody"></div>
                    <div class="dt-modal-foot">
                        <button type="button" class="dt-btn" data-act="close">${esc(vt('close', 'Close'))}</button>
                    </div>
                </div>`;
            document.body.appendChild(viewsModalEl);
            document.addEventListener('keydown', viewsEsc);

            viewsModalEl.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', closeViewsModal));

            viewsModalEl.querySelectorAll('.dt-views-layout-btn').forEach(b => {
                b.classList.toggle('is-on', b.dataset.layout === viewLayout);
                b.addEventListener('click', () => {
                    viewLayout = b.dataset.layout;
                    viewsModalEl.querySelectorAll('.dt-views-layout-btn')
                        .forEach(x => x.classList.toggle('is-on', x.dataset.layout === viewLayout));
                    try { localStorage.setItem('dtViewLayout', viewLayout); } catch (e) { /* private window */ }
                    refreshViewsList();
                });
            });

            let searchTimer = null;
            byId('dtViewsSearch').addEventListener('input', () => {
                clearTimeout(searchTimer);
                searchTimer = setTimeout(refreshViewsList, 200);
            });

            refreshViewsList();
        }

        /**
         * Reloads the views matching the library's search and draws them, wiring the default, edit and delete buttons.
         *
         * Clicking anywhere else on an entry applies that view and closes the library.
         */
        async function refreshViewsList() {
            const body = byId('dtViewsBody');
            if (!body) return;
            const q = (byId('dtViewsSearch') || {}).value || '';
            const views = await loadViews(q);

            if (!views.length) {
                body.innerHTML = `<div class="dt-views-empty">${esc(q
                    ? vt('none_matching', 'No views match what you typed.')
                    : vt('none_yet', 'No saved views yet. Set the table up how you like it, then use Save view in the toolbar.'))}</div>`;
                return;
            }

            body.className = 'dt-views-body dt-views-' + viewLayout;
            body.innerHTML = views.map(v => renderViewEntry(v)).join('');

            body.querySelectorAll('[data-view-act]').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    const id  = Number(btn.closest('[data-view-id]').dataset.viewId);
                    const act = btn.dataset.viewAct;
                    if (act === 'edit')    { openViewEditor(views.find(v => Number(v.id) === id)); }
                    if (act === 'delete')  { deleteView(id); }
                    if (act === 'default') { setDefaultView(viewDefaultId === id ? null : id); }
                });
            });
            body.querySelectorAll('[data-view-id]').forEach(el => {
                el.addEventListener('click', () => {
                    applyViewById(Number(el.dataset.viewId), views);
                    closeViewsModal();
                });
            });
        }

        /**
         * Returns the HTML for one view in the library: name, badges, description, owner, dates and its action buttons.
         *
         * Edit and delete only appear when the server says the analyst can edit the view.
         */
        function renderViewEntry(v) {
            const isDefault = viewDefaultId === Number(v.id);
            const vis = v.visibility === 'public' ? vt('vis_public', 'Everyone')
                      : v.visibility === 'team'   ? (v.team_name || vt('vis_team', 'Team'))
                      : vt('vis_private', 'Only me');

            // Dates are plain calendar timestamps from the server. Show the day,
            // not the minute: on a library the useful question is "is this
            // stale?", and a time of day is noise against that.
            const day = s => (s || '').substring(0, 10);
            const used = v.last_used_datetime
                ? vt('last_used', 'Last used {d}', { d: day(v.last_used_datetime) })
                : vt('never_used', 'Never used');

            return `
                <div class="dt-view-entry${isDefault ? ' is-default' : ''}" data-view-id="${v.id}" tabindex="0">
                    <div class="dt-view-main">
                        <div class="dt-view-name">
                            ${esc(v.name)}
                            ${isDefault ? `<span class="dt-view-badge dt-view-badge-default">${esc(vt('default', 'Default'))}</span>` : ''}
                            <span class="dt-view-badge dt-view-vis-${esc(v.visibility)}">${esc(vis)}</span>
                        </div>
                        ${v.description ? `<div class="dt-view-desc">${esc(v.description)}</div>` : ''}
                        <div class="dt-view-meta">
                            ${v.owner_name ? esc(vt('by', 'by {name}', { name: v.owner_name })) : esc(vt('by_nobody', 'owner removed'))}
                            <span class="dt-view-sep">&bull;</span>${esc(vt('created', 'Created {d}', { d: day(v.created_datetime) }))}
                            <span class="dt-view-sep">&bull;</span>${esc(vt('modified', 'Modified {d}', { d: day(v.updated_datetime) }))}
                            <span class="dt-view-sep">&bull;</span>${esc(used)}
                        </div>
                    </div>
                    <div class="dt-view-acts">
                        <button type="button" class="dt-view-act" data-view-act="default"
                                title="${esc(isDefault ? vt('unset_default', 'Stop opening this table with this view')
                                                       : vt('set_default', 'Open this table with this view'))}">
                            ${isDefault ? '&#9733;' : '&#9734;'}
                        </button>
                        ${v.can_edit ? `
                        <button type="button" class="dt-view-act" data-view-act="edit" title="${esc(vt('edit', 'Edit'))}">&#9998;</button>
                        <button type="button" class="dt-view-act dt-view-act-danger" data-view-act="delete" title="${esc(vt('delete', 'Delete'))}">&times;</button>` : ''}
                    </div>
                </div>`;
        }

        /**
         * The save / edit dialog.
         *
         * ⚠️ Editing an existing view keeps its SAVED config rather than
         * overwriting it with whatever the table looks like now. Renaming a view
         * should not silently change what it shows. "Save current view" is the
         * gesture for that, and it says so.
         */
        function openViewEditor(view) {
            const editing = !!view;
            const wrap = document.createElement('div');
            wrap.className = 'dt-modal-overlay dt-views-editor';
            wrap.addEventListener('click', e => { if (e.target === wrap) wrap.remove(); });

            const teamOpts = viewTeams.map(t =>
                `<option value="${t.id}"${editing && Number(view.team_id) === t.id ? ' selected' : ''}>${esc(t.name)}</option>`).join('');

            wrap.innerHTML = `
                <div class="dt-modal dt-view-editor">
                    <div class="dt-modal-head">
                        <h3>${esc(editing ? vt('edit_heading', 'Edit view') : vt('save_heading', 'Save this view'))}</h3>
                        <button type="button" class="dt-modal-x" data-act="cancel">&times;</button>
                    </div>
                    <div class="dt-modal-body">
                        <label class="dt-field">
                            <span>${esc(vt('field_name', 'Name'))}</span>
                            <input type="text" id="dtViewName" maxlength="120" value="${editing ? esc(view.name) : ''}"
                                   placeholder="${esc(vt('name_placeholder', 'My end user devices'))}">
                        </label>
                        <label class="dt-field">
                            <span>${esc(vt('field_description', 'Description'))}</span>
                            <textarea id="dtViewDesc" maxlength="500" rows="2"
                                      placeholder="${esc(vt('desc_placeholder', 'What this view is for, so somebody else knows whether to use it'))}">${editing && view.description ? esc(view.description) : ''}</textarea>
                        </label>
                        <div class="dt-field">
                            <span>${esc(vt('field_visibility', 'Who can see it'))}</span>
                            <div class="dt-vis-choices">
                                <label><input type="radio" name="dtVis" value="private"${!editing || view.visibility === 'private' ? ' checked' : ''}> ${esc(vt('vis_private', 'Only me'))}</label>
                                <label${viewTeams.length ? '' : ' class="dt-vis-off"'}>
                                    <input type="radio" name="dtVis" value="team"${editing && view.visibility === 'team' ? ' checked' : ''}${viewTeams.length ? '' : ' disabled'}>
                                    ${esc(vt('vis_team_label', 'A team'))}
                                </label>
                                <label><input type="radio" name="dtVis" value="public"${editing && view.visibility === 'public' ? ' checked' : ''}> ${esc(vt('vis_public_label', 'Everyone'))}</label>
                            </div>
                            ${viewTeams.length
                                ? `<select id="dtViewTeam" class="dt-view-team">${teamOpts}</select>`
                                : `<div class="dt-views-hint">${esc(vt('no_teams', 'You are not in a team, so there is nobody to share a team view with.'))}</div>`}
                        </div>
                        ${editing ? `<div class="dt-views-hint">${esc(vt('edit_hint', 'This changes the name and who can see it. What the view SHOWS is left as it was saved - use "Save current view" to capture the table as it looks now.'))}</div>` : ''}
                    </div>
                    <div class="dt-modal-foot">
                        <button type="button" class="dt-btn dt-btn-primary" data-act="save">${esc(vt('save', 'Save'))}</button>
                        <button type="button" class="dt-btn" data-act="cancel">${esc(vt('cancel', 'Cancel'))}</button>
                    </div>
                </div>`;

            document.body.appendChild(wrap);
            wrap.querySelectorAll('[data-act="cancel"]').forEach(b => b.addEventListener('click', () => wrap.remove()));
            byId('dtViewName').focus();

            const teamSel = byId('dtViewTeam');
            /** Shows the team picker only while the "A team" visibility choice is ticked. */
            const syncTeam = () => {
                const on = wrap.querySelector('input[name="dtVis"]:checked').value === 'team';
                if (teamSel) teamSel.style.display = on ? '' : 'none';
            };
            wrap.querySelectorAll('input[name="dtVis"]').forEach(r => r.addEventListener('change', syncTeam));
            syncTeam();

            wrap.querySelector('[data-act="save"]').addEventListener('click', async () => {
                const name = byId('dtViewName').value.trim();
                if (!name) { byId('dtViewName').focus(); return; }
                const vis = wrap.querySelector('input[name="dtVis"]:checked').value;

                const payload = {
                    table_key:   viewsKey,
                    name:        name,
                    description: byId('dtViewDesc').value.trim(),
                    visibility:  vis,
                    team_id:     vis === 'team' && teamSel ? Number(teamSel.value) : null,
                    // Editing keeps the saved config; saving new captures now.
                    config:      editing ? (typeof view.config === 'string' ? JSON.parse(view.config) : view.config)
                                         : captureViewConfig(),
                };
                if (editing) payload.id = Number(view.id);

                const data = await viewsFetch('save.php', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                if (!data.success) { alertViews(data.error); return; }
                wrap.remove();
                if (!editing) { activeViewId = Number(data.id); markActiveView(name); }
                refreshViewsList();
            });
        }

        /** Deletes a saved view, clears the toolbar label if it was the active one, and refreshes the library. */
        async function deleteView(id) {
            const data = await viewsFetch('delete.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: id }),
            });
            if (!data.success) { alertViews(data.error); return; }
            if (activeViewId === id) { activeViewId = null; markActiveView(); }
            refreshViewsList();
        }

        /** Sets or clears (id null) the view this table opens with for the analyst, then refreshes the library. */
        async function setDefaultView(id) {
            const data = await viewsFetch('use.php', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ table_key: viewsKey, default_id: id }),
            });
            if (!data.success) { alertViews(data.error); return; }
            viewDefaultId = id;
            refreshViewsList();
        }

        /** Errors go through the host page's toast when it has one. */
        function alertViews(msg) {
            const text = msg || vt('failed', 'That did not work.');
            if (typeof window.showToast === 'function') window.showToast(text, 'error');
            else console.error('table views:', text);
        }

        // --- Column value/display helpers -----------------------------
        /** Returns a cell's raw value for sorting - the column's value() when it has one, otherwise row[key]. */
        function colValue(col, row) {
            if (typeof col.value === 'function') return col.value(row);
            return row[col.key];
        }
        /**
         * Returns a cell's text as shown, searched, filtered and exported.
         *
         * Uses the column's display(row), then the older format(raw), then the raw value; null becomes ''.
         */
        function colDisplay(col, row) {
            if (typeof col.display === 'function') return col.display(row) || '';
            if (typeof col.format === 'function') return col.format(row[col.key]) || '';
            const raw = row[col.key];
            return (raw === null || raw === undefined) ? '' : String(raw);
        }

        // --- Toolbar --------------------------------------------------
        /**
         * Wires the toolbar (search, Columns, Reset, Views, Save view, CSV, PDF) and popover closing.
         *
         * Views and Save view are hidden on a table with no viewsKey; PDF is only wired when config.pdf is set.
         */
        function wireToolbar() {
            const s = byId(els.search);
            if (s) s.addEventListener('input', e => {
                searchTerm = e.target.value.trim().toLowerCase();
                if (server) render(); else renderBody();
            });
            const cb = byId(els.columnsBtn);
            if (cb) cb.addEventListener('click', e => { e.stopPropagation(); openColumnsDrawer(e.currentTarget); });
            const rb = byId(els.resetBtn);
            if (rb) rb.addEventListener('click', () => {
                filters = {};
                searchTerm = '';
                sort = { key: defaultSort.key, dir: defaultSort.dir };
                if (s) s.value = '';
                closePopover();
                render();
                savePreferences();
            });
            // Saved views. The button is in the shared toolbar for every table,
            // but only wired up on tables that asked for views, and hidden
            // otherwise — a button that does nothing is worse than no button.
            const vb = byId('dtViewsBtn');
            const sv = byId('dtSaveViewBtn');
            if (viewsKey) {
                if (vb) {
                    vb.addEventListener('click', e => { e.stopPropagation(); openViewsLibrary(); });
                    markActiveView();
                }
                // Saving is its own toolbar action rather than something you
                // open the library to reach: it is about the table in front of
                // you, and it is the commoner of the two.
                if (sv) sv.addEventListener('click', e => {
                    e.stopPropagation();
                    // The editor needs the analyst's teams to offer them, and
                    // they arrive with the view list. Fetch before opening, or
                    // the "A team" choice would be disabled the first time.
                    loadViews('').then(() => openViewEditor(null)).catch(() => openViewEditor(null));
                });
                try {
                    const saved = localStorage.getItem('dtViewLayout');
                    if (saved === 'cards' || saved === 'list') viewLayout = saved;
                } catch (e) { /* private window: the default is fine */ }
            } else {
                if (vb) vb.style.display = 'none';
                if (sv) sv.style.display = 'none';
            }

            const csv = byId(els.csvBtn);
            if (csv) csv.addEventListener('click', exportCSV);
            const pdf = byId(els.pdfBtn);
            if (pdf && config.pdf) pdf.addEventListener('click', exportPDF);

            document.addEventListener('click', e => {
                if (openPopover && !openPopover.contains(e.target)) closePopover();
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') closePopover(); });
        }

        // --- Rendering ------------------------------------------------
        /** Returns the column definitions that are switched on, in their current order. */
        function visibleColumns() {
            return columnState.filter(c => c.visible).map(c => colByKey[c.key]).filter(Boolean);
        }

        /** Redraws the header and the body. */
        function render() {
            if (server && !booting && signature !== querySignature()) { page=1; void reloadPage(); return; }
            renderHead(); renderBody();
        }

        /**
         * Draws the header row and wires click-to-sort, the filter buttons and drag-to-reorder on each column heading.
         */
        function renderHead() {
            const head = byId(els.head);
            const cols = visibleColumns();
            head.innerHTML = `<tr>${cols.map(col => {
                const isSorted = sort.key === col.key;
                const arrow = isSorted ? (sort.dir === 'asc' ? '▲' : '▼') : '↕';
                const sortedClass = isSorted ? ' sorted' : '';
                const hasFilter = filters[col.key] && filters[col.key].size > 0;
                const filterClass = hasFilter ? ' active' : '';
                return `
                    <th data-col-key="${esc(col.key)}" draggable="true">
                        <div class="dt-th-content${sortedClass}">
                            <span class="dt-th-label">${esc(col.label)}</span>
                            <span class="dt-sort-arrow">${col.sortable === false ? '' : arrow}</span>
                            <button type="button" class="dt-filter-btn${filterClass}" title="Filter ${esc(col.label)}" data-filter-key="${esc(col.key)}">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
                                </svg>
                            </button>
                        </div>
                    </th>`;
            }).join('')}</tr>`;

            head.querySelectorAll('th').forEach(th => {
                const key = th.dataset.colKey;
                if (colByKey[key].filterable === false) th.querySelector('.dt-filter-btn').hidden=true;
                th.querySelector('.dt-th-content').addEventListener('click', e => {
                    if (e.target.closest('.dt-filter-btn')) return;
                    if(colByKey[key].sortable !== false) toggleSort(key);
                });
                th.querySelector('.dt-filter-btn').addEventListener('click', e => {
                    e.stopPropagation();
                    openFilterDropdown(key, e.currentTarget);
                });
                th.addEventListener('dragstart', e => {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', key);
                    th.classList.add('dt-dragging');
                });
                th.addEventListener('dragend', () => {
                    th.classList.remove('dt-dragging');
                    head.querySelectorAll('th').forEach(t => t.classList.remove('dt-drag-over'));
                });
                th.addEventListener('dragover', e => {
                    e.preventDefault();
                    head.querySelectorAll('th').forEach(t => t.classList.remove('dt-drag-over'));
                    th.classList.add('dt-drag-over');
                });
                th.addEventListener('drop', e => {
                    e.preventDefault();
                    const from = e.dataTransfer.getData('text/plain');
                    if (from && from !== key) reorderColumn(from, key);
                });
            });
        }

        /**
         * Draws the filtered, sorted rows and the row count, then wires inline editing and row clicks.
         *
         * config.hideCount leaves the count element alone so a module can use it for something else.
         */
        function renderBody() {
            const body = byId(els.body);
            const cols = visibleColumns();
            const rows = applyFiltersAndSort();

            // A module can suppress the row count (e.g. to put its own note in
            // the toolbar's right slot) with config.hideCount.
            const countEl = byId(els.count);
            if (countEl && !config.hideCount) {
                countEl.textContent = server ? `${rows.length} / ${total}` : rows.length === allRows.length
                    ? `${rows.length} ${noun}${rows.length === 1 ? '' : 's'}`
                    : `${rows.length} of ${allRows.length}`;
            }

            if (rows.length === 0) {
                body.innerHTML = `<tr><td colspan="${cols.length || 1}" class="dt-empty">No ${noun}s match the current filters.</td></tr>`;
                config.onRowsRendered?.(rows);
                return;
            }

            const clickable = typeof config.onRowClick === 'function' ? ' dt-clickable' : '';
            body.innerHTML = rows.map(row => {
                const tds = cols.map(col => renderCell(row, col)).join('');
                return `<tr class="dt-row${clickable}" data-id="${esc(rowId(row))}">${tds}</tr>`;
            }).join('');

            if (editable) wireEditControls(body);
            if (clickable) wireRowClicks(body, rows);
            config.onRowsRendered?.(rows);
        }

        /** Returns one cell's HTML - plain text, or an input or select for an editable column. */
        function renderCell(row, col) {
            const display = colDisplay(col, row);
            if (!editable || !col.editable) {
                const title = (typeof col.cellTitle === 'function') ? col.cellTitle(row) : display;
                return `<td title="${esc(title)}">${esc(display)}</td>`;
            }
            const id = esc(rowId(row));
            const kind = col.editable.kind;
            if (kind === 'text') {
                return `<td><input class="dt-cell-input dt-edit" data-id="${id}" data-key="${esc(col.key)}" data-kind="text"
                    value="${esc(display)}" onfocus="event.target.select()"></td>`;
            }
            if (kind === 'date') {
                return `<td><input type="date" class="dt-cell-date dt-edit" data-id="${id}" data-key="${esc(col.key)}" data-kind="date"
                    value="${esc(colValue(col, row) || '')}"></td>`;
            }
            if (kind === 'datetime') {
                return `<td><input type="datetime-local" class="dt-cell-date dt-edit" data-id="${id}" data-key="${esc(col.key)}" data-kind="datetime"
                    value="${esc(toLocalInput(row[col.key]))}"></td>`;
            }
            if (kind === 'bool') {
                const v = colValue(col, row);
                const on = (v === 1 || v === true || v === '1');
                return `<td><select class="dt-cell-select dt-edit" data-id="${id}" data-key="${esc(col.key)}" data-kind="bool">
                    <option value="1"${on ? ' selected' : ''}>Yes</option>
                    <option value="0"${on ? '' : ' selected'}>No</option></select></td>`;
            }
            if (kind === 'lookup') return renderLookupCell(row, col, id);
            return `<td>${esc(display)}</td>`;
        }

        /**
         * Returns an editable lookup cell: a select built from the module's lookup list, with an optional colour swatch.
         */
        function renderLookupCell(row, col, id) {
            const ed = col.editable;
            const lookup = (config.getLookups ? config.getLookups() : {})[ed.listKey] || [];
            const currentVal = row[col.key];
            const opts = [];
            if (ed.allowNull) {
                const sel = (currentVal === null || currentVal === undefined || currentVal === '') ? ' selected' : '';
                opts.push(`<option value=""${sel}>${esc(ed.nullLabel || '—')}</option>`);
            }
            let swatchColour = '';
            lookup.forEach(item => {
                const value = item[ed.valueKey];
                const label = item[ed.labelKey];
                const isCurrent = String(value) === String(currentVal);
                if (isCurrent && ed.colourKey) swatchColour = item[ed.colourKey] || '';
                opts.push(`<option value="${esc(value)}"${isCurrent ? ' selected' : ''}>${esc(label)}</option>`);
            });
            const swatch = ed.colourKey ? `<span class="dt-swatch" style="background:${esc(swatchColour || '#bbb')}"></span>` : '';
            return `<td><div class="dt-cell-wrap">${swatch}<select class="dt-cell-select dt-edit"
                data-id="${id}" data-key="${esc(col.key)}" data-kind="lookup">${opts.join('')}</select></div></td>`;
        }

        // --- Inline edit wiring ---------------------------------------
        /** Saves an inline-edited cell when its input or select changes. */
        function wireEditControls(body) {
            body.querySelectorAll('.dt-edit').forEach(el => {
                el.addEventListener('change', () => {
                    const id = el.dataset.id;
                    const col = colByKey[el.dataset.key];
                    if (!col) return;
                    const row = allRows.find(r => String(rowId(r)) === String(id));
                    if (!row) return;
                    saveCell(row, col, el.value);
                });
            });
        }

        /** Turns an edit control's value into what the module saves (1/0, null, a DB datetime, an int id). */
        function normalizeValue(col, raw) {
            const kind = col.editable.kind;
            if (kind === 'bool') return (raw === '1' || raw === 1 || raw === true) ? 1 : 0;
            if (kind === 'date') return raw || null;
            if (kind === 'datetime') return raw ? fromLocalInput(raw) : null;
            if (kind === 'lookup') {
                if (col.editable.valueKey === 'id') return (raw === '' || raw === null) ? null : parseInt(raw, 10);
                return raw === '' ? null : raw;
            }
            return raw;
        }

        /**
         * Saves one edited cell through the module's onSaveCell and shows a toast.
         *
         * On failure the rows are reloaded so the cell goes back to what the server holds.
         */
        async function saveCell(row, col, raw) {
            const value = normalizeValue(col, raw);
            try {
                await config.onSaveCell(row, col, value);
                if (window.showToast) showToast('Saved', 'success');
                if (col.editable.colourKey) refreshSwatch(row, col);
            } catch (e) {
                if (window.showToast) showToast(e && e.message ? e.message : 'Save failed', 'error');
                await reload();
            }
        }

        /** Updates a lookup cell's colour swatch after a save, without redrawing the table. */
        function refreshSwatch(row, col) {
            const ed = col.editable;
            const lookup = (config.getLookups ? config.getLookups() : {})[ed.listKey] || [];
            const item = lookup.find(i => String(i[ed.valueKey]) === String(row[col.key]));
            const colour = (item && item[ed.colourKey]) || '#bbb';
            const tr = byId(els.body).querySelector(`tr.dt-row[data-id="${cssEsc(rowId(row))}"]`);
            if (!tr) return;
            const idx = visibleColumns().findIndex(c => c.key === col.key);
            if (idx < 0) return;
            const sw = tr.children[idx] && tr.children[idx].querySelector('.dt-swatch');
            if (sw) sw.style.background = colour;
        }

        // --- Row clicks -----------------------------------------------
        /** Calls the module's onRowClick when a row is clicked, but not when the click lands on an edit control. */
        function wireRowClicks(body, rows) {
            body.querySelectorAll('tr.dt-row').forEach(tr => {
                tr.addEventListener('click', e => {
                    if (e.target.closest('input, select, button, option, label, .dt-swatch')) return;
                    const row = allRows.find(r => String(rowId(r)) === String(tr.dataset.id));
                    if (row) config.onRowClick(row, e);
                });
                if(config.onRowContextMenu) tr.addEventListener('contextmenu',e=>{
                    const row=allRows.find(r=>String(rowId(r))===String(tr.dataset.id));
                    if(row) config.onRowContextMenu(row,e);
                });
            });
        }

        // --- Filter / sort / search -----------------------------------
        /**
         * Returns the rows that pass the column filters and the search, sorted by the current sort column.
         *
         * Filters match on display text (empty is '(empty)'); search looks only at visible columns. Empty values
         * always sort last, whichever the direction. Also exposed as getViewRows and used by the exports.
         */
        function applyFiltersAndSort() {
            if(server) return allRows;
            const cols = visibleColumns();
            let rows = allRows.filter(row => {
                for (const colKey in filters) {
                    const allowed = filters[colKey];
                    if (!allowed || allowed.size === 0) continue;
                    const col = colByKey[colKey];
                    if (!col) continue;
                    const display = colDisplay(col, row);
                    const key = display === '' ? '(empty)' : display;
                    if (!allowed.has(key)) return false;
                }
                return true;
            });

            if (searchTerm) {
                rows = rows.filter(row => {
                    for (const col of cols) {
                        if (String(colDisplay(col, row) || '').toLowerCase().indexOf(searchTerm) !== -1) return true;
                    }
                    return false;
                });
            }

            const sortCol = colByKey[sort.key];
            if (sortCol) {
                const dir = sort.dir === 'desc' ? -1 : 1;
                const isNum = sortCol.type === 'number';
                const isDate = sortCol.type === 'date';
                rows = rows.slice().sort((a, b) => {
                    if (isNum) {
                        const va = colValue(sortCol, a), vb = colValue(sortCol, b);
                        if (va === null || va === undefined || va === '') return 1;
                        if (vb === null || vb === undefined || vb === '') return -1;
                        return (Number(va) - Number(vb)) * dir;
                    }
                    if (isDate) {
                        const va = colValue(sortCol, a) || '', vb = colValue(sortCol, b) || '';
                        if (!va && !vb) return 0;
                        if (!va) return 1;
                        if (!vb) return -1;
                        return (va < vb ? -1 : va > vb ? 1 : 0) * dir;
                    }
                    const va = colDisplay(sortCol, a), vb = colDisplay(sortCol, b);
                    if (!va && !vb) return 0;
                    if (!va) return 1;
                    if (!vb) return -1;
                    return String(va).localeCompare(String(vb), undefined, { sensitivity: 'base' }) * dir;
                });
            }
            return rows;
        }

        /**
         * Sorts by a column, flipping the direction when it is already the sort column, and saves the preference.
         */
        function toggleSort(key) {
            if (sort.key === key) sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
            else sort = { key, dir: 'asc' };
            render();
            savePreferences();
        }

        /** Moves a column to where another one is, redraws and saves the preference. */
        function reorderColumn(fromKey, toKey) {
            const fromIdx = columnState.findIndex(c => c.key === fromKey);
            const toIdx = columnState.findIndex(c => c.key === toKey);
            if (fromIdx < 0 || toIdx < 0) return;
            const [moved] = columnState.splice(fromIdx, 1);
            columnState.splice(toIdx, 0, moved);
            render();
            savePreferences();
        }

        // --- Per-column filter dropdown -------------------------------
        /**
         * Opens the tickbox filter for one column, listing each distinct value with its count.
         *
         * Values and counts come from the rows that pass the OTHER columns' filters. Ticking every value removes
         * the filter rather than storing one that matches everything.
         */
        function openFilterDropdown(colKey, anchorEl) {
            closePopover();
            const col = colByKey[colKey];
            if (!col) return;

            const otherFilters = Object.assign({}, filters);
            delete otherFilters[colKey];
            const baseRows = allRows.filter(row => {
                for (const k in otherFilters) {
                    const allowed = otherFilters[k];
                    if (!allowed || allowed.size === 0) continue;
                    const c = colByKey[k];
                    if (!c) continue;
                    const display = colDisplay(c, row);
                    const key = display === '' ? '(empty)' : display;
                    if (!allowed.has(key)) return false;
                }
                return true;
            });
            const distinct = new Map();
            baseRows.forEach(row => {
                const display = colDisplay(col, row);
                const key = display === '' ? '(empty)' : display;
                distinct.set(key, (distinct.get(key) || 0) + 1);
            });
            const labels=new Map();
            if(server) {
                distinct.clear();
                (facets[colKey] || []).forEach(item=>{distinct.set(String(item.value),item.count);labels.set(String(item.value),item.label);});
                (filters[colKey] || []).forEach(value=>{if(!distinct.has(value)) distinct.set(value,0);});
            }
            const sorted = [...distinct.keys()].sort((a, b) =>
                a === '(empty)' ? -1 : b === '(empty)' ? 1
                : String(a).localeCompare(String(b), undefined, { sensitivity: 'base' })
            );

            const current = filters[colKey];
            const selected = new Set(current && current.size > 0 ? [...current] : sorted);

            const pop = document.createElement('div');
            pop.className = 'dt-pop dt-filter-pop';
            pop.innerHTML = `
                <input type="text" class="dt-pop-search" placeholder="Search values..." autocomplete="off">
                <div class="dt-pop-actions">
                    <a class="dt-pop-select-all">Select all</a>
                    <a class="dt-pop-clear">Clear</a>
                </div>
                <div class="dt-pop-list">
                    ${sorted.map(v => `
                        <label class="dt-pop-item">
                            <input type="checkbox" value="${esc(v)}" ${selected.has(v) ? 'checked' : ''}>
                            <span class="dt-pop-value">${esc(labels.get(v) ?? v)}</span>
                            <span style="color:#999;font-size:11px;">${distinct.get(v)}</span>
                        </label>`).join('')}
                </div>
                <div class="dt-pop-buttons">
                    <button type="button" class="dt-pop-cancel">Cancel</button>
                    <button type="button" class="dt-pop-apply">Apply</button>
                </div>`;
            document.body.appendChild(pop);
            positionPopover(pop, anchorEl);
            openPopover = pop;

            const list = pop.querySelector('.dt-pop-list');
            const searchEl = pop.querySelector('.dt-pop-search');
            searchEl.focus();
            searchEl.addEventListener('input', () => {
                const term = searchEl.value.trim().toLowerCase();
                list.querySelectorAll('.dt-pop-item').forEach(item => {
                    const txt = item.querySelector('.dt-pop-value').textContent.toLowerCase();
                    item.style.display = term === '' || txt.indexOf(term) !== -1 ? '' : 'none';
                });
            });
            pop.querySelector('.dt-pop-select-all').addEventListener('click', () => {
                list.querySelectorAll('.dt-pop-item:not([style*="display: none"]) input').forEach(cb => cb.checked = true);
            });
            pop.querySelector('.dt-pop-clear').addEventListener('click', () => {
                list.querySelectorAll('.dt-pop-item:not([style*="display: none"]) input').forEach(cb => cb.checked = false);
            });
            pop.querySelector('.dt-pop-cancel').addEventListener('click', closePopover);
            pop.querySelector('.dt-pop-apply').addEventListener('click', () => {
                const checked = new Set();
                list.querySelectorAll('input:checked').forEach(cb => checked.add(cb.value));
                if (checked.size === sorted.length) delete filters[colKey];
                else filters[colKey] = checked;
                closePopover();
                render();
            });
        }

        // --- Columns drawer -------------------------------------------
        /**
         * Opens the Columns popover, where columns are ticked on or off and dragged into order; each change is saved.
         */
        function openColumnsDrawer(anchorEl) {
            closePopover();
            const pop = document.createElement('div');
            pop.className = 'dt-pop dt-cols-pop';
            pop.innerHTML = `
                <h4>Columns</h4>
                <div class="dt-cols-hint">Drag to reorder. Tick to show.</div>
                <div class="dt-cols-list">
                    ${columnState.map(c => {
                        const col = colByKey[c.key];
                        if (!col) return '';
                        return `
                            <div class="dt-cols-item" draggable="true" data-col-key="${esc(c.key)}">
                                <span class="dt-cols-drag">⋮⋮</span>
                                <input type="checkbox" ${c.visible ? 'checked' : ''} data-toggle-key="${esc(c.key)}">
                                <span>${esc(col.label)}</span>
                            </div>`;
                    }).join('')}
                </div>`;
            document.body.appendChild(pop);
            positionPopover(pop, anchorEl);
            openPopover = pop;

            const list = pop.querySelector('.dt-cols-list');
            list.querySelectorAll('.dt-cols-item').forEach(item => {
                const key = item.dataset.colKey;
                item.querySelector('input').addEventListener('change', e => {
                    const entry = columnState.find(c => c.key === key);
                    if (entry) {
                        entry.visible = e.target.checked;
                        render();
                        savePreferences();
                    }
                });
                item.addEventListener('dragstart', e => {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', key);
                    item.classList.add('dragging');
                });
                item.addEventListener('dragend', () => {
                    item.classList.remove('dragging');
                    list.querySelectorAll('.dt-cols-item').forEach(i => i.classList.remove('drag-over'));
                });
                item.addEventListener('dragover', e => {
                    e.preventDefault();
                    list.querySelectorAll('.dt-cols-item').forEach(i => i.classList.remove('drag-over'));
                    item.classList.add('drag-over');
                });
                item.addEventListener('drop', e => {
                    e.preventDefault();
                    const from = e.dataTransfer.getData('text/plain');
                    if (from && from !== key) {
                        reorderColumn(from, key);
                        closePopover();
                        openColumnsDrawer(anchorEl);
                    }
                });
            });
        }

        // --- Popover positioning --------------------------------------
        /** Places a popover below its anchor, kept inside the window's width. */
        function positionPopover(pop, anchorEl) {
            const r = anchorEl.getBoundingClientRect();
            pop.style.visibility = 'hidden';
            pop.style.left = '0px';
            pop.style.top = '0px';
            const pw = pop.offsetWidth || 240;
            const left = Math.max(8, Math.min(r.left, window.innerWidth - pw - 8));
            pop.style.left = `${left}px`;
            pop.style.top = `${r.bottom + 4 + window.scrollY}px`;
            pop.style.visibility = 'visible';
        }

        /** Removes the open filter or columns popover, if there is one. */
        function closePopover() {
            if (openPopover && openPopover.parentNode) openPopover.parentNode.removeChild(openPopover);
            openPopover = null;
        }

        // --- Exports --------------------------------------------------
        /** Downloads the filtered, sorted rows (visible columns only) as a CSV, with a BOM so Excel reads UTF-8. */
        async function exportCSV() {
            if(server) {
                if(exportController){exportController.abort();return;}
                exportController=new AbortController();
                const button=byId(els.csvBtn),label=button?.textContent;
                if(button)button.textContent=T('cancel_export');
                try {await server.exportCsv({...query(),columns:visibleColumns().map(c=>c.key)},exportController.signal);}
                catch(error){if(error.name!=='AbortError')serverNotice(T('export_failed'));}
                finally{exportController=null;if(button)button.textContent=label;}
                return;
            }
            const cols = visibleColumns();
            const rows = applyFiltersAndSort();
            const header = cols.map(c => csvCell(c.label)).join(',');
            const body = rows.map(row => cols.map(c => csvCell(colDisplay(c, row))).join(',')).join('\n');
            const csv = '﻿' + header + '\n' + body;  // BOM for Excel UTF-8
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `${config.exportName || 'export'}-${formatDateStr()}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            if (window.showToast) showToast(`Exported ${rows.length} ${noun}${rows.length === 1 ? '' : 's'} to CSV`, 'success');
        }

        /**
         * Downloads the filtered, sorted rows as a landscape A4 PDF (jsPDF + autoTable), with optional logo.
         *
         * Shows an error toast when the page has not loaded jsPDF.
         */
        async function exportPDF() {
            if (!window.jspdf) {
                if (window.showToast) showToast('PDF library not loaded', 'error');
                return;
            }
            const opts = config.pdf || {};
            const { jsPDF } = window.jspdf;
            const cols = visibleColumns();
            const rows = applyFiltersAndSort();
            const doc = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'landscape' });
            let startY = 10;

            if (opts.logo) {
                try {
                    const img = new Image();
                    img.crossOrigin = 'anonymous';
                    await new Promise((resolve, reject) => {
                        img.onload = resolve; img.onerror = reject; img.src = opts.logo;
                    });
                    const maxH = 12;
                    const w = maxH * (img.width / img.height);
                    doc.addImage(img, 'PNG', 10, startY, w, maxH);
                    startY += maxH + 5;
                } catch (e) { /* no logo */ }
            }

            doc.setFontSize(14);
            doc.setTextColor(44, 62, 80);
            doc.text(opts.title || (config.exportName || 'Export'), 10, startY + 5);
            doc.setFontSize(10);
            doc.setTextColor(120, 120, 120);
            doc.text(`${rows.length} of ${allRows.length} — ${fmtDateTime(new Date())}`, 10, startY + 11);
            startY += 18;

            doc.autoTable({
                startY: startY,
                head: [cols.map(c => c.label)],
                body: rows.map(row => cols.map(c => colDisplay(c, row))),
                styles: { fontSize: 8, cellPadding: 2, overflow: 'linebreak' },
                headStyles: { fillColor: opts.headFill || [0, 120, 212], textColor: [255, 255, 255], fontStyle: 'bold' },
                alternateRowStyles: { fillColor: [248, 250, 252] },
                margin: { left: 10, right: 10 },
            });

            doc.save(`${config.exportName || 'export'}-${formatDateStr()}.pdf`);
            if (window.showToast) showToast(`Exported ${rows.length} ${noun}${rows.length === 1 ? '' : 's'} to PDF`, 'success');
        }

        /** Quotes a CSV value when it holds a quote, comma or newline, doubling any quotes. */
        function csvCell(v) {
            const s = String(v == null ? '' : v);
            if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        }

        /** Returns today's date as YYYY-MM-DD for export file names. */
        function formatDateStr() {
            const d = new Date();
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        // --- Datetime helpers (DB 'YYYY-MM-DD HH:MM:SS' <-> input) -----
        /** Turns a DB 'YYYY-MM-DD HH:MM:SS' value into the 'YYYY-MM-DDTHH:MM' a datetime-local input wants. */
        function toLocalInput(raw) {
            if (!raw) return '';
            return String(raw).replace(' ', 'T').slice(0, 16);
        }
        /** Turns a datetime-local input's value back into a DB 'YYYY-MM-DD HH:MM:SS' string. */
        function fromLocalInput(v) {
            if (!v) return null;
            const s = v.replace('T', ' ');
            return s.length === 16 ? s + ':00' : s;
        }

        // --- Utilities ------------------------------------------------
        /** Escapes a value for use in HTML text or an attribute. */
        function esc(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }
        // For querySelector attribute values (data-id) — escape quotes/backslashes.
        function cssEsc(s) {
            return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
        }

        // Public surface
        return {
            start, setQuery,
            reload,
            render,
            findRow: id => allRows.find(r => String(rowId(r)) === String(id)),
            getViewRows: applyFiltersAndSort,
            getAllRows: () => allRows,
        };
    }

    window.createDataTable = createDataTable;
})();
