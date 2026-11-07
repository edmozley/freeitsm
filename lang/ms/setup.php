<?php
/**
 * FreeITSM — setup strings (ms).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php ms
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Persediaan FreeITSM',
    'heading' => 'Pengesahan Persediaan',
    'storage' => [
        'heading' => 'Berhenti — fail yang anda muat naik akan dipadam setiap kali anda mengemas kini',
        'explain' => 'FreeITSM sedang berjalan dalam Docker, dan sebahagian folder tempat ia menyimpan fail yang dimuat naik berada di dalam kontena berbanding pada volum Docker. Mengemas kini FreeITSM membina semula kontena, dan segala-galanya di dalamnya dibuang apabila itu berlaku. Pangkalan data anda berada pada volum dan akan terus wujud, jadi selepas itu lampiran anda masih akan disenaraikan sedangkan fail itu sendiri sudah tiada. Dua minit sekarang menghalang ini secara kekal.',
        'encryption_key' => 'Ini termasuk kunci penyulitan anda. Jika ia hilang, kata laluan peti mel dan kelayakan integrasi yang disimpan tidak rosak tetapi tidak akan dapat dibaca lagi, dan setiap satu perlu dimasukkan semula secara manual.',
        'existing_install' => 'Pemasangan ini sudah digunakan, jadi jangan sekadar menambah ini dan membina semula. Salin folder-folder itu keluar daripada kontena yang sedang berjalan dahulu — menambah volum tidak menyelamatkan fail yang sudah berada di dalamnya. Sistem → Alat Nyahpepijat → D013 memberikan anda arahan mengikut susunan yang betul.',
        'step1' => '1. Cipta satu fail baharu bernama docker-compose.override.yml, dalam folder yang sama dengan docker-compose.yml, mengandungi tepat ini. Jangan sunting docker-compose.yml itu sendiri — fail itu digantikan apabila anda mengemas kini, dan perubahan anda akan hilang atau akan menyekat kemas kini itu. FreeITSM membaca fail override secara automatik.',
        'step2' => '2. Gunakannya:',
        'foot' => 'Kemudian muat semula halaman ini — mesej ini akan hilang. Tiada apa-apa lagi perlu diubah, dan melakukannya sekarang tidak memerlukan apa-apa kos, kerana belum ada apa-apa disimpan dalam folder-folder ini lagi.',
        'foot_in_use' => 'Kemudian muat semula halaman ini — mesej ini akan hilang. Jangan langkau langkah menyalin keluar di atas: sudah ada fail dalam folder-folder ini, dan membina semula tanpa menyalinnya keluar dahulu akan memadamkannya buat selama-lamanya.',
    ],
    'summary' => [
        'passed' => '{n} lulus',
        'warning' => '{n} amaran',
        'warnings' => '{n} amaran',
        'failed' => '{n} gagal',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Sambungan pangkalan data',
        'encryption_key' => 'Kunci penyulitan',
        'ssl_verify' => 'Pengesahan sijil HTTPS',
        'ca_bundle_ini' => 'Bundel CA dalam php.ini',
        'display_errors' => 'Paparan ralat',
        'php_version' => 'Versi PHP',
        'php_extension' => 'Sambungan PHP: {ext}',
        'php_extension_optional' => 'Sambungan PHP: {ext} (pilihan)',
        'storage_persistence' => 'Kekekalan storan (Docker)',
    ],
    'detail' => [
        'found' => 'Dijumpai',
        'storage_persisted' => 'Setiap folder yang menyimpan fail yang dimuat naik berada pada storan yang terus wujud selepas pembinaan semula',
        'storage_at_risk' => 'TIDAK berada pada volum Docker dan dikosongkan setiap kali dibina semula: {dirs} — tambah volum untuk setiap satu ini sekarang, sebelum apa-apa disimpan di dalamnya',
        'storage_at_risk_masked' => '{n} folder yang menyimpan fail yang dimuat naik tidak akan terus wujud selepas kemas kini',
        'config_not_found' => 'Tidak dijumpai — salin config.php ke root aplikasi',
        'db_config_not_found' => 'Tidak dijumpai di: {path}',
        'db_config_path_unset' => 'Pemboleh ubah $db_config_path tidak ditetapkan dalam config.php',
        'db_connected' => 'Bersambung (pemacu: {driver})',
        'db_constants_undefined' => 'Pemalar pangkalan data tidak ditakrifkan — semak db_config.php',
        'encryption_key_missing' => 'Tidak dijumpai di: {path} — diperlukan untuk menyulitkan tetapan sensitif',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH tidak ditakrifkan dalam includes/encryption.php',
        'ssl_enabled' => 'Diaktifkan',
        'ssl_verified' => 'Aktif dan berfungsi — permintaan HTTPS langsung telah disahkan sijilnya (bundel CA: {bundle})',
        'ssl_broken' => 'Aktif, tetapi pelayan tidak dapat mengesahkan sijil — HTTPS keluar (e-mel, AI, webhook, log masuk) akan gagal. Penyelesaian paling mudah: letakkan fail cacert.pem dalam folder includes/ aplikasi (muat turun daripada https://curl.se/ca/cacert.pem) — tiada perubahan php.ini diperlukan. Ralat: {error}',
        'ssl_untested' => 'Aktif, tetapi permintaan ujian langsung tidak dapat diselesaikan (tiada rangkaian keluar?), jadi pengesahan tidak dapat disahkan. Ralat: {error}',
        'ssl_bundle_system' => 'stor sistem',
        'help_link' => 'Cara membetulkannya — panduan sijil HTTPS →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'tidak ditetapkan',
        'ca_ini_missing' => '{path} (fail tiada!)',
        'ca_ini_note_fix' => ' — betulkan laluan atau komenkan tetapan tersebut dalam php.ini.',
        'ca_ini_note_fallback' => ' — pilihan: FreeITSM kembali menggunakan senarai CA terbundelnya (Windows) atau stor amanah OS (Linux). Nota: ini mencerminkan PHP pelayan web; pekerja latar belakang menggunakan php.ini CLI yang berasingan.',
        'ssl_disabled' => 'Dinyahaktifkan — aktifkan untuk produksi (tetapkan SSL_VERIFY_PEER kepada true dalam config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER tidak ditakrifkan dalam config.php',
        'display_errors_enabled' => 'Diaktifkan — nyahaktifkan untuk produksi (tetapkan display_errors kepada 0 dalam config.php)',
        'display_errors_disabled' => 'Dinyahaktifkan',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — PHP 8.1 atau lebih tinggi diperlukan',
        'php_version_eol' => '{version} — masih disokong, tetapi keluaran ini tidak lagi menerima kemas kini keselamatan sejak mencapai tamat hayat. PHP 8.3 atau 8.4 disyorkan.',
        'extension_loaded' => 'Dimuatkan',
        'extension_not_loaded' => 'Tidak dimuatkan — aktifkan dalam php.ini',
        'pdo_mysql_not_loaded' => 'Tidak dimuatkan — aktifkan pdo_mysql dalam php.ini',
        'db_config_not_found_masked' => 'Tidak dijumpai pada laluan yang ditetapkan dalam config.php',
        'ssl_verified_masked' => 'Hidup dan berfungsi — permintaan HTTPS langsung telah disahkan sijil',
        'ssl_broken_masked' => 'Hidup, tetapi server tidak dapat mengesahkan sijil — HTTPS keluar (e-mel, AI, webhook, log masuk) akan gagal. Log masuk sebagai pentadbir untuk melihat ralat.',
        'ssl_untested_masked' => 'Hidup, tetapi permintaan ujian langsung tidak dapat diselesaikan, jadi pengesahan tidak dapat disahkan.',
        'db_error_masked' => 'Tidak dapat menyambung — log masuk sebagai pentadbir untuk melihat ralat penuh',
        'encryption_key_missing_masked' => 'Tidak dijumpai — diperlukan untuk menyulitkan tetapan sensitif',
        'ca_ini_masked_ok' => 'Dikonfigurasikan',
        'ca_ini_masked_broken' => 'Ditetapkan, tetapi menuju kepada fail yang tiada di situ — betulkan laluan atau komenkan tetapan itu dalam php.ini.',
        'php_version_ok_masked' => 'Memenuhi keperluan',
        'php_version_too_low_masked' => 'Terlalu lama — PHP 8.1 atau lebih tinggi diperlukan',
        'php_version_eol_masked' => 'Disokong, tetapi keluaran ini telah mencapai penghujung hayat dan tidak lagi menerima kemas kini keselamatan. Log masuk sebagai pentadbir untuk melihat versi.',
    ],
    'locked' => [
        'notice' => 'Persediaan telah selesai pada pemasangan ini, jadi laluan, ralat sambungan dan kelayakan disembunyikan. Log masuk sebagai pentadbir untuk melihat butiran penuh.',
    ],
    'db_verify' => [
        'heading' => 'Pengesahan Pangkalan Data',
        'intro' => 'Semak dan cipta secara automatik mana-mana jadual atau lajur yang hilang dalam pangkalan data.',
        'run' => 'Jalankan',
    ],
    'login' => [
        'heading' => 'Log Masuk Lalai',
        'intro' => 'Akaun admin lalai dicipta apabila anda menjalankan Pengesahan Pangkalan Data.',
        'username' => 'Nama pengguna:',
        'password' => 'Kata laluan:',
    ],
    'footer' => [
        'warning' => 'Setelah sistem anda berada dalam produksi, padamkan folder {folder} demi keselamatan.',
        'signature' => 'Pengesahan Persediaan FreeITSM',
    ],
    'js' => [
        'running' => 'Sedang berjalan...',
        'run' => 'Jalankan',
        'tables_checked' => '{n} jadual disemak:',
        'ok' => '{n} OK',
        'created' => '{n} dicipta',
        'updated' => '{n} dikemas kini',
        'errors' => '{n} ralat',
        'unknown_error' => 'Ralat tidak diketahui',
        'verify_failed' => 'Gagal menjalankan pengesahan pangkalan data: {error}',
    ],
];
