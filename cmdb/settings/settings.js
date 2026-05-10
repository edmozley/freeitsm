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
        alert(msg);
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
}

// ---------- Classes ----------

async function loadClasses() {
    const tbody = document.getElementById('classesTableBody');
    tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Loading…</td></tr>';
    try {
        const res = await fetch(API + 'get_classes.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load classes');
        classes = data.classes;
        renderClasses();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">Error: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderClasses() {
    const tbody = document.getElementById('classesTableBody');
    if (!classes.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No classes yet — click <strong>Add</strong> to create your first one (e.g. Server, Database, Application).</td></tr>';
        return;
    }
    tbody.innerHTML = classes.map(c => `
        <tr>
            <td><strong>${escapeHtml(c.name)}</strong></td>
            <td><span class="key-hint">${escapeHtml(c.class_key)}</span></td>
            <td style="color: #6b7280;">${escapeHtml(c.description || '')}</td>
            <td><span class="badge clickable" onclick="openPropsModal(${c.id})">${c.property_count} ${c.property_count === 1 ? 'property' : 'properties'}</span></td>
            <td>${c.display_order}</td>
            <td><span class="badge ${c.is_active ? 'active' : 'inactive'}">${c.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button class="action-btn" title="Edit" onclick="openClassModal(${c.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="Delete" onclick="deleteClass(${c.id}, '${escapeHtml(c.name).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openClassModal(id = null) {
    const cls = id ? classes.find(c => c.id === id) : null;
    document.getElementById('classModalTitle').textContent = cls ? 'Edit Class' : 'Add Class';
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
        if (!data.success) throw new Error(data.error || 'Save failed');
        closeClassModal();
        showInlineToast(payload.id ? 'Class updated' : 'Class created');
        loadClasses();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

async function deleteClass(id, name) {
    if (!confirm(`Delete the class "${name}"?\n\nThis is only allowed when no objects exist for it. Property definitions are removed automatically.`)) return;
    try {
        const data = await postJson(API + 'delete_class.php', { id });
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showInlineToast('Class deleted');
        loadClasses();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
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
    tbody.innerHTML = '<tr><td colspan="7" class="empty-row">Loading…</td></tr>';
    try {
        const res = await fetch(API + 'get_class_properties.php?class_id=' + activeClassForProps.id);
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load properties');
        propsForClass = data.properties;
        renderProps();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="7" class="empty-row">Error: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderProps() {
    const tbody = document.getElementById('propsTableBody');
    if (!propsForClass.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="empty-row">No properties yet — click <strong>Add Property</strong>.</td></tr>';
        return;
    }
    tbody.innerHTML = propsForClass.map(p => `
        <tr>
            <td><strong>${escapeHtml(p.label)}</strong></td>
            <td><span class="key-hint">${escapeHtml(p.property_key)}</span></td>
            <td><span class="badge type">${escapeHtml(p.property_type)}</span></td>
            <td>${escapeHtml(p.target_class_name || '')}</td>
            <td>${p.is_required ? '<span class="badge active">Required</span>' : ''}</td>
            <td>${p.display_order}</td>
            <td>
                <button class="action-btn" title="Edit" onclick="openPropertyModal(${p.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="Delete" onclick="deleteProperty(${p.id}, '${escapeHtml(p.label).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openPropertyModal(id = null) {
    const prop = id ? propsForClass.find(p => p.id === id) : null;
    document.getElementById('propertyModalTitle').textContent = prop ? 'Edit Property' : 'Add Property';
    document.getElementById('propertyId').value = prop ? prop.id : '';
    document.getElementById('propertyLabel').value = prop ? prop.label : '';
    document.getElementById('propertyKey').value = prop ? prop.property_key : '';
    document.getElementById('propertyKey').dataset.touched = prop ? '1' : '';
    document.getElementById('propertyType').value = prop ? prop.property_type : 'text';
    document.getElementById('propertyDisplayOrder').value = prop ? prop.display_order : 0;
    document.getElementById('propertyIsRequired').checked = prop ? prop.is_required : false;

    // Populate the target class dropdown
    const tcSel = document.getElementById('propertyTargetClass');
    tcSel.innerHTML = '<option value="">— Select —</option>' + classes.map(c =>
        `<option value="${c.id}" ${prop && prop.target_class_id === c.id ? 'selected' : ''}>${escapeHtml(c.name)}</option>`
    ).join('');

    // Populate options textarea (dropdown only)
    document.getElementById('propertyOptions').value = prop && prop.options ? prop.options.join('\n') : '';

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
    const optionsText = document.getElementById('propertyOptions').value;
    const options = type === 'dropdown'
        ? optionsText.split('\n').map(s => s.trim()).filter(s => s !== '')
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
        showInlineToast('Pick a target class for the reference', true);
        return;
    }
    if (type === 'dropdown' && options.length === 0) {
        showInlineToast('Add at least one dropdown option', true);
        return;
    }

    try {
        const data = await postJson(API + 'save_class_property.php', payload);
        if (!data.success) throw new Error(data.error || 'Save failed');
        closePropertyModal();
        showInlineToast(payload.id ? 'Property updated' : 'Property created');
        loadPropsForClass();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

async function deleteProperty(id, label) {
    if (!confirm(`Delete property "${label}"?\n\nOnly allowed when no objects have a value for it.`)) return;
    try {
        const data = await postJson(API + 'delete_class_property.php', { id });
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showInlineToast('Property deleted');
        loadPropsForClass();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

// ---------- Relationship Types ----------

async function loadRelTypes() {
    const tbody = document.getElementById('relTypesTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="empty-row">Loading…</td></tr>';
    try {
        const res = await fetch(API + 'get_relationship_types.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load');
        relTypes = data.relationship_types;
        renderRelTypes();
    } catch (err) {
        tbody.innerHTML = `<tr><td colspan="6" class="empty-row">Error: ${escapeHtml(err.message)}</td></tr>`;
    }
}

function renderRelTypes() {
    const tbody = document.getElementById('relTypesTableBody');
    if (!relTypes.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="empty-row">No relationship types yet — click <strong>Add</strong>.</td></tr>';
        return;
    }
    tbody.innerHTML = relTypes.map(r => `
        <tr>
            <td><strong>${escapeHtml(r.verb)}</strong></td>
            <td>${escapeHtml(r.inverse_verb)}</td>
            <td style="color: #6b7280;">${escapeHtml(r.description || '')}</td>
            <td>${r.display_order}</td>
            <td><span class="badge ${r.is_active ? 'active' : 'inactive'}">${r.is_active ? 'Active' : 'Inactive'}</span></td>
            <td>
                <button class="action-btn" title="Edit" onclick="openRelTypeModal(${r.id})">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="action-btn delete" title="Delete" onclick="deleteRelType(${r.id}, '${escapeHtml(r.verb).replace(/'/g, "\\'")}')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1.5 14a2 2 0 0 1-2 2h-7a2 2 0 0 1-2-2L5 6"/></svg>
                </button>
            </td>
        </tr>
    `).join('');
}

function openRelTypeModal(id = null) {
    const r = id ? relTypes.find(x => x.id === id) : null;
    document.getElementById('relTypeModalTitle').textContent = r ? 'Edit Relationship Type' : 'Add Relationship Type';
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
        if (!data.success) throw new Error(data.error || 'Save failed');
        closeRelTypeModal();
        showInlineToast(payload.id ? 'Updated' : 'Created');
        loadRelTypes();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

async function deleteRelType(id, verb) {
    if (!confirm(`Delete relationship type "${verb}"?\n\nOnly allowed when no relationships currently use it.`)) return;
    try {
        const data = await postJson(API + 'delete_relationship_type.php', { id });
        if (!data.success) throw new Error(data.error || 'Delete failed');
        showInlineToast('Deleted');
        loadRelTypes();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

// ---------- AI Integration ----------

async function loadAiSettings() {
    try {
        const res = await fetch(API + 'get_ai_settings.php');
        const data = await res.json();
        if (!data.success) throw new Error(data.error || 'Failed to load');
        document.getElementById('aiApiKey').value = data.api_key_masked || '';
        document.getElementById('aiApiKey').placeholder = data.has_api_key ? '' : 'sk-ant-...';
        document.getElementById('aiModel').value = data.model || 'claude-haiku-4-5-20251001';
        document.getElementById('aiCustomInstructions').value = data.custom_instructions || '';
        const r = document.getElementById('aiTestResult');
        r.style.display = 'none';
        r.className = 'test-result';
    } catch (err) {
        showInlineToast('Error loading AI settings: ' + err.message, true);
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
        if (!data.success) throw new Error(data.error || 'Save failed');
        showInlineToast('AI settings saved');
        loadAiSettings();
    } catch (err) {
        showInlineToast('Error: ' + err.message, true);
    }
}

async function testAiKey() {
    const r = document.getElementById('aiTestResult');
    r.className = 'test-result';
    r.style.display = 'block';
    r.textContent = 'Testing…';
    try {
        const res = await fetch(API + 'test_ai_key.php', { method: 'POST' });
        const data = await res.json();
        if (data.success) {
            r.className = 'test-result success';
            r.textContent = data.message || 'Connection OK.';
        } else {
            r.className = 'test-result error';
            r.textContent = 'Failed: ' + (data.error || 'unknown error');
        }
    } catch (err) {
        r.className = 'test-result error';
        r.textContent = 'Network error: ' + err.message;
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
        btn.textContent = 'Generate suggestions';
        btn.disabled = false;
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'suggestions') {
        btn.textContent = 'Add Selected';
        btn.disabled = false;
        cancelBtn.textContent = 'Cancel';
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'loading_questions' || stage === 'loading_suggestions') {
        btn.disabled = true;
        cancelBtn.style.display = '';
        actions.style.display = '';
    } else if (stage === 'result') {
        // Result stage: only an OK button. Modal stays put until clicked.
        btn.textContent = 'OK';
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
        if (!data.success) throw new Error(data.error || 'Failed to fetch questions');
        aiSuggestQuestions = data.questions || [];
        if (aiSuggestQuestions.length === 0) throw new Error('AI returned no questions — try again.');
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
            ${q.examples ? `<div class="examples">e.g. ${escapeHtml(q.examples)}</div>` : ''}
            <input type="text" id="aiQ_${i}" placeholder="Your answer">
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
            if (!data.success) throw new Error(data.error || 'Failed to fetch suggestions');
            aiSuggestSuggestions = data.properties || [];
            if (aiSuggestSuggestions.length === 0) throw new Error('AI returned no usable suggestions.');
            renderAiSuggestions();
            setAiStage('suggestions');
        } catch (err) {
            document.getElementById('aiErrorMessage').textContent = err.message;
            setAiStage('error');
        }
    } else if (aiSuggestStage === 'suggestions') {
        await applyAiSuggestions();
    } else if (aiSuggestStage === 'result') {
        // Result acknowledged — close modal and refresh
        closeAiSuggestModal();
        loadPropsForClass();
    }
}

function renderAiSuggestions() {
    const list = document.getElementById('aiSuggestionsList');
    list.innerHTML = aiSuggestSuggestions.map((p, i) => {
        const meta = [];
        meta.push(`type: <strong>${escapeHtml(p.property_type)}</strong>`);
        if (p.is_required) meta.push('<span style="color: #be185d; font-weight: 500;">required</span>');
        if (p.property_type === 'dropdown' && p.options) meta.push(`${p.options.length} options`);
        if (p.property_type === 'object_ref' && p.target_class_hint) meta.push(`refers to ~ <em>${escapeHtml(p.target_class_hint)}</em>`);
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
        showInlineToast('Select at least one property to add', true);
        return;
    }

    document.getElementById('aiSuggestPrimaryBtn').disabled = true;
    document.getElementById('aiSuggestPrimaryBtn').textContent = 'Adding…';

    const added = [];
    const skippedObjectRefs = []; // {label, hint}
    const errors = [];            // {label, error}

    // object_ref suggestions need the analyst to pick a target class — skip those
    // for the bulk-add and surface them so the analyst knows to add manually.
    for (const idx of checked) {
        const s = aiSuggestSuggestions[idx];
        if (s.property_type === 'object_ref') {
            skippedObjectRefs.push({ label: s.label, hint: s.target_class_hint || '' });
            continue;
        }
        try {
            const payload = {
                class_id: activeClassForProps.id,
                label: s.label,
                property_key: s.property_key,
                property_type: s.property_type,
                target_class_id: null,
                is_required: !!s.is_required,
                display_order: 0,
                options: s.property_type === 'dropdown' ? (s.options || []) : []
            };
            const data = await postJson(API + 'save_class_property.php', payload);
            if (data.success) added.push(s.label);
            else errors.push({ label: s.label, error: data.error || 'failed' });
        } catch (err) {
            errors.push({ label: s.label, error: err.message });
        }
    }

    renderAiResult(added, skippedObjectRefs, errors);
    setAiStage('result');
}

function renderAiResult(added, skippedObjectRefs, errors) {
    const summary = document.getElementById('aiResultSummary');
    const details = document.getElementById('aiResultDetails');

    const hasIssues = skippedObjectRefs.length > 0 || errors.length > 0;
    const tone = hasIssues
        ? { bg: '#fef3c7', border: '#f59e0b', color: '#92400e', icon: '⚠' }
        : { bg: '#dcfce7', border: '#22c55e', color: '#166534', icon: '✓' };

    summary.style.background = tone.bg;
    summary.style.border = '1px solid ' + tone.border;
    summary.style.color = tone.color;
    summary.innerHTML = `<strong>${tone.icon} Added ${added.length} ${added.length === 1 ? 'property' : 'properties'}.</strong>` +
        (hasIssues ? ` ${skippedObjectRefs.length + errors.length} need${(skippedObjectRefs.length + errors.length) === 1 ? 's' : ''} your attention — see below.` : '');

    let html = '';
    if (added.length > 0) {
        html += `<div style="margin-bottom: 16px;">
            <div style="font-weight: 600; color: #166534; margin-bottom: 6px; font-size: 13px;">Added</div>
            <ul style="margin: 0; padding-left: 20px; color: #1f2937; font-size: 13px;">
                ${added.map(l => `<li>${escapeHtml(l)}</li>`).join('')}
            </ul>
        </div>`;
    }
    if (skippedObjectRefs.length > 0) {
        html += `<div style="margin-bottom: 16px;">
            <div style="font-weight: 600; color: #92400e; margin-bottom: 6px; font-size: 13px;">Skipped — object references (need a target class picked manually)</div>
            <ul style="margin: 0; padding-left: 20px; color: #1f2937; font-size: 13px;">
                ${skippedObjectRefs.map(s => `<li><strong>${escapeHtml(s.label)}</strong>${s.hint ? ` — should reference <em>${escapeHtml(s.hint)}</em>` : ''}</li>`).join('')}
            </ul>
            <div style="margin-top: 8px; color: #6b7280; font-size: 12px;">
                Add these via <strong>Add Property</strong> after the matching target class exists, then pick "Object Reference" as the type.
            </div>
        </div>`;
    }
    if (errors.length > 0) {
        html += `<div style="margin-bottom: 8px;">
            <div style="font-weight: 600; color: #b91c1c; margin-bottom: 6px; font-size: 13px;">Failed</div>
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
