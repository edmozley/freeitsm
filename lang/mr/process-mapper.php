<?php
/**
 * मराठी (mr) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'प्रक्रिया मॅपर',

    'nav' => [
        'processes' => 'प्रक्रिया',
        'help'      => 'मदत',
    ],

    'sidebar' => [
        'new_process'        => '+ नवीन प्रक्रिया',
        'search_placeholder' => 'प्रक्रिया शोधा…',
        'no_processes_yet'   => 'अद्याप कोणत्या प्रक्रिया नाहीत',
    ],

    'toolbar' => [
        'process'   => 'प्रक्रिया',
        'decision'  => 'निर्णय',
        'terminal'  => 'सुरुवात/शेवट',
        'document'  => 'दस्तऐवज',
        'connect'   => 'जोडा',
        'group'     => 'गट',
        'lane'      => 'लेन',
        'export'    => 'निर्यात',
        'save'      => 'जतन करा',
    ],

    'context' => [
        'create_new' => 'नवीन तयार करा…',
    ],

    'autosave' => [
        'label'   => 'स्वयं-जतन',
        'saved'   => 'जतन केले',
        'unsaved' => 'जतन केलेले नाही',
        'unsaved_changes' => 'जतन न केलेले बदल',
        'saving'  => 'जतन करत आहे…',
        'failed'  => 'जतन अयशस्वी —',
        'retry'   => 'पुन्हा प्रयत्न',
        'off'     => 'स्वयं-जतन बंद',
        'tooltip' => 'संपादन थांबल्यानंतर काही सेकंदात आपोआप जतन होते',
    ],

    'detail' => [
        'step_title'   => 'पायरीचे तपशील',
        'group_title'  => 'गटाचे तपशील',
        'lane_title'   => 'लेनचे तपशील',
        'label'        => 'लेबल',
        'type'         => 'प्रकार',
        'colour'       => 'रंग',
        'gradient'     => 'ग्रेडियंट',
        'description'  => 'वर्णन',
        'position'     => 'स्थान',
        'size'         => 'आकार',
        'height'       => 'उंची',
        'order'        => 'क्रम (वरून खाली)',
        'connectors'   => 'कनेक्टर',
        'no_connectors'=> 'कनेक्टर नाहीत',
        'step_type' => [
            'process'  => 'प्रक्रिया',
            'decision' => 'निर्णय',
            'terminal' => 'सुरुवात/शेवट',
            'document' => 'दस्तऐवज',
        ],
        'step_description_placeholder' => 'या पायरीबद्दल टिपा जोडा…',
        'lane_label_placeholder'       => 'उदा. HR / IT / पुरवठादार',
        'group_label_placeholder'      => 'उदा. निराकरण टप्पा',
        'lane_hint'                    => 'पुनर्क्रमित करण्यासाठी लेनचा डावा शीर्षलेख ओढा. आकार बदलण्यासाठी तळाची कडा ओढा. या लेनला नियुक्त करण्यासाठी पट्ट्यात एक पायरी टाका.',
    ],

    'export_modal' => [
        'title'  => 'निर्यात — Mermaid फ्लोचार्ट',
        'hint'   => 'Mermaid समर्थन असलेल्या कोणत्याही Markdown संपादकात हे मार्कअप पेस्ट करा (GitHub, GitLab, Notion, Confluence, Obsidian…). लेन <code>subgraph</code> ब्लॉक बनतात; ऑटो-लेआउट तुम्ही हाताने ठेवलेल्या स्थानांची जागा घेतो.',
        'copy'   => 'कॉपी',
        'copied' => 'कॉपी झाले ✓',
        'close'  => 'बंद',
    ],

    'toast' => [
        'no_process_open' => 'प्रथम एक प्रक्रिया उघडा किंवा तयार करा',
        'saved'           => 'जतन केले',
        'save_failed'     => 'जतन अयशस्वी',
    ],
];
