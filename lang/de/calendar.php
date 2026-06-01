<?php
/**
 * Deutsch (de) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Kalender',

    'nav' => [
        'calendar' => 'Kalender',
        'table'    => 'Tabelle',
        'settings' => 'Einstellungen',
        'help'     => 'Hilfe',
    ],

    'sidebar' => [
        'new_event'   => 'Neuer Termin',
        'categories'  => 'Kategorien',
        'none'        => 'Keine Kategorien gefunden',
    ],

    'event' => [
        'modal_new'      => 'Neuer Termin',
        'modal_edit'     => 'Termin bearbeiten',
        'title'          => 'Titel',
        'title_ph'       => 'Termintitel...',
        'category'       => 'Kategorie',
        'category_none'  => '-- Kategorie auswählen --',
        'start_date'     => 'Startdatum',
        'start_time'     => 'Startzeit',
        'end_date'       => 'Enddatum',
        'end_time'       => 'Endzeit',
        'all_day'        => 'Ganztägiger Termin',
        'location'       => 'Ort',
        'location_ph'    => 'Ort (optional)',
        'description'    => 'Beschreibung',
        'description_ph' => 'Beschreibung (optional)',
        'delete'         => 'Löschen',
        'cancel'         => 'Abbrechen',
        'save'           => 'Speichern',
        'edit'           => 'Bearbeiten',
        'delete_confirm' => 'Möchten Sie diesen Termin wirklich löschen?',
        'title_required' => 'Bitte geben Sie einen Termintitel ein',
        'start_required' => 'Bitte wählen Sie ein Startdatum',
    ],

    'table' => [
        'start_required' => 'Startdatum/-zeit ist erforderlich',
        'save_failed'    => 'Speichern fehlgeschlagen',
        'col_title'       => 'Titel',
        'col_category'    => 'Kategorie',
        'col_start'       => 'Start',
        'col_end'         => 'Ende',
        'col_all_day'     => 'Ganztägig',
        'col_location'    => 'Ort',
        'col_description' => 'Beschreibung',
        'col_created_by'  => 'Erstellt von',
        'col_created'     => 'Erstellt',
    ],

    'settings' => [
        'title'           => 'Kalendereinstellungen',
        'tab_categories'  => 'Kategorien',
        'heading'         => 'Terminkategorien',
        'add'             => 'Hinzufügen',
        'intro'           => 'Verwalten Sie die Kategorien zur Organisation von Kalenderterminen. Jede Kategorie kann eine eigene Farbe zur einfachen Erkennung haben.',
        'col_name'        => 'Name',
        'col_description' => 'Beschreibung',
        'col_status'      => 'Status',
        'active'          => 'Aktiv',
        'inactive'        => 'Inaktiv',
        'edit'            => 'Bearbeiten',
        'delete'          => 'Löschen',
        'empty'           => 'Noch keine Kategorien. Klicken Sie auf <strong>Hinzufügen</strong>, um eine zu erstellen.',
        'load_error'      => 'Fehler beim Laden der Kategorien',

        'modal_add'       => 'Kategorie hinzufügen',
        'modal_edit'      => 'Kategorie bearbeiten',
        'modal_name'      => 'Name',
        'modal_name_ph'   => 'z. B. Zertifikatsablauf',
        'modal_description'    => 'Beschreibung',
        'modal_description_ph' => 'Optionale Beschreibung...',
        'modal_colour'    => 'Farbe',
        'modal_active'    => 'Aktiv',
        'cancel'          => 'Abbrechen',
        'save'            => 'Speichern',
        'name_required'   => 'Bitte geben Sie einen Kategorienamen ein',

        'delete_title'    => 'Kategorie löschen',
        'delete_confirm'  => 'Möchten Sie "{name}" wirklich löschen? Dies kann nicht rückgängig gemacht werden.',
        'delete_this'     => 'diese Kategorie',
    ],

    'toast' => [
        'saved'         => 'Gespeichert',
        'deleted'       => 'Gelöscht',
        'save_failed'   => 'Speichern fehlgeschlagen',
        'delete_failed' => 'Löschen fehlgeschlagen',
    ],

    'help' => [
        'page_title'  => 'Kalender-Leitfaden',
        'guide'       => 'Leitfaden',
        'hero_title'  => 'Kalender-Leitfaden',
        'hero_sub'    => 'Verfolgen Sie Zertifikate, Verträge, Wartungsfenster und wiederkehrende Termine &mdash; alles an einem Ort.',

        'nav_overview'  => 'Überblick',
        'nav_views'     => 'Kalenderansichten',
        'nav_creating'  => 'Termine erstellen',
        'nav_categories'=> 'Terminkategorien',
        'nav_settings'  => 'Einstellungen',
        'nav_tips'      => 'Schnelltipps',

        // Section 1 — Overview
        'overview_heading' => 'Überblick',
        'overview_intro'   => 'Das Kalendermodul bietet Ihrem IT-Team eine gemeinsame Zeitleiste für alles, was zählt. Anstatt sich auf Tabellen oder persönliche Erinnerungen zu verlassen, können Sie Ablaufdaten von Zertifikaten, Vertragsverlängerungen, geplante Wartungsfenster und Teamtermine in einem einzigen, farblich gekennzeichneten Kalender verfolgen, den jeder am Service Desk sehen kann.',
        'feature_tracking_title' => 'Terminverfolgung',
        'feature_tracking_desc'  => 'Erstellen Sie Termine mit Titeln, Daten, Uhrzeiten, Orten und Beschreibungen. Jeder Termin ist für das Team sichtbar, sodass nichts durchrutscht.',
        'feature_views_title'    => 'Mehrere Ansichten',
        'feature_views_desc'     => 'Wechseln Sie zwischen Monats-, Wochen- und Tagesansicht, um den gewünschten Detailgrad zu erhalten. Die Monatsansicht bietet einen Überblick; Wochen- und Tagesansicht zeigen genaue Zeitfenster.',
        'feature_categories_title' => 'Kategorien',
        'feature_categories_desc'  => 'Organisieren Sie Termine in farblich gekennzeichneten Kategorien wie Zertifikate, Verträge, Wartung und Besprechungen. Filtern Sie den Kalender, um nur das anzuzeigen, was Sie interessiert.',
        'feature_scheduling_title' => 'Terminplanung',
        'feature_scheduling_desc'  => 'Planen Sie Wartungsfenster, legen Sie ganztägige Termine für Fristen fest und planen Sie wiederkehrende Arbeiten. Der Kalender hilft Ihrem Team, sich abzustimmen und Konflikte zu vermeiden.',

        // Section 2 — Views
        'views_heading' => 'Kalenderansichten',
        'views_intro'   => 'Der Kalender bietet drei Ansichten, sodass Sie je nach Bedarf hinein- oder herauszoomen können. Wechseln Sie zwischen ihnen über die Umschaltflächen oben rechts in der Kalenderkopfzeile.',
        'views_month_title' => 'Monatsansicht',
        'views_month_desc'  => 'Die Standardansicht. Zeigt ein vollständiges Monatsraster mit Terminen, die als farbige Balken an jedem Tag dargestellt werden. Ideal, um einen Überblick darüber zu erhalten, was im gesamten Team ansteht.',
        'views_week_title'  => 'Wochenansicht',
        'views_week_desc'   => 'Zeigt sieben Tage mit stündlichen Zeitfenstern. Termine werden entsprechend ihrer Start- und Endzeiten positioniert, was das Erkennen von Terminkonflikten erleichtert.',
        'views_day_title'   => 'Tagesansicht',
        'views_day_desc'    => 'Konzentriert sich auf einen einzelnen Tag mit detaillierter stündlicher Aufschlüsselung. Verwenden Sie diese Ansicht, wenn Sie an einem arbeitsreichen Tag genau sehen müssen, was Stunde für Stunde geschieht.',
        'views_nav'         => 'Verwenden Sie die Navigationspfeile neben dem Monats-/Wochen-/Tagestitel, um sich in der Zeit vor- und zurückzubewegen. Die Schaltfläche <strong>Heute</strong> bringt Sie direkt zum aktuellen Datum zurück, egal wie weit Sie navigiert haben.',
        'views_flow_today'  => 'Schaltfläche „Heute“',
        'views_flow_nav'    => 'Vor/Zurück navigieren',
        'views_flow_choose' => 'Ansicht wählen',
        'views_flow_click'  => 'Termin anklicken',
        'views_tip'         => 'Klicken Sie auf einen beliebigen Termin im Kalender, um ein Schnellansichts-Popup mit Titel, Uhrzeit, Ort und Beschreibung zu öffnen. Von dort aus können Sie das vollständige Bearbeitungsformular öffnen.',

        // Section 3 — Creating events
        'creating_heading' => 'Termine erstellen',
        'creating_intro'   => 'Das Hinzufügen von Terminen zum Kalender ist unkompliziert. Klicken Sie auf die Schaltfläche <strong>+ Neuer Termin</strong> in der Seitenleiste, um das Terminformular zu öffnen. Füllen Sie die Details aus und speichern Sie &mdash; der Termin erscheint sofort im Kalender.',
        'creating_step1'   => '<strong>+ Neuer Termin anklicken</strong> &mdash; die Schaltfläche befindet sich in der Kalender-Seitenleiste auf der linken Seite. Dies öffnet das Dialogfeld zur Terminerstellung.',
        'creating_step2'   => '<strong>Titel eingeben</strong> &mdash; geben Sie dem Termin einen klaren, aussagekräftigen Namen. Zum Beispiel: "SSL-Zertifikatsverlängerung &mdash; webserver01" oder "Monatliches Patch-Fenster".',
        'creating_step3'   => '<strong>Kategorie wählen</strong> &mdash; wählen Sie aus dem Dropdown-Menü, um den Termin farblich zu kennzeichnen. Kategorien werden in den Einstellungen konfiguriert und helfen Ihnen, den Kalender später zu filtern.',
        'creating_step4'   => '<strong>Daten und Uhrzeiten festlegen</strong> &mdash; wählen Sie ein Startdatum und optional ein Enddatum. Fügen Sie Start- und Endzeiten für zeitlich festgelegte Termine hinzu oder aktivieren Sie "Ganztägiger Termin" für Fristen und ganztägige Einträge.',
        'creating_step5'   => '<strong>Ort und Beschreibung hinzufügen</strong> &mdash; geben Sie optional an, wo der Termin stattfindet, und fügen Sie Notizen hinzu. Diese Details werden im Schnellansichts-Popup angezeigt, wenn jemand auf den Termin klickt.',
        'creating_step6'   => '<strong>Speichern</strong> &mdash; klicken Sie auf Speichern, und der Termin wird erstellt. Er erscheint sofort im Kalender, farblich nach seiner Kategorie gekennzeichnet.',
        'creating_tip'     => 'Um einen bestehenden Termin zu bearbeiten, klicken Sie im Kalender darauf, um das Popup zu öffnen, und klicken Sie dann auf <strong>Bearbeiten</strong>. Dasselbe Formular öffnet sich, vorausgefüllt mit den aktuellen Details des Termins. Sie können Termine auch über das Bearbeitungsformular löschen.',

        // Section 4 — Categories
        'categories_heading' => 'Terminkategorien',
        'categories_intro'   => 'Kategorien sind das Rückgrat der Kalenderorganisation. Jede Kategorie hat einen Namen und eine Farbe, sodass Termine auf einen Blick sofort erkennbar sind. Die Seitenleiste zeigt alle verfügbaren Kategorien mit Kontrollkästchen &mdash; deaktivieren Sie eine Kategorie, um diese Termine aus dem Kalender auszublenden.',
        'categories_certificates' => '<strong>Zertifikate</strong> &mdash; verfolgen Sie Ablaufdaten von SSL/TLS-Zertifikaten, Code-Signing-Zertifikaten und anderen Anmeldedaten, die regelmäßig erneuert werden müssen',
        'categories_contracts'    => '<strong>Verträge</strong> &mdash; erfassen Sie Verlängerungsdaten von Lieferantenverträgen, Lizenzabläufe und SLA-Überprüfungstermine, damit nichts unerwartet ausläuft',
        'categories_maintenance'  => '<strong>Wartung</strong> &mdash; planen Sie geplante Wartungsfenster für Server, Netzwerkgeräte und Infrastruktur. Ihr Team und die Beteiligten können genau sehen, wann mit Ausfallzeiten zu rechnen ist',
        'categories_meetings'     => '<strong>Besprechungen</strong> &mdash; erfassen Sie Team-Stand-ups, CAB-Meetings, Lieferantengespräche und andere wiederkehrende Termine, die für den IT-Betrieb relevant sind',
        'categories_custom'       => '<strong>Eigene Kategorien</strong> &mdash; fügen Sie in den Einstellungen eigene Kategorien hinzu, die zum Arbeitsablauf Ihres Teams passen. Häufige Ergänzungen sind "Bereitstellungen", "Audits" und "Schulungen"',
        'categories_filtering'    => 'Die Filterung erfolgt in Echtzeit. Wenn Sie eine Kategorie in der Seitenleiste deaktivieren, werden Termine dieser Kategorie sofort ausgeblendet, ohne die Seite neu zu laden. Aktivieren Sie sie erneut, um sie zurückzuholen.',
        'categories_tip'          => 'Die Farbkennzeichnung funktioniert in allen drei Ansichten. In der Monatsansicht werden Termine als farbige Balken angezeigt. In der Wochen- und Tagesansicht werden Termine als farbige Blöcke zur richtigen Zeit dargestellt.',

        // Section 5 — Settings
        'settings_heading' => 'Einstellungen',
        'settings_intro'   => 'Auf der Einstellungsseite können Sie konfigurieren, wie der Kalender für Ihr Team funktioniert. Sie erreichen sie, indem Sie in der Navigationsleiste oben im Kalendermodul auf <strong>Einstellungen</strong> klicken.',
        'settings_step1'   => '<strong>Kategorien verwalten</strong> &mdash; fügen Sie Terminkategorien hinzu, bearbeiten oder entfernen Sie sie. Jede Kategorie hat einen Namen und eine Farbe. Änderungen werden sofort im gesamten Kalender für alle Benutzer wirksam.',
        'settings_step2'   => '<strong>Farben festlegen</strong> &mdash; wählen Sie mit der Farbauswahl eine Farbe für jede Kategorie. Wählen Sie deutlich unterscheidbare Farben, damit Termine in einem vollen Kalender leicht auseinanderzuhalten sind.',
        'settings_step3'   => '<strong>Kategorien umbenennen</strong> &mdash; klicken Sie auf einen Kategorienamen, um ihn zu bearbeiten. Bestehende Termine, die dieser Kategorie zugewiesen sind, werden automatisch aktualisiert.',
        'settings_step4'   => '<strong>Kategorien löschen</strong> &mdash; entfernen Sie Kategorien, die Sie nicht mehr benötigen. Termine in einer gelöschten Kategorie werden nicht entfernt &mdash; sie bleiben ohne Kategoriezuordnung im Kalender.',
        'settings_tip'     => 'Halten Sie Ihre Kategorieliste übersichtlich. Zu viele Kategorien können die Seitenleiste unübersichtlich machen und die Farbkennzeichnung schwerer lesbar. Streben Sie 5&ndash;10 klar definierte Kategorien an, die die Anforderungen Ihres Teams abdecken.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Schnelltipps',
        'tips_maintenance_title' => 'Wartungsfenster',
        'tips_maintenance_desc'  => 'Erstellen Sie ganztägige Termine oder zeitlich festgelegte Blöcke für geplante Wartungen. Geben Sie die betroffenen Systeme in der Beschreibung an, damit Analysten schnell prüfen können, ob ein Ausfall zu erwarten ist.',
        'tips_certificates_title' => 'Zertifikatsverlängerungen',
        'tips_certificates_desc'  => 'Fügen Sie 30 Tage vor Ablauf jedes Zertifikats einen Termin hinzu. So hat Ihr Team genügend Vorlaufzeit zur Erneuerung, ohne einen Ausfall durch ein abgelaufenes Zertifikat zu riskieren.',
        'tips_contracts_title'   => 'Vertragsverfolgung',
        'tips_contracts_desc'    => 'Erfassen Sie Vertragsverlängerungsdaten als ganztägige Termine. Fügen Sie den Lieferantennamen und den Vertragswert in der Beschreibung hinzu, damit die Informationen bei Verhandlungen griffbereit sind.',
        'tips_filters_title'     => 'Kategoriefilter nutzen',
        'tips_filters_desc'      => 'Wenn der Kalender voll wird, deaktivieren Sie Kategorien, die Sie nicht benötigen. Blenden Sie zum Beispiel Besprechungen aus, wenn Sie nur an anstehenden Wartungsfenstern interessiert sind.',
    ],
];
