<?php
/**
 * Italiano (it) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Salva',
    'cancel'       => 'Annulla',
    'delete'       => 'Elimina',
    'add'          => 'Aggiungi',
    'edit'         => 'Modifica',
    'close'        => 'Chiudi',
    'copy'         => 'Copia',
    'copied'       => 'Copiato',
    'retry'        => 'Riprova',
    'export'       => 'Esporta',
    'open'         => 'Apri',
    'apply'        => 'Applica',

    'yes'          => 'Sì',
    'no'           => 'No',
    'ok'           => 'OK',
    'loading'      => 'Caricamento…',
    'saving'       => 'Salvataggio…',
    'saved'        => 'Salvato',
    'unsaved'      => 'Non salvato',
    'unsaved_changes' => 'Modifiche non salvate',
    'failed'       => 'Fallito',

    'just_now'     => 'proprio ora',
    'today'        => 'Oggi',
    'yesterday'    => 'Ieri',

    'required'     => 'Obbligatorio',
    'optional'     => 'Facoltativo',
    'select_one'   => 'Seleziona…',
    'search'       => 'Cerca',

    'error_generic'       => 'Qualcosa è andato storto.',
    'error_network'       => 'Errore di rete',
    'error_not_logged_in' => 'Devi essere autenticato.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Service Desk',
        'browser_title'    => 'Service Desk - ITSM',
        'welcome_heading'  => 'Cosa vuoi fare?',
        'welcome_subtitle' => 'Seleziona un modulo per iniziare',
        'footer'           => 'Service Desk ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Moduli ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Vedetta',     'description' => 'Dashboard unificata delle attenzioni per tutti i moduli'],
        'tickets'        => ['name' => 'Ticket',      'description' => 'Gestisci richieste di supporto, email e problemi degli utenti'],
        'assets'         => ['name' => 'Asset',       'description' => 'Traccia asset IT e assegnazioni agli utenti'],
        'knowledge'      => ['name' => 'Conoscenza',  'description' => 'Crea e consulta articoli della knowledge base'],
        'changes'        => ['name' => 'Modifiche',   'description' => 'Pianifica, traccia e gestisci le modifiche IT'],
        'calendar'       => ['name' => 'Calendario',  'description' => 'Traccia eventi, scadenze e pianificazioni'],
        'morning-checks' => ['name' => 'Controlli',   'description' => 'Registra i controlli giornalieri dell\'infrastruttura'],
        'reporting'      => ['name' => 'Report',      'description' => 'Visualizza log di sistema e analisi'],
        'software'       => ['name' => 'Software',    'description' => 'Sfoglia inventario software e licenze'],
        'forms'          => ['name' => 'Moduli',      'description' => 'Progetta moduli personalizzati e visualizza gli invii'],
        'contracts'      => ['name' => 'Contratti',   'description' => 'Gestisci fornitori, contatti e contratti'],
        'service-status' => ['name' => 'Stato',       'description' => 'Monitora lo stato dei servizi e traccia gli incidenti'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Sfoglia la documentazione del codice generata automaticamente'],
        'lms'            => ['name' => 'LMS',         'description' => 'Sistema di gestione dell\'apprendimento con player SCORM'],
        'process-mapper' => ['name' => 'Processi',    'description' => 'Strumento visuale per diagrammi di flusso e mappatura processi'],
        'tasks'          => ['name' => 'Attività',    'description' => 'Bacheca Kanban e vista elenco per tracciare le attività'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Configuration Management Database'],
        'network-mapper' => ['name' => 'Rete',        'description' => 'Progetta e documenta diagrammi di rete'],
        'system'         => ['name' => 'Sistema',     'description' => 'Amministrazione e configurazione del sistema'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Controlla nuove email',
        'change_password' => 'Cambia password',
        'mfa'             => 'Autenticazione multifattore',
        'trusted_device'  => 'Dispositivo attendibile',
        'logout'          => 'Esci',
        'logout_confirm'  => 'Sei sicuro di voler uscire?',
        'badge_off'       => 'Off',
        'badge_on'        => 'On',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Cambia password',
        'current_password' => 'Password attuale',
        'new_password'     => 'Nuova password',
        'confirm_password' => 'Conferma nuova password',
        'submit'           => 'Cambia password',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Autenticazione multifattore',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Precedente',
        'next'     => 'Successivo',
        'today'    => 'Oggi',

        'months' => [
            'january'   => 'gennaio',
            'february'  => 'febbraio',
            'march'     => 'marzo',
            'april'     => 'aprile',
            'may'       => 'maggio',
            'june'      => 'giugno',
            'july'      => 'luglio',
            'august'    => 'agosto',
            'september' => 'settembre',
            'october'   => 'ottobre',
            'november'  => 'novembre',
            'december'  => 'dicembre',
        ],

        'weekdays' => [
            'monday'    => 'lunedì',
            'tuesday'   => 'martedì',
            'wednesday' => 'mercoledì',
            'thursday'  => 'giovedì',
            'friday'    => 'venerdì',
            'saturday'  => 'sabato',
            'sunday'    => 'domenica',
        ],
    ],
];
