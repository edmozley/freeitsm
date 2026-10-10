<?php
/**
 * Checklists — the SOP template editor, on its own screen.
 *
 * WHY THIS IS NOT A MODAL (it was, as contributed in PR #141):
 *
 * A template is a parent with an UNBOUNDED list of children, each child having
 * five attributes of its own. That is the same shape as a form and its fields,
 * and forms/edit/ is a full screen for exactly this reason. A 680px modal at
 * 88vh with its own inner scrollbar is a bad place to drag a fifteen-step
 * procedure into order — the drop target is often off-screen and a scrolling
 * modal body cannot auto-scroll during a drag without a lot of bespoke work.
 *
 * A screen also gets a URL: ?id=N can be linked to from a ticket, the browser's
 * back button behaves, and the unsaved-changes guard has something to guard.
 *
 * House rule the shape follows: a modal is for a handful of fields; anything
 * with a repeating child list gets a screen (forms, process mapper, knowledge).
 *
 * The drag-and-drop is the forms module's implementation, deliberately — same
 * handle-gated dragstart, same midpoint drop calculation, same class names for
 * the drop indicator. Copied rather than re-invented so the two behave alike.
 */
session_start();
require_once '../../config.php';
require_once '../../includes/functions.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';

// ⚠️ The client needs these too. This editor builds its step rows in
// JavaScript, so the labels inside them never pass through PHP's t() - they
// are looked up by window.t() against window.translations, published below.
$translationNamespaces = ['common', 'checklists'];

I18n::initFromSession();
Tz::init();
requireModuleAccess('checklists');

$current_page = 'templates';
$path_prefix  = '../../';

$conn = connectToDatabase();

