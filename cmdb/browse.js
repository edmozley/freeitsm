/**
 * CMDB Browse page — class sidebar + object table.
 * Click a class to filter; click a row to open the object detail page.
 */

const API = '../api/cmdb/';

let classes = [];
let activeClass = null;
let objects = [];
let searchTimer = null;

/** Escapes text for safe use in HTML. */
// GH #312: person, analyst, team, location, company, supplier, contract (cmdbRecordTypes()).
const RECORD_TYPES = new Set(window.CMDB_RECORD_TYPES || []);

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
/** Shows a toast - an error toast when isError, otherwise a success toast. */
function showInlineToast(msg, isError = false) {
    if (typeof showToast === 'function') showToast(msg, isError ? 'error' : 'success');
    else showToast(msg, 'error');
}
/** Posts a JSON body and returns the parsed JSON reply. */
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return res.json();
}

// ---------------------------------------------------------------------------
// Property filters and columns for the selected class.
//
// The list's state lives in the page URL - ?class=5&search=db&f=12~eq~Production
// &f=14~lte~2027-01-01&cols=12,14 - so a filtered list can be bookmarked or
// sent to somebody and survives a reload. Each f is "<property id>~<op>~<value>"
// (the value is everything after the second ~), the same shape get_objects.php
// takes as f[]. The server checks every filter against the class and drops any
// that no longer apply; what it echoes back (with names for picked records) is
// what the chips show.
// ---------------------------------------------------------------------------

let classProps = [];        // the selected class's property definitions
let filters = [];           // [{pid, op, value, label}]
let columns = [];           // up to 3 property ids
let builderOpen = false;
let builderPick = null;     // {id, name} chosen in a picker value editor
const MAX_COLUMNS = 3;

/** The operators a property type offers, in the order the select lists them. */
function opsFor(type) {
    let ops;
    if (type === 'text') ops = ['has'];
    else if (type === 'number') ops = ['eq', 'gte', 'lte'];
    else if (type === 'date') ops = ['lte', 'gte'];
    else ops = ['eq'];       // boolean, dropdown, object_ref, the record types
    return ops.concat(['set', 'empty']);
}

/** The words for an operator, which for "eq" depend on the type ("equals" for a number, "is" otherwise). */
function opLabel(op, type) {
    if (op === 'eq') return window.t(type === 'number' ? 'cmdb.list.op_equals' : 'cmdb.list.op_is');
    if ((op === 'gte' || op === 'lte') && type === 'date') return window.t(op === 'gte' ? 'cmdb.list.op_on_or_after' : 'cmdb.list.op_on_or_before');
    return window.t('cmdb.list.op_' + ({ has: 'contains', gte: 'at_least', lte: 'at_most', set: 'is_set', empty: 'is_empty' })[op]);
}

/** True for the types whose value is a picked CI or record (an id). */
function isPickType(type) {
    return type === 'object_ref' || RECORD_TYPES.has(type);
}

/** A filter's value as the chip shows it. */
function filterValueText(f, prop) {
    if (f.op === 'set' || f.op === 'empty') return '';
    const type = prop ? prop.property_type : '';
    if (type === 'boolean') return window.t(f.value === '1' ? 'cmdb.new_object.yes' : 'cmdb.new_object.no');
    if (type === 'date') { try { return fmtNaiveDate(f.value); } catch (e) { return f.value; } }
    if (isPickType(type)) {
        if (f.label) return f.label;
        if (f.state === 'removed') return window.t('cmdb.record.removed');
        if (f.state === 'hidden') return window.t('cmdb.record.hidden');
        return '#' + f.value;
    }
    return f.value;
}

/** Reads class, search, filters and columns from the page URL. */
function readUrlState() {
    const q = new URLSearchParams(window.location.search);
    return {
        classId: parseInt(q.get('class') || '', 10) || null,
        search: q.get('search') || '',
        filters: q.getAll('f').map(s => {
            const m = /^(\d+)~([a-z]+)~?([\s\S]*)$/.exec(s);
            return m ? { pid: parseInt(m[1], 10), op: m[2], value: m[3], label: null } : null;
        }).filter(Boolean),
        columns: (q.get('cols') || '').split(',').map(n => parseInt(n, 10)).filter(n => n > 0).slice(0, MAX_COLUMNS)
    };
}

