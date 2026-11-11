<?php
/**
 * The help assistant's window (includes/help_assistant_widget.php, assets/js/help-assistant.js).
 * phrase.<kind>.N: the canned lines, chosen by how good the match is - see
 * helpAssistantReplyKind(). Add or remove variants freely; they never claim an AI read anything.
 */
return [
    'title' => 'Ayuda',
    'button' => 'Asistente de ayuda',
    'greeting' => '¡Hola! Pregúnteme cómo hacer algo en FreeITSM y encontraré la parte adecuada de la ayuda.',
    'mode_search' => 'Se buscan las páginas de ayuda - no hay ninguna IA configurada, así que obtiene las páginas adecuadas en lugar de una respuesta escrita.',
    'placeholder' => 'Pregunte cómo hacer algo…',
    'send' => 'Enviar',
    'reset' => 'Reiniciar',
    'close' => 'Cerrar',
    'open' => 'Abrir',
    'also' => 'También le puede interesar',
    'searching' => 'Buscando en la ayuda…',
    'error' => 'Algo salió mal - inténtelo de nuevo.',
    'phrase' => [
        'clear' => [
            1 => 'Esto parece justo lo que necesita:',
            2 => 'Buenas noticias - la ayuda cubre esto:',
            3 => 'Creo que es esto:',
        ],
        'several' => [
            1 => 'Esto es lo que encontré en la ayuda:',
            2 => 'Estas parecen las coincidencias más cercanas:',
            3 => 'Puede que esto le sirva:',
            4 => 'He revisado la ayuda y encontré esto:',
        ],
        'module' => [
            1 => 'Esto dice la ayuda de este módulo:',
            2 => 'De la ayuda del módulo en el que está:',
        ],
        'weak' => [
            1 => 'No encontré una coincidencia exacta, pero esto podría acercarse:',
            2 => 'No es una coincidencia perfecta, pero vale la pena verlo:',
        ],
        'none' => [
            1 => 'No encontré nada sobre eso - ¿pruebe otras palabras o indique el módulo en el que está?',
            2 => 'Nada en la ayuda coincide. ¿Quizá una pregunta más corta?',
        ],
    ],
];
