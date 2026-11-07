<?php
/**
 * FreeITSM — setup strings (nl).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nl
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'FreeITSM-setup',
    'heading' => 'Setupverificatie',
    'storage' => [
        'heading' => 'Stop — bestanden die je uploadt worden bij elke update verwijderd',
        'explain' => 'FreeITSM draait in Docker en sommige mappen waarin geüploade bestanden worden opgeslagen, staan in de container in plaats van op een Docker-volume. Bij het updaten van FreeITSM wordt de container opnieuw opgebouwd en alles erin gaat daarbij verloren. Je database staat op een volume en blijft behouden, dus achteraf zouden je bijlagen nog wel in de lijst staan terwijl de bestanden zelf weg zijn. Twee minuten nu voorkomt dit voorgoed.',
        'encryption_key' => 'Dit omvat je versleutelingssleutel. Als die verloren gaat, raken opgeslagen postvakwachtwoorden en integratiegegevens niet beschadigd, maar ze kunnen nooit meer worden gelezen en elk moet met de hand opnieuw worden ingevoerd.',
        'existing_install' => 'Deze installatie is al in gebruik, dus voeg deze niet zomaar toe om daarna opnieuw op te bouwen. Kopieer eerst de mappen uit de draaiende container — een volume toevoegen redt geen bestanden die er al in staan. Systeem → Debug Tools → D013 geeft je de opdrachten in de juiste volgorde.',
        'step1' => '1. Maak een nieuw bestand met de naam docker-compose.override.yml, in dezelfde map als docker-compose.yml, met precies dit erin. Bewerk docker-compose.yml zelf niet — dat bestand wordt bij een update vervangen en je wijzigingen zouden verloren gaan of de update blokkeren. FreeITSM leest het override-bestand automatisch.',
        'step2' => '2. Pas het toe:',
        'foot' => 'Laad daarna deze pagina opnieuw — dit bericht is dan verdwenen. Er hoeft niets anders te veranderen en het nu doen kost je niets, omdat er nog niets in deze mappen is opgeslagen.',
        'foot_in_use' => 'Laad daarna deze pagina opnieuw — dit bericht is dan verdwenen. Sla de stap hierboven om te kopiëren niet over: er staan al bestanden in deze mappen en opnieuw opbouwen zonder ze eerst te kopiëren verwijdert ze voorgoed.',
    ],
    'summary' => [
        'passed' => '{n} geslaagd',
        'warning' => '{n} waarschuwing',
        'warnings' => '{n} waarschuwingen',
        'failed' => '{n} mislukt',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Databaseverbinding',
        'encryption_key' => 'Versleutelingssleutel',
        'ssl_verify' => 'Verificatie van HTTPS-certificaten',
        'ca_bundle_ini' => 'CA-bundel in php.ini',
        'display_errors' => 'Fouten weergeven',
        'php_version' => 'PHP-versie',
        'php_extension' => 'PHP-extensie: {ext}',
        'php_extension_optional' => 'PHP-extensie: {ext} (optioneel)',
        'storage_persistence' => 'Persistentie van opslag (Docker)',
    ],
    'detail' => [
        'found' => 'Gevonden',
        'storage_persisted' => 'Elke map met geüploade bestanden staat op opslag die een herbouw overleeft',
        'storage_at_risk' => 'NIET op een Docker-volume en bij elke herbouw leeggemaakt: {dirs} — voeg nu voor elk daarvan een volume toe, voordat er iets in wordt opgeslagen',
        'storage_at_risk_masked' => '{n} mappen met geüploade bestanden zouden een update niet overleven',
        'config_not_found' => 'Niet gevonden — kopieer config.php naar de hoofdmap van de applicatie',
        'db_config_not_found' => 'Niet gevonden op: {path}',
        'db_config_path_unset' => 'Variabele $db_config_path is niet ingesteld in config.php',
        'db_connected' => 'Verbonden (driver: {driver})',
        'db_constants_undefined' => 'Databaseconstanten niet gedefinieerd — controleer db_config.php',
        'encryption_key_missing' => 'Niet gevonden op: {path} — nodig voor het versleutelen van gevoelige instellingen',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH is niet gedefinieerd in includes/encryption.php',
        'ssl_enabled' => 'Ingeschakeld',
        'ssl_verified' => 'Aan en werkend — een live HTTPS-verzoek is certificaatgeverifieerd (CA-bundel: {bundle})',
        'ssl_broken' => 'Aan, maar de server kon een certificaat niet verifiëren — uitgaand HTTPS (e-mail, AI, webhooks, aanmelden) zal mislukken. Eenvoudigste oplossing: plaats een bestand cacert.pem in de map includes/ van de app (download van https://curl.se/ca/cacert.pem) — er zijn geen wijzigingen in php.ini nodig. Fout: {error}',
        'ssl_untested' => 'Aan, maar een live testverzoek kon niet worden voltooid (geen uitgaand netwerk?), dus de verificatie kon niet worden bevestigd. Fout: {error}',
        'ssl_bundle_system' => 'systeemopslag',
        'help_link' => 'Zo los je dit op — handleiding HTTPS-certificaten →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'niet ingesteld',
        'ca_ini_missing' => '{path} (bestand ontbreekt!)',
        'ca_ini_note_fix' => ' — corrigeer het pad of zet de instelling in commentaar in php.ini.',
        'ca_ini_note_fallback' => ' — optioneel: FreeITSM valt terug op zijn meegeleverde CA-lijst (Windows) of de vertrouwensopslag van het besturingssysteem (Linux). Let op: dit geeft de PHP van de webserver weer; de achtergrondworker gebruikt een aparte CLI-php.ini.',
        'ssl_disabled' => 'Uitgeschakeld — schakel in voor productie (zet SSL_VERIFY_PEER op true in config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER is niet gedefinieerd in config.php',
        'display_errors_enabled' => 'Ingeschakeld — schakel uit voor productie (zet display_errors op 0 in config.php)',
        'display_errors_disabled' => 'Uitgeschakeld',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 of hoger is vereist',
        'php_version_eol' => '{version} — nog ondersteund, maar deze versie heeft geen beveiligingsupdates meer ontvangen sinds het einde van de levensduur. PHP 8.3 of 8.4 aanbevolen.',
        'extension_loaded' => 'Geladen',
        'extension_not_loaded' => 'Niet geladen — schakel in via php.ini',
        'pdo_mysql_not_loaded' => 'Niet geladen — schakel pdo_mysql in via php.ini',
        'db_config_not_found_masked' => 'Niet gevonden op het pad dat in config.php is ingesteld',
        'ssl_verified_masked' => 'Aan en werkend — een live HTTPS-verzoek is certificaatgeverifieerd',
        'ssl_broken_masked' => 'Aan, maar de server kon een certificaat niet verifiëren — uitgaand HTTPS (e-mail, AI, webhooks, aanmelden) zal mislukken. Meld je aan als beheerder om de fout te zien.',
        'ssl_untested_masked' => 'Aan, maar een live testverzoek kon niet worden voltooid, dus de verificatie kon niet worden bevestigd.',
        'db_error_masked' => 'Kon geen verbinding maken — meld je aan als beheerder om de volledige fout te zien',
        'encryption_key_missing_masked' => 'Niet gevonden — nodig voor het versleutelen van gevoelige instellingen',
        'ca_ini_masked_ok' => 'Geconfigureerd',
        'ca_ini_masked_broken' => 'Ingesteld, maar verwijst naar een bestand dat er niet is — corrigeer het pad of zet de instelling in commentaar in php.ini.',
        'php_version_ok_masked' => 'Voldoet aan de eis',
        'php_version_too_low_masked' => 'Te oud — PHP 8.1 of hoger is vereist',
        'php_version_eol_masked' => 'Ondersteund, maar deze versie heeft het einde van de levensduur bereikt en ontvangt geen beveiligingsupdates meer. Meld je aan als beheerder om de versie te zien.',
    ],
    'locked' => [
        'notice' => 'De setup is voltooid op deze installatie, dus paden, verbindingsfouten en inloggegevens zijn verborgen. Meld je aan als beheerder om alle details te zien.',
    ],
    'db_verify' => [
        'heading' => 'Databaseverificatie',
        'intro' => 'Controleer ontbrekende tabellen of kolommen in de database en maak ze automatisch aan.',
        'run' => 'Uitvoeren',
    ],
    'login' => [
        'heading' => 'Standaardaanmelding',
        'intro' => 'Er wordt een standaardbeheerdersaccount aangemaakt wanneer je Databaseverificatie uitvoert.',
        'username' => 'Gebruikersnaam:',
        'password' => 'Wachtwoord:',
    ],
    'footer' => [
        'warning' => 'Verwijder de map {folder} om veiligheidsredenen zodra je systeem in productie is.',
        'signature' => 'FreeITSM-setupverificatie',
    ],
    'js' => [
        'running' => 'Bezig...',
        'run' => 'Uitvoeren',
        'tables_checked' => '{n} tabellen gecontroleerd:',
        'ok' => '{n} OK',
        'created' => '{n} aangemaakt',
        'updated' => '{n} bijgewerkt',
        'errors' => '{n} fouten',
        'unknown_error' => 'Onbekende fout',
        'verify_failed' => 'Databaseverificatie uitvoeren mislukt: {error}',
    ],
];
