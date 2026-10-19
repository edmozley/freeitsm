<?php
/**
 * Afrikaans (af) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'inventory_source' => 'Bron',
    // Left-panel visibility preference — shared labels (mirrors lang/en/common.php).
    'left_panel' => [
        'tab'        => 'Linkerpaneel',
        'visibility' => 'Sigbaarheid',
        'always'     => 'Altyd sigbaar',
        'hover'      => 'Wys met sweef',
    ],

    // Buttons
    'save'         => 'Stoor',
    'cancel'       => 'Kanselleer',
    'delete'       => 'Skrap',
    'add'          => 'Voeg by',
    'edit'         => 'Wysig',
    'close'        => 'Sluit',
    'copy'         => 'Kopieer',
    'copied'       => 'Gekopieer',
    'retry'        => 'Probeer weer',
    'export'       => 'Voer uit',
    'back'         => 'Terug',
    'open'         =>  'Maak oop',
    'apply'        => 'Pas toe',

    // Confirm / state
    'yes'          => 'Ja',
    'no'           => 'Nee',
    'ok'           => 'OK',
    'loading'      => 'Laai...',
    'saving'       => 'Besig om te stoor...',
    'saved'        => 'Gestoor',
    'unsaved'      => 'Nie gestoor nie',
    'unsaved_changes' => 'Ongestoorde veranderinge',
    'failed'       => 'Misluk',

    // Time / units (often inlined)
    'just_now'     => 'sopas',
    'today'        => 'Vandag',
    'yesterday'    => 'Gister',

    // Form helpers
    'required'     => 'Vereis',
    'optional'     => 'Opsioneel',
    'select_one'   => 'Kies...',
    'search'       => 'Soek',
    'filter'       => 'Filter',

    // Errors
    'error_generic'        => 'Iets het verkeerd gegaan.',
    'error_network'        => 'Netwerkfout',
    'error_not_logged_in'  => 'U moet aangemeld wees.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Dienstoonbank',
        'browser_title'    => 'Dienstoonbank - ITSM',
        'welcome_heading'  => 'Wat wil u doen?',
        'welcome_subtitle' => 'Kies \'n module om te begin',
        'footer'           => 'Dienstoonbank ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM-modules',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Wagpos',      'description' => 'Verenigde aandagpaneel oor alle modules'],
        'tickets'        => ['name' => 'Kaartjies',   'description' => 'Bestuur ondersteuningsversoeke, e-posse en gebruikersprobleme'],
        'assets'         => ['name' => 'Bates',       'description' => 'Volg IT-bates en gebruikertoewysings'],
        'knowledge'      => ['name' => 'Kennis',      'description' => 'Skep en blaai deur kennisbasisartikels'],
        'changes'        => ['name' => 'Wysigings',   'description' => 'Beplan, volg en bestuur IT-wysigings'],
        'calendar'       => ['name' => 'Kalender',    'description' => 'Volg gebeure, sperdatums en skedules'],
        'morning-checks' => ['name' => 'Toetse',      'description' => 'Teken daaglikse infrastruktuurtoetse aan'],
        'reporting'      => ['name' => 'Verslagdoening', 'description' => 'Bekyk stelselrekords en ontleding'],
        'software'       => ['name' => 'Sagteware',   'description' => 'Blaai deur sagteware-voorraad en lisensiëring'],
        'forms'          => ['name' => 'Vorms',       'description' => 'Ontwerp pasgemaakte vorms en bekyk inskrywings'],
        'contracts'      => ['name' => 'Kontrakte',   'description' => 'Bestuur verskaffers, kontakte en kontrakte'],
        'service-status' => ['name' => 'Status',      'description' => 'Monitor diensgesondheid en volg insidente'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Blaai deur outomaties gegenereerde kodebasisdokumentasie'],
        'lms'            => ['name' => 'LMS',         'description' => 'Leerbestuurstelsel met SCORM-kursusspeler'],
        'process-mapper' => ['name' => 'Prosesse',    'description' => 'Visuele vloeidiagram- en proseskaartgereedskap'],
        'tasks'          => ['name' => 'Take',        'description' => 'Kanban-bord en lysaansig om take te volg'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Configuration Management Database'],
        'network-mapper' => ['name' => 'Netwerk',     'description' => 'Ontwerp en dokumenteer netwerkdiagramme'],
        'system'         => ['name' => 'Stelsel',     'description' => 'Stelseladministrasie en konfigurasie'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Kyk vir nuwe e-posse',
        'change_password' => 'Verander wagwoord',
        'mfa'             => 'Multi-faktor-verifikasie',
        'trusted_device'  => 'Vertroude toestel',
        'logout'          => 'Meld af',
        'logout_confirm'  => 'Is u seker u wil afmeld?',
        'badge_off'       => 'Af',
        'badge_on'        => 'Aan',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Verander wagwoord',
        'current_password' => 'Huidige wagwoord',
        'new_password'     => 'Nuwe wagwoord',
        'confirm_password' => 'Bevestig nuwe wagwoord',
        'submit'           => 'Verander wagwoord',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Multi-faktor-verifikasie',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Vorige',
        'next'     => 'Volgende',
        'today'    => 'Vandag',

        'months' => [
            'january'   => 'Januarie',
            'february'  => 'Februarie',
            'march'     => 'Maart',
            'april'     => 'April',
            'may'       => 'Mei',
            'june'      => 'Junie',
            'july'      => 'Julie',
            'august'    => 'Augustus',
            'september' => 'September',
            'october'   => 'Oktober',
            'november'  => 'November',
            'december'  => 'Desember',
        ],

        'weekdays' => [
            'monday'    => 'Maandag',
            'tuesday'   => 'Dinsdag',
            'wednesday' => 'Woensdag',
            'thursday'  => 'Donderdag',
            'friday'    => 'Vrydag',
            'saturday'  => 'Saterdag',
            'sunday'    => 'Sondag',
        ],

        // Abbreviated forms, used by the date formatter for the
        // "25 Aug 2026" family of formats (GH #105).
        'months_short' => [
            'january'   => 'Jan',
            'february'  => 'Feb',
            'march'     => 'Mrt',
            'april'     => 'Apr',
            'may'       => 'Mei',
            'june'      => 'Jun',
            'july'      => 'Jul',
            'august'    => 'Aug',
            'september' => 'Sep',
            'october'   => 'Okt',
            'november'  => 'Nov',
            'december'  => 'Des',
        ],

        'weekdays_short' => [
            'monday'    => 'Maa',
            'tuesday'   => 'Din',
            'wednesday' => 'Woe',
            'thursday'  => 'Don',
            'friday'    => 'Vry',
            'saturday'  => 'Sat',
            'sunday'    => 'Son',
        ],
    ],
];
