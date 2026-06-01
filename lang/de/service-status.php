<?php
/**
 * Deutsch (de) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Servicestatus',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Einstellungen',
        'help'     => 'Hilfe',
    ],

    'board' => [
        'services'        => 'Services',
        'service_count'   => '{count} Services',
        'loading'         => 'Wird geladen...',
        'no_services'     => 'Keine Services konfiguriert. Gehen Sie zu den Einstellungen, um Services hinzuzufügen.',
        'incidents'       => 'Vorfälle',
        'new'             => 'Neu',
        'col_title'       => 'Titel',
        'col_status'      => 'Status',
        'col_affected'    => 'Betroffene Services',
        'col_updated'     => 'Aktualisiert',
        'no_incidents'    => 'Keine Vorfälle vorhanden.',
        'none'            => 'Keine',
    ],

    'modal' => [
        'new_incident'        => 'Neuer Vorfall',
        'edit_incident'       => 'Vorfall bearbeiten',
        'title'               => 'Titel',
        'title_placeholder'   => 'Kurze Beschreibung des Vorfalls',
        'status'              => 'Status',
        'comment'             => 'Kommentar',
        'comment_placeholder' => 'Details zum Vorfall...',
        'affected_services'   => 'Betroffene Services',
        'add_service'         => '+ Service hinzufügen',
        'delete'              => 'Löschen',
        'cancel'              => 'Abbrechen',
        'save'                => 'Speichern',
    ],

    'toast' => [
        'incident_saved'   => 'Vorfall gespeichert',
        'incident_deleted' => 'Vorfall gelöscht',
        'save_failed'      => 'Speichern fehlgeschlagen',
        'delete_failed'    => 'Löschen fehlgeschlagen',
        'save_incident_failed'   => 'Vorfall konnte nicht gespeichert werden',
        'delete_incident_failed' => 'Vorfall konnte nicht gelöscht werden',
        'saved'            => 'Gespeichert',
        'deleted'          => 'Gelöscht',
        'save_service_failed'    => 'Service konnte nicht gespeichert werden',
        'delete_service_failed'  => 'Service konnte nicht gelöscht werden',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Vorfall löschen',
        'delete_incident_message' => 'Diesen Vorfall löschen?',
        'delete_title'            => 'Löschen',
        'delete_message'          => '"{name}" löschen?',
        'delete_label'            => 'Löschen',
    ],

    'settings' => [
        'tab_services'     => 'Services',
        'tab_statuses'     => 'Status',
        'tab_impacts'      => 'Auswirkungsstufen',

        'services_heading' => 'Services',
        'statuses_heading' => 'Vorfallstatus',
        'impacts_heading'  => 'Auswirkungsstufen',
        'add'              => 'Hinzufügen',
        'loading'          => 'Wird geladen...',
        'no_services'      => 'Noch keine Services. Klicken Sie auf Hinzufügen, um einen anzulegen.',
        'no_items'         => 'Keine Einträge gefunden',
        'load_failed'      => 'Daten konnten nicht geladen werden',
        'error_prefix'     => 'Fehler: {message}',

        'statuses_intro_html' => 'Workflow-Zustände für Servicevorfälle. Als <em>behoben</em> markierte Status schließen den Vorfall – sie stempeln automatisch <code>resolved_datetime</code> und entfernen den Vorfall vom aktiven Dashboard. Genau ein Status ist die Vorgabe für neue Vorfälle.',
        'impacts_intro_html'  => 'Schweregrad-Stufen, die als Abzeichen auf jeder Service-Karte angezeigt werden. Die <strong>Schweregrad-Reihenfolge</strong> steuert die Sortierung nach der "schlimmsten aktuellen Auswirkung" auf dem Dashboard – niedriger = schlimmer (1 = schwerer Ausfall, 5 = betriebsbereit). Zwei Zeilen können sich eine Reihenfolge teilen.',

        'col_name'        => 'Name',
        'col_description' => 'Beschreibung',
        'col_order'       => 'Reihenfolge',
        'col_status'      => 'Status',
        'col_actions'     => 'Aktionen',
        'col_colour'      => 'Farbe',
        'col_resolved'    => 'Behoben',
        'col_default'     => 'Standard',
        'col_severity'    => 'Schweregrad',

        'active'          => 'Aktiv',
        'inactive'        => 'Inaktiv',
        'yes'             => 'Ja',
        'no'              => 'Nein',
        'edit'            => 'Bearbeiten',
        'delete'          => 'Löschen',

        'kind_status'     => 'Status',
        'kind_impact'     => 'Auswirkungsstufe',

        // Service modal
        'add_service'     => 'Service hinzufügen',
        'edit_service'    => 'Service bearbeiten',
        'field_name'      => 'Name',
        'field_description' => 'Beschreibung',
        'field_order'     => 'Anzeigereihenfolge',
        'field_active'    => 'Aktiv',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Eintrag hinzufügen',
        'add_kind'        => '{kind} hinzufügen',
        'edit_kind'       => '{kind} bearbeiten',
        'field_colour'    => 'Farbe',
        'field_resolved'  => 'Gilt als behoben',
        'resolved_help_html' => 'Vorfälle in diesem Status stempeln automatisch <code>resolved_datetime</code> und verschwinden vom aktiven Dashboard.',
        'field_severity'  => 'Schweregrad-Reihenfolge',
        'severity_help'   => '1 = am schlimmsten (schwerer Ausfall). Höher = weniger schwerwiegend.',
        'field_default'   => 'Standard',

        'cancel'          => 'Abbrechen',
        'save'            => 'Speichern',
    ],

    'help' => [
        'page_title' => 'Servicestatus-Leitfaden',
        'guide'      => 'Leitfaden',

        'nav_overview'  => 'Überblick',
        'nav_dashboard' => 'Das Status-Dashboard',
        'nav_services'  => 'Services verwalten',
        'nav_history'   => 'Vorfallhistorie',
        'nav_settings'  => 'Einstellungen',
        'nav_tips'      => 'Schnelltipps',

        'hero_title' => 'Servicestatus-Leitfaden',
        'hero_sub'   => 'Überwachen Sie Ihre IT-Services, kommunizieren Sie Vorfälle und halten Sie alle Beteiligten in Echtzeit auf dem Laufenden.',

        // Section 1: Overview
        'overview_heading' => 'Überblick',
        'overview_intro'   => 'Das Modul Servicestatus bietet Ihnen eine zentrale Übersicht über den Zustand jedes IT-Service, auf den sich Ihre Organisation verlässt. Wenn etwas schiefgeht, können Sie Vorfälle erfassen, betroffene Services aktualisieren und Benutzer während des gesamten Behebungsprozesses informiert halten.',
        'feature_dashboard_title' => 'Status-Dashboard',
        'feature_dashboard_desc'  => 'Sehen Sie den aktuellen Zustand jedes Service auf einen Blick. Farblich gekennzeichnete Abzeichen zeigen, ob ein Service betriebsbereit ist, beeinträchtigt ist, gewartet wird oder einen Ausfall hat.',
        'feature_incident_title'  => 'Vorfallverfolgung',
        'feature_incident_desc'   => 'Erfassen Sie Vorfälle mit Titeln, Statusaktualisierungen und Kommentaren. Verknüpfen Sie betroffene Services mit jedem Vorfall, damit alle genau wissen, was betroffen ist und warum.',
        'feature_management_title' => 'Serviceverwaltung',
        'feature_management_desc'  => 'Konfigurieren Sie Ihren Servicekatalog in den Einstellungen. Fügen Sie Services mit Namen, Beschreibungen und Anzeigereihenfolge hinzu. Aktivieren oder deaktivieren Sie Services, während sich Ihre Infrastruktur weiterentwickelt.',
        'feature_comms_title' => 'Kommunikation',
        'feature_comms_desc'  => 'Halten Sie Beteiligte mit Statusaktualisierungen in Echtzeit informiert. Jeder Vorfall führt einen Status- und Kommentarverlauf, damit Benutzer den Fortschritt der Behebung verfolgen können, ohne beim Service Desk nachzufragen.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Das Status-Dashboard',
        'dashboard_p1'      => 'Das Dashboard ist das Erste, was Sie sehen, wenn Sie das Modul Servicestatus öffnen. Es zeigt ein Raster aus Service-Karten, die jeweils den Servicenamen, eine kurze Beschreibung und ein farblich gekennzeichnetes Auswirkungs-Abzeichen anzeigen, das den aktuell schlimmsten Status widerspiegelt. Unter dem Raster befindet sich die Vorfalltabelle mit allen aktuellen und aktiven Vorfällen.',
        'dashboard_p2_html' => 'Jede Service-Karte spiegelt automatisch die schwerwiegendste Auswirkungsstufe wider, die ihr von einem aktiven (nicht behobenen) Vorfall zugewiesen wurde. Wenn alle einen Service betreffenden Vorfälle behoben sind, kehrt er zu <strong>Betriebsbereit</strong> zurück.',
        'status_levels'     => 'Statusstufen',
        'level_operational_name' => 'Betriebsbereit',
        'level_operational_desc' => 'Der Service läuft normal, ohne bekannte Probleme. Dies ist der Standardzustand für alle funktionierenden Services.',
        'level_degraded_name'    => 'Beeinträchtigte Leistung',
        'level_degraded_desc'    => 'Der Service ist verfügbar, läuft aber langsamer als erwartet oder mit eingeschränkter Funktionalität. Benutzer bemerken möglicherweise Verzögerungen.',
        'level_maintenance_name' => 'In Wartung',
        'level_maintenance_desc' => 'Geplante Ausfallzeit oder Wartungsfenster. Der Service ist möglicherweise vorübergehend nicht verfügbar, während Arbeiten durchgeführt werden.',
        'level_outage_name'      => 'Schwerer Ausfall',
        'level_outage_desc'      => 'Der Service ist vollständig nicht verfügbar. Dies ist der schwerwiegendste Status und sollte eine sofortige Untersuchung auslösen.',
        'dashboard_tip'     => 'Auswirkungsstufen sind hierarchisch. Wenn ein Service mit mehreren aktiven Vorfällen verknüpft ist, zeigt das Dashboard die schlimmste Auswirkung an. Wenn beispielsweise ein Vorfall einen Service als Beeinträchtigt und ein anderer als Schwerer Ausfall markiert, wird Schwerer Ausfall angezeigt.',

        // Section 3: Managing services
        'services_heading_html' => 'Services verwalten &amp; Vorfälle erfassen',
        'services_intro'        => 'Services sind die Bausteine Ihrer Statusseite. Jeder steht für einen IT-Service, ein System oder eine Infrastrukturkomponente, von der Ihre Benutzer abhängen. Wenn etwas schiefgeht, erstellen Sie einen Vorfall und verknüpfen ihn mit den betroffenen Services.',
        'add_incident_heading'  => 'Einen neuen Vorfall hinzufügen',
        'add_incident_step1_html' => '<strong>Klicken Sie auf "Neu"</strong> im Dashboard, um das Vorfallformular zu öffnen.',
        'add_incident_step2_html' => '<strong>Geben Sie einen Titel ein</strong> &mdash; eine kurze, klare Beschreibung des Problems. Zum Beispiel: "Verzögerungen bei der E-Mail-Zustellung" oder "VPN-Gateway nicht erreichbar".',
        'add_incident_step3_html' => '<strong>Legen Sie den Status fest</strong> &mdash; wählen Sie Untersuchung läuft, Identifiziert, Drittanbieter, Überwachung oder Behoben. Beginnen Sie mit Untersuchung läuft und aktualisieren Sie, sobald Sie mehr wissen.',
        'add_incident_step4_html' => '<strong>Fügen Sie einen Kommentar hinzu</strong> &mdash; beschreiben Sie, was bislang bekannt ist, welche Maßnahmen ergriffen werden und welche Behelfslösungen für Benutzer verfügbar sind.',
        'add_incident_step5_html' => '<strong>Verknüpfen Sie betroffene Services</strong> &mdash; fügen Sie einen oder mehrere Services hinzu und wählen Sie für jeden die Auswirkungsstufe (Schwerer Ausfall, Teilausfall, Beeinträchtigt, Wartung, Betriebsbereit oder Keine Störung).',
        'add_incident_step6_html' => '<strong>Speichern</strong> &mdash; der Vorfall erscheint in der Tabelle und die betroffenen Service-Karten werden sofort im Dashboard aktualisiert.',
        'workflow_heading'  => 'Vorfallstatus-Workflow',
        'workflow_investigating' => 'Untersuchung läuft',
        'workflow_identified'    => 'Identifiziert',
        'workflow_monitoring'    => 'Überwachung',
        'workflow_resolved'      => 'Behoben',
        'workflow_note_html'     => 'Verwenden Sie <strong>Drittanbieter</strong>, wenn die Ursache bei einem externen Anbieter oder Dienstleister liegt.',
        'services_tip'      => 'Sie können jeden Vorfall bearbeiten, indem Sie auf seinen Titel in der Tabelle klicken. Aktualisieren Sie den Status, fügen Sie neue Kommentare hinzu oder ändern Sie betroffene Services, während sich die Situation entwickelt. Vorfälle aktuell zu halten ist der Schlüssel zu transparenter Kommunikation.',

        // Section 4: Incident history
        'history_heading' => 'Vorfallhistorie',
        'history_p1'      => 'Die Vorfalltabelle im Dashboard zeigt sowohl aktive als auch behobene Vorfälle und gibt Ihnen einen vollständigen Zeitverlauf des Servicezustands. Jede Zeile zeigt den Vorfalltitel, den aktuellen Status, die betroffenen Services mit ihren Auswirkungsstufen und den Zeitstempel der letzten Aktualisierung.',
        'history_field_title_html'    => '<strong>Titel</strong> &mdash; ein anklickbarer Link, der den Vorfall zur Bearbeitung öffnet. Verwenden Sie klare, aussagekräftige Titel, damit die Historie leicht zu überblicken ist.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; farblich gekennzeichnetes Abzeichen, das die aktuelle Untersuchungsphase anzeigt (Untersuchung läuft, Identifiziert, Drittanbieter, Überwachung oder Behoben).',
        'history_field_affected_html' => '<strong>Betroffene Services</strong> &mdash; gekennzeichnete Abzeichen, die jeden verknüpften Service mit der Farbe seiner Auswirkungsstufe zeigen. Auf einen Blick sehen Sie, was betroffen ist und wie schwerwiegend.',
        'history_field_updated_html'  => '<strong>Aktualisiert</strong> &mdash; der Zeitstempel der jüngsten Änderung. Behobene Vorfälle werden mit gedämpftem Text dargestellt, sodass aktive Vorfälle visuell hervorstechen.',
        'history_p2'      => 'Behobene Vorfälle bleiben als historischer Datensatz in der Tabelle sichtbar. Dadurch lassen sich wiederkehrende Probleme leicht erkennen, der Umgang mit früheren Vorfällen überprüfen und Muster identifizieren, die auf zugrunde liegende Probleme hindeuten könnten.',
        'history_tip'     => 'Die regelmäßige Überprüfung Ihrer Vorfallhistorie hilft Ihnen, Services zu erkennen, die häufig gestört sind. Wenn derselbe Service in mehreren Vorfällen auftaucht, ist es möglicherweise an der Zeit, die Ursache eingehender zu untersuchen oder ein Infrastruktur-Upgrade zu planen.',

        // Section 5: Settings
        'settings_heading' => 'Einstellungen',
        'settings_p1'      => 'Auf der Seite Einstellungen erstellen und pflegen Sie Ihren Servicekatalog. Jeder Service, der im Status-Dashboard erscheint, muss zuerst hier konfiguriert werden.',
        'settings_step1_html' => '<strong>Fügen Sie einen Service hinzu</strong> &mdash; klicken Sie auf "Hinzufügen" und geben Sie einen Namen (z. B. "E-Mail", "VPN", "ERP-System") sowie eine optionale Beschreibung an, die erläutert, was der Service leistet.',
        'settings_step2_html' => '<strong>Legen Sie die Anzeigereihenfolge fest</strong> &mdash; die Reihenfolgenummer steuert, wo der Service im Dashboard-Raster erscheint. Niedrigere Zahlen erscheinen zuerst, stellen Sie also Ihre wichtigsten Services nach oben.',
        'settings_step3_html' => '<strong>Aktiv/Inaktiv umschalten</strong> &mdash; das Deaktivieren eines Service entfernt ihn aus dem Dashboard, ohne ihn zu löschen. Dies ist nützlich für ausgemusterte Services oder saisonale Systeme.',
        'settings_step4_html' => '<strong>Bearbeiten oder löschen</strong> &mdash; verwenden Sie die Aktionsschaltflächen in jeder Zeile, um Servicedetails zu aktualisieren oder einen Service vollständig zu entfernen. Das Bearbeiten ist dem Löschen stets vorzuziehen, damit historische Vorfallverknüpfungen erhalten bleiben.',
        'settings_tip'     => 'Betrachten Sie Ihren Servicekatalog als Fundament Ihrer Statusseite. Nehmen Sie sich Zeit, die Namen und Beschreibungen richtig zu wählen &mdash; das ist es, was Ihre Benutzer und Beteiligten sehen, wenn sie den Zustand Ihrer IT-Umgebung prüfen.',

        // Section 6: Quick tips
        'tips_heading' => 'Schnelltipps',
        'tip_communicate_title' => 'Früh kommunizieren',
        'tip_communicate_desc'  => 'Veröffentlichen Sie einen Vorfall, sobald Sie wissen, dass etwas nicht stimmt, auch wenn Sie noch nicht alle Details haben. Ein Problem schnell anzuerkennen schafft Vertrauen bei Ihren Benutzern.',
        'tip_update_title' => 'Häufig aktualisieren',
        'tip_update_desc'  => 'Regelmäßige Statusaktualisierungen &mdash; selbst wenn sich nichts geändert hat &mdash; zeigen Benutzern, dass aktiv am Problem gearbeitet wird. Schweigen erzeugt Frust und Support-Tickets.',
        'tip_review_title' => 'Muster überprüfen',
        'tip_review_desc'  => 'Prüfen Sie Ihre Vorfallhistorie regelmäßig. Wenn derselbe Service immer wieder auftaucht, könnte das auf ein tieferes Infrastrukturproblem hindeuten, das proaktiv angegangen werden sollte.',
        'tip_maintenance_title' => 'Wartung planen',
        'tip_maintenance_desc'  => 'Verwenden Sie die Auswirkungsstufe Wartung für geplante Arbeiten. Wenn Sie einen Vorfall im Voraus erstellen, wissen Benutzer über geplante Ausfallzeiten Bescheid, bevor sie eintreten.',
    ],
];
