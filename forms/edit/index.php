<?php
/**
 * Forms — dedicated edit page (replacement for the inline editor in
 * forms/index.php and the orphaned forms/builder.php). Pretty URL:
 *
 *   /forms/edit/         → create a new form
 *   /forms/edit/?id=42   → edit form #42
 *
 * Mounted at its own path so an edit session is a real URL the user can
 * bookmark, share, refresh, and back/forward through cleanly. The
 * inline editor in forms/index.php stays as-is for now per the
 * "don't delete anything yet" instruction — once this page is
 * confirmed good we'll cut it over.
 *
 * Includes the versioning metadata panel from #434 (which never made
 * it into the inline editor in forms/index.php — that was the bug we
 * spotted).
 */
session_start();
require_once '../../config.php';

$current_page = 'forms';
$path_prefix = '../../';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - Edit form</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/inbox.css">
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>assets/css/forms.css?v=<?= time() ?>">
    <style>
        /* The dedicated edit page doesn't use the sidebar/list layout
           of forms/index.php — it's a single full-width main panel
           laid out as a flex column so the sticky footer pins at the
           bottom and only .forms-main scrolls. */
        .forms-edit-page {
            display: flex;
            flex-direction: column;
            height: calc(100vh - 48px);
        }
        .forms-edit-page .forms-main {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
        }

        /* Sticky footer for the form-completion actions (Cancel + Save).
           Pinned via flex-shrink: 0 so the scrollbar inside .forms-main
           stops at this strip's top edge. */
        .editor-footer {
            flex-shrink: 0;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 12px 30px 19px;   /* +3px bottom for breathing room */
            border-top: 1px solid #e0e0e0;
            background: #f5f7fa;
        }

        /* Properties drawer — right-side slide-out. Holds the version
           metadata (#434). Hidden off-screen by default, slides in
           when toggled via the Properties button in the top toolbar.
           Backdrop dims the rest of the screen and closes on click. */
        .properties-backdrop {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.25);
            opacity: 0; pointer-events: none;
            transition: opacity 0.18s;
            z-index: 2400;
        }
        .properties-backdrop.open { opacity: 1; pointer-events: auto; }
        .properties-drawer {
            position: fixed;
            /* top is set inline by openPropertiesDrawer() to the
               actual measured .header height — assuming a fixed value
               here would mean any header restyle (e.g. nav-btn font
               change) leaves the drawer overlapping the navbar.
               Sensible fallback of 62px in case JS fails. */
            top: 62px;
            right: 0; bottom: 0;
            width: 360px;
            max-width: 90vw;
            background: white;
            box-shadow: -4px 0 16px rgba(0,0,0,0.08);
            transform: translateX(100%);
            transition: transform 0.22s ease;
            z-index: 2450;
            display: flex;
            flex-direction: column;
        }
        .properties-drawer.open { transform: translateX(0); }
        .properties-drawer-header {
            padding: 14px 18px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-shrink: 0;
        }
        .properties-drawer-header h3 {
            margin: 0; font-size: 15px; color: #333;
        }
        .properties-close {
            background: none; border: none;
            font-size: 22px; line-height: 1;
            color: #999; cursor: pointer; padding: 0 4px;
        }
        .properties-close:hover { color: #333; }
        .properties-drawer-body {
            padding: 18px;
            overflow-y: auto;
            flex: 1;
        }
        .properties-empty {
            font-size: 13px; color: #888; line-height: 1.6;
        }
        .properties-empty p { margin: 0; }

        /* Versioning metadata panel (#434). Lives in the drawer now;
           visible whenever the drawer is open and the form has been
           saved at least once. */
        .form-meta {
            padding: 4px 0;
            font-size: 13px;
            color: #555;
            line-height: 1.7;
            display: grid;
            grid-template-columns: max-content 1fr;
            column-gap: 14px;
            row-gap: 4px;
            margin: 0;
        }
        .form-meta dt { color: #888; font-weight: 500; margin: 0; }
        .form-meta dd { margin: 0; color: #333; }
        .form-meta .form-meta-version {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 10px;
            background: #00897b;
            color: white;
            font-weight: 600;
            font-size: 12px;
        }

        /* AI Assist — copied from forms/index.php so this page is
           self-contained and we don't have to chase css across files
           if we later modify the AI modal. */
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
    <?php include '../includes/header.php'; ?>

    <div class="forms-container forms-edit-page">
        <div class="forms-main">
            <!-- Top toolbar holds INSPECTION tools — AI Assist (build
                 for me) + Properties (show me the metadata). Save and
                 Cancel are the form-completion actions and live in the
                 sticky footer below where the eye naturally lands after
                 finishing the form. -->
            <div class="editor-toolbar">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <h2 id="editorTitle">New form</h2>
                    <div class="unsaved-indicator" id="unsavedIndicator">
                        <span class="unsaved-dot"></span>
                        Unsaved changes
                    </div>
                </div>
                <div class="editor-toolbar-actions">
                    <button class="btn btn-ai-assist" onclick="openAiModal()" title="Describe your form and let AI build it">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.9 5.8L20 10l-5.8 1.9L12 18l-1.9-5.8L4 10l6.1-2.2z"></path><path d="M19 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1z"></path><path d="M5 16l.6 1.8L7.5 18l-1.9.6L5 20l-.6-1.4L2.5 18l2-.2z"></path></svg>
                        AI Assist
                    </button>
                    <button class="btn btn-secondary" id="propertiesBtn" onclick="togglePropertiesDrawer()" title="Show form properties + version history">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                        Properties
                    </button>
                </div>
            </div>

            <!-- Title & description. Versioning metadata moved to the
                 Properties drawer (slides in from the right via the
                 toolbar button) so the main editor stays uncluttered. -->
            <div class="form-settings-card">
                <div class="field-group">
                    <label>Form title</label>
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
                    <h3>Form fields</h3>
                    <div class="add-field-btn">
                        <button class="btn btn-secondary" onclick="toggleAddMenu()" id="addFieldBtn">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                            Add
                        </button>
                        <div class="add-field-menu" id="addFieldMenu">
                            <button onclick="addField('text')"><span class="field-type-badge text">Abc</span> Text input</button>
                            <button onclick="addField('textarea')"><span class="field-type-badge textarea">Txt</span> Text area</button>
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

        <!-- Sticky footer with the form-completion actions. .forms-edit-page
             is a flex column so this pins at the bottom; the scrollbar in
             .forms-main stops at the footer's top edge. -->
        <div class="editor-footer">
            <button class="btn btn-secondary" onclick="cancelEdit()">Cancel</button>
            <button class="btn btn-primary save-btn" id="saveBtn" onclick="saveForm()">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
                Save
            </button>
        </div>
    </div>

    <!-- Properties drawer (right-side slide-out). Hidden by default;
         toggled via the Properties button in the top toolbar. ESC and
         backdrop click close it. -->
    <div class="properties-backdrop" id="propertiesBackdrop" onclick="closePropertiesDrawer()"></div>
    <aside class="properties-drawer" id="propertiesDrawer" aria-hidden="true">
        <div class="properties-drawer-header">
            <h3>Properties</h3>
            <button class="properties-close" onclick="closePropertiesDrawer()" title="Close" aria-label="Close">&times;</button>
        </div>
        <div class="properties-drawer-body">
            <!-- Populated by renderFormMeta() on load + after every save.
                 Shows a placeholder message until the form has been saved
                 at least once (and therefore has a version + author). -->
            <div id="propertiesEmpty" class="properties-empty">
                <p>This form hasn't been saved yet &mdash; properties will appear here once you create it.</p>
            </div>
            <dl class="form-meta" id="formMeta" style="display:none;">
                <dt>Version</dt>
                <dd><span class="form-meta-version" id="formMetaVersion">v1</span></dd>
                <dt>Author</dt>
                <dd id="formMetaAuthor">&mdash;</dd>
                <dt>Created</dt>
                <dd id="formMetaCreated">&mdash;</dd>
                <dt>Last modified</dt>
                <dd id="formMetaModified">&mdash;</dd>
                <dt>Modified by</dt>
                <dd id="formMetaModifiedBy">&mdash;</dd>
            </dl>
        </div>
    </aside>

    <!-- Toast notification -->
    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '<?php echo BASE_URL; ?>api/forms/';
        // Resolve the form id from the URL once on load. Saved into a
        // mutable variable so a successful create can pick up the new id.
        let currentFormId = (() => {
            const v = new URLSearchParams(window.location.search).get('id');
            const n = v ? parseInt(v, 10) : NaN;
            return Number.isFinite(n) && n > 0 ? n : null;
        })();
        let fields = [];
        let isDirty = false;
        let logoAlignment = 'center';

        // Track which element initiated a drag — the drag handle on a
        // field row vs an option row — so dragstart on the wrong target
        // doesn't fire.
        let fieldDragAllowed = false;
        let optDragAllowed = false;
        document.addEventListener('mousedown', function(e) {
            fieldDragAllowed = !!e.target.closest('.field-drag');
            optDragAllowed = !!e.target.closest('.option-drag');
        });

        document.addEventListener('DOMContentLoaded', function() {
            loadSettings();

            if (currentFormId) {
                document.getElementById('editorTitle').textContent = 'Edit form';
                loadFormForEdit(currentFormId);
            } else {
                document.getElementById('editorTitle').textContent = 'New form';
                renderFields();
                updatePreview();
            }

            // Close the add-field popup on outside click
            document.addEventListener('click', function(e) {
                const menu = document.getElementById('addFieldMenu');
                if (menu && !e.target.closest('.add-field-btn')) {
                    menu.classList.remove('open');
                }
            });

            document.getElementById('formTitle').addEventListener('input', function() {
                markDirty(); updatePreview();
            });
            document.getElementById('formDesc').addEventListener('input', function() {
                markDirty(); updatePreview();
            });

            // Warn before navigating away with unsaved work
            window.addEventListener('beforeunload', function(e) {
                if (isDirty) { e.preventDefault(); e.returnValue = ''; }
            });
        });

        // ===== Dirty state =====
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

        // ===== Toast =====
        function showToast(message, isError) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // ===== Load & versioning metadata =====
        async function loadFormForEdit(id) {
            try {
                const res = await fetch(API_BASE + 'get_form.php?id=' + id);
                const data = await res.json();
                if (!data.success) {
                    showToast(data.error || 'Form not found', true);
                    return;
                }
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
                renderFormMeta(data.form);
            } catch (e) {
                showToast('Failed to load form: ' + e.message, true);
            }
        }

        // Populate the Properties drawer's version metadata section.
        // Called after a successful load + after a save (so the new
        // version_number / modified_by show up immediately). Toggles
        // the "not yet saved" placeholder so the drawer never shows
        // dashes for a brand-new unsaved form.
        function renderFormMeta(form) {
            const meta  = document.getElementById('formMeta');
            const empty = document.getElementById('propertiesEmpty');
            if (!form || !form.id) {
                if (meta)  meta.style.display = 'none';
                if (empty) empty.style.display = '';
                return;
            }
            const fmt = (s) => {
                if (!s) return '—';
                const d = new Date(s.replace(' ', 'T') + 'Z');
                if (isNaN(d.getTime())) return s;
                return d.toLocaleString();
            };
            document.getElementById('formMetaVersion').textContent    = 'v' + (form.version_number || 1);
            document.getElementById('formMetaAuthor').textContent     = form.created_by_name || '—';
            document.getElementById('formMetaCreated').textContent    = fmt(form.created_date);
            document.getElementById('formMetaModified').textContent   = fmt(form.modified_date);
            document.getElementById('formMetaModifiedBy').textContent = form.modified_by_name || '—';
            if (meta)  meta.style.display = '';
            if (empty) empty.style.display = 'none';
        }

        // Properties drawer — slide-in from the right with the form's
        // version metadata. Toggled by the Properties button in the
        // top toolbar. Closed by the X, the backdrop, or ESC.
        function togglePropertiesDrawer() {
            const drawer   = document.getElementById('propertiesDrawer');
            const backdrop = document.getElementById('propertiesBackdrop');
            const open = drawer.classList.contains('open');
            if (open) closePropertiesDrawer();
            else openPropertiesDrawer();
        }
        function openPropertiesDrawer() {
            const drawer = document.getElementById('propertiesDrawer');
            // Measure the global header so the drawer always tucks
            // under it, regardless of how tall the navbar actually
            // renders (it's ~60px today but I'd rather not bake that
            // in — see #415 for the same trap we hit on morning-checks).
            const header = document.querySelector('.header');
            if (header) drawer.style.top = header.offsetHeight + 'px';
            drawer.classList.add('open');
            document.getElementById('propertiesBackdrop').classList.add('open');
            drawer.setAttribute('aria-hidden', 'false');
        }
        function closePropertiesDrawer() {
            document.getElementById('propertiesDrawer').classList.remove('open');
            document.getElementById('propertiesBackdrop').classList.remove('open');
            document.getElementById('propertiesDrawer').setAttribute('aria-hidden', 'true');
        }
        // ESC closes the drawer (but only if it's open and no other
        // modal owns the keypress — the AI modal handles its own).
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            if (document.getElementById('aiModal').classList.contains('active')) return;
            if (document.getElementById('propertiesDrawer').classList.contains('open')) {
                closePropertiesDrawer();
            }
        });

        // ===== Cancel / back =====
        function cancelEdit() {
            if (isDirty && !confirm('You have unsaved changes. Discard them?')) return;
            isDirty = false;   // skip the beforeunload warning
            window.location.href = '<?php echo BASE_URL; ?>forms/';
        }

        // ===== Tabs =====
        function switchFormTab(tab) {
            document.getElementById('tabFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabPreview').classList.toggle('active', tab === 'preview');
            document.getElementById('tabContentFields').classList.toggle('active', tab === 'fields');
            document.getElementById('tabContentPreview').classList.toggle('active', tab === 'preview');
            if (tab === 'preview') updatePreview();
        }

        // ===== Fields =====
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
                            <div class="field-options-label">Dropdown options</div>
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
                            <button class="add-option-btn" onclick="addOption(${i})">+ Add option</button>
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
        function updateLabel(i, val)    { fields[i].label = val;       markDirty(); updatePreview(); }
        function toggleRequired(i, val) { fields[i].is_required = val; markDirty(); updatePreview(); }
        function deleteField(i) {
            fields.splice(i, 1);
            markDirty(); renderFields(); updatePreview();
        }
        function addOption(fi) {
            fields[fi].options.push('');
            markDirty(); renderFields();
            setTimeout(() => {
                const items = document.querySelectorAll(`.field-item[data-index="${fi}"] .option-item input[type="text"]`);
                if (items.length) items[items.length - 1].focus();
            }, 50);
        }
        function updateOption(fi, oi, val) {
            fields[fi].options[oi] = val;
            markDirty(); updatePreview();
        }
        function removeOption(fi, oi) {
            fields[fi].options.splice(oi, 1);
            markDirty(); renderFields(); updatePreview();
        }
        function onOptionKeydown(e, fi, oi) {
            if (e.key === 'Enter') {
                e.preventDefault();
                fields[fi].options[oi] = e.target.value;
                addOption(fi);
            }
        }

        // ===== Field drag & drop =====
        let dragFieldIndex = null;
        function onFieldDragStart(e, i) {
            if (!fieldDragAllowed) { e.preventDefault(); return; }
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
            e.currentTarget.classList.add(e.clientY < midY ? 'drag-over-top' : 'drag-over-bottom');
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
            markDirty(); renderFields(); updatePreview();
        }

        // ===== Option drag & drop =====
        let dragOptFieldIndex = null;
        let dragOptIndex = null;
        function onOptDragStart(e, fi, oi) {
            if (!optDragAllowed) { e.preventDefault(); return; }
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
            e.currentTarget.classList.add(e.clientY < midY ? 'drag-over-top' : 'drag-over-bottom');
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
            markDirty(); renderFields(); updatePreview();
        }

        // ===== Preview =====
        function updatePreview() {
            const title = document.getElementById('formTitle').value || 'Untitled form';
            const desc = document.getElementById('formDesc').value;
            const preview = document.getElementById('previewContent');
            if (fields.length === 0) {
                preview.innerHTML = '<p class="preview-empty">Add fields to see a preview</p>';
                return;
            }
            const alignClass = 'align-' + logoAlignment;
            let html = `<img src="<?php echo BASE_URL; ?>assets/images/CompanyLogo.png" alt="Company logo" class="preview-logo ${alignClass}">`;
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

        // ===== Save =====
        async function saveForm() {
            const title = document.getElementById('formTitle').value.trim();
            if (!title) { showToast('Please enter a form title', true); return; }
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
            if (currentFormId) payload.id = currentFormId;
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
                        // Promote the URL from /forms/edit/ to /forms/edit/?id=N
                        // so a refresh keeps the user in the same form.
                        history.replaceState(null, '', './?id=' + currentFormId);
                        document.getElementById('editorTitle').textContent = 'Edit form';
                    }
                    clearDirty();
                    showToast('Form saved successfully');
                    // Reload so the version pill / modified-by reflect the
                    // bump from this save.
                    loadFormForEdit(currentFormId);
                } else {
                    showToast('Error: ' + data.error, true);
                }
            } catch (e) {
                showToast('Failed to save form', true);
            }
        }

        // ===== Settings (logo alignment for preview) =====
        async function loadSettings() {
            try {
                const res = await fetch(API_BASE + 'get_settings.php');
                const data = await res.json();
                if (data.success && data.settings) {
                    logoAlignment = data.settings.logo_alignment || 'center';
                }
            } catch (e) {
                // Defaults stand
            }
        }

        // ===== Utility =====
        function esc(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== AI Assist (streaming SSE) =====
        // Two flavours, switched at modal-open time based on whether
        // there's already a form to modify:
        //  - NEW mode: user describes the form they want, AI builds
        //    it from scratch.
        //  - EDIT mode: user describes a CHANGE, AI receives the
        //    current form as context and returns an updated copy.
        // The backend (ai_generate.php) keys off whether current_form
        // is sent in the payload — see #439.
        let aiAbortController = null;

        // True when there's enough form content to treat the AI call
        // as an edit rather than a fresh build.
        function isEditingExistingForm() {
            const hasFields = fields.length > 0;
            const hasTitle  = (document.getElementById('formTitle').value || '').trim() !== '';
            return hasFields || hasTitle;
        }

        // Suggested-prompt lists shown inside the modal. Swapped at
        // open-time so the user sees relevant ideas for the mode
        // they're in.
        const AI_EXAMPLES_NEW = [
            { label: 'New starter onboarding form for IT', text: "A new starter onboarding form for the IT team. Capture the new starter's name, job title, start date, line manager, software needed (Outlook, Teams, Adobe, Visual Studio), and a notes field for special equipment." },
            { label: 'HR leaver form',                    text: "A leaver form for HR. Capture the leaver's name, last working day, line manager, reason for leaving (resignation / retirement / redundancy / dismissal / end of contract), exit interview required (yes/no), and a notes field." },
            { label: 'User incident reporting form',       text: "An incident reporting form for end users. Subject, description, severity (low / medium / high / critical), affected service, when it started (date as text), and a checkbox confirming they've already tried restarting." },
        ];
        const AI_EXAMPLES_EDIT = [
            { label: 'Add a phone number field',                    text: 'Add a phone number field after the email address. Required.' },
            { label: 'Make all fields required',                    text: 'Mark every field as required.' },
            { label: 'Reorder so the name field comes first',       text: 'Reorder the fields so the name field is at the top.' },
            { label: 'Tighten the description to one short sentence', text: 'Rewrite the description to one short, neutral sentence (under 25 words).' },
            { label: 'Remove the consent checkbox',                 text: 'Remove the consent checkbox at the bottom.' },
        ];

        function openAiModal() {
            const editing = isEditingExistingForm();

            // Toggle modal copy to match the mode.
            document.getElementById('aiModalTitle').innerHTML = editing
                ? '<span class="ai-sparkle">&#10024;</span> AI Assist &mdash; what would you like to change?'
                : '<span class="ai-sparkle">&#10024;</span> AI Assist &mdash; describe your form';
            document.getElementById('aiPromptLabel').textContent = editing
                ? 'What change do you want?'
                : "What's the form for?";
            const ta = document.getElementById('aiDescription');
            ta.placeholder = editing
                ? 'e.g. Add a date-of-birth field. Make the email field required. Rewrite the description to mention the SLA.'
                : "e.g. A holiday request form for staff. Capture the requester's name, the start and end date, the type of leave (annual / sick / parental / unpaid), an optional note, and a confirmation checkbox that they've checked the team rota.";
            document.getElementById('aiHint').textContent = editing
                ? "The AI will see the current form and modify it based on your request — it won't rebuild from scratch."
                : 'Tell it what the form does and what info it needs to capture. The more specific you are, the better the result.';

            // Swap the suggested-prompt list.
            const list = document.getElementById('aiExamplesList');
            const examples = editing ? AI_EXAMPLES_EDIT : AI_EXAMPLES_NEW;
            list.innerHTML = examples.map(e =>
                `<li class="ai-example" data-text="${escAttr(e.text)}">${escHtml(e.label)}</li>`
            ).join('');
            // (Re-)wire each example. We re-bind on every open because
            // the list contents change between modes.
            document.querySelectorAll('.ai-example').forEach(el => {
                el.addEventListener('click', () => {
                    document.getElementById('aiDescription').value = el.dataset.text || '';
                    document.getElementById('aiDescription').focus();
                });
            });

            document.getElementById('aiModal').classList.add('active');
            ta.value = '';
            setTimeout(() => ta.focus(), 50);
            resetAiProgress();
        }

        // Small attribute escape helper for the example data-text values
        // (the existing esc() is fine for inner-text but we need quote
        // escaping for attribute values).
        function escAttr(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }
        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s == null ? '' : String(s);
            return d.innerHTML;
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
            const editing = isEditingExistingForm();
            if (!description) {
                showToast(editing ? 'Please describe what you want to change' : 'Please describe the form you want to build', true);
                return;
            }
            if (description.length > 2000) {
                showToast('Description is too long (max 2000 characters)', true);
                return;
            }
            // No destructive-replace warning in edit mode — the backend
            // (ai_generate.php #439) preserves the existing form and
            // applies the user's modification, so this isn't a nuke.
            // For brand-new forms there's nothing to lose either.

            const generateBtn = document.getElementById('aiGenerateBtn');
            generateBtn.disabled = true;
            const prog = document.getElementById('aiProgress');
            prog.style.display = 'block';
            prog.classList.remove('error');
            const status = document.getElementById('aiStatus');
            const stream = document.getElementById('aiStream');
            stream.textContent = '';
            status.textContent = editing ? 'Applying your change…' : 'Designing your form…';
            aiAbortController = new AbortController();

            // Snapshot the current form to send as context when editing.
            // Same shape as the request payload we send to save_form so
            // the AI sees clean, normalised data.
            const payload = { description: description };
            if (editing) {
                payload.current_form = {
                    title:       document.getElementById('formTitle').value.trim(),
                    description: document.getElementById('formDesc').value.trim(),
                    fields:      fields.map(f => ({
                        field_type:  f.field_type,
                        label:       (f.label || '').trim(),
                        options:     f.field_type === 'dropdown' ? (f.options || []).filter(o => o && o.trim()) : [],
                        is_required: !!f.is_required,
                    })),
                };
            }

            try {
                const resp = await fetch(API_BASE + 'ai_generate.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
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
                            showToast(editing
                                ? `Form updated — ${data.form.fields.length} ${fieldWord} in ${seconds}s`
                                : `Form built — ${data.form.fields.length} ${fieldWord} in ${seconds}s`, false);
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

    <!-- AI Assist Modal — copy + examples swap between New and Edit
         modes when the modal opens (see openAiModal). -->
    <div class="ai-modal-overlay" id="aiModal">
        <div class="ai-modal">
            <div class="ai-modal-header">
                <h3 id="aiModalTitle"><span class="ai-sparkle">&#10024;</span> AI Assist &mdash; describe your form</h3>
                <button type="button" class="ai-modal-close" onclick="closeAiModal()">&times;</button>
            </div>
            <div class="ai-modal-body">
                <label for="aiDescription" id="aiPromptLabel">What's the form for?</label>
                <textarea id="aiDescription"></textarea>
                <div class="ai-hint" id="aiHint">Tell it what the form does and what info it needs to capture. The more specific you are, the better the result.</div>

                <div class="ai-examples">
                    <strong>Try:</strong>
                    <ul id="aiExamplesList"></ul>
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