/** The query string for the current state - shared by the page URL and the API call. */
function stateQuery(forApi) {
    const q = new URLSearchParams();
    q.set(forApi ? 'class_id' : 'class', activeClass.id);
    const search = document.getElementById('searchInput').value.trim();
    if (search) q.set('search', search);
    filters.forEach(f => q.append(forApi ? 'f[]' : 'f', f.pid + '~' + f.op + '~' + (f.value ?? '')));
    if (columns.length) q.set('cols', columns.join(','));
    return q.toString();
}

/** Writes the current state into the address bar without adding history entries. */
function writeUrlState() {
    if (!activeClass) return;
    try { history.replaceState(null, '', window.location.pathname + '?' + stateQuery(false)); } catch (e) { /* file:// or sandboxed - nothing to keep */ }
}

/** Loads the selected class's property definitions (for the filter builder, the chips and the columns). */
async function loadClassProps() {
    classProps = [];
    try {
        const res = await fetch(API + 'get_class_properties.php?class_id=' + activeClass.id);
        const data = await res.json();
        if (data.success) classProps = data.properties || [];
    } catch (e) { /* the list still works without filters */ }
    const has = classProps.length > 0;
    document.getElementById('filterBtn').hidden = !has;
    document.getElementById('columnsBtn').hidden = !has;
}

/** The property definition for an id, or undefined. */
function propById(id) {
    return classProps.find(p => p.id === id);
}

/** Draws the filter bar: the active filters as chips, then the builder when it is open. */
function renderFilterBar() {
    const bar = document.getElementById('filterBar');
    if (!bar) return;
    const btn = document.getElementById('filterBtn');
    if (btn) btn.classList.toggle('is-on', filters.length > 0);
    if (filters.length === 0 && !builderOpen) { bar.hidden = true; bar.innerHTML = ''; return; }
    bar.hidden = false;
    const chips = filters.map((f, i) => {
        const prop = propById(f.pid);
        const val = filterValueText(f, prop);
        return `<span class="flt-chip">
            <span><strong>${escapeHtml(prop ? prop.label : '?')}</strong> ${escapeHtml(opLabel(f.op, prop ? prop.property_type : ''))}${val !== '' ? ' <strong>' + escapeHtml(val) + '</strong>' : ''}</span>
            <button type="button" class="flt-chip-x" onclick="removeFilter(${i})" title="${escapeHtml(window.t('cmdb.list.filter_remove'))}" aria-label="${escapeHtml(window.t('cmdb.list.filter_remove'))}">&times;</button>
        </span>`;
    }).join('');
    const clear = filters.length > 1 ? `<button type="button" class="flt-link" onclick="clearFilters()">${escapeHtml(window.t('cmdb.list.filter_clear'))}</button>` : '';
    bar.innerHTML = `${chips}${builderOpen ? builderHtml() : ''}${clear}`;
    if (builderOpen) wireBuilder();
}

/** The builder row: property, operator, a value editor for the type, Add and Cancel. */
function builderHtml() {
    return `<span class="flt-builder" id="fltBuilder">
        <select id="fltProp" aria-label="${escapeHtml(window.t('cmdb.list.filter_property'))}">
            ${classProps.map(p => `<option value="${p.id}">${escapeHtml(p.label)}</option>`).join('')}
        </select>
        <select id="fltOp" aria-label="${escapeHtml(window.t('cmdb.list.filter_operator'))}"></select>
        <span id="fltValueWrap" class="flt-value"></span>
        <button type="button" class="flt-btn flt-btn-primary" onclick="addFilterFromBuilder()">${escapeHtml(window.t('cmdb.list.filter_add'))}</button>
        <button type="button" class="flt-btn" onclick="closeFilterBuilder()">${escapeHtml(window.t('cmdb.new_object.cancel'))}</button>
    </span>`;
}

/** Fills the operator select for the chosen property and redraws the value editor. */
function wireBuilder() {
    const propSel = document.getElementById('fltProp');
    const opSel = document.getElementById('fltOp');
    const refreshOps = () => {
        const prop = propById(parseInt(propSel.value, 10));
        opSel.innerHTML = opsFor(prop.property_type).map(op => `<option value="${op}">${escapeHtml(opLabel(op, prop.property_type))}</option>`).join('');
        renderValueEditor();
    };
    propSel.addEventListener('change', refreshOps);
    opSel.addEventListener('change', renderValueEditor);
    refreshOps();
    propSel.focus();
}

