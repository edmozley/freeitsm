<?php
/**
 * Português (Brasil) (pt-BR) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Mapeador de processos',

    'toolbar' => [
        'process'   => 'Processo',
        'decision'  => 'Decisão',
        'terminal'  => 'Início/Fim',
        'document'  => 'Documento',
        'connect'   => 'Conectar',
        'group'     => 'Grupo',
        'lane'      => 'Raia',
        'export'    => 'Exportar',
        'save'      => 'Salvar',
    ],

    'autosave' => [
        'label'   => 'Salvamento automático',
        'saved'   => 'Salvo',
        'unsaved' => 'Não salvo',
        'unsaved_changes' => 'Alterações não salvas',
        'saving'  => 'Salvando…',
        'failed'  => 'Falha ao salvar —',
        'retry'   => 'tentar novamente',
        'off'     => 'Salvamento automático desativado',
        'tooltip' => 'Salva automaticamente alguns segundos após você parar de editar',
    ],

    'detail' => [
        'step_title'   => 'Detalhes da etapa',
        'group_title'  => 'Detalhes do grupo',
        'lane_title'   => 'Detalhes da raia',
        'label'        => 'Rótulo',
        'type'         => 'Tipo',
        'colour'       => 'Cor',
        'gradient'     => 'Gradiente',
        'description'  => 'Descrição',
        'position'     => 'Posição',
        'size'         => 'Tamanho',
        'height'       => 'Altura',
        'order'        => 'Ordem (de cima para baixo)',
        'connectors'   => 'Conectores',
        'no_connectors'=> 'Sem conectores',
    ],

    'export_modal' => [
        'title'  => 'Exportar — Fluxograma Mermaid',
        'hint'   => 'Cole este código em qualquer editor Markdown compatível com Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). As raias se tornam blocos <code>subgraph</code>; o layout automático substitui suas posições.',
        'copy'   => 'Copiar',
        'copied' => 'Copiado ✓',
        'close'  => 'Fechar',
    ],

    'toast' => [
        'no_process_open' => 'Abra ou crie um processo primeiro',
        'saved'           => 'Salvo',
        'save_failed'     => 'Falha ao salvar',
    ],
];
