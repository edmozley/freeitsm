<?php
/**
 * Forms Module - Form Builder/Designer
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
    <title>Service Desk - Form Builder</title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script src="../assets/js/toast.js"></script>
    <style>
        .builder-container {
            flex: 1;
            overflow-y: auto;
            background-color: #f5f7fa;
        }

        .builder-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 25px;
        }

        .builder-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .builder-toolbar h2 {
            margin: 0;
            font-size: 20px;
            color: #333;
        }

        .toolbar-actions {
            display: flex;
            gap: 8px;
        }

        .btn {
            padding: 9px 18px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.15s;
            text-decoration: none;
        }

        .btn-primary { background: #00897b; color: white; }
        .btn-primary:hover { background: #00695c; }
        .btn-secondary { background: #f5f7fa; color: #333; border: 1px solid #ddd; }
        .btn-secondary:hover { background: #eef0f2; }

        .builder-layout {
            display: grid;
            grid-template-columns: 1fr 380px;
            gap: 20px;
        }

        /* Form Settings */
        .form-settings {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            padding: 20px;
            margin-bottom: 16px;
        }

        .form-settings input, .form-settings textarea {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            font-family: inherit;
            box-sizing: border-box;
        }

        .form-settings input:focus, .form-settings textarea:focus {
            outline: none;
            border-color: #00897b;
            box-shadow: 0 0 0 2px rgba(0,137,123,0.1);
        }

        .form-settings label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #666;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .form-settings .field-group { margin-bottom: 14px; }
        .form-settings .field-group:last-child { margin-bottom: 0; }

        /* Fields List */
        .fields-panel {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            padding: 20px;
        }

        .fields-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 14px;
        }

        .fields-header h3 {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
            color: #333;
        }

        .add-field-btn {
            position: relative;
        }

        .add-field-menu {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 10;
            min-width: 180px;
            padding: 4px 0;
        }

        .add-field-menu.open { display: block; }

        .add-field-menu button {
            display: flex;
            align-items: center;
            gap: 8px;
            width: 100%;
            padding: 8px 14px;
            border: none;
            background: none;
            font-size: 13px;
            cursor: pointer;
            color: #333;
        }

        .add-field-menu button:hover { background: #f5f7fa; }

        .field-type-badge {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            padding: 1px 6px;
            border-radius: 3px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        .field-type-badge.text { background: #e3f2fd; color: #1565c0; }
        .field-type-badge.textarea { background: #f3e5f5; color: #7b1fa2; }
        .field-type-badge.checkbox { background: #e8f5e9; color: #2e7d32; }
        .field-type-badge.dropdown { background: #fff3e0; color: #e65100; }

        /* Field Items */
        .field-list { list-style: none; padding: 0; margin: 0; }

        .field-item {
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px 14px;
            margin-bottom: 8px;
            background: #fafafa;
            transition: box-shadow 0.15s;
        }

        .field-item:hover { box-shadow: 0 1px 4px rgba(0,0,0,0.08); }

        .field-item-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .field-drag {
            cursor: grab;
            color: #bbb;
            padding: 2px;
        }

        .field-label-input {
            flex: 1;
            padding: 6px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
        }

        .field-label-input:focus {
            outline: none;
            border-color: #00897b;
        }

        .field-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .field-required-toggle {
            display: flex;
            align-items: center;
            gap: 4px;
            font-size: 11px;
            color: #888;
            cursor: pointer;
        }

        .field-required-toggle input { margin: 0; }

        .field-move-btn, .field-delete-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #999;
            border-radius: 3px;
        }

        .field-move-btn:hover { color: #333; background: #eee; }
        .field-delete-btn:hover { color: #d32f2f; background: #ffebee; }

        /* Dropdown Options */
        .field-options {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #eee;
        }

        .field-options-label {
            font-size: 11px;
            color: #888;
            margin-bottom: 6px;
            font-weight: 600;
        }

        .option-item {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-bottom: 4px;
        }

        .option-item input {
            flex: 1;
            padding: 5px 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            font-family: inherit;
        }

        .option-item input:focus {
            outline: none;
            border-color: #00897b;
        }

        .option-remove {
            background: none;
            border: none;
            cursor: pointer;
            color: #ccc;
            padding: 2px;
            font-size: 16px;
        }

        .option-remove:hover { color: #d32f2f; }

        .add-option-btn {
            background: none;
            border: 1px dashed #ccc;
            border-radius: 3px;
            padding: 4px 10px;
            font-size: 12px;
            cursor: pointer;
            color: #888;
            width: 100%;
            margin-top: 4px;
        }

        .add-option-btn:hover { border-color: #00897b; color: #00897b; }

        .no-fields {
            text-align: center;
            padding: 30px;
            color: #999;
            font-size: 13px;
        }

        /* Preview Panel */
        .preview-panel {
            position: sticky;
            top: 25px;
        }

        .preview-card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.08);
            padding: 20px;
        }

        .preview-card h3 {
            margin: 0 0 16px;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding-bottom: 8px;
            border-bottom: 1px solid #eee;
        }

        .preview-title {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 0 0 4px;
        }

        .preview-desc {
            font-size: 13px;
            color: #888;
            margin: 0 0 16px;
        }

        .preview-field {
            margin-bottom: 14px;
        }

        .preview-field label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: #333;
            margin-bottom: 4px;
        }

        .preview-field label .required-star {
            color: #d32f2f;
            margin-left: 2px;
        }

        .preview-field input[type="text"],
        .preview-field textarea,
        .preview-field select {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 13px;
            font-family: inherit;
            box-sizing: border-box;
            background: #f9f9f9;
        }

        .preview-field textarea { min-height: 60px; resize: vertical; }

        .preview-field .checkbox-row {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
        }

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

    <div class="main-container builder-container">
        <div class="builder-content">
            <div class="builder-toolbar">
                <h2 id="pageTitle">New Form</h2>
                <div class="toolbar-actions">
                    <a href="./" class="btn btn-secondary">Cancel</a>
                    <button class="btn btn-ai-assist" onclick="openAiModal()" title="Describe your form and let AI build it">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path><path d="M19 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"></path><path d="M5 16l.6 1.8L7.5 18l-1.9.6L5 20l-.6-1.4L2.5 18l2-.2z"></path></svg>
                        AI Assist
                    </button>
                    <button class="btn btn-primary" onclick="saveForm()">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                        Save Form
                    </button>
                </div>
            </div>

            <div class="builder-layout">
                <div class="builder-left">
                    <div class="form-settings">
                        <div class="field-group">
                            <label>Form Title</label>
                            <input type="text" id="formTitle" placeholder="Enter form title...">
                        </div>
                        <div class="field-group">
                            <label>Description</label>
                            <textarea id="formDesc" rows="2" placeholder="Optional description..."></textarea>
                        </div>
                    </div>

                    <div class="fields-panel">
                        <div class="fields-header">
                            <h3>Fields</h3>
                            <div class="add-field-btn">
                                <button class="btn btn-secondary" onclick="toggleAddMenu()" id="addFieldBtn">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                                    Add Field
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
                            <li class="no-fields" id="noFieldsMsg">No fields added yet. Click "Add Field" to start building your form.</li>
                        </ul>
                    </div>
                </div>

                <div class="preview-panel">
                    <div class="preview-card">
                        <h3>Preview</h3>
                        <div id="previewContent">
                            <p style="color:#999;font-size:13px;text-align:center">Add fields to see a preview</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../api/forms/';
        let formId = null;
        let fields = [];

        document.addEventListener('DOMContentLoaded', function() {
            const params = new URLSearchParams(window.location.search);
            formId = params.get('id');
            if (formId) {
                document.getElementById('pageTitle').textContent = 'Edit Form';
                loadForm(formId);
            }

            document.addEventListener('click', function(e) {
                if (!e.target.closest('.add-field-btn')) {
                    document.getElementById('addFieldMenu').classList.remove('open');
                }
            });
        });

        async function loadForm(id) {
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
            }
        }

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
            renderFields();
            updatePreview();
            // Focus the new field's label input
            setTimeout(() => {
                const inputs = document.querySelectorAll('.field-label-input');
                if (inputs.length) inputs[inputs.length - 1].focus();
            }, 50);
        }

        function renderFields() {
            const list = document.getElementById('fieldList');
            const noMsg = document.getElementById('noFieldsMsg');

            if (fields.length === 0) {
                list.innerHTML = '<li class="no-fields">No fields added yet. Click "Add Field" to start building your form.</li>';
                return;
            }

            list.innerHTML = fields.map((f, i) => {
                let optionsHtml = '';
                if (f.field_type === 'dropdown') {
                    optionsHtml = `
                        <div class="field-options">
                            <div class="field-options-label">Dropdown Options</div>
                            ${(f.options || []).map((opt, oi) => `
                                <div class="option-item">
                                    <input type="text" value="${esc(opt)}" onchange="updateOption(${i}, ${oi}, this.value)" placeholder="Option ${oi + 1}">
                                    <button class="option-remove" onclick="removeOption(${i}, ${oi})">&times;</button>
                                </div>
                            `).join('')}
                            <button class="add-option-btn" onclick="addOption(${i})">+ Add Option</button>
                        </div>`;
                }

                return `
                    <li class="field-item" data-index="${i}">
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
                                <button class="field-move-btn" onclick="moveField(${i}, -1)" title="Move up" ${i === 0 ? 'disabled' : ''}>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="18 15 12 9 6 15"></polyline></svg>
                                </button>
                                <button class="field-move-btn" onclick="moveField(${i}, 1)" title="Move down" ${i === fields.length - 1 ? 'disabled' : ''}>
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg>
                                </button>
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

        function updateLabel(i, val) { fields[i].label = val; updatePreview(); }
        function toggleRequired(i, val) { fields[i].is_required = val; updatePreview(); }

        function moveField(i, dir) {
            const j = i + dir;
            if (j < 0 || j >= fields.length) return;
            [fields[i], fields[j]] = [fields[j], fields[i]];
            renderFields();
            updatePreview();
        }

        function deleteField(i) {
            fields.splice(i, 1);
            renderFields();
            updatePreview();
        }

        function addOption(fi) {
            fields[fi].options.push('');
            renderFields();
            // Focus the new option input
            setTimeout(() => {
                const items = document.querySelectorAll(`.field-item[data-index="${fi}"] .option-item input`);
                if (items.length) items[items.length - 1].focus();
            }, 50);
        }

        function updateOption(fi, oi, val) {
            fields[fi].options[oi] = val;
            updatePreview();
        }

        function removeOption(fi, oi) {
            fields[fi].options.splice(oi, 1);
            renderFields();
            updatePreview();
        }

        function updatePreview() {
            const title = document.getElementById('formTitle').value || 'Untitled Form';
            const desc = document.getElementById('formDesc').value;
            const preview = document.getElementById('previewContent');

            if (fields.length === 0) {
                preview.innerHTML = '<p style="color:#999;font-size:13px;text-align:center">Add fields to see a preview</p>';
                return;
            }

            let html = `<p class="preview-title">${esc(title)}</p>`;
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

        // Update preview on title/desc change
        document.getElementById('formTitle').addEventListener('input', updatePreview);
        document.getElementById('formDesc').addEventListener('input', updatePreview);

        async function saveForm() {
            const title = document.getElementById('formTitle').value.trim();
            if (!title) {
                showToast('Please enter a form title', 'error');
                return;
            }

            const validFields = fields.filter(f => f.label.trim());
            if (validFields.length === 0) {
                showToast('Please add at least one field with a label', 'error');
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

            if (formId) payload.id = parseInt(formId);

            try {
                const res = await fetch(API_BASE + 'save_form.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();

                if (data.success) {
                    if (!formId) {
                        formId = data.form_id;
                        history.replaceState(null, '', 'builder.php?id=' + formId);
                        document.getElementById('pageTitle').textContent = 'Edit Form';
                    }
                    showToast('Form saved', 'success');
                } else {
                    showToast('Error: ' + data.error, 'error');
                }
            } catch (e) {
                showToast('Failed to save form', 'error');
            }
        }

        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ---------------- AI Assist ----------------
        let aiAbortController = null;

        function openAiModal() {
            document.getElementById('aiModal').classList.add('active');
            const ta = document.getElementById('aiDescription');
            ta.value = '';
            setTimeout(() => ta.focus(), 50);
            resetAiProgress();

            // Wire example clicks (idempotent — flag the element so we only attach once)
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
            // Abort any in-flight stream
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
                showToast('Please describe the form you want to build', 'error');
                return;
            }
            if (description.length > 2000) {
                showToast('Description is too long (max 2000 characters)', 'error');
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
                let lastEventName = '';
                let dataLines = [];

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
                            // Heuristic field counter: count occurrences of "field_type"
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
                        case 'done':
                            applyGeneratedForm(data.form);
                            const seconds = data.duration_ms ? (data.duration_ms / 1000).toFixed(1) : '?';
                            const fieldWord = data.form.fields.length === 1 ? 'field' : 'fields';
                            showToast(`Form built — ${data.form.fields.length} ${fieldWord} in ${seconds}s`, 'success');
                            closeAiModal();
                            break;
                        case 'error':
                            throw new Error(data.message || 'AI request failed');
                    }
                };

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;
                    buffer += decoder.decode(value, { stream: true });

                    // Split off complete SSE events (\n\n delimiter)
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
                    // User cancelled; nothing to do
                } else {
                    prog.classList.add('error');
                    document.getElementById('aiStatus').textContent = 'Error: ' + err.message;
                    showToast('AI Assist failed: ' + err.message, 'error');
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