/** Draws the value editor that fits the chosen property and operator (none for "is set" / "is empty"). */
function renderValueEditor() {
    const wrap = document.getElementById('fltValueWrap');
    const prop = propById(parseInt(document.getElementById('fltProp').value, 10));
    const op = document.getElementById('fltOp').value;
    builderPick = null;
    if (op === 'set' || op === 'empty') { wrap.innerHTML = ''; return; }
    const label = escapeHtml(window.t('cmdb.list.filter_value'));
    const type = prop.property_type;
    if (type === 'number') wrap.innerHTML = `<input type="number" id="fltValue" step="any" aria-label="${label}">`;
    else if (type === 'date') wrap.innerHTML = `<input type="date" id="fltValue" aria-label="${label}">`;
    else if (type === 'boolean') wrap.innerHTML = `<select id="fltValue" aria-label="${label}">
            <option value="1">${escapeHtml(window.t('cmdb.new_object.yes'))}</option>
            <option value="0">${escapeHtml(window.t('cmdb.new_object.no'))}</option></select>`;
    else if (type === 'dropdown') wrap.innerHTML = `<select id="fltValue" aria-label="${label}">
            ${(prop.options || []).map(o => { const v = (o && typeof o === 'object') ? o.value : o; return `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`; }).join('')}</select>`;
    else if (isPickType(type)) {
        wrap.innerHTML = `<span class="ac-wrap flt-pick"><input type="text" id="fltValue" autocomplete="off" aria-label="${label}"
                placeholder="${escapeHtml(window.t('cmdb.list.filter_pick_placeholder'))}"><span class="ac-results" id="fltResults"></span></span>`;
        wirePicker(prop);
    } else wrap.innerHTML = `<input type="text" id="fltValue" maxlength="255" aria-label="${label}">`;
    const input = document.getElementById('fltValue');
    if (input && !isPickType(type)) {
        input.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); addFilterFromBuilder(); } });
    }
}

/** A type-ahead over the property's target class (CI) or record type; the chosen one goes in builderPick. */
function wirePicker(prop) {
    const input = document.getElementById('fltValue');
    const results = document.getElementById('fltResults');
    let timer = null, current = [], hi = -1;
    const draw = () => {
        results.innerHTML = current.length === 0
            ? `<div class="ac-empty">${escapeHtml(window.t('cmdb.new_object.no_matches'))}</div>`
            : current.map((r, i) => `<div class="ac-result ${i === hi ? 'highlighted' : ''}" data-idx="${i}"><span>${escapeHtml(r.name)}</span></div>`).join('');
        results.classList.add('active');
        results.querySelectorAll('.ac-result').forEach(el => el.addEventListener('mousedown', e => {
            e.preventDefault(); pick(current[parseInt(el.dataset.idx, 10)]);
        }));
    };
    const pick = r => { builderPick = { id: r.id, name: r.name }; input.value = r.name; results.classList.remove('active'); };
    const search = async q => {
        const url = RECORD_TYPES.has(prop.property_type)
            ? API + 'search_records.php?type=' + encodeURIComponent(prop.property_type) + '&q=' + encodeURIComponent(q)
            : API + 'search_objects.php?q=' + encodeURIComponent(q) + (prop.target_class_id ? '&class_id=' + prop.target_class_id : '');
        try {
            const data = await (await fetch(url)).json();
            current = data.success ? (data.results || []) : [];
        } catch (e) { current = []; }
        hi = -1; draw();
    };
    input.addEventListener('input', () => {
        builderPick = null;
        const q = input.value.trim();
        clearTimeout(timer);
        // A CI search needs a letter; a record list can open on its first few.
        if (q === '' && !RECORD_TYPES.has(prop.property_type)) { results.classList.remove('active'); return; }
        timer = setTimeout(() => search(q), 200);
    });
    input.addEventListener('focus', () => { if (RECORD_TYPES.has(prop.property_type) && !input.value) search(''); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (results.classList.contains('active') && hi >= 0) pick(current[hi]);
            else if (builderPick) addFilterFromBuilder();
            return;
        }
        if (!results.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); hi = Math.min(current.length - 1, hi + 1); draw(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); hi = Math.max(0, hi - 1); draw(); }
        else if (e.key === 'Escape') results.classList.remove('active');
    });
    input.addEventListener('blur', () => setTimeout(() => results.classList.remove('active'), 200));
}

/** Opens the builder (Filter button). */
function openFilterBuilder() {
    if (!activeClass || classProps.length === 0) return;
    closeColumnsMenu();
    builderOpen = true;
    renderFilterBar();
}

/** Closes the builder without adding anything. */
function closeFilterBuilder() {
    builderOpen = false;
    renderFilterBar();
}

