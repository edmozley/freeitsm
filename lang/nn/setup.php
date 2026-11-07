<?php
/**
 * FreeITSM — setup strings (nn).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nn
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'FreeITSM-oppsett',
    'heading' => 'Oppsettskontroll',
    'storage' => [
        'heading' => 'Stopp — filer du lastar opp blir sletta kvar gong du oppdaterer',
        'explain' => 'FreeITSM køyrer i Docker, og nokre av mappene han lagrar opplasta filer i, er inni containeren i staden for på eit Docker-volum. Å oppdatere FreeITSM bygger containeren om att, og alt inni han blir kasta når det skjer. Databasen din er på eit volum og overlever, så etterpå ville vedlegga dine framleis vore lista medan sjølve filene var borte. To minutt no hindrar dette for godt.',
        'encryption_key' => 'Dette inkluderer krypteringsnøkkelen din. Viss han går tapt, blir ikkje lagra postkassepassord og integrasjonslegitimasjon skadde, men dei kan aldri lesast igjen, og kvar ein må skrivast inn for hand.',
        'existing_install' => 'Denne installasjonen er alt i bruk, så ikkje berre legg desse til og bygg om att. Kopier mappene ut av den køyrande containeren først — å leggje til eit volum reddar ikkje filer som alt er inni han. System → Feilsøkingsverktøy → D013 gjev deg kommandoane i rett rekkjefølgje.',
        'step1' => '1. Lag ei ny fil kalla docker-compose.override.yml, i same mappe som docker-compose.yml, med nøyaktig dette innhaldet. Ikkje rediger sjølve docker-compose.yml — den fila blir bytt ut når du oppdaterer, og endringane dine ville gått tapt eller blokkert oppdateringa. FreeITSM les overstyringsfila automatisk.',
        'step2' => '2. Ta ho i bruk:',
        'foot' => 'Last så sida på nytt — denne meldinga vil vere borte. Ingenting anna treng endrast, og å gjere det no kostar deg ingenting, for det er ikkje lagra noko i desse mappene enno.',
        'foot_in_use' => 'Last så sida på nytt — denne meldinga vil vere borte. Ikkje hopp over kopieringssteget over: det er alt filer i desse mappene, og å byggje om att utan å kopiere dei ut først vil slette dei for godt.',
    ],
    'summary' => [
        'passed' => '{n} bestått',
        'warning' => '{n} åtvaring',
        'warnings' => '{n} åtvaringar',
        'failed' => '{n} feila',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Databasetilkopling',
        'encryption_key' => 'Krypteringsnøkkel',
        'ssl_verify' => 'Verifisering av HTTPS-sertifikat',
        'ca_bundle_ini' => 'CA-pakke i php.ini',
        'display_errors' => 'Vis feil',
        'php_version' => 'PHP-versjon',
        'php_extension' => 'PHP-utviding: {ext}',
        'php_extension_optional' => 'PHP-utviding: {ext} (valfri)',
        'storage_persistence' => 'Lagringsvaraktigheit (Docker)',
    ],
    'detail' => [
        'found' => 'Funnen',
        'storage_persisted' => 'Kvar mappe som held opplasta filer, er på lagring som overlever ei ombygging',
        'storage_at_risk' => 'IKKJE på eit Docker-volum, og tømt ved kvar ombygging: {dirs} — legg til eit volum for kvar av desse no, før noko blir lagra i dei',
        'storage_at_risk_masked' => '{n} mapper som held opplasta filer, ville ikkje overlevd ei oppdatering',
        'config_not_found' => 'Ikkje funnen — kopier config.php til rotmappa til programmet',
        'db_config_not_found' => 'Ikkje funnen på: {path}',
        'db_config_path_unset' => 'Variabelen $db_config_path er ikkje sett i config.php',
        'db_connected' => 'Tilkopla (drivar: {driver})',
        'db_constants_undefined' => 'Databasekonstantane er ikkje definerte — sjekk db_config.php',
        'encryption_key_missing' => 'Ikkje funnen på: {path} — krevst for å kryptere sensitive innstillingar',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH er ikkje definert i includes/encryption.php',
        'ssl_enabled' => 'Aktivert',
        'ssl_verified' => 'På og verkar — sertifikatet vart verifisert i ein reell HTTPS-førespurnad (CA-pakke: {bundle})',
        'ssl_broken' => 'På, men tenaren klarte ikkje å verifisere eit sertifikat — utgåande HTTPS (e-post, KI, webhooks, innlogging) vil feile. Enklaste løysinga: legg ei cacert.pem-fil i includes/-mappa til programmet (last ned frå https://curl.se/ca/cacert.pem) — ingen endringar i php.ini er nødvendige. Feil: {error}',
        'ssl_untested' => 'På, men ein reell testførespurnad kunne ikkje fullførast (ingen utgåande nettverkstilgang?), så verifiseringa kunne ikkje stadfestast. Feil: {error}',
        'ssl_bundle_system' => 'systemlageret',
        'help_link' => 'Slik rettar du dette — rettleiing for HTTPS-sertifikat →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'ikkje sett',
        'ca_ini_missing' => '{path} (fila manglar!)',
        'ca_ini_note_fix' => ' — rett stien eller kommenter ut innstillinga i php.ini.',
        'ca_ini_note_fallback' => ' — valfritt: FreeITSM fell tilbake på si eiga medfølgjande CA-liste (Windows) eller tillitslageret til operativsystemet (Linux). Merk: dette gjeld PHP i tenaren; bakgrunnsprosessen brukar ei eiga php.ini for CLI.',
        'ssl_disabled' => 'Deaktivert — bør slåast på i produksjon (sett SSL_VERIFY_PEER til true i config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER er ikkje definert i config.php',
        'display_errors_enabled' => 'Aktivert — bør slåast av i produksjon (sett display_errors til 0 i config.php)',
        'display_errors_disabled' => 'Deaktivert',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 eller nyare krevst',
        'php_version_eol' => '{version} — framleis støtta, men denne versjonen har ikkje fått tryggingsoppdateringar sidan han nådde slutten på levetida. PHP 8.3 eller 8.4 er tilrådd.',
        'extension_loaded' => 'Lasta',
        'extension_not_loaded' => 'Ikkje lasta — aktiver ho i php.ini',
        'pdo_mysql_not_loaded' => 'Ikkje lasta — aktiver pdo_mysql i php.ini',
        'db_config_not_found_masked' => 'Ikkje funnen på stien som er sett i config.php',
        'ssl_verified_masked' => 'På og verkar — sertifikatet vart verifisert i ein reell HTTPS-førespurnad',
        'ssl_broken_masked' => 'På, men tenaren klarte ikkje å verifisere eit sertifikat — utgåande HTTPS (e-post, KI, webhooks, innlogging) vil feile. Logg inn som administrator for å sjå feilen.',
        'ssl_untested_masked' => 'På, men ein reell testførespurnad kunne ikkje fullførast, så verifiseringa kunne ikkje stadfestast.',
        'db_error_masked' => 'Kunne ikkje kople til — logg inn som administrator for å sjå heile feilmeldinga',
        'encryption_key_missing_masked' => 'Ikkje funnen — krevst for å kryptere sensitive innstillingar',
        'ca_ini_masked_ok' => 'Konfigurert',
        'ca_ini_masked_broken' => 'Sett, men peikar på ei fil som ikkje finst — rett stien eller kommenter ut innstillinga i php.ini.',
        'php_version_ok_masked' => 'Oppfyller kravet',
        'php_version_too_low_masked' => 'For gammal — PHP 8.1 eller høgare er påkravd',
        'php_version_eol_masked' => 'Støtta, men denne utgåva har nådd slutten av levetida og får ikkje lenger tryggingsoppdateringar. Logg inn som administrator for å sjå versjonen.',
    ],
    'locked' => [
        'notice' => 'Oppsettet er fullført på denne installasjonen, så stiar, tilkoplingsfeil og påloggingsdetaljar er skjulte. Logg inn som administrator for å sjå alle detaljar.',
    ],
    'db_verify' => [
        'heading' => 'Databasekontroll',
        'intro' => 'Sjekk databasen og opprett automatisk tabellar eller kolonnar som manglar.',
        'run' => 'Køyr',
    ],
    'login' => [
        'heading' => 'Standard innlogging',
        'intro' => 'Ein standard administratorkonto blir oppretta når du køyrer databasekontrollen.',
        'username' => 'Brukarnamn:',
        'password' => 'Passord:',
    ],
    'footer' => [
        'warning' => 'Når systemet er sett i produksjon, bør du slette mappa {folder} av tryggingsomsyn.',
        'signature' => 'FreeITSM oppsettskontroll',
    ],
    'js' => [
        'running' => 'Køyrer ...',
        'run' => 'Køyr',
        'tables_checked' => '{n} tabellar sjekka:',
        'ok' => '{n} OK',
        'created' => '{n} oppretta',
        'updated' => '{n} oppdaterte',
        'errors' => '{n} feil',
        'unknown_error' => 'Ukjend feil',
        'verify_failed' => 'Kunne ikkje køyre databasekontrollen: {error}',
    ],
];
