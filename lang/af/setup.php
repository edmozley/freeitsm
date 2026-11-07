<?php
/**
 * FreeITSM — setup strings (af).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php af
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'FreeITSM-opstelling',
    'heading' => 'Opstellingsverifikasie',
    'storage' => [
        'heading' => 'Stop — lêers wat jy oplaai sal elke keer geskrap word wanneer jy opdateer',
        'explain' => 'FreeITSM loop in Docker, en sommige van die vouers waarin dit opgelaaide lêers stoor, is binne die houer eerder as op \'n Docker-volume. Wanneer FreeITSM opgedateer word, word die houer herbou, en alles daarbinne word weggegooi wanneer dit gebeur. Jou databasis is op \'n volume en sal oorleef, so jou aanhangsels sal daarna steeds gelys wees terwyl die lêers self weg is. Twee minute nou voorkom dit permanent.',
        'encryption_key' => 'Dit sluit jou enkripsiesleutel in. As dit verlore gaan, word gestoorde posbuswagwoorde en integrasie-geloofsbriewe nie beskadig nie, maar kan dit nooit weer gelees word nie, en elkeen moet met die hand ingevoer word.',
        'existing_install' => 'Hierdie installasie is reeds in gebruik, so moenie bloot hierdie byvoeg en herbou nie. Kopieer eers die vouers uit die lopende houer — om \'n volume by te voeg red nie lêers wat reeds daarin is nie. Stelsel → Ontfoutingsgereedskap → D013 gee jou die opdragte in die regte volgorde.',
        'step1' => '1. Skep \'n nuwe lêer genaamd docker-compose.override.yml, in dieselfde vouer as docker-compose.yml, wat presies dit bevat. Moenie docker-compose.yml self wysig nie — daardie lêer word vervang wanneer jy opdateer, en jou veranderinge sal verlore gaan of die opdatering blokkeer. FreeITSM lees die oorskryf-lêer outomaties.',
        'step2' => '2. Pas dit toe:',
        'foot' => 'Herlaai dan hierdie bladsy — hierdie boodskap sal weg wees. Niks anders hoef te verander nie, en om dit nou te doen kos jou niks nie, want daar is nog niks in hierdie vouers gestoor nie.',
        'foot_in_use' => 'Herlaai dan hierdie bladsy — hierdie boodskap sal weg wees. Moenie die uitkopieerstap hierbo oorslaan nie: daar is reeds lêers in hierdie vouers, en om te herbou sonder om hulle eers uit te kopieer sal hulle vir goed skrap.',
    ],
    'summary' => [
        'passed' => '{n} geslaag',
        'warning' => '{n} waarskuwing',
        'warnings' => '{n} waarskuwings',
        'failed' => '{n} misluk',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Databasisverbinding',
        'encryption_key' => 'Enkripsiesleutel',
        'ssl_verify' => 'HTTPS-sertifikaatverifikasie',
        'ca_bundle_ini' => 'CA-bundel in php.ini',
        'display_errors' => 'Vertoon foute',
        'php_version' => 'PHP-weergawe',
        'php_extension' => 'PHP-uitbreiding: {ext}',
        'php_extension_optional' => 'PHP-uitbreiding: {ext} (opsioneel)',
        'storage_persistence' => 'Bergingsvolharding (Docker)',
    ],
    'detail' => [
        'found' => 'Gevind',
        'storage_persisted' => 'Elke vouer wat opgelaaide lêers hou, is op berging wat \'n herbou oorleef',
        'storage_at_risk' => 'NIE op \'n Docker-volume nie en leeggemaak deur elke herbou: {dirs} — voeg nou \'n volume vir elkeen hiervan by, voordat enigiets daarin gestoor word',
        'storage_at_risk_masked' => '{n} vouers wat opgelaaide lêers hou, sal nie \'n opdatering oorleef nie',
        'config_not_found' => 'Nie gevind nie — kopieer config.php na die toepassingswortel',
        'db_config_not_found' => 'Nie gevind by: {path}',
        'db_config_path_unset' => '$db_config_path-veranderlike nie in config.php gestel nie',
        'db_connected' => 'Gekoppel (drywer: {driver})',
        'db_constants_undefined' => 'Databasiskonstantes nie gedefinieer nie — kontroleer db_config.php',
        'encryption_key_missing' => 'Nie gevind by: {path} nie — nodig om sensitiewe instellings te enkripteer',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH nie in includes/encryption.php gedefinieer nie',
        'ssl_enabled' => 'Aangeskakel',
        'ssl_verified' => 'Aan en werk — \'n lewendige HTTPS-versoek is sertifikaat-geverifieer (CA-bundel: {bundle})',
        'ssl_broken' => 'Aan, maar die bediener kon nie \'n sertifikaat verifieer nie — uitgaande HTTPS (e-pos, KI, webhooks, aanmelding) sal misluk. Eenvoudigste regstelling: sit \'n cacert.pem-lêer in die toepassing se includes/-vouer (laai af vanaf https://curl.se/ca/cacert.pem) — geen php.ini-veranderinge nodig nie. Fout: {error}',
        'ssl_untested' => 'Aan, maar \'n lewendige toetsversoek kon nie voltooi word nie (geen uitgaande netwerk?), so verifikasie kon nie bevestig word nie. Fout: {error}',
        'ssl_bundle_system' => 'stelselwinkel',
        'help_link' => 'Hoe om dit reg te maak — HTTPS-sertifikaatgids →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'nie gestel nie',
        'ca_ini_missing' => '{path} (lêer ontbreek!)',
        'ca_ini_note_fix' => ' — maak die pad reg of merk die instelling in php.ini as kommentaar.',
        'ca_ini_note_fallback' => ' — opsioneel: FreeITSM val terug op sy meegeleverde CA-lys (Windows) of die BS-vertrouensberging (Linux). Let wel: dit weerspieël die webbediener se PHP; die agtergrondwerker gebruik \'n aparte CLI php.ini.',
        'ssl_disabled' => 'Afgeskakel — skakel aan vir produksie (stel SSL_VERIFY_PEER op true in config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER nie in config.php gedefinieer nie',
        'display_errors_enabled' => 'Aangeskakel — skakel af vir produksie (stel display_errors op 0 in config.php)',
        'display_errors_disabled' => 'Afgeskakel',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 of hoër word vereis',
        'php_version_eol' => '{version} — steeds ondersteun, maar hierdie weergawe het geen sekuriteitsopdaterings gehad sedert dit end-of-life bereik het nie. PHP 8.3 of 8.4 word aanbeveel.',
        'extension_loaded' => 'Gelaai',
        'extension_not_loaded' => 'Nie gelaai nie — skakel aan in php.ini',
        'pdo_mysql_not_loaded' => 'Nie gelaai nie — skakel pdo_mysql aan in php.ini',
        'db_config_not_found_masked' => 'Nie gevind by die pad wat in config.php gestel is nie',
        'ssl_verified_masked' => 'Aan en werk — \'n lewendige HTTPS-versoek is sertifikaat-geverifieer',
        'ssl_broken_masked' => 'Aan, maar die bediener kon nie \'n sertifikaat verifieer nie — uitgaande HTTPS (e-pos, KI, webhooks, aanmelding) sal misluk. Meld aan as \'n administrateur om die fout te sien.',
        'ssl_untested_masked' => 'Aan, maar \'n lewendige toetsversoek kon nie voltooi word nie, so verifikasie kon nie bevestig word nie.',
        'db_error_masked' => 'Kon nie koppel nie — meld aan as \'n administrateur om die volledige fout te sien',
        'encryption_key_missing_masked' => 'Nie gevind nie — nodig om sensitiewe instellings te enkripteer',
        'ca_ini_masked_ok' => 'Opgestel',
        'ca_ini_masked_broken' => 'Gestel, maar wys na \'n lêer wat nie daar is nie — maak die pad reg of merk die instelling in php.ini as kommentaar.',
        'php_version_ok_masked' => 'Voldoen aan die vereiste',
        'php_version_too_low_masked' => 'Te oud — PHP 8.1 of hoër word vereis',
        'php_version_eol_masked' => 'Ondersteun, maar hierdie weergawe het end-of-life bereik en ontvang nie meer sekuriteitsopdaterings nie. Meld aan as \'n administrateur om die weergawe te sien.',
    ],
    'locked' => [
        'notice' => 'Opstelling is voltooi op hierdie installasie, so paaie, verbindingsfoute en geloofsbriewe is versteek. Meld aan as \'n administrateur om die volledige besonderhede te sien.',
    ],
    'db_verify' => [
        'heading' => 'Databasisverifikasie',
        'intro' => 'Kontroleer en skep outomaties enige ontbrekende tabelle of kolomme in die databasis.',
        'run' => 'Loop',
    ],
    'login' => [
        'heading' => 'Verstekaanmelding',
        'intro' => '\'n Verstek-adminrekening word geskep wanneer jy Databasisverifikasie laat loop.',
        'username' => 'Gebruikersnaam:',
        'password' => 'Wagwoord:',
    ],
    'footer' => [
        'warning' => 'Sodra jou stelsel in produksie is, skrap die {folder}-vouer vir sekuriteit.',
        'signature' => 'FreeITSM-opstellingsverifikasie',
    ],
    'js' => [
        'running' => 'Loop tans...',
        'run' => 'Loop',
        'tables_checked' => '{n} tabelle gekontroleer:',
        'ok' => '{n} OK',
        'created' => '{n} geskep',
        'updated' => '{n} bygewerk',
        'errors' => '{n} foute',
        'unknown_error' => 'Onbekende fout',
        'verify_failed' => 'Kon nie DB-verifikasie laat loop nie: {error}',
    ],
];