/** Turns the builder's choices into a filter, if the value is there, and reloads the list. */
function addFilterFromBuilder() {
    const prop = propById(parseInt(document.getElementById('fltProp').value, 10));
    const op = document.getElementById('fltOp').value;
    if (!prop) return;
    let value = '', label = null;
    if (op !== 'set' && op !== 'empty') {
        const input = document.getElementById('fltValue');
        if (isPickType(prop.property_type)) {
            if (!builderPick) { showInlineToast(window.t('cmdb.list.filter_pick_one'), true); input && input.focus(); return; }
            value = String(builderPick.id); label = builderPick.name;
        } else {
            value = input ? input.value.trim() : '';
            if (value === '') { showInlineToast(window.t('cmdb.list.filter_need_value'), true); input && input.focus(); return; }
        }
    }
    filters.push({ pid: prop.id, op, value, label });
    builderOpen = false;
    renderFilterBar();
    reloadList();
}

/** Removes one filter chip and reloads. */
function removeFilter(i) {
    filters.splice(i, 1);
    renderFilterBar();
    reloadList();
}

/** Removes every filter and reloads. */
function clearFilters() {
    filters = [];
    renderFilterBar();
    reloadList();
}

/** Saves the state to the URL and reloads the objects. */
function reloadList() {
    writeUrlState();
    loadObjects();
}

/** Opens or closes the Columns menu: tick up to three of the class's properties to show as columns. */
function toggleColumnsMenu(ev) {
    if (ev) ev.stopPropagation();
    const menu = document.getElementById('columnsMenu');
    if (!menu.hidden) { closeColumnsMenu(); return; }
    const full = columns.length >= MAX_COLUMNS;
    menu.innerHTML = `<div class="col-menu-head">${escapeHtml(window.t('cmdb.list.columns_hint', { n: MAX_COLUMNS }))}</div>`
        + classProps.map(p => {
            const on = columns.includes(p.id);
            return `<label class="col-menu-item${!on && full ? ' is-off' : ''}">
                <input type="checkbox" value="${p.id}" ${on ? 'checked' : ''} ${!on && full ? 'disabled' : ''} onchange="toggleColumn(${p.id}, this.checked)">
                <span>${escapeHtml(p.label)}</span></label>`;
        }).join('');
    const btn = document.getElementById('columnsBtn');
    const r = btn.getBoundingClientRect();
    menu.style.top = (r.bottom + 4) + 'px';
    menu.style.left = Math.max(8, Math.min(r.left, window.innerWidth - 268)) + 'px';
    menu.hidden = false;
}

/** Closes the Columns menu. */
function closeColumnsMenu() {
    const menu = document.getElementById('columnsMenu');
    if (menu) menu.hidden = true;
}

/** Adds or removes a property column, then redraws the menu and reloads the list. */
function toggleColumn(pid, on) {
    columns = columns.filter(c => c !== pid);
    if (on && columns.length < MAX_COLUMNS) columns.push(pid);
    document.getElementById('columnsBtn').classList.toggle('is-on', columns.length > 0);
    closeColumnsMenu();
    toggleColumnsMenu();
    reloadList();
}

document.addEventListener('click', e => {
    const menu = document.getElementById('columnsMenu');
    if (menu && !menu.hidden && !menu.contains(e.target)) closeColumnsMenu();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeColumnsMenu(); });

/** One property value in a table cell. */
function columnCellHtml(v, prop) {
    const dash = '<span style="color:var(--text-dim, #d1d5db);">—</span>';
    if (v === undefined || v === null || !prop) return dash;
    const type = prop.property_type;
    if (type === 'boolean') return escapeHtml(window.t(v ? 'cmdb.new_object.yes' : 'cmdb.new_object.no'));
    if (type === 'date') { try { return escapeHtml(fmtNaiveDate(v)); } catch (e) { return escapeHtml(v); } }
    if (type === 'dropdown') {
        const opt = (prop.options || []).find(o => o && o.value === v);
        const col = opt && opt.colour;
        return col ? `<span class="col-pill" style="background:${escapeHtml(col)};">${escapeHtml(v)}</span>` : escapeHtml(v);
    }
    if (typeof v === 'object') {
        if (v.state === 'removed') return `<span class="col-gone">${escapeHtml(window.t('cmdb.record.removed'))}</span>`;
        if (v.state === 'hidden' || v.name === null) return `<span class="col-gone">${escapeHtml(window.t('cmdb.record.hidden'))}</span>`;
        return escapeHtml(v.name) + (v.state === 'inactive' ? ` <span class="col-gone">${escapeHtml(window.t('cmdb.record.inactive'))}</span>` : '');
    }
    return escapeHtml(String(v));
}

