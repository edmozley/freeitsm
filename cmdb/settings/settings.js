/**
 * CMDB Settings Page
 * Handles three tabs: Classes (with nested per-class properties), Relationship Types, AI Integration.
 */

const API = '../../api/cmdb/';

let currentTab = 'classes';
let classes = [];
let relTypes = [];
let propsForClass = [];
let activeClassForProps = null; // class object whose properties are open in the modal

// ---------- Utilities ----------

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]);
}

function slugify(s) {
    return String(s ?? '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');
}

function showInlineToast(msg, isError = false) {
    if (typeof showToast === 'function') {
        showToast(msg, isError ? 'error' : 'success');
    } else {
        showToast(msg, 'error');
    }
}

async function postJson(url, body) {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {})
    });
    return res.json();
}

// ---------- Tabs ----------

function switchTab(tab) {
    currentTab = tab;
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
    if (btn) btn.classList.add('active');
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    document.getElementById(tab + '-tab').classList.add('active');

    if (tab === 'classes') loadClasses();
    else if (tab === 'relationship-types') loadRelTypes();
    else if (tab === 'ai') loadAiSettings();
    else if (tab === 'left-panel') loadSidebarMode();
}

// ---------- Left panel preference ----------
// 'always' vs 'hover', stored per-analyst via user_preferences. header.php
// reads the same key on every CMDB page and toggles .sidebar-hover on the
// .browse-container. Also editable under System → Preferences.
const SIDEBAR_MODE_KEY = 'cmdb_sidebar_mode';
let sidebarModeLoaded = false;
async function loadSidebarMode() {
    if (sidebarModeLoaded) return;
    sidebarModeLoaded = true;
    try {
        const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
        const d = await r.json();
        const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
        document.querySelectorAll('input[name="cmdbSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
    } catch (e) {
        const first = document.querySelector('input[name="cmdbSidebarMode"][value="always"]');
        if (first) first.checked = true;
    }
}
async function saveSidebarMode(value) {
    if (value !== 'always' && value !== 'hover') return;
    try {
        const r = await fetch('../../api/system/set_user_preference.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ key: SIDEBAR_MODE_KEY, value: value })
        });
        const d = await r.json();
        if (d.success) showInlineToast(window.t('cmdb.settings.left_panel_saved'));
    } catch (e) { /* no-op */ }
}

// ---------- Classes ----------

async function loadClasses() {
    const tbody = document.getElementById('classesTableBody');
    tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${escapeHtml(window.t('cmdb.settings.loading'))}</td></tr>`;
    try {
        const res = await fetch(API + 'get_classes.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.classes_load_failed'));
        classes = data.classes;
        renderClasses();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${escapeHtml(window.t('cmdb.settings.error_prefix', { message: err.message }))}</td></tr>`;
    }
}

