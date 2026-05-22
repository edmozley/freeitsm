<?php
/**
 * தமிழ் (ta) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'செயல்முறை வரைவி',

    'nav' => [
        'processes' => 'செயல்முறைகள்',
        'help'      => 'உதவி',
    ],

    'sidebar' => [
        'new_process'        => '+ புதிய செயல்முறை',
        'search_placeholder' => 'செயல்முறைகளைத் தேடு…',
        'no_processes_yet'   => 'இன்னும் செயல்முறைகள் இல்லை',
    ],

    'toolbar' => [
        'process'   => 'செயல்முறை',
        'decision'  => 'முடிவு',
        'terminal'  => 'தொடக்கம்/முடிவு',
        'document'  => 'ஆவணம்',
        'connect'   => 'இணை',
        'group'     => 'குழு',
        'lane'      => 'பாதை',
        'export'    => 'ஏற்றுமதி',
        'save'      => 'சேமி',
    ],

    'context' => [
        'create_new' => 'புதிதாக உருவாக்கு…',
    ],

    'autosave' => [
        'label'   => 'தானியங்கி சேமிப்பு',
        'saved'   => 'சேமிக்கப்பட்டது',
        'unsaved' => 'சேமிக்கப்படவில்லை',
        'unsaved_changes' => 'சேமிக்கப்படாத மாற்றங்கள்',
        'saving'  => 'சேமிக்கப்படுகிறது…',
        'failed'  => 'சேமிப்பு தோல்வி —',
        'retry'   => 'மீண்டும் முயற்சி',
        'off'     => 'தானியங்கி சேமிப்பு முடக்கப்பட்டது',
        'tooltip' => 'திருத்தம் நிறுத்தப்பட்ட சில விநாடிகளில் தானாக சேமிக்கிறது',
    ],

    'detail' => [
        'step_title'   => 'படி விவரங்கள்',
        'group_title'  => 'குழு விவரங்கள்',
        'lane_title'   => 'பாதை விவரங்கள்',
        'label'        => 'லேபிள்',
        'type'         => 'வகை',
        'colour'       => 'நிறம்',
        'gradient'     => 'சாய்வு',
        'description'  => 'விளக்கம்',
        'position'     => 'நிலை',
        'size'         => 'அளவு',
        'height'       => 'உயரம்',
        'order'        => 'வரிசை (மேலிருந்து கீழே)',
        'connectors'   => 'இணைப்புகள்',
        'no_connectors'=> 'இணைப்புகள் இல்லை',
        'step_type' => [
            'process'  => 'செயல்முறை',
            'decision' => 'முடிவு',
            'terminal' => 'தொடக்கம்/முடிவு',
            'document' => 'ஆவணம்',
        ],
        'step_description_placeholder' => 'இந்த படியைப் பற்றி குறிப்புகள் சேர்க்கவும்…',
        'lane_label_placeholder'       => 'எ.கா. HR / IT / விற்பனையாளர்',
        'group_label_placeholder'      => 'எ.கா. தீர்வு கட்டம்',
        'lane_hint'                    => 'மறுவரிசைப்படுத்த பாதையின் இடது விளிம்பு தலைப்பை இழுக்கவும். அளவை மாற்ற கீழ் விளிம்பை இழுக்கவும். இந்த பாதைக்கு ஒரு படியை ஒதுக்க, பாதையில் விடவும்.',
    ],

    'export_modal' => [
        'title'  => 'ஏற்றுமதி — Mermaid பாய்வு வரைபடம்',
        'hint'   => 'Mermaid-ஐ ஆதரிக்கும் எந்த Markdown திருத்தியிலும் இந்தக் குறியீட்டை ஒட்டவும் (GitHub, GitLab, Notion, Confluence, Obsidian…). பாதைகள் <code>subgraph</code> தொகுதிகளாக மாறும்; தானியங்கி அமைப்பு உங்கள் கையால் வைத்த நிலைகளை மாற்றுகிறது.',
        'copy'   => 'நகலெடு',
        'copied' => 'நகலெடுக்கப்பட்டது ✓',
        'close'  => 'மூடு',
    ],

    'toast' => [
        'no_process_open' => 'முதலில் ஒரு செயல்முறையைத் திறக்கவும் அல்லது உருவாக்கவும்',
        'saved'           => 'சேமிக்கப்பட்டது',
        'save_failed'     => 'சேமிக்க முடியவில்லை',
    ],
];