document.addEventListener('DOMContentLoaded', () => {
    loadClasses();
});

/** Loads the active CMDB classes from get_classes.php into the sidebar, or a pointer to settings when there are none. */
async function loadClasses() {
    const list = document.getElementById('classList');
    try {
        const res = await fetch(API + 'get_classes.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.list.classes_load_failed'));
        classes = (data.classes || []).filter(c => c.is_active);
        if (classes.length === 0) {
            list.innerHTML = `<div style="padding: 16px; color: #6b7280; font-size: 13px;">
                ${window.t('cmdb.list.no_classes', { link: `<a href="settings/" style="color: #be185d;">${escapeHtml(window.t('cmdb.list.no_classes_link'))}</a>` })}
            </div>`;
            return;
        }
        renderClassList();
        // A bookmarked or shared list: ?class=5&f=...&cols=...
        const st = readUrlState();
        if (st.classId && classes.some(c => c.id === st.classId)) selectClass(st.classId, st);
    } catch (err) {
        list.innerHTML = `<div style="padding: 16px; color: #b91c1c; font-size: 13px;">${escapeHtml(window.t('cmdb.list.error_prefix', { message: err.message }))}</div>`;
    }
}

/** Draws the class sidebar with each class's object count, highlighting the selected one. */
function renderClassList() {
    const list = document.getElementById('classList');
    list.innerHTML = classes.map(c => `
        <div class="class-item ${activeClass && activeClass.id === c.id ? 'active' : ''} ${c.object_count === 0 ? 'empty' : ''}"
             onclick="selectClass(${c.id})">
            <span>${escapeHtml(c.name)}</span>
            <span class="count">${c.object_count}</span>
        </div>
    `).join('');
}

/**
 * Selects a class - sets the title, enables New and loads its objects. A click
 * starts clean (the filters belong to the class); `fromUrl` restores a
 * bookmarked list's search, filters and columns instead.
 */
async function selectClass(id, fromUrl = null) {
    activeClass = classes.find(c => c.id === id);
    if (!activeClass) return;
    document.getElementById('mainTitle').textContent = activeClass.name;
    document.getElementById('newObjectBtn').disabled = false;
    document.getElementById('searchInput').value = fromUrl ? fromUrl.search : '';
    filters = fromUrl ? fromUrl.filters : [];
    columns = fromUrl ? fromUrl.columns : [];
    builderOpen = false;
    closeColumnsMenu();
    renderClassList();
    renderFilterBar();
    await loadClassProps();
    columns = columns.filter(c => propById(c));
    document.getElementById('columnsBtn').classList.toggle('is-on', columns.length > 0);
    writeUrlState();
    loadObjects();
}

/**
 * Loads the selected class's objects from get_objects.php - the search box, the
 * filters and the chosen columns - and draws the table.
 */
let loadSeq = 0;
async function loadObjects() {
    if (!activeClass) return;
    const seq = ++loadSeq;
    const list = document.getElementById('objectList');
    list.innerHTML = `<div class="empty-state"><p>${escapeHtml(window.t('cmdb.list.loading'))}</p></div>`;
    try {
        const res = await fetch(API + 'get_objects.php?' + stateQuery(true));
        const data = await res.json();
        if (seq !== loadSeq) return;   // a newer search or filter has already gone out
        if (!data.success) throw new Error(data.error || window.t('cmdb.list.objects_load_failed'));
        objects = data.objects || [];
        // The server's view of the filters wins: a filter for a property since
        // deleted is dropped, and picked records come back with their names.
        if (Array.isArray(data.filters)) {
            filters = data.filters.map(f => ({ pid: f.property_id, op: f.op, value: f.value, label: f.label ?? null, state: f.state }));
            if (data.ignored_filters > 0) {
                showInlineToast(window.t('cmdb.list.filter_dropped', { n: data.ignored_filters }), true);
                writeUrlState();
            }
            // Never redraw under somebody half way through adding a filter.
            if (!builderOpen) renderFilterBar();
        }
        renderObjects();
    } catch (err) {
        list.innerHTML = `<div class="empty-state"><p style="color: #b91c1c;">${escapeHtml(window.t('cmdb.list.error_prefix', { message: err.message }))}</p></div>`;
    }
}