$templateId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$definedRoles = $conn->query("SELECT name FROM checklist_roles ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
$categories   = $conn->query("SELECT name FROM checklist_categories ORDER BY name ASC")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(t('checklists.editor.page_title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=78">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js?v=3"></script>
    <style>
        html, body { height: auto !important; min-height: 100vh; overflow-y: auto !important; overflow-x: hidden; margin: 0; padding: 0; background: var(--app-bg, #f8fafc); }
        .ed-shell { display: flex; flex-direction: column; min-height: 100vh; }

        .ed-bar {
            display: flex; align-items: center; gap: 14px;
            padding: 14px 32px;
            background: var(--surface, #fff);
            border-bottom: 1px solid var(--border-soft, #e2e8f0);
            position: sticky; top: 0; z-index: 20;
        }
        .ed-bar h1 { margin: 0; font-size: 18px; font-weight: 700; color: var(--text, #0f172a); }
        .ed-bar .spacer { flex: 1; }
        .ed-unsaved { font-size: 12px; color: var(--text-muted, #64748b); opacity: 0; transition: opacity 0.15s; }
        .ed-unsaved.visible { opacity: 1; }

        .ed-body { flex: 1 1 auto; width: 100%; box-sizing: border-box; padding: 24px 32px 60px; }
        .ed-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.4fr); gap: 24px; align-items: start; }
        @media (max-width: 1000px) { .ed-grid { grid-template-columns: 1fr; } }

        .ed-card { background: var(--surface, #fff); border: 1px solid var(--border-soft, #e2e8f0); border-radius: 8px; padding: 20px 22px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .ed-card h2 { margin: 0 0 4px 0; font-size: 15px; font-weight: 700; color: var(--text, #0f172a); }
        .ed-card .ed-sub { margin: 0 0 16px 0; font-size: 12px; color: var(--text-muted, #64748b); }

        .ed-field { display: flex; flex-direction: column; gap: 6px; margin-bottom: 14px; }
        .ed-field label { font-size: 12px; font-weight: 600; color: var(--text-muted, #64748b); }
        .ed-input, .ed-select, .ed-textarea {
            width: 100%; box-sizing: border-box;
            padding: 8px 12px; font-size: 13px;
            border: 1px solid var(--border-soft, #cbd5e1); border-radius: 6px;
            background: var(--surface, #fff); color: var(--text, #1e293b);
            font-family: inherit;
        }
        .ed-textarea { min-height: 78px; resize: vertical; }

        .btn-teal { background: #0d9488; color: #fff; border: none; padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-teal:hover { background: #0f766e; }
        /* Also used on an <a>, which inherits the global link underline and
         * colour unless both are reset here. */
        .btn-plain {
            background: var(--surface, #fff); color: var(--text, #334155);
            border: 1px solid var(--border-soft, #cbd5e1);
            padding: 8px 16px; border-radius: 6px;
            font-size: 13px; font-weight: 500; cursor: pointer;
            text-decoration: none; display: inline-flex; align-items: center;
        }
        .btn-plain:hover { background: var(--surface-hover, #f1f5f9); text-decoration: none; }

        /* ── Steps. Drag mechanics and class names mirror forms/edit. ── */
        .step-list { list-style: none; margin: 0; padding: 0; }
        .step-item {
            border: 1px solid var(--border-soft, #e2e8f0);
            border-radius: 6px;
            background: var(--app-bg, #f8fafc);
            padding: 10px 12px;
            margin-bottom: 8px;
            transition: box-shadow 0.15s, opacity 0.15s, border-color 0.15s;
        }
        .step-item:hover { box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        .step-item.dragging { opacity: 0.4; }
        .step-item.drag-over-top    { border-top: 2px solid #0d9488; margin-top: -1px; }
        .step-item.drag-over-bottom { border-bottom: 2px solid #0d9488; margin-bottom: 7px; }

        .step-head { display: flex; align-items: center; gap: 8px; }
        .step-drag { cursor: grab; color: var(--text-faint, #bbb); padding: 2px; touch-action: none; display: inline-flex; }
        .step-drag:active { cursor: grabbing; }
        .step-num { font-size: 11px; font-weight: 700; color: var(--text-muted, #64748b); min-width: 18px; text-align: right; }
        .step-title { flex: 2; }
        .step-role  { flex: 1.1; }
        .step-del { background: none; border: none; color: var(--danger-accent, #d13438); cursor: pointer; padding: 4px; border-radius: 4px; display: inline-flex; }
        .step-del:hover { background: var(--danger-bg, #fdf3f3); }

        .step-opts { display: flex; align-items: center; gap: 18px; margin-top: 8px; padding-left: 28px; flex-wrap: wrap; }
        .step-opt { display: flex; align-items: center; gap: 8px; font-size: 12px; color: var(--text, #334155); }
        .step-ph-wrap { flex: 1 1 220px; min-width: 180px; }

        .ed-empty { text-align: center; padding: 26px; font-size: 13px; color: var(--text-muted, #64748b); border: 1px dashed var(--border-soft, #cbd5e1); border-radius: 6px; }
    </style>
    <link rel="stylesheet" href="../../assets/css/mobile.css?v=192">
</head>
<body data-mobile-page="checklists-edit" data-analyst-id="<?php echo $_SESSION['analyst_id'] ?? ''; ?>" class="ed-shell">
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="ed-bar">
        <a href="<?php echo BASE_URL; ?>checklists/" class="btn-plain" id="edBack"><?php echo htmlspecialchars(t('checklists.editor.back')); ?></a>
        <h1 id="edTitle"><?php echo htmlspecialchars(t('checklists.editor.new_template')); ?></h1>
        <span class="ed-unsaved" id="edUnsaved"><?php echo htmlspecialchars(t('checklists.editor.unsaved')); ?></span>
        <div class="spacer"></div>
        <button class="btn-teal" id="edSave"><?php echo htmlspecialchars(t('checklists.editor.save')); ?></button>
    </div>

    <div class="ed-body">
        <div class="ed-grid">
            <div class="ed-card">
                <h2><?php echo htmlspecialchars(t('checklists.editor.heading_template')); ?></h2>
                <p class="ed-sub"><?php echo htmlspecialchars(t('checklists.editor.heading_template_sub')); ?></p>

                <div class="ed-field">
                    <label for="edName"><?php echo htmlspecialchars(t('checklists.editor.title')); ?></label>
                    <input type="text" id="edName" class="ed-input" maxlength="255" placeholder="<?php echo htmlspecialchars(t('checklists.editor.title_ph')); ?>">
                </div>
                <div class="ed-field">
                    <label for="edCategory"><?php echo htmlspecialchars(t('checklists.editor.category')); ?></label>
                    <input type="text" id="edCategory" class="ed-input" maxlength="100" list="edCategoryList" placeholder="<?php echo htmlspecialchars(t('checklists.editor.category_ph')); ?>">
                    <datalist id="edCategoryList">
                        <?php foreach ($categories as $c): ?><option value="<?php echo htmlspecialchars($c, ENT_QUOTES); ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="ed-field">
                    <label for="edScope"><?php echo htmlspecialchars(t('checklists.editor.applies_to')); ?></label>
                    <select id="edScope" class="ed-select">
                        <option value="both"><?php echo htmlspecialchars(t('checklists.editor.applies_both')); ?></option>
                        <option value="ticket"><?php echo htmlspecialchars(t('checklists.editor.applies_ticket')); ?></option>
                        <option value="task"><?php echo htmlspecialchars(t('checklists.editor.applies_task')); ?></option>
                    </select>
                    <?php
                    // Checklists on tasks are behind Tasks → Settings → Checklists
                    // (discussion #138). Until that is on, a checklist "for Tasks"
                    // can never be attached to one - so say so here, where the
                    // choice is made, rather than let it look like it worked.
                    require_once '../../includes/services/checklists.php';
                    if (!ChecklistsService::tasksEnabled($conn)): ?>
                    <div class="ed-hint" style="margin-top:4px;font-size:12px;color:var(--text-muted, #666);"><?php echo htmlspecialchars(t('checklists.editor.tasks_off_hint')); ?></div>
                    <?php endif; ?>
                </div>
                <div class="ed-field">
                    <label for="edClosureMode"><?php echo htmlspecialchars(t('checklists.editor.closure_mode')); ?></label>
                    <select id="edClosureMode" class="ed-select">
                        <option value="warn"><?php echo htmlspecialchars(t('checklists.editor.closure_warn')); ?></option>
                        <option value="block"><?php echo htmlspecialchars(t('checklists.editor.closure_block')); ?></option>
                    </select>
                    <span style="font-size: 11px; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.editor.closure_mode_desc')); ?></span>
                </div>
                <div class="ed-field">
                    <label for="edDesc"><?php echo htmlspecialchars(t('checklists.editor.description')); ?></label>
                    <textarea id="edDesc" class="ed-textarea" placeholder="<?php echo htmlspecialchars(t('checklists.editor.description_ph')); ?>"></textarea>
                </div>
                <div class="ed-field" style="margin-bottom: 0;">
                    <label for="edKeywords"><?php echo htmlspecialchars(t('checklists.editor.keywords')); ?></label>
                    <input type="text" id="edKeywords" class="ed-input" placeholder="<?php echo htmlspecialchars(t('checklists.editor.keywords_ph')); ?>">
                    <span style="font-size: 11px; color: var(--text-muted, #64748b);"><?php echo htmlspecialchars(t('checklists.editor.keywords_help')); ?></span>
                </div>
            </div>

            <div class="ed-card">
                <div style="display: flex; align-items: flex-start; gap: 12px;">
                    <div style="flex: 1;">
                        <h2><?php echo htmlspecialchars(t('checklists.editor.heading_steps')); ?></h2>
                        <p class="ed-sub"><?php echo htmlspecialchars(t('checklists.editor.heading_steps_sub')); ?></p>
                    </div>
                    <button class="btn-teal" id="edAddStep"><?php echo htmlspecialchars(t('checklists.editor.add_step')); ?></button>
                </div>
                <ul class="step-list" id="edSteps"></ul>
                <div class="ed-empty" id="edStepsEmpty"><?php echo htmlspecialchars(t('checklists.editor.steps_empty')); ?></div>
            </div>
        </div>
    </div>

    <script>
        const CHK_ROLES  = <?php echo json_encode(array_values($definedRoles), JSON_UNESCAPED_UNICODE); ?>;
        const TEMPLATE_ID = <?php echo (int)$templateId; ?>;

        let steps = [];          // the single source of truth; the DOM is rendered from it
        let isDirty = false;
        let dragIndex = null;

        function esc(s) {
            return String(s ?? '').replace(/&/g, '&amp;').replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }

        function markDirty() {
            if (isDirty) return;
            isDirty = true;
            document.getElementById('edUnsaved').classList.add('visible');
        }
        function clearDirty() {
            isDirty = false;
            document.getElementById('edUnsaved').classList.remove('visible');
        }
        window.addEventListener('beforeunload', function (e) {
            if (isDirty) { e.preventDefault(); e.returnValue = ''; }
        });

        // ⚠️ Gate dragstart on the HANDLE. Without this the whole row is
        // draggable and a click-drag inside a text input starts a reorder
        // instead of selecting text. Same guard forms/edit uses.
        let dragAllowed = false;
        document.addEventListener('mousedown', function (e) {
            dragAllowed = !!e.target.closest('.step-drag');
        });

        function renderSteps() {
            const list  = document.getElementById('edSteps');
            const empty = document.getElementById('edStepsEmpty');
            empty.hidden = steps.length > 0;

            list.innerHTML = steps.map(function (s, i) {
                let roleOpts = '<option value="">' + esc(t('checklists.editor.role_optional')) + '</option>';
                let matched = false;
                CHK_ROLES.forEach(function (r) {
                    const sel = r.toLowerCase() === String(s.suggested_role || '').trim().toLowerCase();
                    if (sel) matched = true;
                    roleOpts += '<option value="' + esc(r) + '"' + (sel ? ' selected' : '') + '>' + esc(r) + '</option>';
                });
                // A role the list no longer offers still belongs to this step —
                // keep it selectable rather than silently dropping it on save.
                if (s.suggested_role && !matched) {
                    roleOpts += '<option value="' + esc(s.suggested_role) + '" selected>' + esc(s.suggested_role) + ' (not in list)</option>';
                }

                return '' +
                '<li class="step-item" data-index="' + i + '" draggable="true"' +
                '    ondragstart="onStepDragStart(event,' + i + ')"' +
                '    ondragend="onStepDragEnd(event)"' +
                '    ondragover="onStepDragOver(event,' + i + ')"' +
                '    ondrop="onStepDrop(event,' + i + ')">' +
                  '<div class="step-head">' +
                    '<span class="step-drag" title="Drag to reorder">' +
                      '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"></line><line x1="8" y1="12" x2="21" y2="12"></line><line x1="8" y1="18" x2="21" y2="18"></line><line x1="3" y1="6" x2="3.01" y2="6"></line><line x1="3" y1="12" x2="3.01" y2="12"></line><line x1="3" y1="18" x2="3.01" y2="18"></line></svg>' +
                    '</span>' +
                    '<span class="step-num">' + (i + 1) + '</span>' +
                    '<input type="text" class="ed-input step-title" data-f="title" value="' + esc(s.title) + '" placeholder="' + esc(t('checklists.editor.step_title_ph')) + '">' +
                    '<select class="ed-select step-role" data-f="suggested_role">' + roleOpts + '</select>' +
                    '<button type="button" class="step-del" title="Remove step" aria-label="Remove step">' +
                      '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>' +
                    '</button>' +
                  '</div>' +
                  '<div class="step-opts">' +
                    '<span class="step-opt"><label class="toggle-switch"><input type="checkbox" data-f="is_mandatory"' + (s.is_mandatory ? ' checked' : '') + '><span class="toggle-slider"></span></label> ' + esc(t('checklists.editor.mandatory')) + '</span>' +
                    '<span class="step-opt"><label class="toggle-switch"><input type="checkbox" data-f="requires_input"' + (s.requires_input ? ' checked' : '') + '><span class="toggle-slider"></span></label> ' + esc(t('checklists.editor.ask_for_value')) + '</span>' +
                    '<span class="step-ph-wrap"' + (s.requires_input ? '' : ' hidden') + '>' +
                      '<input type="text" class="ed-input step-ph" data-f="input_placeholder" value="' + esc(s.input_placeholder) + '" placeholder="' + esc(t('checklists.editor.value_prompt_ph')) + '">' +
                    '</span>' +
                  '</div>' +
                '</li>';
            }).join('');
        }

        // One delegated listener for the whole list, so a re-render never
        // orphans a handler.
        document.getElementById('edSteps').addEventListener('input', function (e) {
            const li = e.target.closest('.step-item');
            if (!li || !e.target.dataset.f) return;
            steps[+li.dataset.index][e.target.dataset.f] = e.target.value;
            markDirty();
        });
        document.getElementById('edSteps').addEventListener('change', function (e) {
            const li = e.target.closest('.step-item');
            if (!li || !e.target.dataset.f) return;
            const i = +li.dataset.index, f = e.target.dataset.f;
            if (e.target.type === 'checkbox') {
                steps[i][f] = e.target.checked ? 1 : 0;
                if (f === 'requires_input') {
                    const wrap = li.querySelector('.step-ph-wrap');
                    wrap.hidden = !e.target.checked;              // [hidden], not style.display
                    if (e.target.checked) wrap.querySelector('input').focus();
                }
            } else {
                steps[i][f] = e.target.value;
            }
            markDirty();
        });
        document.getElementById('edSteps').addEventListener('click', async function (e) {
            // closest(), not classList — the click lands on the <svg> or <path>.
            if (!e.target.closest('.step-del')) return;
            const li = e.target.closest('.step-item');
            const i = +li.dataset.index;
            const name = (steps[i].title || '').trim();
            const ok = await showConfirm({
                title: t('checklists.editor.remove_step'),
                message: name ? t('checklists.editor.remove_step_named', { name: name })
                              : t('checklists.editor.remove_step_empty'),
                okLabel: t('checklists.editor.remove'), okClass: 'danger'
            });
            if (!ok) return;
            steps.splice(i, 1);
            markDirty(); renderSteps();
            showToast('Step removed', 'success');
        });

        document.getElementById('edAddStep').addEventListener('click', function () {
            steps.push({ title: '', suggested_role: '', is_mandatory: 1, requires_input: 0, input_placeholder: '' });
            markDirty(); renderSteps();
            const inputs = document.querySelectorAll('#edSteps .step-title');
            if (inputs.length) inputs[inputs.length - 1].focus();
        });

        // ── Drag to reorder (forms/edit's implementation) ───────────────────
        function onStepDragStart(e, i) {
            if (!dragAllowed) { e.preventDefault(); return; }
            dragIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'step');
            requestAnimationFrame(function () {
                const el = document.querySelector('.step-item[data-index="' + i + '"]');
                if (el) el.classList.add('dragging');
            });
        }
        function onStepDragEnd() {
            dragIndex = null;
            document.querySelectorAll('.step-item').forEach(function (el) {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }
        function onStepDragOver(e, i) {
            if (dragIndex === null || dragIndex === i) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            document.querySelectorAll('.step-item').forEach(function (el) {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            e.currentTarget.classList.add(e.clientY < midY ? 'drag-over-top' : 'drag-over-bottom');
        }
        function onStepDrop(e, i) {
            e.preventDefault();
            if (dragIndex === null || dragIndex === i) return;
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let target = e.clientY < midY ? i : i + 1;
            if (dragIndex < target) target--;          // the row leaves a gap behind it
            const [moved] = steps.splice(dragIndex, 1);
            steps.splice(target, 0, moved);
            dragIndex = null;
            markDirty(); renderSteps();
        }

        // ── Load and save ───────────────────────────────────────────────────
        async function load() {
            if (!TEMPLATE_ID) { renderSteps(); return; }
            try {
                const r = await fetch('../api.php?action=get&id=' + TEMPLATE_ID);
                const d = await r.json();
                if (!d.success) { showToast(d.error || 'Could not load the template', 'error'); return; }
                const t = d.template;
                document.getElementById('edTitle').textContent = 'Edit template';
                document.getElementById('edName').value     = t.title || '';
                document.getElementById('edCategory').value = t.category || '';
                document.getElementById('edScope').value    = t.scope || 'both';
                document.getElementById('edClosureMode').value = (t.closure_mode === 'block') ? 'block' : 'warn';
                document.getElementById('edDesc').value     = t.description || '';
                document.getElementById('edKeywords').value = t.keywords || '';
                steps = (t.items || []).map(function (it) {
                    return {
                        title: it.title || '',
                        suggested_role: it.suggested_role || '',
                        is_mandatory: Number(it.is_mandatory) ? 1 : 0,
                        requires_input: Number(it.requires_input) ? 1 : 0,
                        input_placeholder: it.input_placeholder || ''
                    };
                });
                renderSteps();
                clearDirty();
            } catch (err) {
                showToast('Could not load the template', 'error');
            }
        }

        document.getElementById('edSave').addEventListener('click', async function () {
            const title = document.getElementById('edName').value.trim();
            if (!title) { showToast('Give the template a title first', 'error'); document.getElementById('edName').focus(); return; }

            // Drop blank steps rather than refusing to save over them — an empty
            // row is somebody who added one and changed their mind.
            const payload = {
                id: TEMPLATE_ID,
                title: title,
                category: document.getElementById('edCategory').value.trim(),
                scope: document.getElementById('edScope').value,
                closure_mode: document.getElementById('edClosureMode').value,
                description: document.getElementById('edDesc').value.trim(),
                keywords: document.getElementById('edKeywords').value.trim(),
                items: steps.filter(function (s) { return (s.title || '').trim() !== ''; })
            };

            this.disabled = true;
            try {
                const r = await fetch('../api.php?action=save', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const d = await r.json();
                if (!d.success) { showToast(d.error || 'Could not save', 'error'); this.disabled = false; return; }
                clearDirty();                       // before navigating, or the guard fires
                showToast('Saved', 'success');
                window.location.href = '<?php echo BASE_URL; ?>checklists/';
            } catch (err) {
                showToast('Could not save', 'error');
                this.disabled = false;
            }
        });

        ['edName', 'edCategory', 'edScope', 'edClosureMode', 'edDesc', 'edKeywords'].forEach(function (id) {
            document.getElementById(id).addEventListener('input', markDirty);
        });

        load();
    </script>
    <script src="../../assets/js/mobile.js?v=78"></script>
</body>
</html>
