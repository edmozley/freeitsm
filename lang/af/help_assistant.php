<?php
/**
 * FreeITSM — help_assistant strings (af).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php af
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Hulp',
    'button' => 'Hulpassistent',
    'greeting' => 'Hallo! Vra my hoe om iets in FreeITSM te doen en ek sal die regte deel van die hulp vind.',
    'mode_search' => 'Deursoek die hulpbladsye - geen KI is opgestel nie, so jy kry die regte bladsye eerder as \'n geskrewe antwoord.',
    'mode_meaning' => 'Deursoek die hulp volgens betekenis - geen klets-KI is opgestel nie, so jy kry die regte bladsye eerder as \'n geskrewe antwoord.',
    'named_notice' => 'As die hulp nie \'n vraag kan beantwoord nie, word dit met jou naam met jou administrateurs gedeel.',
    'mode_ask' => 'Antwoorde word deur KI uit FreeITSM se eie hulp geskryf - die afdelings wat dit gebruik het, is geskakel, sodat jy kan nagaan.',
    'sources' => 'Uit die hulp',
    'thinking' => 'Lees die hulp…',
    'placeholder' => 'Vra hoe om iets te doen…',
    'send' => 'Stuur',
    'reset' => 'Herstel',
    'close' => 'Maak toe',
    'open' => 'Maak oop',
    'also' => 'Ook die moeite werd om te sien',
    'searching' => 'Deursoek die hulp…',
    'error' => 'Iets het skeefgeloop - probeer asseblief weer.',
    'phrase' => [
        'clear' => [
            1 => 'Dit lyk presies soos wat jy nodig het:',
            2 => 'Goeie nuus - die hulp dek dit:',
            3 => 'Ek dink dit is die een:',
        ],
        'several' => [
            1 => 'Dit is wat ek in die hulp gevind het:',
            2 => 'Dit lyk soos die naaste passings:',
            3 => 'Hierdie kan nuttig wees:',
            4 => 'Ek het deur die hulp gekyk en hierdie gevind:',
        ],
        'module' => [
            1 => 'Dit is wat die hulp vir hierdie module sê:',
            2 => 'Uit die hulp vir die module waarin jy is:',
        ],
        'weak' => [
            1 => 'Ek kon nie \'n presiese passing vind nie, maar hierdie kan naby wees:',
            2 => 'Nie \'n perfekte passing nie, maar die moeite werd om te sien:',
        ],
        'none' => [
            1 => 'Ek kon niks daarvoor vind nie - probeer ander woorde, of noem die module waarin jy is?',
            2 => 'Niks in die hulp pas daarby nie. Probeer dalk \'n korter vraag?',
        ],
        'not_covered' => [
            1 => 'Die hulp blyk dit nie te dek nie. Dit is die naaste afdelings:',
            2 => 'Ek kon dit nie in die hulp vind nie - hier is wat die naaste kom:',
        ],
    ],
];
