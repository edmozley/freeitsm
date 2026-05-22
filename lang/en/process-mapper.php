<?php
/**
 * English (en) — Process Mapper module strings.
 *
 * Used as the pilot module for the i18n rollout (phase 1). Keys here cover the
 * toolbar, autosave status indicator, detail panel labels, and Mermaid export
 * modal — about 30 strings total. The rest of the module's strings (toasts,
 * confirmations, dynamic content) will be swept through in a later pass.
 */
return [
    'title' => 'Process Mapper',

    'nav' => [
        'processes' => 'Processes',
        'help'      => 'Help',
    ],

    'sidebar' => [
        'new_process'        => '+ New Process',
        'search_placeholder' => 'Search processes...',
        'no_processes_yet'   => 'No processes yet',
    ],

    'toolbar' => [
        'process'   => 'Process',
        'decision'  => 'Decision',
        'terminal'  => 'Terminal',
        'document'  => 'Document',
        'connect'   => 'Connect',
        'group'     => 'Group',
        'lane'      => 'Lane',
        'export'    => 'Export',
        'save'      => 'Save',
    ],

    'context' => [
        'create_new' => 'Create new…',
    ],

    'autosave' => [
        'label'   => 'Autosave',
        'saved'   => 'Saved',
        'unsaved' => 'Unsaved',
        'unsaved_changes' => 'Unsaved changes',
        'saving'  => 'Saving…',
        'failed'  => 'Save failed —',
        'retry'   => 'retry',
        'off'     => 'Autosave off',
        'tooltip' => 'Auto-save every couple of seconds after you stop editing',
    ],

    'detail' => [
        'step_title'   => 'Step Details',
        'group_title'  => 'Group Details',
        'lane_title'   => 'Lane Details',
        'label'        => 'Label',
        'type'         => 'Type',
        'colour'       => 'Colour',
        'gradient'     => 'Gradient',
        'description'  => 'Description',
        'position'     => 'Position',
        'size'         => 'Size',
        'height'       => 'Height',
        'order'        => 'Order (top to bottom)',
        'connectors'   => 'Connectors',
        'no_connectors'=> 'No connectors',
        'step_type' => [
            'process'  => 'Process',
            'decision' => 'Decision',
            'terminal' => 'Terminal (Start/End)',
            'document' => 'Document',
        ],
        'step_description_placeholder' => 'Add notes about this step...',
        'lane_label_placeholder'       => 'e.g. HR / IT / Vendor',
        'group_label_placeholder'      => 'e.g. Resolution phase',
        'lane_hint'                    => 'Drag the lane\'s left-edge header to reorder. Drag the bottom edge to resize. Drop a step into the band to assign it to this lane.',
    ],

    'export_modal' => [
        'title'  => 'Export — Mermaid flowchart',
        'hint'   => 'Paste this markup into any Markdown editor that supports Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Lanes become <code>subgraph</code> blocks; auto-layout takes over from your hand-placed positions.',
        'copy'   => 'Copy',
        'copied' => 'Copied ✓',
        'close'  => 'Close',
    ],

    'toast' => [
        'no_process_open' => 'Open or create a process first',
        'saved'           => 'Saved',
        'save_failed'     => 'Failed to save',
    ],
];
