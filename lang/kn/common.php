<?php
/**
 * ಕನ್ನಡ (kn) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'ಉಳಿಸಿ',
    'cancel'       => 'ರದ್ದು',
    'delete'       => 'ಅಳಿಸಿ',
    'add'          => 'ಸೇರಿಸಿ',
    'edit'         => 'ಸಂಪಾದಿಸಿ',
    'close'        => 'ಮುಚ್ಚಿ',
    'copy'         => 'ನಕಲಿಸಿ',
    'copied'       => 'ನಕಲಿಸಲಾಗಿದೆ',
    'retry'        => 'ಮತ್ತೆ ಪ್ರಯತ್ನಿಸಿ',
    'export'       => 'ರಫ್ತು',
    'open'         => 'ತೆರೆಯಿರಿ',
    'apply'        => 'ಅನ್ವಯಿಸಿ',

    'yes'          => 'ಹೌದು',
    'no'           => 'ಇಲ್ಲ',
    'ok'           => 'ಸರಿ',
    'loading'      => 'ಲೋಡ್ ಆಗುತ್ತಿದೆ…',
    'saving'       => 'ಉಳಿಸಲಾಗುತ್ತಿದೆ…',
    'saved'        => 'ಉಳಿಸಲಾಗಿದೆ',
    'unsaved'      => 'ಉಳಿಸಿಲ್ಲ',
    'unsaved_changes' => 'ಉಳಿಸದ ಬದಲಾವಣೆಗಳು',
    'failed'       => 'ವಿಫಲವಾಗಿದೆ',

    'just_now'     => 'ಈಗಷ್ಟೇ',
    'today'        => 'ಇಂದು',
    'yesterday'    => 'ನಿನ್ನೆ',

    'required'     => 'ಅಗತ್ಯವಿದೆ',
    'optional'     => 'ಐಚ್ಛಿಕ',
    'select_one'   => 'ಆಯ್ಕೆ ಮಾಡಿ…',
    'search'       => 'ಹುಡುಕಿ',

    'error_generic'       => 'ಏನೋ ತಪ್ಪಾಯಿತು.',
    'error_network'       => 'ನೆಟ್‌ವರ್ಕ್ ದೋಷ',
    'error_not_logged_in' => 'ನೀವು ಲಾಗಿನ್ ಆಗಿರಬೇಕು.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'ಸೇವಾ ಕೇಂದ್ರ',
        'browser_title'    => 'ಸೇವಾ ಕೇಂದ್ರ - ITSM',
        'welcome_heading'  => 'ನೀವು ಏನು ಮಾಡಲು ಬಯಸುತ್ತೀರಿ?',
        'welcome_subtitle' => 'ಪ್ರಾರಂಭಿಸಲು ಒಂದು ಮಾಡ್ಯೂಲ್ ಆಯ್ಕೆಮಾಡಿ',
        'footer'           => 'ಸೇವಾ ಕೇಂದ್ರ ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM ಮಾಡ್ಯೂಲ್‌ಗಳು',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'ಕಾವಲು',         'description' => 'ಎಲ್ಲಾ ಮಾಡ್ಯೂಲ್‌ಗಳಿಗೆ ಏಕೀಕೃತ ಗಮನ ಡ್ಯಾಶ್‌ಬೋರ್ಡ್'],
        'tickets'        => ['name' => 'ಟಿಕೆಟ್‌ಗಳು',    'description' => 'ಬೆಂಬಲ ವಿನಂತಿಗಳು, ಇಮೇಲ್‌ಗಳು ಮತ್ತು ಬಳಕೆದಾರ ಸಮಸ್ಯೆಗಳನ್ನು ನಿರ್ವಹಿಸಿ'],
        'assets'         => ['name' => 'ಸ್ವತ್ತುಗಳು',    'description' => 'IT ಸ್ವತ್ತುಗಳು ಮತ್ತು ಬಳಕೆದಾರರ ಹಂಚಿಕೆಗಳನ್ನು ಟ್ರ್ಯಾಕ್ ಮಾಡಿ'],
        'knowledge'      => ['name' => 'ಜ್ಞಾನ',          'description' => 'ಜ್ಞಾನ ಆಧಾರ ಲೇಖನಗಳನ್ನು ರಚಿಸಿ ಮತ್ತು ವೀಕ್ಷಿಸಿ'],
        'changes'        => ['name' => 'ಬದಲಾವಣೆಗಳು',   'description' => 'IT ಬದಲಾವಣೆಗಳನ್ನು ಯೋಜಿಸಿ, ಟ್ರ್ಯಾಕ್ ಮಾಡಿ ಮತ್ತು ನಿರ್ವಹಿಸಿ'],
        'calendar'       => ['name' => 'ಕ್ಯಾಲೆಂಡರ್',   'description' => 'ಘಟನೆಗಳು, ಗಡುವುಗಳು ಮತ್ತು ವೇಳಾಪಟ್ಟಿಗಳನ್ನು ಟ್ರ್ಯಾಕ್ ಮಾಡಿ'],
        'morning-checks' => ['name' => 'ಪರಿಶೀಲನೆಗಳು',  'description' => 'ದೈನಂದಿನ ಮೂಲಸೌಕರ್ಯ ಪರಿಶೀಲನೆಗಳನ್ನು ದಾಖಲಿಸಿ'],
        'reporting'      => ['name' => 'ವರದಿಗಳು',      'description' => 'ಸಿಸ್ಟಮ್ ಲಾಗ್‌ಗಳು ಮತ್ತು ವಿಶ್ಲೇಷಣೆ ವೀಕ್ಷಿಸಿ'],
        'software'       => ['name' => 'ತಂತ್ರಾಂಶ',     'description' => 'ತಂತ್ರಾಂಶ ದಾಸ್ತಾನು ಮತ್ತು ಪರವಾನಗಿಯನ್ನು ವೀಕ್ಷಿಸಿ'],
        'forms'          => ['name' => 'ಫಾರ್ಮ್‌ಗಳು',   'description' => 'ಕಸ್ಟಮ್ ಫಾರ್ಮ್‌ಗಳನ್ನು ವಿನ್ಯಾಸಗೊಳಿಸಿ ಮತ್ತು ಸಲ್ಲಿಕೆಗಳನ್ನು ವೀಕ್ಷಿಸಿ'],
        'contracts'      => ['name' => 'ಒಪ್ಪಂದಗಳು',    'description' => 'ಪೂರೈಕೆದಾರರು, ಸಂಪರ್ಕಗಳು ಮತ್ತು ಒಪ್ಪಂದಗಳನ್ನು ನಿರ್ವಹಿಸಿ'],
        'service-status' => ['name' => 'ಸ್ಥಿತಿ',         'description' => 'ಸೇವೆಯ ಆರೋಗ್ಯವನ್ನು ಮೇಲ್ವಿಚಾರಣೆ ಮಾಡಿ ಮತ್ತು ಘಟನೆಗಳನ್ನು ಟ್ರ್ಯಾಕ್ ಮಾಡಿ'],
        'wiki'           => ['name' => 'ವಿಕಿ',           'description' => 'ಸ್ವಯಂ-ಉತ್ಪಾದಿತ ಕೋಡ್‌ಬೇಸ್ ದಾಖಲಾತಿ ವೀಕ್ಷಿಸಿ'],
        'lms'            => ['name' => 'LMS',           'description' => 'SCORM ಕೋರ್ಸ್ ಪ್ಲೇಯರ್‌ನೊಂದಿಗೆ ಕಲಿಕೆ ನಿರ್ವಹಣಾ ವ್ಯವಸ್ಥೆ'],
        'process-mapper' => ['name' => 'ಪ್ರಕ್ರಿಯೆಗಳು', 'description' => 'ದೃಶ್ಯ ಫ್ಲೋಚಾರ್ಟ್ ಮತ್ತು ಪ್ರಕ್ರಿಯೆ ಮ್ಯಾಪಿಂಗ್ ಸಾಧನ'],
        'tasks'          => ['name' => 'ಕಾರ್ಯಗಳು',     'description' => 'ಕಾರ್ಯಗಳನ್ನು ಟ್ರ್ಯಾಕ್ ಮಾಡಲು ಕಾನ್‌ಬನ್ ಬೋರ್ಡ್ ಮತ್ತು ಪಟ್ಟಿ ವೀಕ್ಷಣೆ'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'ಕಾನ್ಫಿಗರೇಶನ್ ನಿರ್ವಹಣಾ ಡೇಟಾಬೇಸ್'],
        'network-mapper' => ['name' => 'ನೆಟ್‌ವರ್ಕ್',   'description' => 'ನೆಟ್‌ವರ್ಕ್ ರೇಖಾಚಿತ್ರಗಳನ್ನು ವಿನ್ಯಾಸಗೊಳಿಸಿ ಮತ್ತು ದಾಖಲಿಸಿ'],
        'system'         => ['name' => 'ಸಿಸ್ಟಮ್',       'description' => 'ಸಿಸ್ಟಮ್ ಆಡಳಿತ ಮತ್ತು ಸಂರಚನೆ'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'ಹೊಸ ಇಮೇಲ್‌ಗಳಿಗಾಗಿ ಪರಿಶೀಲಿಸಿ',
        'change_password' => 'ಪಾಸ್‌ವರ್ಡ್ ಬದಲಾಯಿಸಿ',
        'mfa'             => 'ಬಹು-ಅಂಶ ದೃಢೀಕರಣ',
        'trusted_device'  => 'ವಿಶ್ವಾಸಾರ್ಹ ಸಾಧನ',
        'logout'          => 'ಲಾಗ್ ಔಟ್',
        'logout_confirm'  => 'ನೀವು ನಿಜವಾಗಿಯೂ ಲಾಗ್ ಔಟ್ ಮಾಡಲು ಬಯಸುತ್ತೀರಾ?',
        'badge_off'       => 'ಆಫ್',
        'badge_on'        => 'ಆನ್',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'ಪಾಸ್‌ವರ್ಡ್ ಬದಲಾಯಿಸಿ',
        'current_password' => 'ಪ್ರಸ್ತುತ ಪಾಸ್‌ವರ್ಡ್',
        'new_password'     => 'ಹೊಸ ಪಾಸ್‌ವರ್ಡ್',
        'confirm_password' => 'ಹೊಸ ಪಾಸ್‌ವರ್ಡ್ ದೃಢೀಕರಿಸಿ',
        'submit'           => 'ಪಾಸ್‌ವರ್ಡ್ ಬದಲಾಯಿಸಿ',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'ಬಹು-ಅಂಶ ದೃಢೀಕರಣ',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'ಹಿಂದಿನ',
        'next'     => 'ಮುಂದಿನ',
        'today'    => 'ಇಂದು',

        'months' => [
            'january'   => 'ಜನವರಿ',
            'february'  => 'ಫೆಬ್ರವರಿ',
            'march'     => 'ಮಾರ್ಚ್',
            'april'     => 'ಏಪ್ರಿಲ್',
            'may'       => 'ಮೇ',
            'june'      => 'ಜೂನ್',
            'july'      => 'ಜುಲೈ',
            'august'    => 'ಆಗಸ್ಟ್',
            'september' => 'ಸೆಪ್ಟೆಂಬರ್',
            'october'   => 'ಅಕ್ಟೋಬರ್',
            'november'  => 'ನವೆಂಬರ್',
            'december'  => 'ಡಿಸೆಂಬರ್',
        ],

        'weekdays' => [
            'monday'    => 'ಸೋಮವಾರ',
            'tuesday'   => 'ಮಂಗಳವಾರ',
            'wednesday' => 'ಬುಧವಾರ',
            'thursday'  => 'ಗುರುವಾರ',
            'friday'    => 'ಶುಕ್ರವಾರ',
            'saturday'  => 'ಶನಿವಾರ',
            'sunday'    => 'ಭಾನುವಾರ',
        ],
    ],
];
