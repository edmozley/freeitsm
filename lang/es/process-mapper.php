<?php
/**
 * Español (es) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Mapeador de procesos',

    'toolbar' => [
        'process'   => 'Proceso',
        'decision'  => 'Decisión',
        'terminal'  => 'Inicio/Fin',
        'document'  => 'Documento',
        'connect'   => 'Conectar',
        'group'     => 'Grupo',
        'lane'      => 'Carril',
        'export'    => 'Exportar',
        'save'      => 'Guardar',
    ],

    'autosave' => [
        'label'   => 'Autoguardado',
        'saved'   => 'Guardado',
        'unsaved' => 'Sin guardar',
        'unsaved_changes' => 'Cambios sin guardar',
        'saving'  => 'Guardando…',
        'failed'  => 'Error al guardar —',
        'retry'   => 'reintentar',
        'off'     => 'Autoguardado desactivado',
        'tooltip' => 'Guarda automáticamente unos segundos después de dejar de editar',
    ],

    'detail' => [
        'step_title'   => 'Detalles del paso',
        'group_title'  => 'Detalles del grupo',
        'lane_title'   => 'Detalles del carril',
        'label'        => 'Etiqueta',
        'type'         => 'Tipo',
        'colour'       => 'Color',
        'gradient'     => 'Degradado',
        'description'  => 'Descripción',
        'position'     => 'Posición',
        'size'         => 'Tamaño',
        'height'       => 'Altura',
        'order'        => 'Orden (de arriba abajo)',
        'connectors'   => 'Conectores',
        'no_connectors'=> 'Sin conectores',
    ],

    'export_modal' => [
        'title'  => 'Exportar — Diagrama Mermaid',
        'hint'   => 'Pegue este código en cualquier editor Markdown compatible con Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Los carriles se convierten en bloques <code>subgraph</code>; el diseño automático sustituye sus posiciones.',
        'copy'   => 'Copiar',
        'copied' => 'Copiado ✓',
        'close'  => 'Cerrar',
    ],

    'toast' => [
        'no_process_open' => 'Abra o cree primero un proceso',
        'saved'           => 'Guardado',
        'save_failed'     => 'Error al guardar',
    ],
];
