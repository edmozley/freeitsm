<?php
/**
 * FreeITSM — setup strings (it).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php it
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Configurazione di FreeITSM',
    'heading' => 'Verifica della configurazione',
    'storage' => [
        'heading' => 'Fermati — i file caricati verranno eliminati a ogni aggiornamento',
        'explain' => 'FreeITSM è in esecuzione in Docker e alcune delle cartelle in cui salva i file caricati si trovano all\'interno del container invece che su un volume Docker. L\'aggiornamento di FreeITSM ricostruisce il container e tutto ciò che contiene viene eliminato. Il database è su un volume e sopravvive, quindi dopo l\'aggiornamento gli allegati risulterebbero ancora in elenco mentre i file non ci sarebbero più. Due minuti ora evitano il problema per sempre.',
        'encryption_key' => 'Questo include la tua chiave di crittografia. Se va persa, le password delle caselle di posta e le credenziali delle integrazioni salvate non vengono danneggiate ma non potranno mai più essere lette, e ognuna va reinserita a mano.',
        'existing_install' => 'Questa installazione è già in uso, quindi non limitarti ad aggiungerli e ricostruire. Copia prima le cartelle fuori dal container in esecuzione — aggiungere un volume non salva i file che sono già al suo interno. Sistema → Strumenti di debug → D013 ti dà i comandi nell\'ordine giusto.',
        'step1' => '1. Crea un nuovo file chiamato docker-compose.override.yml, nella stessa cartella di docker-compose.yml, con esattamente questo contenuto. Non modificare docker-compose.yml: quel file viene sostituito a ogni aggiornamento e le tue modifiche andrebbero perse o bloccherebbero l\'aggiornamento. FreeITSM legge automaticamente il file override.',
        'step2' => '2. Applicalo:',
        'foot' => 'Poi ricarica questa pagina — questo messaggio sparirà. Non serve cambiare altro e farlo ora non costa nulla, perché in queste cartelle non è ancora salvato nulla.',
        'foot_in_use' => 'Poi ricarica questa pagina — questo messaggio sparirà. Non saltare il passaggio di copia qui sopra: in queste cartelle ci sono già dei file e ricostruire senza copiarli prima li eliminerà definitivamente.',
    ],
    'summary' => [
        'passed' => '{n} superati',
        'warning' => '{n} avviso',
        'warnings' => '{n} avvisi',
        'failed' => '{n} non riusciti',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Connessione al database',
        'encryption_key' => 'Chiave di crittografia',
        'ssl_verify' => 'Verifica del certificato HTTPS',
        'ca_bundle_ini' => 'Bundle CA in php.ini',
        'display_errors' => 'Visualizzazione errori',
        'php_version' => 'Versione PHP',
        'php_extension' => 'Estensione PHP: {ext}',
        'php_extension_optional' => 'Estensione PHP: {ext} (facoltativa)',
        'storage_persistence' => 'Persistenza dell\'archiviazione (Docker)',
    ],
    'detail' => [
        'found' => 'Trovato',
        'storage_persisted' => 'Ogni cartella che contiene file caricati si trova su un\'archiviazione che sopravvive alla ricostruzione',
        'storage_at_risk' => 'NON su un volume Docker e svuotate a ogni ricostruzione: {dirs} — aggiungi subito un volume per ciascuna, prima che vi venga salvato qualcosa',
        'storage_at_risk_masked' => '{n} cartelle che contengono file caricati non sopravvivrebbero a un aggiornamento',
        'config_not_found' => 'Non trovato — copia config.php nella radice dell\'applicazione',
        'db_config_not_found' => 'Non trovato in: {path}',
        'db_config_path_unset' => 'Variabile $db_config_path non impostata in config.php',
        'db_connected' => 'Connesso (driver: {driver})',
        'db_constants_undefined' => 'Costanti del database non definite — controlla db_config.php',
        'encryption_key_missing' => 'Non trovata in: {path} — necessaria per crittografare le impostazioni sensibili',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH non definito in includes/encryption.php',
        'ssl_enabled' => 'Attivata',
        'ssl_verified' => 'Attiva e funzionante — una richiesta HTTPS reale è stata verificata tramite certificato (bundle CA: {bundle})',
        'ssl_broken' => 'Attiva, ma il server non ha potuto verificare un certificato — l\'HTTPS in uscita (email, IA, webhook, accesso) non funzionerà. Soluzione più semplice: metti un file cacert.pem nella cartella includes/ dell\'applicazione (scaricalo da https://curl.se/ca/cacert.pem) — non servono modifiche a php.ini. Errore: {error}',
        'ssl_untested' => 'Attiva, ma non è stato possibile completare una richiesta di prova (rete in uscita assente?), quindi la verifica non ha potuto essere confermata. Errore: {error}',
        'ssl_bundle_system' => 'archivio di sistema',
        'help_link' => 'Come risolvere — guida ai certificati HTTPS →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'non impostato',
        'ca_ini_missing' => '{path} (file mancante!)',
        'ca_ini_note_fix' => ' — correggi il percorso oppure commenta l\'impostazione in php.ini.',
        'ca_ini_note_fallback' => ' — facoltativo: FreeITSM ripiega sul proprio elenco CA incluso (Windows) o sull\'archivio di fiducia del sistema operativo (Linux). Nota: questo riflette il PHP del web server; il worker in background usa un php.ini CLI separato.',
        'ssl_disabled' => 'Disattivata — attivala in produzione (imposta SSL_VERIFY_PEER su true in config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER non definito in config.php',
        'display_errors_enabled' => 'Attivata — disattivala in produzione (imposta display_errors su 0 in config.php)',
        'display_errors_disabled' => 'Disattivata',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — è richiesto PHP 8.1 o superiore',
        'php_version_eol' => '{version} — ancora supportata, ma questa versione non riceve aggiornamenti di sicurezza da quando ha raggiunto il fine vita. Consigliato PHP 8.3 o 8.4.',
        'extension_loaded' => 'Caricata',
        'extension_not_loaded' => 'Non caricata — attivala in php.ini',
        'pdo_mysql_not_loaded' => 'Non caricata — attiva pdo_mysql in php.ini',
        'db_config_not_found_masked' => 'Non trovato nel percorso impostato in config.php',
        'ssl_verified_masked' => 'Attiva e funzionante — una richiesta HTTPS reale è stata verificata tramite certificato',
        'ssl_broken_masked' => 'Attiva, ma il server non ha potuto verificare un certificato — l\'HTTPS in uscita (email, IA, webhook, accesso) non funzionerà. Accedi come amministratore per vedere l\'errore.',
        'ssl_untested_masked' => 'Attiva, ma non è stato possibile completare una richiesta di prova, quindi la verifica non ha potuto essere confermata.',
        'db_error_masked' => 'Connessione non riuscita — accedi come amministratore per vedere l\'errore completo',
        'encryption_key_missing_masked' => 'Non trovata — necessaria per crittografare le impostazioni sensibili',
        'ca_ini_masked_ok' => 'Configurato',
        'ca_ini_masked_broken' => 'Impostato, ma punta a un file che non esiste — correggi il percorso oppure commenta l\'impostazione in php.ini.',
        'php_version_ok_masked' => 'Soddisfa il requisito',
        'php_version_too_low_masked' => 'Troppo vecchia — è richiesto PHP 8.1 o superiore',
        'php_version_eol_masked' => 'Supportata, ma questa versione ha raggiunto il fine vita e non riceve più aggiornamenti di sicurezza. Accedi come amministratore per vedere la versione.',
    ],
    'locked' => [
        'notice' => 'La configurazione è completata su questa installazione, quindi percorsi, errori di connessione e credenziali sono nascosti. Accedi come amministratore per vedere tutti i dettagli.',
    ],
    'db_verify' => [
        'heading' => 'Verifica database',
        'intro' => 'Controlla e crea automaticamente le tabelle o le colonne mancanti nel database.',
        'run' => 'Esegui',
    ],
    'login' => [
        'heading' => 'Accesso predefinito',
        'intro' => 'Un account admin predefinito viene creato quando esegui Verifica database.',
        'username' => 'Nome utente:',
        'password' => 'Password:',
    ],
    'footer' => [
        'warning' => 'Quando il sistema è in produzione, elimina la cartella {folder} per sicurezza.',
        'signature' => 'Verifica della configurazione di FreeITSM',
    ],
    'js' => [
        'running' => 'In esecuzione...',
        'run' => 'Esegui',
        'tables_checked' => '{n} tabelle controllate:',
        'ok' => '{n} OK',
        'created' => '{n} create',
        'updated' => '{n} aggiornate',
        'errors' => '{n} errori',
        'unknown_error' => 'Errore sconosciuto',
        'verify_failed' => 'Impossibile eseguire la verifica del database: {error}',
    ],
];
