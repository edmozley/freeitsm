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
        let filters = {};
        let searchTerm = '';
        let openPopover = null;

        // Inject the accent so shared CSS + body-appended popovers pick it up.
        if (config.accent) document.documentElement.style.setProperty('--dt-accent', config.accent);

        // --- Boot -----------------------------------------------------
        document.addEventListener('DOMContentLoaded', boot);

        async function boot() {
            columnState = columns.slice()
                .sort((a, b) => a.defaultOrder - b.defaultOrder)
                .map(c => ({ key: c.key, visible: c.defaultVisible }));

            const tableEl = byId(els.table);
            if (tableEl && editable) tableEl.classList.add('dt-editable');

            await loadPreferences();
            await reload();
            wireToolbar();
        }

        function byId(id) { return document.getElementById(id); }

        // --- Data -----------------------------------------------------
        async function reload() {
            try {
                allRows = (await config.load()) || [];
            } catch (e) {
                console.error('data-table load:', e);
                allRows = [];
            }
            render();
        }

        // --- Preferences ----------------------------------------------
        async function loadPreferences() {
            try {
                const res = await fetch(`${prefApi}get_user_preference.php?key=${encodeURIComponent(prefKey)}`);
                const data = await res.json();
                if (!data.success || !data.value) return;
                const parsed = JSON.parse(data.value);
                if (Array.isArray(parsed.cols)) {
                    const known = new Set(columns.map(c => c.key));
                    const seen = new Set();
                    const merged = [];
                    parsed.cols.forEach(c => {
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
                if (parsed.sort && colByKey[parsed.sort.k]) {
                    sort = { key: parsed.sort.k, dir: parsed.sort.d === 'desc' ? 'desc' : 'asc' };
                }
            } catch (e) { /* defaults */ }
        }

        let saveTimer = null;
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

        // --- Column value/display helpers -----------------------------
        function colValue(col, row) {
            if (typeof col.value === 'function') return col.value(row);
            return row[col.key];
        }
        function colDisplay(col, row) {
            if (typeof col.display === 'function') return col.display(row) || '';
            if (typeof col.format === 'function') return col.format(row[col.key]) || '';
            const raw = row[col.key];
            return (raw === null || raw === undefined) ? '' : String(raw);
        }

        // --- Toolbar --------------------------------------------------
        function wireToolbar() {
            const s = byId(els.search);
            if (s) s.addEventListener('input', e => {
                searchTerm = e.target.value.trim().toLowerCase();
                renderBody();
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
        function visibleColumns() {
            return columnState.filter(c => c.visible).map(c => colByKey[c.key]).filter(Boolean);
        }

        function render() { renderHead(); renderBody(); }

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
                            <span class="dt-sort-arrow">${arrow}</span>
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
                th.querySelector('.dt-th-content').addEventListener('click', e => {
                    if (e.target.closest('.dt-filter-btn')) return;
                    toggleSort(key);
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

        function renderBody() {
            const body = byId(els.body);
            const cols = visibleColumns();
            const rows = applyFiltersAndSort();

            // A module can suppress the row count (e.g. to put its own note in
            // the toolbar's right slot) with config.hideCount.
            const countEl = byId(els.count);
            if (countEl && !config.hideCount) {
                countEl.textContent = rows.length === allRows.length
                    ? `${rows.length} ${noun}${rows.length === 1 ? '' : 's'}`
                    : `${rows.length} of ${allRows.length}`;
            }

            if (rows.length === 0) {
                body.innerHTML = `<tr><td colspan="${cols.length || 1}" class="dt-empty">No ${noun}s match the current filters.</td></tr>`;
                return;
            }

            const clickable = typeof config.onRowClick === 'function' ? ' dt-clickable' : '';
            body.innerHTML = rows.map(row => {
                const tds = cols.map(col => renderCell(row, col)).join('');
                return `<tr class="dt-row${clickable}" data-id="${esc(rowId(row))}">${tds}</tr>`;
            }).join('');

            if (editable) wireEditControls(body);
            if (clickable) wireRowClicks(body, rows);
        }

        function renderCell(row, col) {
            const display = colDisplay(col, row);
            if (!editable || !col.editable) {
                return `<td title="${esc(display)}">${esc(display)}</td>`;
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
        function wireRowClicks(body, rows) {
            body.querySelectorAll('tr.dt-row').forEach(tr => {
                tr.addEventListener('click', e => {
                    if (e.target.closest('input, select, button, option, label, .dt-swatch')) return;
                    const row = allRows.find(r => String(rowId(r)) === String(tr.dataset.id));
                    if (row) config.onRowClick(row);
                });
            });
        }

        // --- Filter / sort / search -----------------------------------
        function applyFiltersAndSort() {
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

        function toggleSort(key) {
            if (sort.key === key) sort.dir = sort.dir === 'asc' ? 'desc' : 'asc';
            else sort = { key, dir: 'asc' };
            render();
            savePreferences();
        }

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
                            <span class="dt-pop-value">${esc(v)}</span>
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

        function closePopover() {
            if (openPopover && openPopover.parentNode) openPopover.parentNode.removeChild(openPopover);
            openPopover = null;
        }

        // --- Exports --------------------------------------------------
        function exportCSV() {
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
            doc.text(`${rows.length} of ${allRows.length} — ${new Date().toLocaleString()}`, 10, startY + 11);
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

        function csvCell(v) {
            const s = String(v == null ? '' : v);
            if (s.indexOf('"') !== -1 || s.indexOf(',') !== -1 || s.indexOf('\n') !== -1) {
                return '"' + s.replace(/"/g, '""') + '"';
            }
            return s;
        }

        function formatDateStr() {
            const d = new Date();
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
        }

        // --- Datetime helpers (DB 'YYYY-MM-DD HH:MM:SS' <-> input) -----
        function toLocalInput(raw) {
            if (!raw) return '';
            return String(raw).replace(' ', 'T').slice(0, 16);
        }
        function fromLocalInput(v) {
            if (!v) return null;
            const s = v.replace('T', ' ');
            return s.length === 16 ? s + ':00' : s;
        }

        // --- Utilities ------------------------------------------------
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
            reload,
            render,
            findRow: id => allRows.find(r => String(rowId(r)) === String(id)),
            getViewRows: applyFiltersAndSort,
            getAllRows: () => allRows,
        };
    }

    window.createDataTable = createDataTable;
})();
