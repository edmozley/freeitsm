<?php
/**
 * FreeITSM — setup strings (gu).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php gu
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'FreeITSM સેટઅપ',
    'heading' => 'સેટઅપ વેરિફિકેશન',
    'storage' => [
        'heading' => 'અટકો — તમે અપલોડ કરેલી ફાઇલો દર વખતે તમે અપડેટ કરો ત્યારે કાઢી નાખવામાં આવશે',
        'explain' => 'FreeITSM Docker માં ચાલી રહ્યું છે, અને તે અપલોડ કરેલી ફાઇલો સંગ્રહિત કરે છે તેમાંના કેટલાક ફોલ્ડર Docker વોલ્યુમને બદલે કન્ટેનરની અંદર છે. FreeITSM અપડેટ કરવાથી કન્ટેનર ફરીથી બને છે, અને તે થાય ત્યારે તેની અંદરની દરેક વસ્તુ ફેંકી દેવામાં આવે છે. તમારો ડેટાબેઝ વોલ્યુમ પર છે અને ટકી રહેશે, તેથી પછી તમારા એટેચમેન્ટ્સ યાદીમાં દેખાતા રહેશે જ્યારે ફાઇલો પોતે જ ગુમ થઈ ગઈ હશે. અત્યારે બે મિનિટ આને કાયમ માટે અટકાવે છે.',
        'encryption_key' => 'આમાં તમારી એન્ક્રિપ્શન કી શામેલ છે. જો તે ખોવાઈ જાય, તો સાચવેલા મેઈલબોક્સ પાસવર્ડ્સ અને ઈન્ટિગ્રેશન ક્રેડેન્શિયલ્સને નુકસાન થતું નથી પણ તે ફરી ક્યારેય વાંચી શકાશે નહીં, અને દરેકને હાથે ફરીથી દાખલ કરવું પડશે.',
        'existing_install' => 'આ ઈન્સ્ટોલેશન પહેલેથી ઉપયોગમાં છે, તેથી ફક્ત આ ઉમેરીને રિબિલ્ડ ન કરો. પહેલા ચાલી રહેલા કન્ટેનરમાંથી ફોલ્ડર્સની નકલ કરો — વોલ્યુમ ઉમેરવાથી પહેલેથી અંદર રહેલી ફાઇલો બચતી નથી. System → Debug Tools → D013 તમને યોગ્ય ક્રમમાં કમાન્ડ્સ આપે છે.',
        'step1' => '1. docker-compose.yml જે ફોલ્ડરમાં છે તે જ ફોલ્ડરમાં, docker-compose.override.yml નામની નવી ફાઇલ બનાવો, જેમાં બરાબર આ સામગ્રી હોય. docker-compose.yml ને જાતે સંપાદિત ન કરો — તે ફાઇલ તમે અપડેટ કરો ત્યારે બદલાઈ જાય છે, અને તમારા ફેરફારો ગુમ થઈ જશે અથવા અપડેટને અવરોધશે. FreeITSM ઓવરરાઈડ ફાઇલ આપમેળે વાંચે છે.',
        'step2' => '2. તેને લાગુ કરો:',
        'foot' => 'પછી આ પેજ ફરી લોડ કરો — આ સંદેશ જતો રહેશે. બીજું કંઈ બદલવાની જરૂર નથી, અને અત્યારે આ કરવાથી કંઈ ગુમાવવાનું નથી, કારણ કે આ ફોલ્ડર્સમાં હજુ સુધી કંઈ સંગ્રહિત નથી.',
        'foot_in_use' => 'પછી આ પેજ ફરી લોડ કરો — આ સંદેશ જતો રહેશે. ઉપરનું નકલ-બહાર-કાઢવાનું પગલું છોડશો નહીં: આ ફોલ્ડર્સમાં પહેલેથી ફાઇલો છે, અને પહેલા તેમની નકલ કર્યા વિના રિબિલ્ડ કરવાથી તે કાયમ માટે કાઢી નખાશે.',
    ],
    'summary' => [
        'passed' => '{n} પાસ થયું',
        'warning' => '{n} ચેતવણી',
        'warnings' => '{n} ચેતવણીઓ',
        'failed' => '{n} નિષ્ફળ',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'ડેટાબેઝ કનેક્શન',
        'encryption_key' => 'એન્ક્રિપ્શન કી',
        'ssl_verify' => 'HTTPS પ્રમાણપત્ર ચકાસણી',
        'ca_bundle_ini' => 'php.ini માં CA બંડલ',
        'display_errors' => 'ભૂલો દર્શાવવી',
        'php_version' => 'PHP વર્ઝન',
        'php_extension' => 'PHP એક્સટેન્શન: {ext}',
        'php_extension_optional' => 'PHP એક્સટેન્શન: {ext} (વૈકલ્પિક)',
        'storage_persistence' => 'સ્ટોરેજ પર્સિસ્ટન્સ (Docker)',
    ],
    'detail' => [
        'found' => 'મળ્યું',
        'storage_persisted' => 'અપલોડ કરેલી ફાઇલો ધરાવતું દરેક ફોલ્ડર એવા સ્ટોરેજ પર છે જે રિબિલ્ડ પછી પણ ટકી રહે છે',
        'storage_at_risk' => 'Docker વોલ્યુમ પર નથી અને દરેક રિબિલ્ડ વખતે ખાલી થાય છે: {dirs} — તેમાં કંઈ સંગ્રહિત થાય તે પહેલાં, આ દરેક માટે અત્યારે વોલ્યુમ ઉમેરો',
        'storage_at_risk_masked' => '{n} ફોલ્ડર્સ જેમાં અપલોડ કરેલી ફાઇલો છે તે અપડેટમાં ટકશે નહીં',
        'config_not_found' => 'મળ્યું નથી — config.php ને એપ્લિકેશન રૂટમાં નકલ કરો',
        'db_config_not_found' => 'અહીં મળ્યું નથી: {path}',
        'db_config_path_unset' => '$db_config_path વેરિયેબલ config.php માં સેટ નથી',
        'db_connected' => 'કનેક્ટેડ (ડ્રાઈવર: {driver})',
        'db_constants_undefined' => 'ડેટાબેઝ કોન્સ્ટન્ટ્સ વ્યાખ્યાયિત નથી — db_config.php તપાસો',
        'encryption_key_missing' => 'અહીં મળ્યું નથી: {path} — સંવેદનશીલ સેટિંગ્સને એન્ક્રિપ્ટ કરવા માટે જરૂરી',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH includes/encryption.php માં વ્યાખ્યાયિત નથી',
        'ssl_enabled' => 'સક્ષમ',
        'ssl_verified' => 'ચાલુ અને કાર્યરત — એક લાઈવ HTTPS વિનંતી પ્રમાણપત્ર-ચકાસાયેલ હતી (CA બંડલ: {bundle})',
        'ssl_broken' => 'ચાલુ, પણ સર્વર પ્રમાણપત્ર ચકાસી શક્યું નહીં — આઉટબાઉન્ડ HTTPS (ઈમેલ, AI, વેબહુક્સ, સાઈન-ઈન) નિષ્ફળ જશે. સૌથી સરળ ઉકેલ: એપ્લિકેશનના includes/ ફોલ્ડરમાં cacert.pem ફાઇલ મૂકો (https://curl.se/ca/cacert.pem પરથી ડાઉનલોડ કરો) — php.ini માં કોઈ ફેરફારની જરૂર નથી. ભૂલ: {error}',
        'ssl_untested' => 'ચાલુ, પણ લાઈવ ટેસ્ટ વિનંતી પૂર્ણ કરી શકાઈ નહીં (કોઈ આઉટબાઉન્ડ નેટવર્ક નથી?), તેથી ચકાસણીની પુષ્ટિ થઈ શકી નથી. ભૂલ: {error}',
        'ssl_bundle_system' => 'સિસ્ટમ સ્ટોર',
        'help_link' => 'આને કેવી રીતે ઠીક કરવું — HTTPS પ્રમાણપત્રો માર્ગદર્શિકા →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'સેટ નથી',
        'ca_ini_missing' => '{path} (ફાઇલ ગુમ છે!)',
        'ca_ini_note_fix' => ' — path ઠીક કરો અથવા php.ini માં સેટિંગ કોમેન્ટ કરો.',
        'ca_ini_note_fallback' => ' — વૈકલ્પિક: FreeITSM તેની બંડલ કરેલી CA યાદી (Windows) અથવા OS ટ્રસ્ટ સ્ટોર (Linux) પર પાછું વળે છે. નોંધ: આ વેબ સર્વરના PHP ને પ્રતિબિંબિત કરે છે; બેકગ્રાઉન્ડ વર્કર અલગ CLI php.ini વાપરે છે.',
        'ssl_disabled' => 'નિષ્ક્રિય — પ્રોડક્શન માટે સક્ષમ કરો (config.php માં SSL_VERIFY_PEER ને true સેટ કરો)',
        'ssl_undefined' => 'SSL_VERIFY_PEER config.php માં વ્યાખ્યાયિત નથી',
        'display_errors_enabled' => 'સક્ષમ — પ્રોડક્શન માટે નિષ્ક્રિય કરો (config.php માં display_errors ને 0 સેટ કરો)',
        'display_errors_disabled' => 'નિષ્ક્રિય',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 અથવા તેથી વધુ જરૂરી છે',
        'php_version_eol' => '{version} — હજુ સપોર્ટેડ છે, પણ આ રિલીઝ એન્ડ ઓફ લાઈફ થયા પછી કોઈ સિક્યુરિટી અપડેટ મળ્યા નથી. PHP 8.3 અથવા 8.4 ભલામણ કરેલ છે.',
        'extension_loaded' => 'લોડ થયેલ',
        'extension_not_loaded' => 'લોડ થયેલ નથી — php.ini માં સક્ષમ કરો',
        'pdo_mysql_not_loaded' => 'લોડ થયેલ નથી — php.ini માં pdo_mysql સક્ષમ કરો',
        'db_config_not_found_masked' => 'config.php માં સેટ કરેલા path પર મળ્યું નથી',
        'ssl_verified_masked' => 'ચાલુ અને કાર્યરત — એક લાઈવ HTTPS વિનંતી પ્રમાણપત્ર-ચકાસાયેલ હતી',
        'ssl_broken_masked' => 'ચાલુ, પણ સર્વર પ્રમાણપત્ર ચકાસી શક્યું નહીં — આઉટબાઉન્ડ HTTPS (ઈમેલ, AI, વેબહુક્સ, સાઈન-ઈન) નિષ્ફળ જશે. ભૂલ જોવા માટે એડમિનિસ્ટ્રેટર તરીકે સાઈન ઈન કરો.',
        'ssl_untested_masked' => 'ચાલુ, પણ લાઈવ ટેસ્ટ વિનંતી પૂર્ણ કરી શકાઈ નહીં, તેથી ચકાસણીની પુષ્ટિ થઈ શકી નથી.',
        'db_error_masked' => 'કનેક્ટ કરી શકાયું નથી — સંપૂર્ણ ભૂલ જોવા માટે એડમિનિસ્ટ્રેટર તરીકે સાઈન ઈન કરો',
        'encryption_key_missing_masked' => 'મળ્યું નથી — સંવેદનશીલ સેટિંગ્સને એન્ક્રિપ્ટ કરવા માટે જરૂરી',
        'ca_ini_masked_ok' => 'ગોઠવેલ',
        'ca_ini_masked_broken' => 'સેટ છે, પણ એવી ફાઇલ તરફ નિર્દેશ કરે છે જે ત્યાં નથી — path ઠીક કરો અથવા php.ini માં સેટિંગ કોમેન્ટ કરો.',
        'php_version_ok_masked' => 'જરૂરિયાત પૂરી કરે છે',
        'php_version_too_low_masked' => 'ખૂબ જૂનું — PHP 8.1 અથવા તેથી વધુ જરૂરી છે',
        'php_version_eol_masked' => 'સપોર્ટેડ છે, પણ આ રિલીઝ એન્ડ ઓફ લાઈફ થઈ ગયું છે અને હવે સિક્યુરિટી અપડેટ મળતા નથી. વર્ઝન જોવા માટે એડમિનિસ્ટ્રેટર તરીકે સાઈન ઈન કરો.',
    ],
    'locked' => [
        'notice' => 'આ ઈન્સ્ટોલ પર સેટઅપ પૂર્ણ છે, તેથી path, કનેક્શન ભૂલો અને ક્રેડેન્શિયલ્સ છુપાવેલા છે. સંપૂર્ણ વિગત જોવા માટે એડમિનિસ્ટ્રેટર તરીકે સાઈન ઈન કરો.',
    ],
    'db_verify' => [
        'heading' => 'ડેટાબેઝ વેરિફાય',
        'intro' => 'ડેટાબેઝમાં ગુમ થયેલા ટેબલ કે કૉલમ તપાસો અને આપમેળે બનાવો.',
        'run' => 'ચલાવો',
    ],
    'login' => [
        'heading' => 'ડિફૉલ્ટ લોગિન',
        'intro' => 'તમે Database Verify ચલાવો ત્યારે એક ડિફૉલ્ટ એડમિન એકાઉન્ટ બનાવવામાં આવે છે.',
        'username' => 'યુઝરનેમ:',
        'password' => 'પાસવર્ડ:',
    ],
    'footer' => [
        'warning' => 'તમારી સિસ્ટમ પ્રોડક્શનમાં આવે પછી, સુરક્ષા માટે {folder} ફોલ્ડર કાઢી નાખો.',
        'signature' => 'FreeITSM સેટઅપ વેરિફિકેશન',
    ],
    'js' => [
        'running' => 'ચાલી રહ્યું છે...',
        'run' => 'ચલાવો',
        'tables_checked' => '{n} ટેબલ તપાસાયા:',
        'ok' => '{n} ઠીક છે',
        'created' => '{n} બનાવાયા',
        'updated' => '{n} અપડેટ થયા',
        'errors' => '{n} ભૂલો',
        'unknown_error' => 'અજ્ઞાત ભૂલ',
        'verify_failed' => 'DB verify ચલાવવામાં નિષ્ફળ: {error}',
    ],
];
