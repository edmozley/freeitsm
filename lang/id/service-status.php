<?php
/**
 * Bahasa Indonesia (id) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Status Layanan',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Pengaturan',
        'help'     => 'Bantuan',
    ],

    'board' => [
        'services'        => 'Layanan',
        'service_count'   => '{count} layanan',
        'loading'         => 'Memuat...',
        'no_services'     => 'Belum ada layanan yang dikonfigurasi. Buka Pengaturan untuk menambahkan layanan.',
        'incidents'       => 'Insiden',
        'new'             => 'Baru',
        'col_title'       => 'Judul',
        'col_status'      => 'Status',
        'col_affected'    => 'Layanan Terdampak',
        'col_updated'     => 'Diperbarui',
        'no_incidents'    => 'Tidak ada insiden untuk ditampilkan.',
        'none'            => 'Tidak ada',
    ],

    'modal' => [
        'new_incident'        => 'Insiden Baru',
        'edit_incident'       => 'Ubah Insiden',
        'title'               => 'Judul',
        'title_placeholder'   => 'Deskripsi singkat insiden',
        'status'              => 'Status',
        'comment'             => 'Komentar',
        'comment_placeholder' => 'Detail tentang insiden...',
        'affected_services'   => 'Layanan Terdampak',
        'add_service'         => '+ Tambah Layanan',
        'delete'              => 'Hapus',
        'cancel'              => 'Batal',
        'save'                => 'Simpan',
    ],

    'toast' => [
        'incident_saved'   => 'Insiden disimpan',
        'incident_deleted' => 'Insiden dihapus',
        'save_failed'      => 'Gagal menyimpan',
        'delete_failed'    => 'Gagal menghapus',
        'save_incident_failed'   => 'Gagal menyimpan insiden',
        'delete_incident_failed' => 'Gagal menghapus insiden',
        'saved'            => 'Disimpan',
        'deleted'          => 'Dihapus',
        'save_service_failed'    => 'Gagal menyimpan layanan',
        'delete_service_failed'  => 'Gagal menghapus layanan',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Hapus insiden',
        'delete_incident_message' => 'Hapus insiden ini?',
        'delete_title'            => 'Hapus',
        'delete_message'          => 'Hapus "{name}"?',
        'delete_label'            => 'Hapus',
    ],

    'settings' => [
        'tab_services'     => 'Layanan',
        'tab_statuses'     => 'Status',
        'tab_impacts'      => 'Tingkat dampak',

        'services_heading' => 'Layanan',
        'statuses_heading' => 'Status insiden',
        'impacts_heading'  => 'Tingkat dampak',
        'add'              => 'Tambah',
        'loading'          => 'Memuat...',
        'no_services'      => 'Belum ada layanan. Klik Tambah untuk membuat satu.',
        'no_items'         => 'Tidak ada item ditemukan',
        'load_failed'      => 'Gagal memuat data',
        'error_prefix'     => 'Kesalahan: {message}',

        'statuses_intro_html' => 'Status alur kerja untuk insiden layanan. Status yang ditandai sebagai <em>teratasi</em> menutup insiden — secara otomatis mencatat <code>resolved_datetime</code> dan menghapus insiden dari dasbor aktif. Tepat satu status menjadi default untuk insiden baru.',
        'impacts_intro_html'  => 'Tingkat keparahan yang ditampilkan sebagai lencana pada setiap kartu layanan. <strong>Urutan keparahan</strong> menentukan pengurutan "dampak terburuk saat ini" pada dasbor — lebih rendah = lebih buruk (1 = gangguan besar, 5 = operasional). Dua baris dapat berbagi urutan yang sama.',

        'col_name'        => 'Nama',
        'col_description' => 'Deskripsi',
        'col_order'       => 'Urutan',
        'col_status'      => 'Status',
        'col_actions'     => 'Tindakan',
        'col_colour'      => 'Warna',
        'col_resolved'    => 'Teratasi',
        'col_default'     => 'Default',
        'col_severity'    => 'Keparahan',

        'active'          => 'Aktif',
        'inactive'        => 'Nonaktif',
        'yes'             => 'Ya',
        'no'              => 'Tidak',
        'edit'            => 'Ubah',
        'delete'          => 'Hapus',

        'kind_status'     => 'status',
        'kind_impact'     => 'tingkat dampak',

        // Service modal
        'add_service'     => 'Tambah layanan',
        'edit_service'    => 'Ubah layanan',
        'field_name'      => 'Nama',
        'field_description' => 'Deskripsi',
        'field_order'     => 'Urutan tampilan',
        'field_active'    => 'Aktif',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Tambah item',
        'add_kind'        => 'Tambah {kind}',
        'edit_kind'       => 'Ubah {kind}',
        'field_colour'    => 'Warna',
        'field_resolved'  => 'Dihitung sebagai teratasi',
        'resolved_help_html' => 'Insiden dengan status ini secara otomatis mencatat <code>resolved_datetime</code> dan hilang dari dasbor aktif.',
        'field_severity'  => 'Urutan keparahan',
        'severity_help'   => '1 = terburuk (Gangguan Besar). Lebih tinggi = kurang parah.',
        'field_default'   => 'Default',

        'cancel'          => 'Batal',
        'save'            => 'Simpan',
    ],

    'help' => [
        'page_title' => 'Panduan Status Layanan',
        'guide'      => 'Panduan',

        'nav_overview'  => 'Ikhtisar',
        'nav_dashboard' => 'Dasbor status',
        'nav_services'  => 'Mengelola layanan',
        'nav_history'   => 'Riwayat insiden',
        'nav_settings'  => 'Pengaturan',
        'nav_tips'      => 'Tips cepat',

        'hero_title' => 'Panduan status layanan',
        'hero_sub'   => 'Pantau layanan TI Anda, komunikasikan insiden, dan beri tahu pemangku kepentingan secara real-time.',

        // Section 1: Overview
        'overview_heading' => 'Ikhtisar',
        'overview_intro'   => 'Modul Status Layanan memberi Anda tampilan terpusat atas kesehatan setiap layanan TI yang diandalkan organisasi Anda. Ketika sesuatu bermasalah, Anda dapat mencatat insiden, memperbarui layanan terdampak, dan terus memberi tahu pengguna sepanjang proses penyelesaian.',
        'feature_dashboard_title' => 'Dasbor status',
        'feature_dashboard_desc'  => 'Lihat kesehatan terkini setiap layanan sekilas. Lencana berkode warna menunjukkan apakah setiap layanan beroperasi, menurun, dalam pemeliharaan, atau mengalami gangguan.',
        'feature_incident_title'  => 'Pelacakan insiden',
        'feature_incident_desc'   => 'Catat insiden dengan judul, pembaruan status, dan komentar. Tautkan layanan terdampak ke setiap insiden agar semua orang tahu persis apa yang terdampak dan mengapa.',
        'feature_management_title' => 'Manajemen layanan',
        'feature_management_desc'  => 'Konfigurasikan katalog layanan Anda di pengaturan. Tambahkan layanan dengan nama, deskripsi, dan urutan tampilan. Aktifkan atau nonaktifkan layanan seiring berkembangnya infrastruktur Anda.',
        'feature_comms_title' => 'Komunikasi',
        'feature_comms_desc'  => 'Beri tahu pemangku kepentingan dengan pembaruan status secara real-time. Setiap insiden membawa status dan jejak komentar sehingga pengguna dapat mengikuti kemajuan penyelesaian tanpa harus mengejar service desk.',

        // Section 2: Dashboard
        'dashboard_heading' => 'Dasbor status',
        'dashboard_p1'      => 'Dasbor adalah hal pertama yang Anda lihat saat membuka modul Status Layanan. Dasbor menampilkan kisi kartu layanan, masing-masing menunjukkan nama layanan, deskripsi singkat, dan lencana dampak berkode warna yang mencerminkan status terburuknya saat ini. Di bawah kisi terdapat tabel insiden yang mencantumkan semua insiden terkini dan aktif.',
        'dashboard_p2_html' => 'Setiap kartu layanan secara otomatis mencerminkan tingkat dampak paling parah yang ditetapkan padanya dari insiden aktif (belum teratasi) mana pun. Ketika semua insiden yang memengaruhi layanan teratasi, layanan kembali ke <strong>Operasional</strong>.',
        'status_levels'     => 'Tingkat status',
        'level_operational_name' => 'Operasional',
        'level_operational_desc' => 'Layanan berjalan normal tanpa masalah yang diketahui. Ini adalah status default untuk semua layanan yang sehat.',
        'level_degraded_name'    => 'Kinerja Menurun',
        'level_degraded_desc'    => 'Layanan tersedia tetapi berjalan lebih lambat dari yang diharapkan atau dengan fungsionalitas berkurang. Pengguna mungkin merasakan keterlambatan.',
        'level_maintenance_name' => 'Dalam Pemeliharaan',
        'level_maintenance_desc' => 'Waktu henti atau jendela pemeliharaan terencana. Layanan mungkin tidak tersedia sementara selama pekerjaan dilaksanakan.',
        'level_outage_name'      => 'Gangguan Besar',
        'level_outage_desc'      => 'Layanan sama sekali tidak tersedia. Ini adalah status paling parah dan harus memicu investigasi segera.',
        'dashboard_tip'     => 'Tingkat dampak bersifat hierarkis. Jika sebuah layanan ditautkan ke beberapa insiden aktif, dasbor menampilkan dampak terburuk. Misalnya, satu insiden menandai layanan sebagai Menurun dan insiden lain menandainya sebagai Gangguan Besar akan menghasilkan Gangguan Besar yang ditampilkan.',

        // Section 3: Managing services
        'services_heading_html' => 'Mengelola layanan &amp; mencatat insiden',
        'services_intro'        => 'Layanan adalah blok penyusun halaman status Anda. Masing-masing mewakili layanan TI, sistem, atau komponen infrastruktur yang diandalkan pengguna Anda. Ketika sesuatu bermasalah, Anda membuat insiden dan menautkannya ke layanan terdampak.',
        'add_incident_heading'  => 'Menambahkan insiden baru',
        'add_incident_step1_html' => '<strong>Klik "Baru"</strong> pada dasbor untuk membuka formulir insiden.',
        'add_incident_step2_html' => '<strong>Masukkan judul</strong> &mdash; deskripsi masalah yang singkat dan jelas. Misalnya: "Keterlambatan pengiriman email" atau "Gateway VPN tidak dapat dijangkau".',
        'add_incident_step3_html' => '<strong>Atur status</strong> &mdash; pilih Menyelidiki, Teridentifikasi, Pihak Ketiga, Memantau, atau Teratasi. Mulai dengan Menyelidiki dan perbarui seiring Anda mengetahui lebih banyak.',
        'add_incident_step4_html' => '<strong>Tambahkan komentar</strong> &mdash; jelaskan apa yang diketahui sejauh ini, tindakan apa yang sedang diambil, dan solusi sementara apa pun yang tersedia bagi pengguna.',
        'add_incident_step5_html' => '<strong>Tautkan layanan terdampak</strong> &mdash; tambahkan satu atau beberapa layanan dan pilih tingkat dampak untuk masing-masing (Gangguan Besar, Gangguan Sebagian, Menurun, Pemeliharaan, Operasional, atau Tanpa Gangguan).',
        'add_incident_step6_html' => '<strong>Simpan</strong> &mdash; insiden muncul di tabel dan kartu layanan terdampak diperbarui segera pada dasbor.',
        'workflow_heading'  => 'Alur kerja status insiden',
        'workflow_investigating' => 'Menyelidiki',
        'workflow_identified'    => 'Teridentifikasi',
        'workflow_monitoring'    => 'Memantau',
        'workflow_resolved'      => 'Teratasi',
        'workflow_note_html'     => 'Gunakan <strong>Pihak Ketiga</strong> ketika akar penyebab berada pada vendor atau penyedia eksternal.',
        'services_tip'      => 'Anda dapat mengubah insiden mana pun dengan mengklik judulnya di tabel. Perbarui status, tambahkan komentar baru, atau ubah layanan terdampak seiring berkembangnya situasi. Menjaga insiden tetap terbarui adalah kunci komunikasi yang transparan.',

        // Section 4: Incident history
        'history_heading' => 'Riwayat insiden',
        'history_p1'      => 'Tabel insiden pada dasbor menampilkan insiden aktif maupun yang teratasi, memberi Anda lini masa lengkap kesehatan layanan. Setiap baris menampilkan judul insiden, status terkini, layanan terdampak beserta tingkat dampaknya, dan stempel waktu pembaruan terakhir.',
        'history_field_title_html'    => '<strong>Judul</strong> &mdash; tautan yang dapat diklik untuk membuka insiden agar dapat diubah. Gunakan judul yang jelas dan deskriptif agar riwayat mudah ditelusuri.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; lencana berkode warna yang menunjukkan fase investigasi saat ini (Menyelidiki, Teridentifikasi, Pihak Ketiga, Memantau, atau Teratasi).',
        'history_field_affected_html' => '<strong>Layanan terdampak</strong> &mdash; lencana bertanda yang menunjukkan setiap layanan tertaut beserta warna tingkat dampaknya. Sekilas Anda dapat melihat apa yang terdampak dan seberapa parah.',
        'history_field_updated_html'  => '<strong>Diperbarui</strong> &mdash; stempel waktu perubahan terbaru. Insiden yang teratasi ditata dengan teks redup sehingga insiden aktif menonjol secara visual.',
        'history_p2'      => 'Insiden yang teratasi tetap terlihat di tabel sebagai catatan riwayat. Ini memudahkan untuk mengenali masalah yang berulang, meninjau bagaimana insiden masa lalu ditangani, dan mengidentifikasi pola yang mungkin menunjukkan masalah mendasar.',
        'history_tip'     => 'Meninjau riwayat insiden Anda secara teratur membantu Anda mengidentifikasi layanan yang sering terganggu. Jika layanan yang sama muncul dalam beberapa insiden, mungkin sudah waktunya menyelidiki akar penyebabnya lebih dalam atau merencanakan peningkatan infrastruktur.',

        // Section 5: Settings
        'settings_heading' => 'Pengaturan',
        'settings_p1'      => 'Halaman Pengaturan adalah tempat Anda membangun dan memelihara katalog layanan Anda. Setiap layanan yang muncul pada dasbor status harus dikonfigurasi di sini terlebih dahulu.',
        'settings_step1_html' => '<strong>Tambahkan layanan</strong> &mdash; klik "Tambah" dan berikan nama (mis. "Email", "VPN", "Sistem ERP") serta deskripsi opsional yang menjelaskan fungsi layanan tersebut.',
        'settings_step2_html' => '<strong>Atur urutan tampilan</strong> &mdash; nomor urutan menentukan di mana layanan muncul pada kisi dasbor. Nomor yang lebih kecil muncul lebih dulu, jadi tempatkan layanan paling kritis Anda di bagian atas.',
        'settings_step3_html' => '<strong>Alihkan aktif/nonaktif</strong> &mdash; menonaktifkan layanan menghapusnya dari dasbor tanpa menghapusnya secara permanen. Ini berguna untuk layanan yang dihentikan atau sistem musiman.',
        'settings_step4_html' => '<strong>Ubah atau hapus</strong> &mdash; gunakan tombol tindakan pada setiap baris untuk memperbarui detail layanan atau menghapus layanan sepenuhnya. Mengubah selalu lebih disarankan daripada menghapus agar tautan insiden historis tetap utuh.',
        'settings_tip'     => 'Anggap katalog layanan Anda sebagai fondasi halaman status Anda. Luangkan waktu untuk menyusun nama dan deskripsi dengan tepat &mdash; inilah yang akan dilihat pengguna dan pemangku kepentingan Anda saat memeriksa kesehatan lingkungan TI Anda.',

        // Section 6: Quick tips
        'tips_heading' => 'Tips cepat',
        'tip_communicate_title' => 'Komunikasikan lebih awal',
        'tip_communicate_desc'  => 'Publikasikan insiden segera setelah Anda mengetahui ada yang bermasalah, meskipun Anda belum memiliki semua detailnya. Mengakui masalah dengan cepat membangun kepercayaan dengan pengguna Anda.',
        'tip_update_title' => 'Perbarui sesering mungkin',
        'tip_update_desc'  => 'Pembaruan status secara teratur &mdash; bahkan jika tidak ada yang berubah &mdash; menunjukkan kepada pengguna bahwa masalah sedang ditangani secara aktif. Keheningan menimbulkan frustrasi dan tiket dukungan.',
        'tip_review_title' => 'Tinjau pola',
        'tip_review_desc'  => 'Periksa riwayat insiden Anda secara teratur. Jika layanan yang sama terus muncul, itu mungkin menunjukkan masalah infrastruktur yang lebih dalam yang layak ditangani secara proaktif.',
        'tip_maintenance_title' => 'Rencanakan pemeliharaan',
        'tip_maintenance_desc'  => 'Gunakan tingkat dampak Pemeliharaan untuk pekerjaan terencana. Membuat insiden lebih awal memberi tahu pengguna tentang waktu henti terjadwal sebelum terjadi.',
    ],
];
