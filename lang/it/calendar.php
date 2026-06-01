<?php
/**
 * Italiano (it) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Calendario',

    'nav' => [
        'calendar' => 'Calendario',
        'table'    => 'Tabella',
        'settings' => 'Impostazioni',
        'help'     => 'Guida',
    ],

    'sidebar' => [
        'new_event'   => 'Nuovo evento',
        'categories'  => 'Categorie',
        'none'        => 'Nessuna categoria trovata',
    ],

    'event' => [
        'modal_new'      => 'Nuovo evento',
        'modal_edit'     => 'Modifica evento',
        'title'          => 'Titolo',
        'title_ph'       => 'Titolo dell\'evento...',
        'category'       => 'Categoria',
        'category_none'  => '-- Seleziona categoria --',
        'start_date'     => 'Data di inizio',
        'start_time'     => 'Ora di inizio',
        'end_date'       => 'Data di fine',
        'end_time'       => 'Ora di fine',
        'all_day'        => 'Evento per tutto il giorno',
        'location'       => 'Luogo',
        'location_ph'    => 'Luogo (facoltativo)',
        'description'    => 'Descrizione',
        'description_ph' => 'Descrizione (facoltativa)',
        'delete'         => 'Elimina',
        'cancel'         => 'Annulla',
        'save'           => 'Salva',
        'edit'           => 'Modifica',
        'delete_confirm' => 'Sei sicuro di voler eliminare questo evento?',
        'title_required' => 'Inserisci un titolo per l\'evento',
        'start_required' => 'Seleziona una data di inizio',
    ],

    'table' => [
        'start_required' => 'La data/ora di inizio è obbligatoria',
        'save_failed'    => 'Salvataggio non riuscito',
        'col_title'       => 'Titolo',
        'col_category'    => 'Categoria',
        'col_start'       => 'Inizio',
        'col_end'         => 'Fine',
        'col_all_day'     => 'Tutto il giorno',
        'col_location'    => 'Luogo',
        'col_description' => 'Descrizione',
        'col_created_by'  => 'Creato da',
        'col_created'     => 'Creato',
    ],

    'settings' => [
        'title'           => 'Impostazioni calendario',
        'tab_categories'  => 'Categorie',
        'heading'         => 'Categorie di eventi',
        'add'             => 'Aggiungi',
        'intro'           => 'Gestisci le categorie usate per organizzare gli eventi del calendario. Ogni categoria può avere un colore personalizzato per una facile identificazione.',
        'col_name'        => 'Nome',
        'col_description' => 'Descrizione',
        'col_status'      => 'Stato',
        'active'          => 'Attiva',
        'inactive'        => 'Inattiva',
        'edit'            => 'Modifica',
        'delete'          => 'Elimina',
        'empty'           => 'Ancora nessuna categoria. Fai clic su <strong>Aggiungi</strong> per crearne una.',
        'load_error'      => 'Errore durante il caricamento delle categorie',

        'modal_add'       => 'Aggiungi categoria',
        'modal_edit'      => 'Modifica categoria',
        'modal_name'      => 'Nome',
        'modal_name_ph'   => 'es. Scadenza certificato',
        'modal_description'    => 'Descrizione',
        'modal_description_ph' => 'Descrizione facoltativa...',
        'modal_colour'    => 'Colore',
        'modal_active'    => 'Attiva',
        'cancel'          => 'Annulla',
        'save'            => 'Salva',
        'name_required'   => 'Inserisci un nome per la categoria',

        'delete_title'    => 'Elimina categoria',
        'delete_confirm'  => 'Sei sicuro di voler eliminare "{name}"? Questa operazione non può essere annullata.',
        'delete_this'     => 'questa categoria',
    ],

    'toast' => [
        'saved'         => 'Salvato',
        'deleted'       => 'Eliminato',
        'save_failed'   => 'Salvataggio non riuscito',
        'delete_failed' => 'Eliminazione non riuscita',
    ],

    'help' => [
        'page_title'  => 'Guida al calendario',
        'guide'       => 'Guida',
        'hero_title'  => 'Guida al calendario',
        'hero_sub'    => 'Tieni traccia di certificati, contratti, finestre di manutenzione ed eventi ricorrenti &mdash; tutto in un unico posto.',

        'nav_overview'  => 'Panoramica',
        'nav_views'     => 'Viste del calendario',
        'nav_creating'  => 'Creazione di eventi',
        'nav_categories'=> 'Categorie di eventi',
        'nav_settings'  => 'Impostazioni',
        'nav_tips'      => 'Suggerimenti rapidi',

        // Section 1 — Overview
        'overview_heading' => 'Panoramica',
        'overview_intro'   => 'Il modulo Calendario offre al tuo team IT una cronologia condivisa per tutto ciò che conta. Invece di affidarti a fogli di calcolo o promemoria personali, puoi tenere traccia delle date di scadenza dei certificati, dei rinnovi dei contratti, delle finestre di manutenzione pianificata e degli eventi del team in un unico calendario con codice colore, visibile a tutti gli addetti del service desk.',
        'feature_tracking_title' => 'Monitoraggio degli eventi',
        'feature_tracking_desc'  => 'Crea eventi con titoli, date, orari, luoghi e descrizioni. Ogni evento è visibile al team, così niente passa inosservato.',
        'feature_views_title'    => 'Viste multiple',
        'feature_views_desc'     => 'Passa dalla vista mese a quella settimana o giorno per ottenere il livello di dettaglio di cui hai bisogno. La vista mese mostra una panoramica; le viste settimana e giorno mostrano fasce orarie precise.',
        'feature_categories_title' => 'Categorie',
        'feature_categories_desc'  => 'Organizza gli eventi in categorie con codice colore come certificati, contratti, manutenzione e riunioni. Filtra il calendario per mostrare solo ciò che ti interessa.',
        'feature_scheduling_title' => 'Pianificazione',
        'feature_scheduling_desc'  => 'Pianifica finestre di manutenzione, imposta eventi per tutto il giorno per le scadenze e programma il lavoro ricorrente. Il calendario aiuta il tuo team a coordinarsi ed evitare conflitti.',

        // Section 2 — Views
        'views_heading' => 'Viste del calendario',
        'views_intro'   => 'Il calendario offre tre viste, così puoi ingrandire o ridurre a seconda di ciò che ti serve. Passa dall\'una all\'altra usando i pulsanti di selezione nell\'angolo in alto a destra dell\'intestazione del calendario.',
        'views_month_title' => 'Vista mese',
        'views_month_desc'  => 'La vista predefinita. Mostra una griglia di un mese intero con gli eventi visualizzati come barre colorate su ciascun giorno. Ideale per avere una panoramica di ciò che attende il team.',
        'views_week_title'  => 'Vista settimana',
        'views_week_desc'   => 'Mostra sette giorni con fasce orarie. Gli eventi sono posizionati in base ai loro orari di inizio e fine, rendendo facile individuare i conflitti di pianificazione.',
        'views_day_title'   => 'Vista giorno',
        'views_day_desc'    => 'Si concentra su un singolo giorno con una suddivisione oraria dettagliata. Usala quando hai bisogno di vedere esattamente cosa accade ora per ora in una giornata intensa.',
        'views_nav'         => 'Usa le frecce di navigazione accanto al titolo mese/settimana/giorno per spostarti avanti e indietro nel tempo. Il pulsante <strong>Oggi</strong> ti riporta direttamente alla data corrente, per quanto tu abbia navigato lontano.',
        'views_flow_today'  => 'Pulsante Oggi',
        'views_flow_nav'    => 'Naviga prec./succ.',
        'views_flow_choose' => 'Scegli la vista',
        'views_flow_click'  => 'Fai clic sull\'evento',
        'views_tip'         => 'Fai clic su qualsiasi evento del calendario per aprire un popup di anteprima rapida che mostra il titolo, l\'orario, il luogo e la descrizione. Da lì puoi aprire il modulo di modifica completo.',

        // Section 3 — Creating events
        'creating_heading' => 'Creazione di eventi',
        'creating_intro'   => 'Aggiungere eventi al calendario è semplice. Fai clic sul pulsante <strong>+ Nuovo evento</strong> nella barra laterale per aprire il modulo dell\'evento. Compila i dettagli e salva &mdash; l\'evento appare immediatamente sul calendario.',
        'creating_step1'   => '<strong>Fai clic su + Nuovo evento</strong> &mdash; il pulsante si trova nella barra laterale del calendario sulla sinistra. Si apre la finestra di creazione dell\'evento.',
        'creating_step2'   => '<strong>Inserisci un titolo</strong> &mdash; assegna all\'evento un nome chiaro e descrittivo. Ad esempio: "Rinnovo certificato SSL &mdash; webserver01" o "Finestra di patching mensile".',
        'creating_step3'   => '<strong>Scegli una categoria</strong> &mdash; seleziona dal menu a discesa per assegnare un codice colore all\'evento. Le categorie si configurano in Impostazioni e ti aiutano a filtrare il calendario in seguito.',
        'creating_step4'   => '<strong>Imposta date e orari</strong> &mdash; scegli una data di inizio ed eventualmente una data di fine. Aggiungi orari di inizio e fine per gli eventi con orario, oppure spunta "Evento per tutto il giorno" per scadenze e voci a giornata intera.',
        'creating_step5'   => '<strong>Aggiungi luogo e descrizione</strong> &mdash; specifica facoltativamente dove si svolge l\'evento e aggiungi delle note. Questi dettagli vengono mostrati nel popup di anteprima rapida quando qualcuno fa clic sull\'evento.',
        'creating_step6'   => '<strong>Salva</strong> &mdash; fai clic su Salva e l\'evento viene creato. Appare subito sul calendario, con il codice colore della sua categoria.',
        'creating_tip'     => 'Per modificare un evento esistente, fai clic su di esso nel calendario per aprire il popup, quindi fai clic su <strong>Modifica</strong>. Si apre lo stesso modulo precompilato con i dettagli correnti dell\'evento. Puoi anche eliminare gli eventi dal modulo di modifica.',

        // Section 4 — Categories
        'categories_heading' => 'Categorie di eventi',
        'categories_intro'   => 'Le categorie sono la spina dorsale dell\'organizzazione del calendario. Ogni categoria ha un nome e un colore, così gli eventi sono immediatamente riconoscibili a colpo d\'occhio. La barra laterale mostra tutte le categorie disponibili con caselle di spunta &mdash; deseleziona una categoria per nascondere quegli eventi dal calendario.',
        'categories_certificates' => '<strong>Certificati</strong> &mdash; tieni traccia delle date di scadenza dei certificati SSL/TLS, dei certificati di firma del codice e di altre credenziali che richiedono un rinnovo periodico',
        'categories_contracts'    => '<strong>Contratti</strong> &mdash; registra le date di rinnovo dei contratti con i fornitori, le scadenze delle licenze e le tappe di revisione degli SLA, così niente scade in modo inaspettato',
        'categories_maintenance'  => '<strong>Manutenzione</strong> &mdash; pianifica le finestre di manutenzione programmata per server, apparecchiature di rete e infrastruttura. Il tuo team e gli stakeholder possono vedere esattamente quando è previsto un fermo',
        'categories_meetings'     => '<strong>Riunioni</strong> &mdash; registra gli stand-up del team, le riunioni del CAB, le chiamate con i fornitori e altri appuntamenti ricorrenti rilevanti per le operazioni IT',
        'categories_custom'       => '<strong>Categorie personalizzate</strong> &mdash; aggiungi le tue categorie in Impostazioni per adattarle al flusso di lavoro del tuo team. Aggiunte comuni includono "Distribuzioni", "Audit" e "Formazione"',
        'categories_filtering'    => 'Il filtraggio viene applicato in tempo reale. Quando deselezioni una categoria nella barra laterale, gli eventi di quella categoria vengono nascosti immediatamente senza ricaricare la pagina. Selezionala di nuovo per ripristinarli.',
        'categories_tip'          => 'Il codice colore funziona in tutte e tre le viste. Nella vista mese, gli eventi appaiono come barre colorate. Nelle viste settimana e giorno, gli eventi vengono visualizzati come blocchi colorati posizionati all\'orario corretto.',

        // Section 5 — Settings
        'settings_heading' => 'Impostazioni',
        'settings_intro'   => 'La pagina Impostazioni ti consente di configurare il funzionamento del calendario per il tuo team. Accedi facendo clic su <strong>Impostazioni</strong> nella barra di navigazione in cima al modulo Calendario.',
        'settings_step1'   => '<strong>Gestisci le categorie</strong> &mdash; aggiungi, modifica o rimuovi le categorie di eventi. Ogni categoria ha un nome e un colore. Le modifiche hanno effetto immediato sul calendario per tutti gli utenti.',
        'settings_step2'   => '<strong>Imposta i colori</strong> &mdash; scegli un colore per ogni categoria usando il selettore di colori. Scegli colori distinti, così gli eventi sono facili da distinguere in un calendario affollato.',
        'settings_step3'   => '<strong>Rinomina le categorie</strong> &mdash; fai clic sul nome di una categoria per modificarlo. Gli eventi esistenti assegnati a quella categoria vengono aggiornati automaticamente.',
        'settings_step4'   => '<strong>Elimina le categorie</strong> &mdash; rimuovi le categorie che non ti servono più. Gli eventi di una categoria eliminata non vengono rimossi &mdash; restano sul calendario senza un\'assegnazione di categoria.',
        'settings_tip'     => 'Mantieni l\'elenco delle categorie essenziale. Avere troppe categorie può rendere la barra laterale disordinata e il codice colore più difficile da leggere. Punta a 5&ndash;10 categorie ben definite che coprano le esigenze del tuo team.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Suggerimenti rapidi',
        'tips_maintenance_title' => 'Finestre di manutenzione',
        'tips_maintenance_desc'  => 'Crea eventi per tutto il giorno o blocchi con orario per la manutenzione pianificata. Includi i sistemi interessati nella descrizione, così gli analisti possono verificare rapidamente se è previsto un\'interruzione.',
        'tips_certificates_title' => 'Rinnovi dei certificati',
        'tips_certificates_desc'  => 'Aggiungi eventi 30 giorni prima della scadenza di ogni certificato. Questo dà al tuo team tempo sufficiente per il rinnovo senza rischiare un\'interruzione dovuta a un certificato scaduto.',
        'tips_contracts_title'   => 'Monitoraggio dei contratti',
        'tips_contracts_desc'    => 'Registra le date di rinnovo dei contratti come eventi per tutto il giorno. Aggiungi il nome del fornitore e il valore del contratto nella descrizione, così le informazioni sono a portata di mano quando è il momento di negoziare.',
        'tips_filters_title'     => 'Usa i filtri per categoria',
        'tips_filters_desc'      => 'Quando il calendario diventa affollato, deseleziona le categorie che non ti servono. Ad esempio, nascondi le riunioni quando ti interessano solo le prossime finestre di manutenzione.',
    ],
];
