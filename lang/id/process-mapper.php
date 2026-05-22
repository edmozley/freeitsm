<?php
/**
 * Bahasa Indonesia (id) — Process Mapper module strings.
 *
 * Mirrors lang/en/process-mapper.php structure exactly. Only values change.
 */
return [
    'title' => 'Process Mapper',

    'nav' => [
        'processes' => 'Proses',
        'help'      => 'Bantuan',
    ],

    'sidebar' => [
        'new_process'        => '+ Proses Baru',
        'search_placeholder' => 'Cari proses...',
        'no_processes_yet'   => 'Belum ada proses',
    ],

    'toolbar' => [
        'process'   => 'Proses',
        'decision'  => 'Keputusan',
        'terminal'  => 'Terminal',
        'document'  => 'Dokumen',
        'connect'   => 'Hubungkan',
        'group'     => 'Grup',
        'lane'      => 'Jalur',
        'export'    => 'Ekspor',
        'save'      => 'Simpan',
    ],

    'context' => [
        'create_new' => 'Buat baru…',
    ],

    'autosave' => [
        'label'   => 'Simpan Otomatis',
        'saved'   => 'Tersimpan',
        'unsaved' => 'Belum disimpan',
        'unsaved_changes' => 'Perubahan belum disimpan',
        'saving'  => 'Menyimpan…',
        'failed'  => 'Penyimpanan gagal —',
        'retry'   => 'coba lagi',
        'off'     => 'Simpan otomatis nonaktif',
        'tooltip' => 'Menyimpan otomatis setiap beberapa detik setelah Anda berhenti mengedit',
    ],

    'detail' => [
        'step_title'   => 'Detail Langkah',
        'group_title'  => 'Detail Grup',
        'lane_title'   => 'Detail Jalur',
        'label'        => 'Label',
        'type'         => 'Tipe',
        'colour'       => 'Warna',
        'gradient'     => 'Gradien',
        'description'  => 'Deskripsi',
        'position'     => 'Posisi',
        'size'         => 'Ukuran',
        'height'       => 'Tinggi',
        'order'        => 'Urutan (atas ke bawah)',
        'connectors'   => 'Konektor',
        'no_connectors'=> 'Tidak ada konektor',
        'step_type' => [
            'process'  => 'Proses',
            'decision' => 'Keputusan',
            'terminal' => 'Terminal (Mulai/Akhir)',
            'document' => 'Dokumen',
        ],
        'step_description_placeholder' => 'Tambahkan catatan tentang langkah ini...',
        'lane_label_placeholder'       => 'misal, HR / IT / Vendor',
        'group_label_placeholder'      => 'misal, Tahap penyelesaian',
        'lane_hint'                    => 'Seret header tepi-kiri jalur untuk mengatur ulang urutan. Seret tepi bawah untuk mengubah ukuran. Lepaskan langkah ke dalam pita untuk menetapkannya ke jalur ini.',
    ],

    'export_modal' => [
        'title'  => 'Ekspor — Bagan alir Mermaid',
        'hint'   => 'Tempel markup ini ke editor Markdown apa pun yang mendukung Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Jalur menjadi blok <code>subgraph</code>; tata letak otomatis menggantikan posisi yang Anda tempatkan secara manual.',
        'copy'   => 'Salin',
        'copied' => 'Tersalin ✓',
        'close'  => 'Tutup',
    ],

    'toast' => [
        'no_process_open' => 'Buka atau buat proses terlebih dahulu',
        'saved'           => 'Tersimpan',
        'save_failed'     => 'Gagal menyimpan',
    ],
];
