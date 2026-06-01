<?php
/**
 * Français (fr) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'État des services',

    'nav' => [
        'status'   => 'État',
        'settings' => 'Paramètres',
        'help'     => 'Aide',
    ],

    'board' => [
        'services'        => 'Services',
        'service_count'   => '{count} services',
        'loading'         => 'Chargement...',
        'no_services'     => 'Aucun service configuré. Allez dans Paramètres pour ajouter des services.',
        'incidents'       => 'Incidents',
        'new'             => 'Nouveau',
        'col_title'       => 'Titre',
        'col_status'      => 'État',
        'col_affected'    => 'Services concernés',
        'col_updated'     => 'Mis à jour',
        'no_incidents'    => 'Aucun incident à afficher.',
        'none'            => 'Aucun',
    ],

    'modal' => [
        'new_incident'        => 'Nouvel incident',
        'edit_incident'       => 'Modifier l\'incident',
        'title'               => 'Titre',
        'title_placeholder'   => 'Brève description de l\'incident',
        'status'              => 'État',
        'comment'             => 'Commentaire',
        'comment_placeholder' => 'Détails sur l\'incident...',
        'affected_services'   => 'Services concernés',
        'add_service'         => '+ Ajouter un service',
        'delete'              => 'Supprimer',
        'cancel'              => 'Annuler',
        'save'                => 'Enregistrer',
    ],

    'toast' => [
        'incident_saved'   => 'Incident enregistré',
        'incident_deleted' => 'Incident supprimé',
        'save_failed'      => 'Échec de l\'enregistrement',
        'delete_failed'    => 'Échec de la suppression',
        'save_incident_failed'   => 'Échec de l\'enregistrement de l\'incident',
        'delete_incident_failed' => 'Échec de la suppression de l\'incident',
        'saved'            => 'Enregistré',
        'deleted'          => 'Supprimé',
        'save_service_failed'    => 'Échec de l\'enregistrement du service',
        'delete_service_failed'  => 'Échec de la suppression du service',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Supprimer l\'incident',
        'delete_incident_message' => 'Supprimer cet incident ?',
        'delete_title'            => 'Supprimer',
        'delete_message'          => 'Supprimer « {name} » ?',
        'delete_label'            => 'Supprimer',
    ],

    'settings' => [
        'tab_services'     => 'Services',
        'tab_statuses'     => 'États',
        'tab_impacts'      => 'Niveaux d\'impact',

        'services_heading' => 'Services',
        'statuses_heading' => 'États des incidents',
        'impacts_heading'  => 'Niveaux d\'impact',
        'add'              => 'Ajouter',
        'loading'          => 'Chargement...',
        'no_services'      => 'Aucun service pour l\'instant. Cliquez sur Ajouter pour en créer un.',
        'no_items'         => 'Aucun élément trouvé',
        'load_failed'      => 'Échec du chargement des données',
        'error_prefix'     => 'Erreur : {message}',

        'statuses_intro_html' => 'États du flux de travail des incidents de service. Les états marqués comme <em>résolu</em> clôturent l\'incident — horodatant automatiquement <code>resolved_datetime</code> et retirant l\'incident du tableau de bord actif. Un seul état est celui par défaut des nouveaux incidents.',
        'impacts_intro_html'  => 'Niveaux de gravité affichés sous forme de badge sur chaque carte de service. L\'<strong>ordre de gravité</strong> détermine le classement du « pire impact actuel » sur le tableau de bord — plus bas = pire (1 = panne majeure, 5 = opérationnel). Deux lignes peuvent partager le même ordre.',

        'col_name'        => 'Nom',
        'col_description' => 'Description',
        'col_order'       => 'Ordre',
        'col_status'      => 'État',
        'col_actions'     => 'Actions',
        'col_colour'      => 'Couleur',
        'col_resolved'    => 'Résolu',
        'col_default'     => 'Par défaut',
        'col_severity'    => 'Gravité',

        'active'          => 'Actif',
        'inactive'        => 'Inactif',
        'yes'             => 'Oui',
        'no'              => 'Non',
        'edit'            => 'Modifier',
        'delete'          => 'Supprimer',

        'kind_status'     => 'état',
        'kind_impact'     => 'niveau d\'impact',

        // Service modal
        'add_service'     => 'Ajouter un service',
        'edit_service'    => 'Modifier le service',
        'field_name'      => 'Nom',
        'field_description' => 'Description',
        'field_order'     => 'Ordre d\'affichage',
        'field_active'    => 'Actif',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Ajouter un élément',
        'add_kind'        => 'Ajouter : {kind}',
        'edit_kind'       => 'Modifier : {kind}',
        'field_colour'    => 'Couleur',
        'field_resolved'  => 'Compte comme résolu',
        'resolved_help_html' => 'Les incidents dans cet état horodatent automatiquement <code>resolved_datetime</code> et disparaissent du tableau de bord actif.',
        'field_severity'  => 'Ordre de gravité',
        'severity_help'   => '1 = pire (panne majeure). Plus élevé = moins grave.',
        'field_default'   => 'Par défaut',

        'cancel'          => 'Annuler',
        'save'            => 'Enregistrer',
    ],

    'help' => [
        'page_title' => 'Guide de l\'état des services',
        'guide'      => 'Guide',

        'nav_overview'  => 'Aperçu',
        'nav_dashboard' => 'Le tableau de bord d\'état',
        'nav_services'  => 'Gérer les services',
        'nav_history'   => 'Historique des incidents',
        'nav_settings'  => 'Paramètres',
        'nav_tips'      => 'Conseils rapides',

        'hero_title' => 'Guide de l\'état des services',
        'hero_sub'   => 'Surveillez vos services informatiques, communiquez les incidents et tenez les parties prenantes informées en temps réel.',

        // Section 1: Overview
        'overview_heading' => 'Aperçu',
        'overview_intro'   => 'Le module État des services vous offre une vue centralisée de la santé de chaque service informatique dont dépend votre organisation. En cas de problème, vous pouvez enregistrer des incidents, mettre à jour les services concernés et tenir les utilisateurs informés tout au long du processus de résolution.',
        'feature_dashboard_title' => 'Tableau de bord d\'état',
        'feature_dashboard_desc'  => 'Visualisez d\'un coup d\'œil la santé actuelle de chaque service. Des badges à code couleur indiquent si chaque service est opérationnel, dégradé, en maintenance ou en panne.',
        'feature_incident_title'  => 'Suivi des incidents',
        'feature_incident_desc'   => 'Enregistrez les incidents avec un titre, des mises à jour d\'état et des commentaires. Liez les services concernés à chaque incident pour que chacun sache exactement ce qui est touché et pourquoi.',
        'feature_management_title' => 'Gestion des services',
        'feature_management_desc'  => 'Configurez votre catalogue de services dans les paramètres. Ajoutez des services avec un nom, une description et un ordre d\'affichage. Activez ou désactivez les services à mesure que votre infrastructure évolue.',
        'feature_comms_title' => 'Communication',
        'feature_comms_desc'  => 'Tenez les parties prenantes informées grâce à des mises à jour d\'état en temps réel. Chaque incident comporte un état et un fil de commentaires afin que les utilisateurs puissent suivre l\'avancement de la résolution sans solliciter le centre de services.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Le tableau de bord d\'état',
        'dashboard_p1'      => 'Le tableau de bord est la première chose que vous voyez en ouvrant le module État des services. Il affiche une grille de cartes de service, chacune indiquant le nom du service, une brève description et un badge d\'impact à code couleur reflétant son pire état actuel. Sous la grille se trouve le tableau des incidents répertoriant tous les incidents récents et actifs.',
        'dashboard_p2_html' => 'Chaque carte de service reflète automatiquement le niveau d\'impact le plus grave qui lui est attribué par un incident actif (non résolu). Lorsque tous les incidents affectant un service sont résolus, celui-ci revient à l\'état <strong>Opérationnel</strong>.',
        'status_levels'     => 'Niveaux d\'état',
        'level_operational_name' => 'Opérationnel',
        'level_operational_desc' => 'Le service fonctionne normalement, sans problème connu. C\'est l\'état par défaut de tous les services sains.',
        'level_degraded_name'    => 'Performance dégradée',
        'level_degraded_desc'    => 'Le service est disponible mais fonctionne plus lentement que prévu ou avec des fonctionnalités réduites. Les utilisateurs peuvent constater des ralentissements.',
        'level_maintenance_name' => 'En maintenance',
        'level_maintenance_desc' => 'Interruption planifiée ou fenêtre de maintenance. Le service peut être temporairement indisponible pendant les travaux.',
        'level_outage_name'      => 'Panne majeure',
        'level_outage_desc'      => 'Le service est totalement indisponible. C\'est l\'état le plus grave et il doit déclencher une investigation immédiate.',
        'dashboard_tip'     => 'Les niveaux d\'impact sont hiérarchiques. Si un service est lié à plusieurs incidents actifs, le tableau de bord affiche le pire impact. Par exemple, un incident marquant un service comme Dégradé et un autre le marquant comme Panne majeure aboutiront à l\'affichage de Panne majeure.',

        // Section 3: Managing services
        'services_heading_html' => 'Gérer les services &amp; enregistrer les incidents',
        'services_intro'        => 'Les services sont les éléments de base de votre page d\'état. Chacun représente un service informatique, un système ou un composant d\'infrastructure dont dépendent vos utilisateurs. En cas de problème, vous créez un incident et le liez aux services concernés.',
        'add_incident_heading'  => 'Ajouter un nouvel incident',
        'add_incident_step1_html' => '<strong>Cliquez sur « Nouveau »</strong> sur le tableau de bord pour ouvrir le formulaire d\'incident.',
        'add_incident_step2_html' => '<strong>Saisissez un titre</strong> &mdash; une description brève et claire du problème. Par exemple : « Retards de distribution des e-mails » ou « Passerelle VPN injoignable ».',
        'add_incident_step3_html' => '<strong>Définissez l\'état</strong> &mdash; choisissez Investigation, Identifié, Tiers, Surveillance ou Résolu. Commencez par Investigation et mettez à jour au fur et à mesure.',
        'add_incident_step4_html' => '<strong>Ajoutez un commentaire</strong> &mdash; décrivez ce qui est connu à ce stade, les actions entreprises et les solutions de contournement disponibles pour les utilisateurs.',
        'add_incident_step5_html' => '<strong>Liez les services concernés</strong> &mdash; ajoutez un ou plusieurs services et choisissez le niveau d\'impact de chacun (Panne majeure, Panne partielle, Dégradé, Maintenance, Opérationnel ou Aucune perturbation).',
        'add_incident_step6_html' => '<strong>Enregistrez</strong> &mdash; l\'incident apparaît dans le tableau et les cartes des services concernés se mettent à jour immédiatement sur le tableau de bord.',
        'workflow_heading'  => 'Flux de travail de l\'état des incidents',
        'workflow_investigating' => 'Investigation',
        'workflow_identified'    => 'Identifié',
        'workflow_monitoring'    => 'Surveillance',
        'workflow_resolved'      => 'Résolu',
        'workflow_note_html'     => 'Utilisez <strong>Tiers</strong> lorsque la cause racine relève d\'un fournisseur ou prestataire externe.',
        'services_tip'      => 'Vous pouvez modifier n\'importe quel incident en cliquant sur son titre dans le tableau. Mettez à jour l\'état, ajoutez de nouveaux commentaires ou modifiez les services concernés à mesure que la situation évolue. Tenir les incidents à jour est essentiel à une communication transparente.',

        // Section 4: Incident history
        'history_heading' => 'Historique des incidents',
        'history_p1'      => 'Le tableau des incidents du tableau de bord affiche à la fois les incidents actifs et résolus, vous offrant une chronologie complète de la santé des services. Chaque ligne indique le titre de l\'incident, son état actuel, les services concernés avec leurs niveaux d\'impact et l\'horodatage de la dernière mise à jour.',
        'history_field_title_html'    => '<strong>Titre</strong> &mdash; un lien cliquable qui ouvre l\'incident pour modification. Utilisez des titres clairs et descriptifs pour que l\'historique soit facile à parcourir.',
        'history_field_status_html'   => '<strong>État</strong> &mdash; badge à code couleur indiquant la phase d\'investigation actuelle (Investigation, Identifié, Tiers, Surveillance ou Résolu).',
        'history_field_affected_html' => '<strong>Services concernés</strong> &mdash; badges étiquetés indiquant chaque service lié avec la couleur de son niveau d\'impact. D\'un coup d\'œil, vous voyez ce qui est touché et à quel point.',
        'history_field_updated_html'  => '<strong>Mis à jour</strong> &mdash; l\'horodatage de la modification la plus récente. Les incidents résolus sont affichés en texte atténué afin que les incidents actifs ressortent visuellement.',
        'history_p2'      => 'Les incidents résolus restent visibles dans le tableau à titre d\'historique. Cela permet de repérer facilement les problèmes récurrents, d\'examiner la manière dont les incidents passés ont été traités et d\'identifier des tendances pouvant révéler des problèmes sous-jacents.',
        'history_tip'     => 'Examiner régulièrement votre historique des incidents vous aide à identifier les services fréquemment perturbés. Si le même service apparaît dans plusieurs incidents, il est peut-être temps d\'investiguer plus en profondeur la cause racine ou de planifier une mise à niveau de l\'infrastructure.',

        // Section 5: Settings
        'settings_heading' => 'Paramètres',
        'settings_p1'      => 'La page Paramètres est l\'endroit où vous construisez et maintenez votre catalogue de services. Chaque service apparaissant sur le tableau de bord d\'état doit d\'abord y être configuré.',
        'settings_step1_html' => '<strong>Ajoutez un service</strong> &mdash; cliquez sur « Ajouter » et indiquez un nom (p. ex. « E-mail », « VPN », « Système ERP ») ainsi qu\'une description facultative expliquant ce que fait le service.',
        'settings_step2_html' => '<strong>Définissez l\'ordre d\'affichage</strong> &mdash; le numéro d\'ordre détermine où le service apparaît dans la grille du tableau de bord. Les numéros les plus bas apparaissent en premier, alors placez vos services les plus critiques en haut.',
        'settings_step3_html' => '<strong>Activez/désactivez</strong> &mdash; désactiver un service le retire du tableau de bord sans le supprimer. Utile pour les services mis hors service ou les systèmes saisonniers.',
        'settings_step4_html' => '<strong>Modifiez ou supprimez</strong> &mdash; utilisez les boutons d\'action de chaque ligne pour mettre à jour les détails d\'un service ou le supprimer entièrement. La modification est toujours préférable à la suppression afin que les liens historiques vers les incidents restent intacts.',
        'settings_tip'     => 'Considérez votre catalogue de services comme le fondement de votre page d\'état. Prenez le temps de bien choisir les noms et descriptions &mdash; c\'est ce que vos utilisateurs et parties prenantes verront lorsqu\'ils consulteront la santé de votre environnement informatique.',

        // Section 6: Quick tips
        'tips_heading' => 'Conseils rapides',
        'tip_communicate_title' => 'Communiquez tôt',
        'tip_communicate_desc'  => 'Publiez un incident dès que vous savez qu\'un problème survient, même si vous n\'avez pas encore tous les détails. Reconnaître rapidement un problème renforce la confiance de vos utilisateurs.',
        'tip_update_title' => 'Mettez à jour fréquemment',
        'tip_update_desc'  => 'Des mises à jour d\'état régulières &mdash; même si rien n\'a changé &mdash; montrent aux utilisateurs que le problème est activement traité. Le silence engendre frustration et tickets de support.',
        'tip_review_title' => 'Analysez les tendances',
        'tip_review_desc'  => 'Consultez régulièrement votre historique des incidents. Si le même service revient sans cesse, cela peut révéler un problème d\'infrastructure plus profond à traiter de manière proactive.',
        'tip_maintenance_title' => 'Planifiez la maintenance',
        'tip_maintenance_desc'  => 'Utilisez le niveau d\'impact Maintenance pour les travaux planifiés. Créer un incident à l\'avance permet d\'informer les utilisateurs d\'une interruption programmée avant qu\'elle ne survienne.',
    ],
];
