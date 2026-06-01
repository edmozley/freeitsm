<?php
/**
 * Nederlands (nl) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Agenda',

    'nav' => [
        'calendar' => 'Agenda',
        'table'    => 'Tabel',
        'settings' => 'Instellingen',
        'help'     => 'Help',
    ],

    'sidebar' => [
        'new_event'   => 'Nieuw item',
        'categories'  => 'Categorieën',
        'none'        => 'Geen categorieën gevonden',
    ],

    'event' => [
        'modal_new'      => 'Nieuw item',
        'modal_edit'     => 'Item bewerken',
        'title'          => 'Titel',
        'title_ph'       => 'Titel van item...',
        'category'       => 'Categorie',
        'category_none'  => '-- Categorie kiezen --',
        'start_date'     => 'Startdatum',
        'start_time'     => 'Starttijd',
        'end_date'       => 'Einddatum',
        'end_time'       => 'Eindtijd',
        'all_day'        => 'Hele dag',
        'location'       => 'Locatie',
        'location_ph'    => 'Locatie (optioneel)',
        'description'    => 'Omschrijving',
        'description_ph' => 'Omschrijving (optioneel)',
        'delete'         => 'Verwijderen',
        'cancel'         => 'Annuleren',
        'save'           => 'Opslaan',
        'edit'           => 'Bewerken',
        'delete_confirm' => 'Weet je zeker dat je dit item wilt verwijderen?',
        'title_required' => 'Voer een titel voor het item in',
        'start_required' => 'Selecteer een startdatum',
    ],

    'table' => [
        'start_required' => 'Startdatum/-tijd is verplicht',
        'save_failed'    => 'Opslaan mislukt',
        'col_title'       => 'Titel',
        'col_category'    => 'Categorie',
        'col_start'       => 'Start',
        'col_end'         => 'Einde',
        'col_all_day'     => 'Hele dag',
        'col_location'    => 'Locatie',
        'col_description' => 'Omschrijving',
        'col_created_by'  => 'Aangemaakt door',
        'col_created'     => 'Aangemaakt',
    ],

    'settings' => [
        'title'           => 'Agenda-instellingen',
        'tab_categories'  => 'Categorieën',
        'heading'         => 'Itemcategorieën',
        'add'             => 'Toevoegen',
        'intro'           => 'Beheer categorieën om agenda-items te organiseren. Elke categorie kan een eigen kleur hebben voor eenvoudige herkenning.',
        'col_name'        => 'Naam',
        'col_description' => 'Omschrijving',
        'col_status'      => 'Status',
        'active'          => 'Actief',
        'inactive'        => 'Inactief',
        'edit'            => 'Bewerken',
        'delete'          => 'Verwijderen',
        'empty'           => 'Nog geen categorieën. Klik op <strong>Toevoegen</strong> om er een aan te maken.',
        'load_error'      => 'Fout bij laden van categorieën',

        'modal_add'       => 'Categorie toevoegen',
        'modal_edit'      => 'Categorie bewerken',
        'modal_name'      => 'Naam',
        'modal_name_ph'   => 'bijv. Verloop certificaat',
        'modal_description'    => 'Omschrijving',
        'modal_description_ph' => 'Optionele omschrijving...',
        'modal_colour'    => 'Kleur',
        'modal_active'    => 'Actief',
        'cancel'          => 'Annuleren',
        'save'            => 'Opslaan',
        'name_required'   => 'Voer een categorienaam in',

        'delete_title'    => 'Categorie verwijderen',
        'delete_confirm'  => 'Weet je zeker dat je "{name}" wilt verwijderen? Dit kan niet ongedaan worden gemaakt.',
        'delete_this'     => 'deze categorie',
    ],

    'toast' => [
        'saved'         => 'Opgeslagen',
        'deleted'       => 'Verwijderd',
        'save_failed'   => 'Opslaan mislukt',
        'delete_failed' => 'Verwijderen mislukt',
    ],

    'help' => [
        'page_title'  => 'Agendahandleiding',
        'guide'       => 'Handleiding',
        'hero_title'  => 'Agendahandleiding',
        'hero_sub'    => 'Houd certificaten, contracten, onderhoudsvensters en terugkerende items bij &mdash; allemaal op één plek.',

        'nav_overview'  => 'Overzicht',
        'nav_views'     => 'Agendaweergaven',
        'nav_creating'  => 'Items aanmaken',
        'nav_categories'=> 'Itemcategorieën',
        'nav_settings'  => 'Instellingen',
        'nav_tips'      => 'Snelle tips',

        // Section 1 — Overview
        'overview_heading' => 'Overzicht',
        'overview_intro'   => 'De Agenda-module geeft je IT-team een gedeelde tijdlijn voor alles wat van belang is. In plaats van te vertrouwen op spreadsheets of persoonlijke herinneringen, kun je verloopdatums van certificaten, contractverlengingen, geplande onderhoudsvensters en teamactiviteiten bijhouden in één kleurgecodeerde agenda die iedereen op de servicedesk kan zien.',
        'feature_tracking_title' => 'Items bijhouden',
        'feature_tracking_desc'  => 'Maak items aan met titels, datums, tijden, locaties en omschrijvingen. Elk item is zichtbaar voor het team, zodat er niets tussen wal en schip valt.',
        'feature_views_title'    => 'Meerdere weergaven',
        'feature_views_desc'     => 'Wissel tussen maand-, week- en dagweergave om het detailniveau te krijgen dat je nodig hebt. De maandweergave biedt een overzicht; de week- en dagweergave tonen precieze tijdvakken.',
        'feature_categories_title' => 'Categorieën',
        'feature_categories_desc'  => 'Organiseer items in kleurgecodeerde categorieën zoals certificaten, contracten, onderhoud en vergaderingen. Filter de agenda om alleen te tonen wat voor jou van belang is.',
        'feature_scheduling_title' => 'Plannen',
        'feature_scheduling_desc'  => 'Plan onderhoudsvensters, stel items voor een hele dag in voor deadlines en plan terugkerend werk. De agenda helpt je team te coördineren en conflicten te vermijden.',

        // Section 2 — Views
        'views_heading' => 'Agendaweergaven',
        'views_intro'   => 'De agenda biedt drie weergaven, zodat je kunt in- of uitzoomen afhankelijk van wat je nodig hebt. Wissel ertussen met de schakelknoppen rechtsboven in de agendakoptekst.',
        'views_month_title' => 'Maandweergave',
        'views_month_desc'  => 'De standaardweergave. Toont een volledig maandraster met items weergegeven als gekleurde balken op elke dag. Ideaal om een overzicht te krijgen van wat er aankomt binnen het team.',
        'views_week_title'  => 'Weekweergave',
        'views_week_desc'   => 'Toont zeven dagen met tijdvakken per uur. Items worden geplaatst op basis van hun start- en eindtijd, waardoor planningsconflicten makkelijk te herkennen zijn.',
        'views_day_title'   => 'Dagweergave',
        'views_day_desc'    => 'Richt zich op één dag met een gedetailleerde indeling per uur. Gebruik deze wanneer je precies wilt zien wat er uur na uur gebeurt op een drukke dag.',
        'views_nav'         => 'Gebruik de navigatiepijlen naast de titel van de maand/week/dag om vooruit en achteruit in de tijd te gaan. De knop <strong>Vandaag</strong> brengt je direct terug naar de huidige datum, hoe ver je ook genavigeerd hebt.',
        'views_flow_today'  => 'Knop Vandaag',
        'views_flow_nav'    => 'Vorige/volgende',
        'views_flow_choose' => 'Weergave kiezen',
        'views_flow_click'  => 'Item aanklikken',
        'views_tip'         => 'Klik op een item in de agenda om een snelweergave te openen met de titel, tijd, locatie en omschrijving. Van daaruit kun je het volledige bewerkformulier openen.',

        // Section 3 — Creating events
        'creating_heading' => 'Items aanmaken',
        'creating_intro'   => 'Items aan de agenda toevoegen is eenvoudig. Klik op de knop <strong>+ Nieuw item</strong> in de zijbalk om het itemformulier te openen. Vul de gegevens in en sla op &mdash; het item verschijnt direct in de agenda.',
        'creating_step1'   => '<strong>Klik op + Nieuw item</strong> &mdash; de knop staat in de zijbalk van de agenda aan de linkerkant. Hiermee opent het venster om een item aan te maken.',
        'creating_step2'   => '<strong>Voer een titel in</strong> &mdash; geef het item een duidelijke, beschrijvende naam. Bijvoorbeeld: "Verlenging SSL-certificaat &mdash; webserver01" of "Maandelijks patchvenster".',
        'creating_step3'   => '<strong>Kies een categorie</strong> &mdash; selecteer er een uit de keuzelijst om het item van een kleur te voorzien. Categorieën worden ingesteld in Instellingen en helpen je later de agenda te filteren.',
        'creating_step4'   => '<strong>Stel de datums en tijden in</strong> &mdash; kies een startdatum en eventueel een einddatum. Voeg start- en eindtijden toe voor items met een tijd, of vink "Hele dag" aan voor deadlines en items die de hele dag duren.',
        'creating_step5'   => '<strong>Voeg locatie en omschrijving toe</strong> &mdash; geef optioneel aan waar het item plaatsvindt en voeg notities toe. Deze gegevens worden getoond in de snelweergave wanneer iemand op het item klikt.',
        'creating_step6'   => '<strong>Opslaan</strong> &mdash; klik op Opslaan en het item wordt aangemaakt. Het verschijnt meteen in de agenda, kleurgecodeerd op basis van de categorie.',
        'creating_tip'     => 'Om een bestaand item te bewerken, klik je erop in de agenda om de snelweergave te openen en klik je vervolgens op <strong>Bewerken</strong>. Hetzelfde formulier opent vooraf ingevuld met de huidige gegevens van het item. Je kunt items ook verwijderen via het bewerkformulier.',

        // Section 4 — Categories
        'categories_heading' => 'Itemcategorieën',
        'categories_intro'   => 'Categorieën vormen de ruggengraat van de agenda-organisatie. Elke categorie heeft een naam en een kleur, zodat items in één oogopslag herkenbaar zijn. De zijbalk toont alle beschikbare categorieën met selectievakjes &mdash; vink een categorie uit om die items in de agenda te verbergen.',
        'categories_certificates' => '<strong>Certificaten</strong> &mdash; houd verloopdatums van SSL/TLS-certificaten, code-signing-certificaten en andere referenties die periodiek vernieuwd moeten worden bij',
        'categories_contracts'    => '<strong>Contracten</strong> &mdash; leg verlengingsdatums van leverancierscontracten, het verlopen van licenties en mijlpalen voor SLA-evaluaties vast, zodat er niets onverwacht verloopt',
        'categories_maintenance'  => '<strong>Onderhoud</strong> &mdash; plan geplande onderhoudsvensters voor servers, netwerkapparatuur en infrastructuur. Je team en belanghebbenden kunnen precies zien wanneer downtime wordt verwacht',
        'categories_meetings'     => '<strong>Vergaderingen</strong> &mdash; registreer team-stand-ups, CAB-vergaderingen, leveranciersgesprekken en andere terugkerende afspraken die relevant zijn voor de IT-operatie',
        'categories_custom'       => '<strong>Eigen categorieën</strong> &mdash; voeg in Instellingen je eigen categorieën toe die passen bij de werkwijze van je team. Veelgebruikte toevoegingen zijn "Implementaties", "Audits" en "Training"',
        'categories_filtering'    => 'Filteren gebeurt in realtime. Wanneer je een categorie uitvinkt in de zijbalk, worden items in die categorie direct verborgen zonder de pagina opnieuw te laden. Vink hem weer aan om ze terug te halen.',
        'categories_tip'          => 'Kleurcodering werkt in alle drie de weergaven. In de maandweergave worden items getoond als gekleurde balken. In de week- en dagweergave worden items weergegeven als gekleurde blokken op het juiste tijdstip.',

        // Section 5 — Settings
        'settings_heading' => 'Instellingen',
        'settings_intro'   => 'Op de pagina Instellingen kun je instellen hoe de agenda voor je team werkt. Je opent deze door op <strong>Instellingen</strong> te klikken in de navigatiebalk bovenaan de Agenda-module.',
        'settings_step1'   => '<strong>Categorieën beheren</strong> &mdash; voeg itemcategorieën toe, bewerk of verwijder ze. Elke categorie heeft een naam en een kleur. Wijzigingen worden direct in de hele agenda voor alle gebruikers van kracht.',
        'settings_step2'   => '<strong>Kleuren instellen</strong> &mdash; kies voor elke categorie een kleur met de kleurkiezer. Kies duidelijk verschillende kleuren, zodat items op een drukke agenda makkelijk uit elkaar te houden zijn.',
        'settings_step3'   => '<strong>Categorieën hernoemen</strong> &mdash; klik op een categorienaam om deze te bewerken. Bestaande items die aan die categorie zijn toegewezen, worden automatisch bijgewerkt.',
        'settings_step4'   => '<strong>Categorieën verwijderen</strong> &mdash; verwijder categorieën die je niet meer nodig hebt. Items in een verwijderde categorie worden niet verwijderd &mdash; ze blijven in de agenda staan zonder toegewezen categorie.',
        'settings_tip'     => 'Houd je categorielijst overzichtelijk. Te veel categorieën kunnen de zijbalk rommelig maken en de kleurcodering moeilijker leesbaar. Streef naar 5&ndash;10 goed gedefinieerde categorieën die de behoeften van je team dekken.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Snelle tips',
        'tips_maintenance_title' => 'Onderhoudsvensters',
        'tips_maintenance_desc'  => 'Maak items voor de hele dag of tijdblokken aan voor gepland onderhoud. Vermeld de betrokken systemen in de omschrijving, zodat analisten snel kunnen controleren of een storing wordt verwacht.',
        'tips_certificates_title' => 'Certificaatvernieuwingen',
        'tips_certificates_desc'  => 'Voeg items toe 30 dagen voordat elk certificaat verloopt. Zo heeft je team genoeg voorbereidingstijd om te vernieuwen zonder het risico op een storing door een verlopen certificaat.',
        'tips_contracts_title'   => 'Contracten bijhouden',
        'tips_contracts_desc'    => 'Leg verlengingsdatums van contracten vast als items voor de hele dag. Voeg de naam van de leverancier en de contractwaarde toe aan de omschrijving, zodat de informatie bij de hand is wanneer het tijd is om te onderhandelen.',
        'tips_filters_title'     => 'Gebruik categoriefilters',
        'tips_filters_desc'      => 'Als de agenda druk wordt, vink dan categorieën uit die je niet nodig hebt. Verberg bijvoorbeeld vergaderingen wanneer je alleen geïnteresseerd bent in aankomende onderhoudsvensters.',
    ],
];
