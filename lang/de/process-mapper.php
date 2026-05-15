<?php
/**
 * Deutsch (de) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Prozess-Mapper',

    'toolbar' => [
        'process'   => 'Prozess',
        'decision'  => 'Entscheidung',
        'terminal'  => 'Start/Ende',
        'document'  => 'Dokument',
        'connect'   => 'Verbinden',
        'group'     => 'Gruppe',
        'lane'      => 'Schwimmbahn',
        'export'    => 'Exportieren',
        'save'      => 'Speichern',
    ],

    'autosave' => [
        'label'   => 'Automatisch speichern',
        'saved'   => 'Gespeichert',
        'unsaved' => 'Nicht gespeichert',
        'unsaved_changes' => 'Nicht gespeicherte Änderungen',
        'saving'  => 'Speichern…',
        'failed'  => 'Speichern fehlgeschlagen —',
        'retry'   => 'erneut versuchen',
        'off'     => 'Automatisches Speichern aus',
        'tooltip' => 'Speichert automatisch ein paar Sekunden nach der letzten Änderung',
    ],

    'detail' => [
        'step_title'   => 'Schritt-Details',
        'group_title'  => 'Gruppen-Details',
        'lane_title'   => 'Schwimmbahn-Details',
        'label'        => 'Bezeichnung',
        'type'         => 'Typ',
        'colour'       => 'Farbe',
        'gradient'     => 'Verlauf',
        'description'  => 'Beschreibung',
        'position'     => 'Position',
        'size'         => 'Größe',
        'height'       => 'Höhe',
        'order'        => 'Reihenfolge (oben nach unten)',
        'connectors'   => 'Verbindungen',
        'no_connectors'=> 'Keine Verbindungen',
    ],

    'export_modal' => [
        'title'  => 'Export — Mermaid-Flussdiagramm',
        'hint'   => 'Fügen Sie diesen Code in einen beliebigen Markdown-Editor mit Mermaid-Unterstützung ein (GitHub, GitLab, Notion, Confluence, Obsidian…). Schwimmbahnen werden zu <code>subgraph</code>-Blöcken; das automatische Layout ersetzt Ihre handgesetzten Positionen.',
        'copy'   => 'Kopieren',
        'copied' => 'Kopiert ✓',
        'close'  => 'Schließen',
    ],

    'toast' => [
        'no_process_open' => 'Öffnen oder erstellen Sie zuerst einen Prozess',
        'saved'           => 'Gespeichert',
        'save_failed'     => 'Speichern fehlgeschlagen',
    ],
];
