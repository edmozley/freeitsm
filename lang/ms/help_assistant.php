<?php
/**
 * FreeITSM — help_assistant strings (ms).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php ms
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Bantuan',
    'button' => 'Pembantu bantuan',
    'greeting' => 'Hai! Tanya saya cara melakukan sesuatu dalam FreeITSM dan saya akan mencari bahagian bantuan yang betul.',
    'mode_search' => 'Mencari halaman bantuan - tiada AI disediakan, jadi anda mendapat halaman yang betul dan bukan jawapan bertulis.',
    'mode_meaning' => 'Mencari bantuan mengikut maksud - tiada AI sembang disediakan, jadi anda mendapat halaman yang betul dan bukan jawapan bertulis.',
    'named_notice' => 'Jika bantuan tidak dapat menjawab soalan, ia dikongsi dengan pentadbir anda bersama nama anda.',
    'mode_ask' => 'Jawapan ditulis oleh AI daripada bantuan FreeITSM sendiri - bahagian yang digunakan dipautkan, supaya anda boleh menyemak.',
    'sources' => 'Daripada bantuan',
    'thinking' => 'Membaca bantuan…',
    'placeholder' => 'Tanya cara melakukan sesuatu…',
    'send' => 'Hantar',
    'reset' => 'Set semula',
    'close' => 'Tutup',
    'open' => 'Buka',
    'also' => 'Turut berbaloi dilihat',
    'searching' => 'Mencari dalam bantuan…',
    'error' => 'Sesuatu telah berlaku - sila cuba lagi.',
    'phrase' => [
        'clear' => [
            1 => 'Ini nampaknya betul-betul yang anda perlukan:',
            2 => 'Berita baik - bantuan merangkumi perkara ini:',
            3 => 'Saya rasa inilah dia:',
        ],
        'several' => [
            1 => 'Inilah yang saya temui dalam bantuan:',
            2 => 'Ini kelihatan padanan yang paling hampir:',
            3 => 'Anda mungkin mendapati ini berguna:',
            4 => 'Saya telah menyemak bantuan dan menemui ini:',
        ],
        'module' => [
            1 => 'Inilah yang dinyatakan bantuan untuk modul ini:',
            2 => 'Daripada bantuan untuk modul yang anda gunakan:',
        ],
        'weak' => [
            1 => 'Saya tidak menemui padanan tepat, tetapi ini mungkin hampir:',
            2 => 'Bukan padanan sempurna, tetapi berbaloi dilihat:',
        ],
        'none' => [
            1 => 'Saya tidak menemui apa-apa untuk itu - cuba perkataan lain, atau namakan modul yang anda gunakan?',
            2 => 'Tiada apa dalam bantuan yang sepadan. Mungkin cuba soalan yang lebih pendek?',
        ],
        'not_covered' => [
            1 => 'Bantuan nampaknya tidak merangkumi perkara itu. Ini ialah bahagian yang paling hampir:',
            2 => 'Saya tidak menemui itu dalam bantuan - inilah yang paling hampir:',
        ],
    ],
];
