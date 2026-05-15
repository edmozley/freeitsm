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
