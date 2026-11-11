<?php
/**
 * FreeITSM — help_assistant strings (pl).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php pl
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Pomoc',
    'button' => 'Asystent pomocy',
    'greeting' => 'Cześć! Zapytaj, jak coś zrobić w FreeITSM, a znajdę odpowiednią część pomocy.',
    'mode_search' => 'Przeszukuję strony pomocy - AI nie jest skonfigurowane, więc dostajesz właściwe strony, a nie napisaną odpowiedź.',
    'mode_meaning' => 'Przeszukuję pomoc według znaczenia - czat AI nie jest skonfigurowany, więc dostajesz właściwe strony, a nie napisaną odpowiedź.',
    'named_notice' => 'Jeśli pomoc nie potrafi odpowiedzieć na pytanie, jest ono przekazywane administratorom wraz z Twoim imieniem i nazwiskiem.',
    'mode_ask' => 'Odpowiedzi pisze AI na podstawie własnej pomocy FreeITSM - użyte sekcje są podlinkowane, więc możesz to sprawdzić.',
    'sources' => 'Z pomocy',
    'thinking' => 'Czytam pomoc…',
    'placeholder' => 'Zapytaj, jak coś zrobić…',
    'send' => 'Wyślij',
    'reset' => 'Resetuj',
    'close' => 'Zamknij',
    'open' => 'Otwórz',
    'also' => 'Warto też zajrzeć',
    'searching' => 'Przeszukuję pomoc…',
    'error' => 'Coś poszło nie tak - spróbuj ponownie.',
    'phrase' => [
        'clear' => [
            1 => 'To wygląda dokładnie na to, czego potrzebujesz:',
            2 => 'Dobra wiadomość - pomoc to opisuje:',
            3 => 'Chyba to jest to:',
        ],
        'several' => [
            1 => 'Oto, co znalazłem w pomocy:',
            2 => 'To wyglądają na najbliższe dopasowania:',
            3 => 'Mogą Ci się przydać:',
            4 => 'Przejrzałem pomoc i znalazłem to:',
        ],
        'module' => [
            1 => 'Oto, co mówi pomoc dla tego modułu:',
            2 => 'Z pomocy modułu, w którym jesteś:',
        ],
        'weak' => [
            1 => 'Nie znalazłem dokładnego dopasowania, ale to może być blisko:',
            2 => 'Nie idealne dopasowanie, ale warto zajrzeć:',
        ],
        'none' => [
            1 => 'Nic na ten temat nie znalazłem - spróbuj innych słów lub podaj moduł, w którym jesteś.',
            2 => 'Nic w pomocy tego nie dotyczy. Może spróbuj krótszego pytania?',
        ],
        'not_covered' => [
            1 => 'Wygląda na to, że pomoc tego nie obejmuje. Oto najbliższe sekcje:',
            2 => 'Nie znalazłem tego w pomocy - oto, co jest najbliżej:',
        ],
    ],
];
