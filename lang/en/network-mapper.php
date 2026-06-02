<?php
/**
 * English (en) — Network Mapper module strings.
 *
 * Source-of-truth locale. Every other lang/<code>/network-mapper.php may omit
 * keys; missing keys fall back to the value here (see includes/i18n.php).
 *
 * Covers the diagrams landing page, the canvas editor chrome (toolbar, status,
 * dropdowns, modals, detail panel, picker, related-objects, branding), the
 * toasts/confirms raised by assets/js/network-mapper.js, and the help guide.
 *
 * Does NOT cover user diagram data (node/object names, labels, saved JSON),
 * CMDB-derived names, or the icon library labels — those stay verbatim.
 */
return [
    'title' => 'Network Mapper',

    // Shared header nav (includes/header.php)
    'nav' => [
        'diagrams' => 'Diagrams',
        'help'     => 'Help',
    ],

    // Diagrams landing page (index.php)
    'index' => [
        'browser_title'    => 'FreeITSM — Network Mapper',
        'heading'          => 'Network Diagrams',
        'filter_placeholder' => 'Filter by title…',
        'new'              => 'New diagram',
        'loading'          => 'Loading diagrams…',
        'load_failed'      => 'Failed to load: {message}',
        'empty_heading'    => 'No diagrams yet',
        'empty_body'       => 'Network diagrams sit on top of the CMDB — drag a class onto the canvas, bind it to a CMDB object, and let related objects pull in automatically.',
        'empty_create'     => 'Create your first diagram',
        'no_description'   => 'No description',
        'version_unknown'  => 'v?',
        'versions_suffix'  => ' · {count} versions',
        'nodes'            => 'nodes',
        'connectors'       => 'connectors',
        'author_unknown'   => 'Unknown',
        'meta_by'          => 'By {author} · updated {date}',
        // New diagram modal
        'modal_title'      => 'New network diagram',
        'field_title'      => 'Title *',
        'field_title_ph'   => 'e.g. Core network — HQ floor 2',
        'field_description'=> 'Description',
        'field_description_ph' => 'What does this diagram show? (optional)',
        'field_version'    => 'Initial version label',
        'field_version_ph' => 'v1',
        'field_version_help' => 'Free text — e.g. “v1”, “Draft”, “Q1 baseline”. You can save new versions later from the editor.',
        'create'           => 'Create & open',
        // toasts / validation
        'title_required'   => 'Title is required',
        'create_failed'    => 'Failed: {message}',
        'delete_title'     => 'Delete',
        'delete_confirm'   => 'Delete "{title}"? This removes the current version only. Older versions in the chain are preserved.',
        'deleted'          => 'Diagram deleted',
        'delete_failed'    => 'Delete failed: {message}',
    ],

    // Diagram editor shell (diagram.php)
    'editor' => [
        'browser_title'    => 'FreeITSM — Network Diagram',
        'browser_title_named' => 'FreeITSM — {title}',
        'back'             => '← All diagrams',
        'loading'          => 'Loading…',
        'load_failed'      => 'Failed to load diagram',
        'untitled'         => '(untitled)',

        // Toolbar
        'autosave'         => 'Autosave',
        'autosave_title'   => 'Auto-save changes ~2s after the last edit',
        'page_off'         => 'Page: Off',
        'page_label'       => 'Page: {label} {orient}',
        'page_btn_title'   => 'Show a paper-size outline on the canvas — useful before exporting to PNG/PDF',
        'zoom_out'         => 'Zoom out',
        'zoom_in'          => 'Zoom in',
        'zoom_reset_title' => 'Click to reset to 100%',
        'zoom_fit'         => 'Fit',
        'zoom_fit_title'   => 'Fit page (or all nodes) to the visible canvas',
        'branding'         => 'Branding',
        'branding_title'   => 'Override the org-wide header/footer for this diagram (set a page size first)',
        'centre'           => 'Centre',
        'centre_title'     => 'Move all nodes so the diagram is centred on the selected paper size (requires a page size to be set)',
        'export_png'       => 'PNG',
        'export_png_title' => 'Export the diagram as a PNG image (clipped to the page outline if set)',
        'export_pdf'       => 'PDF',
        'export_pdf_title' => 'Export the diagram as a PDF (uses the chosen paper size + orientation)',
        'present'          => 'Present',
        'present_title'    => 'Hide the toolbar and panels to show just the diagram (Esc to exit, then F11 for full-screen)',
        'versions'         => 'Versions',
        'versions_title'   => 'Browse the version history of this diagram',
        'save_version'     => 'Save as new version',
        'save_version_title' => 'Clone the current version forward into a new editable version',
        'save'             => 'Save',
        'save_title'       => 'Save (Ctrl+S)',

        // Version pill
        'pill_current'     => '{label} (current)',
        'pill_readonly'    => '{label} (read-only)',
        'version_unknown'  => 'v?',

        // Meta row
        'meta_author'      => 'Author:',
        'meta_created'     => 'Created:',
        'meta_updated'     => 'Updated:',
        'author_unknown'   => 'Unknown',

        // Read-only banner
        'readonly_banner'  => 'Read-only version.',
        'readonly_banner_rest' => ' This is a historical version of the diagram. To make changes, fork it into a new version from the current (leaf) version.',
        'readonly_back'    => '← Back to diagrams',

        // Palette
        'palette_title'    => 'CMDB classes',
        'palette_hint'     => 'drag to canvas',
        'palette_loading'  => 'Loading classes…',
        'palette_load_failed' => 'Failed to load classes: {message}',
        'palette_empty'    => 'No CMDB classes defined yet. <a href="../cmdb/settings/">Create one</a> to start dragging objects onto the diagram.',
        'palette_tile_title' => 'Drag onto the canvas',
        'palette_object'   => '{count} object',
        'palette_objects'  => '{count} objects',

        // Canvas empty state
        'canvas_empty_heading' => 'Empty diagram',
        'canvas_empty_body'    => "Drag a class from the palette onto the canvas to start placing nodes. You'll be asked which CMDB object to bind it to.",

        // Present mode
        'present_exit'     => 'Exit Present',
        'present_exit_title' => 'Exit Present mode (Esc)',

        // Read-only titles applied to gated buttons
        'readonly_save_title'    => 'This is a historical version — read-only',
        'readonly_fork_title'    => 'Only the current version can be forked into a new version',
        'readonly_generic_title' => 'Historical versions are read-only',
    ],

    // Node detail panel
    'detail' => [
        'node'             => 'Node',
        'class'            => 'Class',
        'class_value_dash' => '—',
        'status'           => 'Status',
        'planned_pill'     => 'PLANNED',
        'planned_future'   => 'Future state',
        'cmdb'             => 'CMDB',
        'cmdb_open'        => 'Open in CMDB →',
        'icon'             => 'Icon',
        'icon_change'      => 'Change',
        'icon_change_title'=> 'Pick a different icon for this node',
        'icon_reset'       => 'Reset',
        'icon_reset_title' => 'Use the class default icon',
        'properties'       => 'Properties',
        'properties_from'  => 'from CMDB',
        'properties_loading' => 'Loading properties…',
        'properties_load_failed' => 'Could not load properties: {message}',
        'properties_empty' => 'No property values set on this object.',
        'add_related'      => 'Add related objects',
        'add_related_title'=> 'Pull in CMDB neighbours of this object',
        'value_dash'       => '—',
        'bool_yes'         => 'Yes',
        'bool_no'          => 'No',
        'ref_open_title'   => 'Open in CMDB',
    ],

    // CMDB object picker (opened on drop)
    'picker' => [
        'title_prefix'     => 'Pick a ',
        'title_default'    => 'CMDB object',
        'title_suffix'     => ' to place',
        'search_ph'        => 'Type to filter…',
        'search_failed'    => 'Failed: {message}',
        'all_in_use'       => 'Every object in this class is already on the diagram.',
        'none_yet'         => 'No objects in this class yet. <a href="../cmdb/" target="_blank">Create one in CMDB →</a>',
        'planned'          => 'PLANNED',
        'in_parent'        => 'in {parent}',
        'cancel'           => 'Cancel',
    ],

    // Icon picker modal
    'iconpicker' => [
        'title'            => 'Pick an icon for {name}',
        'search_ph'        => 'Filter by name (e.g. ‘database’, ‘firewall’)…',
        'no_match'         => 'No icons match “{query}”.',
        'cancel'           => 'Cancel',
    ],

    // Related-objects modal
    'related' => [
        'title'            => 'Add objects related to {name}',
        'intro'            => 'Tick any to add them to the diagram. Each tick places the object as a new node (auto-laid-out around the source) and draws a connector that mirrors the relationship.',
        'loading'          => 'Loading related objects…',
        'load_failed'      => 'Failed to load: {message}',
        'empty'            => 'No related objects in CMDB yet. Add relationships or object-ref properties on the source object in CMDB, then come back.',
        'group_outgoing'   => 'This object → others',
        'group_incoming'   => 'Others → this object',
        'group_property'   => 'Referenced by properties',
        'planned'          => 'PLANNED',
        'on_canvas'        => 'on canvas',
        'cancel'           => 'Cancel',
        'add'              => 'Add',
        'add_one'          => 'Add {count} object',
        'add_many'         => 'Add {count} objects',
        'save_first'       => 'Save the diagram first so this node has a stable id',
        'placed_one'       => '{count} object added',
        'placed_many'      => '{count} objects added',
        'placed_none'      => 'No new objects placed',
        'connector_one'    => '{count} connector',
        'connector_many'   => '{count} connectors',
        'result_combined'  => '{placed} · {connectors}',
    ],

    // Versions dropdown
    'versions' => [
        'loading'          => 'Loading version history…',
        'load_failed'      => 'Failed to load: {message}',
        'empty'            => 'No version history yet.',
        'viewing_current'  => 'Viewing · current',
        'viewing'          => 'Viewing',
        'current'          => 'Current',
        'readonly'         => 'Read-only',
        'author_unknown'   => 'Unknown',
    ],

    // Page-size dropdown
    'page' => [
        'off'              => 'Off',
        'off_meta'         => 'No page outline shown',
        'current'          => 'Current',
        'row_label'        => '{label} {orient}',
        'orient_landscape' => 'landscape',
        'orient_portrait'  => 'portrait',
        'readonly'         => 'Historical versions are read-only',
    ],

    // Branding modal
    'branding' => [
        'title'            => 'Diagram branding — header & footer',
        'intro'            => 'Override the organisation-wide header/footer for this diagram only. Placeholders show the default values that would be inherited — clear a slot and Save to <em>explicitly</em> blank it, or click <strong>Reset</strong> to clear all overrides and inherit the org-wide defaults configured in <a href="../system/branding/" target="_blank">System › Branding</a>.',
        'col_left'         => 'Left',
        'col_center'       => 'Centre',
        'col_right'        => 'Right',
        'row_header'       => 'Header',
        'row_footer'       => 'Footer',
        'tokens_label'     => 'Tokens',
        'tokens_intro'     => ' resolved at render time:',
        'tokens_note'      => 'Header/footer only renders when a page outline is set — use the <strong>Page</strong> dropdown to pick one.',
        'reset'            => 'Reset',
        'reset_title'      => 'Clear all overrides — slots will inherit the org-wide defaults',
        'cancel'           => 'Cancel',
        'save'             => 'Save',
        'blank_default'    => '(blank by default)',
        'readonly'         => 'Historical versions are read-only',
    ],

    // Save-as-new-version modal
    'newversion' => [
        'title'            => 'Save as new version',
        'intro'            => 'Clones the current diagram (nodes, connectors, metadata) forward into a new editable version. The current version becomes a read-only historical record.',
        'field_title'      => 'Title *',
        'field_description' => 'Description',
        'field_version'    => 'Version label',
        'field_version_ph' => 'v2',
        'field_version_help' => 'Free text — e.g. “v2”, “Q2 baseline”, “Post-migration”.',
        'cancel'           => 'Cancel',
        'create'           => 'Create version',
        'only_current'     => 'Only the current version can be forked',
        'saving_first'     => 'Saving pending changes first…',
        'title_required'   => 'Title is required',
        'create_failed'    => 'Failed: {message}',
    ],

    // Save status indicator + save toasts
    'status' => [
        'unsaved'          => 'Unsaved',
        'unsaved_changes'  => 'Unsaved changes',
        'saving'           => 'Saving…',
        'saved'            => 'Saved',
        'save_failed'      => 'Save failed —',
        'retry'            => 'retry',
        'autosave_off'     => 'Autosave off',
    ],

    // Toasts (save / export / centre / fit)
    'toast' => [
        'saved'            => 'Saved',
        'save_failed'      => 'Save failed: {message}',
        'png_exported'     => 'PNG exported',
        'pdf_exported'     => 'PDF exported',
        'export_lib_failed'=> 'Export library failed to load — check your network and refresh',
        'pdf_lib_failed'   => 'PDF library failed to load — check your network and refresh',
        'nothing_to_export'=> 'Nothing to export — place some nodes or set a page size first',
        'export_failed'    => 'Export failed: {message}',
        'export_failed_unknown' => 'unknown error',
        'nothing_to_fit'   => 'Nothing to fit — set a page size or place some nodes',
        'centre_no_nodes'  => 'Nothing to centre — place some nodes first',
        'centre_no_page'   => 'Set a page size first (Page dropdown)',
        'centre_too_large' => 'Diagram is too large to centre on this page size — try a larger paper or use Fit + zoom',
        'centre_already'   => 'Diagram is already centred',
        'centred'          => 'Diagram centred on page',
        'readonly'         => 'Historical versions are read-only',
    ],

    // Inline connector label editor
    'connector' => [
        'label_ph'         => 'Label (Enter to save, Esc to cancel)',
    ],

    // Help guide (help.php)
    'help' => [
        'browser_title'    => 'FreeITSM — Network Mapper Guide',
        'sidebar_title'    => 'Guide',
        'hero_title'       => 'Network Mapper guide',
        'hero_subtitle'    => 'Draw your network and architecture diagrams over the top of the CMDB — every box you place is a real object the rest of the platform knows about.',

        'nav_overview'     => 'Overview',
        'nav_creating'     => 'Creating a diagram',
        'nav_placing'      => 'Placing nodes',
        'nav_connectors'   => 'Drawing connectors',
        'nav_related'      => 'Adding related objects',
        'nav_planned'      => 'Planned objects',
        'nav_paper'        => 'Page size guide',
        'nav_branding'     => 'Header & footer',
        'nav_versioning'   => 'Versioning',
        'nav_saving'       => 'Saving',
        'nav_tips'         => 'Quick tips',

        // 1. Overview
        'overview_title'   => 'Overview',
        'overview_body'    => "Network Mapper is a visual layer on top of the CMDB. Each node on the canvas is a binding to a real <code>cmdb_objects</code> row, so the diagram doesn't drift from what the rest of the platform knows about your estate. Move a node, the binding stays. Delete an object in CMDB, the diagram updates. Want a future-state architecture diagram? Mark the objects as planned in CMDB — they'll render with a dashed amber border on the diagram automatically.",
        'flow_create'      => 'Create a diagram',
        'flow_drag'        => 'Drag objects in',
        'flow_connect'     => 'Draw connectors',
        'flow_save'        => 'Save',
        'feat_bound_title' => 'CMDB-bound nodes',
        'feat_bound_body'  => 'Every node references a real CMDB object — click through to its detail page from the side panel.',
        'feat_prov_title'  => 'Provenance-linked connectors',
        'feat_prov_body'   => 'Drawing a connector via Add related objects writes the CMDB relationship id, so the line traces back to a real link.',
        'feat_autosave_title' => 'Autosave + manual save',
        'feat_autosave_body'  => 'Toggle autosave on for ~2-second debounced background saves, or use {ctrl}+{s} any time.',
        'feat_history_title'  => 'Linear version history',
        'feat_history_body'   => 'Save-as-new-version forks the current diagram forward; older versions become read-only historical records.',

        // 2. Creating
        'creating_title'   => 'Creating a diagram',
        'creating_body'    => 'From the Diagrams landing page, hit <strong>+ New diagram</strong>. Give it a title (e.g. <em>Production stack — web tier</em>), an optional description, and a starting version label (default <code>v1</code>). You\'ll land straight in the editor.',
        'creating_tip'     => '<strong>Tip:</strong> Diagrams are intended to be focussed views, not exhaustive maps. One diagram per system, environment, or change is usually the right grain. You can always pull in extra related objects later.',

        // 3. Placing nodes
        'placing_title'    => 'Placing nodes',
        'placing_body'     => 'The left palette lists every active CMDB class with its icon and object count. Drag a class tile onto the canvas, drop opens a picker scoped to that class — type to filter, arrow keys to navigate, Enter to pick. The node lands at the drop coordinates, snapped to the 20-pixel grid, with the chosen object\'s name as the label.',
        'placing_step1'    => 'Drag a class tile from the left palette onto the canvas.',
        'placing_step2'    => 'Type in the picker to filter by name (Up/Down + Enter also work).',
        'placing_step3'    => 'Click an object to place it — the node appears at the drop point.',
        'placing_step4'    => 'Click to select, drag to move, {del} to remove.',
        'placing_tip1'     => '<strong>Already on the canvas?</strong> Objects you\'ve already placed are filtered out of the picker so you can\'t accidentally place the same object twice on one diagram.',
        'placing_tip2'     => '<strong>Per-node icon override:</strong> by default every node uses its CMDB class\'s icon. If you want to distinguish two objects of the same class visually (e.g. "Production MS SQL" vs "Reporting Oracle", both Database Server), select the node, open the detail panel, and click <strong>Change</strong> next to the Icon row — pick from ~65 icons grouped into 12 categories. Reset clears the override and goes back to the class default.',

        // 4. Connectors
        'connectors_title' => 'Drawing connectors',
        'connectors_body'  => 'Hover or select a node — four small cyan dots appear at the edges of the icon. Mousedown on a dot, drag to another node, mouseup to create the connector. A dashed cyan line tracks the cursor while you drag so you can see where it\'ll land.',
        'connectors_step1' => '<strong>Draw:</strong> mousedown on an edge dot → drag to target node → mouseup creates an arrow.',
        'connectors_step2' => '<strong>Select:</strong> click any connector — it turns cyan with a thicker stroke.',
        'connectors_step3' => '<strong>Label:</strong> double-click a connector — an inline text input opens at the midpoint (Enter saves, Esc cancels).',
        'connectors_step4' => '<strong>Delete:</strong> select a connector and press {del}.',
        'connectors_tip'   => '<strong>Direction matters:</strong> arrows point from <em>source</em> to <em>target</em> in the order you drew them. If you want to flip an arrow, delete it and re-draw from the other end.',

        // 5. Related
        'related_title'    => 'Adding related objects',
        'related_body'     => 'This is the killer feature. Click a placed node — the detail panel slides in beside the canvas. Hit <strong>Add related objects</strong> and the modal lists every CMDB object connected to this one across three buckets:',
        'related_out_title'  => 'This object → others',
        'related_out_body'   => 'Outgoing relationships — what this object depends on, hosts, owns, etc.',
        'related_in_title'   => 'Others → this object',
        'related_in_body'    => 'Incoming relationships — what depends on it, what it\'s part of, what hosts it.',
        'related_ref_title'  => 'Referenced by properties',
        'related_ref_body'   => 'Other objects that point at this one via an object-ref property (e.g. "Owner = Jane").',
        'related_commit'   => 'Tick the rows you want, hit <strong>Add</strong>, and the selected objects get placed in a ring around the source node with a connector each. The relationship verb becomes the connector label, and the connector is provenance-linked back to the real CMDB relationship row when applicable.',
        'related_tip1'     => '<strong>Why this matters:</strong> CMDB usually has way more information than fits on one diagram. Add related objects gives you <em>guided exploration</em> — start from one object you care about, and pull in only the neighbours you actually want to show.',
        'related_tip2'     => '<strong>Properties are visible too:</strong> the detail panel shows every CMDB property that has a value on the selected object — type-aware rendering for dates, numbers, dropdowns (with their colour), booleans (Yes/No), object references (pink pill links straight into CMDB), and URL detection in text fields. Empty properties are hidden so the panel stays tight.',

        // 6. Planned
        'planned_title'    => 'Planned objects (future-state architecture)',
        'planned_pill'     => 'PLANNED',
        'planned_body_before' => 'If an object is marked as ',
        'planned_body_after'  => ' in CMDB (i.e. it\'s part of your future-state architecture but not yet real), it renders on the diagram with a dashed amber border, an italic amber label, and a small PLANNED pill above the icon. This turns any diagram into a visual as-is/to-be map without needing two separate diagrams.',
        'planned_tip'      => '<strong>Workflow:</strong> mark CMDB objects as planned during design, draw them into the diagram alongside your real estate, then flip the planned flag off in CMDB when they go live — the diagram styling updates on its next load. No edits to the diagram needed.',

        // 7. Paper
        'paper_title'      => 'Page size guide',
        'paper_body'       => 'Use the <strong>Page</strong> dropdown in the editor toolbar to overlay a paper outline on the canvas (A4, A3, A2, Letter, or Tabloid — portrait or landscape). Anything inside the dashed cyan box will print or export cleanly; anything outside gets cropped or scrolled past. Useful as a layout guide before sharing or screenshotting the diagram. Default is <strong>Off</strong> — no overlay shown.',
        'paper_tip1'       => '<strong>Per-diagram setting:</strong> each diagram remembers its own paper size, so a service map can use A3 landscape while a small workflow diagram uses A4 portrait without any setup each time. The setting is also carried forward when you save as a new version — you don\'t need to re-pick.',
        'paper_tip2'       => '<strong>Why not just export at the right size?</strong> Picking it up front means you can compose the diagram inside the printable area as you go — no surprise crops after the fact. PNG / PDF export will use this outline as the bounds when added in a future release.',

        // 8. Branding
        'branding_title'   => 'Header & footer',
        'branding_body'    => 'Render the company logo, document title, author, version, and modified date along the top and bottom of the page outline — the same six slots you\'d configure in Word\'s header and footer (left / centre / right, top and bottom). Each slot is free text that can mix in template tokens which get resolved at render time.',
        'branding_step1'   => 'Set up the org-wide defaults once at <strong>System › Branding</strong> — upload your company logo and decide what each of the 6 slots should contain. Every diagram inherits these by default.',
        'branding_step2'   => 'On any individual diagram, click <strong>Branding</strong> in the editor toolbar to override one or more slots for that diagram only. The modal\'s input placeholders show what each slot would inherit from the org default, so you can see what you\'re overriding.',
        'branding_step3'   => '<strong>Reset</strong> in the modal clears all overrides on this diagram and re-inherits the org-wide defaults.',
        'branding_tip1'    => '<strong>Available tokens:</strong> <code>{{logo}}</code> (the uploaded company logo), <code>{{title}}</code>, <code>{{author}}</code>, <code>{{version}}</code>, and <code>{{modified}}</code>. Mix tokens with plain text — e.g. <code>Author: {{author}}</code> renders as <em>Author: Ed Mozley</em>.',
        'branding_tip2'    => '<strong>Page outline required:</strong> the header/footer only renders when a paper size is set via the <strong>Page</strong> dropdown — the outline gives the overlay its anchor points. Turn the page off and the branding hides too.',
        'branding_tip3'    => '<strong>Empty vs inherit:</strong> a blank slot in the modal is an <em>explicit</em> blank (overrides the org default with nothing). To go back to inheriting, click Reset.',

        // 9. Versioning
        'versioning_title' => 'Versioning',
        'versioning_body_before' => 'Every diagram is part of a linear version chain. The leaf (no children) is the editable ',
        'versioning_pill_current' => 'v? (current)',
        'versioning_body_mid'     => ' version; older nodes in the chain are read-only history ',
        'versioning_pill_readonly'=> 'v? (read-only)',
        'versioning_body_after'   => '. Saving as a new version clones the current state forward into a new editable leaf and demotes the old leaf to historical.',
        'versioning_step1' => 'Edit the current version freely — changes save in place via the Save button or autosave.',
        'versioning_step2' => 'When you want a snapshot, click <strong>Save as new version</strong> — the old state becomes the historical record, you continue on the new leaf.',
        'versioning_step3' => 'Historical versions open read-only — click any node or connector to inspect, but you can\'t modify them.',
        'versioning_warn'  => '<strong>No branching:</strong> a parent can have at most one child in the chain — the history is strictly linear. If you need to explore an alternative architecture, create a separate diagram rather than forking the chain.',

        // 10. Saving
        'saving_title'     => 'Saving',
        'saving_body'      => 'Two modes. <strong>Autosave</strong> (toggle in the toolbar) saves around 2 seconds after your last edit — the Word-style status indicator next to the toggle shows <em>Unsaved</em>, <em>Saving…</em>, then <em>Saved</em>. Toggle state is remembered per analyst. <strong>Manual save</strong> via the Save button or {ctrl}+{s} works in either mode.',
        'saving_tip'       => '<strong>Mid-drag is safe:</strong> autosave defers if you\'re dragging a node, so the diagram doesn\'t snap back to its last-saved position underneath you.',
        'saving_warn'      => '<strong>Unsaved changes:</strong> if you try to navigate away with unsaved edits, the browser will prompt you. Don\'t ignore that prompt unless you really mean to discard.',

        // 11. Quick tips
        'tips_title'       => 'Quick tips',
        'tip_ctrls'        => '<strong>Ctrl+S</strong> saves regardless of autosave state.',
        'tip_esc'          => '<strong>Esc</strong> closes any open modal (picker, related-objects, save-as-version) and the detail panel.',
        'tip_deselect'     => 'Click the empty canvas to deselect — closes the detail panel too.',
        'tip_track'        => 'Move the source node and connectors track its new position live.',
        'tip_dedupe'       => 'The picker filters out objects already on the canvas so you can\'t double-place.',
        'tip_cmdblink'     => 'Click the CMDB link in the detail panel to open the object\'s full page in a new tab.',
    ],
];