/** Draws the object table - name (with a Planned pill), parent, child count and last updated - or the empty state. */
function renderObjects() {
    const list = document.getElementById('objectList');
    const searching = filters.length > 0 || document.getElementById('searchInput').value.trim() !== '';
    if (objects.length === 0 && searching) {
        list.innerHTML = `
            <div class="empty-state">
                <h3>${escapeHtml(window.t('cmdb.list.no_match_heading'))}</h3>
                <p>${escapeHtml(window.t('cmdb.list.no_match_hint'))}</p>
            </div>`;
        return;
    }
    if (objects.length === 0) {
        list.innerHTML = `
            <div class="empty-state">
                <h3>${window.t('cmdb.list.no_objects_heading', { name: `<em>${escapeHtml(activeClass.name)}</em>` })}</h3>
                <p>${window.t('cmdb.list.no_objects_hint', { new: `<strong>${escapeHtml(window.t('common.action_row.new'))}</strong>` })}</p>
            </div>`;
        return;
    }
    const colProps = columns.map(propById).filter(Boolean);
    list.innerHTML = `
        <table>
            <thead>
                <tr>
                    <th>${escapeHtml(window.t('cmdb.list.col_name'))}</th>
                    ${colProps.map(p => `<th>${escapeHtml(p.label)}</th>`).join('')}
                    <th>${escapeHtml(window.t('cmdb.list.col_parent'))}</th>
                    <th>${escapeHtml(window.t('cmdb.list.col_children'))}</th>
                    <th style="width: 180px;">${escapeHtml(window.t('cmdb.list.col_updated'))}</th>
                </tr>
            </thead>
            <tbody>
                ${objects.map(o => `
                    <tr onclick="openObject(${o.id})"${o.is_planned ? ' class="is-planned"' : ''}>
                        <td><span class="object-name">${escapeHtml(o.name)}</span>${o.is_planned ? ` <span class="planned-pill" title="${escapeHtml(window.t('cmdb.list.planned_title'))}">${escapeHtml(window.t('cmdb.list.planned_pill'))}</span>` : ''}</td>
                        ${colProps.map(p => `<td>${columnCellHtml((o.columns || {})[p.id], p)}</td>`).join('')}
                        <td>${o.parent_id
                            ? `<span class="parent-link"><strong>${escapeHtml(o.parent_name || '?')}</strong> <span style="color:#9ca3af">(${escapeHtml(o.parent_class_name || '')})</span></span>`
                            : '<span style="color:#d1d5db;">—</span>'}</td>
                        <td>${o.child_count > 0 ? `<span class="badge-count">${o.child_count}</span>` : '<span style="color:#d1d5db;">—</span>'}</td>
                        <td style="color:#6b7280; font-size: 13px;">${formatDate(o.updated_datetime)}</td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

/** Formats a UTC timestamp in the analyst's zone and date format, or returns it unchanged if that fails. */
function formatDate(s) {
    if (!s) return '';
    try {
        const d = parseUTCDate(s);
        return fmtDateTime(d);
    } catch (e) { return s; }
}

/** Reloads the object list 200ms after the analyst stops typing in the search box. */
function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => reloadList(), 250);
}

/** Opens an object's detail page. */
function openObject(id) {
    window.location.href = 'object.php?id=' + id;
}

// New Object modal — fetches the class's properties so any required ones can be
// filled inline before saving. Optional properties stay on the detail page.

let newObjectRequiredProps = []; // properties that must be filled to create

/** Opens the New object modal for the selected class and adds inputs for any properties the class marks required. */
async function openNewObjectModal() {
    if (!activeClass) return;
    document.getElementById('newObjectClassName').textContent = activeClass.name;
    document.getElementById('newObjectName').value = '';
    document.getElementById('newObjectReqFields').innerHTML = '';
    // Reset the Planned toggle so it doesn't carry state from a previous open
    const plannedCb = document.getElementById('newObjectIsPlanned');
    if (plannedCb) plannedCb.checked = false;
    newObjectRequiredProps = [];
    document.getElementById('newObjectModal').classList.add('active');
    setTimeout(() => document.getElementById('newObjectName').focus(), 0);

    // Pull property defs and render required ones
    try {
        const res = await fetch(API + 'get_class_properties.php?class_id=' + activeClass.id);
        const data = await res.json();
        if (!data.success) return;
        newObjectRequiredProps = (data.properties || []).filter(p => p.is_required);
        if (newObjectRequiredProps.length > 0) renderRequiredFieldEditors(newObjectRequiredProps);
    } catch (e) { /* silent — modal still works for non-required-field classes */ }
}

/** Closes the New object modal and forgets its required properties. */
function closeNewObjectModal() {
    document.getElementById('newObjectModal').classList.remove('active');
    newObjectRequiredProps = [];
}

