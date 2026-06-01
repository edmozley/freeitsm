<?php
/**
 * Polski (pl) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Stan usług',

    'nav' => [
        'status'   => 'Stan',
        'settings' => 'Ustawienia',
        'help'     => 'Pomoc',
    ],

    'board' => [
        'services'        => 'Usługi',
        'service_count'   => '{count} usług',
        'loading'         => 'Ładowanie...',
        'no_services'     => 'Nie skonfigurowano żadnych usług. Przejdź do Ustawień, aby dodać usługi.',
        'incidents'       => 'Incydenty',
        'new'             => 'Nowy',
        'col_title'       => 'Tytuł',
        'col_status'      => 'Stan',
        'col_affected'    => 'Usługi, których dotyczy',
        'col_updated'     => 'Zaktualizowano',
        'no_incidents'    => 'Brak incydentów do wyświetlenia.',
        'none'            => 'Brak',
    ],

    'modal' => [
        'new_incident'        => 'Nowy incydent',
        'edit_incident'       => 'Edytuj incydent',
        'title'               => 'Tytuł',
        'title_placeholder'   => 'Krótki opis incydentu',
        'status'              => 'Stan',
        'comment'             => 'Komentarz',
        'comment_placeholder' => 'Szczegóły dotyczące incydentu...',
        'affected_services'   => 'Usługi, których dotyczy',
        'add_service'         => '+ Dodaj usługę',
        'delete'              => 'Usuń',
        'cancel'              => 'Anuluj',
        'save'                => 'Zapisz',
    ],

    'toast' => [
        'incident_saved'   => 'Incydent zapisany',
        'incident_deleted' => 'Incydent usunięty',
        'save_failed'      => 'Nie udało się zapisać',
        'delete_failed'    => 'Nie udało się usunąć',
        'save_incident_failed'   => 'Nie udało się zapisać incydentu',
        'delete_incident_failed' => 'Nie udało się usunąć incydentu',
        'saved'            => 'Zapisano',
        'deleted'          => 'Usunięto',
        'save_service_failed'    => 'Nie udało się zapisać usługi',
        'delete_service_failed'  => 'Nie udało się usunąć usługi',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Usuń incydent',
        'delete_incident_message' => 'Usunąć ten incydent?',
        'delete_title'            => 'Usuń',
        'delete_message'          => 'Usunąć „{name}”?',
        'delete_label'            => 'Usuń',
    ],

    'settings' => [
        'tab_services'     => 'Usługi',
        'tab_statuses'     => 'Stany',
        'tab_impacts'      => 'Poziomy wpływu',

        'services_heading' => 'Usługi',
        'statuses_heading' => 'Stany incydentów',
        'impacts_heading'  => 'Poziomy wpływu',
        'add'              => 'Dodaj',
        'loading'          => 'Ładowanie...',
        'no_services'      => 'Brak usług. Kliknij Dodaj, aby utworzyć usługę.',
        'no_items'         => 'Nie znaleziono elementów',
        'load_failed'      => 'Nie udało się załadować danych',
        'error_prefix'     => 'Błąd: {message}',

        'statuses_intro_html' => 'Stany przepływu pracy dla incydentów usług. Stany oznaczone jako <em>rozwiązane</em> zamykają incydent — automatycznie ustawiając <code>resolved_datetime</code> i usuwając incydent z aktywnego pulpitu. Dokładnie jeden stan jest domyślny dla nowych incydentów.',
        'impacts_intro_html'  => 'Pasma istotności wyświetlane jako odznaka na każdej karcie usługi. <strong>Kolejność istotności</strong> steruje sortowaniem według „najgorszego bieżącego wpływu” na pulpicie — niższa = gorsza (1 = poważna awaria, 5 = sprawna). Dwa wiersze mogą mieć tę samą kolejność.',

        'col_name'        => 'Nazwa',
        'col_description' => 'Opis',
        'col_order'       => 'Kolejność',
        'col_status'      => 'Stan',
        'col_actions'     => 'Akcje',
        'col_colour'      => 'Kolor',
        'col_resolved'    => 'Rozwiązane',
        'col_default'     => 'Domyślny',
        'col_severity'    => 'Istotność',

        'active'          => 'Aktywny',
        'inactive'        => 'Nieaktywny',
        'yes'             => 'Tak',
        'no'              => 'Nie',
        'edit'            => 'Edytuj',
        'delete'          => 'Usuń',

        'kind_status'     => 'stan',
        'kind_impact'     => 'poziom wpływu',

        // Service modal
        'add_service'     => 'Dodaj usługę',
        'edit_service'    => 'Edytuj usługę',
        'field_name'      => 'Nazwa',
        'field_description' => 'Opis',
        'field_order'     => 'Kolejność wyświetlania',
        'field_active'    => 'Aktywny',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Dodaj element',
        'add_kind'        => 'Dodaj {kind}',
        'edit_kind'       => 'Edytuj {kind}',
        'field_colour'    => 'Kolor',
        'field_resolved'  => 'Liczy się jako rozwiązane',
        'resolved_help_html' => 'Incydenty w tym stanie automatycznie ustawiają <code>resolved_datetime</code> i znikają z aktywnego pulpitu.',
        'field_severity'  => 'Kolejność istotności',
        'severity_help'   => '1 = najgorsza (poważna awaria). Wyższa = mniej istotna.',
        'field_default'   => 'Domyślny',

        'cancel'          => 'Anuluj',
        'save'            => 'Zapisz',
    ],

    'help' => [
        'page_title' => 'Przewodnik po stanie usług',
        'guide'      => 'Przewodnik',

        'nav_overview'  => 'Przegląd',
        'nav_dashboard' => 'Pulpit stanu',
        'nav_services'  => 'Zarządzanie usługami',
        'nav_history'   => 'Historia incydentów',
        'nav_settings'  => 'Ustawienia',
        'nav_tips'      => 'Szybkie wskazówki',

        'hero_title' => 'Przewodnik po stanie usług',
        'hero_sub'   => 'Monitoruj swoje usługi IT, komunikuj incydenty i informuj interesariuszy w czasie rzeczywistym.',

        // Section 1: Overview
        'overview_heading' => 'Przegląd',
        'overview_intro'   => 'Moduł Stan usług zapewnia scentralizowany widok kondycji każdej usługi IT, na której polega Twoja organizacja. Gdy coś pójdzie nie tak, możesz rejestrować incydenty, aktualizować usługi, których dotyczą, i informować użytkowników przez cały proces rozwiązywania.',
        'feature_dashboard_title' => 'Pulpit stanu',
        'feature_dashboard_desc'  => 'Zobacz bieżącą kondycję każdej usługi na pierwszy rzut oka. Oznaczone kolorami odznaki pokazują, czy dana usługa jest sprawna, działa z obniżoną wydajnością, jest w konserwacji czy ma awarię.',
        'feature_incident_title'  => 'Śledzenie incydentów',
        'feature_incident_desc'   => 'Rejestruj incydenty z tytułami, aktualizacjami stanu i komentarzami. Powiąż usługi, których dotyczy incydent, aby wszyscy wiedzieli dokładnie, co i dlaczego jest dotknięte.',
        'feature_management_title' => 'Zarządzanie usługami',
        'feature_management_desc'  => 'Skonfiguruj swój katalog usług w ustawieniach. Dodawaj usługi z nazwami, opisami i kolejnością wyświetlania. Aktywuj lub dezaktywuj usługi w miarę rozwoju infrastruktury.',
        'feature_comms_title' => 'Komunikacja',
        'feature_comms_desc'  => 'Informuj interesariuszy dzięki aktualizacjom stanu w czasie rzeczywistym. Każdy incydent zawiera stan i wątek komentarzy, dzięki czemu użytkownicy mogą śledzić postęp rozwiązywania bez nagabywania działu wsparcia.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Pulpit stanu',
        'dashboard_p1'      => 'Pulpit to pierwsza rzecz, jaką widzisz po otwarciu modułu Stan usług. Wyświetla siatkę kart usług, z których każda pokazuje nazwę usługi, krótki opis i oznaczoną kolorami odznakę wpływu odzwierciedlającą jej bieżący najgorszy stan. Pod siatką znajduje się tabela incydentów wymieniająca wszystkie ostatnie i aktywne incydenty.',
        'dashboard_p2_html' => 'Każda karta usługi automatycznie odzwierciedla najpoważniejszy poziom wpływu przypisany do niej z dowolnego aktywnego (nierozwiązanego) incydentu. Gdy wszystkie incydenty dotyczące usługi zostaną rozwiązane, powraca ona do stanu <strong>Sprawna</strong>.',
        'status_levels'     => 'Poziomy stanu',
        'level_operational_name' => 'Sprawna',
        'level_operational_desc' => 'Usługa działa normalnie, bez znanych problemów. Jest to stan domyślny dla wszystkich zdrowych usług.',
        'level_degraded_name'    => 'Obniżona wydajność',
        'level_degraded_desc'    => 'Usługa jest dostępna, ale działa wolniej niż oczekiwano lub z ograniczoną funkcjonalnością. Użytkownicy mogą zauważyć opóźnienia.',
        'level_maintenance_name' => 'W konserwacji',
        'level_maintenance_desc' => 'Planowany przestój lub okno konserwacyjne. Usługa może być tymczasowo niedostępna podczas wykonywania prac.',
        'level_outage_name'      => 'Poważna awaria',
        'level_outage_desc'      => 'Usługa jest całkowicie niedostępna. Jest to najpoważniejszy stan i powinien wywołać natychmiastowe dochodzenie.',
        'dashboard_tip'     => 'Poziomy wpływu są hierarchiczne. Jeśli usługa jest powiązana z wieloma aktywnymi incydentami, pulpit pokazuje najgorszy wpływ. Na przykład jeden incydent oznaczający usługę jako Obniżona wydajność, a drugi jako Poważna awaria spowoduje wyświetlenie Poważnej awarii.',

        // Section 3: Managing services
        'services_heading_html' => 'Zarządzanie usługami &amp; rejestrowanie incydentów',
        'services_intro'        => 'Usługi są podstawowymi elementami Twojej strony stanu. Każda z nich reprezentuje usługę IT, system lub komponent infrastruktury, od którego zależą Twoi użytkownicy. Gdy coś pójdzie nie tak, tworzysz incydent i łączysz go z usługami, których dotyczy.',
        'add_incident_heading'  => 'Dodawanie nowego incydentu',
        'add_incident_step1_html' => '<strong>Kliknij „Nowy”</strong> na pulpicie, aby otworzyć formularz incydentu.',
        'add_incident_step2_html' => '<strong>Wprowadź tytuł</strong> &mdash; krótki, jasny opis problemu. Na przykład: „Opóźnienia w dostarczaniu poczty” lub „Brak dostępu do bramy VPN”.',
        'add_incident_step3_html' => '<strong>Ustaw stan</strong> &mdash; wybierz Badanie, Zidentyfikowano, Strona trzecia, Monitorowanie lub Rozwiązano. Zacznij od Badania i aktualizuj w miarę zdobywania informacji.',
        'add_incident_step4_html' => '<strong>Dodaj komentarz</strong> &mdash; opisz, co wiadomo do tej pory, jakie działania są podejmowane oraz wszelkie obejścia dostępne dla użytkowników.',
        'add_incident_step5_html' => '<strong>Powiąż usługi, których dotyczy</strong> &mdash; dodaj jedną lub więcej usług i wybierz poziom wpływu dla każdej (Poważna awaria, Częściowa awaria, Obniżona wydajność, Konserwacja, Sprawna lub Bez zakłóceń).',
        'add_incident_step6_html' => '<strong>Zapisz</strong> &mdash; incydent pojawia się w tabeli, a karty usług, których dotyczy, natychmiast aktualizują się na pulpicie.',
        'workflow_heading'  => 'Przepływ pracy stanu incydentu',
        'workflow_investigating' => 'Badanie',
        'workflow_identified'    => 'Zidentyfikowano',
        'workflow_monitoring'    => 'Monitorowanie',
        'workflow_resolved'      => 'Rozwiązano',
        'workflow_note_html'     => 'Użyj stanu <strong>Strona trzecia</strong>, gdy przyczyna źródłowa leży po stronie zewnętrznego dostawcy.',
        'services_tip'      => 'Możesz edytować dowolny incydent, klikając jego tytuł w tabeli. Aktualizuj stan, dodawaj nowe komentarze lub zmieniaj usługi, których dotyczy, w miarę rozwoju sytuacji. Utrzymywanie aktualnych incydentów jest kluczem do przejrzystej komunikacji.',

        // Section 4: Incident history
        'history_heading' => 'Historia incydentów',
        'history_p1'      => 'Tabela incydentów na pulpicie pokazuje zarówno aktywne, jak i rozwiązane incydenty, dając Ci pełną oś czasu kondycji usług. Każdy wiersz wyświetla tytuł incydentu, bieżący stan, usługi, których dotyczy, wraz z ich poziomami wpływu oraz znacznik czasu ostatniej aktualizacji.',
        'history_field_title_html'    => '<strong>Tytuł</strong> &mdash; klikalny link otwierający incydent do edycji. Używaj jasnych, opisowych tytułów, aby historia była łatwa do przejrzenia.',
        'history_field_status_html'   => '<strong>Stan</strong> &mdash; oznaczona kolorami odznaka pokazująca bieżącą fazę dochodzenia (Badanie, Zidentyfikowano, Strona trzecia, Monitorowanie lub Rozwiązano).',
        'history_field_affected_html' => '<strong>Usługi, których dotyczy</strong> &mdash; oznaczone odznaki pokazujące każdą powiązaną usługę z kolorem jej poziomu wpływu. Na pierwszy rzut oka widzisz, co jest dotknięte i jak poważnie.',
        'history_field_updated_html'  => '<strong>Zaktualizowano</strong> &mdash; znacznik czasu najnowszej zmiany. Rozwiązane incydenty są sformatowane wyszarzonym tekstem, aby aktywne incydenty wizualnie się wyróżniały.',
        'history_p2'      => 'Rozwiązane incydenty pozostają widoczne w tabeli jako zapis historyczny. Ułatwia to wychwytywanie powtarzających się problemów, przeglądanie sposobu obsługi przeszłych incydentów oraz identyfikowanie wzorców, które mogą wskazywać na problemy źródłowe.',
        'history_tip'     => 'Regularne przeglądanie historii incydentów pomaga zidentyfikować usługi, które są często zakłócane. Jeśli ta sama usługa pojawia się w wielu incydentach, może to być czas na głębsze zbadanie przyczyny źródłowej lub zaplanowanie modernizacji infrastruktury.',

        // Section 5: Settings
        'settings_heading' => 'Ustawienia',
        'settings_p1'      => 'Strona Ustawienia to miejsce, w którym budujesz i utrzymujesz swój katalog usług. Każda usługa, która pojawia się na pulpicie stanu, musi najpierw zostać tutaj skonfigurowana.',
        'settings_step1_html' => '<strong>Dodaj usługę</strong> &mdash; kliknij „Dodaj” i podaj nazwę (np. „Poczta”, „VPN”, „System ERP”) oraz opcjonalny opis wyjaśniający, co robi dana usługa.',
        'settings_step2_html' => '<strong>Ustaw kolejność wyświetlania</strong> &mdash; numer kolejności określa, gdzie usługa pojawia się w siatce pulpitu. Niższe numery pojawiają się pierwsze, więc umieść swoje najbardziej krytyczne usługi na górze.',
        'settings_step3_html' => '<strong>Przełącz aktywny/nieaktywny</strong> &mdash; dezaktywacja usługi usuwa ją z pulpitu bez jej usuwania. Jest to przydatne w przypadku wycofanych usług lub systemów sezonowych.',
        'settings_step4_html' => '<strong>Edytuj lub usuń</strong> &mdash; użyj przycisków akcji w każdym wierszu, aby zaktualizować szczegóły usługi lub całkowicie usunąć usługę. Edycja jest zawsze preferowana nad usuwaniem, aby historyczne powiązania incydentów pozostały nienaruszone.',
        'settings_tip'     => 'Pomyśl o swoim katalogu usług jako o fundamencie strony stanu. Poświęć czas na właściwe ustawienie nazw i opisów &mdash; to właśnie zobaczą Twoi użytkownicy i interesariusze, gdy sprawdzą kondycję Twojego środowiska IT.',

        // Section 6: Quick tips
        'tips_heading' => 'Szybkie wskazówki',
        'tip_communicate_title' => 'Komunikuj wcześnie',
        'tip_communicate_desc'  => 'Opublikuj incydent, gdy tylko dowiesz się, że coś jest nie tak, nawet jeśli nie masz jeszcze wszystkich szczegółów. Szybkie potwierdzenie problemu buduje zaufanie wśród użytkowników.',
        'tip_update_title' => 'Aktualizuj często',
        'tip_update_desc'  => 'Regularne aktualizacje stanu &mdash; nawet jeśli nic się nie zmieniło &mdash; pokazują użytkownikom, że problem jest aktywnie rozwiązywany. Cisza rodzi frustrację i zgłoszenia do wsparcia.',
        'tip_review_title' => 'Przeglądaj wzorce',
        'tip_review_desc'  => 'Regularnie sprawdzaj historię incydentów. Jeśli ta sama usługa wciąż się pojawia, może to wskazywać na głębszy problem infrastrukturalny, który warto rozwiązać.',
        'tip_maintenance_title' => 'Planuj konserwację',
        'tip_maintenance_desc'  => 'Użyj poziomu wpływu Konserwacja dla zaplanowanych prac. Utworzenie incydentu z wyprzedzeniem pozwala poinformować użytkowników o planowanym przestoju, zanim nastąpi.',
    ],
];
