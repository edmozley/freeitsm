<?php
/**
 * Afrikaans (af) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Process Mapper',

    'nav' => [
        'processes' => 'Prosesse',
        'help'      => 'Hulp',
    ],

    'sidebar' => [
        'new_process'        => '+ Nuwe proses',
        'search_placeholder' => 'Soek prosesse...',
        'no_processes_yet'   => 'Nog geen prosesse nie',
    ],

    'toolbar' => [
        'process'   => 'Proses',
        'decision'  => 'Besluit',
        'terminal'  => 'Begin/Einde',
        'document'  => 'Dokument',
        'connect'   => 'Verbind',
        'group'     => 'Groep',
        'lane'      => 'Baan',
        'export'    => 'Voer uit',
        'save'      => 'Stoor',
    ],

    'context' => [
        'create_new' => 'Skep nuwe…',
    ],

    'autosave' => [
        'label'   => 'Outostoor',
        'saved'   => 'Gestoor',
        'unsaved' => 'Nie gestoor nie',
        'unsaved_changes' => 'Ongestoorde veranderinge',
        'saving'  => 'Besig om te stoor...',
        'failed'  => 'Stoor het misluk —',
        'retry'   => 'probeer weer',
        'off'     => 'Outostoor af',
        'tooltip' => 'Stoor outomaties \'n paar sekondes nadat u ophou wysig het',
    ],

    'detail' => [
        'step_title'   => 'Stapbesonderhede',
        'group_title'  => 'Groepbesonderhede',
        'lane_title'   => 'Baanbesonderhede',
        'label'        => 'Etiket',
        'type'         => 'Tipe',
        'colour'       => 'Kleur',
        'gradient'     => 'Gradiënt',
        'description'  => 'Beskrywing',
        'position'     => 'Posisie',
        'size'         => 'Grootte',
        'height'       => 'Hoogte',
        'order'        => 'Volgorde (bo na onder)',
        'connectors'   => 'Verbindings',
        'no_connectors'=> 'Geen verbindings nie',
        'step_type' => [
            'process'  => 'Proses',
            'decision' => 'Besluit',
            'terminal' => 'Terminaal (Begin/Einde)',
            'document' => 'Dokument',
        ],
        'step_description_placeholder' => 'Voeg notas oor hierdie stap by...',
        'lane_label_placeholder'       => 'bv. MH / IT / Verskaffer',
        'group_label_placeholder'      => 'bv. Oplossingsfase',
        'lane_hint'                    => 'Sleep die baan se linkerkantopskrif om te herrangskik. Sleep die onderkant om die grootte te verander. Laat val \'n stap binne-in die baan om dit aan hierdie baan toe te wys.',
    ],

    'export_modal' => [
        'title'  => 'Voer uit — Mermaid-vloeidiagram',
        'hint'   => 'Plak hierdie opmaak in enige Markdown-redigeerder wat Mermaid ondersteun (GitHub, GitLab, Notion, Confluence, Obsidian...). Bane word <code>subgraph</code>-blokke; outo-uitleg neem oor by u handgeplaaste posisies.',
        'copy'   => 'Kopieer',
        'copied' => 'Gekopieer ✓',
        'close'  => 'Sluit',
    ],

    'toast' => [
        'no_process_open' => 'Maak eers \'n proses oop of skep een',
        'saved'           => 'Gestoor',
        'save_failed'     => 'Stoor het misluk',
    ],
];
