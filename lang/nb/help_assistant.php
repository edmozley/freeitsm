<?php
/**
 * FreeITSM — help_assistant strings (nb).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nb
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Hjelp',
    'button' => 'Hjelpeassistent',
    'greeting' => 'Hei! Spør meg hvordan du gjør noe i FreeITSM, så finner jeg riktig del av hjelpen.',
    'mode_search' => 'Søker i hjelpesidene - ingen AI er satt opp, så du får de riktige sidene i stedet for et skrevet svar.',
    'mode_meaning' => 'Søker i hjelpen etter betydning - ingen chat-AI er satt opp, så du får de riktige sidene i stedet for et skrevet svar.',
    'named_notice' => 'Hvis hjelpen ikke kan svare på et spørsmål, deles det med administratorene dine med navnet ditt.',
    'mode_ask' => 'Svarene er skrevet av AI ut fra FreeITSMs egen hjelp - delene den brukte er lenket, så du kan kontrollere.',
    'sources' => 'Fra hjelpen',
    'thinking' => 'Leser hjelpen…',
    'placeholder' => 'Spør hvordan du gjør noe…',
    'send' => 'Send',
    'reset' => 'Tilbakestill',
    'close' => 'Lukk',
    'open' => 'Åpne',
    'also' => 'Verdt et blikk også',
    'searching' => 'Søker i hjelpen…',
    'error' => 'Noe gikk galt - prøv igjen.',
    'phrase' => [
        'clear' => [
            1 => 'Dette ser ut til å være akkurat det du trenger:',
            2 => 'Gode nyheter - hjelpen dekker dette:',
            3 => 'Jeg tror dette er den riktige:',
        ],
        'several' => [
            1 => 'Dette fant jeg i hjelpen:',
            2 => 'Dette ser ut til å være de nærmeste treffene:',
            3 => 'Disse kan være nyttige:',
            4 => 'Jeg så gjennom hjelpen og fant disse:',
        ],
        'module' => [
            1 => 'Dette sier hjelpen for denne modulen:',
            2 => 'Fra hjelpen for modulen du er i:',
        ],
        'weak' => [
            1 => 'Jeg fant ikke et eksakt treff, men disse kan komme nær:',
            2 => 'Ikke et perfekt treff, men verdt et blikk:',
        ],
        'none' => [
            1 => 'Jeg fant ingenting om det - prøv andre ord, eller si hvilken modul du er i?',
            2 => 'Ingenting i hjelpen samsvarer med det. Kanskje prøve et kortere spørsmål?',
        ],
        'not_covered' => [
            1 => 'Hjelpen ser ikke ut til å dekke det. Dette er de nærmeste delene:',
            2 => 'Jeg fant det ikke i hjelpen - dette er det nærmeste:',
        ],
    ],
];
