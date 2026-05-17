<?php
/**
 * ગુજરાતી (gu) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'સાચવો',
    'cancel'       => 'રદ કરો',
    'delete'       => 'કાઢી નાખો',
    'add'          => 'ઉમેરો',
    'edit'         => 'સંપાદન',
    'close'        => 'બંધ',
    'copy'         => 'નકલ',
    'copied'       => 'નકલ થઈ',
    'retry'        => 'ફરી પ્રયાસ',
    'export'       => 'નિકાસ',
    'open'         => 'ખોલો',
    'apply'        => 'લાગુ કરો',

    'yes'          => 'હા',
    'no'           => 'ના',
    'ok'           => 'ઠીક છે',
    'loading'      => 'લોડ થઈ રહ્યું છે…',
    'saving'       => 'સાચવી રહ્યું છે…',
    'saved'        => 'સાચવ્યું',
    'unsaved'      => 'સાચવ્યું નથી',
    'unsaved_changes' => 'સાચવ્યા વગરના ફેરફારો',
    'failed'       => 'નિષ્ફળ',

    'just_now'     => 'હમણાં જ',
    'today'        => 'આજે',
    'yesterday'    => 'ગઈકાલે',

    'required'     => 'જરૂરી',
    'optional'     => 'વૈકલ્પિક',
    'select_one'   => 'પસંદ કરો…',
    'search'       => 'શોધો',

    'error_generic'       => 'કંઈક ખોટું થયું.',
    'error_network'       => 'નેટવર્ક ભૂલ',
    'error_not_logged_in' => 'તમારે લોગ ઇન કરવાની જરૂર છે.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'સેવા ડેસ્ક',
        'browser_title'    => 'સેવા ડેસ્ક - ITSM',
        'welcome_heading'  => 'તમે શું કરવા માંગો છો?',
        'welcome_subtitle' => 'શરૂ કરવા માટે એક મોડ્યુલ પસંદ કરો',
        'footer'           => 'સેવા ડેસ્ક ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM મોડ્યુલો',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'નિરીક્ષણ',     'description' => 'બધા મોડ્યુલો માટે એકીકૃત ધ્યાન ડેશબોર્ડ'],
        'tickets'        => ['name' => 'ટિકિટ',        'description' => 'સહાય વિનંતીઓ, ઇમેઇલ અને વપરાશકર્તા સમસ્યાઓનું સંચાલન કરો'],
        'assets'         => ['name' => 'સંપત્તિઓ',     'description' => 'IT સંપત્તિઓ અને વપરાશકર્તા સોંપણીઓને ટ્રૅક કરો'],
        'knowledge'      => ['name' => 'જ્ઞાન',         'description' => 'જ્ઞાન આધાર લેખો બનાવો અને જુઓ'],
        'changes'        => ['name' => 'ફેરફારો',      'description' => 'IT ફેરફારોની યોજના, ટ્રૅકિંગ અને સંચાલન કરો'],
        'calendar'       => ['name' => 'કેલેન્ડર',     'description' => 'ઘટનાઓ, સમયમર્યાદા અને સમયપત્રકોને ટ્રૅક કરો'],
        'morning-checks' => ['name' => 'તપાસ',          'description' => 'દૈનિક માળખાકીય તપાસ રેકોર્ડ કરો'],
        'reporting'      => ['name' => 'અહેવાલો',      'description' => 'સિસ્ટમ લોગ અને વિશ્લેષણ જુઓ'],
        'software'       => ['name' => 'સોફ્ટવેર',     'description' => 'સોફ્ટવેર ઈન્વેન્ટરી અને લાઇસન્સિંગ બ્રાઉઝ કરો'],
        'forms'          => ['name' => 'ફોર્મ',         'description' => 'કસ્ટમ ફોર્મ ડિઝાઇન કરો અને સબમિશન જુઓ'],
        'contracts'      => ['name' => 'કરારો',         'description' => 'સપ્લાયરો, સંપર્કો અને કરારોનું સંચાલન કરો'],
        'service-status' => ['name' => 'સ્થિતિ',         'description' => 'સેવા આરોગ્યનું નિરીક્ષણ કરો અને ઘટનાઓને ટ્રૅક કરો'],
        'wiki'           => ['name' => 'વિકિ',          'description' => 'સ્વચાલિત રીતે જનરેટ થયેલ કોડબેઝ દસ્તાવેજીકરણ બ્રાઉઝ કરો'],
        'lms'            => ['name' => 'LMS',           'description' => 'SCORM કોર્સ પ્લેયર સાથે લર્નિંગ મેનેજમેન્ટ સિસ્ટમ'],
        'process-mapper' => ['name' => 'પ્રક્રિયાઓ',   'description' => 'વિઝ્યુઅલ ફ્લોચાર્ટ અને પ્રક્રિયા મેપિંગ સાધન'],
        'tasks'          => ['name' => 'કાર્યો',        'description' => 'કાર્યો ટ્રૅક કરવા માટે કાનબાન બોર્ડ અને સૂચિ દૃશ્ય'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'કોન્ફિગરેશન મેનેજમેન્ટ ડેટાબેઝ'],
        'network-mapper' => ['name' => 'નેટવર્ક',      'description' => 'નેટવર્ક આકૃતિઓ ડિઝાઇન અને દસ્તાવેજ કરો'],
        'system'         => ['name' => 'સિસ્ટમ',       'description' => 'સિસ્ટમ વહીવટ અને રૂપરેખાંકન'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'નવા ઇમેઇલ માટે તપાસો',
        'change_password' => 'પાસવર્ડ બદલો',
        'mfa'             => 'મલ્ટિ-ફેક્ટર પ્રમાણીકરણ',
        'trusted_device'  => 'વિશ્વસનીય ઉપકરણ',
        'logout'          => 'લોગ આઉટ',
        'logout_confirm'  => 'શું તમે ખરેખર લોગ આઉટ કરવા માંગો છો?',
        'badge_off'       => 'બંધ',
        'badge_on'        => 'ચાલુ',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'પાસવર્ડ બદલો',
        'current_password' => 'વર્તમાન પાસવર્ડ',
        'new_password'     => 'નવો પાસવર્ડ',
        'confirm_password' => 'નવા પાસવર્ડની પુષ્ટિ કરો',
        'submit'           => 'પાસવર્ડ બદલો',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'મલ્ટિ-ફેક્ટર પ્રમાણીકરણ',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'પાછલું',
        'next'     => 'આગલું',
        'today'    => 'આજે',

        'months' => [
            'january'   => 'જાન્યુઆરી',
            'february'  => 'ફેબ્રુઆરી',
            'march'     => 'માર્ચ',
            'april'     => 'એપ્રિલ',
            'may'       => 'મે',
            'june'      => 'જૂન',
            'july'      => 'જુલાઈ',
            'august'    => 'ઓગસ્ટ',
            'september' => 'સપ્ટેમ્બર',
            'october'   => 'ઓક્ટોબર',
            'november'  => 'નવેમ્બર',
            'december'  => 'ડિસેમ્બર',
        ],

        'weekdays' => [
            'monday'    => 'સોમવાર',
            'tuesday'   => 'મંગળવાર',
            'wednesday' => 'બુધવાર',
            'thursday'  => 'ગુરુવાર',
            'friday'    => 'શુક્રવાર',
            'saturday'  => 'શનિવાર',
            'sunday'    => 'રવિવાર',
        ],
    ],
];
