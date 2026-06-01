/**
 * CMDB Browse page — class sidebar + object table.
 * Click a class to filter; click a row to open the object detail page.
 */

const API = '../api/cmdb/';

let classes = [];
let activeClass = null;
let objects = [];
let searchTimer = null;

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}
function showInlineToast(msg, isError = false) {
    if (typeof showToast === 'function') showToast(msg, isError ? 'error' : 'success');
    else showToast(msg, 'error');
}
async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return res.json();
}

document.addEventListener('DOMContentLoaded', () => {
    loadClasses();
});

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
    } catch (err) {
        list.innerHTML = `<div style="padding: 16px; color: #b91c1c; font-size: 13px;">${escapeHtml(window.t('cmdb.list.error_prefix', { message: err.message }))}</div>`;
    }
}

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

function selectClass(id) {
    activeClass = classes.find(c => c.id === id);
    if (!activeClass) return;
    document.getElementById('mainTitle').textContent = activeClass.name;
    document.getElementById('newObjectBtn').disabled = false;
    document.getElementById('searchInput').value = '';
    renderClassList();
    loadObjects();
}

async function loadObjects(searchOverride = null) {
    if (!activeClass) return;
    const list = document.getElementById('objectList');
    list.innerHTML = `<div class="empty-state"><p>${escapeHtml(window.t('cmdb.list.loading'))}</p></div>`;
    const search = searchOverride !== null ? searchOverride : document.getElementById('searchInput').value.trim();
    const url = API + 'get_objects.php?class_id=' + activeClass.id + (search ? '&search=' + encodeURIComponent(search) : '');
    try {
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.list.objects_load_failed'));
        objects = data.objects || [];
        renderObjects();
    } catch (err) {
        list.innerHTML = `<div class="empty-state"><p style="color: #b91c1c;">${escapeHtml(window.t('cmdb.list.error_prefix', { message: err.message }))}</p></div>`;
    }
}

function renderObjects() {
    const list = document.getElementById('objectList');
    if (objects.length === 0) {
        list.innerHTML = `
            <div class="empty-state">
                <h3>${window.t('cmdb.list.no_objects_heading', { name: `<em>${escapeHtml(activeClass.name)}</em>` })}</h3>
                <p>${window.t('cmdb.list.no_objects_hint', { new: `<strong>${escapeHtml(window.t('cmdb.list.new'))}</strong>` })}</p>
            </div>`;
        return;
    }
    list.innerHTML = `
        <table>
            <thead>
                <tr>
                    <th>${escapeHtml(window.t('cmdb.list.col_name'))}</th>
                    <th>${escapeHtml(window.t('cmdb.list.col_parent'))}</th>
                    <th>${escapeHtml(window.t('cmdb.list.col_children'))}</th>
                    <th style="width: 180px;">${escapeHtml(window.t('cmdb.list.col_updated'))}</th>
                </tr>
            </thead>
            <tbody>
                ${objects.map(o => `
                    <tr onclick="openObject(${o.id})"${o.is_planned ? ' class="is-planned"' : ''}>
                        <td><span class="object-name">${escapeHtml(o.name)}</span>${o.is_planned ? ` <span class="planned-pill" title="${escapeHtml(window.t('cmdb.list.planned_title'))}">${escapeHtml(window.t('cmdb.list.planned_pill'))}</span>` : ''}</td>
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

function formatDate(s) {
    if (!s) return '';
    try {
        const d = new Date(s.replace(' ', 'T') + 'Z');
        return d.toLocaleString();
    } catch (e) { return s; }
}

function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => loadObjects(), 200);
}

function openObject(id) {
    window.location.href = 'object.php?id=' + id;
}

// New Object modal — fetches the class's properties so any required ones can be
// filled inline before saving. Optional properties stay on the detail page.

let newObjectRequiredProps = []; // properties that must be filled to create

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

function closeNewObjectModal() {
    document.getElementById('newObjectModal').classList.remove('active');
    newObjectRequiredProps = [];
}

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
    reqProps.forEach(p => { if (p.property_type === 'object_ref') wireRefAutocomplete(p); });
}

function renderFieldEditor(p) {
    const id = 'newProp_' + p.id;
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

function wireRefAutocomplete(prop) {
    const inputEl   = document.getElementById('newProp_' + prop.id);
    const idEl      = document.getElementById('newProp_' + prop.id + '_id');
    const resultsEl = document.getElementById('newProp_' + prop.id + '_results');
    if (!inputEl || !idEl || !resultsEl) return;
    let timer = null;
    let highlighted = -1;
    let current = [];

    const renderResults = () => {
        if (current.length === 0) {
            resultsEl.innerHTML = `<div class="ac-empty">${escapeHtml(window.t('cmdb.new_object.no_matches'))}</div>`;
            resultsEl.classList.add('active');
            return;
        }
        resultsEl.innerHTML = current.map((r, i) => `
            <div class="ac-result ${i === highlighted ? 'highlighted' : ''}" data-idx="${i}">
                <span>${escapeHtml(r.name)}</span>
                <span class="ac-class">${escapeHtml(r.class_name)}</span>
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
                const url = API + 'search_objects.php?q=' + encodeURIComponent(q)
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

function collectRequiredFieldValues() {
    const out = [];
    const missing = [];
    newObjectRequiredProps.forEach(p => {
        const id = 'newProp_' + p.id;
        let raw = null;
        if (p.property_type === 'object_ref') {
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
