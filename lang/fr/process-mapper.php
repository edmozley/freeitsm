<?php
/**
 * Français (fr) — Process Mapper module strings.
 * Pilot translation for phase 1 — switching to French should produce visible UI changes.
 */
return [
    'title' => 'Cartographie des processus',

    'nav' => [
        'processes' => 'Processus',
        'help'      => 'Aide',
    ],

    'sidebar' => [
        'new_process'        => '+ Nouveau processus',
        'search_placeholder' => 'Rechercher des processus…',
        'no_processes_yet'   => 'Aucun processus',
    ],

    'toolbar' => [
        'process'   => 'Étape',
        'decision'  => 'Décision',
        'terminal'  => 'Début/Fin',
        'document'  => 'Document',
        'connect'   => 'Connecter',
        'group'     => 'Groupe',
        'lane'      => 'Couloir',
        'export'    => 'Exporter',
        'save'      => 'Enregistrer',
    ],

    'context' => [
        'create_new' => 'Créer…',
    ],

    'autosave' => [
        'label'   => 'Auto-enregistrement',
        'saved'   => 'Enregistré',
        'unsaved' => 'Non enregistré',
        'unsaved_changes' => 'Modifications non enregistrées',
        'saving'  => 'Enregistrement…',
        'failed'  => 'Échec de l\'enregistrement —',
        'retry'   => 'réessayer',
        'off'     => 'Auto-enregistrement désactivé',
        'tooltip' => 'Enregistre automatiquement quelques secondes après l\'arrêt des modifications',
    ],

    'detail' => [
        'step_title'   => 'Détails de l\'étape',
        'group_title'  => 'Détails du groupe',
        'lane_title'   => 'Détails du couloir',
        'label'        => 'Libellé',
        'type'         => 'Type',
        'colour'       => 'Couleur',
        'gradient'     => 'Dégradé',
        'description'  => 'Description',
        'position'     => 'Position',
        'size'         => 'Taille',
        'height'       => 'Hauteur',
        'order'        => 'Ordre (haut en bas)',
        'connectors'   => 'Connecteurs',
        'no_connectors'=> 'Aucun connecteur',
        'step_type' => [
            'process'  => 'Étape',
            'decision' => 'Décision',
            'terminal' => 'Début/Fin',
            'document' => 'Document',
        ],
        'step_description_placeholder' => 'Ajouter des notes sur cette étape…',
        'lane_label_placeholder'       => 'ex. RH / IT / Fournisseur',
        'group_label_placeholder'      => 'ex. Phase de résolution',
        'lane_hint'                    => 'Glissez l\'en-tête gauche du couloir pour réorganiser. Glissez le bord inférieur pour redimensionner. Déposez une étape dans la bande pour l\'affecter à ce couloir.',
    ],

    'export_modal' => [
        'title'  => 'Exporter — Diagramme Mermaid',
        'hint'   => 'Collez ce code dans n\'importe quel éditeur Markdown prenant en charge Mermaid (GitHub, GitLab, Notion, Confluence, Obsidian…). Les couloirs deviennent des blocs <code>subgraph</code> ; la mise en page automatique remplace vos positions.',
        'copy'   => 'Copier',
        'copied' => 'Copié ✓',
        'close'  => 'Fermer',
    ],

    'toast' => [
        'no_process_open' => 'Ouvrez ou créez d\'abord un processus',
        'saved'           => 'Enregistré',
        'save_failed'     => 'Échec de l\'enregistrement',
    ],
];
