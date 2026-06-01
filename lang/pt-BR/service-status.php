<?php
/**
 * Português (Brasil) (pt-BR) — Service Status module strings.
 * Missing keys fall back to lang/en/service-status.php per-key.
 */
return [
    'title' => 'Status dos serviços',

    'nav' => [
        'status'   => 'Status',
        'settings' => 'Configurações',
        'help'     => 'Ajuda',
    ],

    'board' => [
        'services'        => 'Serviços',
        'service_count'   => '{count} serviços',
        'loading'         => 'Carregando...',
        'no_services'     => 'Nenhum serviço configurado. Vá em Configurações para adicionar serviços.',
        'incidents'       => 'Incidentes',
        'new'             => 'Novo',
        'col_title'       => 'Título',
        'col_status'      => 'Status',
        'col_affected'    => 'Serviços afetados',
        'col_updated'     => 'Atualizado',
        'no_incidents'    => 'Nenhum incidente para exibir.',
        'none'            => 'Nenhum',
    ],

    'modal' => [
        'new_incident'        => 'Novo incidente',
        'edit_incident'       => 'Editar incidente',
        'title'               => 'Título',
        'title_placeholder'   => 'Breve descrição do incidente',
        'status'              => 'Status',
        'comment'             => 'Comentário',
        'comment_placeholder' => 'Detalhes sobre o incidente...',
        'affected_services'   => 'Serviços afetados',
        'add_service'         => '+ Adicionar serviço',
        'delete'              => 'Excluir',
        'cancel'              => 'Cancelar',
        'save'                => 'Salvar',
    ],

    'toast' => [
        'incident_saved'   => 'Incidente salvo',
        'incident_deleted' => 'Incidente excluído',
        'save_failed'      => 'Falha ao salvar',
        'delete_failed'    => 'Falha ao excluir',
        'save_incident_failed'   => 'Falha ao salvar o incidente',
        'delete_incident_failed' => 'Falha ao excluir o incidente',
        'saved'            => 'Salvo',
        'deleted'          => 'Excluído',
        'save_service_failed'    => 'Falha ao salvar o serviço',
        'delete_service_failed'  => 'Falha ao excluir o serviço',
    ],

    'confirm' => [
        'delete_incident_title'   => 'Excluir incidente',
        'delete_incident_message' => 'Excluir este incidente?',
        'delete_title'            => 'Excluir',
        'delete_message'          => 'Excluir "{name}"?',
        'delete_label'            => 'Excluir',
    ],

    'settings' => [
        'tab_services'     => 'Serviços',
        'tab_statuses'     => 'Status',
        'tab_impacts'      => 'Níveis de impacto',

        'services_heading' => 'Serviços',
        'statuses_heading' => 'Status de incidentes',
        'impacts_heading'  => 'Níveis de impacto',
        'add'              => 'Adicionar',
        'loading'          => 'Carregando...',
        'no_services'      => 'Nenhum serviço ainda. Clique em Adicionar para criar um.',
        'no_items'         => 'Nenhum item encontrado',
        'load_failed'      => 'Falha ao carregar os dados',
        'error_prefix'     => 'Erro: {message}',

        'statuses_intro_html' => 'Estados do fluxo de trabalho para incidentes de serviço. Status marcados como <em>resolvido</em> encerram o incidente — registrando automaticamente o <code>resolved_datetime</code> e removendo o incidente do painel ativo. Exatamente um status é o padrão para novos incidentes.',
        'impacts_intro_html'  => 'Faixas de severidade exibidas como o selo em cada cartão de serviço. A <strong>ordem de severidade</strong> determina a ordenação do "pior impacto atual" no painel — menor = pior (1 = falha grave, 5 = operacional). Duas linhas podem compartilhar a mesma ordem.',

        'col_name'        => 'Nome',
        'col_description' => 'Descrição',
        'col_order'       => 'Ordem',
        'col_status'      => 'Status',
        'col_actions'     => 'Ações',
        'col_colour'      => 'Cor',
        'col_resolved'    => 'Resolvido',
        'col_default'     => 'Padrão',
        'col_severity'    => 'Severidade',

        'active'          => 'Ativo',
        'inactive'        => 'Inativo',
        'yes'             => 'Sim',
        'no'              => 'Não',
        'edit'            => 'Editar',
        'delete'          => 'Excluir',

        'kind_status'     => 'status',
        'kind_impact'     => 'nível de impacto',

        // Service modal
        'add_service'     => 'Adicionar serviço',
        'edit_service'    => 'Editar serviço',
        'field_name'      => 'Nome',
        'field_description' => 'Descrição',
        'field_order'     => 'Ordem de exibição',
        'field_active'    => 'Ativo',

        // Lookup modal (statuses + impact levels)
        'add_item'        => 'Adicionar item',
        'add_kind'        => 'Adicionar {kind}',
        'edit_kind'       => 'Editar {kind}',
        'field_colour'    => 'Cor',
        'field_resolved'  => 'Conta como resolvido',
        'resolved_help_html' => 'Incidentes neste status registram automaticamente o <code>resolved_datetime</code> e saem do painel ativo.',
        'field_severity'  => 'Ordem de severidade',
        'severity_help'   => '1 = pior (Falha grave). Maior = menos severo.',
        'field_default'   => 'Padrão',

        'cancel'          => 'Cancelar',
        'save'            => 'Salvar',
    ],

    'help' => [
        'page_title' => 'Guia de status dos serviços',
        'guide'      => 'Guia',

        'nav_overview'  => 'Visão geral',
        'nav_dashboard' => 'O painel de status',
        'nav_services'  => 'Gerenciando serviços',
        'nav_history'   => 'Histórico de incidentes',
        'nav_settings'  => 'Configurações',
        'nav_tips'      => 'Dicas rápidas',

        'hero_title' => 'Guia de status dos serviços',
        'hero_sub'   => 'Monitore seus serviços de TI, comunique incidentes e mantenha as partes interessadas informadas em tempo real.',

        // Section 1: Overview
        'overview_heading' => 'Visão geral',
        'overview_intro'   => 'O módulo Status dos serviços oferece uma visão centralizada da saúde de cada serviço de TI do qual sua organização depende. Quando algo dá errado, você pode registrar incidentes, atualizar os serviços afetados e manter os usuários informados durante todo o processo de resolução.',
        'feature_dashboard_title' => 'Painel de status',
        'feature_dashboard_desc'  => 'Veja a saúde atual de cada serviço de relance. Selos com código de cores mostram se cada serviço está operacional, degradado, em manutenção ou enfrentando uma falha.',
        'feature_incident_title'  => 'Acompanhamento de incidentes',
        'feature_incident_desc'   => 'Registre incidentes com títulos, atualizações de status e comentários. Vincule os serviços afetados a cada incidente para que todos saibam exatamente o que está impactado e por quê.',
        'feature_management_title' => 'Gerenciamento de serviços',
        'feature_management_desc'  => 'Configure seu catálogo de serviços nas configurações. Adicione serviços com nomes, descrições e ordem de exibição. Ative ou desative serviços conforme sua infraestrutura evolui.',
        'feature_comms_title' => 'Comunicação',
        'feature_comms_desc'  => 'Mantenha as partes interessadas informadas com atualizações de status em tempo real. Cada incidente carrega um status e um histórico de comentários para que os usuários possam acompanhar o progresso da resolução sem precisar cobrar a central de serviços.',

        // Section 2: Dashboard
        'dashboard_heading' => 'O painel de status',
        'dashboard_p1'      => 'O painel é a primeira coisa que você vê ao abrir o módulo Status dos serviços. Ele exibe uma grade de cartões de serviço, cada um mostrando o nome do serviço, uma breve descrição e um selo de impacto com código de cores refletindo seu pior status atual. Abaixo da grade fica a tabela de incidentes, listando todos os incidentes recentes e ativos.',
        'dashboard_p2_html' => 'Cada cartão de serviço reflete automaticamente o nível de impacto mais severo atribuído a ele por qualquer incidente ativo (não resolvido). Quando todos os incidentes que afetam um serviço são resolvidos, ele retorna a <strong>Operacional</strong>.',
        'status_levels'     => 'Níveis de status',
        'level_operational_name' => 'Operacional',
        'level_operational_desc' => 'O serviço está funcionando normalmente, sem problemas conhecidos. Este é o estado padrão para todos os serviços saudáveis.',
        'level_degraded_name'    => 'Desempenho degradado',
        'level_degraded_desc'    => 'O serviço está disponível, mas funcionando mais lentamente do que o esperado ou com funcionalidade reduzida. Os usuários podem notar atrasos.',
        'level_maintenance_name' => 'Em manutenção',
        'level_maintenance_desc' => 'Tempo de inatividade planejado ou janela de manutenção. O serviço pode ficar temporariamente indisponível enquanto o trabalho é realizado.',
        'level_outage_name'      => 'Falha grave',
        'level_outage_desc'      => 'O serviço está completamente indisponível. Este é o status mais severo e deve disparar uma investigação imediata.',
        'dashboard_tip'     => 'Os níveis de impacto são hierárquicos. Se um serviço estiver vinculado a vários incidentes ativos, o painel mostra o pior impacto. Por exemplo, um incidente marcando um serviço como Degradado e outro marcando-o como Falha grave resultará na exibição de Falha grave.',

        // Section 3: Managing services
        'services_heading_html' => 'Gerenciando serviços &amp; registrando incidentes',
        'services_intro'        => 'Os serviços são os blocos de construção da sua página de status. Cada um representa um serviço de TI, sistema ou componente de infraestrutura do qual seus usuários dependem. Quando algo dá errado, você cria um incidente e o vincula aos serviços afetados.',
        'add_incident_heading'  => 'Adicionando um novo incidente',
        'add_incident_step1_html' => '<strong>Clique em "Novo"</strong> no painel para abrir o formulário de incidente.',
        'add_incident_step2_html' => '<strong>Insira um título</strong> &mdash; uma descrição breve e clara do problema. Por exemplo: "Atrasos na entrega de e-mails" ou "Gateway de VPN inacessível".',
        'add_incident_step3_html' => '<strong>Defina o status</strong> &mdash; escolha Investigando, Identificado, Terceiros, Monitorando ou Resolvido. Comece com Investigando e atualize à medida que descobrir mais.',
        'add_incident_step4_html' => '<strong>Adicione um comentário</strong> &mdash; descreva o que se sabe até agora, quais ações estão sendo tomadas e quaisquer soluções de contorno disponíveis para os usuários.',
        'add_incident_step5_html' => '<strong>Vincule os serviços afetados</strong> &mdash; adicione um ou mais serviços e escolha o nível de impacto de cada um (Falha grave, Falha parcial, Degradado, Manutenção, Operacional ou Sem interrupção).',
        'add_incident_step6_html' => '<strong>Salvar</strong> &mdash; o incidente aparece na tabela e os cartões dos serviços afetados são atualizados imediatamente no painel.',
        'workflow_heading'  => 'Fluxo de trabalho de status do incidente',
        'workflow_investigating' => 'Investigando',
        'workflow_identified'    => 'Identificado',
        'workflow_monitoring'    => 'Monitorando',
        'workflow_resolved'      => 'Resolvido',
        'workflow_note_html'     => 'Use <strong>Terceiros</strong> quando a causa raiz estiver com um fornecedor ou provedor externo.',
        'services_tip'      => 'Você pode editar qualquer incidente clicando em seu título na tabela. Atualize o status, adicione novos comentários ou altere os serviços afetados conforme a situação evolui. Manter os incidentes atualizados é fundamental para uma comunicação transparente.',

        // Section 4: Incident history
        'history_heading' => 'Histórico de incidentes',
        'history_p1'      => 'A tabela de incidentes no painel mostra tanto os incidentes ativos quanto os resolvidos, oferecendo uma linha do tempo completa da saúde dos serviços. Cada linha exibe o título do incidente, o status atual, os serviços afetados com seus níveis de impacto e o horário da última atualização.',
        'history_field_title_html'    => '<strong>Título</strong> &mdash; um link clicável que abre o incidente para edição. Use títulos claros e descritivos para que o histórico seja fácil de examinar.',
        'history_field_status_html'   => '<strong>Status</strong> &mdash; selo com código de cores mostrando a fase atual da investigação (Investigando, Identificado, Terceiros, Monitorando ou Resolvido).',
        'history_field_affected_html' => '<strong>Serviços afetados</strong> &mdash; selos marcados mostrando cada serviço vinculado com a cor do seu nível de impacto. De relance, você consegue ver o que está impactado e com que gravidade.',
        'history_field_updated_html'  => '<strong>Atualizado</strong> &mdash; o horário da alteração mais recente. Incidentes resolvidos são estilizados com texto esmaecido para que os incidentes ativos se destaquem visualmente.',
        'history_p2'      => 'Os incidentes resolvidos permanecem visíveis na tabela como registro histórico. Isso facilita identificar problemas recorrentes, revisar como incidentes passados foram tratados e detectar padrões que possam apontar para problemas subjacentes.',
        'history_tip'     => 'Revisar regularmente seu histórico de incidentes ajuda a identificar serviços que são frequentemente interrompidos. Se o mesmo serviço aparece em vários incidentes, pode ser hora de investigar a causa raiz mais a fundo ou planejar uma atualização de infraestrutura.',

        // Section 5: Settings
        'settings_heading' => 'Configurações',
        'settings_p1'      => 'A página de Configurações é onde você cria e mantém seu catálogo de serviços. Todo serviço que aparece no painel de status precisa ser configurado aqui primeiro.',
        'settings_step1_html' => '<strong>Adicione um serviço</strong> &mdash; clique em "Adicionar" e forneça um nome (ex.: "E-mail", "VPN", "Sistema ERP") e uma descrição opcional explicando o que o serviço faz.',
        'settings_step2_html' => '<strong>Defina a ordem de exibição</strong> &mdash; o número de ordem controla onde o serviço aparece na grade do painel. Números menores aparecem primeiro, então coloque seus serviços mais críticos no topo.',
        'settings_step3_html' => '<strong>Alterne entre ativo/inativo</strong> &mdash; desativar um serviço o remove do painel sem excluí-lo. Isso é útil para serviços descomissionados ou sistemas sazonais.',
        'settings_step4_html' => '<strong>Editar ou excluir</strong> &mdash; use os botões de ação em cada linha para atualizar os detalhes do serviço ou removê-lo completamente. A edição é sempre preferível à exclusão, para que os vínculos históricos de incidentes permaneçam intactos.',
        'settings_tip'     => 'Pense no seu catálogo de serviços como a base da sua página de status. Dedique tempo para acertar os nomes e descrições &mdash; é isso que seus usuários e partes interessadas verão ao verificar a saúde do seu ambiente de TI.',

        // Section 6: Quick tips
        'tips_heading' => 'Dicas rápidas',
        'tip_communicate_title' => 'Comunique cedo',
        'tip_communicate_desc'  => 'Publique um incidente assim que souber que algo está errado, mesmo que ainda não tenha todos os detalhes. Reconhecer um problema rapidamente cria confiança com seus usuários.',
        'tip_update_title' => 'Atualize com frequência',
        'tip_update_desc'  => 'Atualizações de status regulares &mdash; mesmo que nada tenha mudado &mdash; mostram aos usuários que o problema está sendo trabalhado ativamente. O silêncio gera frustração e chamados de suporte.',
        'tip_review_title' => 'Revise padrões',
        'tip_review_desc'  => 'Verifique seu histórico de incidentes regularmente. Se o mesmo serviço continua aparecendo, isso pode apontar para um problema de infraestrutura mais profundo que vale a pena resolver de forma proativa.',
        'tip_maintenance_title' => 'Planeje a manutenção',
        'tip_maintenance_desc'  => 'Use o nível de impacto Manutenção para trabalhos planejados. Criar um incidente com antecedência permite que os usuários saibam sobre o tempo de inatividade programado antes que ele aconteça.',
    ],
];
