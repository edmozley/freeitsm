<?php
/**
 * Polski (pl) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Zapisz',
    'cancel'       => 'Anuluj',
    'delete'       => 'Usuń',
    'add'          => 'Dodaj',
    'edit'         => 'Edytuj',
    'close'        => 'Zamknij',
    'copy'         => 'Kopiuj',
    'copied'       => 'Skopiowano',
    'retry'        => 'Spróbuj ponownie',
    'export'       => 'Eksportuj',
    'open'         => 'Otwórz',
    'apply'        => 'Zastosuj',

    'yes'          => 'Tak',
    'no'           => 'Nie',
    'ok'           => 'OK',
    'loading'      => 'Ładowanie…',
    'saving'       => 'Zapisywanie…',
    'saved'        => 'Zapisano',
    'unsaved'      => 'Niezapisane',
    'unsaved_changes' => 'Niezapisane zmiany',
    'failed'       => 'Niepowodzenie',

    'just_now'     => 'przed chwilą',
    'today'        => 'Dziś',
    'yesterday'    => 'Wczoraj',

    'required'     => 'Wymagane',
    'optional'     => 'Opcjonalne',
    'select_one'   => 'Wybierz…',
    'search'       => 'Szukaj',

    'error_generic'       => 'Coś poszło nie tak.',
    'error_network'       => 'Błąd sieci',
    'error_not_logged_in' => 'Musisz być zalogowany.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Centrum obsługi',
        'browser_title'    => 'Centrum obsługi - ITSM',
        'welcome_heading'  => 'Co chcesz zrobić?',
        'welcome_subtitle' => 'Wybierz moduł, aby rozpocząć',
        'footer'           => 'Centrum obsługi ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Moduły ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Strażnica',   'description' => 'Ujednolicony pulpit alertów dla wszystkich modułów'],
        'tickets'        => ['name' => 'Zgłoszenia',  'description' => 'Zarządzaj zgłoszeniami wsparcia, e-mailami i problemami użytkowników'],
        'assets'         => ['name' => 'Zasoby',      'description' => 'Śledź zasoby IT i przypisania do użytkowników'],
        'knowledge'      => ['name' => 'Wiedza',      'description' => 'Twórz i przeglądaj artykuły bazy wiedzy'],
        'changes'        => ['name' => 'Zmiany',      'description' => 'Planuj, śledź i zarządzaj zmianami IT'],
        'calendar'       => ['name' => 'Kalendarz',   'description' => 'Śledź wydarzenia, terminy i harmonogramy'],
        'morning-checks' => ['name' => 'Kontrole',    'description' => 'Rejestruj codzienne kontrole infrastruktury'],
        'reporting'      => ['name' => 'Raporty',     'description' => 'Przeglądaj dzienniki systemowe i analizy'],
        'software'       => ['name' => 'Oprogramowanie','description' => 'Przeglądaj inwentarz oprogramowania i licencje'],
        'forms'          => ['name' => 'Formularze',  'description' => 'Projektuj niestandardowe formularze i przeglądaj zgłoszenia'],
        'contracts'      => ['name' => 'Kontrakty',   'description' => 'Zarządzaj dostawcami, kontaktami i kontraktami'],
        'service-status' => ['name' => 'Status',      'description' => 'Monitoruj kondycję usług i śledź incydenty'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Przeglądaj automatycznie generowaną dokumentację kodu'],
        'lms'            => ['name' => 'LMS',         'description' => 'System zarządzania nauczaniem z odtwarzaczem SCORM'],
        'process-mapper' => ['name' => 'Procesy',     'description' => 'Wizualne narzędzie do diagramów i mapowania procesów'],
        'tasks'          => ['name' => 'Zadania',     'description' => 'Tablica Kanban i widok listy do śledzenia zadań'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Baza zarządzania konfiguracją'],
        'network-mapper' => ['name' => 'Sieć',        'description' => 'Projektuj i dokumentuj diagramy sieciowe'],
        'system'         => ['name' => 'System',      'description' => 'Administracja i konfiguracja systemu'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Sprawdź nowe e-maile',
        'change_password' => 'Zmień hasło',
        'mfa'             => 'Uwierzytelnianie wieloskładnikowe',
        'trusted_device'  => 'Zaufane urządzenie',
        'logout'          => 'Wyloguj',
        'logout_confirm'  => 'Czy na pewno chcesz się wylogować?',
        'badge_off'       => 'Wył.',
        'badge_on'        => 'Wł.',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Zmień hasło',
        'current_password' => 'Aktualne hasło',
        'new_password'     => 'Nowe hasło',
        'confirm_password' => 'Potwierdź nowe hasło',
        'submit'           => 'Zmień hasło',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Uwierzytelnianie wieloskładnikowe',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Poprzedni',
        'next'     => 'Następny',
        'today'    => 'Dziś',

        'months' => [
            'january'   => 'styczeń',
            'february'  => 'luty',
            'march'     => 'marzec',
            'april'     => 'kwiecień',
            'may'       => 'maj',
            'june'      => 'czerwiec',
            'july'      => 'lipiec',
            'august'    => 'sierpień',
            'september' => 'wrzesień',
            'october'   => 'październik',
            'november'  => 'listopad',
            'december'  => 'grudzień',
        ],

        'weekdays' => [
            'monday'    => 'poniedziałek',
            'tuesday'   => 'wtorek',
            'wednesday' => 'środa',
            'thursday'  => 'czwartek',
            'friday'    => 'piątek',
            'saturday'  => 'sobota',
            'sunday'    => 'niedziela',
        ],
    ],
];
