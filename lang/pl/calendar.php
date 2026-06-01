<?php
/**
 * Polski (pl) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Kalendarz',

    'nav' => [
        'calendar' => 'Kalendarz',
        'table'    => 'Tabela',
        'settings' => 'Ustawienia',
        'help'     => 'Pomoc',
    ],

    'sidebar' => [
        'new_event'   => 'Nowe wydarzenie',
        'categories'  => 'Kategorie',
        'none'        => 'Nie znaleziono kategorii',
    ],

    'event' => [
        'modal_new'      => 'Nowe wydarzenie',
        'modal_edit'     => 'Edytuj wydarzenie',
        'title'          => 'Tytuł',
        'title_ph'       => 'Tytuł wydarzenia...',
        'category'       => 'Kategoria',
        'category_none'  => '-- Wybierz kategorię --',
        'start_date'     => 'Data rozpoczęcia',
        'start_time'     => 'Godzina rozpoczęcia',
        'end_date'       => 'Data zakończenia',
        'end_time'       => 'Godzina zakończenia',
        'all_day'        => 'Wydarzenie całodniowe',
        'location'       => 'Lokalizacja',
        'location_ph'    => 'Lokalizacja (opcjonalnie)',
        'description'    => 'Opis',
        'description_ph' => 'Opis (opcjonalnie)',
        'delete'         => 'Usuń',
        'cancel'         => 'Anuluj',
        'save'           => 'Zapisz',
        'edit'           => 'Edytuj',
        'delete_confirm' => 'Czy na pewno chcesz usunąć to wydarzenie?',
        'title_required' => 'Wprowadź tytuł wydarzenia',
        'start_required' => 'Wybierz datę rozpoczęcia',
    ],

    'table' => [
        'start_required' => 'Data/godzina rozpoczęcia jest wymagana',
        'save_failed'    => 'Zapis nie powiódł się',
        'col_title'       => 'Tytuł',
        'col_category'    => 'Kategoria',
        'col_start'       => 'Początek',
        'col_end'         => 'Koniec',
        'col_all_day'     => 'Cały dzień',
        'col_location'    => 'Lokalizacja',
        'col_description' => 'Opis',
        'col_created_by'  => 'Utworzone przez',
        'col_created'     => 'Utworzono',
    ],

    'settings' => [
        'title'           => 'Ustawienia kalendarza',
        'tab_categories'  => 'Kategorie',
        'heading'         => 'Kategorie wydarzeń',
        'add'             => 'Dodaj',
        'intro'           => 'Zarządzaj kategoriami używanymi do porządkowania wydarzeń w kalendarzu. Każda kategoria może mieć własny kolor ułatwiający identyfikację.',
        'col_name'        => 'Nazwa',
        'col_description' => 'Opis',
        'col_status'      => 'Status',
        'active'          => 'Aktywna',
        'inactive'        => 'Nieaktywna',
        'edit'            => 'Edytuj',
        'delete'          => 'Usuń',
        'empty'           => 'Brak kategorii. Kliknij <strong>Dodaj</strong>, aby utworzyć nową.',
        'load_error'      => 'Błąd podczas ładowania kategorii',

        'modal_add'       => 'Dodaj kategorię',
        'modal_edit'      => 'Edytuj kategorię',
        'modal_name'      => 'Nazwa',
        'modal_name_ph'   => 'np. Wygaśnięcie certyfikatu',
        'modal_description'    => 'Opis',
        'modal_description_ph' => 'Opcjonalny opis...',
        'modal_colour'    => 'Kolor',
        'modal_active'    => 'Aktywna',
        'cancel'          => 'Anuluj',
        'save'            => 'Zapisz',
        'name_required'   => 'Wprowadź nazwę kategorii',

        'delete_title'    => 'Usuń kategorię',
        'delete_confirm'  => 'Czy na pewno chcesz usunąć „{name}”? Tej operacji nie można cofnąć.',
        'delete_this'     => 'tę kategorię',
    ],

    'toast' => [
        'saved'         => 'Zapisano',
        'deleted'       => 'Usunięto',
        'save_failed'   => 'Nie udało się zapisać',
        'delete_failed' => 'Nie udało się usunąć',
    ],

    'help' => [
        'page_title'  => 'Przewodnik po kalendarzu',
        'guide'       => 'Przewodnik',
        'hero_title'  => 'Przewodnik po kalendarzu',
        'hero_sub'    => 'Śledź certyfikaty, umowy, okna serwisowe i wydarzenia cykliczne &mdash; wszystko w jednym miejscu.',

        'nav_overview'  => 'Przegląd',
        'nav_views'     => 'Widoki kalendarza',
        'nav_creating'  => 'Tworzenie wydarzeń',
        'nav_categories'=> 'Kategorie wydarzeń',
        'nav_settings'  => 'Ustawienia',
        'nav_tips'      => 'Szybkie wskazówki',

        // Section 1 — Overview
        'overview_heading' => 'Przegląd',
        'overview_intro'   => 'Moduł Kalendarz daje Twojemu zespołowi IT wspólną oś czasu dla wszystkiego, co ważne. Zamiast polegać na arkuszach kalkulacyjnych czy osobistych przypomnieniach, możesz śledzić daty wygaśnięcia certyfikatów, odnowienia umów, zaplanowane okna serwisowe i wydarzenia zespołowe w jednym, oznaczonym kolorami kalendarzu, który widzi cały dział obsługi.',
        'feature_tracking_title' => 'Śledzenie wydarzeń',
        'feature_tracking_desc'  => 'Twórz wydarzenia z tytułami, datami, godzinami, lokalizacjami i opisami. Każde wydarzenie jest widoczne dla zespołu, więc nic nie umknie uwadze.',
        'feature_views_title'    => 'Wiele widoków',
        'feature_views_desc'     => 'Przełączaj się między widokiem miesiąca, tygodnia i dnia, aby uzyskać potrzebny poziom szczegółowości. Widok miesiąca pokazuje ogólny przegląd; widoki tygodnia i dnia pokazują dokładne przedziały czasowe.',
        'feature_categories_title' => 'Kategorie',
        'feature_categories_desc'  => 'Porządkuj wydarzenia w oznaczone kolorami kategorie, takie jak certyfikaty, umowy, serwis i spotkania. Filtruj kalendarz, aby wyświetlać tylko to, co Cię interesuje.',
        'feature_scheduling_title' => 'Planowanie',
        'feature_scheduling_desc'  => 'Planuj okna serwisowe, ustawiaj wydarzenia całodniowe dla terminów i planuj prace cykliczne. Kalendarz pomaga zespołowi koordynować działania i unikać konfliktów.',

        // Section 2 — Views
        'views_heading' => 'Widoki kalendarza',
        'views_intro'   => 'Kalendarz oferuje trzy widoki, dzięki czemu możesz przybliżać lub oddalać widok w zależności od potrzeb. Przełączaj się między nimi za pomocą przycisków w prawym górnym rogu nagłówka kalendarza.',
        'views_month_title' => 'Widok miesiąca',
        'views_month_desc'  => 'Widok domyślny. Pokazuje pełną siatkę miesiąca z wydarzeniami wyświetlanymi jako kolorowe paski w każdym dniu. Idealny do uzyskania przeglądu tego, co czeka cały zespół.',
        'views_week_title'  => 'Widok tygodnia',
        'views_week_desc'   => 'Wyświetla siedem dni z przedziałami godzinowymi. Wydarzenia są rozmieszczane zgodnie z godzinami rozpoczęcia i zakończenia, co ułatwia wykrycie konfliktów w harmonogramie.',
        'views_day_title'   => 'Widok dnia',
        'views_day_desc'    => 'Skupia się na jednym dniu ze szczegółowym podziałem na godziny. Używaj go, gdy musisz dokładnie zobaczyć, co dzieje się godzina po godzinie w pracowitym dniu.',
        'views_nav'         => 'Użyj strzałek nawigacyjnych obok tytułu miesiąca/tygodnia/dnia, aby przesuwać się w czasie do przodu i do tyłu. Przycisk <strong>Dziś</strong> przenosi Cię z powrotem do bieżącej daty, niezależnie od tego, jak daleko się przemieściłeś.',
        'views_flow_today'  => 'Przycisk Dziś',
        'views_flow_nav'    => 'Nawigacja wstecz/dalej',
        'views_flow_choose' => 'Wybierz widok',
        'views_flow_click'  => 'Kliknij wydarzenie',
        'views_tip'         => 'Kliknij dowolne wydarzenie w kalendarzu, aby otworzyć okno szybkiego podglądu z tytułem, godziną, lokalizacją i opisem. Stamtąd możesz otworzyć pełny formularz edycji.',

        // Section 3 — Creating events
        'creating_heading' => 'Tworzenie wydarzeń',
        'creating_intro'   => 'Dodawanie wydarzeń do kalendarza jest proste. Kliknij przycisk <strong>+ Nowe wydarzenie</strong> na pasku bocznym, aby otworzyć formularz wydarzenia. Wypełnij szczegóły i zapisz &mdash; wydarzenie pojawi się w kalendarzu natychmiast.',
        'creating_step1'   => '<strong>Kliknij + Nowe wydarzenie</strong> &mdash; przycisk znajduje się na pasku bocznym kalendarza po lewej stronie. Otwiera to okno tworzenia wydarzenia.',
        'creating_step2'   => '<strong>Wprowadź tytuł</strong> &mdash; nadaj wydarzeniu jasną, opisową nazwę. Na przykład: „Odnowienie certyfikatu SSL &mdash; webserver01” lub „Miesięczne okno aktualizacji”.',
        'creating_step3'   => '<strong>Wybierz kategorię</strong> &mdash; wybierz z listy rozwijanej, aby oznaczyć wydarzenie kolorem. Kategorie konfiguruje się w Ustawieniach i pomagają one później filtrować kalendarz.',
        'creating_step4'   => '<strong>Ustaw daty i godziny</strong> &mdash; wybierz datę rozpoczęcia i opcjonalnie datę zakończenia. Dodaj godziny rozpoczęcia i zakończenia dla wydarzeń czasowych lub zaznacz „Wydarzenie całodniowe” dla terminów i wpisów całodniowych.',
        'creating_step5'   => '<strong>Dodaj lokalizację i opis</strong> &mdash; opcjonalnie określ, gdzie odbywa się wydarzenie, i dodaj notatki. Te szczegóły są pokazywane w oknie szybkiego podglądu, gdy ktoś kliknie wydarzenie.',
        'creating_step6'   => '<strong>Zapisz</strong> &mdash; kliknij Zapisz, a wydarzenie zostanie utworzone. Pojawi się w kalendarzu od razu, oznaczone kolorem swojej kategorii.',
        'creating_tip'     => 'Aby edytować istniejące wydarzenie, kliknij je w kalendarzu, aby otworzyć okno podglądu, a następnie kliknij <strong>Edytuj</strong>. Otworzy się ten sam formularz wypełniony bieżącymi szczegółami wydarzenia. Wydarzenia możesz też usuwać z formularza edycji.',

        // Section 4 — Categories
        'categories_heading' => 'Kategorie wydarzeń',
        'categories_intro'   => 'Kategorie są podstawą organizacji kalendarza. Każda kategoria ma nazwę i kolor, dzięki czemu wydarzenia są od razu rozpoznawalne na pierwszy rzut oka. Pasek boczny pokazuje wszystkie dostępne kategorie z polami wyboru &mdash; odznacz kategorię, aby ukryć te wydarzenia z kalendarza.',
        'categories_certificates' => '<strong>Certyfikaty</strong> &mdash; śledź daty wygaśnięcia certyfikatów SSL/TLS, certyfikatów podpisywania kodu i innych poświadczeń wymagających okresowego odnawiania',
        'categories_contracts'    => '<strong>Umowy</strong> &mdash; rejestruj daty odnowienia umów z dostawcami, wygaśnięcia licencji i kamienie milowe przeglądów SLA, aby nic nie wygasło niespodziewanie',
        'categories_maintenance'  => '<strong>Serwis</strong> &mdash; planuj zaplanowane okna serwisowe dla serwerów, sprzętu sieciowego i infrastruktury. Twój zespół i interesariusze widzą dokładnie, kiedy spodziewana jest przerwa w działaniu',
        'categories_meetings'     => '<strong>Spotkania</strong> &mdash; zapisuj codzienne spotkania zespołu, spotkania CAB, rozmowy z dostawcami i inne cykliczne spotkania istotne dla operacji IT',
        'categories_custom'       => '<strong>Kategorie niestandardowe</strong> &mdash; dodawaj własne kategorie w Ustawieniach, aby dopasować je do przepływu pracy zespołu. Częste dodatki to „Wdrożenia”, „Audyty” i „Szkolenia”',
        'categories_filtering'    => 'Filtrowanie jest stosowane w czasie rzeczywistym. Gdy odznaczysz kategorię na pasku bocznym, wydarzenia w tej kategorii są natychmiast ukrywane bez przeładowywania strony. Zaznacz ją ponownie, aby je przywrócić.',
        'categories_tip'          => 'Oznaczanie kolorami działa we wszystkich trzech widokach. W widoku miesiąca wydarzenia są pokazywane jako kolorowe paski. W widokach tygodnia i dnia wydarzenia są wyświetlane jako kolorowe bloki umieszczone w odpowiednim czasie.',

        // Section 5 — Settings
        'settings_heading' => 'Ustawienia',
        'settings_intro'   => 'Strona Ustawienia pozwala skonfigurować sposób działania kalendarza dla Twojego zespołu. Uzyskaj do niej dostęp, klikając <strong>Ustawienia</strong> na pasku nawigacji u góry modułu kalendarza.',
        'settings_step1'   => '<strong>Zarządzaj kategoriami</strong> &mdash; dodawaj, edytuj lub usuwaj kategorie wydarzeń. Każda kategoria ma nazwę i kolor. Zmiany wchodzą w życie natychmiast w całym kalendarzu dla wszystkich użytkowników.',
        'settings_step2'   => '<strong>Ustaw kolory</strong> &mdash; wybierz kolor dla każdej kategorii za pomocą próbnika kolorów. Wybieraj odrębne kolory, aby wydarzenia łatwo było odróżnić w zatłoczonym kalendarzu.',
        'settings_step3'   => '<strong>Zmień nazwy kategorii</strong> &mdash; kliknij nazwę kategorii, aby ją edytować. Istniejące wydarzenia przypisane do tej kategorii są aktualizowane automatycznie.',
        'settings_step4'   => '<strong>Usuwaj kategorie</strong> &mdash; usuń kategorie, których już nie potrzebujesz. Wydarzenia w usuniętej kategorii nie są usuwane &mdash; pozostają w kalendarzu bez przypisanej kategorii.',
        'settings_tip'     => 'Utrzymuj listę kategorii zwięzłą. Zbyt wiele kategorii może sprawić, że pasek boczny będzie zagracony, a oznaczenia kolorami trudniejsze do odczytania. Dąż do 5&ndash;10 dobrze zdefiniowanych kategorii, które pokrywają potrzeby Twojego zespołu.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Szybkie wskazówki',
        'tips_maintenance_title' => 'Okna serwisowe',
        'tips_maintenance_desc'  => 'Twórz wydarzenia całodniowe lub bloki czasowe dla zaplanowanego serwisu. Umieść w opisie systemy, których to dotyczy, aby analitycy mogli szybko sprawdzić, czy spodziewana jest przerwa w działaniu.',
        'tips_certificates_title' => 'Odnawianie certyfikatów',
        'tips_certificates_desc'  => 'Dodawaj wydarzenia 30 dni przed wygaśnięciem każdego certyfikatu. Daje to zespołowi wystarczająco dużo czasu na odnowienie bez ryzyka przerwy w działaniu spowodowanej wygaśnięciem certyfikatu.',
        'tips_contracts_title'   => 'Śledzenie umów',
        'tips_contracts_desc'    => 'Rejestruj daty odnowienia umów jako wydarzenia całodniowe. Dodaj nazwę dostawcy i wartość umowy w opisie, aby informacja była pod ręką, gdy nadejdzie czas negocjacji.',
        'tips_filters_title'     => 'Korzystaj z filtrów kategorii',
        'tips_filters_desc'      => 'Gdy kalendarz staje się zatłoczony, odznacz kategorie, których nie potrzebujesz. Na przykład ukryj spotkania, gdy interesują Cię tylko nadchodzące okna serwisowe.',
    ],
];