/** Renders an input for each required property in the New object modal and wires the object-reference look-ups. */
function renderRequiredFieldEditors(reqProps) {
    const container = document.getElementById('newObjectReqFields');
    let html = `<div class="req-fields-divider">${escapeHtml(window.t('cmdb.new_object.required_fields', { name: activeClass.name }))}</div>`;
    reqProps.forEach(p => {
        html += `<div class="form-group">
            <label>${escapeHtml(p.label)}<span class="req-mark">*</span></label>
            ${renderFieldEditor(p)}
        </div>`;
    });
    container.innerHTML = html;
    // Wire any object_ref autocompletes that got rendered
    reqProps.forEach(p => { if (p.property_type === 'object_ref' || RECORD_TYPES.has(p.property_type)) wireRefAutocomplete(p); });
}

/**
 * Returns the input HTML for one property by type - text, number, date, yes/no, drop-down, or an object-reference search box.
 */
function renderFieldEditor(p) {
    const id = 'newProp_' + p.id;
    // GH #312: a person / team / location ... - the same search box, over records.
    if (RECORD_TYPES.has(p.property_type)) {
        return `<div class="ac-wrap">
                <input type="text" id="${id}" data-pid="${p.id}" data-ptype="${escapeHtml(p.property_type)}" autocomplete="off"
                       placeholder="${escapeHtml(window.t('cmdb.record.search_placeholder', { type: window.t('cmdb.record.type_' + p.property_type) }))}">
                <input type="hidden" id="${id}_id">
                <div class="ac-results" id="${id}_results"></div>
            </div>`;
    }
    switch (p.property_type) {
        case 'text':
            return `<input type="text" id="${id}" data-pid="${p.id}" data-ptype="text">`;
        case 'number':
            return `<input type="number" id="${id}" data-pid="${p.id}" data-ptype="number" step="any">`;
        case 'date':
            return `<input type="date" id="${id}" data-pid="${p.id}" data-ptype="date">`;
        case 'boolean':
            return `<select id="${id}" data-pid="${p.id}" data-ptype="boolean">
                <option value="">${escapeHtml(window.t('cmdb.new_object.pick_one'))}</option>
                <option value="1">${escapeHtml(window.t('cmdb.new_object.yes'))}</option>
                <option value="0">${escapeHtml(window.t('cmdb.new_object.no'))}</option>
            </select>`;
        case 'dropdown':
            return `<select id="${id}" data-pid="${p.id}" data-ptype="dropdown">
                <option value="">${escapeHtml(window.t('cmdb.new_object.pick_one'))}</option>
                ${(p.options || []).map(o => {
                    const v = (o && typeof o === 'object') ? o.value : o;
                    return `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`;
                }).join('')}
            </select>`;
        case 'object_ref':
            return `<div class="ac-wrap">
                <input type="text" id="${id}" data-pid="${p.id}" data-ptype="object_ref" data-targetclass="${p.target_class_id || ''}"
                       autocomplete="off" placeholder="${escapeHtml(window.t('cmdb.new_object.search_placeholder', { name: p.target_class_name || window.t('cmdb.new_object.objects') }))}">
                <input type="hidden" id="${id}_id">
                <div class="ac-results" id="${id}_results"></div>
            </div>
            ${p.target_class_name ? `<small style="color:#6b7280;font-size:12px;">${window.t('cmdb.new_object.picks_from', { name: `<strong>${escapeHtml(p.target_class_name)}</strong>` })}</small>` : ''}`;
        default:
            return `<input type="text" id="${id}" data-pid="${p.id}" data-ptype="text">`;
    }
}

/**
 * Wires an object-reference input to search_objects.php (limited to the target class) with a keyboard-navigable result list; the picked id goes in a hidden input.
 */