function renderClasses() {
    const tbody = document.getElementById('classesTableBody');
    if (!classes.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${window.t('cmdb.settings.no_classes')}</td></tr>`;
        return;
    }
    tbody.innerHTML = classes.map(c => `
        <tr>
            <td><strong>${escapeHtml(c.name)}</strong></td>
            <td><span class="key-hint">${escapeHtml(c.class_key)}</span></td>
            <td style="color: #6b7280;">${escapeHtml(c.description || '')}</td>
            <td><span class="badge clickable" onclick="openPropsModal(${c.id})">${c.property_count} ${c.property_count === 1 ? escapeHtml(window.t('cmdb.settings.property')) : escapeHtml(window.t('cmdb.settings.properties'))}</span></td>
            <td>${c.display_order}</td>
            <td><span class="badge ${c.is_active ? 'active' : 'inactive'}">${c.is_active ? escapeHtml(window.t('cmdb.settings.active')) : escapeHtml(window.t('cmdb.settings.inactive'))}</span></td>
            <td>
                <button class="action-btn" title="${escapeHtml(window.t('cmdb.settings.edit'))}" onclick="openClassModal(${c.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="${escapeHtml(window.t('cmdb.settings.delete'))}" onclick="deleteClass(${c.id}, '${escapeHtml(c.name).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openClassModal(id = null) {
    const cls = id ? classes.find(c => c.id === id) : null;
    document.getElementById('classModalTitle').textContent = cls ? window.t('cmdb.settings.class_modal_edit') : window.t('cmdb.settings.class_modal_add');
    document.getElementById('classId').value = cls ? cls.id : '';
    document.getElementById('className').value = cls ? cls.name : '';
    document.getElementById('classKey').value = cls ? cls.class_key : '';
    document.getElementById('classDescription').value = cls ? (cls.description || '') : '';
    document.getElementById('classDisplayOrder').value = cls ? cls.display_order : 0;
    document.getElementById('classIsActive').checked = cls ? cls.is_active : true;
    document.getElementById('classModal').classList.add('active');
    setTimeout(() => document.getElementById('className').focus(), 0);
}

function closeClassModal() { document.getElementById('classModal').classList.remove('active'); }

async function saveClass(ev) {
    if (ev) ev.preventDefault();
    const payload = {
        id: document.getElementById('classId').value || null,
        name: document.getElementById('className').value,
        class_key: document.getElementById('classKey').value,
        description: document.getElementById('classDescription').value,
        display_order: parseInt(document.getElementById('classDisplayOrder').value, 10) || 0,
        is_active: document.getElementById('classIsActive').checked
    };
    try {
        const data = await postJson(API + 'save_class.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.save_failed'));
        closeClassModal();
        showInlineToast(payload.id ? window.t('cmdb.settings.class_updated') : window.t('cmdb.settings.class_created'));
        loadClasses();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

async function deleteClass(id, name) {
    if (!(await showConfirm({ title: window.t('cmdb.settings.class_delete_title'), message: window.t('cmdb.settings.class_delete_confirm', { name }), okLabel: window.t('cmdb.settings.class_delete_ok'), okClass: 'danger' }))) return;
    try {
        const data = await postJson(API + 'delete_class.php', { id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.delete_failed'));
        showInlineToast(window.t('cmdb.settings.class_deleted'));
        loadClasses();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

// Auto-suggest class_key from name in the Add flow
document.addEventListener('DOMContentLoaded', () => {
    const nameInput = document.getElementById('className');
    const keyInput = document.getElementById('classKey');
    if (nameInput && keyInput) {
        nameInput.addEventListener('input', () => {
            // Only auto-fill on Add (no existing id) and only if user hasn't manually edited the key
            const isAdd = !document.getElementById('classId').value;
            if (isAdd && !keyInput.dataset.touched) {
                keyInput.value = slugify(nameInput.value);
            }
        });
        keyInput.addEventListener('input', () => { keyInput.dataset.touched = '1'; });
    }
});

// ---------- Properties (per-class, in nested modal) ----------

async function openPropsModal(classId) {
    activeClassForProps = classes.find(c => c.id === classId);
    if (!activeClassForProps) return;
    document.getElementById('propsModalClassName').textContent = activeClassForProps.name;
    document.getElementById('propsModal').classList.add('active');
    await loadPropsForClass();
}

function closePropsModal() {
    document.getElementById('propsModal').classList.remove('active');
    activeClassForProps = null;
    propsForClass = [];
    // Counts may have changed — refresh the parent table
    loadClasses();
}

async function loadPropsForClass() {
    const tbody = document.getElementById('propsTableBody');
    tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${escapeHtml(window.t('cmdb.settings.loading'))}</td></tr>`;
    try {
        const res = await fetch(API + 'get_class_properties.php?class_id=' + activeClassForProps.id);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.props_load_failed'));
        propsForClass = data.properties;
        renderProps();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${escapeHtml(window.t('cmdb.settings.error_prefix', { message: err.message }))}</td></tr>`;
    }
}

function renderProps() {
    const tbody = document.getElementById('propsTableBody');
    if (!propsForClass.length) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">${window.t('cmdb.settings.no_properties')}</td></tr>`;
        return;
    }
    tbody.innerHTML = propsForClass.map(p => `
        <tr>
            <td><strong>${escapeHtml(p.label)}</strong></td>
            <td><span class="key-hint">${escapeHtml(p.property_key)}</span></td>
            <td><span class="badge type">${escapeHtml(p.property_type)}</span></td>
            <td>${escapeHtml(p.target_class_name || '')}</td>
            <td>${p.is_required ? `<span class="badge active">${escapeHtml(window.t('cmdb.settings.required_badge'))}</span>` : ''}</td>
            <td>${p.display_order}</td>
            <td>
                <button class="action-btn" title="${escapeHtml(window.t('cmdb.settings.edit'))}" onclick="openPropertyModal(${p.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="${escapeHtml(window.t('cmdb.settings.delete'))}" onclick="deleteProperty(${p.id}, '${escapeHtml(p.label).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openPropertyModal(id = null) {
    const prop = id ? propsForClass.find(p => p.id === id) : null;
    document.getElementById('propertyModalTitle').textContent = prop ? window.t('cmdb.settings.prop_modal_edit') : window.t('cmdb.settings.prop_modal_add');
    document.getElementById('propertyId').value = prop ? prop.id : '';
    document.getElementById('propertyLabel').value = prop ? prop.label : '';
    document.getElementById('propertyKey').value = prop ? prop.property_key : '';
    document.getElementById('propertyKey').dataset.touched = prop ? '1' : '';
    document.getElementById('propertyType').value = prop ? prop.property_type : 'text';
    document.getElementById('propertyDisplayOrder').value = prop ? prop.display_order : 0;
    document.getElementById('propertyIsRequired').checked = prop ? prop.is_required : false;

    // Populate the target class dropdown
    const tcSel = document.getElementById('propertyTargetClass');
    tcSel.innerHTML = `<option value="">${escapeHtml(window.t('cmdb.settings.prop_select'))}</option>` + classes.map(c =>
        `<option value="${c.id}" ${prop && prop.target_class_id === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
    ).join('');

    // Render the row-based options editor (dropdown only — section is hidden otherwise)
    if (typeof renderOptionsEditor === 'function') {
        renderOptionsEditor('propertyOptionsContainer', (prop && prop.options) ? prop.options : []);
    }

    onPropertyTypeChange();
    document.getElementById('propertyModal').classList.add('active');
    setTimeout(() => document.getElementById('propertyLabel').focus(), 0);
}

function closePropertyModal() { document.getElementById('propertyModal').classList.remove('active'); }

function onPropertyTypeChange() {
    const t = document.getElementById('propertyType').value;
    document.getElementById('targetClassGroup').style.display = t === 'object_ref' ? 'block' : 'none';
    document.getElementById('dropdownOptionsGroup').style.display = t === 'dropdown' ? 'block' : 'none';
}

// Auto-suggest property_key from label on Add
document.addEventListener('DOMContentLoaded', () => {
    const labelInput = document.getElementById('propertyLabel');
    const keyInput = document.getElementById('propertyKey');
    if (labelInput && keyInput) {
        labelInput.addEventListener('input', () => {
            const isAdd = !document.getElementById('propertyId').value;
            if (isAdd && !keyInput.dataset.touched) {
                keyInput.value = slugify(labelInput.value);
            }
        });
        keyInput.addEventListener('input', () => { keyInput.dataset.touched = '1'; });
    }
});

async function saveProperty(ev) {
    if (ev) ev.preventDefault();
    if (!activeClassForProps) return;

    const type = document.getElementById('propertyType').value;
    const options = type === 'dropdown' && typeof collectOptionsFromEditor === 'function'
        ? collectOptionsFromEditor('propertyOptionsContainer')
        : [];
    const targetClassId = document.getElementById('propertyTargetClass').value;

    const payload = {
        id: document.getElementById('propertyId').value || null,
        class_id: activeClassForProps.id,
        label: document.getElementById('propertyLabel').value,
        property_key: document.getElementById('propertyKey').value,
        property_type: type,
        target_class_id: type === 'object_ref' ? (targetClassId || null) : null,
        is_required: document.getElementById('propertyIsRequired').checked,
        display_order: parseInt(document.getElementById('propertyDisplayOrder').value, 10) || 0,
        options
    };

    if (type === 'object_ref' && !payload.target_class_id) {
        showInlineToast(window.t('cmdb.settings.prop_pick_target'), true);
        return;
    }
    if (type === 'dropdown' && options.length === 0) {
        showInlineToast(window.t('cmdb.settings.prop_add_option'), true);
        return;
    }

    try {
        const data = await postJson(API + 'save_class_property.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.save_failed'));
        closePropertyModal();
        showInlineToast(payload.id ? window.t('cmdb.settings.prop_updated') : window.t('cmdb.settings.prop_created'));
        loadPropsForClass();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

async function deleteProperty(id, label) {
    if (!(await showConfirm({ title: window.t('cmdb.settings.prop_delete_title'), message: window.t('cmdb.settings.prop_delete_confirm', { label }), okLabel: window.t('cmdb.settings.prop_delete_ok'), okClass: 'danger' }))) return;
    try {
        const data = await postJson(API + 'delete_class_property.php', { id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.delete_failed'));
        showInlineToast(window.t('cmdb.settings.prop_deleted'));
        loadPropsForClass();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

// ---------- Relationship Types ----------

async function loadRelTypes() {
    const tbody = document.getElementById('relTypesTableBody');
    tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${escapeHtml(window.t('cmdb.settings.loading'))}</td></tr>`;
    try {
        const res = await fetch(API + 'get_relationship_types.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.rel_types_load_failed'));
        relTypes = data.relationship_types;
        renderRelTypes();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${escapeHtml(window.t('cmdb.settings.error_prefix', { message: err.message }))}</td></tr>`;
    }
}

function renderRelTypes() {
    const tbody = document.getElementById('relTypesTableBody');
    if (!relTypes.length) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty-row">${window.t('cmdb.settings.no_rel_types')}</td></tr>`;
        return;
    }
    tbody.innerHTML = relTypes.map(r => `
        <tr>
            <td><strong>${escapeHtml(r.verb)}</strong></td>
            <td>${escapeHtml(r.inverse_verb)}</td>
            <td style="color: #6b7280;">${escapeHtml(r.description || '')}</td>
            <td>${r.display_order}</td>
            <td><span class="badge ${r.is_active ? 'active' : 'inactive'}">${r.is_active ? escapeHtml(window.t('cmdb.settings.active')) : escapeHtml(window.t('cmdb.settings.inactive'))}</span></td>
            <td>
                <button class="action-btn" title="${escapeHtml(window.t('cmdb.settings.edit'))}" onclick="openRelTypeModal(${r.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="${escapeHtml(window.t('cmdb.settings.delete'))}" onclick="deleteRelType(${r.id}, '${escapeHtml(r.verb).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openRelTypeModal(id = null) {
    const r = id ? relTypes.find(x => x.id === id) : null;
    document.getElementById('relTypeModalTitle').textContent = r ? window.t('cmdb.settings.rel_type_modal_edit') : window.t('cmdb.settings.rel_type_modal_add');
    document.getElementById('relTypeId').value = r ? r.id : '';
    document.getElementById('relTypeVerb').value = r ? r.verb : '';
    document.getElementById('relTypeInverseVerb').value = r ? r.inverse_verb : '';
    document.getElementById('relTypeDescription').value = r ? (r.description || '') : '';
    document.getElementById('relTypeDisplayOrder').value = r ? r.display_order : 0;
    document.getElementById('relTypeIsActive').checked = r ? r.is_active : true;
    document.getElementById('relTypeModal').classList.add('active');
    setTimeout(() => document.getElementById('relTypeVerb').focus(), 0);
}

function closeRelTypeModal() { document.getElementById('relTypeModal').classList.remove('active'); }

async function saveRelType(ev) {
    if (ev) ev.preventDefault();
    const payload = {
        id: document.getElementById('relTypeId').value || null,
        verb: document.getElementById('relTypeVerb').value,
        inverse_verb: document.getElementById('relTypeInverseVerb').value,
        description: document.getElementById('relTypeDescription').value,
        display_order: parseInt(document.getElementById('relTypeDisplayOrder').value, 10) || 0,
        is_active: document.getElementById('relTypeIsActive').checked
    };
    try {
        const data = await postJson(API + 'save_relationship_type.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.save_failed'));
        closeRelTypeModal();
        showInlineToast(payload.id ? window.t('cmdb.settings.rel_type_updated') : window.t('cmdb.settings.rel_type_created'));
        loadRelTypes();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

async function deleteRelType(id, verb) {
    if (!(await showConfirm({ title: window.t('cmdb.settings.rel_type_delete_title'), message: window.t('cmdb.settings.rel_type_delete_confirm', { verb }), okLabel: window.t('cmdb.settings.rel_type_delete_ok'), okClass: 'danger' }))) return;
    try {
        const data = await postJson(API + 'delete_relationship_type.php', { id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.delete_failed'));
        showInlineToast(window.t('cmdb.settings.rel_type_deleted'));
        loadRelTypes();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

// ---------- AI Integration ----------

async function loadAiSettings() {
    try {
        const res = await fetch(API + 'get_ai_settings.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.ai_load_failed'));
        document.getElementById('aiApiKey').value = data.api_key_masked || '';
        document.getElementById('aiApiKey').placeholder = data.has_api_key ? '' : 'sk-ant-...';
        document.getElementById('aiModel').value = data.model || 'claude-haiku-4-5-20251001';
        document.getElementById('aiCustomInstructions').value = data.custom_instructions || '';
        const r = document.getElementById('aiTestResult');
        r.style.display = 'none';
        r.className = 'test-result';
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.ai_load_error', { message: err.message }), true);
    }
}

async function saveAiSettings(ev) {
    if (ev) ev.preventDefault();
    const payload = {
        api_key: document.getElementById('aiApiKey').value,
        model: document.getElementById('aiModel').value,
        custom_instructions: document.getElementById('aiCustomInstructions').value
    };
    try {
        const data = await postJson(API + 'save_ai_settings.php', payload);
        if (!data.success) throw new Error(data.error || window.t('cmdb.settings.save_failed'));
        showInlineToast(window.t('cmdb.settings.ai_saved'));
        loadAiSettings();
    } catch (err) {
        showInlineToast(window.t('cmdb.settings.error_prefix', { message: err.message }), true);
    }
}

async function testAiKey() {
    const r = document.getElementById('aiTestResult');
    r.className = 'test-result';
    r.style.display = 'block';
    r.textContent = window.t('cmdb.settings.ai_testing');
    try {
        const res = await fetch(API + 'test_ai_key.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            r.className = 'test-result success';
            r.textContent = data.message || window.t('cmdb.settings.ai_connection_ok');
        } else {
            r.className = 'test-result error';
            r.textContent = window.t('cmdb.settings.ai_test_failed', { error: data.error || window.t('cmdb.settings.ai_unknown_error') });
        }
    } catch (err) {
        r.className = 'test-result error';
        r.textContent = window.t('cmdb.settings.ai_network_error', { message: err.message });
    }
}

// ---------- AI Suggest Properties (two-stage wizard) ----------

let aiSuggestQuestions = [];   // [{id, question, examples}]
let aiSuggestAnswers = [];     // [{question, answer}]
let aiSuggestSuggestions = []; // [{label, property_key, property_type, is_required, why, options?, target_class_hint?}]
let aiSuggestStage = null;     // 'loading_questions' | 'questions' | 'loading_suggestions' | 'suggestions' | 'error'

function setAiStage(stage) {
    aiSuggestStage = stage;
    document.querySelectorAll('#aiSuggestModal .ai-stage').forEach(el => el.classList.remove('active'));
    const map = {
        loading_questions:   'aiStageLoadingQuestions',
        questions:           'aiStageQuestions',
        loading_suggestions: 'aiStageLoadingSuggestions',
        suggestions:         'aiStageSuggestions',
        result:              'aiStageResult',
        error:               'aiStageError'
    };
    const el = document.getElementById(map[stage]);
    if (el) el.classList.add('active');

    // Update primary/secondary button label/visibility
    const btn = document.getElementById('aiSuggestPrimaryBtn');
    const cancelBtn = document.getElementById('aiSuggestSecondaryBtn');
    const actions = document.getElementById('aiSuggestActions');
    if (stage === 'questions') {
        btn.textContent = window.t('cmdb.ai_suggest.generate');
        btn.disabled = false;
        cancelBtn.textContent = window.t('cmdb.ai_suggest.cancel');
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'suggestions') {
        btn.textContent = window.t('cmdb.ai_suggest.add');
        btn.disabled = false;
        cancelBtn.textContent = window.t('cmdb.ai_suggest.cancel');
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'loading_questions' || stage === 'loading_suggestions') {
        btn.disabled = true;
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'result') {
        // Result stage: only an OK button. Modal stays put until clicked.
        btn.textContent = window.t('cmdb.ai_suggest.ok');
        btn.disabled = false;
        cancelBtn.style.display = 'none';
        actions.style.display = '';
    } else {
        actions.style.display = 'none';
    }
}

async function openAiSuggestModal() {
    if (!activeClassForProps) return;
    document.getElementById('aiSuggestClassName').textContent = activeClassForProps.name;
    document.getElementById('aiQClassName').textContent = activeClassForProps.name;
    document.getElementById('aiSClassName').textContent = activeClassForProps.name;
    document.getElementById('aiSuggestModal').classList.add('active');
    aiSuggestQuestions = [];
    aiSuggestAnswers = [];
    aiSuggestSuggestions = [];
    setAiStage('loading_questions');

    try {
        const data = await postJson(API + 'ai_suggest_questions.php', { class_id: activeClassForProps.id });
        if (!data.success) throw new Error(data.error || window.t('cmdb.ai_suggest.questions_failed'));
        aiSuggestQuestions = data.questions || [];
        if (aiSuggestQuestions.length === 0) throw new Error(window.t('cmdb.ai_suggest.no_questions'));
        renderAiQuestions();
        setAiStage('questions');
    } catch (err) {
        document.getElementById('aiErrorMessage').textContent = err.message;
        setAiStage('error');
    }
}

function closeAiSuggestModal() {
    document.getElementById('aiSuggestModal').classList.remove('active');
    aiSuggestStage = null;
}

function renderAiQuestions() {
    const list = document.getElementById('aiQuestionsList');
    list.innerHTML = aiSuggestQuestions.map((q, i) => `
        <div class="ai-question">
            <label for="aiQ_${i}">${escapeHtml(q.question)}</label>
            ${q.examples ? `<div class="examples">${escapeHtml(window.t('cmdb.ai_suggest.example_prefix', { examples: q.examples }))}</div>` : ''}
            <input type="text" id="aiQ_${i}" placeholder="${escapeHtml(window.t('cmdb.ai_suggest.answer_placeholder'))}">
        </div>
    `).join('');
}

function collectAiAnswers() {
    aiSuggestAnswers = aiSuggestQuestions.map((q, i) => ({
        question: q.question,
        answer: (document.getElementById('aiQ_' + i)?.value || '').trim()
    })).filter(qa => qa.answer !== '');
}

async function aiPrimaryAction() {
    if (aiSuggestStage === 'questions') {
        // Move to stage 2: ask AI for suggestions
        collectAiAnswers();
        setAiStage('loading_suggestions');
        try {
            const data = await postJson(API + 'ai_suggest_properties.php', {
                class_id: activeClassForProps.id,
                answers: aiSuggestAnswers
            });
            if (!data.success) throw new Error(data.error || window.t('cmdb.ai_suggest.suggestions_failed'));
            aiSuggestSuggestions = data.properties || [];
            if (aiSuggestSuggestions.length === 0) throw new Error(window.t('cmdb.ai_suggest.no_suggestions'));
            renderAiSuggestions();
            setAiStage('suggestions');
        } catch (err) {
            document.getElementById('aiErrorMessage').textContent = err.message;
            setAiStage('error');
        }
    } else if (aiSuggestStage === 'suggestions') {
        await applyAiSuggestions();
    } else if (aiSuggestStage === 'result') {
        // Result acknowledged — close modal and refresh both the props for this
        // class and the Classes tab (auto-create may have added new rows there).
        closeAiSuggestModal();
        loadPropsForClass();
        loadClasses();
    }
}

function renderAiSuggestions() {
    const list = document.getElementById('aiSuggestionsList');
    list.innerHTML = aiSuggestSuggestions.map((p, i) => {
        const meta = [];
        meta.push(`${escapeHtml(window.t('cmdb.ai_suggest.type'))} <strong>${escapeHtml(p.property_type)}</strong>`);
        if (p.is_required) meta.push(`<span style="color: #be185d; font-weight: 500;">${escapeHtml(window.t('cmdb.ai_suggest.required'))}</span>`);
        if (p.property_type === 'dropdown' && p.options) meta.push(escapeHtml(window.t('cmdb.ai_suggest.options_count', { count: p.options.length })));
        if (p.property_type === 'object_ref' && p.target_class_hint) meta.push(`${escapeHtml(window.t('cmdb.ai_suggest.refers_to'))} <em>${escapeHtml(p.target_class_hint)}</em>`);
        return `
            <label class="ai-suggestion" for="aiSug_${i}">
                <input type="checkbox" id="aiSug_${i}" data-idx="${i}" checked>
                <div class="sug-body">
                    <div class="sug-head">
                        <span class="sug-label">${escapeHtml(p.label)}</span>
                        <span class="sug-key">${escapeHtml(p.property_key)}</span>
                    </div>
                    ${p.why ? `<div class="sug-why">${escapeHtml(p.why)}</div>` : ''}
                    <div class="sug-meta">${meta.join(' · ')}</div>
                </div>
            </label>
        `;
    }).join('');
}

async function applyAiSuggestions() {
    const checked = Array.from(document.querySelectorAll('#aiSuggestionsList input[type="checkbox"]:checked'))
        .map(cb => parseInt(cb.dataset.idx, 10));
    if (checked.length === 0) {
        showInlineToast(window.t('cmdb.ai_suggest.select_one'), true);
        return;
    }

    document.getElementById('aiSuggestPrimaryBtn').disabled = true;
    document.getElementById('aiSuggestPrimaryBtn').textContent = window.t('cmdb.ai_suggest.adding');

    const added = [];
    const created = []; // {name, id} — classes auto-created during this run
    const errors = []; // {label, error}

    // Map of existing class names (lowercased) → class id, for object_ref resolution
    const targetClassMap = {};
    classes.forEach(c => { targetClassMap[c.name.toLowerCase()] = c.id; });

    for (const idx of checked) {
        const s = aiSuggestSuggestions[idx];
        let targetClassId = null;

        // Resolve / auto-create target class for object_ref suggestions
        if (s.property_type === 'object_ref') {
            const hint = (s.target_class_hint || '').trim();
            if (!hint) {
                errors.push({ label: s.label, error: window.t('cmdb.ai_suggest.no_target_hint') });
                continue;
            }
            const key = hint.toLowerCase();
            if (targetClassMap[key]) {
                targetClassId = targetClassMap[key];
            } else {
                // Class doesn't exist — auto-create it with the AI's suggested name
                try {
                    const createRes = await postJson(API + 'save_class.php', {
                        name: hint,
                        display_order: 0,
                        is_active: true
                    });
                    if (!createRes.success) throw new Error(createRes.error || window.t('cmdb.ai_suggest.create_class_failed'));
                    targetClassId = createRes.id;
                    targetClassMap[key] = targetClassId;
                    created.push({ name: hint, id: targetClassId });
                    // Push into local classes array so subsequent suggestions in this batch can reuse it
                    classes.push({
                        id: targetClassId,
                        class_key: createRes.class_key,
                        name: hint,
                        description: null,
                        icon_id: null,
                        display_order: 0,
                        is_active: true,
                        property_count: 0,
                        object_count: 0
                    });
                } catch (err) {
                    errors.push({ label: s.label, error: window.t('cmdb.ai_suggest.create_target_failed', { name: hint, message: err.message }) });
                    continue;
                }
            }
        }

        // Save the property (with target_class_id set if object_ref)
        try {
            const payload = {
                class_id: activeClassForProps.id,
                label: s.label,
                property_key: s.property_key,
                property_type: s.property_type,
                target_class_id: targetClassId,
                is_required: !!s.is_required,
                display_order: 0,
                options: s.property_type === 'dropdown' ? (s.options || []) : []
            };
            const data = await postJson(API + 'save_class_property.php', payload);
            if (data.success) added.push(s.label);
            else errors.push({ label: s.label, error: data.error || window.t('cmdb.ai_suggest.failed') });
        } catch (err) {
            errors.push({ label: s.label, error: err.message });
        }
    }

    renderAiResult(added, created, errors);
    setAiStage('result');
}

function renderAiResult(added, created, errors) {
    const summary = document.getElementById('aiResultSummary');
    const details = document.getElementById('aiResultDetails');

    const hasErrors = errors.length > 0;
    const tone = hasErrors
        ? { bg: '#fef3c7', border: '#f59e0b', color: '#92400e', icon: '⚠' }
        : { bg: '#dcfce7', border: '#22c55e', color: '#166534', icon: '✓' };

    summary.style.background = tone.bg;
    summary.style.border = '1px solid ' + tone.border;
    summary.style.color = tone.color;

    const summaryParts = [];
    summaryParts.push(`<strong>${tone.icon} ${escapeHtml(window.t('cmdb.ai_suggest.added_summary', {
        count: added.length,
        word: added.length === 1 ? window.t('cmdb.ai_suggest.property') : window.t('cmdb.ai_suggest.properties')
    }))}</strong>`);
    if (created.length > 0) {
        summaryParts.push(escapeHtml(window.t('cmdb.ai_suggest.created_summary', {
            count: created.length,
            word: created.length === 1 ? window.t('cmdb.ai_suggest.class') : window.t('cmdb.ai_suggest.classes')
        })));
    }
    if (hasErrors) {
        summaryParts.push(escapeHtml(window.t('cmdb.ai_suggest.errors_summary', {
            count: errors.length,
            word: errors.length === 1 ? window.t('cmdb.ai_suggest.item') : window.t('cmdb.ai_suggest.items')
        })));
    }
    summary.innerHTML = summaryParts.join(' ');

    let html = '';
    if (added.length > 0) {
        html += `<div style="margin-bottom: 16px;">
            <div style="font-weight: 600; color: #166534; margin-bottom: 6px; font-size: 13px;">${escapeHtml(window.t('cmdb.ai_suggest.added_heading'))} <em>${escapeHtml(activeClassForProps.name)}</em></div>
            <ul style="margin: 0; padding-left: 20px; color: #1f2937; font-size: 13px;">
                ${added.map(l => `<li>${escapeHtml(l)}</li>`).join('')}
            </ul>
        </div>`;
    }
    if (created.length > 0) {
        html += `<div style="margin-bottom: 16px; padding: 12px; background: #fdf4ff; border: 1px solid #f3e8ff; border-radius: 6px;">
            <div style="font-weight: 600; color: #6b21a8; margin-bottom: 6px; font-size: 13px;">${escapeHtml(window.t('cmdb.ai_suggest.created_heading'))}</div>
            <ul style="margin: 0 0 8px 0; padding-left: 20px; color: #1f2937; font-size: 13px;">
                ${created.map(c => `<li><strong>${escapeHtml(c.name)}</strong> — ${escapeHtml(window.t('cmdb.ai_suggest.created_empty'))}</li>`).join('')}
            </ul>
            <div style="color: #6b21a8; font-size: 12px;">
                ${window.t('cmdb.ai_suggest.created_note')}
            </div>
        </div>`;
    }
    if (hasErrors) {
        html += `<div style="margin-bottom: 8px;">
            <div style="font-weight: 600; color: #b91c1c; margin-bottom: 6px; font-size: 13px;">${escapeHtml(window.t('cmdb.ai_suggest.failed_heading'))}</div>
            <ul style="margin: 0; padding-left: 20px; color: #1f2937; font-size: 13px;">
                ${errors.map(e => `<li><strong>${escapeHtml(e.label)}</strong> — ${escapeHtml(e.error)}</li>`).join('')}
            </ul>
        </div>`;
    }
    details.innerHTML = html;
}

// ---------- Init ----------

document.addEventListener('DOMContentLoaded', () => {
    loadClasses(); // First tab is Classes — load immediately
});
