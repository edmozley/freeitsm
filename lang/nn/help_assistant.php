<?php
/**
 * FreeITSM — help_assistant strings (nn).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php nn
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Hjelp',
    'button' => 'Hjelpeassistent',
    'greeting' => 'Hei! Spør meg korleis du gjer noko i FreeITSM, så finn eg rett stad i hjelpa.',
    'mode_search' => 'Søkjer i hjelpesidene - ingen KI er sett opp, så du får dei rette sidene i staden for eit skrive svar.',
    'mode_meaning' => 'Søkjer i hjelpa etter meining - ingen chat-KI er sett opp, så du får dei rette sidene i staden for eit skrive svar.',
    'named_notice' => 'Viss hjelpa ikkje kan svare på eit spørsmål, blir det delt med administratorane dine saman med namnet ditt.',
    'mode_ask' => 'Svara er skrivne av KI ut frå FreeITSM si eiga hjelp - delane han brukte er lenkja til, så du kan kontrollere.',
    'sources' => 'Frå hjelpa',
    'thinking' => 'Les hjelpa…',
    'placeholder' => 'Spør korleis du gjer noko…',
    'send' => 'Send',
    'reset' => 'Nullstill',
    'close' => 'Lukk',
    'open' => 'Opne',
    'also' => 'Sjå gjerne òg',
    'searching' => 'Søkjer i hjelpa…',
    'error' => 'Noko gjekk gale - prøv igjen.',
    'phrase' => [
        'clear' => [
            1 => 'Dette ser ut som nøyaktig det du treng:',
            2 => 'Gode nyheiter - hjelpa dekkjer dette:',
            3 => 'Eg trur dette er den rette:',
        ],
        'several' => [
            1 => 'Dette fann eg i hjelpa:',
            2 => 'Desse ser ut som dei næraste treffa:',
            3 => 'Desse kan vere til hjelp:',
            4 => 'Eg såg gjennom hjelpa og fann desse:',
        ],
        'module' => [
            1 => 'Dette seier hjelpa for denne modulen:',
            2 => 'Frå hjelpa for modulen du er i:',
        ],
        'weak' => [
            1 => 'Eg fann ikkje eit nøyaktig treff, men desse kan vere nære:',
            2 => 'Ikkje eit perfekt treff, men verdt eit blikk:',
        ],
        'none' => [
            1 => 'Eg fann ingenting om det - prøv andre ord, eller nemn modulen du er i?',
            2 => 'Ingenting i hjelpa samsvarar med det. Prøv kanskje eit kortare spørsmål?',
        ],
        'not_covered' => [
            1 => 'Hjelpa ser ikkje ut til å dekkje det. Dette er dei næraste delane:',
            2 => 'Eg fann ikkje det i hjelpa - dette kjem nærast:',
        ],
    ],
];
