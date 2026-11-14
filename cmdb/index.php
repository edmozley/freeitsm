<?php
/**
 * CMDB - Browse page
 * Sidebar = class list with object counts. Main = table of objects in the
 * selected class, with property filters (chips) and up to three property
 * columns, all kept in the URL. Click a row to open the object detail page.
 */
session_start();
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/i18n.php';
require_once '../includes/theme.php';
require_once '../includes/timezone.php';
I18n::initFromSession();
Tz::init();

requireModuleAccess('cmdb');

$current_page = 'browse';
$translationNamespaces = ['common', 'cmdb'];
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(I18n::getLocale()); ?>" data-theme="<?php echo htmlspecialchars(Theme::active()); ?>" data-theme-mode="<?php echo htmlspecialchars(Theme::mode()); ?>">
<head>
    <link rel="icon" type="image/svg+xml" href="<?php echo defined('BASE_URL') ? BASE_URL : '/'; ?>favicon.svg">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars(pageTitle('cmdb')); ?></title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=27">
    <link rel="stylesheet" href="../assets/css/inbox.css?v=79">
    <script>window.translations = <?php echo json_encode(I18n::exportForJs($translationNamespaces), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;</script>
    <?php echo Tz::scriptTag(); ?>
    <script src="../assets/js/tz.js?v=5"></script>
    <script src="../assets/js/i18n.js?v=4"></script>
    <style>
        body { --accent: var(--cmdb-accent); --accent-hover: var(--cmdb-accent-hover); --on-accent: var(--cmdb-on-accent); overflow: hidden; height: 100vh; background: var(--app-bg, #f5f5f5); }
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

        /* Property filters (GH #312 follow-up): one strip above the table with the
           active filters as chips, and the builder row when Filter is pressed. */
        .filter-bar {
            display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
            padding: 10px 16px;
            background: var(--surface, #ffffff);
            border-bottom: 1px solid var(--border, #e5e7eb);
            flex-shrink: 0;
        }
        .filter-bar[hidden] { display: none; }
        .flt-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 4px 4px 4px 12px;
            border: 1px solid var(--cmdb-accent, #be185d);
            border-radius: 999px;
            background: #fdf2f8;
            color: var(--text, #374151);
            font-size: 13px;
        }
        .flt-chip strong { font-weight: 600; color: var(--text, #111827); }
        .flt-chip-x {
            border: 0; background: transparent; cursor: pointer;
            width: 22px; height: 22px; border-radius: 50%;
            color: var(--cmdb-accent, #be185d); font-size: 16px; line-height: 1;
        }
        .flt-chip-x:hover { background: rgba(190, 24, 93, 0.12); }
        .flt-link {
            border: 0; background: transparent; cursor: pointer; padding: 4px 6px;
            color: var(--cmdb-accent, #be185d); font-size: 13px; font-weight: 500;
        }
        .flt-link:hover { text-decoration: underline; }
        .flt-builder { display: inline-flex; flex-wrap: wrap; align-items: center; gap: 6px; }
        .flt-builder select, .flt-builder input {
            padding: 6px 10px; border: 1px solid var(--border, #d1d5db); border-radius: 4px;
            font: inherit; font-size: 13px; background: var(--surface, #ffffff); color: var(--text, #111827);
            max-width: 220px;
        }
        .flt-builder select:focus, .flt-builder input:focus { outline: none; border-color: var(--cmdb-accent, #be185d); }
        .flt-value { display: inline-flex; }
        .flt-pick { display: inline-block; }
        .flt-pick input { width: 220px; }
        .flt-pick .ac-results { min-width: 220px; }
        .flt-btn {
            padding: 6px 14px; border-radius: 4px; cursor: pointer; font: inherit; font-size: 13px; font-weight: 600;
            border: 1px solid var(--border, #d1d5db); background: var(--surface, #ffffff); color: var(--text, #374151);
        }
        .flt-btn-primary { background: var(--cmdb-accent, #be185d); border-color: var(--cmdb-accent, #be185d); color: var(--cmdb-on-accent, #ffffff); }
        .flt-btn-primary:hover { background: var(--cmdb-accent-hover, #9d174d); }
        /* Filter / Columns while something is applied. */
        .action-row-btn.is-on { border-color: var(--cmdb-accent, #be185d); color: var(--cmdb-accent, #be185d); }

        .col-menu {
            position: fixed; z-index: 50; width: 260px; max-height: 320px; overflow-y: auto;
            background: var(--surface, #ffffff); border: 1px solid var(--border, #e5e7eb); border-radius: 6px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.12); padding: 6px 0;
        }
        .col-menu[hidden] { display: none; }
        .col-menu-head { padding: 6px 14px 8px; font-size: 12px; color: var(--text-muted, #6b7280); }
        .col-menu-item { display: flex; align-items: center; gap: 10px; padding: 7px 14px; font-size: 13px; cursor: pointer; color: var(--text, #374151); }
        .col-menu-item:hover { background: #fdf2f8; }
        .col-menu-item.is-off { color: var(--text-dim, #9ca3af); cursor: not-allowed; }
        .col-menu-item input { margin: 0; accent-color: var(--cmdb-accent, #be185d); }
        .col-pill { display: inline-block; padding: 2px 10px; border-radius: 999px; color: #ffffff; font-size: 12px; font-weight: 600; }
        .col-gone { color: var(--text-dim, #9ca3af); font-style: italic; font-size: 13px; }
        [data-theme-mode="dark"] .flt-chip { background: rgba(190, 24, 93, 0.15); }
        [data-theme-mode="dark"] .col-menu-item:hover { background: rgba(190, 24, 93, 0.15); }

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
    <!-- Mobile layer: linked AFTER this page's own <style> so its @media rules
         win on ties (Mobile-Friendly-Techniques §9). -->
    <link rel="stylesheet" href="../assets/css/mobile.css?v=196">
</head>
<body data-mobile-module="cmdb" data-mobile-page="cmdb-browse">
    <?php include 'includes/header.php'; ?>
    <?php /* The action row (#185) is what was the list's own header: the class
             name is its title (browse.js rewrites #mainTitle when a class is
             picked), then the filter box and New. New stays disabled until a
             class is picked - an object cannot exist without one. */
          require_once __DIR__ . '/../includes/action_row.php';
          renderActionRow(t('cmdb.list.select_class'),
              [array_merge(actionRowNew('openNewObjectModal()', t('cmdb.list.new_object'), false, 'newObjectBtn'), ['disabled' => true])],
              ['title_id' => 'mainTitle',
               // Filter and Columns (GH #312 follow-up) stay hidden until the picked
               // class turns out to have properties to filter on.
               'extra' => '<input type="text" class="action-row-search" id="searchInput" placeholder="'
                        . htmlspecialchars(t('cmdb.list.search_placeholder')) . '" oninput="onSearchInput(event)">'
                        . '<button type="button" class="action-row-btn" id="filterBtn" hidden onclick="openFilterBuilder()" title="'
                        . htmlspecialchars(t('cmdb.list.filter_title')) . '">' . htmlspecialchars(t('cmdb.list.filter_button')) . '</button>'
                        . '<button type="button" class="action-row-btn" id="columnsBtn" hidden onclick="toggleColumnsMenu(event)" title="'
                        . htmlspecialchars(t('cmdb.list.columns_title')) . '">' . htmlspecialchars(t('cmdb.list.columns_button')) . '</button>']); ?>
    <div class="col-menu" id="columnsMenu" hidden></div>

    <div class="browse-container">
        <aside class="browse-sidebar">
            <div class="sidebar-header"><h2><?php echo htmlspecialchars(t('cmdb.list.classes')); ?></h2></div>
            <div class="class-list" id="classList">
                <div style="padding: 16px; color: var(--text-dim, #9ca3af); font-size: 13px;"><?php echo htmlspecialchars(t('cmdb.list.loading')); ?></div>
            </div>
        </aside>

        <main class="browse-main">
            <div class="filter-bar" id="filterBar" hidden></div>
            <div class="object-list" id="objectList">
                <div class="empty-state">
                    <?php /* 🔴 "Pick a class ON THE LEFT" stops being true on a phone:
                             the class list becomes a chip strip ACROSS THE TOP, so the
                             one instruction on an otherwise empty screen points at
                             nothing. Only a screenshot found it — every measurement
                             passed, because a sentence that points the wrong way is
                             exactly as wide as one that does not.

                             On a phone the heading above this panel already reads
                             "Select a class", so the mobile layer hides this line
                             rather than restating it. No new string, and no reworded
                             one either — the first attempt swapped in a translated
                             synonym and the screenshot showed the same words twice,
                             one under the other. */ ?>
                    <h3 class="cmdb-empty-lead"><?php echo htmlspecialchars(t('cmdb.list.pick_class_heading')); ?></h3>
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

    <script>window.CMDB_RECORD_TYPES = <?php require_once __DIR__ . '/../includes/cmdb_record_refs.php'; echo json_encode(array_keys(cmdbRecordTypes())); ?>;</script>
    <script src="browse.js?v=7"></script>
    <!-- The mobile layer's JS. Without it the module nav has no hamburger and
         sits off-screen — a CSS-only opt-in is not an opt-in (Techniques §25). -->
    <script src="../assets/js/mobile.js?v=83"></script>
</body>
</html>
