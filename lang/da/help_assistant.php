<?php
/**
 * FreeITSM — help_assistant strings (da).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php da
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Hjælp',
    'button' => 'Hjælpeassistent',
    'greeting' => 'Hej! Spørg mig, hvordan du gør noget i FreeITSM, så finder jeg den rette del af hjælpen.',
    'mode_search' => 'Søger i hjælpesiderne - der er ikke sat AI op, så du får de rigtige sider i stedet for et skrevet svar.',
    'mode_meaning' => 'Søger i hjælpen efter betydning - der er ikke sat chat-AI op, så du får de rigtige sider i stedet for et skrevet svar.',
    'named_notice' => 'Hvis hjælpen ikke kan besvare et spørgsmål, deles det med dine administratorer sammen med dit navn.',
    'mode_ask' => 'Svarene er skrevet af AI ud fra FreeITSM\'s egen hjælp - de afsnit, den brugte, er linket, så du kan tjekke.',
    'sources' => 'Fra hjælpen',
    'thinking' => 'Læser hjælpen…',
    'placeholder' => 'Spørg, hvordan du gør noget…',
    'send' => 'Send',
    'reset' => 'Nulstil',
    'close' => 'Luk',
    'open' => 'Åbn',
    'also' => 'Også et kig værd',
    'searching' => 'Søger i hjælpen…',
    'error' => 'Noget gik galt - prøv igen.',
    'phrase' => [
        'clear' => [
            1 => 'Det her ligner præcis det, du har brug for:',
            2 => 'Gode nyheder - hjælpen dækker det her:',
            3 => 'Jeg tror, det er den her:',
        ],
        'several' => [
            1 => 'Det her fandt jeg i hjælpen:',
            2 => 'Det her ser ud til at være de nærmeste match:',
            3 => 'Du kan måske bruge disse:',
            4 => 'Jeg kiggede hjælpen igennem og fandt disse:',
        ],
        'module' => [
            1 => 'Det her siger hjælpen til dette modul:',
            2 => 'Fra hjælpen til det modul, du er i:',
        ],
        'weak' => [
            1 => 'Jeg kunne ikke finde et præcist match, men disse kan være tæt på:',
            2 => 'Ikke et perfekt match, men et kig værd:',
        ],
        'none' => [
            1 => 'Jeg kunne ikke finde noget om det - prøv andre ord, eller nævn det modul, du er i?',
            2 => 'Intet i hjælpen matcher det. Prøv måske et kortere spørgsmål?',
        ],
        'not_covered' => [
            1 => 'Hjælpen ser ikke ud til at dække det. Det er de nærmeste afsnit:',
            2 => 'Jeg kunne ikke finde det i hjælpen - det her kommer tættest på:',
        ],
    ],
];
