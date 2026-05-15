<?php
/**
 * Italiano (it) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Mappatore di processi',

    'toolbar' => [
        'process'   => 'Processo',
        'decision'  => 'Decisione',
        'terminal'  => 'Inizio/Fine',
        'document'  => 'Documento',
        'connect'   => 'Connetti',
        'group'     => 'Gruppo',
        'lane'      => 'Corsia',
        'export'    => 'Esporta',
        'save'      => 'Salva',
    ],

    'autosave' => [
        'label'   => 'Salvataggio automatico',
        'saved'   => 'Salvato',
        'unsaved' => 'Non salvato',
        'unsaved_changes' => 'Modifiche non salvate',
        'saving'  => 'Salvataggio…',
        'failed'  => 'Salvataggio non riuscito —',
        'retry'   => 'riprova',
        'off'     => 'Salvataggio automatico disattivato',
        'tooltip' => 'Salva automaticamente qualche secondo dopo aver smesso di modificare',
    ],

    'detail' => [
        'step_title'   => 'Dettagli passo',
        'group_title'  => 'Dettagli gruppo',
        'lane_title'   => 'Dettagli corsia',
        'label'        => 'Etichetta',
        'type'         => 'Tipo',
        'colour'       => 'Colore',
        'gradient'     => 'Sfumatura',
        'description'  => 'Descrizione',
        'position'     => 'Posizione',
        'size'         => 'Dimensione',
        'height'       => 'Altezza',
        'order'        => 'Ordine (dall\'alto verso il basso)',
        'connectors'   => 'Connettori',
        'no_connectors'=> 'Nessun connettore',
    ],

    'export_modal' => [
        'title'  => 'Esporta — Diagramma Mermaid',
        'hint'   => 'Incolla questo codice in qualsiasi editor Markdown che supporti Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Le corsie diventano blocchi <code>subgraph</code>; il layout automatico sostituisce le posizioni impostate manualmente.',
        'copy'   => 'Copia',
        'copied' => 'Copiato ✓',
        'close'  => 'Chiudi',
    ],

    'toast' => [
        'no_process_open' => 'Apri o crea prima un processo',
        'saved'           => 'Salvato',
        'save_failed'     => 'Salvataggio non riuscito',
    ],
];
