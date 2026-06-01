<?php
/**
 * Italiano (it) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Stato dei servizi',

    'nav' => [
        'status'   => 'Stato',
        'settings' => 'Impostazioni',
        'help'     => 'Aiuto',
    ],

    'board' => [
        'services'        => 'Servizi',
        'service_count'   => '{count} servizi',
        'loading'         => 'Caricamento...',
        'no_services'     => 'Nessun servizio configurato. Vai in Impostazioni per aggiungere servizi.',
        'incidents'       => 'Incidenti',
        'new'             => 'Nuovo',
        'col_title'       => 'Titolo',
        'col_status'      => 'Stato',
        'col_affected'    => 'Servizi interessati',
        'col_updated'     => 'Aggiornato',
        'no_incidents'    => 'Nessun incidente da mostrare.',
        'none'            => 'Nessuno',
    ],

    'modal' => [
        'new_incident'        => 'Nuovo incidente',
        'edit_incident'       => 'Modifica incidente',
        'title'               => 'Titolo',
        'title_placeholder'   => 'Breve descrizione dell\'incidente',
        'status'              => 'Stato',
        'comment'             => 'Commento',
        'comment_placeholder' => 'Dettagli sull\'incidente...',
        'affected_services'   => 'Servizi interessati',
        'add_service'         => '+ Aggiungi servizio',
        'delete'              => 'Elimina',
        'cancel'              => 'Annulla',
        'save'                => 'Salva',
    ],

    'toast' => [
        'incident_saved'   => 'Incidente salvato',
        'incident_deleted' => 'Incidente eliminato',
        'save_failed'      => 'Salvataggio non riuscito',
        'delete_failed'    => 'Eliminazione non riuscita',
        'save_incident_failed'   => 'Salvataggio dell\'incidente non riuscito',
        'delete_incident_failed' => 'Eliminazione dell\'incidente non riuscita',
        'saved'            => 'Salvato',
        'deleted'          => 'Eliminato',
        'save_service_failed'    => 'Salvataggio del servizio non riuscito',
        'delete_service_failed'  => 'Eliminazione del servizio non riuscita',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Elimina incidente',
        'delete_incident_message' => 'Eliminare questo incidente?',
        'delete_title'            => 'Elimina',
        'delete_message'          => 'Eliminare "{name}"?',
        'delete_label'            => 'Elimina',
    ],

    'settings' => [
        'tab_services'     => 'Servizi',
        'tab_statuses'     => 'Stati',
        'tab_impacts'      => 'Livelli di impatto',

        'services_heading' => 'Servizi',
        'statuses_heading' => 'Stati degli incidenti',
        'impacts_heading'  => 'Livelli di impatto',
        'add'              => 'Aggiungi',
        'loading'          => 'Caricamento...',
        'no_services'      => 'Ancora nessun servizio. Fai clic su Aggiungi per crearne uno.',
        'no_items'         => 'Nessun elemento trovato',
        'load_failed'      => 'Caricamento dei dati non riuscito',
        'error_prefix'     => 'Errore: {message}',

        'statuses_intro_html' => 'Stati del flusso di lavoro per gli incidenti dei servizi. Gli stati contrassegnati come <em>risolto</em> chiudono l\'incidente — registrano automaticamente <code>resolved_datetime</code> e rimuovono l\'incidente dalla dashboard attiva. Esattamente uno stato è quello predefinito per i nuovi incidenti.',
        'impacts_intro_html'  => 'Fasce di gravità mostrate come badge su ogni scheda di servizio. L\'<strong>ordine di gravità</strong> determina l\'ordinamento per "peggior impatto corrente" sulla dashboard — più basso = peggiore (1 = interruzione grave, 5 = operativo). Due righe possono condividere lo stesso ordine.',

        'col_name'        => 'Nome',
        'col_description' => 'Descrizione',
        'col_order'       => 'Ordine',
        'col_status'      => 'Stato',
        'col_actions'     => 'Azioni',
        'col_colour'      => 'Colore',
        'col_resolved'    => 'Risolto',
        'col_default'     => 'Predefinito',
        'col_severity'    => 'Gravità',

        'active'          => 'Attivo',
        'inactive'        => 'Inattivo',
        'yes'             => 'Sì',
        'no'              => 'No',
        'edit'            => 'Modifica',
        'delete'          => 'Elimina',

        'kind_status'     => 'stato',
        'kind_impact'     => 'livello di impatto',

        // Service modal
        'add_service'     => 'Aggiungi servizio',
        'edit_service'    => 'Modifica servizio',
        'field_name'      => 'Nome',
        'field_description' => 'Descrizione',
        'field_order'     => 'Ordine di visualizzazione',
        'field_active'    => 'Attivo',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Aggiungi elemento',
        'add_kind'        => 'Aggiungi {kind}',
        'edit_kind'       => 'Modifica {kind}',
        'field_colour'    => 'Colore',
        'field_resolved'  => 'Conta come risolto',
        'resolved_help_html' => 'Gli incidenti in questo stato registrano automaticamente <code>resolved_datetime</code> e scompaiono dalla dashboard attiva.',
        'field_severity'  => 'Ordine di gravità',
        'severity_help'   => '1 = peggiore (interruzione grave). Più alto = meno grave.',
        'field_default'   => 'Predefinito',

        'cancel'          => 'Annulla',
        'save'            => 'Salva',
    ],

    'help' => [
        'page_title' => 'Guida allo stato dei servizi',
        'guide'      => 'Guida',

        'nav_overview'  => 'Panoramica',
        'nav_dashboard' => 'La dashboard di stato',
        'nav_services'  => 'Gestione dei servizi',
        'nav_history'   => 'Cronologia degli incidenti',
        'nav_settings'  => 'Impostazioni',
        'nav_tips'      => 'Suggerimenti rapidi',

        'hero_title' => 'Guida allo stato dei servizi',
        'hero_sub'   => 'Monitora i tuoi servizi IT, comunica gli incidenti e tieni informati gli stakeholder in tempo reale.',

        // Section 1: Overview
        'overview_heading' => 'Panoramica',
        'overview_intro'   => 'Il modulo Stato dei servizi offre una visione centralizzata dello stato di salute di ogni servizio IT su cui fa affidamento la tua organizzazione. Quando qualcosa va storto, puoi registrare gli incidenti, aggiornare i servizi interessati e tenere informati gli utenti durante tutto il processo di risoluzione.',
        'feature_dashboard_title' => 'Dashboard di stato',
        'feature_dashboard_desc'  => 'Visualizza a colpo d\'occhio lo stato di salute attuale di ogni servizio. I badge colorati indicano se ciascun servizio è operativo, degradato, in manutenzione o interessato da un\'interruzione.',
        'feature_incident_title'  => 'Tracciamento degli incidenti',
        'feature_incident_desc'   => 'Registra gli incidenti con titoli, aggiornamenti di stato e commenti. Collega i servizi interessati a ciascun incidente in modo che tutti sappiano con esattezza cosa è coinvolto e perché.',
        'feature_management_title' => 'Gestione dei servizi',
        'feature_management_desc'  => 'Configura il tuo catalogo di servizi nelle impostazioni. Aggiungi servizi con nomi, descrizioni e ordine di visualizzazione. Attiva o disattiva i servizi man mano che la tua infrastruttura evolve.',
        'feature_comms_title' => 'Comunicazione',
        'feature_comms_desc'  => 'Tieni informati gli stakeholder con aggiornamenti di stato in tempo reale. Ogni incidente ha uno stato e una sequenza di commenti, così gli utenti possono seguire l\'avanzamento della risoluzione senza dover sollecitare il service desk.',

        // Section 2: Dashboard
        'dashboard_heading' => 'La dashboard di stato',
        'dashboard_p1'      => 'La dashboard è la prima cosa che vedi quando apri il modulo Stato dei servizi. Mostra una griglia di schede di servizio, ciascuna con il nome del servizio, una breve descrizione e un badge di impatto colorato che riflette il suo peggior stato attuale. Sotto la griglia si trova la tabella degli incidenti che elenca tutti gli incidenti recenti e attivi.',
        'dashboard_p2_html' => 'Ogni scheda di servizio riflette automaticamente il livello di impatto più grave a essa assegnato da qualsiasi incidente attivo (non risolto). Quando tutti gli incidenti che interessano un servizio sono risolti, esso torna a <strong>Operativo</strong>.',
        'status_levels'     => 'Livelli di stato',
        'level_operational_name' => 'Operativo',
        'level_operational_desc' => 'Il servizio funziona normalmente senza problemi noti. Questo è lo stato predefinito per tutti i servizi integri.',
        'level_degraded_name'    => 'Prestazioni degradate',
        'level_degraded_desc'    => 'Il servizio è disponibile ma più lento del previsto o con funzionalità ridotte. Gli utenti potrebbero notare dei ritardi.',
        'level_maintenance_name' => 'In manutenzione',
        'level_maintenance_desc' => 'Interruzione pianificata o finestra di manutenzione. Il servizio potrebbe essere temporaneamente non disponibile durante lo svolgimento dei lavori.',
        'level_outage_name'      => 'Interruzione grave',
        'level_outage_desc'      => 'Il servizio è completamente non disponibile. Questo è lo stato più grave e dovrebbe attivare un\'indagine immediata.',
        'dashboard_tip'     => 'I livelli di impatto sono gerarchici. Se un servizio è collegato a più incidenti attivi, la dashboard mostra il peggior impatto. Ad esempio, un incidente che contrassegna un servizio come Degradato e un altro che lo contrassegna come Interruzione grave faranno sì che venga visualizzato Interruzione grave.',

        // Section 3: Managing services
        'services_heading_html' => 'Gestione dei servizi &amp; registrazione degli incidenti',
        'services_intro'        => 'I servizi sono gli elementi costitutivi della tua pagina di stato. Ognuno rappresenta un servizio IT, un sistema o un componente infrastrutturale da cui dipendono i tuoi utenti. Quando qualcosa va storto, crei un incidente e lo colleghi ai servizi interessati.',
        'add_incident_heading'  => 'Aggiungere un nuovo incidente',
        'add_incident_step1_html' => '<strong>Fai clic su "Nuovo"</strong> nella dashboard per aprire il modulo dell\'incidente.',
        'add_incident_step2_html' => '<strong>Inserisci un titolo</strong> &mdash; una descrizione breve e chiara del problema. Ad esempio: "Ritardi nella consegna delle email" o "Gateway VPN irraggiungibile".',
        'add_incident_step3_html' => '<strong>Imposta lo stato</strong> &mdash; scegli In indagine, Identificato, Terze parti, In monitoraggio o Risolto. Inizia con In indagine e aggiorna man mano che ottieni informazioni.',
        'add_incident_step4_html' => '<strong>Aggiungi un commento</strong> &mdash; descrivi ciò che è noto finora, le azioni intraprese e le eventuali soluzioni alternative disponibili per gli utenti.',
        'add_incident_step5_html' => '<strong>Collega i servizi interessati</strong> &mdash; aggiungi uno o più servizi e scegli il livello di impatto per ciascuno (Interruzione grave, Interruzione parziale, Degradato, Manutenzione, Operativo o Nessuna interruzione).',
        'add_incident_step6_html' => '<strong>Salva</strong> &mdash; l\'incidente appare nella tabella e le schede dei servizi interessati si aggiornano immediatamente sulla dashboard.',
        'workflow_heading'  => 'Flusso di lavoro degli stati di incidente',
        'workflow_investigating' => 'In indagine',
        'workflow_identified'    => 'Identificato',
        'workflow_monitoring'    => 'In monitoraggio',
        'workflow_resolved'      => 'Risolto',
        'workflow_note_html'     => 'Usa <strong>Terze parti</strong> quando la causa principale è imputabile a un fornitore o provider esterno.',
        'services_tip'      => 'Puoi modificare qualsiasi incidente facendo clic sul suo titolo nella tabella. Aggiorna lo stato, aggiungi nuovi commenti o cambia i servizi interessati man mano che la situazione evolve. Mantenere aggiornati gli incidenti è fondamentale per una comunicazione trasparente.',

        // Section 4: Incident history
        'history_heading' => 'Cronologia degli incidenti',
        'history_p1'      => 'La tabella degli incidenti nella dashboard mostra sia gli incidenti attivi sia quelli risolti, offrendoti una cronologia completa dello stato di salute dei servizi. Ogni riga mostra il titolo dell\'incidente, lo stato attuale, i servizi interessati con i relativi livelli di impatto e l\'ora dell\'ultimo aggiornamento.',
        'history_field_title_html'    => '<strong>Titolo</strong> &mdash; un collegamento cliccabile che apre l\'incidente per la modifica. Usa titoli chiari e descrittivi così la cronologia è facile da consultare.',
        'history_field_status_html'   => '<strong>Stato</strong> &mdash; badge colorato che mostra la fase di indagine attuale (In indagine, Identificato, Terze parti, In monitoraggio o Risolto).',
        'history_field_affected_html' => '<strong>Servizi interessati</strong> &mdash; badge etichettati che mostrano ogni servizio collegato con il colore del suo livello di impatto. A colpo d\'occhio puoi vedere cosa è coinvolto e con quale gravità.',
        'history_field_updated_html'  => '<strong>Aggiornato</strong> &mdash; l\'ora della modifica più recente. Gli incidenti risolti sono mostrati con testo attenuato così gli incidenti attivi risaltano visivamente.',
        'history_p2'      => 'Gli incidenti risolti rimangono visibili nella tabella come registro storico. Questo rende facile individuare problemi ricorrenti, esaminare come sono stati gestiti gli incidenti passati e identificare schemi che potrebbero indicare problemi di fondo.',
        'history_tip'     => 'Esaminare regolarmente la cronologia degli incidenti aiuta a identificare i servizi che subiscono frequenti interruzioni. Se lo stesso servizio compare in più incidenti, potrebbe essere il momento di indagare più a fondo sulla causa principale o di pianificare un aggiornamento dell\'infrastruttura.',

        // Section 5: Settings
        'settings_heading' => 'Impostazioni',
        'settings_p1'      => 'La pagina Impostazioni è il luogo in cui costruisci e mantieni il tuo catalogo di servizi. Ogni servizio che appare sulla dashboard di stato deve prima essere configurato qui.',
        'settings_step1_html' => '<strong>Aggiungi un servizio</strong> &mdash; fai clic su "Aggiungi" e fornisci un nome (es. "Email", "VPN", "Sistema ERP") e una descrizione facoltativa che spieghi cosa fa il servizio.',
        'settings_step2_html' => '<strong>Imposta l\'ordine di visualizzazione</strong> &mdash; il numero d\'ordine controlla la posizione del servizio nella griglia della dashboard. I numeri più bassi appaiono per primi, quindi metti i servizi più critici in cima.',
        'settings_step3_html' => '<strong>Attiva/disattiva</strong> &mdash; disattivare un servizio lo rimuove dalla dashboard senza eliminarlo. È utile per servizi dismessi o sistemi stagionali.',
        'settings_step4_html' => '<strong>Modifica o elimina</strong> &mdash; usa i pulsanti di azione su ogni riga per aggiornare i dettagli del servizio o rimuovere completamente un servizio. La modifica è sempre preferibile all\'eliminazione, così i collegamenti agli incidenti storici restano intatti.',
        'settings_tip'     => 'Considera il tuo catalogo di servizi come la base della tua pagina di stato. Dedica tempo a definire bene nomi e descrizioni &mdash; sono ciò che i tuoi utenti e stakeholder vedranno quando controllano lo stato di salute del tuo ambiente IT.',

        // Section 6: Quick tips
        'tips_heading' => 'Suggerimenti rapidi',
        'tip_communicate_title' => 'Comunica subito',
        'tip_communicate_desc'  => 'Pubblica un incidente non appena ti accorgi che qualcosa non va, anche se non hai ancora tutti i dettagli. Riconoscere rapidamente un problema crea fiducia nei tuoi utenti.',
        'tip_update_title' => 'Aggiorna spesso',
        'tip_update_desc'  => 'Aggiornamenti di stato regolari &mdash; anche se nulla è cambiato &mdash; mostrano agli utenti che il problema è in fase di risoluzione attiva. Il silenzio genera frustrazione e ticket di supporto.',
        'tip_review_title' => 'Esamina gli schemi',
        'tip_review_desc'  => 'Controlla regolarmente la cronologia degli incidenti. Se lo stesso servizio continua a comparire, potrebbe indicare un problema infrastrutturale più profondo da affrontare in modo proattivo.',
        'tip_maintenance_title' => 'Pianifica la manutenzione',
        'tip_maintenance_desc'  => 'Usa il livello di impatto Manutenzione per i lavori pianificati. Creare un incidente in anticipo permette agli utenti di conoscere le interruzioni programmate prima che avvengano.',
    ],
];
