<?php
/**
 * FreeITSM — setup strings (id).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php id
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Penyiapan FreeITSM',
    'heading' => 'Verifikasi Penyiapan',
    'storage' => [
        'heading' => 'Berhenti — berkas yang Anda unggah akan dihapus setiap kali Anda memperbarui',
        'explain' => 'FreeITSM berjalan di Docker, dan beberapa folder tempat berkas unggahan disimpan berada di dalam container, bukan di volume Docker. Memperbarui FreeITSM membangun ulang container, dan semua yang ada di dalamnya dibuang saat itu terjadi. Database Anda berada di volume dan akan bertahan, sehingga setelahnya lampiran Anda masih terdaftar sementara berkasnya sudah hilang. Dua menit sekarang mencegah hal ini secara permanen.',
        'encryption_key' => 'Ini termasuk kunci enkripsi Anda. Jika hilang, kata sandi kotak surat dan kredensial integrasi yang tersimpan tidak rusak tetapi tidak akan pernah bisa dibaca lagi, dan setiap satunya harus dimasukkan secara manual.',
        'existing_install' => 'Instalasi ini sudah digunakan, jadi jangan sekadar menambahkan ini lalu membangun ulang. Salin folder dari container yang sedang berjalan terlebih dahulu — menambahkan volume tidak menyelamatkan berkas yang sudah ada di dalamnya. Sistem → Debug Tools → D013 memberi Anda perintah dalam urutan yang benar.',
        'step1' => '1. Buat berkas baru bernama docker-compose.override.yml, di folder yang sama dengan docker-compose.yml, berisi persis seperti ini. Jangan ubah docker-compose.yml itu sendiri — berkas itu diganti saat Anda memperbarui, dan perubahan Anda akan hilang atau menghalangi pembaruan. FreeITSM membaca berkas override secara otomatis.',
        'step2' => '2. Terapkan:',
        'foot' => 'Lalu muat ulang halaman ini — pesan ini akan hilang. Tidak ada lagi yang perlu diubah, dan melakukannya sekarang tidak membuat Anda rugi apa pun, karena belum ada yang tersimpan di folder ini.',
        'foot_in_use' => 'Lalu muat ulang halaman ini — pesan ini akan hilang. Jangan lewati langkah menyalin keluar di atas: sudah ada berkas di folder ini, dan membangun ulang tanpa menyalinnya keluar terlebih dahulu akan menghapusnya selamanya.',
    ],
    'summary' => [
        'passed' => '{n} lulus',
        'warning' => '{n} peringatan',
        'warnings' => '{n} peringatan',
        'failed' => '{n} gagal',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Koneksi database',
        'encryption_key' => 'Kunci enkripsi',
        'ssl_verify' => 'Verifikasi sertifikat HTTPS',
        'ca_bundle_ini' => 'Bundel CA di php.ini',
        'display_errors' => 'Tampilkan error',
        'php_version' => 'Versi PHP',
        'php_extension' => 'Ekstensi PHP: {ext}',
        'php_extension_optional' => 'Ekstensi PHP: {ext} (opsional)',
        'storage_persistence' => 'Persistensi penyimpanan (Docker)',
    ],
    'detail' => [
        'found' => 'Ditemukan',
        'storage_persisted' => 'Setiap folder yang menyimpan berkas unggahan berada di penyimpanan yang bertahan setelah pembangunan ulang',
        'storage_at_risk' => 'TIDAK di volume Docker dan dikosongkan setiap pembangunan ulang: {dirs} — tambahkan volume untuk masing-masing sekarang, sebelum ada yang disimpan di dalamnya',
        'storage_at_risk_masked' => '{n} folder yang menyimpan berkas unggahan tidak akan bertahan setelah pembaruan',
        'config_not_found' => 'Tidak ditemukan — salin config.php ke root aplikasi',
        'db_config_not_found' => 'Tidak ditemukan di: {path}',
        'db_config_path_unset' => 'Variabel $db_config_path belum diatur di config.php',
        'db_connected' => 'Terhubung (driver: {driver})',
        'db_constants_undefined' => 'Konstanta database belum didefinisikan — periksa db_config.php',
        'encryption_key_missing' => 'Tidak ditemukan di: {path} — diperlukan untuk mengenkripsi pengaturan sensitif',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH belum didefinisikan di includes/encryption.php',
        'ssl_enabled' => 'Aktif',
        'ssl_verified' => 'Aktif dan berfungsi — permintaan HTTPS langsung telah diverifikasi sertifikatnya (bundel CA: {bundle})',
        'ssl_broken' => 'Aktif, tetapi server tidak dapat memverifikasi sertifikat — HTTPS keluar (email, AI, webhook, masuk) akan gagal. Perbaikan paling mudah: letakkan berkas cacert.pem di folder includes/ aplikasi (unduh dari https://curl.se/ca/cacert.pem) — tidak perlu mengubah php.ini. Error: {error}',
        'ssl_untested' => 'Aktif, tetapi permintaan uji langsung tidak dapat diselesaikan (tidak ada jaringan keluar?), sehingga verifikasi tidak dapat dipastikan. Error: {error}',
        'ssl_bundle_system' => 'penyimpanan sistem',
        'help_link' => 'Cara memperbaikinya — panduan sertifikat HTTPS →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'belum diatur',
        'ca_ini_missing' => '{path} (berkas tidak ada!)',
        'ca_ini_note_fix' => ' — perbaiki jalur atau jadikan komentar pengaturan itu di php.ini.',
        'ca_ini_note_fallback' => ' — opsional: FreeITSM kembali ke daftar CA bawaannya (Windows) atau penyimpanan kepercayaan OS (Linux). Catatan: ini mencerminkan PHP web server; worker latar belakang memakai php.ini CLI terpisah.',
        'ssl_disabled' => 'Nonaktif — aktifkan untuk produksi (atur SSL_VERIFY_PEER menjadi true di config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER belum didefinisikan di config.php',
        'display_errors_enabled' => 'Aktif — nonaktifkan untuk produksi (atur display_errors menjadi 0 di config.php)',
        'display_errors_disabled' => 'Nonaktif',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — diperlukan PHP 8.1 atau lebih tinggi',
        'php_version_eol' => '{version} — masih didukung, tetapi rilis ini tidak menerima pembaruan keamanan sejak mencapai akhir masa pakai. Disarankan PHP 8.3 atau 8.4.',
        'extension_loaded' => 'Dimuat',
        'extension_not_loaded' => 'Tidak dimuat — aktifkan di php.ini',
        'pdo_mysql_not_loaded' => 'Tidak dimuat — aktifkan pdo_mysql di php.ini',
        'db_config_not_found_masked' => 'Tidak ditemukan di jalur yang diatur di config.php',
        'ssl_verified_masked' => 'Aktif dan berfungsi — permintaan HTTPS langsung telah diverifikasi sertifikatnya',
        'ssl_broken_masked' => 'Aktif, tetapi server tidak dapat memverifikasi sertifikat — HTTPS keluar (email, AI, webhook, masuk) akan gagal. Masuk sebagai administrator untuk melihat errornya.',
        'ssl_untested_masked' => 'Aktif, tetapi permintaan uji langsung tidak dapat diselesaikan, sehingga verifikasi tidak dapat dipastikan.',
        'db_error_masked' => 'Tidak dapat terhubung — masuk sebagai administrator untuk melihat error lengkapnya',
        'encryption_key_missing_masked' => 'Tidak ditemukan — diperlukan untuk mengenkripsi pengaturan sensitif',
        'ca_ini_masked_ok' => 'Terkonfigurasi',
        'ca_ini_masked_broken' => 'Diatur, tetapi menunjuk ke berkas yang tidak ada — perbaiki jalur atau jadikan komentar pengaturan itu di php.ini.',
        'php_version_ok_masked' => 'Memenuhi persyaratan',
        'php_version_too_low_masked' => 'Terlalu lama — diperlukan PHP 8.1 atau lebih tinggi',
        'php_version_eol_masked' => 'Didukung, tetapi rilis ini telah mencapai akhir masa pakai dan tidak lagi menerima pembaruan keamanan. Masuk sebagai administrator untuk melihat versinya.',
    ],
    'locked' => [
        'notice' => 'Penyiapan telah selesai pada instalasi ini, sehingga jalur, error koneksi, dan kredensial disembunyikan. Masuk sebagai administrator untuk melihat detail lengkap.',
    ],
    'db_verify' => [
        'heading' => 'Verifikasi Database',
        'intro' => 'Periksa dan buat otomatis tabel atau kolom yang hilang di database.',
        'run' => 'Jalankan',
    ],
    'login' => [
        'heading' => 'Login Default',
        'intro' => 'Akun admin default dibuat saat Anda menjalankan Verifikasi Database.',
        'username' => 'Nama pengguna:',
        'password' => 'Kata sandi:',
    ],
    'footer' => [
        'warning' => 'Setelah sistem Anda berada di produksi, hapus folder {folder} demi keamanan.',
        'signature' => 'Verifikasi Penyiapan FreeITSM',
    ],
    'js' => [
        'running' => 'Menjalankan...',
        'run' => 'Jalankan',
        'tables_checked' => '{n} tabel diperiksa:',
        'ok' => '{n} OK',
        'created' => '{n} dibuat',
        'updated' => '{n} diperbarui',
        'errors' => '{n} error',
        'unknown_error' => 'Error tidak diketahui',
        'verify_failed' => 'Gagal menjalankan verifikasi DB: {error}',
    ],
];
