<?php
/**
 * The help assistant's window (includes/help_assistant_widget.php, assets/js/help-assistant.js).
 * phrase.<kind>.N: the canned lines, chosen by how good the match is - see
 * helpAssistantReplyKind(). Add or remove variants freely; they never claim an AI read anything.
 */
return [
    'title' => 'Hilfe',
    'button' => 'Hilfe-Assistent',
    'greeting' => 'Hallo! Fragen Sie mich, wie etwas in FreeITSM geht, und ich finde die passende Stelle in der Hilfe.',
    'mode_search' => 'Die Hilfeseiten werden durchsucht - es ist keine KI eingerichtet, daher erhalten Sie die passenden Seiten statt einer geschriebenen Antwort.',
    'mode_meaning' => 'Die Hilfe wird nach Bedeutung durchsucht - es ist keine Chat-KI eingerichtet, daher erhalten Sie die passenden Seiten statt einer geschriebenen Antwort.',
    'named_notice' => 'Wenn die Hilfe eine Frage nicht beantworten kann, wird sie mit Ihrem Namen an Ihre Administratoren weitergegeben.',
    'mode_ask' => 'Die Antworten schreibt eine KI aus der FreeITSM-Hilfe - die verwendeten Abschnitte sind verlinkt, damit Sie sie prüfen können.',
    'sources' => 'Aus der Hilfe',
    'thinking' => 'Die Hilfe wird gelesen…',
    'placeholder' => 'Fragen Sie, wie etwas geht…',
    'send' => 'Senden',
    'reset' => 'Zurücksetzen',
    'close' => 'Schließen',
    'open' => 'Öffnen',
    'also' => 'Auch interessant',
    'searching' => 'Die Hilfe wird durchsucht…',
    'error' => 'Etwas ist schiefgelaufen - bitte versuchen Sie es erneut.',
    'phrase' => [
        'clear' => [
            1 => 'Das sieht genau nach dem aus, was Sie brauchen:',
            2 => 'Gute Nachricht - die Hilfe deckt das ab:',
            3 => 'Ich glaube, das ist es:',
        ],
        'several' => [
            1 => 'Das habe ich in der Hilfe gefunden:',
            2 => 'Das sind die besten Treffer:',
            3 => 'Vielleicht hilft Ihnen das:',
            4 => 'Ich habe die Hilfe durchgesehen und das gefunden:',
        ],
        'module' => [
            1 => 'Das sagt die Hilfe zu diesem Modul:',
            2 => 'Aus der Hilfe des Moduls, in dem Sie sind:',
        ],
        'weak' => [
            1 => 'Ich habe keinen genauen Treffer gefunden, aber das könnte passen:',
            2 => 'Kein perfekter Treffer, aber einen Blick wert:',
        ],
        'none' => [
            1 => 'Dazu habe ich nichts gefunden - versuchen Sie andere Wörter oder nennen Sie das Modul?',
            2 => 'Nichts in der Hilfe passt dazu. Vielleicht eine kürzere Frage?',
        ],
        // The AI read the help and found it does not cover the question (step 5).
        'not_covered' => [1 => 'Das scheint die Hilfe nicht abzudecken. Das sind die nächstliegenden Abschnitte:', 2 => 'Das habe ich in der Hilfe nicht gefunden - das kommt dem am nächsten:'],
    ],
];
