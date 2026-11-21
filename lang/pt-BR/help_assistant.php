<?php
/**
 * FreeITSM — help_assistant strings (pt-BR).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php pt-BR
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Ajuda',
    'button' => 'Assistente de ajuda',
    'greeting' => 'Olá! Pergunte como fazer algo no FreeITSM e eu encontro a parte certa da ajuda.',
    'mode_search' => 'Pesquisando nas páginas de ajuda - nenhuma IA está configurada, então você recebe as páginas certas em vez de uma resposta escrita.',
    'mode_meaning' => 'Pesquisando a ajuda por significado - nenhuma IA de chat está configurada, então você recebe as páginas certas em vez de uma resposta escrita.',
    'named_notice' => 'Se a ajuda não conseguir responder a uma pergunta, ela é compartilhada com os administradores, com o seu nome.',
    'mode_ask' => 'As respostas são escritas por IA a partir da própria ajuda do FreeITSM - as seções usadas estão com link, para você conferir.',
    'sources' => 'Da ajuda',
    'thinking' => 'Lendo a ajuda…',
    'placeholder' => 'Pergunte como fazer algo…',
    'send' => 'Enviar',
    'reset' => 'Redefinir',
    'close' => 'Fechar',
    'open' => 'Abrir',
    'also' => 'Vale conferir também',
    'searching' => 'Pesquisando na ajuda…',
    'error' => 'Algo deu errado - tente novamente.',
    'phrase' => [
        'clear' => [
            1 => 'Isto parece ser exatamente o que você precisa:',
            2 => 'Boa notícia - a ajuda cobre isto:',
            3 => 'Acho que é esta:',
        ],
        'several' => [
            1 => 'Veja o que encontrei na ajuda:',
            2 => 'Estas parecem ser as correspondências mais próximas:',
            3 => 'Talvez estas ajudem:',
            4 => 'Dei uma olhada na ajuda e encontrei isto:',
        ],
        'module' => [
            1 => 'Veja o que diz a ajuda deste módulo:',
            2 => 'Da ajuda do módulo em que você está:',
        ],
        'weak' => [
            1 => 'Não encontrei uma correspondência exata, mas estas podem ser parecidas:',
            2 => 'Não é uma correspondência perfeita, mas vale conferir:',
        ],
        'none' => [
            1 => 'Não encontrei nada sobre isso - tente outras palavras ou diga em qual módulo você está.',
            2 => 'Nada na ajuda corresponde a isso. Talvez tente uma pergunta mais curta?',
        ],
        'not_covered' => [
            1 => 'A ajuda parece não cobrir isso. Estas são as seções mais próximas:',
            2 => 'Não encontrei isso na ajuda - aqui está o que chega mais perto:',
        ],
    ],
];
