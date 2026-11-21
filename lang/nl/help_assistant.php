<?php
/**
 * FreeITSM — help_assistant strings (nl).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nl
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Help',
    'button' => 'Helpassistent',
    'greeting' => 'Hoi! Vraag me hoe je iets in FreeITSM doet en ik zoek het juiste deel van de help erbij.',
    'mode_search' => 'De helppagina\'s worden doorzocht - er is geen AI ingesteld, dus je krijgt de juiste pagina\'s in plaats van een geschreven antwoord.',
    'mode_meaning' => 'De help wordt op betekenis doorzocht - er is geen chat-AI ingesteld, dus je krijgt de juiste pagina\'s in plaats van een geschreven antwoord.',
    'named_notice' => 'Als de help een vraag niet kan beantwoorden, wordt die met je naam gedeeld met je beheerders.',
    'mode_ask' => 'Antwoorden worden door AI geschreven op basis van de eigen help van FreeITSM - de gebruikte onderdelen zijn gelinkt, zodat je ze kunt controleren.',
    'sources' => 'Uit de help',
    'thinking' => 'De help wordt gelezen…',
    'placeholder' => 'Vraag hoe je iets doet…',
    'send' => 'Verzenden',
    'reset' => 'Resetten',
    'close' => 'Sluiten',
    'open' => 'Openen',
    'also' => 'Ook de moeite waard',
    'searching' => 'De help wordt doorzocht…',
    'error' => 'Er is iets misgegaan - probeer het opnieuw.',
    'phrase' => [
        'clear' => [
            1 => 'Dit lijkt precies wat je nodig hebt:',
            2 => 'Goed nieuws - de help behandelt dit:',
            3 => 'Ik denk dat dit het is:',
        ],
        'several' => [
            1 => 'Dit heb ik in de help gevonden:',
            2 => 'Dit lijken de beste treffers:',
            3 => 'Deze kunnen nuttig zijn:',
            4 => 'Ik heb de help doorgekeken en dit gevonden:',
        ],
        'module' => [
            1 => 'Dit zegt de help voor deze module:',
            2 => 'Uit de help voor de module waarin je zit:',
        ],
        'weak' => [
            1 => 'Ik kon geen exacte treffer vinden, maar deze komen misschien in de buurt:',
            2 => 'Geen perfecte treffer, maar het bekijken waard:',
        ],
        'none' => [
            1 => 'Hiervoor kon ik niets vinden - probeer andere woorden, of noem de module waarin je zit?',
            2 => 'Niets in de help komt hiermee overeen. Probeer misschien een kortere vraag?',
        ],
        'not_covered' => [
            1 => 'De help lijkt dit niet te behandelen. Dit zijn de dichtstbijzijnde onderdelen:',
            2 => 'Ik kon dit niet in de help vinden - dit komt het dichtst in de buurt:',
        ],
    ],
];
