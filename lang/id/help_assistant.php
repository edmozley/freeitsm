<?php
/**
 * FreeITSM — help_assistant strings (id).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php id
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Bantuan',
    'button' => 'Asisten bantuan',
    'greeting' => 'Halo! Tanyakan cara melakukan sesuatu di FreeITSM dan saya akan mencarikan bagian bantuan yang tepat.',
    'mode_search' => 'Mencari di halaman bantuan - AI belum disiapkan, jadi Anda mendapat halaman yang tepat, bukan jawaban tertulis.',
    'mode_meaning' => 'Mencari bantuan berdasarkan makna - AI chat belum disiapkan, jadi Anda mendapat halaman yang tepat, bukan jawaban tertulis.',
    'named_notice' => 'Jika bantuan tidak dapat menjawab pertanyaan, pertanyaan itu dibagikan kepada admin Anda beserta nama Anda.',
    'mode_ask' => 'Jawaban ditulis oleh AI dari bantuan FreeITSM sendiri - bagian yang dipakai ditautkan, sehingga Anda dapat memeriksanya.',
    'sources' => 'Dari bantuan',
    'thinking' => 'Membaca bantuan…',
    'placeholder' => 'Tanyakan cara melakukan sesuatu…',
    'send' => 'Kirim',
    'reset' => 'Atur ulang',
    'close' => 'Tutup',
    'open' => 'Buka',
    'also' => 'Layak dilihat juga',
    'searching' => 'Mencari di bantuan…',
    'error' => 'Terjadi kesalahan - silakan coba lagi.',
    'phrase' => [
        'clear' => [
            1 => 'Ini tampaknya persis yang Anda butuhkan:',
            2 => 'Kabar baik - bantuan membahas hal ini:',
            3 => 'Saya rasa ini yang dimaksud:',
        ],
        'several' => [
            1 => 'Inilah yang saya temukan di bantuan:',
            2 => 'Ini tampaknya yang paling cocok:',
            3 => 'Ini mungkin berguna bagi Anda:',
            4 => 'Saya telah menelusuri bantuan dan menemukan ini:',
        ],
        'module' => [
            1 => 'Inilah isi bantuan untuk modul ini:',
            2 => 'Dari bantuan untuk modul yang sedang Anda buka:',
        ],
        'weak' => [
            1 => 'Saya tidak menemukan yang persis cocok, tetapi ini mungkin mendekati:',
            2 => 'Tidak sepenuhnya cocok, tetapi layak dilihat:',
        ],
        'none' => [
            1 => 'Saya tidak menemukan apa pun untuk itu - coba kata lain, atau sebutkan modul yang sedang Anda buka?',
            2 => 'Tidak ada yang cocok di bantuan. Mungkin coba pertanyaan yang lebih singkat?',
        ],
        'not_covered' => [
            1 => 'Bantuan tampaknya tidak membahas hal itu. Ini bagian yang paling mendekati:',
            2 => 'Saya tidak menemukannya di bantuan - ini yang paling mendekati:',
        ],
    ],
];
