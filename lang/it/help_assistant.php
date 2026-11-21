<?php
/**
 * FreeITSM — help_assistant strings (it).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php it
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Aiuto',
    'button' => 'Assistente di aiuto',
    'greeting' => 'Ciao! Chiedimi come fare qualcosa in FreeITSM e troverò la parte giusta dell\'aiuto.',
    'mode_search' => 'Ricerca nelle pagine di aiuto - nessuna IA è configurata, quindi ricevi le pagine giuste invece di una risposta scritta.',
    'mode_meaning' => 'Ricerca nell\'aiuto per significato - nessuna IA di chat è configurata, quindi ricevi le pagine giuste invece di una risposta scritta.',
    'named_notice' => 'Se l\'aiuto non sa rispondere a una domanda, questa viene condivisa con i tuoi amministratori insieme al tuo nome.',
    'mode_ask' => 'Le risposte sono scritte dall\'IA a partire dall\'aiuto di FreeITSM - le sezioni usate sono collegate, così puoi verificarle.',
    'sources' => 'Dall\'aiuto',
    'thinking' => 'Lettura dell\'aiuto…',
    'placeholder' => 'Chiedi come fare qualcosa…',
    'send' => 'Invia',
    'reset' => 'Reimposta',
    'close' => 'Chiudi',
    'open' => 'Apri',
    'also' => 'Da dare un\'occhiata anche a',
    'searching' => 'Ricerca nell\'aiuto…',
    'error' => 'Qualcosa è andato storto - riprova.',
    'phrase' => [
        'clear' => [
            1 => 'Sembra proprio ciò che ti serve:',
            2 => 'Buone notizie - l\'aiuto lo copre:',
            3 => 'Credo che sia questa:',
        ],
        'several' => [
            1 => 'Ecco cosa ho trovato nell\'aiuto:',
            2 => 'Queste sembrano le corrispondenze più vicine:',
            3 => 'Potresti trovare utili queste:',
            4 => 'Ho dato un\'occhiata all\'aiuto e ho trovato queste:',
        ],
        'module' => [
            1 => 'Ecco cosa dice l\'aiuto per questo modulo:',
            2 => 'Dall\'aiuto del modulo in cui ti trovi:',
        ],
        'weak' => [
            1 => 'Non ho trovato una corrispondenza esatta, ma queste potrebbero andare vicino:',
            2 => 'Non è una corrispondenza perfetta, ma vale un\'occhiata:',
        ],
        'none' => [
            1 => 'Non ho trovato nulla al riguardo - prova con parole diverse o indica il modulo in cui ti trovi.',
            2 => 'Nulla nell\'aiuto corrisponde. Prova con una domanda più breve?',
        ],
        'not_covered' => [
            1 => 'L\'aiuto non sembra trattare questo argomento. Queste sono le sezioni più vicine:',
            2 => 'Non ho trovato questo argomento nell\'aiuto - ecco cosa si avvicina di più:',
        ],
    ],
];
