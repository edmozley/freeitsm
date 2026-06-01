<?php
/**
 * Français (fr) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Calendrier',

    'nav' => [
        'calendar' => 'Calendrier',
        'table'    => 'Tableau',
        'settings' => 'Paramètres',
        'help'     => 'Aide',
    ],

    'sidebar' => [
        'new_event'   => 'Nouvel événement',
        'categories'  => 'Catégories',
        'none'        => 'Aucune catégorie trouvée',
    ],

    'event' => [
        'modal_new'      => 'Nouvel événement',
        'modal_edit'     => 'Modifier l\'événement',
        'title'          => 'Titre',
        'title_ph'       => 'Titre de l\'événement...',
        'category'       => 'Catégorie',
        'category_none'  => '-- Sélectionner une catégorie --',
        'start_date'     => 'Date de début',
        'start_time'     => 'Heure de début',
        'end_date'       => 'Date de fin',
        'end_time'       => 'Heure de fin',
        'all_day'        => 'Événement sur toute la journée',
        'location'       => 'Lieu',
        'location_ph'    => 'Lieu (facultatif)',
        'description'    => 'Description',
        'description_ph' => 'Description (facultative)',
        'delete'         => 'Supprimer',
        'cancel'         => 'Annuler',
        'save'           => 'Enregistrer',
        'edit'           => 'Modifier',
        'delete_confirm' => 'Voulez-vous vraiment supprimer cet événement ?',
        'title_required' => 'Veuillez saisir un titre d\'événement',
        'start_required' => 'Veuillez sélectionner une date de début',
    ],

    'table' => [
        'start_required' => 'La date et l\'heure de début sont obligatoires',
        'save_failed'    => 'Échec de l\'enregistrement',
        'col_title'       => 'Titre',
        'col_category'    => 'Catégorie',
        'col_start'       => 'Début',
        'col_end'         => 'Fin',
        'col_all_day'     => 'Toute la journée',
        'col_location'    => 'Lieu',
        'col_description' => 'Description',
        'col_created_by'  => 'Créé par',
        'col_created'     => 'Créé',
    ],

    'settings' => [
        'title'           => 'Paramètres du calendrier',
        'tab_categories'  => 'Catégories',
        'heading'         => 'Catégories d\'événements',
        'add'             => 'Ajouter',
        'intro'           => 'Gérez les catégories utilisées pour organiser les événements du calendrier. Chaque catégorie peut avoir une couleur personnalisée pour une identification facile.',
        'col_name'        => 'Nom',
        'col_description' => 'Description',
        'col_status'      => 'État',
        'active'          => 'Actif',
        'inactive'        => 'Inactif',
        'edit'            => 'Modifier',
        'delete'          => 'Supprimer',
        'empty'           => 'Aucune catégorie pour l\'instant. Cliquez sur <strong>Ajouter</strong> pour en créer une.',
        'load_error'      => 'Erreur lors du chargement des catégories',

        'modal_add'       => 'Ajouter une catégorie',
        'modal_edit'      => 'Modifier la catégorie',
        'modal_name'      => 'Nom',
        'modal_name_ph'   => 'p. ex. Expiration de certificat',
        'modal_description'    => 'Description',
        'modal_description_ph' => 'Description facultative...',
        'modal_colour'    => 'Couleur',
        'modal_active'    => 'Actif',
        'cancel'          => 'Annuler',
        'save'            => 'Enregistrer',
        'name_required'   => 'Veuillez saisir un nom de catégorie',

        'delete_title'    => 'Supprimer la catégorie',
        'delete_confirm'  => 'Voulez-vous vraiment supprimer « {name} » ? Cette action est irréversible.',
        'delete_this'     => 'cette catégorie',
    ],

    'toast' => [
        'saved'         => 'Enregistré',
        'deleted'       => 'Supprimé',
        'save_failed'   => 'Échec de l\'enregistrement',
        'delete_failed' => 'Échec de la suppression',
    ],

    'help' => [
        'page_title'  => 'Guide du calendrier',
        'guide'       => 'Guide',
        'hero_title'  => 'Guide du calendrier',
        'hero_sub'    => 'Suivez les certificats, contrats, fenêtres de maintenance et événements récurrents &mdash; le tout au même endroit.',

        'nav_overview'  => 'Aperçu',
        'nav_views'     => 'Affichages du calendrier',
        'nav_creating'  => 'Créer des événements',
        'nav_categories'=> 'Catégories d\'événements',
        'nav_settings'  => 'Paramètres',
        'nav_tips'      => 'Astuces rapides',

        // Section 1 — Overview
        'overview_heading' => 'Aperçu',
        'overview_intro'   => 'Le module Calendrier offre à votre équipe informatique une chronologie partagée pour tout ce qui compte. Au lieu de vous fier à des feuilles de calcul ou à des rappels personnels, vous pouvez suivre les dates d\'expiration des certificats, les renouvellements de contrats, les fenêtres de maintenance planifiées et les événements d\'équipe dans un seul calendrier à code couleur que tout le centre de services peut consulter.',
        'feature_tracking_title' => 'Suivi des événements',
        'feature_tracking_desc'  => 'Créez des événements avec des titres, des dates, des heures, des lieux et des descriptions. Chaque événement est visible par l\'équipe, afin que rien ne passe entre les mailles du filet.',
        'feature_views_title'    => 'Affichages multiples',
        'feature_views_desc'     => 'Basculez entre les affichages mois, semaine et jour pour obtenir le niveau de détail dont vous avez besoin. L\'affichage mois donne une vue d\'ensemble ; les affichages semaine et jour montrent des créneaux horaires précis.',
        'feature_categories_title' => 'Catégories',
        'feature_categories_desc'  => 'Organisez les événements en catégories à code couleur comme les certificats, les contrats, la maintenance et les réunions. Filtrez le calendrier pour n\'afficher que ce qui vous intéresse.',
        'feature_scheduling_title' => 'Planification',
        'feature_scheduling_desc'  => 'Planifiez des fenêtres de maintenance, définissez des événements sur toute la journée pour les échéances et programmez des travaux récurrents. Le calendrier aide votre équipe à se coordonner et à éviter les conflits.',

        // Section 2 — Views
        'views_heading' => 'Affichages du calendrier',
        'views_intro'   => 'Le calendrier propose trois affichages pour zoomer ou dézoomer selon vos besoins. Basculez entre eux à l\'aide des boutons de bascule situés dans le coin supérieur droit de l\'en-tête du calendrier.',
        'views_month_title' => 'Affichage mois',
        'views_month_desc'  => 'L\'affichage par défaut. Affiche une grille complète du mois avec les événements représentés par des barres colorées sur chaque jour. Idéal pour obtenir une vue d\'ensemble de ce qui arrive au sein de l\'équipe.',
        'views_week_title'  => 'Affichage semaine',
        'views_week_desc'   => 'Affiche sept jours avec des créneaux horaires. Les événements sont positionnés en fonction de leurs heures de début et de fin, ce qui facilite le repérage des conflits de planification.',
        'views_day_title'   => 'Affichage jour',
        'views_day_desc'    => 'Se concentre sur une seule journée avec un détail horaire précis. Utilisez-le lorsque vous avez besoin de voir exactement ce qui se passe heure par heure lors d\'une journée chargée.',
        'views_nav'         => 'Utilisez les flèches de navigation à côté du titre mois/semaine/jour pour avancer et reculer dans le temps. Le bouton <strong>Aujourd\'hui</strong> vous ramène directement à la date du jour, quelle que soit la distance parcourue dans la navigation.',
        'views_flow_today'  => 'Bouton Aujourd\'hui',
        'views_flow_nav'    => 'Naviguer précédent/suivant',
        'views_flow_choose' => 'Choisir l\'affichage',
        'views_flow_click'  => 'Cliquer sur un événement',
        'views_tip'         => 'Cliquez sur n\'importe quel événement du calendrier pour ouvrir une fenêtre d\'aperçu rapide affichant le titre, l\'heure, le lieu et la description. De là, vous pouvez ouvrir le formulaire de modification complet.',

        // Section 3 — Creating events
        'creating_heading' => 'Créer des événements',
        'creating_intro'   => 'Ajouter des événements au calendrier est simple. Cliquez sur le bouton <strong>+ Nouvel événement</strong> dans la barre latérale pour ouvrir le formulaire d\'événement. Remplissez les détails et enregistrez &mdash; l\'événement apparaît immédiatement sur le calendrier.',
        'creating_step1'   => '<strong>Cliquez sur + Nouvel événement</strong> &mdash; le bouton se trouve dans la barre latérale du calendrier, à gauche. Cela ouvre la fenêtre de création d\'événement.',
        'creating_step2'   => '<strong>Saisissez un titre</strong> &mdash; donnez à l\'événement un nom clair et descriptif. Par exemple : « Renouvellement du certificat SSL &mdash; webserver01 » ou « Fenêtre de correctifs mensuelle ».',
        'creating_step3'   => '<strong>Choisissez une catégorie</strong> &mdash; sélectionnez-en une dans la liste déroulante pour attribuer un code couleur à l\'événement. Les catégories se configurent dans les Paramètres et vous aident à filtrer le calendrier par la suite.',
        'creating_step4'   => '<strong>Définissez les dates et les heures</strong> &mdash; choisissez une date de début et, facultativement, une date de fin. Ajoutez des heures de début et de fin pour les événements horodatés, ou cochez « Événement sur toute la journée » pour les échéances et les entrées d\'une journée entière.',
        'creating_step5'   => '<strong>Ajoutez un lieu et une description</strong> &mdash; précisez facultativement où l\'événement se déroule et ajoutez des notes. Ces détails s\'affichent dans la fenêtre d\'aperçu rapide lorsqu\'on clique sur l\'événement.',
        'creating_step6'   => '<strong>Enregistrez</strong> &mdash; cliquez sur Enregistrer et l\'événement est créé. Il apparaît immédiatement sur le calendrier, avec le code couleur de sa catégorie.',
        'creating_tip'     => 'Pour modifier un événement existant, cliquez dessus dans le calendrier pour ouvrir la fenêtre, puis cliquez sur <strong>Modifier</strong>. Le même formulaire s\'ouvre pré-rempli avec les détails actuels de l\'événement. Vous pouvez aussi supprimer des événements depuis le formulaire de modification.',

        // Section 4 — Categories
        'categories_heading' => 'Catégories d\'événements',
        'categories_intro'   => 'Les catégories sont l\'épine dorsale de l\'organisation du calendrier. Chaque catégorie a un nom et une couleur, de sorte que les événements sont immédiatement reconnaissables d\'un coup d\'œil. La barre latérale affiche toutes les catégories disponibles avec des cases à cocher &mdash; décochez une catégorie pour masquer ces événements du calendrier.',
        'categories_certificates' => '<strong>Certificats</strong> &mdash; suivez les dates d\'expiration des certificats SSL/TLS, les certificats de signature de code et autres identifiants nécessitant un renouvellement périodique',
        'categories_contracts'    => '<strong>Contrats</strong> &mdash; consignez les dates de renouvellement des contrats fournisseurs, les expirations de licences et les jalons de revue de SLA pour que rien n\'expire à l\'improviste',
        'categories_maintenance'  => '<strong>Maintenance</strong> &mdash; planifiez les fenêtres de maintenance pour les serveurs, les équipements réseau et l\'infrastructure. Votre équipe et les parties prenantes peuvent voir précisément quand une interruption est prévue',
        'categories_meetings'     => '<strong>Réunions</strong> &mdash; consignez les points d\'équipe, les réunions du CAB, les appels avec les fournisseurs et autres rendez-vous récurrents pertinents pour les opérations informatiques',
        'categories_custom'       => '<strong>Catégories personnalisées</strong> &mdash; ajoutez vos propres catégories dans les Paramètres pour s\'adapter au flux de travail de votre équipe. Les ajouts courants incluent « Déploiements », « Audits » et « Formation »',
        'categories_filtering'    => 'Le filtrage est appliqué en temps réel. Lorsque vous décochez une catégorie dans la barre latérale, les événements de cette catégorie sont masqués immédiatement sans recharger la page. Recochez-la pour les faire réapparaître.',
        'categories_tip'          => 'Le code couleur fonctionne dans les trois affichages. En affichage mois, les événements apparaissent sous forme de barres colorées. En affichages semaine et jour, les événements sont représentés par des blocs colorés positionnés à la bonne heure.',

        // Section 5 — Settings
        'settings_heading' => 'Paramètres',
        'settings_intro'   => 'La page Paramètres vous permet de configurer le fonctionnement du calendrier pour votre équipe. Accédez-y en cliquant sur <strong>Paramètres</strong> dans la barre de navigation en haut du module Calendrier.',
        'settings_step1'   => '<strong>Gérez les catégories</strong> &mdash; ajoutez, modifiez ou supprimez des catégories d\'événements. Chaque catégorie a un nom et une couleur. Les modifications prennent effet immédiatement dans tout le calendrier pour tous les utilisateurs.',
        'settings_step2'   => '<strong>Définissez les couleurs</strong> &mdash; choisissez une couleur pour chaque catégorie à l\'aide du sélecteur de couleur. Choisissez des couleurs distinctes pour que les événements soient faciles à différencier sur un calendrier chargé.',
        'settings_step3'   => '<strong>Renommez les catégories</strong> &mdash; cliquez sur le nom d\'une catégorie pour le modifier. Les événements existants affectés à cette catégorie sont mis à jour automatiquement.',
        'settings_step4'   => '<strong>Supprimez les catégories</strong> &mdash; retirez les catégories dont vous n\'avez plus besoin. Les événements d\'une catégorie supprimée ne sont pas retirés &mdash; ils restent sur le calendrier sans affectation de catégorie.',
        'settings_tip'     => 'Gardez votre liste de catégories ciblée. Avoir trop de catégories peut surcharger la barre latérale et rendre le code couleur plus difficile à lire. Visez 5&ndash;10 catégories bien définies qui couvrent les besoins de votre équipe.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Astuces rapides',
        'tips_maintenance_title' => 'Fenêtres de maintenance',
        'tips_maintenance_desc'  => 'Créez des événements sur toute la journée ou des blocs horodatés pour la maintenance planifiée. Indiquez les systèmes concernés dans la description afin que les analystes puissent vérifier rapidement si une interruption est prévue.',
        'tips_certificates_title' => 'Renouvellements de certificats',
        'tips_certificates_desc'  => 'Ajoutez des événements 30 jours avant l\'expiration de chaque certificat. Cela donne à votre équipe suffisamment de délai pour renouveler sans risquer une interruption due à un certificat expiré.',
        'tips_contracts_title'   => 'Suivi des contrats',
        'tips_contracts_desc'    => 'Consignez les dates de renouvellement des contrats sous forme d\'événements sur toute la journée. Ajoutez le nom du fournisseur et la valeur du contrat dans la description afin d\'avoir l\'information à portée de main au moment de négocier.',
        'tips_filters_title'     => 'Utilisez les filtres de catégorie',
        'tips_filters_desc'      => 'Lorsque le calendrier devient chargé, décochez les catégories dont vous n\'avez pas besoin. Par exemple, masquez les réunions lorsque seules les prochaines fenêtres de maintenance vous intéressent.',
    ],
];
