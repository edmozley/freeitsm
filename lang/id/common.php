<?php
/**
 * Bahasa Indonesia (id) — Common shared UI strings.
 *
 * Mirrors lang/en/common.php structure exactly. Only values change.
 */
return [
    // Buttons
    'save'         => 'Simpan',
    'cancel'       => 'Batal',
    'delete'       => 'Hapus',
    'add'          => 'Tambah',
    'edit'         => 'Edit',
    'close'        => 'Tutup',
    'copy'         => 'Salin',
    'copied'       => 'Tersalin',
    'retry'        => 'Coba lagi',
    'export'       => 'Ekspor',
    'open'         => 'Buka',
    'apply'        => 'Terapkan',

    // Confirm / state
    'yes'          => 'Ya',
    'no'           => 'Tidak',
    'ok'           => 'OK',
    'loading'      => 'Memuat...',
    'saving'       => 'Menyimpan...',
    'saved'        => 'Tersimpan',
    'unsaved'      => 'Belum disimpan',
    'unsaved_changes' => 'Perubahan belum disimpan',
    'failed'       => 'Gagal',

    // Time / units (often inlined)
    'just_now'     => 'baru saja',
    'today'        => 'Hari ini',
    'yesterday'    => 'Kemarin',

    // Form helpers
    'required'     => 'Wajib',
    'optional'     => 'Opsional',
    'select_one'   => 'Pilih…',
    'search'       => 'Cari',

    // Errors
    'error_generic'        => 'Terjadi kesalahan.',
    'error_network'        => 'Kesalahan jaringan',
    'error_not_logged_in'  => 'Anda harus login terlebih dahulu.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Service Desk',
        'browser_title'    => 'Service Desk - ITSM',
        'welcome_heading'  => 'Apa yang ingin Anda lakukan?',
        'welcome_subtitle' => 'Pilih modul untuk memulai',
        'footer'           => 'Service Desk ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Modul ITSM',
    ],

    // Per-module display name + one-line description.
    // Used by the home cards (name + description tooltip) and the waffle panel (name only).
    'modules' => [
        'watchtower'     => ['name' => 'Watchtower',  'description' => 'Dasbor perhatian terpadu di semua modul'],
        'tickets'        => ['name' => 'Tiket',       'description' => 'Kelola permintaan dukungan, email, dan masalah pengguna'],
        'assets'         => ['name' => 'Aset',        'description' => 'Lacak aset IT dan penugasan pengguna'],
        'knowledge'      => ['name' => 'Pengetahuan', 'description' => 'Buat dan jelajahi artikel basis pengetahuan'],
        'changes'        => ['name' => 'Perubahan',   'description' => 'Rencanakan, lacak, dan kelola perubahan IT'],
        'calendar'       => ['name' => 'Kalender',    'description' => 'Lacak acara, tenggat waktu, dan jadwal'],
        'morning-checks' => ['name' => 'Pemeriksaan', 'description' => 'Catat pemeriksaan infrastruktur harian'],
        'reporting'      => ['name' => 'Laporan',     'description' => 'Lihat log sistem dan analitik'],
        'software'       => ['name' => 'Software',    'description' => 'Jelajahi inventaris software dan lisensi'],
        'forms'          => ['name' => 'Formulir',    'description' => 'Rancang formulir kustom dan lihat pengiriman'],
        'contracts'      => ['name' => 'Kontrak',     'description' => 'Kelola pemasok, kontak, dan kontrak'],
        'service-status' => ['name' => 'Status',      'description' => 'Pantau kesehatan layanan dan lacak insiden'],
        'wiki'           => ['name' => 'Wiki',        'description' => 'Jelajahi dokumentasi basis kode yang dibuat otomatis'],
        'lms'            => ['name' => 'LMS',         'description' => 'Learning Management System dengan pemutar kursus SCORM'],
        'process-mapper' => ['name' => 'Proses',      'description' => 'Alat bagan alir visual dan pemetaan proses'],
        'tasks'          => ['name' => 'Tugas',       'description' => 'Papan Kanban dan tampilan daftar untuk melacak tugas'],
        'cmdb'           => ['name' => 'CMDB',        'description' => 'Configuration Management Database'],
        'network-mapper' => ['name' => 'Jaringan',    'description' => 'Rancang dan dokumentasikan diagram jaringan'],
        'system'         => ['name' => 'Sistem',      'description' => 'Administrasi dan konfigurasi sistem'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Periksa email baru',
        'change_password' => 'Ubah Kata Sandi',
        'mfa'             => 'Multi-Factor Auth',
        'trusted_device'  => 'Perangkat Tepercaya',
        'logout'          => 'Logout',
        'logout_confirm'  => 'Apakah Anda yakin ingin logout?',
        'badge_off'       => 'Nonaktif',
        'badge_on'        => 'Aktif',
    ],

    // Change-password modal (static labels — dynamic JS toasts stay English for now)
    'password_modal' => [
        'title'            => 'Ubah Kata Sandi',
        'current_password' => 'Kata Sandi Saat Ini',
        'new_password'     => 'Kata Sandi Baru',
        'confirm_password' => 'Konfirmasi Kata Sandi Baru',
        'submit'           => 'Ubah Kata Sandi',
    ],

    // MFA modal (just the static title — the dynamic content is JS-rendered)
    'mfa_modal' => [
        'title' => 'Multi-Factor Authentication',
    ],

    // Calendar primitives — months, weekdays, navigation. Shared across any module
    // that renders a calendar (tickets/calendar.php today; top-level calendar/ next).
    'calendar' => [
        'previous' => 'Sebelumnya',
        'next'     => 'Berikutnya',
        'today'    => 'Hari ini',

        'months' => [
            'january'   => 'Januari',
            'february'  => 'Februari',
            'march'     => 'Maret',
            'april'     => 'April',
            'may'       => 'Mei',
            'june'      => 'Juni',
            'july'      => 'Juli',
            'august'    => 'Agustus',
            'september' => 'September',
            'october'   => 'Oktober',
            'november'  => 'November',
            'december'  => 'Desember',
        ],

        'weekdays' => [
            'monday'    => 'Senin',
            'tuesday'   => 'Selasa',
            'wednesday' => 'Rabu',
            'thursday'  => 'Kamis',
            'friday'    => 'Jumat',
            'saturday'  => 'Sabtu',
            'sunday'    => 'Minggu',
        ],
    ],
];
