<?php
/**
 * Morning Checks Settings Page
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
require_once '../../includes/timezone.php';
I18n::initFromSession();
Tz::init();

if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'morning-checks'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('morning-checks.title') . ' ' . t('morning-checks.nav.settings')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=13">
    <link rel="stylesheet" href="../../assets/css/inbox.css">
    <link rel="stylesheet" href="../style.css?v=2">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../../assets/js/tz.js?v=1"></script>
    <script src="../../assets/js/i18n.js"></script>
    <style>
        body { padding-top: 0; }
        /* Full-width settings page, matching the canonical padding used by
           the other modules' settings pages. */
        .settings-container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        /* Module-accent (cyan) tabs */
        .tab:hover { color: var(--accent, #00acc1); }
        .tab.active { color: var(--accent, #00acc1); border-bottom-color: var(--accent, #00acc1); }

        /* Section header with Add button. The Chart tab's header has no Add
           button, so without a reserved height its heading (and everything
           below it) sat higher than on the Checks/Statuses tabs — a visible
           jump when switching tabs. min-height pins the header to the
           button-bearing height on every tab so the layout doesn't shift.
           (This module's body line-height:1.6 inflates the button, hence 42px
           rather than the 34px other settings pages use.) */
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            min-height: 42px;
        }
        .section-header h2 { margin: 0; font-size: 18px; color: var(--text, #2c3e50); }

        /* Check items list. Rendered as a flat list with thin separators
           rather than per-row cards — the outer .tab-content already
           provides the white-card surface so per-row cards were doubling
           up the visual nesting and wasting vertical space. */
        .checks-list {
            margin-top: 0;
            border-top: 1px solid var(--border-soft, #f0f0f0);
        }
        .check-item {
            padding: 10px 8px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            transition: background 0.15s;
        }
        .check-item:hover { background: var(--surface-hover, #fafafa); }

        /* Grip handle */
        .check-drag {
            cursor: grab;
            color: var(--text-faint, #bbb);
            padding: 4px;
            touch-action: none;
            flex-shrink: 0;
            display: flex;
            align-items: center;
        }
        .check-drag:active { cursor: grabbing; }
        .check-drag:hover { color: var(--text-dim, #888); }

        /* Check info */
        .check-info { flex: 1; min-width: 0; }
        .check-info strong { display: block; color: var(--text, #333); font-size: 14px; margin-bottom: 2px; }
        .check-description {
            display: block;
            font-size: 12px;
            color: var(--text-dim, #888);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Drag-and-drop states. Accent 2px line above / below the drop
           target indicates where the row will land. */
        .check-item.dragging { opacity: 0.4; }
        .check-item.drag-over-top { box-shadow: inset 0 2px 0 0 var(--accent, #00acc1); }
        .check-item.drag-over-bottom { box-shadow: inset 0 -2px 0 0 var(--accent, #00acc1); }

        /* Statuses tab — table styling matches the canonical lookup-table
           used in change-management / calendar settings. */
        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th,
        .lookup-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            font-size: 14px;
        }
        .lookup-table th { font-weight: 600; color: var(--text-muted, #666); background: var(--surface-2, #fafafa); }
        .lookup-table td:last-child,
        .lookup-table th:last-child { white-space: nowrap; width: 1%; }
        .status-swatch {
            display: inline-block;
            width: 18px; height: 18px;
            border-radius: 3px;
            border: 1px solid var(--border, #ddd);
            vertical-align: middle;
            margin-right: 6px;
        }
        /* Active/Inactive uses the shared .status-badge / .status-active
           / .status-inactive classes from inbox.css (canonical green/red). */
        .badge-yes { color: var(--accent, #1565c0); font-weight: 600; }
        .badge-no  { color: var(--text-faint, #999); }

        /* Check actions — icon buttons (pencil + trash). Overrides
           inbox.css's chunky .action-btn default for this page so the
           buttons sit tight at the end of each row. */
        .check-actions { display: flex; gap: 4px; flex-shrink: 0; }
        .action-btn {
            background: none;
            border: none;
            padding: 4px;
            color: var(--text-muted, #666);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
            gap: 0;
            cursor: pointer;
        }
        .action-btn:hover { background: none; border: none; color: var(--accent, #00acc1); }
        .action-btn.delete:hover { color: var(--danger-accent, #c62828); }
        .action-btn svg { width: 16px; height: 16px; }

        /* Empty / loading states */
        .checks-empty {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-faint, #999);
            font-size: 14px;
        }

        /* Module accent (cyan) — drives tabs, toggles, focus rings, shared
           .btn primaries. Toggle switch base styles live in inbox.css. */
        body { --accent: var(--mc-accent, #00acc1); --accent-hover: var(--mc-accent-hover, #00838f); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="settings-container">
        <div class="tabs">
            <button class="tab active" data-tab="checks" onclick="switchTab('checks')"><?php echo htmlspecialchars(t('morning-checks.settings.tab_checks')); ?></button>
            <button class="tab" data-tab="statuses" onclick="switchTab('statuses')"><?php echo htmlspecialchars(t('morning-checks.settings.tab_statuses')); ?></button>
            <button class="tab" data-tab="chart" onclick="switchTab('chart')"><?php echo htmlspecialchars(t('morning-checks.settings.tab_chart')); ?></button>
        </div>

        <div class="tab-content active" id="checks-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('morning-checks.settings.checks_heading')); ?></h2>
                <button class="add-btn" onclick="openAddModal()"><?php echo htmlspecialchars(t('morning-checks.settings.add')); ?></button>
            </div>
            <div class="checks-list" id="checksList">
                <div class="checks-empty"><?php echo htmlspecialchars(t('morning-checks.settings.checks_loading')); ?></div>
            </div>
        </div>

        <!-- Statuses tab: manage the available status options for the
             dashboard buttons. Each status carries a label, colour, and
             a RequiresNotes flag (controls whether picking it pops the
             notes modal on the dashboard). -->
        <div class="tab-content" id="statuses-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('morning-checks.settings.statuses_heading')); ?></h2>
                <button class="add-btn" onclick="openAddStatusModal()"><?php echo htmlspecialchars(t('morning-checks.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo t('morning-checks.settings.statuses_intro_html'); ?></p>
            <table class="lookup-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('morning-checks.settings.col_label')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.settings.col_colour')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.settings.col_requires_notes')); ?></th>
                        <th><?php echo htmlspecialchars(t('morning-checks.settings.col_status')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="statusesTableBody">
                    <tr><td colspan="5" style="padding: 24px; text-align: center; color: var(--text-faint, #999);"><?php echo htmlspecialchars(t('morning-checks.settings.statuses_loading')); ?></td></tr>
                </tbody>
            </table>

            <!-- Normalisation tool — only rendered when there are
                 results in the DB whose Status string doesn't resolve
                 to a current StatusID (e.g. left over from a deleted
                 status). Hidden when no orphans exist. -->
            <div id="orphanSection" style="display: none; margin-top: 32px; padding: 16px; background: var(--warning-bg, #fff8e1); border: 1px solid var(--warning-border, #ffd54f); border-radius: 6px;">
                <h3 style="margin: 0 0 6px; font-size: 16px; color: var(--warning-text, #663d00);">⚠ <?php echo htmlspecialchars(t('morning-checks.settings.orphan_heading')); ?></h3>
                <p style="font-size: 13px; color: var(--warning-text, #5d4a00); margin-bottom: 14px;">
                    <?php echo t('morning-checks.settings.orphan_intro_html'); ?>
                </p>
                <table class="lookup-table" id="orphanTable">
                    <thead>
                        <tr>
                            <th><?php echo htmlspecialchars(t('morning-checks.settings.orphan_col_label')); ?></th>
                            <th><?php echo htmlspecialchars(t('morning-checks.settings.orphan_col_rows')); ?></th>
                            <th><?php echo htmlspecialchars(t('morning-checks.settings.orphan_col_map')); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="orphanTableBody"></tbody>
                </table>
                <div style="margin-top: 14px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" class="btn btn-primary" id="mapAllBtn" onclick="normaliseAllOrphans()"><?php echo htmlspecialchars(t('morning-checks.settings.orphan_map_all')); ?></button>
                </div>
            </div>
        </div>

        <!-- Chart tab: visual options for the dashboard trend chart.
             Saved per-analyst via the generic user-preference API so
             different analysts can choose different looks. -->
        <div class="tab-content" id="chart-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('morning-checks.settings.chart_heading')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('morning-checks.settings.chart_intro')); ?></p>

            <div class="form-group">
                <label style="display: block; font-weight: 500; margin-bottom: 8px; color: var(--text, #333); font-size: 13px;"><?php echo htmlspecialchars(t('morning-checks.settings.chart_bar_fill')); ?></label>
                <div style="display: flex; gap: 24px; margin-top: 4px;">
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: var(--text, #333);">
                        <input type="radio" name="chartFill" value="plain" id="chartFillPlain">
                        <?php echo htmlspecialchars(t('morning-checks.settings.chart_plain')); ?>
                    </label>
                    <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 14px; color: var(--text, #333);">
                        <input type="radio" name="chartFill" value="gradient" id="chartFillGradient">
                        <?php echo htmlspecialchars(t('morning-checks.settings.chart_gradient')); ?>
                    </label>
                </div>
                <p style="font-size: 12px; color: var(--text-dim, #888); margin-top: 8px;"><?php echo htmlspecialchars(t('morning-checks.settings.chart_fill_help')); ?></p>
            </div>
        </div>
    </div>

    <!-- Add Modal -->
    <div id="addModal" class="modal">
        <div class="modal-content">
            <h2><?php echo htmlspecialchars(t('morning-checks.settings.modal_add_check')); ?></h2>
            <form id="addCheckForm">
                <div class="form-group">
                    <label for="addCheckName"><?php echo htmlspecialchars(t('morning-checks.settings.modal_check_name')); ?></label>
                    <input type="text" id="addCheckName" required>
                </div>
                <div class="form-group">
                    <label for="addCheckDescription"><?php echo htmlspecialchars(t('morning-checks.settings.modal_description')); ?></label>
                    <textarea id="addCheckDescription" rows="3"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeAddModal()"><?php echo htmlspecialchars(t('morning-checks.settings.modal_cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('morning-checks.settings.modal_add')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <div id="editModal" class="modal">
        <div class="modal-content">
            <h2><?php echo htmlspecialchars(t('morning-checks.settings.modal_edit_check')); ?></h2>
            <form id="editCheckForm">
                <input type="hidden" id="editCheckId">
                <div class="form-group">
                    <label for="editCheckName"><?php echo htmlspecialchars(t('morning-checks.settings.modal_check_name')); ?></label>
                    <input type="text" id="editCheckName" required>
                </div>
                <div class="form-group">
                    <label for="editCheckDescription"><?php echo htmlspecialchars(t('morning-checks.settings.modal_description')); ?></label>
                    <textarea id="editCheckDescription" rows="3"></textarea>
                </div>
                <div class="form-group">
                    <label class="toggle-group">
                        <span class="toggle-switch">
                            <input type="checkbox" id="editIsActive">
                            <span class="toggle-slider"></span>
                        </span>
                        <span class="toggle-label"><?php echo htmlspecialchars(t('morning-checks.settings.modal_active')); ?></span>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()"><?php echo htmlspecialchars(t('morning-checks.settings.modal_cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('morning-checks.settings.modal_save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- Status Add/Edit Modal -->
    <div id="statusModal" class="modal">
        <div class="modal-content">
            <h2 id="statusModalTitle"><?php echo htmlspecialchars(t('morning-checks.settings.modal_add_status')); ?></h2>
            <form id="statusForm" autocomplete="off">
                <input type="hidden" id="statusId">
                <div class="form-group">
                    <label for="statusLabel"><?php echo htmlspecialchars(t('morning-checks.settings.modal_label')); ?></label>
                    <input type="text" id="statusLabel" required maxlength="50" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="statusColour"><?php echo htmlspecialchars(t('morning-checks.settings.modal_colour')); ?></label>
                    <input type="color" id="statusColour" value="#28a745" style="width: 60px; height: 40px; padding: 2px; cursor: pointer;">
                </div>
                <div class="form-group">
                    <label class="toggle-group">
                        <span class="toggle-switch">
                            <input type="checkbox" id="statusRequiresNotes">
                            <span class="toggle-slider"></span>
                        </span>
                        <span class="toggle-label"><?php echo htmlspecialchars(t('morning-checks.settings.modal_requires_notes')); ?></span>
                    </label>
                </div>
                <div class="form-group">
                    <label class="toggle-group">
                        <span class="toggle-switch">
                            <input type="checkbox" id="statusIsActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <span class="toggle-label"><?php echo htmlspecialchars(t('morning-checks.settings.modal_active')); ?></span>
                    </label>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeStatusModal()"><?php echo htmlspecialchars(t('morning-checks.settings.modal_cancel')); ?></button>
                    <button type="submit" class="btn btn-primary"><?php echo htmlspecialchars(t('morning-checks.settings.modal_save')); ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/morning-checks/';
        let checks = [];
        let checkDragAllowed = false;
        let dragIndex = null;

        // Track mousedown for drag handle detection
        document.addEventListener('mousedown', function(e) {
            checkDragAllowed = !!e.target.closest('.check-drag');
        });

        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(tab + '-tab').classList.add('active');
        }

        // Load checks
        async function loadChecks() {
            try {
                const response = await fetch(API_BASE + 'get_all_checks.php');
                checks = await response.json();
                if (checks.error) {
                    document.getElementById('checksList').innerHTML =
                        '<div class="checks-empty" style="color:#dc3545;">' + escapeHtml(window.t('morning-checks.settings.checks_error', { message: checks.error })) + '</div>';
                    return;
                }
                renderChecks();
            } catch (error) {
                document.getElementById('checksList').innerHTML =
                    '<div class="checks-empty" style="color:#dc3545;">' + escapeHtml(window.t('morning-checks.settings.checks_error_loading', { message: error.message })) + '</div>';
            }
        }

        // SVG icons for the row action buttons. Same pencil + trash glyphs
        // used in change-management and calendar settings — centralised here
        // so future icon tweaks (size, stroke) touch one spot.
        const ICON_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        // Render checks list
        function renderChecks() {
            const container = document.getElementById('checksList');

            if (checks.length === 0) {
                container.innerHTML = '<div class="checks-empty">' + escapeHtml(window.t('morning-checks.settings.checks_empty')) + '</div>';
                return;
            }

            container.innerHTML = checks.map((check, i) => `
                <div class="check-item"
                     data-id="${check.CheckID}"
                     data-index="${i}"
                     draggable="true"
                     ondragstart="onDragStart(event, ${i})"
                     ondragend="onDragEnd(event)"
                     ondragover="onDragOver(event, ${i})"
                     ondrop="onDrop(event, ${i})">
                    <span class="check-drag" title="${escapeHtmlAttr(window.t('morning-checks.settings.drag_to_reorder'))}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <circle cx="8" cy="4" r="2"/><circle cx="16" cy="4" r="2"/>
                            <circle cx="8" cy="12" r="2"/><circle cx="16" cy="12" r="2"/>
                            <circle cx="8" cy="20" r="2"/><circle cx="16" cy="20" r="2"/>
                        </svg>
                    </span>
                    <div class="check-info">
                        <strong>${escapeHtml(check.CheckName)}</strong>
                        ${check.CheckDescription ? '<span class="check-description">' + escapeHtml(check.CheckDescription) + '</span>' : ''}
                    </div>
                    <span class="status-badge status-${check.IsActive ? 'active' : 'inactive'}">
                        ${check.IsActive ? escapeHtml(window.t('morning-checks.settings.check_active')) : escapeHtml(window.t('morning-checks.settings.check_inactive'))}
                    </span>
                    <div class="check-actions">
                        <button class="action-btn" onclick="openEditModal(${check.CheckID})" title="${escapeHtmlAttr(window.t('morning-checks.settings.edit'))}">${ICON_EDIT}</button>
                        <button class="action-btn delete" onclick="deleteCheck(${check.CheckID}, '${escapeHtml(check.CheckName).replace(/'/g, "\\'")}')" title="${escapeHtmlAttr(window.t('morning-checks.settings.delete'))}">${ICON_DELETE}</button>
                    </div>
                </div>
            `).join('');
        }

        // --- Drag-and-drop ---

        function onDragStart(e, i) {
            if (!checkDragAllowed) {
                e.preventDefault();
                return;
            }
            dragIndex = i;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'check');
            requestAnimationFrame(() => {
                const item = document.querySelector('.check-item[data-index="' + i + '"]');
                if (item) item.classList.add('dragging');
            });
        }

        function onDragEnd(e) {
            dragIndex = null;
            document.querySelectorAll('.check-item').forEach(el => {
                el.classList.remove('dragging', 'drag-over-top', 'drag-over-bottom');
            });
        }

        function onDragOver(e, i) {
            if (dragIndex === null || dragIndex === i) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            document.querySelectorAll('.check-item').forEach(el => {
                el.classList.remove('drag-over-top', 'drag-over-bottom');
            });
            if (e.clientY < midY) {
                e.currentTarget.classList.add('drag-over-top');
            } else {
                e.currentTarget.classList.add('drag-over-bottom');
            }
        }

        function onDrop(e, i) {
            e.preventDefault();
            if (dragIndex === null || dragIndex === i) return;

            const rect = e.currentTarget.getBoundingClientRect();
            const midY = rect.top + rect.height / 2;
            let targetIndex = e.clientY < midY ? i : i + 1;
            if (dragIndex < targetIndex) targetIndex--;

            const [moved] = checks.splice(dragIndex, 1);
            checks.splice(targetIndex, 0, moved);

            dragIndex = null;
            renderChecks();
            saveOrder();
        }

        async function saveOrder() {
            const order = checks.map(c => c.CheckID);
            try {
                const response = await fetch(API_BASE + 'reorder_checks.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ order: order })
                });
                const data = await response.json();
                if (!data.success) {
                    showToast(window.t('morning-checks.settings.toast_order_error', { message: data.error || window.t('morning-checks.settings.toast_unknown_error') }), 'error');
                }
            } catch (error) {
                showToast(window.t('morning-checks.settings.toast_order_error', { message: error.message }), 'error');
            }
        }

        // --- Modals ---

        function openAddModal() {
            document.getElementById('addCheckForm').reset();
            document.getElementById('addModal').classList.add('active');
            document.getElementById('addCheckName').focus();
        }

        function closeAddModal() {
            document.getElementById('addModal').classList.remove('active');
        }

        function openEditModal(checkId) {
            const check = checks.find(c => c.CheckID === checkId);
            if (!check) return;

            document.getElementById('editCheckId').value = check.CheckID;
            document.getElementById('editCheckName').value = check.CheckName;
            document.getElementById('editCheckDescription').value = check.CheckDescription || '';
            document.getElementById('editIsActive').checked = check.IsActive;
            document.getElementById('editModal').classList.add('active');
            document.getElementById('editCheckName').focus();
        }

        function closeEditModal() {
            document.getElementById('editModal').classList.remove('active');
        }

        // Close modals on outside click
        window.addEventListener('click', function(event) {
            if (event.target.id === 'addModal') closeAddModal();
            if (event.target.id === 'editModal') closeEditModal();
        });

        // --- Form submissions ---

        document.getElementById('addCheckForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const formData = {
                checkName: document.getElementById('addCheckName').value.trim(),
                checkDescription: document.getElementById('addCheckDescription').value.trim(),
                sortOrder: checks.length
            };

            try {
                const response = await fetch(API_BASE + 'add_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('morning-checks.settings.toast_check_added'), 'success');
                    closeAddModal();
                    loadChecks();
                } else {
                    showToast(window.t('morning-checks.settings.toast_add_error', { message: data.error || window.t('morning-checks.settings.toast_unknown_error') }), 'error');
                }
            } catch (error) {
                showToast(window.t('morning-checks.settings.toast_add_check_error', { message: error.message }), 'error');
            }
        });

        document.getElementById('editCheckForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const checkId = parseInt(document.getElementById('editCheckId').value);
            const check = checks.find(c => c.CheckID === checkId);

            const formData = {
                checkId: checkId,
                checkName: document.getElementById('editCheckName').value.trim(),
                checkDescription: document.getElementById('editCheckDescription').value.trim(),
                sortOrder: check ? check.SortOrder : 0,
                isActive: document.getElementById('editIsActive').checked
            };

            try {
                const response = await fetch(API_BASE + 'update_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('morning-checks.settings.toast_check_updated'), 'success');
                    closeEditModal();
                    loadChecks();
                } else {
                    showToast(window.t('morning-checks.settings.toast_add_error', { message: data.error || window.t('morning-checks.settings.toast_unknown_error') }), 'error');
                }
            } catch (error) {
                showToast(window.t('morning-checks.settings.toast_update_check_error', { message: error.message }), 'error');
            }
        });

        // --- Delete ---

        async function deleteCheck(checkId, checkName) {
            if (!(await showConfirm({ title: window.t('morning-checks.settings.confirm_delete_title'), message: window.t('morning-checks.settings.confirm_delete_check', { name: checkName }), okLabel: window.t('morning-checks.settings.confirm_ok'), okClass: 'danger' }))) return;

            try {
                const response = await fetch(API_BASE + 'delete_check.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ checkId: checkId })
                });
                const data = await response.json();

                if (data.success) {
                    showToast(window.t('morning-checks.settings.toast_check_deleted'), 'success');
                    loadChecks();
                } else {
                    showToast(window.t('morning-checks.settings.toast_add_error', { message: data.error || window.t('morning-checks.settings.toast_unknown_error') }), 'error');
                }
            } catch (error) {
                showToast(window.t('morning-checks.settings.toast_delete_check_error', { message: error.message }), 'error');
            }
        }

        // --- Utilities ---

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ===== Statuses tab =====
        // Manages the morningChecks_Statuses table — label / colour /
        // requires-notes flag / active toggle / sort order.

        const ICON_EDIT_S = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE_S = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        let statuses = [];

        async function loadStatuses() {
            try {
                const res = await fetch(API_BASE + 'get_statuses.php');
                const data = await res.json();
                if (data.success) {
                    statuses = data.statuses || [];
                    renderStatuses();
                    // Statuses now loaded — orphan tool can render its
                    // dropdowns against them.
                    loadOrphans();
                } else {
                    document.getElementById('statusesTableBody').innerHTML =
                        '<tr><td colspan="5" style="padding: 24px; text-align: center; color: #c62828;">' + escapeHtml(window.t('morning-checks.settings.statuses_error')) + '</td></tr>';
                }
            } catch (e) {
                document.getElementById('statusesTableBody').innerHTML =
                    '<tr><td colspan="5" style="padding: 24px; text-align: center; color: #c62828;">' + escapeHtml(window.t('morning-checks.settings.statuses_error')) + '</td></tr>';
            }
        }

        // ===== Orphan normalisation tool =====
        // Only shown when there's results data that doesn't join with
        // a current StatusID. Each orphan label gets a row with a
        // dropdown of active statuses + a Map button.

        let orphanLabels = [];

        async function loadOrphans() {
            try {
                const res = await fetch(API_BASE + 'get_status_orphans.php');
                const data = await res.json();
                if (data && data.success && data.totalOrphans > 0) {
                    orphanLabels = data.labels;
                    renderOrphans();
                    document.getElementById('orphanSection').style.display = '';
                } else {
                    orphanLabels = [];
                    document.getElementById('orphanSection').style.display = 'none';
                }
            } catch (e) {
                // Soft-fail; section stays hidden
            }
        }

        function renderOrphans() {
            const activeStatuses = statuses.filter(s => s.IsActive);
            const tbody = document.getElementById('orphanTableBody');

            if (orphanLabels.length === 0) {
                tbody.innerHTML = '';
                return;
            }

            tbody.innerHTML = orphanLabels.map((row, idx) => {
                const options = activeStatuses.map(s =>
                    '<option value="' + s.StatusID + '">' + escapeHtml(s.Label) + '</option>'
                ).join('');
                return `
                    <tr data-label="${escapeHtmlAttr(row.label)}">
                        <td><code style="background: var(--surface-2, #f5f5f5); padding: 2px 6px; border-radius: 3px; font-size: 12px;">${escapeHtml(row.label)}</code></td>
                        <td>${row.count}</td>
                        <td>
                            <select class="orphan-target" data-idx="${idx}" style="padding: 6px 10px; border: 1px solid var(--border, #ddd); border-radius: 4px; font-size: 13px;">
                                ${options}
                            </select>
                        </td>
                        <td>
                            <button type="button" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;" onclick="normaliseOneOrphan(${idx})">${escapeHtml(window.t('morning-checks.settings.orphan_map'))}</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }

        async function normaliseOneOrphan(idx) {
            const orphan = orphanLabels[idx];
            if (!orphan) return;
            const sel = document.querySelector('.orphan-target[data-idx="' + idx + '"]');
            const targetId = sel ? parseInt(sel.value, 10) : 0;
            if (!targetId) return;
            await postNormalise([{ label: orphan.label, statusId: targetId }]);
        }

        async function normaliseAllOrphans() {
            const mappings = [];
            document.querySelectorAll('.orphan-target').forEach(sel => {
                const idx = parseInt(sel.dataset.idx, 10);
                const orphan = orphanLabels[idx];
                const targetId = parseInt(sel.value, 10);
                if (orphan && targetId) mappings.push({ label: orphan.label, statusId: targetId });
            });
            if (mappings.length === 0) return;
            await postNormalise(mappings);
        }

        async function postNormalise(mappings) {
            try {
                const res = await fetch(API_BASE + 'normalise_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mappings: mappings })
                });
                const data = await res.json();
                if (data && data.success) {
                    showToast(window.t(data.updated === 1 ? 'morning-checks.settings.orphan_mapped_one' : 'morning-checks.settings.orphan_mapped_other', { n: data.updated }), 'success');
                    loadOrphans();
                } else {
                    showToast((data && data.error) || window.t('morning-checks.settings.orphan_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('morning-checks.settings.orphan_failed'), 'error');
            }
        }

        function renderStatuses() {
            const tbody = document.getElementById('statusesTableBody');
            if (statuses.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" style="padding: 24px; text-align: center; color: var(--text-faint, #999);">' + window.t('morning-checks.settings.statuses_empty_html') + '</td></tr>';
                return;
            }
            tbody.innerHTML = statuses.map(s => `
                <tr>
                    <td>
                        <span class="status-swatch" style="background-color: ${escapeHtmlAttr(s.Colour)}"></span>
                        ${escapeHtml(s.Label)}
                    </td>
                    <td><code style="font-size: 12px; color: var(--text-muted, #666);">${escapeHtml(s.Colour)}</code></td>
                    <td>${s.RequiresNotes ? '<span class="badge-yes">' + escapeHtml(window.t('morning-checks.settings.yes')) + '</span>' : '<span class="badge-no">' + escapeHtml(window.t('morning-checks.settings.no')) + '</span>'}</td>
                    <td><span class="status-badge status-${s.IsActive ? 'active' : 'inactive'}">${s.IsActive ? escapeHtml(window.t('morning-checks.settings.status_active')) : escapeHtml(window.t('morning-checks.settings.status_inactive'))}</span></td>
                    <td>
                        <button class="action-btn" onclick="openEditStatusModal(${s.StatusID})" title="${escapeHtmlAttr(window.t('morning-checks.settings.edit'))}">${ICON_EDIT_S}</button>
                        <button class="action-btn delete" onclick="deleteStatus(${s.StatusID}, '${escapeJsString(s.Label)}')" title="${escapeHtmlAttr(window.t('morning-checks.settings.delete'))}">${ICON_DELETE_S}</button>
                    </td>
                </tr>
            `).join('');
        }

        function openAddStatusModal() {
            document.getElementById('statusModalTitle').textContent = window.t('morning-checks.settings.modal_add_status');
            document.getElementById('statusId').value = '';
            document.getElementById('statusLabel').value = '';
            document.getElementById('statusColour').value = '#28a745';
            document.getElementById('statusRequiresNotes').checked = false;
            document.getElementById('statusIsActive').checked = true;
            document.getElementById('statusModal').classList.add('active');
            document.getElementById('statusLabel').focus();
        }

        function openEditStatusModal(statusId) {
            const s = statuses.find(x => x.StatusID === statusId);
            if (!s) return;
            document.getElementById('statusModalTitle').textContent = window.t('morning-checks.settings.modal_edit_status');
            document.getElementById('statusId').value = s.StatusID;
            document.getElementById('statusLabel').value = s.Label;
            document.getElementById('statusColour').value = s.Colour;
            document.getElementById('statusRequiresNotes').checked = s.RequiresNotes;
            document.getElementById('statusIsActive').checked = s.IsActive;
            document.getElementById('statusModal').classList.add('active');
            document.getElementById('statusLabel').focus();
        }

        function closeStatusModal() {
            document.getElementById('statusModal').classList.remove('active');
        }

        document.getElementById('statusForm').addEventListener('submit', async function(e) {
            e.preventDefault();
            const id = document.getElementById('statusId').value;
            const payload = {
                statusId:      id ? parseInt(id, 10) : null,
                label:         document.getElementById('statusLabel').value.trim(),
                colour:        document.getElementById('statusColour').value,
                requiresNotes: document.getElementById('statusRequiresNotes').checked,
                isActive:      document.getElementById('statusIsActive').checked
            };
            try {
                const res = await fetch(API_BASE + 'save_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    closeStatusModal();
                    loadStatuses();
                    showToast(id ? window.t('morning-checks.settings.toast_status_updated') : window.t('morning-checks.settings.toast_status_added'), 'success');
                } else {
                    showToast(data.error || window.t('morning-checks.settings.toast_save_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('morning-checks.settings.toast_save_status_failed'), 'error');
            }
        });

        async function deleteStatus(statusId, label) {
            if (!(await showConfirm({ title: window.t('morning-checks.settings.confirm_delete_title'), message: window.t('morning-checks.settings.confirm_delete_status', { label: label }), okLabel: window.t('morning-checks.settings.confirm_ok'), okClass: 'danger' }))) return;
            try {
                const res = await fetch(API_BASE + 'delete_status.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ statusId: statusId })
                });
                const data = await res.json();
                if (data.success) {
                    loadStatuses();
                    showToast(window.t('morning-checks.settings.toast_status_deleted'), 'success');
                } else {
                    showToast(data.error || window.t('morning-checks.settings.toast_delete_failed'), 'error');
                }
            } catch (e) {
                showToast(window.t('morning-checks.settings.toast_delete_status_failed'), 'error');
            }
        }

        // Dismiss the status modal on backdrop click
        document.getElementById('statusModal').addEventListener('click', function(e) {
            if (e.target === this) closeStatusModal();
        });

        // Helpers reused by the statuses tab (escapeHtml already exists
        // further down, so just declare these once here).
        function escapeHtmlAttr(t) { return String(t).replace(/"/g, '&quot;'); }
        function escapeJsString(t) {
            return String(t == null ? '' : t)
                .replace(/\\/g, '\\\\')
                .replace(/'/g, "\\'")
                .replace(/\n/g, '\\n');
        }

        // ===== Chart tab — fill style preference =====
        // Per-analyst preference saved via the generic user-preference
        // API. Dashboard reads the same key on page load to decide
        // whether to render plain or gradient bars.
        const CHART_FILL_PREF = 'mc_chart_fill_style';

        async function loadChartFillSetting() {
            let v = 'plain';
            try {
                const res = await fetch('../../api/system/get_user_preference.php?key=' + CHART_FILL_PREF);
                const data = await res.json();
                if (data && data.success && data.value === 'gradient') v = 'gradient';
            } catch (e) {
                // Stick with default 'plain'
            }
            const radio = document.querySelector('input[name="chartFill"][value="' + v + '"]');
            if (radio) radio.checked = true;
        }

        function wireChartFillSetting() {
            document.querySelectorAll('input[name="chartFill"]').forEach(radio => {
                radio.addEventListener('change', async function() {
                    try {
                        const res = await fetch('../../api/system/set_user_preference.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ key: CHART_FILL_PREF, value: this.value })
                        });
                        const data = await res.json();
                        if (data && data.success) {
                            showToast(window.t('morning-checks.settings.toast_saved'), 'success');
                        } else {
                            showToast((data && data.error) || window.t('morning-checks.settings.toast_save_failed'), 'error');
                        }
                    } catch (e) {
                        showToast(window.t('morning-checks.settings.toast_save_failed'), 'error');
                    }
                });
            });
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            loadChecks();
            loadStatuses();
            loadChartFillSetting();
            wireChartFillSetting();
        });
    </script>
</body>
</html>
