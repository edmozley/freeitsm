<?php
/**
 * Bahasa Indonesia (id) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Kalender',

    'nav' => [
        'calendar' => 'Kalender',
        'table'    => 'Tabel',
        'settings' => 'Pengaturan',
        'help'     => 'Bantuan',
    ],

    'sidebar' => [
        'new_event'   => 'Acara Baru',
        'categories'  => 'Kategori',
        'none'        => 'Tidak ada kategori ditemukan',
    ],

    'event' => [
        'modal_new'      => 'Acara Baru',
        'modal_edit'     => 'Ubah Acara',
        'title'          => 'Judul',
        'title_ph'       => 'Judul acara...',
        'category'       => 'Kategori',
        'category_none'  => '-- Pilih kategori --',
        'start_date'     => 'Tanggal Mulai',
        'start_time'     => 'Waktu Mulai',
        'end_date'       => 'Tanggal Selesai',
        'end_time'       => 'Waktu Selesai',
        'all_day'        => 'Acara sepanjang hari',
        'location'       => 'Lokasi',
        'location_ph'    => 'Lokasi (opsional)',
        'description'    => 'Deskripsi',
        'description_ph' => 'Deskripsi (opsional)',
        'delete'         => 'Hapus',
        'cancel'         => 'Batal',
        'save'           => 'Simpan',
        'edit'           => 'Ubah',
        'delete_confirm' => 'Apakah Anda yakin ingin menghapus acara ini?',
        'title_required' => 'Silakan masukkan judul acara',
        'start_required' => 'Silakan pilih tanggal mulai',
    ],

    'table' => [
        'start_required' => 'Tanggal/waktu mulai wajib diisi',
        'save_failed'    => 'Gagal menyimpan',
        'col_title'       => 'Judul',
        'col_category'    => 'Kategori',
        'col_start'       => 'Mulai',
        'col_end'         => 'Selesai',
        'col_all_day'     => 'Sepanjang hari',
        'col_location'    => 'Lokasi',
        'col_description' => 'Deskripsi',
        'col_created_by'  => 'Dibuat oleh',
        'col_created'     => 'Dibuat',
    ],

    'settings' => [
        'title'           => 'Pengaturan kalender',
        'tab_categories'  => 'Kategori',
        'heading'         => 'Kategori acara',
        'add'             => 'Tambah',
        'intro'           => 'Kelola kategori yang digunakan untuk mengatur acara kalender. Setiap kategori dapat memiliki warna khusus agar mudah dikenali.',
        'col_name'        => 'Nama',
        'col_description' => 'Deskripsi',
        'col_status'      => 'Status',
        'active'          => 'Aktif',
        'inactive'        => 'Tidak aktif',
        'edit'            => 'Ubah',
        'delete'          => 'Hapus',
        'empty'           => 'Belum ada kategori. Klik <strong>Tambah</strong> untuk membuatnya.',
        'load_error'      => 'Gagal memuat kategori',

        'modal_add'       => 'Tambah kategori',
        'modal_edit'      => 'Ubah kategori',
        'modal_name'      => 'Nama',
        'modal_name_ph'   => 'mis. Kedaluwarsa sertifikat',
        'modal_description'    => 'Deskripsi',
        'modal_description_ph' => 'Deskripsi opsional...',
        'modal_colour'    => 'Warna',
        'modal_active'    => 'Aktif',
        'cancel'          => 'Batal',
        'save'            => 'Simpan',
        'name_required'   => 'Silakan masukkan nama kategori',

        'delete_title'    => 'Hapus kategori',
        'delete_confirm'  => 'Apakah Anda yakin ingin menghapus "{name}"? Tindakan ini tidak dapat dibatalkan.',
        'delete_this'     => 'kategori ini',
    ],

    'toast' => [
        'saved'         => 'Tersimpan',
        'deleted'       => 'Terhapus',
        'save_failed'   => 'Gagal menyimpan',
        'delete_failed' => 'Gagal menghapus',
    ],

    'help' => [
        'page_title'  => 'Panduan Kalender',
        'guide'       => 'Panduan',
        'hero_title'  => 'Panduan kalender',
        'hero_sub'    => 'Lacak sertifikat, kontrak, jendela pemeliharaan, dan acara berulang &mdash; semuanya di satu tempat.',

        'nav_overview'  => 'Ikhtisar',
        'nav_views'     => 'Tampilan kalender',
        'nav_creating'  => 'Membuat acara',
        'nav_categories'=> 'Kategori acara',
        'nav_settings'  => 'Pengaturan',
        'nav_tips'      => 'Tips singkat',

        // Section 1 — Overview
        'overview_heading' => 'Ikhtisar',
        'overview_intro'   => 'Modul Kalender memberi tim TI Anda lini masa bersama untuk segala hal yang penting. Alih-alih mengandalkan spreadsheet atau pengingat pribadi, Anda dapat melacak tanggal kedaluwarsa sertifikat, perpanjangan kontrak, jendela pemeliharaan terjadwal, dan acara tim dalam satu kalender berkode warna yang dapat dilihat semua orang di service desk.',
        'feature_tracking_title' => 'Pelacakan acara',
        'feature_tracking_desc'  => 'Buat acara dengan judul, tanggal, waktu, lokasi, dan deskripsi. Setiap acara terlihat oleh tim sehingga tidak ada yang terlewatkan.',
        'feature_views_title'    => 'Beberapa tampilan',
        'feature_views_desc'     => 'Beralih antara tampilan bulan, minggu, dan hari untuk mendapatkan tingkat detail yang Anda butuhkan. Tampilan bulan menunjukkan ikhtisar; tampilan minggu dan hari menunjukkan slot waktu yang presisi.',
        'feature_categories_title' => 'Kategori',
        'feature_categories_desc'  => 'Atur acara ke dalam kategori berkode warna seperti sertifikat, kontrak, pemeliharaan, dan rapat. Saring kalender untuk menampilkan hanya yang Anda pedulikan.',
        'feature_scheduling_title' => 'Penjadwalan',
        'feature_scheduling_desc'  => 'Rencanakan jendela pemeliharaan, atur acara sepanjang hari untuk tenggat waktu, dan jadwalkan pekerjaan berulang. Kalender membantu tim Anda berkoordinasi dan menghindari konflik.',

        // Section 2 — Views
        'views_heading' => 'Tampilan kalender',
        'views_intro'   => 'Kalender menawarkan tiga tampilan sehingga Anda dapat memperbesar atau memperkecil tergantung kebutuhan Anda. Beralih di antaranya menggunakan tombol pengalih di sudut kanan atas header kalender.',
        'views_month_title' => 'Tampilan bulan',
        'views_month_desc'  => 'Tampilan default. Menampilkan kisi satu bulan penuh dengan acara ditampilkan sebagai bilah berwarna pada setiap hari. Ideal untuk mendapatkan ikhtisar tentang apa yang akan datang di seluruh tim.',
        'views_week_title'  => 'Tampilan minggu',
        'views_week_desc'   => 'Menampilkan tujuh hari dengan slot waktu per jam. Acara diposisikan sesuai waktu mulai dan selesainya, sehingga mudah untuk menemukan konflik penjadwalan.',
        'views_day_title'   => 'Tampilan hari',
        'views_day_desc'    => 'Berfokus pada satu hari dengan rincian per jam yang terperinci. Gunakan ini ketika Anda perlu melihat dengan tepat apa yang terjadi jam demi jam selama hari yang sibuk.',
        'views_nav'         => 'Gunakan panah navigasi di samping judul bulan/minggu/hari untuk bergerak maju dan mundur dalam waktu. Tombol <strong>Hari ini</strong> membawa Anda langsung kembali ke tanggal saat ini, sejauh apa pun Anda telah bernavigasi.',
        'views_flow_today'  => 'Tombol hari ini',
        'views_flow_nav'    => 'Navigasi sebelumnya/berikutnya',
        'views_flow_choose' => 'Pilih tampilan',
        'views_flow_click'  => 'Klik acara',
        'views_tip'         => 'Klik acara mana pun di kalender untuk membuka popup tampilan cepat yang menunjukkan judul, waktu, lokasi, dan deskripsi. Dari sana Anda dapat membuka formulir ubah lengkap.',

        // Section 3 — Creating events
        'creating_heading' => 'Membuat acara',
        'creating_intro'   => 'Menambahkan acara ke kalender itu mudah. Klik tombol <strong>+ Acara Baru</strong> di bilah samping untuk membuka formulir acara. Isi detailnya dan simpan &mdash; acara langsung muncul di kalender.',
        'creating_step1'   => '<strong>Klik + Acara Baru</strong> &mdash; tombolnya ada di bilah samping kalender di sebelah kiri. Ini membuka modal pembuatan acara.',
        'creating_step2'   => '<strong>Masukkan judul</strong> &mdash; beri acara nama yang jelas dan deskriptif. Misalnya: "Perpanjangan sertifikat SSL &mdash; webserver01" atau "Jendela patching bulanan".',
        'creating_step3'   => '<strong>Pilih kategori</strong> &mdash; pilih dari dropdown untuk memberi kode warna pada acara. Kategori dikonfigurasi di Pengaturan dan membantu Anda menyaring kalender nanti.',
        'creating_step4'   => '<strong>Atur tanggal dan waktu</strong> &mdash; pilih tanggal mulai dan secara opsional tanggal selesai. Tambahkan waktu mulai dan selesai untuk acara berwaktu, atau centang "Acara sepanjang hari" untuk tenggat waktu dan entri sehari penuh.',
        'creating_step5'   => '<strong>Tambahkan lokasi dan deskripsi</strong> &mdash; secara opsional tentukan di mana acara berlangsung dan tambahkan catatan. Detail ini ditampilkan di popup tampilan cepat ketika seseorang mengklik acara.',
        'creating_step6'   => '<strong>Simpan</strong> &mdash; klik Simpan dan acara dibuat. Acara langsung muncul di kalender, berkode warna menurut kategorinya.',
        'creating_tip'     => 'Untuk mengubah acara yang sudah ada, klik acara di kalender untuk membuka popup, lalu klik <strong>Ubah</strong>. Formulir yang sama terbuka dengan detail acara saat ini sudah terisi. Anda juga dapat menghapus acara dari formulir ubah.',

        // Section 4 — Categories
        'categories_heading' => 'Kategori acara',
        'categories_intro'   => 'Kategori adalah tulang punggung pengaturan kalender. Setiap kategori memiliki nama dan warna, sehingga acara langsung dikenali sekilas pandang. Bilah samping menampilkan semua kategori yang tersedia dengan kotak centang &mdash; hapus centang sebuah kategori untuk menyembunyikan acara tersebut dari kalender.',
        'categories_certificates' => '<strong>Sertifikat</strong> &mdash; lacak tanggal kedaluwarsa sertifikat SSL/TLS, sertifikat penandatanganan kode, dan kredensial lain yang perlu diperpanjang secara berkala',
        'categories_contracts'    => '<strong>Kontrak</strong> &mdash; catat tanggal perpanjangan kontrak vendor, kedaluwarsa lisensi, dan tonggak peninjauan SLA agar tidak ada yang kedaluwarsa tanpa terduga',
        'categories_maintenance'  => '<strong>Pemeliharaan</strong> &mdash; jadwalkan jendela pemeliharaan terencana untuk server, perangkat jaringan, dan infrastruktur. Tim dan pemangku kepentingan Anda dapat melihat dengan tepat kapan waktu henti diperkirakan terjadi',
        'categories_meetings'     => '<strong>Rapat</strong> &mdash; catat stand-up tim, rapat CAB, panggilan vendor, dan janji temu berulang lainnya yang relevan dengan operasi TI',
        'categories_custom'       => '<strong>Kategori khusus</strong> &mdash; tambahkan kategori Anda sendiri di Pengaturan agar sesuai dengan alur kerja tim Anda. Penambahan umum mencakup "Penerapan", "Audit", dan "Pelatihan"',
        'categories_filtering'    => 'Penyaringan diterapkan secara real time. Ketika Anda menghapus centang sebuah kategori di bilah samping, acara dalam kategori tersebut langsung disembunyikan tanpa memuat ulang halaman. Centang lagi untuk menampilkannya kembali.',
        'categories_tip'          => 'Kode warna berfungsi di ketiga tampilan. Pada tampilan bulan, acara muncul sebagai bilah berwarna. Pada tampilan minggu dan hari, acara ditampilkan sebagai blok berwarna yang diposisikan pada waktu yang tepat.',

        // Section 5 — Settings
        'settings_heading' => 'Pengaturan',
        'settings_intro'   => 'Halaman Pengaturan memungkinkan Anda mengonfigurasi cara kerja kalender untuk tim Anda. Akses dengan mengklik <strong>Pengaturan</strong> di bilah navigasi di bagian atas modul kalender.',
        'settings_step1'   => '<strong>Kelola kategori</strong> &mdash; tambahkan, ubah, atau hapus kategori acara. Setiap kategori memiliki nama dan warna. Perubahan langsung berlaku di seluruh kalender untuk semua pengguna.',
        'settings_step2'   => '<strong>Atur warna</strong> &mdash; pilih warna untuk setiap kategori menggunakan pemilih warna. Pilih warna yang berbeda agar acara mudah dibedakan pada kalender yang sibuk.',
        'settings_step3'   => '<strong>Ganti nama kategori</strong> &mdash; klik nama kategori untuk mengubahnya. Acara yang sudah ada yang ditetapkan ke kategori tersebut diperbarui secara otomatis.',
        'settings_step4'   => '<strong>Hapus kategori</strong> &mdash; hapus kategori yang tidak lagi Anda perlukan. Acara dalam kategori yang dihapus tidak ikut dihapus &mdash; acara tetap berada di kalender tanpa penetapan kategori.',
        'settings_tip'     => 'Jaga agar daftar kategori Anda tetap fokus. Memiliki terlalu banyak kategori dapat membuat bilah samping berantakan dan kode warna lebih sulit dibaca. Targetkan 5&ndash;10 kategori yang terdefinisi dengan baik yang memenuhi kebutuhan tim Anda.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Tips singkat',
        'tips_maintenance_title' => 'Jendela pemeliharaan',
        'tips_maintenance_desc'  => 'Buat acara sepanjang hari atau blok berwaktu untuk pemeliharaan terencana. Sertakan sistem yang terpengaruh dalam deskripsi agar analis dapat dengan cepat memeriksa apakah pemadaman diperkirakan terjadi.',
        'tips_certificates_title' => 'Perpanjangan sertifikat',
        'tips_certificates_desc'  => 'Tambahkan acara 30 hari sebelum setiap sertifikat kedaluwarsa. Ini memberi tim Anda cukup waktu untuk memperpanjang tanpa berisiko pemadaman akibat sertifikat yang kedaluwarsa.',
        'tips_contracts_title'   => 'Pelacakan kontrak',
        'tips_contracts_desc'    => 'Catat tanggal perpanjangan kontrak sebagai acara sepanjang hari. Tambahkan nama vendor dan nilai kontrak dalam deskripsi agar informasinya siap saat tiba waktunya untuk bernegosiasi.',
        'tips_filters_title'     => 'Gunakan filter kategori',
        'tips_filters_desc'      => 'Ketika kalender menjadi sibuk, hapus centang kategori yang tidak Anda perlukan. Misalnya, sembunyikan rapat ketika Anda hanya tertarik pada jendela pemeliharaan mendatang.',
    ],
];
