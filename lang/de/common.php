<?php
/**
 * Deutsch (de) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Speichern',
    'cancel'       => 'Abbrechen',
    'delete'       => 'Löschen',
    'add'          => 'Hinzufügen',
    'edit'         => 'Bearbeiten',
    'close'        => 'Schließen',
    'copy'         => 'Kopieren',
    'copied'       => 'Kopiert',
    'retry'        => 'Erneut versuchen',
    'export'       => 'Exportieren',
    'open'         => 'Öffnen',
    'apply'        => 'Anwenden',

    'yes'          => 'Ja',
    'no'           => 'Nein',
    'ok'           => 'OK',
    'loading'      => 'Lädt…',
    'saving'       => 'Speichert…',
    'saved'        => 'Gespeichert',
    'unsaved'      => 'Nicht gespeichert',
    'unsaved_changes' => 'Nicht gespeicherte Änderungen',
    'failed'       => 'Fehlgeschlagen',

    'just_now'     => 'gerade eben',
    'today'        => 'Heute',
    'yesterday'    => 'Gestern',

    'required'     => 'Erforderlich',
    'optional'     => 'Optional',
    'select_one'   => 'Auswählen…',
    'search'       => 'Suchen',

    'error_generic'       => 'Etwas ist schiefgelaufen.',
    'error_network'       => 'Netzwerkfehler',
    'error_not_logged_in' => 'Sie müssen angemeldet sein.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Service Desk',
        'browser_title'    => 'Service Desk - ITSM',
        'welcome_heading'  => 'Was möchten Sie tun?',
        'welcome_subtitle' => 'Wählen Sie ein Modul, um zu beginnen',
        'footer'           => 'Service Desk ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'ITSM-Module',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Wachtturm',    'description' => 'Einheitliches Übersichts-Dashboard für alle Module'],
        'tickets'        => ['name' => 'Tickets',      'description' => 'Verwalten Sie Supportanfragen, E-Mails und Benutzerprobleme'],
        'assets'         => ['name' => 'Assets',       'description' => 'Verfolgen Sie IT-Assets und Benutzerzuweisungen'],
        'knowledge'      => ['name' => 'Wissen',       'description' => 'Erstellen und durchsuchen Sie Wissensdatenbank-Artikel'],
        'changes'        => ['name' => 'Änderungen',   'description' => 'IT-Änderungen planen, verfolgen und verwalten'],
        'calendar'       => ['name' => 'Kalender',     'description' => 'Termine, Fristen und Zeitpläne verfolgen'],
        'morning-checks' => ['name' => 'Checks',       'description' => 'Tägliche Infrastrukturprüfungen erfassen'],
        'reporting'      => ['name' => 'Berichte',     'description' => 'Systemprotokolle und Analysen ansehen'],
        'software'       => ['name' => 'Software',     'description' => 'Software-Inventar und Lizenzierung durchsuchen'],
        'forms'          => ['name' => 'Formulare',    'description' => 'Eigene Formulare entwerfen und Einreichungen anzeigen'],
        'contracts'      => ['name' => 'Verträge',     'description' => 'Lieferanten, Kontakte und Verträge verwalten'],
        'service-status' => ['name' => 'Status',       'description' => 'Servicezustand überwachen und Vorfälle verfolgen'],
        'wiki'           => ['name' => 'Wiki',         'description' => 'Automatisch generierte Codebasis-Dokumentation durchsuchen'],
        'lms'            => ['name' => 'LMS',          'description' => 'Lernmanagementsystem mit SCORM-Kursplayer'],
        'process-mapper' => ['name' => 'Prozesse',     'description' => 'Visuelles Flussdiagramm- und Prozess-Mapping-Tool'],
        'tasks'          => ['name' => 'Aufgaben',     'description' => 'Kanban-Board und Listenansicht zur Aufgabenverfolgung'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'Konfigurations-Management-Datenbank'],
        'network-mapper' => ['name' => 'Netzwerk',     'description' => 'Netzwerkdiagramme entwerfen und dokumentieren'],
        'system'         => ['name' => 'System',       'description' => 'Systemadministration und Konfiguration'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Auf neue E-Mails prüfen',
        'change_password' => 'Passwort ändern',
        'mfa'             => 'Multi-Faktor-Auth.',
        'trusted_device'  => 'Vertrauenswürdiges Gerät',
        'logout'          => 'Abmelden',
        'logout_confirm'  => 'Möchten Sie sich wirklich abmelden?',
        'badge_off'       => 'Aus',
        'badge_on'        => 'Ein',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Passwort ändern',
        'current_password' => 'Aktuelles Passwort',
        'new_password'     => 'Neues Passwort',
        'confirm_password' => 'Neues Passwort bestätigen',
        'submit'           => 'Passwort ändern',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Multi-Faktor-Authentifizierung',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Zurück',
        'next'     => 'Weiter',
        'today'    => 'Heute',

        'months' => [
            'january'   => 'Januar',
            'february'  => 'Februar',
            'march'     => 'März',
            'april'     => 'April',
            'may'       => 'Mai',
            'june'      => 'Juni',
            'july'      => 'Juli',
            'august'    => 'August',
            'september' => 'September',
            'october'   => 'Oktober',
            'november'  => 'November',
            'december'  => 'Dezember',
        ],

        'weekdays' => [
            'monday'    => 'Montag',
            'tuesday'   => 'Dienstag',
            'wednesday' => 'Mittwoch',
            'thursday'  => 'Donnerstag',
            'friday'    => 'Freitag',
            'saturday'  => 'Samstag',
            'sunday'    => 'Sonntag',
        ],
    ],
];
