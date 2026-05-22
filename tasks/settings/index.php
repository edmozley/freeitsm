<?php
/**
 * Tasks Settings - Manage status / priority / tag lookups and module options
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
I18n::initFromSession();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'tasks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('tasks.title') . ' ' . t('tasks.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../../assets/css/tasks.css?v=10">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        body { overflow: auto; height: auto; display: block; }

        .container { max-width: none; margin: 0; padding: 30px; }

        /* Tasks-purple theme for tabs */
        .tab:hover { color: #9333ea; }
        .tab.active { color: #9333ea; border-bottom-color: #9333ea; }

        .section-header h2 { margin: 0 0 8px; font-size: 18px; color: #333; }
        .section-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }

        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th, .lookup-table td { padding: 10px 8px; text-align: left; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
        .lookup-table th { font-weight: 600; color: #666; background: #fafafa; }
        .badge-yes { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 600; }
        .badge-no { color: #999; }
        .badge-active   { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #f3e8ff; color: #7e22ce; font-size: 11px; font-weight: 600; }
        .badge-inactive { display: inline-block; padding: 2px 8px; border-radius: 10px; background: #fafafa; color: #999;   font-size: 11px; font-weight: 600; }
        .swatch { display: inline-block; width: 18px; height: 18px; border-radius: 3px; vertical-align: middle; border: 1px solid #ddd; margin-right: 6px; }
        .action-btn { background: none; border: none; cursor: pointer; padding: 4px; color: #666; }
        .action-btn:hover { color: #9333ea; }
        .action-btn.delete:hover { color: #c62828; }
        .actions-cell { white-space: nowrap; }
        .actions-cell .action-btn { display: inline-flex; vertical-align: middle; }
        .add-btn { background: #9333ea; color: white; padding: 8px 16px; border: none; border-radius: 4px; font-size: 13px; cursor: pointer; }
        .add-btn:hover { background: #7e22ce; }

        /* Modal */
        .lk-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; }
        .lk-modal.active { display: flex; align-items: center; justify-content: center; }
        .lk-modal-content { background: white; border-radius: 8px; padding: 24px; width: 100%; max-width: 480px; }
        .lk-modal-header { font-size: 18px; font-weight: 600; margin-bottom: 16px; }
        .lk-form-group { margin-bottom: 14px; }
        .lk-form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 4px; }
        .lk-form-group input[type="text"], .lk-form-group input[type="number"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
        .lk-form-group .help { display: block; font-size: 12px; color: #888; margin-top: 4px; }
        .lk-modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }
        .btn { padding: 10px 20px; border-radius: 4px; font-size: 14px; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-primary { background: #9333ea; color: white; }
        .btn-primary:hover { background: #7e22ce; }
        .btn-secondary { background: #e0e0e0; color: #333; }
        .btn-secondary:hover { background: #bdbdbd; }

        /* Toast */
        .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%); background: #333; color: white; padding: 10px 18px; border-radius: 4px; font-size: 14px; opacity: 0; pointer-events: none; transition: opacity 0.3s; z-index: 1100; }
        .toast.show { opacity: 1; }
        .toast.toast-error { background: #c62828; }

        /* Calendar span-mode options */
        .span-mode-options { display: flex; flex-direction: column; gap: 10px; max-width: 640px; }
        .span-mode-card { display: flex; gap: 12px; padding: 14px 16px; border: 1px solid #ddd; border-radius: 8px; cursor: pointer; transition: all 0.15s; }
        .span-mode-card:hover { border-color: #9333ea; }
        .span-mode-card.selected { border-color: #9333ea; background: #faf5ff; }
        .span-mode-card input { margin-top: 2px; accent-color: #9333ea; width: 16px; height: 16px; cursor: pointer; }
        .span-mode-name { font-weight: 600; font-size: 14px; color: #333; margin-bottom: 3px; }
        .span-mode-desc { font-size: 13px; color: #777; line-height: 1.45; }

        /* Card field toggles */
        .card-field-options { display: flex; flex-direction: column; gap: 2px; max-width: 640px; }
        .card-field-row { display: flex; align-items: flex-start; gap: 12px; padding: 11px 14px; border-radius: 8px; cursor: pointer; transition: background 0.12s; }
        .card-field-row:hover { background: #faf5ff; }
        .card-field-row input { margin-top: 1px; accent-color: #9333ea; width: 16px; height: 16px; cursor: pointer; flex-shrink: 0; }
        .card-field-name { font-weight: 600; font-size: 14px; color: #333; }
        .card-field-desc { font-size: 13px; color: #888; margin-top: 2px; line-height: 1.4; }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <div class="tabs">
            <button class="tab active" data-tab="statuses" onclick="switchTab('statuses')"><?php echo htmlspecialchars(t('tasks.settings.tab_statuses')); ?></button>
            <button class="tab" data-tab="priorities" onclick="switchTab('priorities')"><?php echo htmlspecialchars(t('tasks.settings.tab_priorities')); ?></button>
            <button class="tab" data-tab="calendar" onclick="switchTab('calendar')"><?php echo htmlspecialchars(t('tasks.settings.tab_calendar')); ?></button>
            <button class="tab" data-tab="card" onclick="switchTab('card')"><?php echo htmlspecialchars(t('tasks.settings.tab_card')); ?></button>
            <button class="tab" data-tab="tags" onclick="switchTab('tags')"><?php echo htmlspecialchars(t('tasks.settings.tab_tags')); ?></button>
        </div>

        <!-- Statuses Tab -->
        <div class="tab-content active" id="statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tasks.settings.statuses_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('status')"><?php echo htmlspecialchars(t('tasks.settings.add')); ?></button>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo htmlspecialchars(t('tasks.settings.statuses_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_name')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_colour')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_closed')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_default')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_order')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_status')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_actions')); ?></th>
                </tr></thead>
                <tbody id="statuses-list"><tr><td colspan="7" style="text-align:center;"><?php echo htmlspecialchars(t('tasks.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Priorities Tab -->
        <div class="tab-content" id="priorities-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tasks.settings.priorities_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('priority')"><?php echo htmlspecialchars(t('tasks.settings.add')); ?></button>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo htmlspecialchars(t('tasks.settings.priorities_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_name')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_colour')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_default')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_order')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_status')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_actions')); ?></th>
                </tr></thead>
                <tbody id="priorities-list"><tr><td colspan="6" style="text-align:center;"><?php echo htmlspecialchars(t('tasks.settings.loading')); ?></td></tr></tbody>
            </table>
        </div>

        <!-- Calendar Tab -->
        <div class="tab-content" id="calendar-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tasks.settings.calendar_heading')); ?></h2>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo htmlspecialchars(t('tasks.settings.calendar_intro')); ?></p>
            <div class="span-mode-options">
                <label class="span-mode-card">
                    <input type="radio" name="spanMode" value="deadline" onchange="saveSpanMode(this.value)">
                    <div class="span-mode-body">
                        <div class="span-mode-name"><?php echo htmlspecialchars(t('tasks.settings.span_deadline_name')); ?></div>
                        <div class="span-mode-desc"><?php echo htmlspecialchars(t('tasks.settings.span_deadline_desc')); ?></div>
                    </div>
                </label>
                <label class="span-mode-card">
                    <input type="radio" name="spanMode" value="span" onchange="saveSpanMode(this.value)">
                    <div class="span-mode-body">
                        <div class="span-mode-name"><?php echo htmlspecialchars(t('tasks.settings.span_span_name')); ?></div>
                        <div class="span-mode-desc"><?php echo htmlspecialchars(t('tasks.settings.span_span_desc')); ?></div>
                    </div>
                </label>
                <label class="span-mode-card">
                    <input type="radio" name="spanMode" value="repeat" onchange="saveSpanMode(this.value)">
                    <div class="span-mode-body">
                        <div class="span-mode-name"><?php echo htmlspecialchars(t('tasks.settings.span_repeat_name')); ?></div>
                        <div class="span-mode-desc"><?php echo htmlspecialchars(t('tasks.settings.span_repeat_desc')); ?></div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Card Tab -->
        <div class="tab-content" id="card-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tasks.settings.card_heading')); ?></h2>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo htmlspecialchars(t('tasks.settings.card_intro')); ?></p>
            <div class="card-field-options">
                <label class="card-field-row">
                    <input type="checkbox" data-field="priority" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_priority_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_priority_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="assignee" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_assignee_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_assignee_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="team" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_team_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_team_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="start_date" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_start_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_start_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="due_date" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_due_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_due_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="description" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_desc_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_desc_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="subtasks" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_subtasks_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_subtasks_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-field="links" onchange="saveCardFields()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.card_links_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.card_links_desc')); ?></div>
                    </div>
                </label>
            </div>
        </div>

        <!-- Tags Tab -->
        <div class="tab-content" id="tags-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('tasks.settings.tags_heading')); ?></h2>
                <button class="add-btn" onclick="openLookupModal('tag')"><?php echo htmlspecialchars(t('tasks.settings.add')); ?></button>
            </div>
            <p style="color: #666; margin-bottom: 16px;"><?php echo htmlspecialchars(t('tasks.settings.tags_intro')); ?></p>
            <table class="lookup-table">
                <thead><tr>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_name')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_colour')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_order')); ?></th>
                    <th><?php echo htmlspecialchars(t('tasks.settings.col_actions')); ?></th>
                </tr></thead>
                <tbody id="tags-list"><tr><td colspan="4" style="text-align:center;"><?php echo htmlspecialchars(t('tasks.settings.loading')); ?></td></tr></tbody>
            </table>

            <h3 style="margin: 28px 0 4px; font-size: 15px; color: #333;"><?php echo htmlspecialchars(t('tasks.settings.tags_display_heading')); ?></h3>
            <div class="card-field-options">
                <label class="card-field-row">
                    <input type="checkbox" data-tagsetting="allow_create" onchange="saveTagSettings()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.tag_allow_create_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.tag_allow_create_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-tagsetting="surface_card" onchange="saveTagSettings()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_card_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_card_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-tagsetting="surface_filter" onchange="saveTagSettings()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_filter_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_filter_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-tagsetting="surface_search" onchange="saveTagSettings()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_search_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_search_desc')); ?></div>
                    </div>
                </label>
                <label class="card-field-row">
                    <input type="checkbox" data-tagsetting="surface_calendar" onchange="saveTagSettings()">
                    <div>
                        <div class="card-field-name"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_calendar_name')); ?></div>
                        <div class="card-field-desc"><?php echo htmlspecialchars(t('tasks.settings.tag_surface_calendar_desc')); ?></div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- Lookup edit modal -->
    <div class="lk-modal" id="lookupModal">
        <div class="lk-modal-content">
            <div class="lk-modal-header" id="lookupModalTitle"></div>
            <form id="lookupForm">
                <input type="hidden" id="lookupItemKind">
                <input type="hidden" id="lookupItemId">

                <div class="lk-form-group">
                    <label for="lookupItemName"><?php echo htmlspecialchars(t('tasks.settings.modal_name')); ?></label>
                    <input type="text" id="lookupItemName" required>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemColour"><?php echo htmlspecialchars(t('tasks.settings.modal_colour')); ?></label>
                    <input type="color" id="lookupItemColour" value="#9333ea" style="width: 60px; height: 32px; padding: 2px;">
                    <span class="help"><?php echo htmlspecialchars(t('tasks.settings.modal_colour_help')); ?></span>
                </div>

                <div class="lk-form-group" id="lookupItemClosedGroup" style="display: none;">
                    <label><input type="checkbox" id="lookupItemClosed"> <?php echo htmlspecialchars(t('tasks.settings.modal_closed')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('tasks.settings.modal_closed_help')); ?></span>
                </div>

                <div class="lk-form-group" id="lookupItemDefaultGroup">
                    <label><input type="checkbox" id="lookupItemDefault"> <?php echo htmlspecialchars(t('tasks.settings.modal_default')); ?></label>
                    <span class="help"><?php echo htmlspecialchars(t('tasks.settings.modal_default_help')); ?></span>
                </div>

                <div class="lk-form-group">
                    <label for="lookupItemOrder"><?php echo htmlspecialchars(t('tasks.settings.modal_order')); ?></label>
                    <input type="number" id="lookupItemOrder" value="0">
                </div>

                <div class="lk-form-group" id="lookupItemActiveGroup">
                    <label><input type="checkbox" id="lookupItemActive" checked> <?php echo htmlspecialchars(t('tasks.settings.modal_active')); ?></label>
                </div>

                <div class="lk-modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeLookupModal()"><?php echo htmlspecialchars(t('tasks.settings.modal_cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('tasks.settings.modal_save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        const API_BASE = '../../api/tasks/';

        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        const LOOKUP_KINDS = {
            'status':   { get: 'get_task_statuses.php',   save: 'save_task_status.php',   del: 'delete_task_status.php',   listKey: 'statuses',   tableId: 'statuses-list',   colspan: 7, hasClosed: true,  hasDefault: true,  hasActive: true  },
            'priority': { get: 'get_task_priorities.php', save: 'save_task_priority.php', del: 'delete_task_priority.php', listKey: 'priorities', tableId: 'priorities-list', colspan: 6, hasClosed: false, hasDefault: true,  hasActive: true  },
            'tag':      { get: 'get_task_tags.php',       save: 'save_task_tag.php',      del: 'delete_task_tag.php',      listKey: 'tags',       tableId: 'tags-list',      colspan: 4, hasClosed: false, hasDefault: false, hasActive: false }
        };

        const lookupCache = { status: [], priority: [], tag: [] };

        // Localised display name for a lookup kind
        function kindLabel(kind) { return t('tasks.settings.kind_' + kind); }

        document.addEventListener('DOMContentLoaded', () => {
            for (const kind of Object.keys(LOOKUP_KINDS)) loadLookup(kind);
            loadSpanMode();
            loadCardFields();
            loadTagSettings();
            const tabFromHash = location.hash.replace('#', '');
            if (['calendar', 'card', 'tags'].includes(tabFromHash)) switchTab(tabFromHash);
        });

        // ── Tag display settings ──
        const TAG_SETTINGS = ['allow_create', 'surface_card', 'surface_filter',
                              'surface_search', 'surface_calendar'];

        async function loadTagSettings() {
            try {
                const data = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
                const ts = (data.success && data.settings.tag_settings) || {};
                TAG_SETTINGS.forEach(f => {
                    const cb = document.querySelector(`input[data-tagsetting="${f}"]`);
                    if (cb) cb.checked = !!ts[f];
                });
            } catch (e) { console.error(e); }
        }

        async function saveTagSettings() {
            const ts = {};
            TAG_SETTINGS.forEach(f => {
                const cb = document.querySelector(`input[data-tagsetting="${f}"]`);
                ts[f] = (cb && cb.checked) ? 1 : 0;
            });
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ settings: { tag_settings: ts } })
                });
                const data = await res.json();
                if (data.success) showToast(t('tasks.toast.saved'));
                else showToast(data.error || t('tasks.toast.save_failed'), true);
            } catch (e) { showToast(t('tasks.toast.save_failed'), true); }
        }

        // ── Calendar span mode ──
        async function loadSpanMode() {
            try {
                const data = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
                const mode = (data.success && data.settings.calendar_span_mode) || 'deadline';
                const radio = document.querySelector(`input[name="spanMode"][value="${mode}"]`);
                if (radio) radio.checked = true;
            } catch (e) { console.error(e); }
            markSelectedCard();
        }

        async function saveSpanMode(value) {
            markSelectedCard();
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ settings: { calendar_span_mode: value } })
                });
                const data = await res.json();
                if (data.success) showToast(t('tasks.toast.saved'));
                else showToast(data.error || t('tasks.toast.save_failed'), true);
            } catch (e) { showToast(t('tasks.toast.save_failed'), true); }
        }

        function markSelectedCard() {
            document.querySelectorAll('.span-mode-card').forEach(card => {
                card.classList.toggle('selected', card.querySelector('input').checked);
            });
        }

        // ── Card field toggles ──
        const CARD_FIELDS = ['priority', 'assignee', 'team', 'start_date',
                             'due_date', 'description', 'subtasks', 'links'];

        async function loadCardFields() {
            try {
                const data = await fetch(API_BASE + 'get_settings.php').then(r => r.json());
                const cf = (data.success && data.settings.card_fields) || {};
                CARD_FIELDS.forEach(f => {
                    const cb = document.querySelector(`input[data-field="${f}"]`);
                    if (cb) cb.checked = !!cf[f];
                });
            } catch (e) { console.error(e); }
        }

        async function saveCardFields() {
            const cf = {};
            CARD_FIELDS.forEach(f => {
                const cb = document.querySelector(`input[data-field="${f}"]`);
                cf[f] = (cb && cb.checked) ? 1 : 0;
            });
            try {
                const res = await fetch(API_BASE + 'save_settings.php', {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ settings: { card_fields: cf } })
                });
                const data = await res.json();
                if (data.success) showToast(t('tasks.toast.saved'));
                else showToast(data.error || t('tasks.toast.save_failed'), true);
            } catch (e) { showToast(t('tasks.toast.save_failed'), true); }
        }

        async function loadLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.get);
                const data = await res.json();
                if (data.success) {
                    lookupCache[kind] = data[cfg.listKey] || [];
                    renderLookup(kind);
                }
            } catch (e) { console.error(e); }
        }

        function renderLookup(kind) {
            const cfg = LOOKUP_KINDS[kind];
            const rows = lookupCache[kind];
            const tbody = document.getElementById(cfg.tableId);
            if (!rows || rows.length === 0) {
                tbody.innerHTML = `<tr><td colspan="${cfg.colspan}" style="text-align:center;">${t('tasks.settings.no_items')}</td></tr>`;
                return;
            }
            const yes = `<span class="badge-yes">${t('tasks.settings.yes')}</span>`;
            const no  = `<span class="badge-no">${t('tasks.settings.no')}</span>`;
            tbody.innerHTML = rows.map(r => {
                const safeName = escapeHtml(r.name).replace(/'/g, "\\'");
                const swatch = r.colour
                    ? `<span class="swatch" style="background:${escapeHtml(r.colour)};"></span><code style="font-size:12px;">${escapeHtml(r.colour)}</code>`
                    : '<span class="badge-no">—</span>';
                const closedCol = cfg.hasClosed
                    ? `<td>${r.is_closed ? yes : no}</td>`
                    : '';
                const defaultCol = cfg.hasDefault
                    ? `<td>${r.is_default ? yes : no}</td>`
                    : '';
                const activeCol = cfg.hasActive
                    ? `<td><span class="${r.is_active ? 'badge-active' : 'badge-inactive'}">${r.is_active ? t('tasks.settings.active') : t('tasks.settings.inactive')}</span></td>`
                    : '';
                return `
                <tr>
                    <td><strong>${escapeHtml(r.name)}</strong></td>
                    <td>${swatch}</td>
                    ${closedCol}
                    ${defaultCol}
                    <td>${r.display_order}</td>
                    ${activeCol}
                    <td class="actions-cell">
                        <button class="action-btn" onclick="editLookup('${kind}', ${r.id})" title="${t('tasks.settings.edit')}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>
                        </button>
                        <button class="action-btn delete" onclick="deleteLookup('${kind}', ${r.id}, '${safeName}')" title="${t('tasks.settings.delete')}">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                        </button>
                    </td>
                </tr>`;
            }).join('');
        }

        function escapeHtml(text) {
            if (text == null) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function openLookupModal(kind) {
            const cfg = LOOKUP_KINDS[kind];
            document.getElementById('lookupModalTitle').textContent = t('tasks.settings.modal_add', { kind: kindLabel(kind) });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = '';
            document.getElementById('lookupItemName').value = '';
            document.getElementById('lookupItemColour').value = '#9333ea';
            document.getElementById('lookupItemClosed').checked = false;
            document.getElementById('lookupItemDefault').checked = false;
            document.getElementById('lookupItemOrder').value = '0';
            document.getElementById('lookupItemActive').checked = true;
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            document.getElementById('lookupItemDefaultGroup').style.display = cfg.hasDefault ? '' : 'none';
            document.getElementById('lookupItemActiveGroup').style.display = cfg.hasActive ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function editLookup(kind, id) {
            const cfg = LOOKUP_KINDS[kind];
            const item = (lookupCache[kind] || []).find(r => r.id == id);
            if (!item) return;
            document.getElementById('lookupModalTitle').textContent = t('tasks.settings.modal_edit', { kind: kindLabel(kind) });
            document.getElementById('lookupItemKind').value = kind;
            document.getElementById('lookupItemId').value = item.id;
            document.getElementById('lookupItemName').value = item.name;
            document.getElementById('lookupItemColour').value = item.colour || '#9333ea';
            document.getElementById('lookupItemClosed').checked = !!item.is_closed;
            document.getElementById('lookupItemDefault').checked = !!item.is_default;
            document.getElementById('lookupItemOrder').value = item.display_order;
            document.getElementById('lookupItemActive').checked = !!item.is_active;
            document.getElementById('lookupItemClosedGroup').style.display = cfg.hasClosed ? '' : 'none';
            document.getElementById('lookupItemDefaultGroup').style.display = cfg.hasDefault ? '' : 'none';
            document.getElementById('lookupItemActiveGroup').style.display = cfg.hasActive ? '' : 'none';
            document.getElementById('lookupModal').classList.add('active');
        }

        function closeLookupModal() {
            document.getElementById('lookupModal').classList.remove('active');
        }

        async function deleteLookup(kind, id, name) {
            if (!confirm(t('tasks.settings.delete_confirm', { name: name }))) return;
            const cfg = LOOKUP_KINDS[kind];
            try {
                const res = await fetch(API_BASE + cfg.del, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({id: id})
                });
                const data = await res.json();
                if (data.success) { showToast(t('tasks.toast.deleted')); loadLookup(kind); }
                else { showToast(data.error || t('tasks.toast.delete_failed'), true); }
            } catch (e) { showToast(t('tasks.toast.delete_failed'), true); }
        }

        document.getElementById('lookupForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const kind = document.getElementById('lookupItemKind').value;
            const cfg = LOOKUP_KINDS[kind];
            const payload = {
                id: document.getElementById('lookupItemId').value || null,
                name: document.getElementById('lookupItemName').value,
                colour: document.getElementById('lookupItemColour').value,
                is_default: document.getElementById('lookupItemDefault').checked ? 1 : 0,
                display_order: parseInt(document.getElementById('lookupItemOrder').value || '0', 10),
                is_active: document.getElementById('lookupItemActive').checked ? 1 : 0
            };
            if (cfg.hasClosed) payload.is_closed = document.getElementById('lookupItemClosed').checked ? 1 : 0;

            try {
                const res = await fetch(API_BASE + cfg.save, {
                    method: 'POST', headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    closeLookupModal();
                    showToast(t('tasks.toast.saved'));
                    loadLookup(kind);
                } else {
                    showToast(data.error || t('tasks.toast.save_failed'), true);
                }
            } catch (e) { showToast(t('tasks.toast.save_failed'), true); }
        });

        function showToast(message, isError) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.className = 'toast' + (isError ? ' toast-error' : '');
            toast.classList.add('show');
            setTimeout(() => toast.classList.remove('show'), 3000);
        }
    </script>
</body>
</html>
