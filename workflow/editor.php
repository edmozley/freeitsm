<?php
/**
 * Workflows — editor (visual canvas builder).
 *
 * Stage 2 of the workflow module: the form-based editor (Stage 1) is gone;
 * everything happens on a Process Mapper-style dot-grid canvas. A workflow
 * is a graph of nodes (one trigger + N conditions + M actions) that the
 * engine reads as: trigger_event + ordered conditions array + ordered
 * actions array. Order is taken from each node's Y position at save time,
 * top-to-bottom — so the user just drags nodes around to reorder.
 *
 * The engine's contract is unchanged. We store x/y as extra fields on each
 * condition/action JSON object (the engine ignores them). The trigger is
 * always pinned to top-centre and isn't stored.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once __DIR__ . '/includes/engine.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'workflow';
$path_prefix = '../';

$translationNamespaces = ['common', 'workflow'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Catalogues sourced from the engine so the editor never drifts out of sync.
$triggers   = WorkflowEngine::availableTriggers();
$ops        = WorkflowEngine::availableOperators();
$actionDefs = WorkflowEngine::availableActions();
$fieldsByTrigger = [];
foreach (array_keys($triggers) as $t) {
    $fieldsByTrigger[$t] = WorkflowEngine::availableFields($t);
}

// Lookup values per normalised-id field — built once at page load and
// exported to JS. For free-text fields we get null back (the editor falls
// back to a plain text input). Keeps the dropdowns offline-capable without
// extra AJAX round-trips when the user picks a field.
$lookupValuesByField = [];
$fieldTypeByField    = [];
$_seenFields = [];
foreach ($fieldsByTrigger as $fields) {
    foreach ($fields as $field) {
        if (isset($_seenFields[$field])) continue;
        $_seenFields[$field] = true;
        $values = WorkflowEngine::availableValuesForField($field);
        if ($values !== null) {
            $lookupValuesByField[$field] = $values;
        }
        // Each field gets a type — 'lookup', 'numeric', or 'text' — driving
        // which operators the editor offers when that field is picked.
        $fieldTypeByField[$field] = WorkflowEngine::fieldType($field);
    }
}

// Action-arg lookups — same idea as the condition lookups above, but keyed
// by lookup name (the value of an action arg's `lookup` spec). Built once
// here so the editor can populate <select> dropdowns offline.
$actionLookupValues = [];
foreach ($actionDefs as $actionKey => $def) {
    foreach (($def['args'] ?? []) as $argSpec) {
        if (is_array($argSpec) && ($argSpec['type'] ?? '') === 'lookup' && !empty($argSpec['lookup'])) {
            $lk = $argSpec['lookup'];
            if (!isset($actionLookupValues[$lk])) {
                $vals = WorkflowEngine::availableActionLookup($lk);
                $actionLookupValues[$lk] = $vals ?? [];
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($id ? t('workflow.editor.edit_title') : t('workflow.editor.new_title')); ?></title>
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <link rel="stylesheet" href="../assets/css/workflow.css?v=4">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js"></script>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="wf-layout">
        <!-- Toolbar above the canvas -->
        <div class="wf-toolbar">
            <div class="wf-toolbar-left">
                <a class="wf-tool-btn" href="index.php" title="<?php echo htmlspecialchars(t('workflow.editor.back')); ?>">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="15 18 9 12 15 6"/></svg>
                    <span><?php echo htmlspecialchars(t('workflow.editor.back')); ?></span>
                </a>
                <div class="wf-tool-sep"></div>
                <button class="wf-tool-btn" onclick="WFE.addCondition()">
                    <svg width="16" height="16" viewBox="0 0 18 18"><polygon points="9,1 17,9 9,17 1,9" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    <span><?php echo htmlspecialchars(t('workflow.editor.add_condition')); ?></span>
                </button>
                <button class="wf-tool-btn" onclick="WFE.addAction()">
                    <svg width="16" height="16" viewBox="0 0 18 18"><rect x="1" y="3" width="16" height="12" rx="2" fill="none" stroke="currentColor" stroke-width="1.5"/></svg>
                    <span><?php echo htmlspecialchars(t('workflow.editor.add_action')); ?></span>
                </button>
                <div class="wf-tool-sep"></div>
                <button class="wf-tool-btn wf-tool-ai" onclick="WFE.openAiModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2l1.7 4.6L18 8l-4.3 1.4L12 14l-1.7-4.6L6 8l4.3-1.4z"/><path d="M5 16l0.9 2.3L8 19l-2.1 0.7L5 22l-0.9-2.3L2 19l2.1-0.7z"/><path d="M19 14l0.9 2.3L22 17l-2.1 0.7L19 20l-0.9-2.3L16 17l2.1-0.7z"/></svg>
                    <span><?php echo htmlspecialchars(t('workflow.ai.btn')); ?></span>
                </button>
            </div>
            <div class="wf-toolbar-right">
                <span class="wf-status" id="wfStatus" aria-live="polite"></span>
                <button class="wf-tool-btn" id="testFireBtn" onclick="WFE.testFire()" title="<?php echo htmlspecialchars(t('workflow.editor.test_fire_hint')); ?>" disabled>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    <span><?php echo htmlspecialchars(t('workflow.editor.test_fire')); ?></span>
                </button>
                <button class="wf-tool-btn wf-tool-primary" onclick="WFE.save()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <span><?php echo htmlspecialchars(t('common.save')); ?></span>
                </button>
            </div>
        </div>

        <div class="wf-main">
            <!-- Canvas — dot-grid, snap-to-grid, drag nodes, auto-routed connectors -->
            <div class="wf-canvas-wrap">
                <div class="wf-canvas" id="wfCanvas" tabindex="0">
                    <svg class="wf-connectors" id="wfConnectors"></svg>
                </div>
            </div>

            <!-- Detail panel — slides in from the right. Body swaps based on
                 what's selected: workflow (default) / trigger / condition / action. -->
            <aside class="wf-detail" id="wfDetail">
                <div class="wf-detail-header">
                    <h3 id="wfDetailTitle">Workflow</h3>
                </div>

                <!-- Workflow-level (default — shown when nothing is selected) -->
                <div class="wf-detail-body" id="wfBodyWorkflow">
                    <div class="form-group">
                        <label for="wfName"><?php echo htmlspecialchars(t('workflow.editor.name_label')); ?></label>
                        <input type="text" id="wfName" maxlength="255" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.editor.name_placeholder')); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="wfDescription"><?php echo htmlspecialchars(t('workflow.editor.description_label')); ?></label>
                        <textarea id="wfDescription" rows="3" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.editor.description_placeholder')); ?>"></textarea>
                    </div>
                    <div class="form-group">
                        <label style="font-weight: normal;">
                            <input type="checkbox" id="wfActive" checked> <?php echo htmlspecialchars(t('workflow.editor.active_label')); ?>
                        </label>
                    </div>
                    <hr style="margin: 20px 0; border: none; border-top: 1px solid #e0e0e0;">
                    <h4 style="margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; color: #888;">Recent runs</h4>
                    <div id="execList" style="font-size: 13px; color: #666;">
                        <em>Save the workflow first, then test-fire it to see runs here.</em>
                    </div>
                </div>

                <!-- Trigger node -->
                <div class="wf-detail-body" id="wfBodyTrigger" style="display: none;">
                    <div class="form-group">
                        <label for="wfTrigger"><?php echo htmlspecialchars(t('workflow.editor.trigger_label')); ?></label>
                        <input type="text" id="wfTriggerSearch" placeholder="Search triggers…" autocomplete="off"
                               oninput="WFTriggerFilter(this.value)"
                               style="width:100%; box-sizing:border-box; margin-bottom:6px; padding:6px 8px; border:1px solid #ccc; border-radius:6px;">
                        <select id="wfTrigger" size="8" onchange="WFE.updateTriggerFromDetail()" style="width:100%;">
                            <?php foreach ($triggers as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small style="display: block; color: #666; margin-top: 4px;"><?php echo htmlspecialchars(t('workflow.editor.trigger_hint')); ?></small>
                    </div>
                </div>

                <!-- Condition node -->
                <div class="wf-detail-body" id="wfBodyCondition" style="display: none;">
                    <div class="form-group">
                        <label for="wfCondField"><?php echo htmlspecialchars(t('workflow.editor.condition_field')); ?></label>
                        <select id="wfCondField" onchange="WFE.updateConditionFromDetail()"></select>
                    </div>
                    <div class="form-group">
                        <label for="wfCondOp"><?php echo htmlspecialchars(t('workflow.editor.condition_op')); ?></label>
                        <select id="wfCondOp" onchange="WFE.updateConditionFromDetail()">
                            <?php foreach ($ops as $k => $label): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.editor.condition_value')); ?></label>
                        <!-- JS fills this with a dropdown / checkbox list /
                             text input depending on the chosen field + op.
                             For lookup fields like ticket.priority_id we
                             show the real labels from the joined table. -->
                        <div id="wfCondValueHost"></div>
                    </div>
                    <button class="wf-detail-delete" onclick="WFE.deleteSelected()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <?php echo htmlspecialchars(t('workflow.editor.remove')); ?>
                    </button>
                </div>

                <!-- Action node -->
                <div class="wf-detail-body" id="wfBodyAction" style="display: none;">
                    <div class="form-group">
                        <label for="wfActType"><?php echo htmlspecialchars(t('workflow.editor.action_type')); ?></label>
                        <select id="wfActType" onchange="WFE.updateActionTypeFromDetail()">
                            <?php foreach ($actionDefs as $k => $def): ?>
                            <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($def['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small id="wfActDesc" style="display: block; color: #666; margin-top: 4px;"></small>
                    </div>
                    <!-- JS builds a per-arg form here based on
                         WF_ACTION_DEFS[type].args (text / textarea /
                         numeric / bool / lookup). Replaces the old single
                         JSON textarea so users don't need to hand-author
                         JSON to wire an action. -->
                    <div id="wfActArgsHost"></div>
                    <button class="wf-detail-delete" onclick="WFE.deleteSelected()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                        <?php echo htmlspecialchars(t('workflow.editor.remove')); ?>
                    </button>
                </div>
            </aside>
        </div>
    </div>

    <!-- AI co-author modal — opened from the toolbar button -->
    <div class="modal" id="wfAiModal">
        <div class="modal-content wf-ai-modal">
            <div class="modal-header">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span class="wf-ai-spark" aria-hidden="true">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="#f59e0b" stroke="none"><path d="M12 2l1.7 4.6L18 8l-4.3 1.4L12 14l-1.7-4.6L6 8l4.3-1.4z"/></svg>
                    </span>
                    <span><?php echo htmlspecialchars(t('workflow.ai.modal_title')); ?></span>
                </div>
            </div>
            <div style="padding: 22px 26px; overflow-y: auto;">
                <p style="margin: 0 0 16px; color: #555; line-height: 1.55;"><?php echo htmlspecialchars(t('workflow.ai.intro')); ?></p>

                <div class="form-group">
                    <label for="wfAiPrompt"><?php echo htmlspecialchars(t('workflow.ai.prompt_label')); ?></label>
                    <textarea id="wfAiPrompt" rows="4" autocomplete="off" placeholder="<?php echo htmlspecialchars(t('workflow.ai.prompt_placeholder')); ?>" style="width:100%; padding:10px 12px; border:1px solid #ddd; border-radius:5px; font-family: inherit; font-size: 14px; resize: vertical; box-sizing: border-box;"></textarea>
                    <small id="wfAiIterateHint" style="display: none; color: #6b7280; margin-top: 6px;"><?php echo htmlspecialchars(t('workflow.ai.iterate_hint')); ?></small>
                </div>

                <p style="font-size: 12px; color: #92400e; background: #fef3c7; padding: 8px 12px; border-radius: 4px; margin: 0 0 16px;">
                    <?php echo htmlspecialchars(t('workflow.ai.only_log_message')); ?>
                </p>

                <!-- Result region — populated after a successful Generate. -->
                <div id="wfAiResult" style="display: none;">
                    <hr style="margin: 14px 0; border: none; border-top: 1px solid #e0e0e0;">

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.ai.explanation_label')); ?></label>
                        <div id="wfAiExplanation" style="padding: 12px 14px; background: #f8fafc; border-left: 3px solid #f59e0b; border-radius: 4px; font-size: 13px; line-height: 1.5; color: #374151;"></div>
                    </div>

                    <div class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.ai.preview_label')); ?></label>
                        <div id="wfAiPreview" style="font-size: 13px; color: #374151;"></div>
                    </div>

                    <div id="wfAiWarnings" style="display: none;" class="form-group">
                        <label><?php echo htmlspecialchars(t('workflow.ai.warnings_label')); ?></label>
                        <ul id="wfAiWarningsList" style="font-size: 12px; color: #92400e; background: #fef9c3; padding: 8px 12px 8px 28px; border-radius: 4px; margin: 0;"></ul>
                    </div>
                </div>
            </div>
            <div class="modal-actions" style="padding: 14px 26px; border-top: 1px solid #eee;">
                <button type="button" class="btn btn-secondary" onclick="WFE.closeAiModal()"><?php echo htmlspecialchars(t('workflow.ai.close')); ?></button>
                <button type="button" class="btn btn-secondary" id="wfAiDiscardBtn" style="display: none;" onclick="WFE.aiDiscard()"><?php echo htmlspecialchars(t('workflow.ai.discard')); ?></button>
                <button type="button" class="btn btn-primary wf-ai-primary" id="wfAiGenerateBtn" onclick="WFE.aiGenerate()"><?php echo htmlspecialchars(t('workflow.ai.generate')); ?></button>
                <button type="button" class="btn btn-primary wf-ai-primary" id="wfAiApplyBtn" style="display: none;" onclick="WFE.aiApply()"><?php echo htmlspecialchars(t('workflow.ai.apply')); ?></button>
            </div>
        </div>
    </div>

    <script>
        // Catalogues from the engine, exported for the editor.
        window.WF_TRIGGERS       = <?php echo json_encode($triggers); ?>;
        window.WF_OPS            = <?php echo json_encode($ops); ?>;
        window.WF_ACTION_DEFS    = <?php echo json_encode($actionDefs); ?>;
        window.WF_FIELDS_BY_TRIG = <?php echo json_encode($fieldsByTrigger); ?>;
        // Normalised id fields → list of {id, label} pairs from their lookup
        // table. Drives the condition value control's dropdown / multi-select.
        // Fields not in this map are free-text and use a plain input.
        window.WF_LOOKUP_VALUES  = <?php echo json_encode($lookupValuesByField); ?>;
        // Field path → type ('lookup' / 'numeric' / 'text'). Drives which
        // operators the editor offers for each field — text fields don't
        // get gt/lt, numeric fields don't get contains/not_contains.
        window.WF_FIELD_TYPES    = <?php echo json_encode($fieldTypeByField); ?>;
        // Per-named-lookup {id, label} pairs for action-arg dropdowns
        // (status, priority, analyst, department, etc.). Mirrors
        // WF_LOOKUP_VALUES but keyed by lookup name rather than field path.
        window.WF_ACTION_LOOKUPS = <?php echo json_encode($actionLookupValues); ?>;
        window.WF_ID             = <?php echo (int)$id; ?>;
        window.WF_API            = '../api/workflow/';
        // Full trigger catalogue {event: label} — drives the searchable picker.
        window.WF_TRIGGERS       = <?php echo json_encode($triggers); ?>;
        // Filter the trigger <select> down to matches as the user types. Keeps the
        // currently-selected option present so its value is never lost, and only
        // fires onchange when the selection actually changes.
        window.WFTriggerFilter = function (query) {
            var sel = document.getElementById('wfTrigger');
            if (!sel) return;
            var current = sel.value;
            var q = (query || '').trim().toLowerCase();
            sel.innerHTML = '';
            Object.keys(window.WF_TRIGGERS).forEach(function (key) {
                var label = window.WF_TRIGGERS[key];
                if (q && key.toLowerCase().indexOf(q) === -1 && String(label).toLowerCase().indexOf(q) === -1 && key !== current) {
                    return;
                }
                var opt = document.createElement('option');
                opt.value = key;
                opt.textContent = label;
                if (key === current) opt.selected = true;
                sel.appendChild(opt);
            });
        };
    </script>
    <script src="../assets/js/workflow-editor.js?v=13"></script>
</body>
</html>
