<?php
/**
 * Calendar Settings - Manage event categories
 */
session_start();
require_once '../../config.php';
require_once '../../includes/i18n.php';
require_once '../../includes/theme.php';
I18n::initFromSession();

// Check if user is logged in
if (!isset($_SESSION['analyst_id'])) {
    header('Location: ../../login.php');
    exit;
}

$analyst_name = $_SESSION['analyst_name'] ?? 'Analyst';
$current_page = 'settings';
$path_prefix = '../../';
$translationNamespaces = ['common', 'calendar'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Service Desk - <?php echo htmlspecialchars(t('calendar.settings.title')); ?></title>
    <link rel="stylesheet" href="../../assets/css/theme.css?v=11">
    <link rel="stylesheet" href="../../assets/css/inbox.css?v=37">
    <style>
        /* Module accent — drives toggle, focus rings, button colours.
           Modal form CSS lives entirely in inbox.css. */
        body { --accent: var(--cal-accent, #ef6c00); --accent-hover: var(--cal-accent-hover, #e65100); }

        /* Full-width settings page matching the canonical settings layout
           (change-management/settings, tickets/settings). */
        .container {
            height: calc(100vh - 48px);
            overflow-y: auto;
            max-width: none;
            margin: 0;
            padding: 16px 30px 24px;
        }

        /* Orange theme override for the canonical .tab classes from
           inbox.css (default blue accent). Only this page is using the
           calendar's orange. */
        .tab:hover { color: var(--cal-accent, #ef6c00); }
        .tab.active { color: var(--cal-accent, #ef6c00); border-bottom-color: var(--cal-accent, #ef6c00); }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
            /* Match the height of a header that has an action button (e.g. the
               Categories "Add" button) so the heading sits in the same place on
               every tab, button or not. */
            min-height: 34px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 18px;
            color: var(--text, #333);
        }

        .add-btn {
            background: var(--cal-accent, #ef6c00);
            color: var(--cal-on-accent, white);
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            font-size: 13px;
            cursor: pointer;
            flex-shrink: 0;
        }
        .add-btn:hover { background: var(--cal-accent-hover, #e65100); }

        /* Categories table */
        .lookup-table { width: 100%; border-collapse: collapse; }
        .lookup-table th,
        .lookup-table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid var(--border-soft, #f0f0f0);
            font-size: 14px;
        }
        .lookup-table th {
            font-weight: 600;
            color: var(--text-muted, #666);
            background: var(--surface-2, #fafafa);
        }
        /* Force the Actions column to size to its content (width: 1%) and
           never wrap the icon buttons. Same trick the change-management
           settings table uses. */
        .lookup-table td:last-child,
        .lookup-table th:last-child {
            white-space: nowrap;
            width: 1%;
        }

        .swatch {
            display: inline-block;
            width: 18px;
            height: 18px;
            border-radius: 3px;
            vertical-align: middle;
            border: 1px solid var(--border, #ddd);
            margin-right: 6px;
        }

        /* Active/Inactive uses the shared .status-badge / .status-active
           / .status-inactive classes from inbox.css (canonical green/red). */

        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: var(--text-muted, #666);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
        }
        .action-btn:hover { color: var(--cal-accent, #ef6c00); }
        .action-btn.delete:hover { color: var(--danger-accent, #c62828); }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-muted, #666);
        }

        /* Modal + buttons use the canonical .modal / .modal-content / .modal-header /
           .modal-body / .modal-footer / .btn / .btn-primary / .btn-secondary
           primitives from inbox.css — no local overrides, so tweaking inbox.css
           updates this modal too. (.btn-primary picks up the orange via the
           pinned --accent above.) */

        /* Form-group + form-row + colour-input sizing all live in inbox.css. */
        .form-group input[type="color"] { width: 60px; height: 40px; padding: 2px; cursor: pointer; }

        .form-checkbox {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            color: var(--text, #333);
            cursor: pointer;
        }
        .form-checkbox input { width: 18px; height: 18px; cursor: pointer; }

        .loading {
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 40px;
        }
        .spinner {
            width: 32px;
            height: 32px;
            border: 3px solid var(--border, #e0e0e0);
            border-top-color: var(--cal-accent, #ef6c00);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
    </style>
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <script src="../../assets/js/i18n.js"></script>
</head>
<body>
    <?php include '../includes/header.php'; ?>

    <div class="container">
        <!-- Tab bar. Just one tab today; structure matches the other
             modules' settings pages so the page can grow without
             restructuring (e.g. future Holidays / Working hours tabs). -->
        <div class="tabs">
            <button class="tab active" data-tab="categories" onclick="switchTab('categories')"><?php echo htmlspecialchars(t('calendar.settings.tab_categories')); ?></button>
            <button class="tab" data-tab="left-panel" onclick="switchTab('left-panel')"><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></button>
        </div>

        <div class="tab-content active" id="categories-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('calendar.settings.heading')); ?></h2>
                <button class="add-btn" onclick="openCategoryModal()"><?php echo htmlspecialchars(t('calendar.settings.add')); ?></button>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('calendar.settings.intro')); ?></p>

            <table class="lookup-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t('calendar.settings.col_name')); ?></th>
                        <th><?php echo htmlspecialchars(t('calendar.settings.col_description')); ?></th>
                        <th><?php echo htmlspecialchars(t('calendar.settings.col_status')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="categoryTableBody">
                    <tr><td colspan="4"><div class="loading"><div class="spinner"></div></div></td></tr>
                </tbody>
            </table>
        </div>

        <!-- Left panel tab — per-analyst preference -->
        <div class="tab-content" id="left-panel-tab">
            <div class="section-header">
                <h2><?php echo htmlspecialchars(t('common.left_panel.tab')); ?></h2>
            </div>
            <p style="color: var(--text-muted, #666); margin-bottom: 16px;"><?php echo htmlspecialchars(t('calendar.settings.left_panel_intro')); ?></p>

            <form id="leftPanelForm" autocomplete="off" onsubmit="event.preventDefault();">
                <div class="form-group">
                    <label style="display: block; margin-bottom: 10px; font-weight: 500; color: var(--text, #333);"><?php echo htmlspecialchars(t('common.left_panel.visibility')); ?></label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; margin-bottom: 8px; cursor: pointer;">
                        <input type="radio" name="calendarSidebarMode" value="always" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.always')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('calendar.settings.left_panel_always_desc')); ?>
                        </span>
                    </label>
                    <label style="display: block; padding: 10px 14px; border: 1px solid var(--border, #ddd); border-radius: 6px; cursor: pointer;">
                        <input type="radio" name="calendarSidebarMode" value="hover" onchange="saveSidebarMode(this.value)">
                        <strong><?php echo htmlspecialchars(t('common.left_panel.hover')); ?></strong>
                        <span style="display: block; font-size: 12px; color: var(--text-dim, #777); margin-top: 4px; margin-left: 22px;">
                            <?php echo htmlspecialchars(t('calendar.settings.left_panel_hover_desc')); ?>
                        </span>
                    </label>
                </div>
            </form>
        </div>
    </div>

    <!-- Category Modal -->
    <div class="modal" id="categoryModal">
        <div class="modal-content" style="max-width: 450px;">
            <div class="modal-header" id="categoryModalTitle"><?php echo htmlspecialchars(t('calendar.settings.modal_add')); ?></div>
            <!-- autocomplete="off" on the form + each input so the browser
                 doesn't suggest previously-entered category names from
                 unrelated forms. Modern browsers can ignore form-level
                 autocomplete=off so we belt-and-brace with field-level too. -->
            <form class="modal-body" autocomplete="off" onsubmit="event.preventDefault(); saveCategory();">
                <input type="hidden" id="categoryId" value="">
                <div class="form-group">
                    <label for="categoryName"><?php echo htmlspecialchars(t('calendar.settings.modal_name')); ?> *</label>
                    <input type="text" id="categoryName" placeholder="<?php echo htmlspecialchars(t('calendar.settings.modal_name_ph')); ?>" autocomplete="off">
                </div>
                <div class="form-group">
                    <label for="categoryDescription"><?php echo htmlspecialchars(t('calendar.settings.modal_description')); ?></label>
                    <textarea id="categoryDescription" placeholder="<?php echo htmlspecialchars(t('calendar.settings.modal_description_ph')); ?>" autocomplete="off"></textarea>
                </div>
                <div class="form-group">
                    <label for="categoryColor"><?php echo htmlspecialchars(t('calendar.settings.modal_colour')); ?></label>
                    <input type="color" id="categoryColor" value="#ef6c00" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="toggle-label">
                        <span class="toggle-switch">
                            <input type="checkbox" id="categoryActive" checked>
                            <span class="toggle-slider"></span>
                        </span>
                        <?php echo htmlspecialchars(t('calendar.settings.modal_active')); ?>
                    </label>
                </div>
            </form>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCategoryModal()"><?php echo htmlspecialchars(t('calendar.settings.cancel')); ?></button>
                <button class="btn btn-primary" onclick="saveCategory()"><?php echo htmlspecialchars(t('calendar.settings.save')); ?></button>
            </div>
        </div>
    </div>

    <script>
        const API_BASE = '../../api/calendar/';
        let categories = [];

        // SVG icons used in the action column. Centralised so future polish
        // (size, stroke width) only touches one place.
        const ICON_EDIT = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg>';
        const ICON_DELETE = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>';

        document.addEventListener('DOMContentLoaded', function() {
            loadCategories();
        });

        // Standard tab switcher (matches the pattern used in other modules'
        // settings pages). Only one tab exists today, but the JS is set up
        // so adding more is just an HTML change.
        function switchTab(tab) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            const btn = document.querySelector('.tab[data-tab="' + tab + '"]');
            if (btn) btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            const content = document.getElementById(tab + '-tab');
            if (content) content.classList.add('active');
            if (tab === 'left-panel') loadSidebarMode();
        }

        // --- Left panel preference ------------------------------------
        // 'always' vs 'hover', stored per-analyst via user_preferences.
        // header.php reads the same key on every calendar page and toggles
        // .sidebar-hover on .calendar-container. Also editable under
        // System → Preferences.
        const SIDEBAR_MODE_KEY = 'calendar_sidebar_mode';
        let sidebarModeLoaded = false;
        async function loadSidebarMode() {
            if (sidebarModeLoaded) return;
            sidebarModeLoaded = true;
            try {
                const r = await fetch('../../api/system/get_user_preference.php?key=' + encodeURIComponent(SIDEBAR_MODE_KEY), { credentials: 'same-origin' });
                const d = await r.json();
                const mode = (d.success && (d.value === 'always' || d.value === 'hover')) ? d.value : 'always';
                document.querySelectorAll('input[name="calendarSidebarMode"]').forEach(i => { i.checked = (i.value === mode); });
            } catch (e) {
                const first = document.querySelector('input[name="calendarSidebarMode"][value="always"]');
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
                if (d.success) showToast(window.t('calendar.toast.saved'), 'success');
            } catch (e) { /* no-op */ }
        }

        // Toast + confirm come from the global helpers in assets/js/toast.js
        // and assets/js/confirm.js (auto-loaded by the waffle menu). API:
        //   showToast(message, 'success' | 'error' | 'warning' | 'info')
        //   showConfirm({title, message, okLabel, okClass, onConfirm})

        async function loadCategories() {
            try {
                const response = await fetch(API_BASE + 'get_categories.php');
                const data = await response.json();

                if (data.success) {
                    categories = data.categories;
                    renderCategories();
                } else {
                    document.getElementById('categoryTableBody').innerHTML =
                        '<tr><td colspan="4"><div class="empty-state">' + window.t('calendar.settings.load_error') + '</div></td></tr>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('categoryTableBody').innerHTML =
                    '<tr><td colspan="4"><div class="empty-state">Error loading categories</div></td></tr>';
            }
        }

        function renderCategories() {
            const tbody = document.getElementById('categoryTableBody');

            if (categories.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4"><div class="empty-state">' + window.t('calendar.settings.empty') + '</div></td></tr>';
                return;
            }

            tbody.innerHTML = categories.map(cat => `
                <tr>
                    <td>
                        <span class="swatch" style="background-color: ${cat.color}"></span>
                        ${escapeHtml(cat.name)}
                    </td>
                    <td>${cat.description ? escapeHtml(cat.description) : '<span style="color:var(--text-faint,#999)">&mdash;</span>'}</td>
                    <td>
                        <span class="status-badge status-${cat.is_active ? 'active' : 'inactive'}">
                            ${cat.is_active ? window.t('calendar.settings.active') : window.t('calendar.settings.inactive')}
                        </span>
                    </td>
                    <td>
                        <button class="action-btn" onclick="editCategory(${cat.id})" title="${window.t('calendar.settings.edit')}">${ICON_EDIT}</button>
                        <button class="action-btn delete" onclick="deleteCategory(${cat.id})" title="${window.t('calendar.settings.delete')}">${ICON_DELETE}</button>
                    </td>
                </tr>
            `).join('');
        }

        function openCategoryModal(categoryId = null) {
            const modal = document.getElementById('categoryModal');
            const title = document.getElementById('categoryModalTitle');

            // Reset form
            document.getElementById('categoryId').value = '';
            document.getElementById('categoryName').value = '';
            document.getElementById('categoryColor').value = '#ef6c00';
            document.getElementById('categoryDescription').value = '';
            document.getElementById('categoryActive').checked = true;

            if (categoryId) {
                const cat = categories.find(c => c.id == categoryId);
                if (cat) {
                    title.textContent = window.t('calendar.settings.modal_edit');
                    document.getElementById('categoryId').value = cat.id;
                    document.getElementById('categoryName').value = cat.name;
                    document.getElementById('categoryColor').value = cat.color;
                    document.getElementById('categoryDescription').value = cat.description || '';
                    document.getElementById('categoryActive').checked = cat.is_active;
                }
            } else {
                title.textContent = window.t('calendar.settings.modal_add');
            }

            modal.classList.add('active');
        }

        function editCategory(id) {
            openCategoryModal(id);
        }

        function closeCategoryModal() {
            document.getElementById('categoryModal').classList.remove('active');
        }

        async function saveCategory() {
            const id = document.getElementById('categoryId').value;
            const name = document.getElementById('categoryName').value.trim();
            const color = document.getElementById('categoryColor').value;
            const description = document.getElementById('categoryDescription').value.trim();
            const isActive = document.getElementById('categoryActive').checked;

            if (!name) {
                showToast(window.t('calendar.settings.name_required'), 'error');
                return;
            }

            const payload = {
                id: id || null,
                name,
                color,
                description,
                is_active: isActive
            };

            try {
                const response = await fetch(API_BASE + 'save_category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });
                const data = await response.json();

                if (data.success) {
                    closeCategoryModal();
                    loadCategories();
                    showToast(window.t('calendar.toast.saved'), 'success');
                } else {
                    showToast(data.error || window.t('calendar.toast.save_failed'), 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast(window.t('calendar.toast.save_failed'), 'error');
            }
        }

        function deleteCategory(id) {
            const cat = categories.find(c => c.id == id);
            const name = cat ? cat.name : window.t('calendar.settings.delete_this');
            showConfirm({
                title: window.t('calendar.settings.delete_title'),
                message: window.t('calendar.settings.delete_confirm', { name }),
                okLabel: window.t('calendar.settings.delete'),
                okClass: 'danger',
                onConfirm: () => doDeleteCategory(id)
            });
        }

        async function doDeleteCategory(id) {
            try {
                const response = await fetch(API_BASE + 'delete_category.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const data = await response.json();

                if (data.success) {
                    loadCategories();
                    showToast(window.t('calendar.toast.deleted'), 'success');
                } else {
                    showToast(data.error || window.t('calendar.toast.delete_failed'), 'error');
                }
            } catch (error) {
                console.error('Error:', error);
                showToast(window.t('calendar.toast.delete_failed'), 'error');
            }
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>
