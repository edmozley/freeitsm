<?php
/**
 * മലയാളം (ml) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'പ്രക്രിയ മാപ്പർ',

    'nav' => [
        'processes' => 'പ്രക്രിയകൾ',
        'help'      => 'സഹായം',
    ],

    'sidebar' => [
        'new_process'        => '+ പുതിയ പ്രക്രിയ',
        'search_placeholder' => 'പ്രക്രിയകൾ തിരയുക…',
        'no_processes_yet'   => 'ഇതുവരെ പ്രക്രിയകളില്ല',
    ],

    'toolbar' => [
        'process'   => 'പ്രക്രിയ',
        'decision'  => 'തീരുമാനം',
        'terminal'  => 'തുടക്കം/അവസാനം',
        'document'  => 'പ്രമാണം',
        'connect'   => 'ബന്ധിപ്പിക്കുക',
        'group'     => 'ഗ്രൂപ്പ്',
        'lane'      => 'ലെയ്ൻ',
        'export'    => 'കയറ്റുമതി',
        'save'      => 'സംരക്ഷിക്കുക',
    ],

    'context' => [
        'create_new' => 'പുതിയത് സൃഷ്ടിക്കുക…',
    ],

    'autosave' => [
        'label'   => 'ഓട്ടോ-സേവ്',
        'saved'   => 'സംരക്ഷിച്ചു',
        'unsaved' => 'സംരക്ഷിച്ചിട്ടില്ല',
        'unsaved_changes' => 'സംരക്ഷിക്കാത്ത മാറ്റങ്ങൾ',
        'saving'  => 'സംരക്ഷിക്കുന്നു…',
        'failed'  => 'സംരക്ഷണം പരാജയപ്പെട്ടു —',
        'retry'   => 'വീണ്ടും ശ്രമിക്കുക',
        'off'     => 'ഓട്ടോ-സേവ് ഓഫ്',
        'tooltip' => 'എഡിറ്റിംഗ് നിർത്തിയ ഏതാനും സെക്കൻഡുകൾക്ക് ശേഷം സ്വയം സംരക്ഷിക്കുന്നു',
    ],

    'detail' => [
        'step_title'   => 'ഘട്ടത്തിന്റെ വിശദാംശങ്ങൾ',
        'group_title'  => 'ഗ്രൂപ്പിന്റെ വിശദാംശങ്ങൾ',
        'lane_title'   => 'ലെയ്നിന്റെ വിശദാംശങ്ങൾ',
        'label'        => 'ലേബൽ',
        'type'         => 'തരം',
        'colour'       => 'നിറം',
        'gradient'     => 'ഗ്രേഡിയന്റ്',
        'description'  => 'വിവരണം',
        'position'     => 'സ്ഥാനം',
        'size'         => 'വലുപ്പം',
        'height'       => 'ഉയരം',
        'order'        => 'ക്രമം (മുകളിൽ നിന്ന് താഴേക്ക്)',
        'connectors'   => 'കണക്ടറുകൾ',
        'no_connectors'=> 'കണക്ടറുകൾ ഇല്ല',
        'step_type' => [
            'process'  => 'പ്രക്രിയ',
            'decision' => 'തീരുമാനം',
            'terminal' => 'തുടക്കം/അവസാനം',
            'document' => 'പ്രമാണം',
        ],
        'step_description_placeholder' => 'ഈ ഘട്ടത്തെക്കുറിച്ച് കുറിപ്പുകൾ ചേർക്കുക…',
        'lane_label_placeholder'       => 'ഉദാ. HR / IT / വെണ്ടർ',
        'group_label_placeholder'      => 'ഉദാ. പരിഹാര ഘട്ടം',
        'lane_hint'                    => 'പുനഃക്രമീകരിക്കാൻ ലെയ്നിന്റെ ഇടത് അരിക് തലക്കെട്ട് വലിച്ചിടുക. വലുപ്പം മാറ്റാൻ താഴത്തെ അരിക് വലിച്ചിടുക. ഈ ലെയ്നിലേക്ക് അസൈൻ ചെയ്യാൻ ബാൻഡിൽ ഒരു ഘട്ടം ഇടുക.',
    ],

    'export_modal' => [
        'title'  => 'കയറ്റുമതി — Mermaid ഫ്ലോചാർട്ട്',
        'hint'   => 'Mermaid പിന്തുണയ്ക്കുന്ന ഏതെങ്കിലും Markdown എഡിറ്ററിൽ ഈ മാർക്കപ്പ് ഒട്ടിക്കുക (GitHub, GitLab, Notion, Confluence, Obsidian…). ലെയ്നുകൾ <code>subgraph</code> ബ്ലോക്കുകളായി മാറുന്നു; ഓട്ടോ-ലേഔട്ട് നിങ്ങൾ കൈകൊണ്ട് വച്ച സ്ഥാനങ്ങളെ മാറ്റി പകരം വയ്ക്കുന്നു.',
        'copy'   => 'പകർത്തുക',
        'copied' => 'പകർത്തി ✓',
        'close'  => 'അടയ്ക്കുക',
    ],

    'toast' => [
        'no_process_open' => 'ആദ്യം ഒരു പ്രക്രിയ തുറക്കുകയോ സൃഷ്ടിക്കുകയോ ചെയ്യുക',
        'saved'           => 'സംരക്ഷിച്ചു',
        'save_failed'     => 'സംരക്ഷിക്കാൻ കഴിഞ്ഞില്ല',
    ],
];
