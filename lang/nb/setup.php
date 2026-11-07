<?php
/**
 * FreeITSM — setup strings (nb).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nb
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'FreeITSM-oppsett',
    'heading' => 'Oppsettskontroll',
    'storage' => [
        'heading' => 'Stopp — filer du laster opp, blir slettet hver gang du oppdaterer',
        'explain' => 'FreeITSM kjører i Docker, og noen av mappene den lagrer opplastede filer i, er inne i containeren fremfor på et Docker-volum. Å oppdatere FreeITSM bygger containeren på nytt, og alt inne i den kastes når det skjer. Databasen din er på et volum og vil overleve, så etterpå ville vedleggene dine fortsatt vært oppført mens selve filene var borte. To minutter nå forhindrer dette permanent.',
        'encryption_key' => 'Dette inkluderer krypteringsnøkkelen din. Hvis den går tapt, blir lagrede postkassepassord og integrasjonslegitimasjon ikke skadet, men kan aldri leses igjen, og hver av dem må angis for hånd.',
        'existing_install' => 'Denne installasjonen er allerede i bruk, så ikke bare legg til disse og bygg på nytt. Kopier mappene ut av den kjørende containeren først — å legge til et volum redder ikke filer som allerede er inne i den. System → Feilsøkingsverktøy → D013 gir deg kommandoene i riktig rekkefølge.',
        'step1' => '1. Opprett en ny fil kalt docker-compose.override.yml, i samme mappe som docker-compose.yml, med nøyaktig dette innholdet. Ikke rediger docker-compose.yml selv — den filen erstattes når du oppdaterer, og endringene dine ville gått tapt eller blokkert oppdateringen. FreeITSM leser overstyringsfilen automatisk.',
        'step2' => '2. Bruk den:',
        'foot' => 'Last deretter siden på nytt — denne meldingen vil være borte. Ingenting annet trenger å endres, og å gjøre det nå koster deg ingenting, fordi det ikke er lagret noe i disse mappene ennå.',
        'foot_in_use' => 'Last deretter siden på nytt — denne meldingen vil være borte. Ikke hopp over kopieringssteget over: det finnes allerede filer i disse mappene, og å bygge på nytt uten å kopiere dem ut først, vil slette dem for godt.',
    ],
    'summary' => [
        'passed' => '{n} bestått',
        'warning' => '{n} advarsel',
        'warnings' => '{n} advarsler',
        'failed' => '{n} feilet',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Databasetilkobling',
        'encryption_key' => 'Krypteringsnøkkel',
        'ssl_verify' => 'Verifisering av HTTPS-sertifikat',
        'ca_bundle_ini' => 'CA-pakke i php.ini',
        'display_errors' => 'Vis feil',
        'php_version' => 'PHP-versjon',
        'php_extension' => 'PHP-utvidelse: {ext}',
        'php_extension_optional' => 'PHP-utvidelse: {ext} (valgfri)',
        'storage_persistence' => 'Lagringspersistens (Docker)',
    ],
    'detail' => [
        'found' => 'Funnet',
        'storage_persisted' => 'Hver mappe som holder opplastede filer, er på lagring som overlever en ny bygging',
        'storage_at_risk' => 'IKKE på et Docker-volum og tømt ved hver ny bygging: {dirs} — legg til et volum for hver av disse nå, før noe lagres i dem',
        'storage_at_risk_masked' => '{n} mapper som holder opplastede filer, ville ikke overlevd en oppdatering',
        'config_not_found' => 'Ikke funnet — kopier config.php til programmets rotmappe',
        'db_config_not_found' => 'Ikke funnet på: {path}',
        'db_config_path_unset' => 'Variabelen $db_config_path er ikke satt i config.php',
        'db_connected' => 'Tilkoblet (driver: {driver})',
        'db_constants_undefined' => 'Databasekonstantene er ikke definert — sjekk db_config.php',
        'encryption_key_missing' => 'Ikke funnet på: {path} — kreves for å kryptere sensitive innstillinger',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH er ikke definert i includes/encryption.php',
        'ssl_enabled' => 'Aktivert',
        'ssl_verified' => 'På og fungerer — sertifikatet ble verifisert i en reell HTTPS-forespørsel (CA-pakke: {bundle})',
        'ssl_broken' => 'På, men serveren klarte ikke å verifisere et sertifikat — utgående HTTPS (e-post, KI, webhooks, innlogging) vil feile. Enkleste løsning: legg en cacert.pem-fil i programmets includes/-mappe (last ned fra https://curl.se/ca/cacert.pem) — ingen endringer i php.ini er nødvendig. Feil: {error}',
        'ssl_untested' => 'På, men en reell testforespørsel kunne ikke fullføres (ingen utgående nettverkstilgang?), så verifiseringen kunne ikke bekreftes. Feil: {error}',
        'ssl_bundle_system' => 'systemlageret',
        'help_link' => 'Slik retter du dette — veiledning for HTTPS-sertifikater →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'ikke satt',
        'ca_ini_missing' => '{path} (filen mangler!)',
        'ca_ini_note_fix' => ' — rett stien eller kommenter ut innstillingen i php.ini.',
        'ca_ini_note_fallback' => ' — valgfritt: FreeITSM faller tilbake på sin egen medfølgende CA-liste (Windows) eller operativsystemets tillitslager (Linux). Merk: dette gjelder PHP i webserveren; bakgrunnsprosessen bruker en egen php.ini for CLI.',
        'ssl_disabled' => 'Deaktivert — bør slås på i produksjon (sett SSL_VERIFY_PEER til true i config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER er ikke definert i config.php',
        'display_errors_enabled' => 'Aktivert — bør slås av i produksjon (sett display_errors til 0 i config.php)',
        'display_errors_disabled' => 'Deaktivert',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 eller nyere kreves',
        'php_version_eol' => '{version} — fortsatt støttet, men denne versjonen har ikke fått sikkerhetsoppdateringer siden den nådde slutten på levetiden. PHP 8.3 eller 8.4 anbefales.',
        'extension_loaded' => 'Lastet',
        'extension_not_loaded' => 'Ikke lastet — aktiver den i php.ini',
        'pdo_mysql_not_loaded' => 'Ikke lastet — aktiver pdo_mysql i php.ini',
        'db_config_not_found_masked' => 'Ikke funnet på stien som er satt i config.php',
        'ssl_verified_masked' => 'På og fungerer — sertifikatet ble verifisert i en reell HTTPS-forespørsel',
        'ssl_broken_masked' => 'På, men serveren klarte ikke å verifisere et sertifikat — utgående HTTPS (e-post, KI, webhooks, innlogging) vil feile. Logg inn som administrator for å se feilen.',
        'ssl_untested_masked' => 'På, men en reell testforespørsel kunne ikke fullføres, så verifiseringen kunne ikke bekreftes.',
        'db_error_masked' => 'Kunne ikke koble til — logg inn som administrator for å se hele feilmeldingen',
        'encryption_key_missing_masked' => 'Ikke funnet — kreves for å kryptere sensitive innstillinger',
        'ca_ini_masked_ok' => 'Konfigurert',
        'ca_ini_masked_broken' => 'Satt, men peker på en fil som ikke finnes — rett stien eller kommenter ut innstillingen i php.ini.',
        'php_version_ok_masked' => 'Oppfyller kravet',
        'php_version_too_low_masked' => 'For gammel — PHP 8.1 eller høyere kreves',
        'php_version_eol_masked' => 'Støttet, men denne utgivelsen har nådd slutten av sin levetid og mottar ikke lenger sikkerhetsoppdateringer. Logg inn som administrator for å se versjonen.',
    ],
    'locked' => [
        'notice' => 'Oppsettet er fullført på denne installasjonen, så stier, tilkoblingsfeil og påloggingsdetaljer er skjult. Logg inn som administrator for å se alle detaljer.',
    ],
    'db_verify' => [
        'heading' => 'Databasekontroll',
        'intro' => 'Sjekk databasen og opprett automatisk tabeller eller kolonner som mangler.',
        'run' => 'Kjør',
    ],
    'login' => [
        'heading' => 'Standard innlogging',
        'intro' => 'En standard administratorkonto opprettes når du kjører databasekontrollen.',
        'username' => 'Brukernavn:',
        'password' => 'Passord:',
    ],
    'footer' => [
        'warning' => 'Når systemet er satt i produksjon, bør du slette mappen {folder} av sikkerhetshensyn.',
        'signature' => 'FreeITSM oppsettskontroll',
    ],
    'js' => [
        'running' => 'Kjører ...',
        'run' => 'Kjør',
        'tables_checked' => '{n} tabeller sjekket:',
        'ok' => '{n} OK',
        'created' => '{n} opprettet',
        'updated' => '{n} oppdatert',
        'errors' => '{n} feil',
        'unknown_error' => 'Ukjent feil',
        'verify_failed' => 'Kunne ikke kjøre databasekontrollen: {error}',
    ],
];
