<?php
/**
 * CMDB - Browse page
 * Sidebar = class list with object counts. Main = table of objects in the
 * selected class. Click a row to open the object detail page.
 */
session_start();
require_once '../config.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

$current_page = 'browse';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreeITSM - <?php echo htmlspecialchars(t('cmdb.title')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=14">
    <link rel="stylesheet" href="../assets/css/inbox.css">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=1"></script>
    <script src="../assets/js/i18n.js?v=2"></script>
    <style>
        body { --accent: var(--cmdb-accent); overflow: hidden; height: 100vh; background: var(--app-bg, #f5f5f5); }
        .browse-container { display: flex; height: calc(100vh - 60px); }

        .browse-sidebar {
            width: 260px;
            background: var(--surface, #ffffff);
            border-right: 1px solid var(--border, #e5e7eb);
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }
        .browse-sidebar .sidebar-header {
            padding: 16px 18px;
            border-bottom: 1px solid var(--border, #e5e7eb);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .browse-sidebar .sidebar-header h2 { font-size: 15px; color: var(--text, #111827); margin: 0; }
        .browse-sidebar .class-list { flex: 1; overflow-y: auto; padding: 8px 0; }

        .class-item {
            padding: 10px 18px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            color: var(--text, #374151);
            border-left: 3px solid transparent;
            transition: background 0.12s, border-color 0.12s, color 0.12s;
        }
        .class-item:hover { background: #fdf2f8; }
        .class-item.active { background: #fce7f3; color: var(--cmdb-accent, #be185d); border-left-color: var(--cmdb-accent, #be185d); font-weight: 600; }
        .class-item .count {
            background: var(--surface-3, #f3f4f6);
            color: var(--text-muted, #6b7280);
            padding: 2px 8px;
            font-size: 12px;
            border-radius: 999px;
        }
        .class-item.active .count { background: #fbcfe8; color: var(--cmdb-accent, #be185d); }
        .class-item.empty { color: var(--text-dim, #9ca3af); }

        .browse-main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
        .main-header {
            padding: 16px 24px;
            border-bottom: 1px solid var(--border, #e5e7eb);
            background: var(--surface, #ffffff);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .main-header h2 { font-size: 18px; color: var(--text, #111827); margin: 0; }
        .main-header .actions { display: flex; gap: 10px; align-items: center; }
        .main-header input[type="text"] {
            padding: 7px 12px;
            border: 1px solid var(--border, #d1d5db);
            border-radius: 4px;
            font-size: 13px;
            width: 240px;
        }
        .main-header input[type="text"]:focus { outline: none; border-color: var(--cmdb-accent, #be185d); }
        .add-btn {
            background: var(--cmdb-accent, #be185d);
            color: var(--cmdb-on-accent, #ffffff);
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 500;
            font-size: 13px;
        }
        .add-btn:hover { background: var(--cmdb-accent-hover, #9d174d); }
        .add-btn:disabled { opacity: 0.5; cursor: not-allowed; }

        .object-list { flex: 1; overflow: auto; padding: 0; background: var(--surface, #ffffff); }
        .object-list table { width: 100%; border-collapse: collapse; }
        .object-list thead { position: sticky; top: 0; background: var(--surface-2, #fafafa); z-index: 1; }
        .object-list th {
            text-align: left;
            padding: 10px 16px;
            font-size: 12px;
            text-transform: uppercase;
            color: var(--text-muted, #6b7280);
            border-bottom: 1px solid var(--border, #e5e7eb);
            font-weight: 600;
        }
        .object-list td { padding: 12px 16px; border-bottom: 1px solid var(--border-soft, #f3f4f6); font-size: 14px; color: var(--text, #1f2937); }
        .object-list tbody tr { cursor: pointer; transition: background 0.1s; }
        .object-list tbody tr:hover { background: var(--surface-hover, #fafafa); }
        .object-list tbody tr:hover .object-name { color: var(--cmdb-accent, #be185d); }

        .object-name { font-weight: 600; color: var(--text, #111827); }
        .empty-state { padding: 80px 40px; text-align: center; color: var(--text-dim, #9ca3af); }
        .empty-state h3 { color: var(--text, #374151); font-weight: 600; margin-bottom: 8px; }
        .empty-state p { margin-bottom: 16px; font-size: 14px; }

        /* Planned-state styling — pill rendered next to the name everywhere
           planned objects appear so the future-state distinction reads at a glance. */
        .planned-pill {
            display: inline-block;
            background: var(--warning-bg, #fef3c7);
            color: var(--warning-text, #92400e);
            border: 1px solid var(--warning-border, #fcd34d);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            margin-left: 8px;
            vertical-align: middle;
        }
        .object-list tr.is-planned td { opacity: 0.85; }
        .object-list tr.is-planned .object-name { font-style: italic; }

        .badge-count {
            background: var(--surface-3, #f3f4f6);
            color: var(--text-muted, #4b5563);
            padding: 2px 8px;
            font-size: 12px;
            border-radius: 999px;
            font-weight: 500;
        }
        .parent-link { color: var(--text-muted, #6b7280); font-size: 13px; }
        .parent-link strong { color: var(--text, #374151); }

        .modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.4); z-index: 1000; }
        .modal.active { display: flex; align-items: center; justify-content: center; }
        .modal-content { background: var(--surface, #ffffff); border-radius: 8px; width: 480px; max-width: 95vw; }
        .modal-header { padding: 18px 24px; border-bottom: 1px solid var(--border, #e5e7eb); font-weight: 600; font-size: 16px; }
        .modal-body { padding: 24px; }
        .modal-actions {
            padding: 16px 24px;
            border-top: 1px solid var(--border, #e5e7eb);
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text, #374151); margin-bottom: 6px; }
        .form-group input, .form-group select {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border, #d1d5db);
            border-radius: 4px; font-size: 14px;
        }
        .form-group input:focus, .form-group select:focus {
            outline: none; border-color: var(--cmdb-accent, #be185d);
            box-shadow: 0 0 0 3px rgba(190, 24, 93, 0.1);
        }
        .form-group small { color: var(--text-muted, #6b7280); font-size: 12px; display: block; margin-top: 4px; }
        .btn { padding: 9px 18px; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 500; border: 1px solid transparent; }
        .btn-primary { background: var(--cmdb-accent, #be185d); color: var(--cmdb-on-accent, #ffffff); }
        .btn-primary:hover { background: var(--cmdb-accent-hover, #9d174d); }
        .btn-secondary { background: var(--surface, #ffffff); color: var(--text, #374151); border-color: var(--border, #d1d5db); }

        /* Required-fields section in the New Object modal */
        .req-fields-divider {
            margin: 18px 0 10px 0;
            padding-top: 16px;
            border-top: 1px solid var(--border-soft, #f3f4f6);
            font-size: 11px;
            text-transform: uppercase;
            color: var(--text-dim, #9ca3af);
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .req-mark { color: var(--cmdb-accent, #be185d); margin-left: 3px; }
        .form-group select, .form-group textarea {
            width: 100%; padding: 9px 12px; border: 1px solid var(--border, #d1d5db);
            border-radius: 4px; font-size: 14px; font-family: inherit;
        }
        .form-group select:focus, .form-group textarea:focus {
            outline: none; border-color: var(--cmdb-accent, #be185d);
            box-shadow: 0 0 0 3px rgba(190, 24, 93, 0.1);
        }

        /* Tiny autocomplete used for object_ref required fields */
        .ac-wrap { position: relative; }
        .ac-results {
            position: absolute;
            top: 100%; left: 0; right: 0;
            background: var(--surface, #ffffff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: 4px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            max-height: 180px;
            overflow-y: auto;
            z-index: 10;
            margin-top: 4px;
            display: none;
        }
        .ac-results.active { display: block; }
        .ac-result {
            padding: 7px 10px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
        }
        .ac-result:hover, .ac-result.highlighted { background: #fdf2f8; color: var(--cmdb-accent, #be185d); }
        .ac-result .ac-class { color: var(--text-dim, #9ca3af); font-size: 11px; }
        .ac-empty { padding: 8px; color: var(--text-dim, #9ca3af); font-size: 12px; text-align: center; }

        /* Dark-mode tints for the pink hover/selected states. The light-mode
           pink washes (#fdf2f8 hover vs #fce7f3 selected) would glare on the
           dark sidebar/menu surfaces, so swap them for translucent accent tints
           that keep the hover-vs-selected distinction. */
        [data-theme-mode="dark"] .class-item:hover { background: rgba(190, 24, 93, 0.12); }
        [data-theme-mode="dark"] .class-item.active { background: rgba(190, 24, 93, 0.24); }
        [data-theme-mode="dark"] .class-item.active .count { background: rgba(190, 24, 93, 0.42); }
        [data-theme-mode="dark"] .ac-result:hover,
        [data-theme-mode="dark"] .ac-result.highlighted { background: rgba(190, 24, 93, 0.15); }
    </style>
</head>
<body>
    <?php include 'includes/header.php'; ?>

    <div class="browse-container">
        <aside class="browse-sidebar">
            <div class="sidebar-header"><h2><?php echo htmlspecialchars(t('cmdb.list.classes')); ?></h2></div>
            <div class="class-list" id="classList">
                <div style="padding: 16px; color: var(--text-dim, #9ca3af); font-size: 13px;"><?php echo htmlspecialchars(t('cmdb.list.loading')); ?></div>
            </div>
        </aside>

        <main class="browse-main">
            <div class="main-header">
                <h2 id="mainTitle"><?php echo htmlspecialchars(t('cmdb.list.select_class')); ?></h2>
                <div class="actions">
                    <input type="text" id="searchInput" placeholder="<?php echo htmlspecialchars(t('cmdb.list.filter_placeholder')); ?>" oninput="onSearchInput(event)">
                    <button class="add-btn" id="newObjectBtn" onclick="openNewObjectModal()" disabled><?php echo htmlspecialchars(t('cmdb.list.new')); ?></button>
                </div>
            </div>
            <div class="object-list" id="objectList">
                <div class="empty-state">
                    <h3><?php echo htmlspecialchars(t('cmdb.list.pick_class_heading')); ?></h3>
                    <p><?php echo str_replace('{link}', '<a href="settings/" style="color: var(--cmdb-accent, #be185d);">' . htmlspecialchars(t('cmdb.list.pick_class_link')) . '</a>', htmlspecialchars(t('cmdb.list.pick_class_hint'))); ?></p>
                </div>
            </div>
        </main>
    </div>

    <!-- New Object Modal -->
    <div class="modal" id="newObjectModal">
        <div class="modal-content">
            <div class="modal-header"><?php echo htmlspecialchars(t('cmdb.new_object.heading')); ?> <span id="newObjectClassName"></span></div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="newObjectName"><?php echo htmlspecialchars(t('cmdb.new_object.name_label')); ?></label>
                    <input type="text" id="newObjectName" placeholder="<?php echo htmlspecialchars(t('cmdb.new_object.name_placeholder')); ?>" maxlength="255">
                    <small><?php echo htmlspecialchars(t('cmdb.new_object.name_help')); ?></small>
                </div>

                <!-- Required-property fields injected by JS when the class has any.
                     Optional properties are filled on the detail page after creation. -->
                <div id="newObjectReqFields"></div>

                <!-- Planned toggle: future-state objects that don't physically exist yet -->
                <div class="form-group" style="margin-top: 14px;">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="newObjectIsPlanned" style="width: auto; margin: 0;">
                        <span><strong><?php echo htmlspecialchars(t('cmdb.new_object.planned_label')); ?></strong> &mdash; <?php echo htmlspecialchars(t('cmdb.new_object.planned_desc')); ?></span>
                    </label>
                    <small style="margin-top: 4px; color: var(--text-dim, #888);"><?php echo htmlspecialchars(t('cmdb.new_object.planned_help')); ?></small>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeNewObjectModal()"><?php echo htmlspecialchars(t('cmdb.new_object.cancel')); ?></button>
                <button type="button" class="btn btn-primary" onclick="createObject()"><?php echo htmlspecialchars(t('cmdb.new_object.create')); ?></button>
            </div>
        </div>
    </div>

    <script src="browse.js?v=3"></script>
</body>
</html>
