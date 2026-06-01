<?php
/**
 * Nederlands (nl) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Servicestatus',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Instellingen',
        'help'     => 'Help',
    ],

    'board' => [
        'services'        => 'Services',
        'service_count'   => '{count} services',
        'loading'         => 'Laden...',
        'no_services'     => 'Geen services geconfigureerd. Ga naar Instellingen om services toe te voegen.',
        'incidents'       => 'Incidenten',
        'new'             => 'Nieuw',
        'col_title'       => 'Titel',
        'col_status'      => 'Status',
        'col_affected'    => 'Getroffen services',
        'col_updated'     => 'Bijgewerkt',
        'no_incidents'    => 'Geen incidenten om weer te geven.',
        'none'            => 'Geen',
    ],

    'modal' => [
        'new_incident'        => 'Nieuw incident',
        'edit_incident'       => 'Incident bewerken',
        'title'               => 'Titel',
        'title_placeholder'   => 'Korte beschrijving van het incident',
        'status'              => 'Status',
        'comment'             => 'Opmerking',
        'comment_placeholder' => 'Details over het incident...',
        'affected_services'   => 'Getroffen services',
        'add_service'         => '+ Service toevoegen',
        'delete'              => 'Verwijderen',
        'cancel'              => 'Annuleren',
        'save'                => 'Opslaan',
    ],

    'toast' => [
        'incident_saved'   => 'Incident opgeslagen',
        'incident_deleted' => 'Incident verwijderd',
        'save_failed'      => 'Opslaan mislukt',
        'delete_failed'    => 'Verwijderen mislukt',
        'save_incident_failed'   => 'Opslaan van incident mislukt',
        'delete_incident_failed' => 'Verwijderen van incident mislukt',
        'saved'            => 'Opgeslagen',
        'deleted'          => 'Verwijderd',
        'save_service_failed'    => 'Opslaan van service mislukt',
        'delete_service_failed'  => 'Verwijderen van service mislukt',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Incident verwijderen',
        'delete_incident_message' => 'Dit incident verwijderen?',
        'delete_title'            => 'Verwijderen',
        'delete_message'          => '"{name}" verwijderen?',
        'delete_label'            => 'Verwijderen',
    ],

    'settings' => [
        'tab_services'     => 'Services',
        'tab_statuses'     => 'Statussen',
        'tab_impacts'      => 'Impactniveaus',

        'services_heading' => 'Services',
        'statuses_heading' => 'Incidentstatussen',
        'impacts_heading'  => 'Impactniveaus',
        'add'              => 'Toevoegen',
        'loading'          => 'Laden...',
        'no_services'      => 'Nog geen services. Klik op Toevoegen om er een te maken.',
        'no_items'         => 'Geen items gevonden',
        'load_failed'      => 'Gegevens laden mislukt',
        'error_prefix'     => 'Fout: {message}',

        'statuses_intro_html' => 'Workflowstatussen voor service-incidenten. Statussen die als <em>opgelost</em> zijn gemarkeerd sluiten het incident — ze stempelen automatisch <code>resolved_datetime</code> en verwijderen het incident van het actieve dashboard. Precies één status is de standaard voor nieuwe incidenten.',
        'impacts_intro_html'  => 'Ernstcategorieën die als badge op elke servicekaart worden getoond. De <strong>ernstvolgorde</strong> bepaalt de "ernstigste huidige impact"-ordening op het dashboard — lager = erger (1 = grote storing, 5 = operationeel). Twee rijen kunnen dezelfde volgorde delen.',

        'col_name'        => 'Naam',
        'col_description' => 'Beschrijving',
        'col_order'       => 'Volgorde',
        'col_status'      => 'Status',
        'col_actions'     => 'Acties',
        'col_colour'      => 'Kleur',
        'col_resolved'    => 'Opgelost',
        'col_default'     => 'Standaard',
        'col_severity'    => 'Ernst',

        'active'          => 'Actief',
        'inactive'        => 'Inactief',
        'yes'             => 'Ja',
        'no'              => 'Nee',
        'edit'            => 'Bewerken',
        'delete'          => 'Verwijderen',

        'kind_status'     => 'status',
        'kind_impact'     => 'impactniveau',

        // Service modal
        'add_service'     => 'Service toevoegen',
        'edit_service'    => 'Service bewerken',
        'field_name'      => 'Naam',
        'field_description' => 'Beschrijving',
        'field_order'     => 'Weergavevolgorde',
        'field_active'    => 'Actief',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Item toevoegen',
        'add_kind'        => '{kind} toevoegen',
        'edit_kind'       => '{kind} bewerken',
        'field_colour'    => 'Kleur',
        'field_resolved'  => 'Telt als opgelost',
        'resolved_help_html' => 'Incidenten met deze status stempelen automatisch <code>resolved_datetime</code> en verdwijnen van het actieve dashboard.',
        'field_severity'  => 'Ernstvolgorde',
        'severity_help'   => '1 = ergst (grote storing). Hoger = minder ernstig.',
        'field_default'   => 'Standaard',

        'cancel'          => 'Annuleren',
        'save'            => 'Opslaan',
    ],

    'help' => [
        'page_title' => 'Handleiding servicestatus',
        'guide'      => 'Handleiding',

        'nav_overview'  => 'Overzicht',
        'nav_dashboard' => 'Het statusdashboard',
        'nav_services'  => 'Services beheren',
        'nav_history'   => 'Incidentgeschiedenis',
        'nav_settings'  => 'Instellingen',
        'nav_tips'      => 'Snelle tips',

        'hero_title' => 'Handleiding servicestatus',
        'hero_sub'   => 'Bewaak uw IT-services, communiceer incidenten en houd belanghebbenden in realtime op de hoogte.',

        // Section 1: Overview
        'overview_heading' => 'Overzicht',
        'overview_intro'   => 'De module Servicestatus geeft u een centraal overzicht van de gezondheid van elke IT-service waarop uw organisatie vertrouwt. Wanneer er iets misgaat, kunt u incidenten registreren, getroffen services bijwerken en gebruikers gedurende het hele oplossingsproces op de hoogte houden.',
        'feature_dashboard_title' => 'Statusdashboard',
        'feature_dashboard_desc'  => 'Zie in één oogopslag de huidige gezondheid van elke service. Kleurgecodeerde badges tonen of elke service operationeel is, verminderd presteert, in onderhoud is of een storing ondervindt.',
        'feature_incident_title'  => 'Incidentregistratie',
        'feature_incident_desc'   => 'Registreer incidenten met titels, statusupdates en opmerkingen. Koppel getroffen services aan elk incident zodat iedereen precies weet wat er getroffen is en waarom.',
        'feature_management_title' => 'Servicebeheer',
        'feature_management_desc'  => 'Configureer uw servicecatalogus in de instellingen. Voeg services toe met namen, beschrijvingen en weergavevolgorde. Activeer of deactiveer services naarmate uw infrastructuur evolueert.',
        'feature_comms_title' => 'Communicatie',
        'feature_comms_desc'  => 'Houd belanghebbenden op de hoogte met realtime statusupdates. Elk incident heeft een status- en opmerkingenspoor zodat gebruikers de voortgang van de oplossing kunnen volgen zonder de servicedesk achterna te zitten.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Het statusdashboard',
        'dashboard_p1'      => 'Het dashboard is het eerste wat u ziet wanneer u de module Servicestatus opent. Het toont een raster van servicekaarten, die elk de servicenaam, een korte beschrijving en een kleurgecodeerde impactbadge tonen die de huidige ernstigste status weergeeft. Onder het raster staat de incidententabel met alle recente en actieve incidenten.',
        'dashboard_p2_html' => 'Elke servicekaart weerspiegelt automatisch het ernstigste impactniveau dat eraan is toegewezen vanuit elk actief (onopgelost) incident. Wanneer alle incidenten die een service treffen zijn opgelost, keert deze terug naar <strong>Operationeel</strong>.',
        'status_levels'     => 'Statusniveaus',
        'level_operational_name' => 'Operationeel',
        'level_operational_desc' => 'De service draait normaal zonder bekende problemen. Dit is de standaardstatus voor alle gezonde services.',
        'level_degraded_name'    => 'Verminderde prestaties',
        'level_degraded_desc'    => 'De service is beschikbaar maar draait trager dan verwacht of met beperkte functionaliteit. Gebruikers kunnen vertragingen opmerken.',
        'level_maintenance_name' => 'In onderhoud',
        'level_maintenance_desc' => 'Geplande downtime of onderhoudsvenster. De service kan tijdelijk niet beschikbaar zijn terwijl de werkzaamheden worden uitgevoerd.',
        'level_outage_name'      => 'Grote storing',
        'level_outage_desc'      => 'De service is volledig onbeschikbaar. Dit is de ernstigste status en moet onmiddellijk onderzoek in gang zetten.',
        'dashboard_tip'     => 'Impactniveaus zijn hiërarchisch. Als een service gekoppeld is aan meerdere actieve incidenten, toont het dashboard de ernstigste impact. Als bijvoorbeeld het ene incident een service als Verminderd markeert en een ander als Grote storing, wordt Grote storing weergegeven.',

        // Section 3: Managing services
        'services_heading_html' => 'Services beheren &amp; incidenten registreren',
        'services_intro'        => 'Services zijn de bouwstenen van uw statuspagina. Elk ervan vertegenwoordigt een IT-service, systeem of infrastructuurcomponent waarvan uw gebruikers afhankelijk zijn. Wanneer er iets misgaat, maakt u een incident aan en koppelt u dit aan de getroffen services.',
        'add_incident_heading'  => 'Een nieuw incident toevoegen',
        'add_incident_step1_html' => '<strong>Klik op "Nieuw"</strong> op het dashboard om het incidentformulier te openen.',
        'add_incident_step2_html' => '<strong>Voer een titel in</strong> &mdash; een korte, duidelijke beschrijving van het probleem. Bijvoorbeeld: "Vertragingen bij e-mailbezorging" of "VPN-gateway onbereikbaar".',
        'add_incident_step3_html' => '<strong>Stel de status in</strong> &mdash; kies Onderzoeken, Geïdentificeerd, Externe partij, Monitoren of Opgelost. Begin met Onderzoeken en werk bij naarmate u meer te weten komt.',
        'add_incident_step4_html' => '<strong>Voeg een opmerking toe</strong> &mdash; beschrijf wat tot nu toe bekend is, welke acties worden ondernomen en welke tijdelijke oplossingen voor gebruikers beschikbaar zijn.',
        'add_incident_step5_html' => '<strong>Koppel getroffen services</strong> &mdash; voeg een of meer services toe en kies voor elk het impactniveau (Grote storing, Gedeeltelijke storing, Verminderd, Onderhoud, Operationeel of Geen verstoring).',
        'add_incident_step6_html' => '<strong>Opslaan</strong> &mdash; het incident verschijnt in de tabel en de getroffen servicekaarten worden onmiddellijk op het dashboard bijgewerkt.',
        'workflow_heading'  => 'Workflow incidentstatus',
        'workflow_investigating' => 'Onderzoeken',
        'workflow_identified'    => 'Geïdentificeerd',
        'workflow_monitoring'    => 'Monitoren',
        'workflow_resolved'      => 'Opgelost',
        'workflow_note_html'     => 'Gebruik <strong>Externe partij</strong> wanneer de oorzaak bij een externe leverancier of aanbieder ligt.',
        'services_tip'      => 'U kunt elk incident bewerken door op de titel in de tabel te klikken. Werk de status bij, voeg nieuwe opmerkingen toe of wijzig de getroffen services naarmate de situatie evolueert. Het bijhouden van incidenten is essentieel voor transparante communicatie.',

        // Section 4: Incident history
        'history_heading' => 'Incidentgeschiedenis',
        'history_p1'      => 'De incidententabel op het dashboard toont zowel actieve als opgeloste incidenten en geeft u een volledige tijdlijn van de servicegezondheid. Elke rij toont de incidenttitel, huidige status, getroffen services met hun impactniveaus en de tijdstempel van de laatste wijziging.',
        'history_field_title_html'    => '<strong>Titel</strong> &mdash; een klikbare link die het incident opent om te bewerken. Gebruik duidelijke, beschrijvende titels zodat de geschiedenis eenvoudig te overzien is.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; kleurgecodeerde badge die de huidige onderzoeksfase toont (Onderzoeken, Geïdentificeerd, Externe partij, Monitoren of Opgelost).',
        'history_field_affected_html' => '<strong>Getroffen services</strong> &mdash; gelabelde badges die elke gekoppelde service tonen met de kleur van het impactniveau. In één oogopslag ziet u wat getroffen is en hoe ernstig.',
        'history_field_updated_html'  => '<strong>Bijgewerkt</strong> &mdash; de tijdstempel van de meest recente wijziging. Opgeloste incidenten worden met gedempte tekst opgemaakt zodat actieve incidenten visueel opvallen.',
        'history_p2'      => 'Opgeloste incidenten blijven zichtbaar in de tabel als historisch overzicht. Dit maakt het eenvoudig om terugkerende problemen op te sporen, te bekijken hoe eerdere incidenten zijn afgehandeld en patronen te herkennen die kunnen wijzen op onderliggende problemen.',
        'history_tip'     => 'Door uw incidentgeschiedenis regelmatig te bekijken, kunt u services identificeren die vaak worden verstoord. Als dezelfde service in meerdere incidenten voorkomt, is het misschien tijd om de oorzaak grondiger te onderzoeken of een infrastructuurupgrade te plannen.',

        // Section 5: Settings
        'settings_heading' => 'Instellingen',
        'settings_p1'      => 'Op de pagina Instellingen bouwt en onderhoudt u uw servicecatalogus. Elke service die op het statusdashboard verschijnt, moet hier eerst worden geconfigureerd.',
        'settings_step1_html' => '<strong>Voeg een service toe</strong> &mdash; klik op "Toevoegen" en geef een naam op (bijv. "E-mail", "VPN", "ERP-systeem") en een optionele beschrijving van wat de service doet.',
        'settings_step2_html' => '<strong>Stel de weergavevolgorde in</strong> &mdash; het volgordenummer bepaalt waar de service in het dashboardraster verschijnt. Lagere nummers verschijnen eerst, dus plaats uw meest kritieke services bovenaan.',
        'settings_step3_html' => '<strong>Schakel actief/inactief</strong> &mdash; door een service te deactiveren verwijdert u deze van het dashboard zonder hem te verwijderen. Dit is handig voor uitgefaseerde services of seizoensgebonden systemen.',
        'settings_step4_html' => '<strong>Bewerken of verwijderen</strong> &mdash; gebruik de actieknoppen op elke rij om servicegegevens bij te werken of een service volledig te verwijderen. Bewerken heeft altijd de voorkeur boven verwijderen, zodat historische incidentkoppelingen intact blijven.',
        'settings_tip'     => 'Beschouw uw servicecatalogus als de basis van uw statuspagina. Besteed tijd aan het juist krijgen van de namen en beschrijvingen &mdash; dit is wat uw gebruikers en belanghebbenden zien wanneer zij de gezondheid van uw IT-omgeving controleren.',

        // Section 6: Quick tips
        'tips_heading' => 'Snelle tips',
        'tip_communicate_title' => 'Communiceer vroeg',
        'tip_communicate_desc'  => 'Plaats een incident zodra u weet dat er iets mis is, zelfs als u nog niet alle details hebt. Een probleem snel erkennen wekt vertrouwen bij uw gebruikers.',
        'tip_update_title' => 'Werk regelmatig bij',
        'tip_update_desc'  => 'Regelmatige statusupdates &mdash; zelfs als er niets is veranderd &mdash; tonen gebruikers dat er actief aan het probleem wordt gewerkt. Stilte leidt tot frustratie en supporttickets.',
        'tip_review_title' => 'Bekijk patronen',
        'tip_review_desc'  => 'Controleer uw incidentgeschiedenis regelmatig. Als dezelfde service steeds blijft verschijnen, kan dit wijzen op een dieperliggend infrastructuurprobleem dat het waard is om aan te pakken.',
        'tip_maintenance_title' => 'Plan onderhoud',
        'tip_maintenance_desc'  => 'Gebruik het impactniveau Onderhoud voor gepland werk. Door vooraf een incident aan te maken, weten gebruikers van geplande downtime voordat deze plaatsvindt.',
    ],
];
