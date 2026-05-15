<?php
/**
 * Nederlands (nl) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Processchema',

    'toolbar' => [
        'process'   => 'Proces',
        'decision'  => 'Beslissing',
        'terminal'  => 'Start/Einde',
        'document'  => 'Document',
        'connect'   => 'Verbinden',
        'group'     => 'Groep',
        'lane'      => 'Baan',
        'export'    => 'Exporteren',
        'save'      => 'Opslaan',
    ],

    'autosave' => [
        'label'   => 'Automatisch opslaan',
        'saved'   => 'Opgeslagen',
        'unsaved' => 'Niet opgeslagen',
        'unsaved_changes' => 'Niet-opgeslagen wijzigingen',
        'saving'  => 'Opslaan…',
        'failed'  => 'Opslaan mislukt —',
        'retry'   => 'opnieuw proberen',
        'off'     => 'Automatisch opslaan uit',
        'tooltip' => 'Slaat automatisch op enkele seconden na de laatste wijziging',
    ],

    'detail' => [
        'step_title'   => 'Stapdetails',
        'group_title'  => 'Groepsdetails',
        'lane_title'   => 'Baandetails',
        'label'        => 'Label',
        'type'         => 'Type',
        'colour'       => 'Kleur',
        'gradient'     => 'Verloop',
        'description'  => 'Beschrijving',
        'position'     => 'Positie',
        'size'         => 'Grootte',
        'height'       => 'Hoogte',
        'order'        => 'Volgorde (boven naar beneden)',
        'connectors'   => 'Verbindingen',
        'no_connectors'=> 'Geen verbindingen',
    ],

    'export_modal' => [
        'title'  => 'Exporteren — Mermaid-stroomschema',
        'hint'   => 'Plak deze code in een willekeurige Markdown-editor die Mermaid ondersteunt (GitHub, GitLab, Notion, Confluence, Obsidian…). Banen worden <code>subgraph</code>-blokken; de automatische lay-out vervangt uw handmatige posities.',
        'copy'   => 'Kopiëren',
        'copied' => 'Gekopieerd ✓',
        'close'  => 'Sluiten',
    ],

    'toast' => [
        'no_process_open' => 'Open of maak eerst een proces',
        'saved'           => 'Opgeslagen',
        'save_failed'     => 'Opslaan mislukt',
    ],
];
