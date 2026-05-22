<?php
/**
 * ਪੰਜਾਬੀ (pa) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'ਪ੍ਰਕਿਰਿਆ ਮੈਪਰ',

    'nav' => [
        'processes' => 'ਪ੍ਰਕਿਰਿਆਵਾਂ',
        'help'      => 'ਮਦਦ',
    ],

    'sidebar' => [
        'new_process'        => '+ ਨਵੀਂ ਪ੍ਰਕਿਰਿਆ',
        'search_placeholder' => 'ਪ੍ਰਕਿਰਿਆਵਾਂ ਖੋਜੋ…',
        'no_processes_yet'   => 'ਅਜੇ ਤੱਕ ਕੋਈ ਪ੍ਰਕਿਰਿਆ ਨਹੀਂ',
    ],

    'toolbar' => [
        'process'   => 'ਪ੍ਰਕਿਰਿਆ',
        'decision'  => 'ਫੈਸਲਾ',
        'terminal'  => 'ਸ਼ੁਰੂ/ਅੰਤ',
        'document'  => 'ਦਸਤਾਵੇਜ਼',
        'connect'   => 'ਜੋੜੋ',
        'group'     => 'ਸਮੂਹ',
        'lane'      => 'ਲੇਨ',
        'export'    => 'ਨਿਰਯਾਤ',
        'save'      => 'ਸੰਭਾਲੋ',
    ],

    'context' => [
        'create_new' => 'ਨਵਾਂ ਬਣਾਓ…',
    ],

    'autosave' => [
        'label'   => 'ਆਟੋ-ਸੇਵ',
        'saved'   => 'ਸੰਭਾਲਿਆ ਗਿਆ',
        'unsaved' => 'ਸੰਭਾਲਿਆ ਨਹੀਂ',
        'unsaved_changes' => 'ਅਣਸੰਭਾਲੀਆਂ ਤਬਦੀਲੀਆਂ',
        'saving'  => 'ਸੰਭਾਲ ਰਿਹਾ ਹੈ…',
        'failed'  => 'ਸੰਭਾਲਣਾ ਅਸਫਲ —',
        'retry'   => 'ਮੁੜ ਕੋਸ਼ਿਸ਼',
        'off'     => 'ਆਟੋ-ਸੇਵ ਬੰਦ',
        'tooltip' => 'ਸੋਧ ਬੰਦ ਕਰਨ ਦੇ ਕੁਝ ਸਕਿੰਟਾਂ ਬਾਅਦ ਆਪਣੇ ਆਪ ਸੰਭਾਲਦਾ ਹੈ',
    ],

    'detail' => [
        'step_title'   => 'ਕਦਮ ਦੇ ਵੇਰਵੇ',
        'group_title'  => 'ਸਮੂਹ ਦੇ ਵੇਰਵੇ',
        'lane_title'   => 'ਲੇਨ ਦੇ ਵੇਰਵੇ',
        'label'        => 'ਲੇਬਲ',
        'type'         => 'ਕਿਸਮ',
        'colour'       => 'ਰੰਗ',
        'gradient'     => 'ਗ੍ਰੇਡੀਐਂਟ',
        'description'  => 'ਵੇਰਵਾ',
        'position'     => 'ਸਥਿਤੀ',
        'size'         => 'ਆਕਾਰ',
        'height'       => 'ਉਚਾਈ',
        'order'        => 'ਕ੍ਰਮ (ਉੱਪਰ ਤੋਂ ਹੇਠਾਂ)',
        'connectors'   => 'ਕਨੈਕਟਰ',
        'no_connectors'=> 'ਕੋਈ ਕਨੈਕਟਰ ਨਹੀਂ',
        'step_type' => [
            'process'  => 'ਪ੍ਰਕਿਰਿਆ',
            'decision' => 'ਫੈਸਲਾ',
            'terminal' => 'ਸ਼ੁਰੂ/ਅੰਤ',
            'document' => 'ਦਸਤਾਵੇਜ਼',
        ],
        'step_description_placeholder' => 'ਇਸ ਕਦਮ ਬਾਰੇ ਨੋਟ ਜੋੜੋ…',
        'lane_label_placeholder'       => 'ਜਿਵੇਂ HR / IT / ਵਿਕਰੇਤਾ',
        'group_label_placeholder'      => 'ਜਿਵੇਂ ਹੱਲ ਪੜਾਅ',
        'lane_hint'                    => 'ਮੁੜ-ਕ੍ਰਮਬੱਧ ਕਰਨ ਲਈ ਲੇਨ ਦੇ ਖੱਬੇ-ਕਿਨਾਰੇ ਦੀ ਸਿਰਲੇਖ ਨੂੰ ਖਿੱਚੋ। ਆਕਾਰ ਬਦਲਣ ਲਈ ਹੇਠਲੇ ਕਿਨਾਰੇ ਨੂੰ ਖਿੱਚੋ। ਇਸ ਲੇਨ ਨੂੰ ਸੌਂਪਣ ਲਈ ਬੈਂਡ ਵਿੱਚ ਇੱਕ ਕਦਮ ਛੱਡੋ।',
    ],

    'export_modal' => [
        'title'  => 'ਨਿਰਯਾਤ — Mermaid ਫਲੋਚਾਰਟ',
        'hint'   => 'Mermaid ਦਾ ਸਮਰਥਨ ਕਰਨ ਵਾਲੇ ਕਿਸੇ ਵੀ Markdown ਸੰਪਾਦਕ ਵਿੱਚ ਇਹ ਮਾਰਕਅੱਪ ਪੇਸਟ ਕਰੋ (GitHub, GitLab, Notion, Confluence, Obsidian…)। ਲੇਨ <code>subgraph</code> ਬਲਾਕ ਬਣ ਜਾਂਦੇ ਹਨ; ਆਟੋ-ਲੇਆਉਟ ਤੁਹਾਡੇ ਹੱਥ ਨਾਲ ਰੱਖੇ ਸਥਾਨਾਂ ਨੂੰ ਬਦਲਦਾ ਹੈ।',
        'copy'   => 'ਨਕਲ ਕਰੋ',
        'copied' => 'ਨਕਲ ਕੀਤੀ ਗਈ ✓',
        'close'  => 'ਬੰਦ ਕਰੋ',
    ],

    'toast' => [
        'no_process_open' => 'ਪਹਿਲਾਂ ਇੱਕ ਪ੍ਰਕਿਰਿਆ ਖੋਲ੍ਹੋ ਜਾਂ ਬਣਾਓ',
        'saved'           => 'ਸੰਭਾਲਿਆ ਗਿਆ',
        'save_failed'     => 'ਸੰਭਾਲਣਾ ਅਸਫਲ',
    ],
];
