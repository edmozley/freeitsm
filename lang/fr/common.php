<?php
/**
 * Français (fr) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    'save'         => 'Enregistrer',
    'cancel'       => 'Annuler',
    'delete'       => 'Supprimer',
    'add'          => 'Ajouter',
    'edit'         => 'Modifier',
    'close'        => 'Fermer',
    'copy'         => 'Copier',
    'copied'       => 'Copié',
    'retry'        => 'Réessayer',
    'export'       => 'Exporter',
    'open'         => 'Ouvrir',
    'apply'        => 'Appliquer',

    'yes'          => 'Oui',
    'no'           => 'Non',
    'ok'           => 'OK',
    'loading'      => 'Chargement…',
    'saving'       => 'Enregistrement…',
    'saved'        => 'Enregistré',
    'unsaved'      => 'Non enregistré',
    'unsaved_changes' => 'Modifications non enregistrées',
    'failed'       => 'Échec',

    'just_now'     => 'à l\'instant',
    'today'        => 'Aujourd\'hui',
    'yesterday'    => 'Hier',

    'required'     => 'Obligatoire',
    'optional'     => 'Facultatif',
    'select_one'   => 'Sélectionner…',
    'search'       => 'Rechercher',

    'error_generic'       => 'Une erreur est survenue.',
    'error_network'       => 'Erreur réseau',
    'error_not_logged_in' => 'Vous devez être connecté.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Centre de services',
        'browser_title'    => 'Centre de services - ITSM',
        'welcome_heading'  => 'Que souhaitez-vous faire ?',
        'welcome_subtitle' => 'Sélectionnez un module pour commencer',
        'footer'           => 'Centre de services ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Modules ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Veille',       'description' => 'Tableau de bord unifié des alertes pour tous les modules'],
        'tickets'        => ['name' => 'Tickets',      'description' => 'Gérez les demandes d\'assistance, les e-mails et les problèmes utilisateurs'],
        'assets'         => ['name' => 'Actifs',       'description' => 'Suivez les actifs informatiques et les affectations utilisateurs'],
        'knowledge'      => ['name' => 'Connaissances','description' => 'Créez et consultez les articles de la base de connaissances'],
        'changes'        => ['name' => 'Changements',  'description' => 'Planifiez, suivez et gérez les changements informatiques'],
        'calendar'       => ['name' => 'Calendrier',   'description' => 'Suivez les événements, les échéances et les plannings'],
        'morning-checks' => ['name' => 'Vérifications','description' => 'Enregistrez les vérifications quotidiennes de l\'infrastructure'],
        'reporting'      => ['name' => 'Rapports',     'description' => 'Consultez les journaux système et les analyses'],
        'software'       => ['name' => 'Logiciels',    'description' => 'Parcourez l\'inventaire logiciel et les licences'],
        'forms'          => ['name' => 'Formulaires',  'description' => 'Concevez des formulaires personnalisés et consultez les soumissions'],
        'contracts'      => ['name' => 'Contrats',     'description' => 'Gérez les fournisseurs, les contacts et les contrats'],
        'service-status' => ['name' => 'Statut',       'description' => 'Surveillez la santé des services et suivez les incidents'],
        'wiki'           => ['name' => 'Wiki',         'description' => 'Parcourez la documentation auto-générée du code'],
        'lms'            => ['name' => 'LMS',          'description' => 'Système de gestion d\'apprentissage avec lecteur SCORM'],
        'process-mapper' => ['name' => 'Processus',    'description' => 'Outil de cartographie de processus et de flux'],
        'tasks'          => ['name' => 'Tâches',       'description' => 'Tableau Kanban et vue liste pour suivre les tâches'],
        'cmdb'           => ['name' => 'CMDB',         'description' => 'Base de données de gestion des configurations'],
        'network-mapper' => ['name' => 'Réseau',       'description' => 'Concevez et documentez des schémas réseau'],
        'system'         => ['name' => 'Système',      'description' => 'Administration et configuration du système'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Vérifier les nouveaux e-mails',
        'change_password' => 'Changer le mot de passe',
        'mfa'             => 'Authentification multifacteur',
        'trusted_device'  => 'Appareil de confiance',
        'logout'          => 'Déconnexion',
        'logout_confirm'  => 'Voulez-vous vraiment vous déconnecter ?',
        'badge_off'       => 'Inactif',
        'badge_on'        => 'Actif',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Changer le mot de passe',
        'current_password' => 'Mot de passe actuel',
        'new_password'     => 'Nouveau mot de passe',
        'confirm_password' => 'Confirmer le nouveau mot de passe',
        'submit'           => 'Changer le mot de passe',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Authentification multifacteur',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Précédent',
        'next'     => 'Suivant',
        'today'    => 'Aujourd\'hui',

        'months' => [
            'january'   => 'janvier',
            'february'  => 'février',
            'march'     => 'mars',
            'april'     => 'avril',
            'may'       => 'mai',
            'june'      => 'juin',
            'july'      => 'juillet',
            'august'    => 'août',
            'september' => 'septembre',
            'october'   => 'octobre',
            'november'  => 'novembre',
            'december'  => 'décembre',
        ],

        'weekdays' => [
            'monday'    => 'lundi',
            'tuesday'   => 'mardi',
            'wednesday' => 'mercredi',
            'thursday'  => 'jeudi',
            'friday'    => 'vendredi',
            'saturday'  => 'samedi',
            'sunday'    => 'dimanche',
        ],
    ],
];
