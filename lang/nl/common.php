<?php
/**
 * Nederlands (nl) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Opslaan',
    'cancel'       => 'Annuleren',
    'delete'       => 'Verwijderen',
    'add'          => 'Toevoegen',
    'edit'         => 'Bewerken',
    'close'        => 'Sluiten',
    'copy'         => 'Kopiëren',
    'copied'       => 'Gekopieerd',
    'retry'        => 'Opnieuw proberen',
    'export'       => 'Exporteren',
    'open'         => 'Openen',
    'apply'        => 'Toepassen',

    'yes'          => 'Ja',
    'no'           => 'Nee',
    'ok'           => 'OK',
    'loading'      => 'Laden…',
    'saving'       => 'Opslaan…',
    'saved'        => 'Opgeslagen',
    'unsaved'      => 'Niet opgeslagen',
    'unsaved_changes' => 'Niet-opgeslagen wijzigingen',
    'failed'       => 'Mislukt',

    'just_now'     => 'zojuist',
    'today'        => 'Vandaag',
    'yesterday'    => 'Gisteren',

    'required'     => 'Verplicht',
    'optional'     => 'Optioneel',
    'select_one'   => 'Selecteer…',
    'search'       => 'Zoeken',

    'error_generic'       => 'Er is iets misgegaan.',
    'error_network'       => 'Netwerkfout',
    'error_not_logged_in' => 'U moet ingelogd zijn.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Servicedesk',
        'browser_title'    => 'Servicedesk - ITSM',
        'welcome_heading'  => 'Wat wilt u doen?',
        'welcome_subtitle' => 'Selecteer een module om te beginnen',
        'footer'           => 'Servicedesk ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM-modules',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Wachttoren',  'description' => 'Eén overzichtsdashboard voor alle modules'],
        'tickets'        => ['name' => 'Tickets',     'description' => 'Beheer supportverzoeken, e-mails en gebruikersproblemen'],
        'assets'         => ['name' => 'Assets',      'description' => 'Volg IT-assets en toewijzingen aan gebruikers'],
        'knowledge'      => ['name' => 'Kennis',      'description' => 'Maak en raadpleeg kennisbankartikelen'],
        'changes'        => ['name' => 'Wijzigingen', 'description' => 'IT-wijzigingen plannen, volgen en beheren'],
        'calendar'       => ['name' => 'Agenda',      'description' => 'Volg gebeurtenissen, deadlines en planningen'],
        'morning-checks' => ['name' => 'Checks',      'description' => 'Leg dagelijkse infrastructuurcontroles vast'],
        'reporting'      => ['name' => 'Rapportage',  'description' => 'Bekijk systeemlogboeken en analyses'],
        'software'       => ['name' => 'Software',    'description' => 'Doorblader software-inventaris en licenties'],
        'forms'          => ['name' => 'Formulieren', 'description' => 'Ontwerp aangepaste formulieren en bekijk inzendingen'],
        'contracts'      => ['name' => 'Contracten',  'description' => 'Beheer leveranciers, contactpersonen en contracten'],
        'service-status' => ['name' => 'Status',      'description' => 'Bewaak servicegezondheid en volg incidenten'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Doorblader automatisch gegenereerde codedocumentatie'],
        'lms'            => ['name' => 'LMS',         'description' => 'Leermanagementsysteem met SCORM-cursusspeler'],
        'process-mapper' => ['name' => 'Processen',   'description' => 'Visueel hulpmiddel voor flowcharts en processen'],
        'tasks'          => ['name' => 'Taken',       'description' => 'Kanban-bord en lijstweergave om taken te volgen'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Configuration Management Database'],
        'network-mapper' => ['name' => 'Netwerk',     'description' => 'Ontwerp en documenteer netwerkdiagrammen'],
        'system'         => ['name' => 'Systeem',     'description' => 'Systeembeheer en configuratie'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Controleren op nieuwe e-mails',
        'change_password' => 'Wachtwoord wijzigen',
        'mfa'             => 'Multi-factor authenticatie',
        'trusted_device'  => 'Vertrouwd apparaat',
        'logout'          => 'Afmelden',
        'logout_confirm'  => 'Weet u zeker dat u zich wilt afmelden?',
        'badge_off'       => 'Uit',
        'badge_on'        => 'Aan',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Wachtwoord wijzigen',
        'current_password' => 'Huidig wachtwoord',
        'new_password'     => 'Nieuw wachtwoord',
        'confirm_password' => 'Bevestig nieuw wachtwoord',
        'submit'           => 'Wachtwoord wijzigen',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Multi-factor authenticatie',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Vorige',
        'next'     => 'Volgende',
        'today'    => 'Vandaag',

        'months' => [
            'january'   => 'januari',
            'february'  => 'februari',
            'march'     => 'maart',
            'april'     => 'april',
            'may'       => 'mei',
            'june'      => 'juni',
            'july'      => 'juli',
            'august'    => 'augustus',
            'september' => 'september',
            'october'   => 'oktober',
            'november'  => 'november',
            'december'  => 'december',
        ],

        'weekdays' => [
            'monday'    => 'maandag',
            'tuesday'   => 'dinsdag',
            'wednesday' => 'woensdag',
            'thursday'  => 'donderdag',
            'friday'    => 'vrijdag',
            'saturday'  => 'zaterdag',
            'sunday'    => 'zondag',
        ],
    ],
];
