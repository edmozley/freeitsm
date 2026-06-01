<?php
/**
 * Afrikaans (af) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Kalender',

    'nav' => [
        'calendar' => 'Kalender',
        'table'    => 'Tabel',
        'settings' => 'Instellings',
        'help'     => 'Hulp',
    ],

    'sidebar' => [
        'new_event'   => 'Nuwe gebeurtenis',
        'categories'  => 'Kategorieë',
        'none'        => 'Geen kategorieë gevind nie',
    ],

    'event' => [
        'modal_new'      => 'Nuwe gebeurtenis',
        'modal_edit'     => 'Wysig gebeurtenis',
        'title'          => 'Titel',
        'title_ph'       => 'Gebeurtenistitel...',
        'category'       => 'Kategorie',
        'category_none'  => '-- Kies kategorie --',
        'start_date'     => 'Begindatum',
        'start_time'     => 'Begintyd',
        'end_date'       => 'Einddatum',
        'end_time'       => 'Eindtyd',
        'all_day'        => 'Heeldag-gebeurtenis',
        'location'       => 'Ligging',
        'location_ph'    => 'Ligging (opsioneel)',
        'description'    => 'Beskrywing',
        'description_ph' => 'Beskrywing (opsioneel)',
        'delete'         => 'Skrap',
        'cancel'         => 'Kanselleer',
        'save'           => 'Stoor',
        'edit'           => 'Wysig',
        'delete_confirm' => 'Is jy seker jy wil hierdie gebeurtenis skrap?',
        'title_required' => 'Voer asseblief \'n gebeurtenistitel in',
        'start_required' => 'Kies asseblief \'n begindatum',
    ],

    'table' => [
        'start_required' => 'Begindatum/-tyd is verpligtend',
        'save_failed'    => 'Stoor het misluk',
        'col_title'       => 'Titel',
        'col_category'    => 'Kategorie',
        'col_start'       => 'Begin',
        'col_end'         => 'Einde',
        'col_all_day'     => 'Heeldag',
        'col_location'    => 'Ligging',
        'col_description' => 'Beskrywing',
        'col_created_by'  => 'Geskep deur',
        'col_created'     => 'Geskep',
    ],

    'settings' => [
        'title'           => 'Kalenderinstellings',
        'tab_categories'  => 'Kategorieë',
        'heading'         => 'Gebeurteniskategorieë',
        'add'             => 'Voeg by',
        'intro'           => 'Bestuur kategorieë wat gebruik word om kalendergebeurtenisse te organiseer. Elke kategorie kan \'n pasgemaakte kleur hê vir maklike identifikasie.',
        'col_name'        => 'Naam',
        'col_description' => 'Beskrywing',
        'col_status'      => 'Status',
        'active'          => 'Aktief',
        'inactive'        => 'Onaktief',
        'edit'            => 'Wysig',
        'delete'          => 'Skrap',
        'empty'           => 'Nog geen kategorieë nie. Klik <strong>Voeg by</strong> om een te skep.',
        'load_error'      => 'Fout met laai van kategorieë',

        'modal_add'       => 'Voeg kategorie by',
        'modal_edit'      => 'Wysig kategorie',
        'modal_name'      => 'Naam',
        'modal_name_ph'   => 'bv. Sertifikaatverval',
        'modal_description'    => 'Beskrywing',
        'modal_description_ph' => 'Opsionele beskrywing...',
        'modal_colour'    => 'Kleur',
        'modal_active'    => 'Aktief',
        'cancel'          => 'Kanselleer',
        'save'            => 'Stoor',
        'name_required'   => 'Voer asseblief \'n kategorienaam in',

        'delete_title'    => 'Skrap kategorie',
        'delete_confirm'  => 'Is jy seker jy wil "{name}" skrap? Dit kan nie ongedaan gemaak word nie.',
        'delete_this'     => 'hierdie kategorie',
    ],

    'toast' => [
        'saved'         => 'Gestoor',
        'deleted'       => 'Geskrap',
        'save_failed'   => 'Kon nie stoor nie',
        'delete_failed' => 'Kon nie skrap nie',
    ],

    'help' => [
        'page_title'  => 'Kalendergids',
        'guide'       => 'Gids',
        'hero_title'  => 'Kalendergids',
        'hero_sub'    => 'Hou sertifikate, kontrakte, onderhoudvensters en herhalende gebeurtenisse by &mdash; alles op een plek.',

        'nav_overview'  => 'Oorsig',
        'nav_views'     => 'Kalenderaansigte',
        'nav_creating'  => 'Skep gebeurtenisse',
        'nav_categories'=> 'Gebeurteniskategorieë',
        'nav_settings'  => 'Instellings',
        'nav_tips'      => 'Vinnige wenke',

        // Section 1 — Overview
        'overview_heading' => 'Oorsig',
        'overview_intro'   => 'Die Kalender-module gee jou IT-span \'n gedeelde tydlyn vir alles wat saak maak. In plaas daarvan om op sigblaaie of persoonlike herinneringe staat te maak, kan jy sertifikaatvervaldatums, kontrakhernuwings, geskeduleerde onderhoudvensters en spangebeurtenisse byhou in een enkele, kleurgekodeerde kalender wat almal by die diensbalie kan sien.',
        'feature_tracking_title' => 'Gebeurtenisopsporing',
        'feature_tracking_desc'  => 'Skep gebeurtenisse met titels, datums, tye, liggings en beskrywings. Elke gebeurtenis is sigbaar vir die span sodat niks deur die mat val nie.',
        'feature_views_title'    => 'Veelvuldige aansigte',
        'feature_views_desc'     => 'Wissel tussen maand-, week- en dagaansigte om die vlak van detail te kry wat jy nodig het. Die maandaansig wys \'n oorsig; week- en dagaansigte wys presiese tydgleuwe.',
        'feature_categories_title' => 'Kategorieë',
        'feature_categories_desc'  => 'Organiseer gebeurtenisse in kleurgekodeerde kategorieë soos sertifikate, kontrakte, onderhoud en vergaderings. Filter die kalender om net te wys waarvoor jy omgee.',
        'feature_scheduling_title' => 'Skedulering',
        'feature_scheduling_desc'  => 'Beplan onderhoudvensters, stel heeldag-gebeurtenisse vir sperdatums op, en skeduleer herhalende werk. Die kalender help jou span om te koördineer en konflikte te vermy.',

        // Section 2 — Views
        'views_heading' => 'Kalenderaansigte',
        'views_intro'   => 'Die kalender bied drie aansigte sodat jy kan in- of uitzoom afhangende van wat jy nodig het. Wissel tussen hulle met die wisselknoppies in die regter-boonste hoek van die kalenderkop.',
        'views_month_title' => 'Maandaansig',
        'views_month_desc'  => 'Die verstekaansig. Wys \'n volledige maandrooster met gebeurtenisse wat as gekleurde balke op elke dag vertoon word. Ideaal om \'n oorsig te kry van wat regoor die span voorlê.',
        'views_week_title'  => 'Weekaansig',
        'views_week_desc'   => 'Vertoon sewe dae met uurlikse tydgleuwe. Gebeurtenisse word volgens hul begin- en eindtye geposisioneer, wat dit maklik maak om skeduleringskonflikte raak te sien.',
        'views_day_title'   => 'Dagaansig',
        'views_day_desc'    => 'Fokus op \'n enkele dag met gedetailleerde uurlikse uiteensettings. Gebruik dit wanneer jy presies moet sien wat uur vir uur gedurende \'n besige dag gebeur.',
        'views_nav'         => 'Gebruik die navigasiepyle langs die maand-/week-/dagtitel om vorentoe en agtertoe in tyd te beweeg. Die <strong>Vandag</strong>-knoppie bring jou reguit terug na die huidige datum, maak nie saak hoe ver jy genavigeer het nie.',
        'views_flow_today'  => 'Vandag-knoppie',
        'views_flow_nav'    => 'Navigeer vorige/volgende',
        'views_flow_choose' => 'Kies aansig',
        'views_flow_click'  => 'Klik gebeurtenis',
        'views_tip'         => 'Klik enige gebeurtenis op die kalender om \'n vinnige-aansig-opspringer oop te maak wat die titel, tyd, ligging en beskrywing wys. Van daar af kan jy die volledige wysigvorm oopmaak.',

        // Section 3 — Creating events
        'creating_heading' => 'Skep gebeurtenisse',
        'creating_intro'   => 'Om gebeurtenisse by die kalender te voeg is eenvoudig. Klik die <strong>+ Nuwe gebeurtenis</strong>-knoppie in die kantbalk om die gebeurtenisvorm oop te maak. Vul die besonderhede in en stoor &mdash; die gebeurtenis verskyn onmiddellik op die kalender.',
        'creating_step1'   => '<strong>Klik + Nuwe gebeurtenis</strong> &mdash; die knoppie is in die kalender se kantbalk aan die linkerkant. Dit maak die skepmodaal vir gebeurtenisse oop.',
        'creating_step2'   => '<strong>Voer \'n titel in</strong> &mdash; gee die gebeurtenis \'n duidelike, beskrywende naam. Byvoorbeeld: "SSL-sertifikaathernuwing &mdash; webserver01" of "Maandelikse paginvenster".',
        'creating_step3'   => '<strong>Kies \'n kategorie</strong> &mdash; kies uit die aftreklys om die gebeurtenis kleur te kodeer. Kategorieë word in Instellings opgestel en help jou om die kalender later te filter.',
        'creating_step4'   => '<strong>Stel die datums en tye</strong> &mdash; kies \'n begindatum en opsioneel \'n einddatum. Voeg begin- en eindtye by vir getydde gebeurtenisse, of merk "Heeldag-gebeurtenis" vir sperdatums en volledige-dag-inskrywings.',
        'creating_step5'   => '<strong>Voeg ligging en beskrywing by</strong> &mdash; spesifiseer opsioneel waar die gebeurtenis plaasvind en voeg notas by. Hierdie besonderhede word in die vinnige-aansig-opspringer gewys wanneer iemand die gebeurtenis klik.',
        'creating_step6'   => '<strong>Stoor</strong> &mdash; klik Stoor en die gebeurtenis word geskep. Dit verskyn dadelik op die kalender, kleurgekodeer volgens sy kategorie.',
        'creating_tip'     => 'Om \'n bestaande gebeurtenis te wysig, klik dit op die kalender om die opspringer oop te maak, klik dan <strong>Wysig</strong>. Dieselfde vorm maak voorafingevul met die gebeurtenis se huidige besonderhede oop. Jy kan ook gebeurtenisse vanaf die wysigvorm skrap.',

        // Section 4 — Categories
        'categories_heading' => 'Gebeurteniskategorieë',
        'categories_intro'   => 'Kategorieë is die ruggraat van kalenderorganisasie. Elke kategorie het \'n naam en \'n kleur, sodat gebeurtenisse onmiddellik met een oogopslag herkenbaar is. Die kantbalk wys alle beskikbare kategorieë met merkblokkies &mdash; ontmerk \'n kategorie om daardie gebeurtenisse van die kalender te versteek.',
        'categories_certificates' => '<strong>Sertifikate</strong> &mdash; hou SSL/TLS-sertifikaatvervaldatums, kodeondertekeningsertifikate en ander legitimasie wat periodieke hernuwing benodig by',
        'categories_contracts'    => '<strong>Kontrakte</strong> &mdash; teken verskaffer-kontrakhernuwingsdatums, lisensieverval en SLA-hersieningsmylpale aan sodat niks onverwags verstryk nie',
        'categories_maintenance'  => '<strong>Onderhoud</strong> &mdash; skeduleer beplande onderhoudvensters vir bedieners, netwerktoerusting en infrastruktuur. Jou span en belanghebbendes kan presies sien wanneer ondertyd verwag word',
        'categories_meetings'     => '<strong>Vergaderings</strong> &mdash; teken span-stand-ups, CAB-vergaderings, verskaffer-oproepe en ander herhalende afsprake wat relevant is vir IT-bedrywighede aan',
        'categories_custom'       => '<strong>Pasgemaakte kategorieë</strong> &mdash; voeg jou eie kategorieë in Instellings by om by jou span se werkvloei te pas. Algemene byvoegings sluit in "Ontplooiings", "Oudits" en "Opleiding"',
        'categories_filtering'    => 'Filtering word intyds toegepas. Wanneer jy \'n kategorie in die kantbalk ontmerk, word gebeurtenisse in daardie kategorie onmiddellik versteek sonder om die bladsy te herlaai. Merk dit weer om hulle terug te bring.',
        'categories_tip'          => 'Kleurkodering werk regoor al drie aansigte. In maandaansig wys gebeurtenisse as gekleurde balke. In week- en dagaansigte word gebeurtenisse as gekleurde blokke vertoon wat by die korrekte tyd geposisioneer is.',

        // Section 5 — Settings
        'settings_heading' => 'Instellings',
        'settings_intro'   => 'Die Instellings-bladsy laat jou toe om te konfigureer hoe die kalender vir jou span werk. Kry toegang daartoe deur op <strong>Instellings</strong> in die navigasiebalk boaan die kalender-module te klik.',
        'settings_step1'   => '<strong>Bestuur kategorieë</strong> &mdash; voeg gebeurteniskategorieë by, wysig of verwyder hulle. Elke kategorie het \'n naam en \'n kleur. Veranderinge tree onmiddellik in werking regoor die kalender vir alle gebruikers.',
        'settings_step2'   => '<strong>Stel kleure</strong> &mdash; kies \'n kleur vir elke kategorie met die kleurkieser. Kies duidelik onderskeibare kleure sodat gebeurtenisse maklik uitmekaar te ken is op \'n besige kalender.',
        'settings_step3'   => '<strong>Hernoem kategorieë</strong> &mdash; klik op \'n kategorienaam om dit te wysig. Bestaande gebeurtenisse wat aan daardie kategorie toegewys is, word outomaties opgedateer.',
        'settings_step4'   => '<strong>Skrap kategorieë</strong> &mdash; verwyder kategorieë wat jy nie meer nodig het nie. Gebeurtenisse in \'n geskrapte kategorie word nie verwyder nie &mdash; hulle bly op die kalender sonder \'n kategorietoewysing.',
        'settings_tip'     => 'Hou jou kategorielys gefokus. Om te veel kategorieë te hê kan die kantbalk deurmekaar maak en die kleurkodering moeiliker maak om te lees. Mik vir 5&ndash;10 goed-gedefinieerde kategorieë wat jou span se behoeftes dek.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Vinnige wenke',
        'tips_maintenance_title' => 'Onderhoudvensters',
        'tips_maintenance_desc'  => 'Skep heeldag-gebeurtenisse of getydde blokke vir beplande onderhoud. Sluit die geaffekteerde stelsels in die beskrywing in sodat ontleders vinnig kan nagaan of \'n onderbreking verwag word.',
        'tips_certificates_title' => 'Sertifikaathernuwings',
        'tips_certificates_desc'  => 'Voeg gebeurtenisse 30 dae voor elke sertifikaat verstryk by. Dit gee jou span genoeg voorlooptyd om te hernu sonder om \'n onderbreking van \'n verstreke sertifikaat te waag.',
        'tips_contracts_title'   => 'Kontrakopsporing',
        'tips_contracts_desc'    => 'Teken kontrakhernuwingsdatums as heeldag-gebeurtenisse aan. Voeg die verskaffer se naam en kontrakwaarde in die beskrywing by sodat die inligting byderhand is wanneer dit tyd is om te onderhandel.',
        'tips_filters_title'     => 'Gebruik kategoriefilters',
        'tips_filters_desc'      => 'Wanneer die kalender besig raak, ontmerk kategorieë wat jy nie nodig het nie. Versteek byvoorbeeld vergaderings wanneer jy net in komende onderhoudvensters belangstel.',
    ],
];