function wireRefAutocomplete(prop) {
    const inputEl   = document.getElementById('newProp_' + prop.id);
    const idEl      = document.getElementById('newProp_' + prop.id + '_id');
    const resultsEl = document.getElementById('newProp_' + prop.id + '_results');
    if (!inputEl || !idEl || !resultsEl) return;
    let timer = null;
    let highlighted = -1;
    let current = [];

    /** Draws the look-up results (or "no matches") with the highlighted row marked. */
    const renderResults = () => {
        if (current.length === 0) {
            resultsEl.innerHTML = `<div class="ac-empty">${escapeHtml(window.t('cmdb.new_object.no_matches'))}</div>`;
            resultsEl.classList.add('active');
            return;
        }
        resultsEl.innerHTML = current.map((r, i) => `
            <div class="ac-result ${i === highlighted ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="ac-class">${escapeHtml(r.class_name || '')}</span>
            </div>`).join('');
        resultsEl.classList.add('active');
        resultsEl.querySelectorAll('.ac-result').forEach(el => {
            // mousedown so the pick fires before the input's blur handler
            el.addEventListener('mousedown', e => {
                e.preventDefault();
                pick(current[parseInt(el.dataset.idx, 10)]);
            });
        });
    };

    /** Fills the input with the chosen object's name and its id into the hidden input, then closes the list. */
    const pick = (r) => {
        inputEl.value = r.name;
        idEl.value = r.id;
        resultsEl.classList.remove('active');
    };

    inputEl.addEventListener('input', () => {
        // If the user changes the text after picking, the previous id is stale — clear it
        idEl.value = '';
        const q = inputEl.value.trim();
        if (timer) clearTimeout(timer);
        if (q === '') { resultsEl.classList.remove('active'); return; }
        timer = setTimeout(async () => {
            try {
                // GH #312: record properties search records (in the active company, where the new CI goes).
                const url = RECORD_TYPES.has(prop.property_type)
                    ? API + 'search_records.php?type=' + encodeURIComponent(prop.property_type) + '&q=' + encodeURIComponent(q)
                    : API + 'search_objects.php?q=' + encodeURIComponent(q)
                    + (prop.target_class_id ? '&class_id=' + prop.target_class_id : '');
                const res = await fetch(url);
                const data = await res.json();
                current = data.success ? (data.results || []) : [];
                highlighted = -1;
                renderResults();
            } catch (e) { /* silent */ }
        }, 200);
    });

    inputEl.addEventListener('keydown', e => {
        if (!resultsEl.classList.contains('active')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); highlighted = Math.min(current.length - 1, highlighted + 1); renderResults(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(0, highlighted - 1); renderResults(); }
        else if (e.key === 'Enter' && highlighted >= 0) { e.preventDefault(); pick(current[highlighted]); }
        else if (e.key === 'Escape') resultsEl.classList.remove('active');
    });

    inputEl.addEventListener('blur', () => {
        // Hide after a beat so a result click can still register
        setTimeout(() => resultsEl.classList.remove('active'), 200);
    });
}

/**
 * Reads the required property inputs into {property_id, value} pairs typed by property, and lists the labels of any left empty.
 */
function collectRequiredFieldValues() {
    const out = [];
    const missing = [];
    newObjectRequiredProps.forEach(p => {
        const id = 'newProp_' + p.id;
        let raw = null;
        if (p.property_type === 'object_ref' || RECORD_TYPES.has(p.property_type)) {
            const refIdEl = document.getElementById(id + '_id');
            raw = refIdEl ? refIdEl.value : '';
            if (raw === '') { missing.push(p.label); return; }
            out.push({ property_id: p.id, value: parseInt(raw, 10) });
        } else {
            const el = document.getElementById(id);
            if (!el) return;
            raw = el.value;
            if (raw === '' || raw === null) { missing.push(p.label); return; }
            if (p.property_type === 'boolean') out.push({ property_id: p.id, value: raw === '1' });
            else if (p.property_type === 'number') out.push({ property_id: p.id, value: Number(raw) });
            else out.push({ property_id: p.id, value: raw });
        }
    });
    return { values: out, missing };
}

/**
 * Creates the object through save_object.php with its required property values and Planned flag, then opens its detail page.
 */
async function createObject() {
    const name = document.getElementById('newObjectName').value.trim();
    if (name === '') {
        showInlineToast(window.t('cmdb.new_object.name_required'), true);
        return;
    }
    const { values, missing } = collectRequiredFieldValues();
    if (missing.length > 0) {
        showInlineToast(window.t('cmdb.new_object.missing_required', { fields: missing.join(', ') }), true);
        return;
    }
    const isPlanned = document.getElementById('newObjectIsPlanned')?.checked || false;
    try {
        const data = await postJson(API + 'save_object.php', {
            class_id: activeClass.id,
            name,
            parent_id: null,
            is_planned: isPlanned,
            property_values: values
        });
        if (!data.success) throw new Error(data.error || window.t('cmdb.new_object.save_failed'));
        closeNewObjectModal();
        // Jump straight into the new object's detail page so the analyst can fill in optional properties
        window.location.href = 'object.php?id=' + data.id;
    } catch (err) {
        showInlineToast(window.t('cmdb.new_object.error_prefix', { message: err.message }), true);
    }
}

// Allow Enter to submit the new-object name
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('newObjectName');
    if (input) {
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); createObject(); }
        });
    }
});
