<?php
/**
 * Bahasa Indonesia (id) — Tickets module strings.
 *
 * Mirrors lang/en/tickets.php structure exactly. Only values change.
 */
return [
    'title' => 'Tiket',

    'nav' => [
        'inbox'     => 'Kotak Masuk',
        'dashboard' => 'Dasbor',
        'users'     => 'Pengguna',
        'calendar'  => 'Kalender',
        'rota'      => 'Jadwal',
        'settings'  => 'Pengaturan',
        'help'      => 'Bantuan',
    ],

    'folders' => [
        'title'            => 'Folder',
        'group_label'      => 'Kelompokkan folder berdasarkan',
        'group_department' => 'Departemen',
        'group_analyst'    => 'Analis',
    ],

    'list' => [
        'all_tickets'     => 'Semua Tiket',
        'new_ticket_btn'  => 'Tiket baru',
        'search_btn'      => 'Cari tiket',
        'refresh_btn'     => 'Segarkan',
        'select_folder'   => 'Pilih folder untuk melihat tiket',
    ],

    'reading_pane' => [
        'select_ticket' => 'Pilih tiket untuk melihat detail',
    ],

    'note_modal' => [
        'title'       => 'Tambah Catatan',
        'note_label'  => 'Catatan',
        'placeholder' => 'Masukkan catatan Anda di sini...',
        'save_btn'    => 'Simpan Catatan',
    ],

    'reply_modal' => [
        'to'             => 'Kepada',
        'cc'             => 'Cc',
        'to_placeholder' => 'penerima@example.com',
        'cc_placeholder' => 'cc@example.com (pisahkan beberapa dengan titik koma)',
        'message'        => 'Pesan',
        'attachments'    => 'Lampiran',
        'drop_files'     => 'Seret file ke sini atau',
        'browse'         => 'telusuri',
        'cleaned_up'     => 'Telah dibersihkan',
        'undo'           => 'Urungkan',
        'cleanup'        => 'Bersihkan',
        'send'           => 'Kirim',
    ],

    'new_ticket_modal' => [
        'title'                   => 'Buat Tiket Baru',
        'requester_name'          => 'Nama Pemohon',
        'requester_email'         => 'Email Pemohon',
        'subject'                 => 'Subjek',
        'department'              => 'Departemen',
        'type'                    => 'Tipe',
        'priority'                => 'Prioritas',
        'description'             => 'Deskripsi',
        'select_placeholder'      => '-- Pilih --',
        'name_placeholder'        => 'misal, Budi Santoso',
        'email_placeholder'       => 'misal, budi.santoso@perusahaan.com',
        'subject_placeholder'     => 'Deskripsi singkat masalah',
        'description_placeholder' => 'Deskripsi terperinci tentang masalah...',
        'create_btn'              => 'Buat Tiket',
        'priority_normal'         => 'Normal',
        'priority_low'            => 'Rendah',
        'priority_high'           => 'Tinggi',
    ],

    'search_modal' => [
        'title'             => 'Cari Tiket',
        'ticket_number'     => 'Nomor Tiket',
        'email_address'     => 'Alamat Email',
        'subject'           => 'Subjek',
        'ticket_number_ph'  => 'misal, TDB-914-96769',
        'email_ph'          => 'misal, user@example.com',
        'subject_ph'        => 'Cari di subjek...',
        'search_btn'        => 'Cari',
        'clear_btn'         => 'Bersihkan',
        'empty_state'       => 'Masukkan kriteria pencarian di atas',
    ],

    'schedule_modal' => [
        'title'                => 'Jadwalkan Pekerjaan',
        'date'                 => 'Tanggal',
        'start_time'           => 'Waktu Mulai',
        'currently_scheduled'  => 'Sedang dijadwalkan:',
        'clear_schedule'       => 'Hapus jadwal',
    ],

    'ai_chat' => [
        'title'       => 'Tanya AI',
        'welcome'     => 'Ajukan pertanyaan tentang tiket ini dan AI akan mencari artikel yang relevan di basis pengetahuan.',
        'placeholder' => 'Ajukan pertanyaan...',
    ],

    // Action buttons in the reading-pane action toolbar (above the email body).
    'actions' => [
        'add_note'             => 'Tambah Catatan',
        'reply'                => 'Balas',
        'forward'              => 'Teruskan',
        'schedule'             => 'Jadwalkan',
        'ask_ai'               => 'Tanya AI',
        'audit'                => 'Riwayat',
        'delete'               => 'Hapus',
        'loading_attachments'  => 'Memuat lampiran...',
    ],

    // CMDB-linked-objects section in the reading pane (below the email body).
    'cmdb' => [
        'section_title'      => 'Objek CMDB Terpengaruh',
        'link_btn'           => '+ Tautkan objek',
        'empty'              => 'Belum ada objek CMDB yang ditautkan.',
        'search_placeholder' => 'Ketik untuk mencari objek CMDB apa pun…',
        'no_matches'         => 'Tidak ada yang cocok.',
        'unlink_title'       => 'Lepas tautan',
        'unlink_confirm'     => 'Lepas tautan objek CMDB ini dari tiket?',
        'unlinked_toast'     => 'Tautan dilepas',
        'linked_toast'       => '{name} ditautkan',
        'already_linked'     => '{name} sudah ditautkan',
    ],

    // Time-tracking section in the reading pane.
    'time_entries' => [
        'section_title'        => 'Entri Waktu',
        'total_prefix'         => 'Total {amount}',
        'minutes_placeholder'  => 'Menit',
        'notes_placeholder'    => 'Apa yang Anda lakukan? (opsional)',
        'add_btn'              => 'Tambah',
        'empty'                => 'Belum ada waktu yang dicatat.',
        'delete_title'         => 'Hapus entri',
        'delete_confirm'       => 'Hapus entri waktu ini?',
        'minutes_required'     => 'Masukkan jumlah menit yang dihabiskan.',
        'save_failed'          => 'Gagal menyimpan entri waktu: {error}',
        'delete_failed'        => 'Gagal menghapus entri waktu: {error}',
    ],

    // tickets/settings/index.php — admin settings page (tabs + section headings)
    'settings' => [
        'page_title' => 'Service Desk - Pengaturan',
        // Tab labels along the top of the page
        'tabs' => [
            'departments'     => 'Departemen',
            'teams'           => 'Tim',
            'ticket_types'    => 'Tipe Tiket',
            'ticket_origins'  => 'Asal Tiket',
            'statuses'        => 'Status',
            'priorities'      => 'Prioritas',
            'rota_locations'  => 'Lokasi Jadwal',
            'mailboxes'       => 'Kotak Surat',
            'email_templates' => 'Template',
            'rota'            => 'Jadwal',
            'analysts'        => 'Analis',
            'general'         => 'Umum',
            'reply_cleanup'   => 'Pembersihan Balasan',
        ],
        // Section h2 headings inside each tab. Most mirror the tab labels but
        // some are more descriptive — kept separate so translators can pick
        // different phrasings where natural.
        'headings' => [
            'departments'      => 'Departemen',
            'teams'            => 'Tim',
            'ticket_types'     => 'Tipe Tiket',
            'ticket_origins'   => 'Asal Tiket',
            'statuses'         => 'Status',
            'priorities'       => 'Prioritas',
            'rota_locations'   => 'Lokasi Jadwal',
            'mailboxes'        => 'Kotak Surat',
            'email_templates'  => 'Template Email',
            'rota_shifts'      => 'Shift Jadwal',
            'rota_settings'    => 'Pengaturan Jadwal',
            'analysts'         => 'Analis',
            'general_settings' => 'Pengaturan Umum',
            'reply_cleanup_ai' => 'AI Pembersihan Balasan',
        ],
        // Shared table column headers across the settings tabs. Most tabs
        // share Name / Description / Order / Status / Actions; the rest are
        // tab-specific.
        'columns' => [
            'name'         => 'Nama',
            'description'  => 'Deskripsi',
            'teams'        => 'Tim',
            'departments'  => 'Departemen',
            'analysts'     => 'Analis',
            'order'        => 'Urutan',
            'status'       => 'Status',
            'actions'      => 'Tindakan',
            'colour'       => 'Warna',
            'closed'       => 'Ditutup',
            'default'      => 'Default',
            'mailbox'      => 'Kotak Surat',
            'last_checked' => 'Terakhir Diperiksa',
            'event'        => 'Peristiwa',
            'subject'      => 'Subjek',
            'start'        => 'Mulai',
            'end'          => 'Selesai',
            'username'     => 'Nama Pengguna',
            'full_name'    => 'Nama Lengkap',
            'email'        => 'Email',
            'last_login'   => 'Login Terakhir',
            'date_time'    => 'Tanggal/Waktu',
            'from'         => 'Dari',
            'action'       => 'Tindakan',
            'reason'       => 'Alasan',
        ],
        // Tooltips on the icon buttons in the Actions column. Edit/Delete
        // reuse the existing common.edit / common.delete keys.
        'tooltips' => [
            'assign_teams'   => 'Tetapkan Tim',
            'activity'       => 'Aktivitas',
            'check_emails'   => 'Periksa Email',
            'logout'         => 'Logout',
            'authenticate'   => 'Autentikasi',
            'reset_password' => 'Atur Ulang Kata Sandi',
        ],
        // Buttons specific to this settings page. Generic Save / Cancel /
        // Add / Close / Delete reuse the existing common.* keys. Pagination
        // Prev/Next reuse common.calendar.previous / common.calendar.next.
        'buttons' => [
            'logs'            => 'Log',
            'check_all'       => 'Periksa Semua',
            'test_connection' => 'Uji koneksi',
            'verify'          => 'Verifikasi',
        ],
        // Add/Edit modal contents across the settings tabs. Each modal has its
        // own sub-namespace below. Convention for technical labels: product
        // names + protocols + standards (Azure, OAuth, IMAP, Microsoft, Google,
        // SMTP) stay in Latin script in every locale; generic surrounding
        // words (ID, Port, Scopes, Secret, URI, Server) translate to the
        // locale's natural equivalent.
        'modals' => [
            // Shared lookup modal — department / team / type / origin / status / priority / rota-location
            'lookup' => [
                'add' => [
                    'department'    => 'Tambah Departemen',
                    'team'          => 'Tambah Tim',
                    'ticket_type'   => 'Tambah Tipe Tiket',
                    'ticket_origin' => 'Tambah Asal Tiket',
                    'status'        => 'Tambah Status',
                    'priority'      => 'Tambah Prioritas',
                    'rota_location' => 'Tambah Lokasi Jadwal',
                    'fallback'      => 'Tambah Item',
                ],
                'edit' => [
                    'department'    => 'Edit Departemen',
                    'team'          => 'Edit Tim',
                    'ticket_type'   => 'Edit Tipe Tiket',
                    'ticket_origin' => 'Edit Asal Tiket',
                    'status'        => 'Edit Status',
                    'priority'      => 'Edit Prioritas',
                    'rota_location' => 'Edit Lokasi Jadwal',
                    'fallback'      => 'Edit Item',
                ],
                'colour_help'         => 'Digunakan untuk lencana di daftar, dasbor, dan laporan.',
                'closed_label'        => 'Dihitung sebagai ditutup',
                'closed_help'         => 'Tiket dengan status ini diperlakukan sebagai terselesaikan/final — dikecualikan dari hitungan antrean terbuka dan memicu stempel tanggal-waktu penutupan.',
                'default_label'       => 'Default untuk tiket baru',
                'default_help'        => 'Hanya satu baris yang dapat menjadi default — mengaktifkan ini akan menghapus tanda pada yang lain.',
                'display_order_label' => 'Urutan Tampilan',
                'active_label'        => 'Aktif',
            ],

            // Mailbox modal — the big one with Microsoft/Google/OAuth/IMAP fields
            'mailbox' => [
                'add_title'                   => 'Tambah Kotak Surat',
                'edit_title'                  => 'Edit Kotak Surat',
                'empty_state'                 => 'Belum ada kotak surat yang dikonfigurasi. Klik "Tambah Kotak Surat" untuk memulai.',
                'provider'                    => 'Penyedia',
                'provider_microsoft'          => 'Microsoft 365 (Exchange / Graph API)',
                'provider_google'             => 'Google Workspace (Gmail API)',
                'display_name'                => 'Nama Tampilan',
                'display_name_placeholder'    => 'misal, Service Desk',
                'target_mailbox'              => 'Kotak Surat Tujuan',
                'target_mailbox_placeholder'  => 'misal, servicedesk@perusahaan.com',
                'azure_tenant_id'             => 'Azure Tenant ID',
                'client_id'                   => 'Client ID',
                'client_secret'               => 'Client Secret',
                'client_secret_placeholder'   => 'Kosongkan untuk mempertahankan yang ada (saat mengedit)',
                'client_secret_help'          => 'Wajib untuk kotak surat baru. Kosongkan saat mengedit untuk mempertahankan secret yang ada.',
                'oauth_redirect_uri'          => 'OAuth Redirect URI',
                'oauth_scopes'                => 'Cakupan OAuth',
                'imap_server'                 => 'Server IMAP',
                'imap_port'                   => 'Port IMAP',
                'email_folder'                => 'Folder Email',
                'max_emails_per_check'        => 'Email Maksimum per Pemeriksaan',
                'rejected_emails'             => 'Email yang Ditolak',
                'rejected_delete'             => 'Hapus permanen',
                'rejected_move_to_deleted'    => 'Pindahkan ke Item Terhapus',
                'rejected_mark_read'          => 'Tandai sebagai sudah dibaca',
                'imported_emails'             => 'Email yang Diimpor',
                'imported_delete'             => 'Hapus permanen',
                'imported_move_to_folder'     => 'Pindahkan ke folder',
                'move_to_folder_label'        => 'Pindahkan ke Folder',
                'move_to_folder_placeholder'  => 'misal, Diproses',
                'active'                      => 'Aktif',
                'whitelist_label'             => 'Whitelist Email',
                'whitelist_help'              => 'Jika kosong, semua pengirim diizinkan. Tambahkan domain atau alamat email untuk membatasi email mana yang diimpor.',
                'whitelist_domain'            => 'Domain',
                'whitelist_email'             => 'Email',
                'whitelist_value_placeholder' => 'misal, perusahaan.com atau user@example.com',
            ],

            // Activity log modal
            'activity' => [
                'title'              => 'Aktivitas Kotak Surat',
                'search_placeholder' => 'Cari berdasarkan pengirim, nama, atau subjek...',
                'processing_log'     => 'Log Pemrosesan',
            ],

            // Analyst modal
            'analyst' => [
                'add_title'             => 'Tambah Analis',
                'edit_title'            => 'Edit Analis',
                'username'              => 'Nama Pengguna',
                'username_placeholder'  => 'misal, bsantoso',
                'full_name'             => 'Nama Lengkap',
                'full_name_placeholder' => 'misal, Budi Santoso',
                'email'                 => 'Email',
                'email_placeholder'     => 'misal, bsantoso@perusahaan.com',
                'password'              => 'Password',
                'password_placeholder'  => 'Masukkan password',
                'password_help'         => 'Wajib untuk analis baru.',
                'active'                => 'Aktif',
            ],

            // Password reset modal
            'password_reset' => [
                'title'                        => 'Atur Ulang Kata Sandi',
                'resetting_for'                => 'Mengatur ulang kata sandi untuk:',
                'new_password'                 => 'Kata Sandi Baru',
                'new_password_placeholder'     => 'Masukkan kata sandi baru',
                'confirm_password'             => 'Konfirmasi Kata Sandi',
                'confirm_password_placeholder' => 'Konfirmasi kata sandi baru',
            ],

            // Team assignment modal
            'team_assignment' => [
                'title'       => 'Tetapkan Tim',
                'description' => 'Pilih tim yang akan ditetapkan:',
                'loading'     => 'Memuat tim...',
            ],

            // Email template modal
            'template' => [
                'add_title'           => 'Tambah Template Email',
                'edit_title'          => 'Edit Template Email',
                'name'                => 'Nama',
                'name_placeholder'    => 'misal, Balasan Otomatis Tiket Baru',
                'event_trigger'       => 'Pemicu Peristiwa',
                'event_select'        => 'Pilih peristiwa...',
                'event_new_ticket'    => 'Tiket baru dari email',
                'event_assigned'      => 'Tiket ditugaskan',
                'event_closed'        => 'Tiket ditutup',
                'subject'             => 'Subjek',
                'subject_placeholder' => 'misal, Permintaan Anda telah diterima',
                'subject_help'        => '[SDREF:...] ditambahkan secara otomatis untuk threading balasan.',
                'body'                => 'Isi',
                'body_placeholder'    => "Yth. [requester_name],\n\nTerima kasih telah menghubungi kami...",
                'body_help'           => 'Kode merge: [ticket_reference], [ticket_subject], [ticket_status], [ticket_priority], [requester_name], [requester_email], [analyst_name], [analyst_email], [department_name], [created_date], [closed_date]',
                'display_order'       => 'Urutan Tampilan',
                'active'              => 'Aktif',
            ],

            // Rota shift modal
            'rota_shift' => [
                'add_title'        => 'Tambah Shift',
                'edit_title'       => 'Edit Shift',
                'name'             => 'Nama',
                'name_placeholder' => 'misal, Pagi, Standar, Malam',
                'start_time'       => 'Waktu Mulai',
                'end_time'         => 'Waktu Selesai',
                'display_order'    => 'Urutan Tampilan',
                'active'           => 'Aktif',
            ],
        ],
    ],

    // tickets/rota.php — weekly staff rota grid
    'rota' => [
        'page_title'      => 'Service Desk - Jadwal Kerja',
        'analyst_col'     => 'Analis',
        'no_analysts'     => 'Tidak ada analis aktif yang ditemukan.',
        'add_entry'       => 'Tambah entri',
        'on_call_badge'   => 'Siaga',
        'modal' => [
            'add_title'         => 'Tambah Entri Jadwal',
            'edit_title'        => 'Edit Entri Jadwal',
            'shift_label'       => 'Shift *',
            'shift_placeholder' => 'Pilih shift...',
            'location_label'    => 'Lokasi',
            'on_call_checkbox'  => 'Siaga',
        ],
        'toasts' => [
            'saved'         => 'Entri tersimpan',
            'deleted'       => 'Entri dihapus',
            'save_failed'   => 'Gagal menyimpan entri',
            'delete_failed' => 'Gagal menghapus entri',
            'error'         => 'Kesalahan: {error}',
        ],
        'delete_confirm'  => 'Hapus entri jadwal ini?',
    ],

    // tickets/users.php — end-user directory with per-user ticket list
    'users' => [
        'page_title'            => 'Service Desk - Pengguna',
        'list_title'            => 'Pengguna',
        'search_placeholder'    => 'Cari pengguna...',
        'count'                 => '{count} pengguna',
        'ticket_count'          => '{count} tiket',
        'unknown_name'          => 'Tidak diketahui',
        'no_users'              => 'Tidak ada pengguna yang ditemukan',
        'select_user'           => 'Pilih pengguna untuk melihat detail dan tiket mereka',
        'no_tickets'            => 'Tidak ada tiket yang ditemukan untuk pengguna ini',
        'error_loading_tickets' => 'Kesalahan saat memuat tiket',
        'tickets_section'       => 'Tiket ({count})',
        'status_new_fallback'   => 'Baru',
        'info' => [
            'email'         => 'Email',
            'first_seen'    => 'Pertama Terlihat',
            'total_tickets' => 'Total Tiket',
        ],
        'table' => [
            'ticket_number' => 'Tiket #',
            'subject'       => 'Subjek',
            'status'        => 'Status',
            'priority'      => 'Prioritas',
            'created'       => 'Dibuat',
        ],
    ],

    // tickets/calendar.php — scheduled-tickets calendar view
    'calendar' => [
        'page_title'    => 'Service Desk - Kalender',
        'modal_title'   => 'Detail Tiket',
        'open_in_inbox' => 'Buka di Kotak Masuk',
        'x_more'        => '{count} lainnya...',
        'unassigned'    => 'Belum ditugaskan',
        'na'            => 'T/A',
        'date_at_time'  => '{date} pukul {time}',
        'modal' => [
            'scheduled'  => 'Dijadwalkan:',
            'status'     => 'Status:',
            'priority'   => 'Prioritas:',
            'requester'  => 'Pemohon:',
            'department' => 'Departemen:',
            'owner'      => 'Pemilik:',
        ],
    ],
];
