<?php
/**
 * Afrikaans (af) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Diensstatus',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Instellings',
        'help'     => 'Hulp',
    ],

    'board' => [
        'services'        => 'Dienste',
        'service_count'   => '{count} dienste',
        'loading'         => 'Laai tans...',
        'no_services'     => 'Geen dienste gekonfigureer nie. Gaan na Instellings om dienste by te voeg.',
        'incidents'       => 'Voorvalle',
        'new'             => 'Nuwe',
        'col_title'       => 'Titel',
        'col_status'      => 'Status',
        'col_affected'    => 'Geraakte dienste',
        'col_updated'     => 'Bygewerk',
        'no_incidents'    => 'Geen voorvalle om te wys nie.',
        'none'            => 'Geen',
    ],

    'modal' => [
        'new_incident'        => 'Nuwe voorval',
        'edit_incident'       => 'Wysig voorval',
        'title'               => 'Titel',
        'title_placeholder'   => 'Kort beskrywing van die voorval',
        'status'              => 'Status',
        'comment'             => 'Opmerking',
        'comment_placeholder' => 'Besonderhede oor die voorval...',
        'affected_services'   => 'Geraakte dienste',
        'add_service'         => '+ Voeg diens by',
        'delete'              => 'Skrap',
        'cancel'              => 'Kanselleer',
        'save'                => 'Stoor',
    ],

    'toast' => [
        'incident_saved'   => 'Voorval gestoor',
        'incident_deleted' => 'Voorval geskrap',
        'save_failed'      => 'Kon nie stoor nie',
        'delete_failed'    => 'Kon nie skrap nie',
        'save_incident_failed'   => 'Kon nie voorval stoor nie',
        'delete_incident_failed' => 'Kon nie voorval skrap nie',
        'saved'            => 'Gestoor',
        'deleted'          => 'Geskrap',
        'save_service_failed'    => 'Kon nie diens stoor nie',
        'delete_service_failed'  => 'Kon nie diens skrap nie',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Skrap voorval',
        'delete_incident_message' => 'Skrap hierdie voorval?',
        'delete_title'            => 'Skrap',
        'delete_message'          => 'Skrap "{name}"?',
        'delete_label'            => 'Skrap',
    ],

    'settings' => [
        'tab_services'     => 'Dienste',
        'tab_statuses'     => 'Statusse',
        'tab_impacts'      => 'Impakvlakke',

        'services_heading' => 'Dienste',
        'statuses_heading' => 'Voorvalstatusse',
        'impacts_heading'  => 'Impakvlakke',
        'add'              => 'Voeg by',
        'loading'          => 'Laai tans...',
        'no_services'      => 'Nog geen dienste nie. Klik Voeg by om een te skep.',
        'no_items'         => 'Geen items gevind nie',
        'load_failed'      => 'Kon nie data laai nie',
        'error_prefix'     => 'Fout: {message}',

        'statuses_intro_html' => 'Werkvloeitoestande vir diensvoorvalle. Statusse wat as <em>opgelos</em> gemerk is, sluit die voorval — dit stempel <code>resolved_datetime</code> outomaties en verwyder die voorval van die aktiewe paneelbord. Presies een status is die verstek vir nuwe voorvalle.',
        'impacts_intro_html'  => 'Erns-bande wat as die kentekens op elke dienskaart vertoon word. <strong>Erns-volgorde</strong> dryf die "ergste huidige impak"-rangskikking op die paneelbord — laer = erger (1 = groot onderbreking, 5 = operasioneel). Twee rye kan dieselfde volgorde deel.',

        'col_name'        => 'Naam',
        'col_description' => 'Beskrywing',
        'col_order'       => 'Volgorde',
        'col_status'      => 'Status',
        'col_actions'     => 'Aksies',
        'col_colour'      => 'Kleur',
        'col_resolved'    => 'Opgelos',
        'col_default'     => 'Verstek',
        'col_severity'    => 'Erns',

        'active'          => 'Aktief',
        'inactive'        => 'Onaktief',
        'yes'             => 'Ja',
        'no'              => 'Nee',
        'edit'            => 'Wysig',
        'delete'          => 'Skrap',

        'kind_status'     => 'status',
        'kind_impact'     => 'impakvlak',

        // Service modal
        'add_service'     => 'Voeg diens by',
        'edit_service'    => 'Wysig diens',
        'field_name'      => 'Naam',
        'field_description' => 'Beskrywing',
        'field_order'     => 'Vertoonvolgorde',
        'field_active'    => 'Aktief',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Voeg item by',
        'add_kind'        => 'Voeg {kind} by',
        'edit_kind'       => 'Wysig {kind}',
        'field_colour'    => 'Kleur',
        'field_resolved'  => 'Tel as opgelos',
        'resolved_help_html' => 'Voorvalle in hierdie status stempel <code>resolved_datetime</code> outomaties en verdwyn van die aktiewe paneelbord.',
        'field_severity'  => 'Erns-volgorde',
        'severity_help'   => '1 = ergste (Groot onderbreking). Hoër = minder ernstig.',
        'field_default'   => 'Verstek',

        'cancel'          => 'Kanselleer',
        'save'            => 'Stoor',
    ],

    'help' => [
        'page_title' => 'Diensstatus-gids',
        'guide'      => 'Gids',

        'nav_overview'  => 'Oorsig',
        'nav_dashboard' => 'Die statuspaneelbord',
        'nav_services'  => 'Bestuur dienste',
        'nav_history'   => 'Voorvalgeskiedenis',
        'nav_settings'  => 'Instellings',
        'nav_tips'      => 'Vinnige wenke',

        'hero_title' => 'Diensstatus-gids',
        'hero_sub'   => 'Monitor jou IT-dienste, kommunikeer voorvalle, en hou belanghebbendes intyds ingelig.',

        // Section 1: Overview
        'overview_heading' => 'Oorsig',
        'overview_intro'   => 'Die Diensstatus-module gee jou \'n gesentraliseerde oorsig van die gesondheid van elke IT-diens waarop jou organisasie staatmaak. Wanneer iets verkeerd loop, kan jy voorvalle aanteken, geraakte dienste bywerk, en gebruikers ingelig hou regdeur die oplossingsproses.',
        'feature_dashboard_title' => 'Statuspaneelbord',
        'feature_dashboard_desc'  => 'Sien die huidige gesondheid van elke diens met een oogopslag. Kleurgekodeerde kentekens wys of elke diens operasioneel, afgetakel, onder onderhoud, of besig met \'n onderbreking is.',
        'feature_incident_title'  => 'Voorvalopsporing',
        'feature_incident_desc'   => 'Teken voorvalle aan met titels, statusopdaterings, en opmerkings. Skakel geraakte dienste aan elke voorval sodat almal presies weet wat geraak word en hoekom.',
        'feature_management_title' => 'Diensbestuur',
        'feature_management_desc'  => 'Konfigureer jou dienskatalogus in instellings. Voeg dienste by met name, beskrywings, en vertoonvolgorde. Aktiveer of deaktiveer dienste soos jou infrastruktuur ontwikkel.',
        'feature_comms_title' => 'Kommunikasie',
        'feature_comms_desc'  => 'Hou belanghebbendes ingelig met intydse statusopdaterings. Elke voorval dra \'n status en opmerkingspoor sodat gebruikers die oplossingsvordering kan volg sonder om die dienstoonbank na te jaag.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Die statuspaneelbord',
        'dashboard_p1'      => 'Die paneelbord is die eerste ding wat jy sien wanneer jy die Diensstatus-module oopmaak. Dit vertoon \'n rooster van dienskaarte, elk wat die diensnaam, \'n kort beskrywing, en \'n kleurgekodeerde impakkenteken wat sy huidige ergste status weerspieël, wys. Onder die rooster sit die voorvaltabel wat alle onlangse en aktiewe voorvalle lys.',
        'dashboard_p2_html' => 'Elke dienskaart weerspieël outomaties die ernstigste impakvlak wat daaraan toegeken is vanaf enige aktiewe (onopgeloste) voorval. Wanneer alle voorvalle wat \'n diens raak opgelos is, keer dit terug na <strong>Operasioneel</strong>.',
        'status_levels'     => 'Statusvlakke',
        'level_operational_name' => 'Operasioneel',
        'level_operational_desc' => 'Die diens loop normaal sonder enige bekende probleme. Dit is die verstektoestand vir alle gesonde dienste.',
        'level_degraded_name'    => 'Afgetakelde werkverrigting',
        'level_degraded_desc'    => 'Die diens is beskikbaar maar loop stadiger as verwag of met verminderde funksionaliteit. Gebruikers mag vertragings opmerk.',
        'level_maintenance_name' => 'Onder onderhoud',
        'level_maintenance_desc' => 'Beplande staantyd of onderhoudsvenster. Die diens mag tydelik onbeskikbaar wees terwyl werk gedoen word.',
        'level_outage_name'      => 'Groot onderbreking',
        'level_outage_desc'      => 'Die diens is heeltemal onbeskikbaar. Dit is die ernstigste status en behoort onmiddellike ondersoek te ontketen.',
        'dashboard_tip'     => 'Impakvlakke is hiërargies. As \'n diens aan veelvuldige aktiewe voorvalle gekoppel is, wys die paneelbord die ergste impak. Byvoorbeeld, een voorval wat \'n diens as Afgetakel merk en \'n ander wat dit as Groot onderbreking merk, sal lei tot Groot onderbreking wat vertoon word.',

        // Section 3: Managing services
        'services_heading_html' => 'Bestuur dienste &amp; teken voorvalle aan',
        'services_intro'        => 'Dienste is die boublokke van jou statusbladsy. Elkeen verteenwoordig \'n IT-diens, stelsel, of infrastruktuurkomponent waarvan jou gebruikers afhanklik is. Wanneer iets verkeerd loop, skep jy \'n voorval en skakel dit aan die geraakte dienste.',
        'add_incident_heading'  => '\'n Nuwe voorval byvoeg',
        'add_incident_step1_html' => '<strong>Klik "Nuwe"</strong> op die paneelbord om die voorvalvorm oop te maak.',
        'add_incident_step2_html' => '<strong>Voer \'n titel in</strong> &mdash; \'n kort, duidelike beskrywing van die probleem. Byvoorbeeld: "E-pos-afleweringsvertragings" of "VPN-poort onbereikbaar".',
        'add_incident_step3_html' => '<strong>Stel die status</strong> &mdash; kies Ondersoek, Geïdentifiseer, 3de Party, Monitering, of Opgelos. Begin met Ondersoek en werk by soos jy meer leer.',
        'add_incident_step4_html' => '<strong>Voeg \'n opmerking by</strong> &mdash; beskryf wat tot dusver bekend is, watter aksies geneem word, en enige oplossings wat vir gebruikers beskikbaar is.',
        'add_incident_step5_html' => '<strong>Skakel geraakte dienste</strong> &mdash; voeg een of meer dienste by en kies die impakvlak vir elk (Groot onderbreking, Gedeeltelike onderbreking, Afgetakel, Onderhoud, Operasioneel, of Geen ontwrigting).',
        'add_incident_step6_html' => '<strong>Stoor</strong> &mdash; die voorval verskyn in die tabel en geraakte dienskaarte werk onmiddellik op die paneelbord by.',
        'workflow_heading'  => 'Voorvalstatus-werkvloei',
        'workflow_investigating' => 'Ondersoek',
        'workflow_identified'    => 'Geïdentifiseer',
        'workflow_monitoring'    => 'Monitering',
        'workflow_resolved'      => 'Opgelos',
        'workflow_note_html'     => 'Gebruik <strong>3de Party</strong> wanneer die grondoorsaak by \'n eksterne verskaffer of voorsiener lê.',
        'services_tip'      => 'Jy kan enige voorval wysig deur sy titel in die tabel te klik. Werk die status by, voeg nuwe opmerkings by, of verander geraakte dienste soos die situasie ontwikkel. Om voorvalle bygewerk te hou is die sleutel tot deursigtige kommunikasie.',

        // Section 4: Incident history
        'history_heading' => 'Voorvalgeskiedenis',
        'history_p1'      => 'Die voorvaltabel op die paneelbord wys beide aktiewe en opgeloste voorvalle, wat jou \'n volledige tydlyn van diensgesondheid gee. Elke ry vertoon die voorvaltitel, huidige status, geraakte dienste met hul impakvlakke, en die laaste bygewerkte tydstempel.',
        'history_field_title_html'    => '<strong>Titel</strong> &mdash; \'n klikbare skakel wat die voorval vir wysiging oopmaak. Gebruik duidelike, beskrywende titels sodat die geskiedenis maklik is om te deurblaai.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; kleurgekodeerde kenteken wat die huidige ondersoekfase wys (Ondersoek, Geïdentifiseer, 3de Party, Monitering, of Opgelos).',
        'history_field_affected_html' => '<strong>Geraakte dienste</strong> &mdash; gemerkte kentekens wat elke gekoppelde diens met sy impakvlakkleur wys. Met een oogopslag kan jy sien wat geraak word en hoe ernstig.',
        'history_field_updated_html'  => '<strong>Bygewerk</strong> &mdash; die tydstempel van die mees onlangse verandering. Opgeloste voorvalle word met gedempte teks gestileer sodat aktiewe voorvalle visueel uitstaan.',
        'history_p2'      => 'Opgeloste voorvalle bly sigbaar in die tabel as \'n historiese rekord. Dit maak dit maklik om herhalende probleme op te spoor, te hersien hoe vorige voorvalle hanteer is, en patrone te identifiseer wat op onderliggende probleme kan dui.',
        'history_tip'     => 'Om jou voorvalgeskiedenis gereeld te hersien help jou om dienste te identifiseer wat gereeld ontwrig word. As dieselfde diens in veelvuldige voorvalle verskyn, mag dit tyd wees om die grondoorsaak dieper te ondersoek of \'n infrastruktuuropgradering te beplan.',

        // Section 5: Settings
        'settings_heading' => 'Instellings',
        'settings_p1'      => 'Die Instellings-bladsy is waar jy jou dienskatalogus bou en onderhou. Elke diens wat op die statuspaneelbord verskyn, moet eers hier gekonfigureer word.',
        'settings_step1_html' => '<strong>Voeg \'n diens by</strong> &mdash; klik "Voeg by" en verskaf \'n naam (bv. "E-pos", "VPN", "ERP-stelsel") en \'n opsionele beskrywing wat verduidelik wat die diens doen.',
        'settings_step2_html' => '<strong>Stel die vertoonvolgorde</strong> &mdash; die volgordenommer beheer waar die diens op die paneelbordrooster verskyn. Laer nommers verskyn eerste, so plaas jou mees kritieke dienste boaan.',
        'settings_step3_html' => '<strong>Wissel aktief/onaktief</strong> &mdash; om \'n diens te deaktiveer verwyder dit van die paneelbord sonder om dit te skrap. Dit is nuttig vir afgeskafde dienste of seisoenale stelsels.',
        'settings_step4_html' => '<strong>Wysig of skrap</strong> &mdash; gebruik die aksieknoppies op elke ry om diensbesonderhede by te werk of \'n diens heeltemal te verwyder. Wysiging word altyd bo skrapping verkies sodat historiese voorvalskakels heel bly.',
        'settings_tip'     => 'Dink aan jou dienskatalogus as die grondslag van jou statusbladsy. Spandeer tyd om die name en beskrywings reg te kry &mdash; dit is wat jou gebruikers en belanghebbendes sal sien wanneer hulle die gesondheid van jou IT-omgewing nagaan.',

        // Section 6: Quick tips
        'tips_heading' => 'Vinnige wenke',
        'tip_communicate_title' => 'Kommunikeer vroeg',
        'tip_communicate_desc'  => 'Plaas \'n voorval sodra jy weet iets is verkeerd, selfs al het jy nog nie al die besonderhede nie. Om \'n probleem vinnig te erken bou vertroue met jou gebruikers.',
        'tip_update_title' => 'Werk gereeld by',
        'tip_update_desc'  => 'Gereelde statusopdaterings &mdash; selfs al het niks verander nie &mdash; wys gebruikers dat daar aktief aan die probleem gewerk word. Stilte broei frustrasie en ondersteuningskaartjies.',
        'tip_review_title' => 'Hersien patrone',
        'tip_review_desc'  => 'Gaan jou voorvalgeskiedenis gereeld na. As dieselfde diens aanhou verskyn, mag dit op \'n dieper infrastruktuurprobleem dui wat die moeite werd is om proaktief aan te spreek.',
        'tip_maintenance_title' => 'Beplan onderhoud',
        'tip_maintenance_desc'  => 'Gebruik die Onderhoud-impakvlak vir beplande werk. Om \'n voorval vooraf te skep laat gebruikers weet van geskeduleerde staantyd voordat dit gebeur.',
    ],
];
