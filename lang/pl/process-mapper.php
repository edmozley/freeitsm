<?php
/**
 * Polski (pl) — Process Mapper module strings.
 * Falls back per-key to lang/en/process-mapper.php for anything missing.
 */
return [
    'title' => 'Mapowanie procesów',

    'toolbar' => [
        'process'   => 'Proces',
        'decision'  => 'Decyzja',
        'terminal'  => 'Początek/Koniec',
        'document'  => 'Dokument',
        'connect'   => 'Połącz',
        'group'     => 'Grupa',
        'lane'      => 'Tor',
        'export'    => 'Eksportuj',
        'save'      => 'Zapisz',
    ],

    'autosave' => [
        'label'   => 'Autozapis',
        'saved'   => 'Zapisano',
        'unsaved' => 'Niezapisane',
        'unsaved_changes' => 'Niezapisane zmiany',
        'saving'  => 'Zapisywanie…',
        'failed'  => 'Zapisywanie nie powiodło się —',
        'retry'   => 'spróbuj ponownie',
        'off'     => 'Autozapis wyłączony',
        'tooltip' => 'Zapisuje automatycznie kilka sekund po zaprzestaniu edycji',
    ],

    'detail' => [
        'step_title'   => 'Szczegóły kroku',
        'group_title'  => 'Szczegóły grupy',
        'lane_title'   => 'Szczegóły toru',
        'label'        => 'Etykieta',
        'type'         => 'Typ',
        'colour'       => 'Kolor',
        'gradient'     => 'Gradient',
        'description'  => 'Opis',
        'position'     => 'Pozycja',
        'size'         => 'Rozmiar',
        'height'       => 'Wysokość',
        'order'        => 'Kolejność (od góry do dołu)',
        'connectors'   => 'Łączniki',
        'no_connectors'=> 'Brak łączników',
    ],

    'export_modal' => [
        'title'  => 'Eksport — Diagram Mermaid',
        'hint'   => 'Wklej ten kod do dowolnego edytora Markdown obsługującego Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Tory stają się blokami <code>subgraph</code>; układ automatyczny zastępuje ręcznie ustawione pozycje.',
        'copy'   => 'Kopiuj',
        'copied' => 'Skopiowano ✓',
        'close'  => 'Zamknij',
    ],

    'toast' => [
        'no_process_open' => 'Najpierw otwórz lub utwórz proces',
        'saved'           => 'Zapisano',
        'save_failed'     => 'Zapisywanie nie powiodło się',
    ],
];
