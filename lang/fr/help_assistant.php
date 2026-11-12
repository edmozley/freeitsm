<?php
/**
 * FreeITSM — help_assistant strings (fr).
 *
 * Keys mirror lang/en/help_assistant.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php fr
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Aide',
    'button' => 'Assistant d\'aide',
    'greeting' => 'Bonjour ! Demandez-moi comment faire quelque chose dans FreeITSM et je trouverai la bonne partie de l\'aide.',
    'mode_search' => 'Recherche dans les pages d\'aide - aucune IA n\'est configurée, vous obtenez donc les bonnes pages plutôt qu\'une réponse rédigée.',
    'mode_meaning' => 'Recherche dans l\'aide par le sens - aucune IA de conversation n\'est configurée, vous obtenez donc les bonnes pages plutôt qu\'une réponse rédigée.',
    'named_notice' => 'Si l\'aide ne peut pas répondre à une question, elle est transmise à vos administrateurs avec votre nom.',
    'mode_ask' => 'Les réponses sont rédigées par l\'IA à partir de l\'aide propre à FreeITSM - les sections utilisées sont liées, pour que vous puissiez vérifier.',
    'sources' => 'Tiré de l\'aide',
    'thinking' => 'Lecture de l\'aide…',
    'placeholder' => 'Demandez comment faire quelque chose…',
    'send' => 'Envoyer',
    'reset' => 'Réinitialiser',
    'close' => 'Fermer',
    'open' => 'Ouvrir',
    'also' => 'À consulter aussi',
    'searching' => 'Recherche dans l\'aide…',
    'error' => 'Une erreur s\'est produite - veuillez réessayer.',
    'phrase' => [
        'clear' => [
            1 => 'Cela ressemble exactement à ce qu\'il vous faut :',
            2 => 'Bonne nouvelle - l\'aide couvre ce sujet :',
            3 => 'Je pense que c\'est celle-ci :',
        ],
        'several' => [
            1 => 'Voici ce que j\'ai trouvé dans l\'aide :',
            2 => 'Voici les correspondances les plus proches :',
            3 => 'Ceci pourrait vous aider :',
            4 => 'J\'ai parcouru l\'aide et j\'ai trouvé ceci :',
        ],
        'module' => [
            1 => 'Voici ce que dit l\'aide de ce module :',
            2 => 'Dans l\'aide du module où vous vous trouvez :',
        ],
        'weak' => [
            1 => 'Je n\'ai pas trouvé de correspondance exacte, mais ceci pourrait s\'en approcher :',
            2 => 'Pas une correspondance parfaite, mais à consulter :',
        ],
        'none' => [
            1 => 'Je n\'ai rien trouvé à ce sujet - essayez d\'autres mots, ou indiquez le module où vous êtes ?',
            2 => 'Rien dans l\'aide ne correspond. Essayez peut-être une question plus courte ?',
        ],
        'not_covered' => [
            1 => 'L\'aide ne semble pas couvrir ce sujet. Voici les sections les plus proches :',
            2 => 'Je n\'ai pas trouvé cela dans l\'aide - voici ce qui s\'en rapproche le plus :',
        ],
    ],
];
