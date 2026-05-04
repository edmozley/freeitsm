<?php
/**
 * Forms Module - Form List & Builder (unified single-page layout)
 */
session_start();
require_once '../config.php';

$current_page = 'forms';
$path_prefix = '../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Forms</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/forms.css?v=<?= time() ?>">
    <style>
        /* AI Assist */
        .btn-ai-assist {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
            color: white;
        }
        .btn-ai-assist:hover { background: linear-gradient(135deg, #4f46e5, #4338ca); }

        .ai-modal-overlay {
            position: fixed; inset: 0; background: rgba(0,0,0,0.45);
            display: none; align-items: center; justify-content: center; z-index: 2500;
        }
        .ai-modal-overlay.active { display: flex; }
        .ai-modal {
            background: #fff; border-radius: 8px; box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            width: 640px; max-width: calc(100vw - 40px); max-height: calc(100vh - 40px); overflow: hidden;
            display: flex; flex-direction: column;
        }
        .ai-modal-header {
            padding: 16px 20px; border-bottom: 1px solid #eee;
            display: flex; justify-content: space-between; align-items: center;
        }
        .ai-modal-header h3 {
            margin: 0; font-size: 16px; color: #333;
            display: flex; align-items: center; gap: 8px;
        }
        .ai-sparkle {
            display: inline-block; font-size: 16px;
            background: linear-gradient(135deg, #6366f1, #ec4899);
            -webkit-background-clip: text; -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .ai-modal-close {
            background: none; border: none; font-size: 22px; line-height: 1;
            color: #999; cursor: pointer; padding: 0;
        }
        .ai-modal-close:hover { color: #333; }
        .ai-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .ai-modal-body label {
            display: block; margin-bottom: 6px; font-weight: 500;
            font-size: 13px; color: #333;
        }
        .ai-modal-body textarea {
            width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 5px;
            font-size: 13px; box-sizing: border-box; font-family: inherit;
            min-height: 110px; resize: vertical;
        }
        .ai-modal-body textarea:focus {
            outline: none; border-color: #6366f1; box-shadow: 0 0 0 2px rgba(99,102,241,0.12);
        }
        .ai-modal-body .ai-hint {
            color: #888; font-size: 12px; margin-top: 6px;
        }
        .ai-modal-body .ai-examples {
            font-size: 12px; color: #6b7280; margin-top: 14px;
        }
        .ai-modal-body .ai-examples strong { color: #4b5563; }
        .ai-modal-body .ai-examples ul { margin: 6px 0 0 0; padding-left: 18px; }
        .ai-modal-body .ai-examples li { margin-bottom: 3px; cursor: pointer; }
        .ai-modal-body .ai-examples li:hover { color: #4f46e5; text-decoration: underline; }

        .ai-progress {
            margin-top: 16px; padding: 14px;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;
            font-size: 12px; color: #475569;
        }
        .ai-progress .ai-progress-status {
            display: flex; align-items: center; gap: 8px; font-weight: 500; margin-bottom: 8px;
        }
        .ai-progress .ai-spinner {
            width: 12px; height: 12px; border-radius: 50%;
            border: 2px solid #c7d2fe; border-top-color: #4f46e5;
            animation: ai-spin 0.8s linear infinite;
        }
        @keyframes ai-spin { to { transform: rotate(360deg); } }
        .ai-progress .ai-progress-counters {
            display: flex; gap: 14px; font-size: 11px; color: #6b7280; margin-bottom: 8px;
        }
        .ai-progress .ai-progress-counters span strong { color: #1f2937; }
        .ai-progress pre.ai-stream {
            margin: 0; max-height: 180px; overflow: auto;
            background: #0f172a; color: #cbd5e1; padding: 10px;
            border-radius: 4px; font-size: 11px; line-height: 1.45;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            white-space: pre-wrap; word-break: break-word;
        }
        .ai-progress.error {
            background: #fef2f2; border-color: #fecaca; color: #991b1b;
        }

        .ai-modal-footer {
            padding: 14px 20px; border-top: 1px solid #eee;
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .ai-modal-footer .btn:disabled { opacity: 0.5; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="forms-container">
        <!-- Sidebar with search and form list -->
        <div class="forms-sidebar">
            <div class="sidebar-section">
                <h3>Search Forms</h3>
                <div class="search-box">
                    <input type="text" id="formSearch" placeholder="Search by title..." onkeyup="filterForms()">
                </div>
            </div>
            <div class="sidebar-section">
                <button class="btn btn-primary btn-full" onclick="openNewForm()">+ New Form</button>
            </div>
            <div class="sidebar-section" style="flex: 1; overflow-y: auto;">
                <h3>Forms</h3>
                <div class="form-list" id="formList">
                    <div class="form-list-empty">Loading...</div>
                </div>
            </div>
        </div>

        <!-- Main content area -->
        <div class="forms-main">
            <!-- Welcome / empty state -->
            <div class="forms-welcome" id="welcomeView">
                <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                    <line x1="16" y1="13" x2="8" y2="13"></line>
                    <line x1="16" y1="17" x2="8" y2="17"></line>
                    <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
                <h3>Select a form or create a new one</h3>
                <p>Use the sidebar to browse your forms or click "New Form" to get started.</p>
            </div>

            <!-- Editor view -->
            <div id="editorView" style="display: none;">
                <div class="editor-toolbar">
                    <div style="display: flex; align-items: center; gap: 12px;">
                        <h2 id="editorTitle">New Form</h2>
                        <div class="unsaved-indicator" id="unsavedIndicator">
                            <span class="unsaved-dot"></span>
                            Unsaved changes
                        </div>
                    </div>
                    <div class="editor-toolbar-actions">
                        <button class="btn btn-secondary" onclick="cancelEdit()">Cancel</button>
                        <button class="btn btn-ai-assist" onclick="openAiModal()" title="Describe your form and let AI build it">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path><path d="M19 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"></path><path d="M5 16l.6 1.8L7.5 18l-1.9.6L5 20l-.6-1.4L2.5 18l2-.2z"></path></svg>
                            AI Assist
                        </button>
                        <button class="btn btn-primary save-btn" id="saveBtn" onclick="saveForm()">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                            Save
                        </button>
                    </div>
                </div>

                <!-- Title & Description (full width) -->
                <div class="form-settings-card">
                    <div class="field-group">
                        <label>Form Title</label>
                        <input type="text" id="formTitle" placeholder="Enter form title...">
                    </div>
                    <div class="field-group">
                        <label>Description</label>
                        <textarea id="formDesc" rows="2" placeholder="Optional description..."></textarea>
                    </div>
                </div>

                <!-- Tabs: Fields | Preview -->
                <div class="form-tabs">
                    <button class="form-tab active" onclick="switchFormTab('fields')" id="tabFields">Fields</button>
                    <button class="form-tab" onclick="switchFormTab('preview')" id="tabPreview">Preview</button>
                </div>

                <!-- Fields tab -->
                <div class="form-tab-content active" id="tabContentFields">
                    <div class="fields-header">
                        <h3>Form Fields</h3>
                        <div class="add-field-btn">
                            <button class="btn btn-secondary" onclick="toggleAddMenu()" id="addFieldBtn">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                Add
                            </button>
                            <div class="add-field-menu" id="addFieldMenu">
                                <button onclick="addField('text')"><span class="field-type-badge text">Abc</span> Text Input</button>
                                <button onclick="addField('textarea')"><span class="field-type-badge textarea">Txt</span> Text Area</button>
                                <button onclick="addField('checkbox')"><span class="field-type-badge checkbox">Chk</span> Checkbox</button>
                                <button onclick="addField('dropdown')"><span class="field-type-badge dropdown">Sel</span> Dropdown</button>
                            </div>
                        </div>
                    </div>
                    <ul class="field-list" id="fieldList">
                        <li class="no-fields">No fields added yet. Click "Add" to start building your form.</li>
                    </ul>
                </div>

                <!-- Preview tab -->
                <div class="form-tab-content" id="tabContentPreview">
                    <div id="previewContent">
                        <p class="preview-empty">Add fields to see a preview</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Delete confirmation -->
    <div class="confirm-overlay" id="confirmOverlay" onclick="if(event.target===this)closeConfirm()">
        <div class="confirm-box">
            <h3>Delete Form</h3>
            <p>This will permanently delete this form and all its submissions. Are you sure?</p>
            <div class="confirm-actions">
                <button class="btn btn-secondary" onclick="closeConfirm()">Cancel</button>
                <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
            </div>
        </div>
    </div>

    <!-- Toast notification -->
    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '../api/forms/';
        let allForms = [];
        let currentFormId = null;
        let fields = [];
        let isDirty = false;
        let logoAlignment = 'center';

        // Track mousedown target for drag-from-handle detection
        // (dragstart e.target is the draggable element, not what was clicked)
        let fieldDragAllowed = false;
        let optDragAllowed = false;
        document.addEventListener('mousedown', function(e) {
            fieldDragAllowed = !!e.target.closest('.field-drag');
            optDragAllowed = !!e.target.closest('.option-drag');
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadForms();
            loadSettings();

            // Check URL for direct form editing
            const params = new URLSearchParams(window.location.search);
            const editId = params.get('id');
            if (editId) {
                openEditForm(parseInt(editId));
            }

            // Close add field menu on outside click
            document.addEventListener('click', function(e) {
                const menu = document.getElementById('addFieldMenu');
                if (menu && !e.target.closest('.add-field-btn')) {
                    menu.classList.remove('open');
                }
            });

            // Track dirty state on title/description input
            document.getElementById('formTitle').addEventListener('input', function() {
                markDirty();
                updatePreview();
            });
            document.getElementById('formDesc').addEventListener('input', function() {
                markDirty();
                updatePreview();
            });

            // Warn before leaving with unsaved changes
            window.addEventListener('beforeunload', function(e) {
                if (isDirty) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });
        });

        // ========== Dirty State Tracking ==========

        function markDirty() {
            if (isDirty) return;
            isDirty = true;
            document.getElementById('unsavedIndicator').classList.add('visible');
            document.getElementById('saveBtn').classList.add('has-changes');
        }

        function clearDirty() {
            isDirty = false;
            document.getElementById('unsavedIndicator').classList.remove('visible');
            document.getElementById('saveBtn').classList.remove('has-changes');
        }

        // ========== Toast Notification ==========

        function showToast(message, isError) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // ========== Form List ==========

        async function loadForms() {
            try {
                const res = await fetch(API_BASE + 'get_forms.php');
                const data = await res.json();
                if (data.success) {
                    allForms = data.forms;
                    renderFormList(allForms);
                }
            } catch (e) {
                console.error(e);
            }
        }

        function renderFormList(forms) {
            const list = document.getElementById('formList');

            if (forms.length === 0) {
                list.innerHTML = '<div class="form-list-empty">No forms found</div>';
                return;
            }

            list.innerHTML = forms.map(f => `
                <div class="form-list-item ${currentFormId == f.id ? 'active' : ''}" onclick="openEditForm(${f.id})">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div class="form-list-item-title">${esc(f.title)}</div>
                        <span class="form-list-item-status ${f.is_active == 1 ? 'active' : 'inactive'}">${f.is_active == 1 ? 'Active' : 'Inactive'}</span>
                    </div>
                    <div class="form-list-item-meta">
                        <span>${f.field_count} field${f.field_count != 1 ? 's' : ''}</span>
                        <span>${f.submission_count} submission${f.submission_count != 1 ? 's' : ''}</span>
                    </div>
                    <div class="form-list-item-actions">
                        <a href="fill.php?id=${f.id}" class="btn btn-primary" onclick="event.stopPropagation()">Fill In</a>
                        <a href="submissions.php?id=${f.id}" class="btn btn-secondary" onclick="event.stopPropagation()">Submissions</a>
                        <button class="btn btn-danger" onclick="event.stopPropagation(); confirmDelete(${f.id})" style="margin-left: auto; padding: 4px 8px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </div>
                </div>
            `).join('');
        }

        function filterForms() {
            const query = document.getElementById('formSearch').value.toLowerCase();
            const filtered = allForms.filter(f => f.title.toLowerCase().includes(query));
            renderFormList(filtered);
        }

        // ========== Editor ==========

        function openNewForm() {
            if (isDirty && !confirm('You have unsaved changes. Discard them?')) return;
            currentFormId = null;
            fields = [];
            document.getElementById('editorTitle').textContent = 'New Form';
            document.getElementById('formTitle').value = '';
            document.getElementById('formDesc').value = '';
            clearDirty();
            renderFields();
            updatePreview();
            switchFormTab('fields');
            showEditor();
            renderFormList(allForms);
            history.replaceState(null, '', './');
        }

        async function openEditForm(id) {
            if (isDirty && !confirm('You have unsaved changes. Discard them?')) return;
            currentFormId = id;
            document.getElementById('editorTitle').textContent = 'Edit Form';
            clearDirty();
            showEditor();
            renderFormList(allForms);

            try {
                const res = await fetch(API_BASE + 'get_form.php?id=' + id);
                const data = await res.json();
                if (data.success) {
                    document.getElementById('formTitle').value = data.form.title;
                    document.getElementById('formDesc').value = data.form.description || '';
                    fields = data.form.fields.map(f => ({
                        field_type: f.field_type,
                        label: f.label,
                        options: f.options ? JSON.parse(f.options) : [],
                        is_required: f.is_required == 1
                    }));
                    renderFields();
                    updatePreview();
                    switchFormTab('fields');
                    history.replaceState(null, '', './?id=' + id);
                }
            } catch (e) {
                console.error(e);
            }
        }

        function showEditor() {
            document.getElementById('welcomeView').style.display = 'none';
            document.getElementById('editorView').style.display = 'block';
        }

        function cancelEdit() {
            if (isDirty && !confirm('You have unsaved changes. Discard them?')) return;
            currentFormId = null;
            clearDirty();
            document.getElementById('editorView').style.display = 'none';
            document.getElementById('welcomeView').style.display = 'flex';
            renderFormList(allForms);
            history.replaceState(null, '', './');
        }

        // ========== Tabs ==========

        function switchFormTab(tab) {
            document.getElementById('tabFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabPreview').classList.toggle('active', tab === 'preview');
            document.getElementById('tabContentFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabContentPreview').classList.toggle('active', tab === 'preview');

            if (tab === 'preview') {
                updatePreview();
            }
        }

        // ========== Field Management ==========

        function toggleAddMenu() {
            document.getElementById('addFieldMenu').classList.toggle('open');
        }

        function addField(type) {
            document.getElementById('addFieldMenu').classList.remove('open');
            fields.push({
                field_type: type,
                label: '',
                options: type === 'dropdown' ? ['Option 1'] : [],
                is_required: false
            });
            markDirty();
            renderFields();
            updatePreview();
            setTimeout(() => {
                const inputs = document.querySelectorAll('.field-label-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }, 50);
        }

        function renderFields() {
            const list = document.getElementById('fieldList');

            if (fields.length === 0) {
                list.innerHTML = '<li class="no-fields">No fields added yet. Click "Add" to start building your form.</li>';
                return;
            }

            list.innerHTML = fields.map((f, i) => {
                let optionsHtml = '';
                if (f.field_type === 'dropdown') {
                    optionsHtml = `
                        <div class="field-options">
                            <div class="field-options-label">Dropdown Options</div>
                            ${(f.options || []).map((opt, oi) => `
                                <div class="option-item" draggable="true"
                                     ondragstart="onOptDragStart(event, ${i}, ${oi})"
                                     ondragend="onOptDragEnd(event)"
                                     ondragover="onOptDragOver(event, ${i}, ${oi})"
                                     ondrop="onOptDrop(event, ${i}, ${oi})">
                                    <span class="option-drag" title="Drag to reorder">⠿</span>
                                    <input type="text" value="${esc(opt)}"
                                           onchange="updateOption(${i}, ${oi}, this.value)"
                                           onkeydown="onOptionKeydown(event, ${i}, ${oi})"
                                           placeholder="Option ${oi + 1}">
                                    <button class="option-remove" onclick="removeOption(${i}, ${oi})">&times;</button>
                                </div>
                            `).join('')}
                            <button class="add-option-btn" onclick="addOption(${i})">+ Add Option</button>
                        </div>`;
                }

                return `
                    <li class="field-item" data-index="${i}" draggable="true"
                        ondragstart="onFieldDragStart(event, ${i})"
                        ondragend="onFieldDragEnd(event)"
                        ondragover="onFieldDragOver(event, ${i})"
                        ondrop="onFieldDrop(event, ${i})">
                        <div class="field-item-header">
                            <span class="field-drag" title="Drag to reorder">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>
                            </span>
                            <span class="field-type-badge ${f.field_type}">${typeName(f.field_type)}</span>
                            <input type="text" class="field-label-input" value="${esc(f.label)}" placeholder="Field label..." onchange="updateLabel(${i}, this.value)">
                            <div class="field-controls">
                                <label class="field-required-toggle">
                                    <input type="checkbox" ${f.is_required ? 'checked' : ''} onchange="toggleRequired(${i}, this.checked)">
                                    Required
                                </label>
                                <button class="field-delete-btn" onclick="deleteField(${i})" title="Remove field">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                </button>
                            </div>
                        </div>
                        ${optionsHtml}
                    </li>`;
            }).join('');
        }

        function typeName(t) {
            return { text: 'Text', textarea: 'Textarea', checkbox: 'Checkbox', dropdown: 'Dropdown' }[t] || t;
        }

        function updateLabel(i, val) { fields[i].label = val; markDirty(); updatePreview(); }
        function toggleRequired(i, val) { fields[i].is_required = val; markDirty(); updatePreview(); }

        function deleteField(i) {
            fields.splice(i, 1);
            markDirty();
            renderFields();
            updatePreview();
        }

        function addOption(fi) {
            fields[fi].options.push('');
            markDirty();
            renderFields();
            setTimeout(() => {
                const items = document.querySelectorAll(`.field-item[data-index="${fi}"] .option-item input[type="text"]`);
                if (items.length) items[items.length - 1].focus();
            }, 50);
        }

        function updateOption(fi, oi, val) {
            fields[fi].options[oi] = val;
            markDirty();
            updatePreview();
        }

        function removeOption(fi, oi) {
            fields[fi].options.splice(oi, 1);
            markDirty();
            renderFields();
            updatePreview();
        }

        function onOptionKeydown(e, fi, oi) {
            if (e.key === 'Enter') {
                e.preventDefault();
                fields[fi].options[oi] = e.target.value;
                addOption(fi);
            }
        }

        // ========== Field Drag & Drop ==========

        let dragFieldIndex = null;

        function onFieldDragStart(e, i) {
            if (!fieldDragAllowed) {
                e.preventDefault();
                return;
            }
            dragFieldIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'field');
            requestAnimationFrame(() => {
                const item = document.querySelector(`.field-item[data-index="${i}"]`);
                if (item) item.classList.add('dragging');
            });
        }

        function onFieldDragEnd(e) {
            dragFieldIndex = null;
            document.querySelectorAll('.field-item').forEach(el => {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }

        function onFieldDragOver(e, i) {
            if (dragFieldIndex === null || dragFieldIndex === i) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            document.querySelectorAll('.field-item').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            if (e.clientY < midY) {
                e.currentTarget.classList.add('drag-over-top');
            } else {
                e.currentTarget.classList.add('drag-over-bottom');
            }
        }

        function onFieldDrop(e, i) {
            e.preventDefault();
            if (dragFieldIndex === null || dragFieldIndex === i) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let targetIndex = e.clientY < midY ? i : i + 1;
            if (dragFieldIndex < targetIndex) targetIndex--;
            const [moved] = fields.splice(dragFieldIndex, 1);
            fields.splice(targetIndex, 0, moved);
            dragFieldIndex = null;
            markDirty();
            renderFields();
            updatePreview();
        }

        // ========== Option Drag & Drop ==========

        let dragOptFieldIndex = null;
        let dragOptIndex = null;

        function onOptDragStart(e, fi, oi) {
            if (!optDragAllowed) {
                e.preventDefault();
                return;
            }
            e.stopPropagation();
            dragOptFieldIndex = fi;
            dragOptIndex = oi;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'option');
            requestAnimationFrame(() => e.currentTarget.classList.add('dragging'));
        }

        function onOptDragEnd(e) {
            dragOptFieldIndex = null;
            dragOptIndex = null;
            document.querySelectorAll('.option-item').forEach(el => {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }

        function onOptDragOver(e, fi, oi) {
            if (dragOptFieldIndex !== fi || dragOptIndex === null || dragOptIndex === oi) return;
            e.preventDefault();
            e.stopPropagation();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            e.currentTarget.closest('.field-options').querySelectorAll('.option-item').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            if (e.clientY < midY) {
                e.currentTarget.classList.add('drag-over-top');
            } else {
                e.currentTarget.classList.add('drag-over-bottom');
            }
        }

        function onOptDrop(e, fi, oi) {
            e.preventDefault();
            e.stopPropagation();
            if (dragOptFieldIndex !== fi || dragOptIndex === null || dragOptIndex === oi) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let targetIndex = e.clientY < midY ? oi : oi + 1;
            if (dragOptIndex < targetIndex) targetIndex--;
            const opts = fields[fi].options;
            const [moved] = opts.splice(dragOptIndex, 1);
            opts.splice(targetIndex, 0, moved);
            dragOptFieldIndex = null;
            dragOptIndex = null;
            markDirty();
            renderFields();
            updatePreview();
        }

        // ========== Preview ==========

        function updatePreview() {
            const title = document.getElementById('formTitle').value || 'Untitled Form';
            const desc = document.getElementById('formDesc').value;
            const preview = document.getElementById('previewContent');

            if (fields.length === 0) {
                preview.innerHTML = '<p class="preview-empty">Add fields to see a preview</p>';
                return;
            }

            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="../assets/images/CompanyLogo.png" alt="Company Logo" class="preview-logo ${alignClass}">`;
            html += `<p class="preview-title">${esc(title)}</p>`;
            if (desc) html += `<p class="preview-desc">${esc(desc)}</p>`;

            html += fields.map(f => {
                const reqStar = f.is_required ? '<span class="required-star">*</span>' : '';
                const label = esc(f.label || 'Untitled field');

                switch (f.field_type) {
                    case 'text':
                        return `<div class="preview-field"><label>${label}${reqStar}</label><input type="text" disabled placeholder="Text input..."></div>`;
                    case 'textarea':
                        return `<div class="preview-field"><label>${label}${reqStar}</label><textarea disabled placeholder="Text area..."></textarea></div>`;
                    case 'checkbox':
                        return `<div class="preview-field"><div class="checkbox-row"><input type="checkbox" disabled> <label>${label}${reqStar}</label></div></div>`;
                    case 'dropdown':
                        const opts = (f.options || []).filter(o => o).map(o => `<option>${esc(o)}</option>`).join('');
                        return `<div class="preview-field"><label>${label}${reqStar}</label><select disabled><option value="">Select...</option>${opts}</select></div>`;
                    default:
                        return '';
                }
            }).join('');

            preview.innerHTML = html;
        }

        // ========== Save ==========

        async function saveForm() {
            const title = document.getElementById('formTitle').value.trim();
            if (!title) {
                showToast('Please enter a form title', true);
                return;
            }

            const validFields = fields.filter(f => f.label.trim());
            if (validFields.length === 0) {
                showToast('Please add at least one field with a label', true);
                return;
            }

            const payload = {
                title: title,
                description: document.getElementById('formDesc').value.trim(),
                fields: validFields.map(f => ({
                    field_type: f.field_type,
                    label: f.label.trim(),
                    options: f.field_type === 'dropdown' ? f.options.filter(o => o.trim()) : null,
                    is_required: f.is_required ? 1 : 0
                }))
            };

            if (currentFormId) payload.id = parseInt(currentFormId);

            try {
                const res = await fetch(API_BASE + 'save_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.success) {
                    if (!currentFormId) {
                        currentFormId = data.form_id;
                        document.getElementById('editorTitle').textContent = 'Edit Form';
                    }
                    history.replaceState(null, '', './?id=' + currentFormId);
                    clearDirty();
                    showToast('Form saved successfully');
                    await loadForms();
                    renderFormList(allForms);
                } else {
                    showToast('Error: ' + data.error, true);
                }
            } catch (e) {
                showToast('Failed to save form', true);
            }
        }

        // ========== Settings ==========

        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    logoAlignment = data.settings.logo_alignment || 'center';
                }
            } catch (e) {
                console.error(e);
            }
        }

        // ========== Delete ==========

        let deleteFormId = null;

        function confirmDelete(id) {
            deleteFormId = id;
            document.getElementById('confirmOverlay').classList.add('open');
        }

        function closeConfirm() {
            document.getElementById('confirmOverlay').classList.remove('open');
            deleteFormId = null;
        }

        document.getElementById('confirmDeleteBtn').addEventListener('click', async function() {
            if (!deleteFormId) return;
            try {
                const res = await fetch(API_BASE + 'delete_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id: deleteFormId })
                });
                const data = await res.json();
                if (data.success) {
                    closeConfirm();
                    if (deleteFormId == currentFormId) {
                        isDirty = false;
                        cancelEdit();
                    }
                    await loadForms();
                    renderFormList(allForms);
                    showToast('Form deleted');
                }
            } catch (e) {
                console.error(e);
            }
        });

        // ========== Utility ==========

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ========== AI Assist ==========
        let aiAbortController = null;

        function openAiModal() {
            document.getElementById('aiModal').classList.add('active');
            const ta = document.getElementById('aiDescription');
            ta.value = '';
            setTimeout(() => ta.focus(), 50);
            resetAiProgress();

            // Wire example clicks once
            document.querySelectorAll('.ai-example').forEach(el => {
                if (el.dataset.wired) return;
                el.dataset.wired = '1';
                el.addEventListener('click', () => {
                    document.getElementById('aiDescription').value = el.dataset.text || '';
                    document.getElementById('aiDescription').focus();
                });
            });
        }

        function closeAiModal() {
            if (aiAbortController) { aiAbortController.abort(); aiAbortController = null; }
            document.getElementById('aiModal').classList.remove('active');
        }

        function resetAiProgress() {
            const prog = document.getElementById('aiProgress');
            prog.style.display = 'none';
            prog.classList.remove('error');
            document.getElementById('aiStream').textContent = '';
            document.getElementById('aiStatus').textContent = '';
            document.getElementById('aiTokensIn').textContent = '0';
            document.getElementById('aiTokensOut').textContent = '0';
            document.getElementById('aiCacheRead').textContent = '0';
            document.getElementById('aiFieldCount').textContent = '0';
        }

        async function runAiGeneration() {
            const description = document.getElementById('aiDescription').value.trim();
            if (!description) {
                showToast('Please describe the form you want to build', true);
                return;
            }
            if (description.length > 2000) {
                showToast('Description is too long (max 2000 characters)', true);
                return;
            }

            const replaceConfirm = (fields.length > 0)
                ? confirm('This will replace the current form title, description and fields. Continue?')
                : true;
            if (!replaceConfirm) return;

            const generateBtn = document.getElementById('aiGenerateBtn');
            generateBtn.disabled = true;

            const prog = document.getElementById('aiProgress');
            prog.style.display = 'block';
            prog.classList.remove('error');
            const status = document.getElementById('aiStatus');
            const stream = document.getElementById('aiStream');
            stream.textContent = '';
            status.textContent = 'Designing your form…';

            aiAbortController = new AbortController();

            try {
                const resp = await fetch(API_BASE + 'ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ description: description }),
                    signal: aiAbortController.signal
                });

                if (!resp.body) throw new Error('Streaming not supported by your browser');
                const reader = resp.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                let acc = '';
                let detectedFields = 0;

                const handleEvent = (eventName, dataStr) => {
                    if (!dataStr) return;
                    let data;
                    try { data = JSON.parse(dataStr); } catch (e) { return; }

                    switch (eventName) {
                        case 'text': {
                            const delta = data.delta || '';
                            acc += delta;
                            stream.textContent = acc;
                            stream.scrollTop = stream.scrollHeight;
                            const matches = acc.match(/"field_type"\s*:/g);
                            const newCount = matches ? matches.length : 0;
                            if (newCount !== detectedFields) {
                                detectedFields = newCount;
                                document.getElementById('aiFieldCount').textContent = String(detectedFields);
                            }
                            break;
                        }
                        case 'usage':
                            if (data.tokens_in != null)  document.getElementById('aiTokensIn').textContent  = String(data.tokens_in);
                            if (data.tokens_out != null) document.getElementById('aiTokensOut').textContent = String(data.tokens_out);
                            if (data.cache_read != null) document.getElementById('aiCacheRead').textContent  = String(data.cache_read);
                            break;
                        case 'done': {
                            applyGeneratedForm(data.form);
                            const seconds = data.duration_ms ? (data.duration_ms / 1000).toFixed(1) : '?';
                            const fieldWord = data.form.fields.length === 1 ? 'field' : 'fields';
                            showToast(`Form built — ${data.form.fields.length} ${fieldWord} in ${seconds}s`, false);
                            closeAiModal();
                            switchFormTab('preview');
                            break;
                        }
                        case 'error':
                            throw new Error(data.message || 'AI request failed');
                    }
                };

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    let idx;
                    while ((idx = buffer.indexOf('\n\n')) !== -1) {
                        const block = buffer.slice(0, idx);
                        buffer = buffer.slice(idx + 2);

                        let eventName = '';
                        let dataStr = '';
                        for (const line of block.split('\n')) {
                            if (line.startsWith('event: ')) eventName = line.slice(7).trim();
                            else if (line.startsWith('data: ')) dataStr += line.slice(6);
                        }
                        if (eventName) handleEvent(eventName, dataStr);
                    }
                }
            } catch (err) {
                if (err.name === 'AbortError') {
                    // user cancelled
                } else {
                    prog.classList.add('error');
                    document.getElementById('aiStatus').textContent = 'Error: ' + err.message;
                    showToast('AI Assist failed: ' + err.message, true);
                }
            } finally {
                generateBtn.disabled = false;
                aiAbortController = null;
            }
        }

        function applyGeneratedForm(form) {
            document.getElementById('formTitle').value = form.title || '';
            document.getElementById('formDesc').value  = form.description || '';
            fields = (form.fields || []).map(f => ({
                field_type:  f.field_type || 'text',
                label:       f.label || '',
                options:     Array.isArray(f.options) ? f.options.slice() : [],
                is_required: !!f.is_required
            }));
            renderFields();
            updatePreview();
            markDirty();
        }
    </script>

    <!-- AI Assist Modal -->
    <div class="ai-modal-overlay" id="aiModal">
        <div class="ai-modal">
            <div class="ai-modal-header">
                <h3><span class="ai-sparkle">&#10024;</span> AI Assist &mdash; describe your form</h3>
                <button type="button" class="ai-modal-close" onclick="closeAiModal()">&times;</button>
            </div>
            <div class="ai-modal-body">
                <label for="aiDescription">What's the form for?</label>
                <textarea id="aiDescription" placeholder="e.g. A holiday request form for staff. Capture the requester's name, the start and end date, the type of leave (annual / sick / parental / unpaid), an optional note, and a confirmation checkbox that they've checked the team rota."></textarea>
                <div class="ai-hint">Tell it what the form does and what info it needs to capture. The more specific you are, the better the result.</div>

                <div class="ai-examples">
                    <strong>Try:</strong>
                    <ul>
                        <li class="ai-example" data-text="A new starter onboarding form for the IT team. Capture the new starter's name, job title, start date, line manager, software needed (Outlook, Teams, Adobe, Visual Studio), and a notes field for special equipment.">New starter onboarding form for IT</li>
                        <li class="ai-example" data-text="A leaver form for HR. Capture the leaver's name, last working day, line manager, reason for leaving (resignation / retirement / redundancy / dismissal / end of contract), exit interview required (yes/no), and a notes field.">HR leaver form</li>
                        <li class="ai-example" data-text="An incident reporting form for end users. Subject, description, severity (low / medium / high / critical), affected service, when it started (date as text), and a checkbox confirming they've already tried restarting.">User incident reporting form</li>
                    </ul>
                </div>

                <div class="ai-progress" id="aiProgress" style="display:none;">
                    <div class="ai-progress-status">
                        <div class="ai-spinner"></div>
                        <span id="aiStatus">Designing your form&hellip;</span>
                    </div>
                    <div class="ai-progress-counters">
                        <span>Fields detected: <strong id="aiFieldCount">0</strong></span>
                        <span>Tokens in: <strong id="aiTokensIn">0</strong></span>
                        <span>Tokens out: <strong id="aiTokensOut">0</strong></span>
                        <span>Cached: <strong id="aiCacheRead">0</strong></span>
                    </div>
                    <pre class="ai-stream" id="aiStream"></pre>
                </div>
            </div>
            <div class="ai-modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeAiModal()">Cancel</button>
                <button type="button" class="btn btn-ai-assist" id="aiGenerateBtn" onclick="runAiGeneration()">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path></svg>
                    Generate
                </button>
            </div>
        </div>
    </div>
</body>
</html>
