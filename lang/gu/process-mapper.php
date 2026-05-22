<?php
/**
 * ગુજરાતી (gu) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'પ્રક્રિયા મેપર',

    'nav' => [
        'processes' => 'પ્રક્રિયાઓ',
        'help'      => 'મદદ',
    ],

    'sidebar' => [
        'new_process'        => '+ નવી પ્રક્રિયા',
        'search_placeholder' => 'પ્રક્રિયાઓ શોધો…',
        'no_processes_yet'   => 'હજુ સુધી કોઈ પ્રક્રિયા નથી',
    ],

    'toolbar' => [
        'process'   => 'પ્રક્રિયા',
        'decision'  => 'નિર્ણય',
        'terminal'  => 'શરૂઆત/અંત',
        'document'  => 'દસ્તાવેજ',
        'connect'   => 'જોડો',
        'group'     => 'જૂથ',
        'lane'      => 'લેન',
        'export'    => 'નિકાસ',
        'save'      => 'સાચવો',
    ],

    'context' => [
        'create_new' => 'નવું બનાવો…',
    ],

    'autosave' => [
        'label'   => 'ઑટો-સેવ',
        'saved'   => 'સાચવ્યું',
        'unsaved' => 'સાચવ્યું નથી',
        'unsaved_changes' => 'સાચવ્યા વગરના ફેરફારો',
        'saving'  => 'સાચવી રહ્યું છે…',
        'failed'  => 'સાચવવામાં નિષ્ફળ —',
        'retry'   => 'ફરી પ્રયાસ',
        'off'     => 'ઑટો-સેવ બંધ',
        'tooltip' => 'સંપાદન બંધ કર્યાની થોડી સેકન્ડ પછી આપમેળે સાચવે છે',
    ],

    'detail' => [
        'step_title'   => 'પગલાંની વિગતો',
        'group_title'  => 'જૂથની વિગતો',
        'lane_title'   => 'લેનની વિગતો',
        'label'        => 'લેબલ',
        'type'         => 'પ્રકાર',
        'colour'       => 'રંગ',
        'gradient'     => 'ગ્રેડિયન્ટ',
        'description'  => 'વર્ણન',
        'position'     => 'સ્થાન',
        'size'         => 'કદ',
        'height'       => 'ઊંચાઈ',
        'order'        => 'ક્રમ (ઉપરથી નીચે)',
        'connectors'   => 'કનેક્ટર્સ',
        'no_connectors'=> 'કોઈ કનેક્ટર્સ નથી',
        'step_type' => [
            'process'  => 'પ્રક્રિયા',
            'decision' => 'નિર્ણય',
            'terminal' => 'શરૂઆત/અંત',
            'document' => 'દસ્તાવેજ',
        ],
        'step_description_placeholder' => 'આ પગલાં વિશે નોંધો ઉમેરો…',
        'lane_label_placeholder'       => 'દા.ત. HR / IT / સપ્લાયર',
        'group_label_placeholder'      => 'દા.ત. નિરાકરણ તબક્કો',
        'lane_hint'                    => 'ફરીથી ગોઠવવા માટે લેનની ડાબી-કિનારી હેડર ખેંચો. કદ બદલવા માટે નીચેની ધાર ખેંચો. આ લેનને સોંપવા માટે બેન્ડમાં એક પગલું છોડો.',
    ],

    'export_modal' => [
        'title'  => 'નિકાસ — Mermaid ફ્લોચાર્ટ',
        'hint'   => 'Mermaid ને સપોર્ટ કરતા કોઈપણ Markdown એડિટરમાં આ માર્કઅપ પેસ્ટ કરો (GitHub, GitLab, Notion, Confluence, Obsidian…). લેન <code>subgraph</code> બ્લોક્સ બને છે; ઑટો-લેઆઉટ તમે હાથથી ગોઠવેલા સ્થાનોની જગ્યાએ આવે છે.',
        'copy'   => 'નકલ',
        'copied' => 'નકલ થઈ ✓',
        'close'  => 'બંધ',
    ],

    'toast' => [
        'no_process_open' => 'પહેલા પ્રક્રિયા ખોલો અથવા બનાવો',
        'saved'           => 'સાચવ્યું',
        'save_failed'     => 'સાચવવામાં નિષ્ફળ',
    ],
];
