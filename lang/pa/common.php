<?php
/**
 * ਪੰਜਾਬੀ (pa) — Common shared UI strings.
 * Gurmukhi script (Indian Punjabi). Falls back per-key to lang/en/common.php.
 */
return [
    'save'         => 'ਸੰਭਾਲੋ',
    'cancel'       => 'ਰੱਦ ਕਰੋ',
    'delete'       => 'ਮਿਟਾਓ',
    'add'          => 'ਜੋੜੋ',
    'edit'         => 'ਸੋਧੋ',
    'close'        => 'ਬੰਦ ਕਰੋ',
    'copy'         => 'ਨਕਲ ਕਰੋ',
    'copied'       => 'ਨਕਲ ਕੀਤੀ ਗਈ',
    'retry'        => 'ਮੁੜ ਕੋਸ਼ਿਸ਼',
    'export'       => 'ਨਿਰਯਾਤ',
    'open'         => 'ਖੋਲ੍ਹੋ',
    'apply'        => 'ਲਾਗੂ ਕਰੋ',

    'yes'          => 'ਹਾਂ',
    'no'           => 'ਨਹੀਂ',
    'ok'           => 'ਠੀਕ ਹੈ',
    'loading'      => 'ਲੋਡ ਹੋ ਰਿਹਾ ਹੈ…',
    'saving'       => 'ਸੰਭਾਲ ਰਿਹਾ ਹੈ…',
    'saved'        => 'ਸੰਭਾਲਿਆ ਗਿਆ',
    'unsaved'      => 'ਸੰਭਾਲਿਆ ਨਹੀਂ',
    'unsaved_changes' => 'ਅਣਸੰਭਾਲੀਆਂ ਤਬਦੀਲੀਆਂ',
    'failed'       => 'ਅਸਫਲ',

    'just_now'     => 'ਹੁਣੇ ਹੁਣੇ',
    'today'        => 'ਅੱਜ',
    'yesterday'    => 'ਕੱਲ੍ਹ',

    'required'     => 'ਲੋੜੀਂਦਾ',
    'optional'     => 'ਵਿਕਲਪਿਕ',
    'select_one'   => 'ਚੁਣੋ…',
    'search'       => 'ਖੋਜੋ',

    'error_generic'       => 'ਕੁਝ ਗਲਤ ਹੋਇਆ।',
    'error_network'       => 'ਨੈੱਟਵਰਕ ਗਲਤੀ',
    'error_not_logged_in' => 'ਤੁਹਾਨੂੰ ਲਾਗ ਇਨ ਕਰਨਾ ਪਵੇਗਾ।',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'ਸੇਵਾ ਡੈਸਕ',
        'browser_title'    => 'ਸੇਵਾ ਡੈਸਕ - ITSM',
        'welcome_heading'  => 'ਤੁਸੀਂ ਕੀ ਕਰਨਾ ਚਾਹੋਗੇ?',
        'welcome_subtitle' => 'ਸ਼ੁਰੂ ਕਰਨ ਲਈ ਇੱਕ ਮੋਡੀਊਲ ਚੁਣੋ',
        'footer'           => 'ਸੇਵਾ ਡੈਸਕ ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM ਮੋਡੀਊਲ',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'ਨਿਗਰਾਨੀ',     'description' => 'ਸਾਰੇ ਮੋਡੀਊਲਾਂ ਲਈ ਇਕਜੁੱਟ ਧਿਆਨ ਡੈਸ਼ਬੋਰਡ'],
        'tickets'        => ['name' => 'ਟਿਕਟ',         'description' => 'ਸਹਾਇਤਾ ਬੇਨਤੀਆਂ, ਈਮੇਲਾਂ ਅਤੇ ਉਪਭੋਗਤਾ ਮੁੱਦਿਆਂ ਦਾ ਪ੍ਰਬੰਧਨ ਕਰੋ'],
        'assets'         => ['name' => 'ਜਾਇਦਾਦਾਂ',    'description' => 'IT ਜਾਇਦਾਦਾਂ ਅਤੇ ਉਪਭੋਗਤਾ ਸੌਂਪਣ ਨੂੰ ਟਰੈਕ ਕਰੋ'],
        'knowledge'      => ['name' => 'ਗਿਆਨ',         'description' => 'ਗਿਆਨ ਅਧਾਰ ਲੇਖ ਬਣਾਓ ਅਤੇ ਵੇਖੋ'],
        'changes'        => ['name' => 'ਤਬਦੀਲੀਆਂ',    'description' => 'IT ਤਬਦੀਲੀਆਂ ਦੀ ਯੋਜਨਾ, ਟਰੈਕਿੰਗ ਅਤੇ ਪ੍ਰਬੰਧਨ ਕਰੋ'],
        'calendar'       => ['name' => 'ਕੈਲੰਡਰ',      'description' => 'ਘਟਨਾਵਾਂ, ਸਮਾਂ ਸੀਮਾਵਾਂ ਅਤੇ ਸਮਾਂ-ਸਾਰਣੀਆਂ ਨੂੰ ਟਰੈਕ ਕਰੋ'],
        'morning-checks' => ['name' => 'ਜਾਂਚ',         'description' => 'ਰੋਜ਼ਾਨਾ ਬੁਨਿਆਦੀ ਢਾਂਚੇ ਦੀ ਜਾਂਚ ਰਿਕਾਰਡ ਕਰੋ'],
        'reporting'      => ['name' => 'ਰਿਪੋਰਟਿੰਗ',   'description' => 'ਸਿਸਟਮ ਲੌਗ ਅਤੇ ਵਿਸ਼ਲੇਸ਼ਣ ਵੇਖੋ'],
        'software'       => ['name' => 'ਸਾਫਟਵੇਅਰ',    'description' => 'ਸਾਫਟਵੇਅਰ ਵਸਤੂ ਸੂਚੀ ਅਤੇ ਲਾਇਸੈਂਸਿੰਗ ਬ੍ਰਾਊਜ਼ ਕਰੋ'],
        'forms'          => ['name' => 'ਫਾਰਮ',         'description' => 'ਕਸਟਮ ਫਾਰਮ ਡਿਜ਼ਾਈਨ ਕਰੋ ਅਤੇ ਸਬਮਿਸ਼ਨ ਵੇਖੋ'],
        'contracts'      => ['name' => 'ਠੇਕੇ',         'description' => 'ਸਪਲਾਇਰ, ਸੰਪਰਕ ਅਤੇ ਠੇਕਿਆਂ ਦਾ ਪ੍ਰਬੰਧਨ ਕਰੋ'],
        'service-status' => ['name' => 'ਸਥਿਤੀ',        'description' => 'ਸੇਵਾ ਸਿਹਤ ਦੀ ਨਿਗਰਾਨੀ ਕਰੋ ਅਤੇ ਘਟਨਾਵਾਂ ਨੂੰ ਟਰੈਕ ਕਰੋ'],
        'wiki'           => ['name' => 'ਵਿਕੀ',         'description' => 'ਆਪਣੇ ਆਪ ਬਣੇ ਕੋਡਬੇਸ ਦਸਤਾਵੇਜ਼ ਬ੍ਰਾਊਜ਼ ਕਰੋ'],
        'lms'            => ['name' => 'LMS',          'description' => 'SCORM ਕੋਰਸ ਪਲੇਅਰ ਨਾਲ ਸਿੱਖਣ ਪ੍ਰਬੰਧਨ ਪ੍ਰਣਾਲੀ'],
        'process-mapper' => ['name' => 'ਪ੍ਰਕਿਰਿਆਵਾਂ', 'description' => 'ਵਿਜ਼ੂਅਲ ਫਲੋਚਾਰਟ ਅਤੇ ਪ੍ਰਕਿਰਿਆ ਮੈਪਿੰਗ ਟੂਲ'],
        'tasks'          => ['name' => 'ਕਾਰਜ',         'description' => 'ਕਾਰਜਾਂ ਨੂੰ ਟਰੈਕ ਕਰਨ ਲਈ ਕਾਨਬਨ ਬੋਰਡ ਅਤੇ ਸੂਚੀ ਦ੍ਰਿਸ਼'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'ਕੌਨਫਿਗਰੇਸ਼ਨ ਪ੍ਰਬੰਧਨ ਡੇਟਾਬੇਸ'],
        'network-mapper' => ['name' => 'ਨੈੱਟਵਰਕ',     'description' => 'ਨੈੱਟਵਰਕ ਡਾਇਗ੍ਰਾਮ ਡਿਜ਼ਾਈਨ ਕਰੋ ਅਤੇ ਦਸਤਾਵੇਜ਼ ਬਣਾਓ'],
        'system'         => ['name' => 'ਸਿਸਟਮ',       'description' => 'ਸਿਸਟਮ ਪ੍ਰਸ਼ਾਸਨ ਅਤੇ ਕੌਨਫਿਗਰੇਸ਼ਨ'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'ਨਵੀਆਂ ਈਮੇਲਾਂ ਲਈ ਜਾਂਚ ਕਰੋ',
        'change_password' => 'ਪਾਸਵਰਡ ਬਦਲੋ',
        'mfa'             => 'ਬਹੁ-ਕਾਰਕ ਪ੍ਰਮਾਣਿਕਤਾ',
        'trusted_device'  => 'ਭਰੋਸੇਯੋਗ ਉਪਕਰਣ',
        'logout'          => 'ਲਾਗ ਆਊਟ',
        'logout_confirm'  => 'ਕੀ ਤੁਸੀਂ ਯਕੀਨੀ ਤੌਰ ਤੇ ਲਾਗ ਆਊਟ ਕਰਨਾ ਚਾਹੁੰਦੇ ਹੋ?',
        'badge_off'       => 'ਬੰਦ',
        'badge_on'        => 'ਚਾਲੂ',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'ਪਾਸਵਰਡ ਬਦਲੋ',
        'current_password' => 'ਮੌਜੂਦਾ ਪਾਸਵਰਡ',
        'new_password'     => 'ਨਵਾਂ ਪਾਸਵਰਡ',
        'confirm_password' => 'ਨਵੇਂ ਪਾਸਵਰਡ ਦੀ ਪੁਸ਼ਟੀ ਕਰੋ',
        'submit'           => 'ਪਾਸਵਰਡ ਬਦਲੋ',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'ਬਹੁ-ਕਾਰਕ ਪ੍ਰਮਾਣਿਕਤਾ',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'ਪਿਛਲਾ',
        'next'     => 'ਅਗਲਾ',
        'today'    => 'ਅੱਜ',

        'months' => [
            'january'   => 'ਜਨਵਰੀ',
            'february'  => 'ਫਰਵਰੀ',
            'march'     => 'ਮਾਰਚ',
            'april'     => 'ਅਪ੍ਰੈਲ',
            'may'       => 'ਮਈ',
            'june'      => 'ਜੂਨ',
            'july'      => 'ਜੁਲਾਈ',
            'august'    => 'ਅਗਸਤ',
            'september' => 'ਸਤੰਬਰ',
            'october'   => 'ਅਕਤੂਬਰ',
            'november'  => 'ਨਵੰਬਰ',
            'december'  => 'ਦਸੰਬਰ',
        ],

        'weekdays' => [
            'monday'    => 'ਸੋਮਵਾਰ',
            'tuesday'   => 'ਮੰਗਲਵਾਰ',
            'wednesday' => 'ਬੁੱਧਵਾਰ',
            'thursday'  => 'ਵੀਰਵਾਰ',
            'friday'    => 'ਸ਼ੁੱਕਰਵਾਰ',
            'saturday'  => 'ਸ਼ਨੀਵਾਰ',
            'sunday'    => 'ਐਤਵਾਰ',
        ],
    ],
];
