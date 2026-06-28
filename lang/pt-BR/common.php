<?php
/**
 * Português (Brasil) (pt-BR) — Common shared UI strings.
 * Falls back per-key to lang/en/common.php for anything missing here.
 */
return [
    // Shared AI provider/model/key panel (includes/ai_settings_panel.php),
    // reused by every module's AI settings tab.
    'ai' => [
        'provider'            => 'Provedor',
        'provider_anthropic'  => 'Anthropic (Claude)',
        'provider_openai'     => 'OpenAI (GPT)',
        'provider_openrouter' => 'OpenRouter (uma chave, vários modelos)',
        'openrouter_note'     => 'Com o OpenRouter, uma única chave alcança centenas de modelos. Observe que os prompts são roteados pelo serviço do OpenRouter.',
        'model'               => 'Modelo',
        'model_placeholder'   => 'Digite ou escolha um modelo…',
        'model_set'           => 'Modelo',
        'loading_models'      => 'Carregando lista de modelos…',
        'no_models'           => 'Nenhum modelo correspondente — você pode digitar qualquer ID de modelo',
        'openrouter_pricing'  => 'Preços exibidos por 1M de tokens (entrada / saída).',
        'models_stale'        => 'em cache',
        'api_key'             => 'Chave de API',
        'api_key_help'        => 'Armazenada de forma criptografada. Deixe em branco para manter a chave salva.',
        'api_key_set'         => 'Uma chave está salva. Deixe em branco para mantê-la.',
        'verify_ssl'          => 'Verificar certificado SSL',
        'verify_ssl_help'     => 'Mantenha ativado em produção. Desative apenas se o seu servidor não conseguir validar o certificado do provedor.',
        'save'                => 'Salvar',
        'test'                => 'Testar',
        'testing'             => 'Testando…',
        'test_ok'             => 'Conexão OK',
        'test_failed'         => 'Falha no teste',
        'saved'               => 'Salvo',
        'save_failed'         => 'Falha ao salvar',
    ],

    // Left-panel visibility preference — shared labels (mirrors lang/en/common.php).
    'left_panel' => [
        'tab'        => 'Painel esquerdo',
        'visibility' => 'Visibilidade',
        'always'     => 'Sempre visível',
        'hover'      => 'Exibir ao passar o mouse',
    ],

    'save'         => 'Salvar',
    'cancel'       => 'Cancelar',
    'delete'       => 'Excluir',
    'add'          => 'Adicionar',
    'edit'         => 'Editar',
    'close'        => 'Fechar',
    'copy'         => 'Copiar',
    'copied'       => 'Copiado',
    'retry'        => 'Tentar novamente',
    'export'       => 'Exportar',
    'open'         => 'Abrir',
    'apply'        => 'Aplicar',

    'yes'          => 'Sim',
    'no'           => 'Não',
    'ok'           => 'OK',
    'loading'      => 'Carregando…',
    'saving'       => 'Salvando…',
    'saved'        => 'Salvo',
    'unsaved'      => 'Não salvo',
    'unsaved_changes' => 'Alterações não salvas',
    'failed'       => 'Falhou',

    'just_now'     => 'agora mesmo',
    'today'        => 'Hoje',
    'yesterday'    => 'Ontem',

    'required'     => 'Obrigatório',
    'optional'     => 'Opcional',
    'select_one'   => 'Selecionar…',
    'search'       => 'Pesquisar',

    'error_generic'       => 'Algo deu errado.',
    'error_network'       => 'Erro de rede',
    'error_not_logged_in' => 'Você precisa estar conectado.',

    // Home / landing page (index.php)
    'home' => [
        'header_title'     => 'Central de Serviços',
        'browser_title'    => 'Central de Serviços - ITSM',
        'welcome_heading'  => 'O que você gostaria de fazer?',
        'welcome_subtitle' => 'Selecione um módulo para começar',
        'footer'           => 'Central de Serviços ITSM',
    ],

    // Waffle module-switcher panel (shared header)
    'waffle' => [
        'title' => 'Módulos ITSM',
    ],

    // Per-module display name + one-line description.
    'modules' => [
        'watchtower'     => ['name' => 'Vigia',         'description' => 'Painel unificado de atenção para todos os módulos'],
        'tickets'        => ['name' => 'Tickets',       'description' => 'Gerencie solicitações de suporte, e-mails e problemas de usuários'],
        'assets'         => ['name' => 'Ativos',        'description' => 'Acompanhe ativos de TI e atribuições a usuários'],
        'knowledge'      => ['name' => 'Conhecimento',  'description' => 'Crie e consulte artigos da base de conhecimento'],
        'changes'        => ['name' => 'Mudanças',      'description' => 'Planeje, acompanhe e gerencie mudanças de TI'],
        'problems'       => ['name' => 'Gerenciamento de problemas', 'name_short' => 'Problemas', 'description' => 'Acompanhe a causa raiz por trás de incidentes recorrentes'],
        'calendar'       => ['name' => 'Calendário',    'description' => 'Acompanhe eventos, prazos e agendas'],
        'morning-checks' => ['name' => 'Verificações',  'description' => 'Registre verificações diárias de infraestrutura'],
        'reporting'      => ['name' => 'Relatórios',    'description' => 'Visualize logs do sistema e análises'],
        'software'       => ['name' => 'Software',      'description' => 'Consulte o inventário de software e licenciamento'],
        'forms'          => ['name' => 'Formulários',   'description' => 'Crie formulários personalizados e veja envios'],
        'contracts'      => ['name' => 'Contratos',     'description' => 'Gerencie fornecedores, contatos e contratos'],
        'service-status' => ['name' => 'Status',        'description' => 'Monitore a saúde dos serviços e registre incidentes'],
        'wiki'           => ['name' => 'Wiki',          'description' => 'Consulte a documentação do código gerada automaticamente'],
        'lms'            => ['name' => 'LMS',           'description' => 'Sistema de gestão de aprendizagem com player SCORM'],
        'process-mapper' => ['name' => 'Processos',     'description' => 'Ferramenta visual de fluxogramas e mapeamento de processos'],
        'tasks'          => ['name' => 'Tarefas',       'description' => 'Quadro Kanban e visão em lista para acompanhar tarefas'],
        'cmdb'           => ['name' => 'CMDB',          'description' => 'Banco de Dados de Gerenciamento de Configuração'],
        'network-mapper' => ['name' => 'Rede',          'description' => 'Projete e documente diagramas de rede'],
        'system'         => ['name' => 'Sistema',       'description' => 'Administração e configuração do sistema'],
        'workflow'       => ['name' => 'Fluxos',        'description' => 'Automação entre módulos — gatilhos, condições, ações'],
    ],

    // Account / user menu in the shared header
    'account' => [
        'mail_check'      => 'Verificar novos e-mails',
        'change_password' => 'Alterar senha',
        'mfa'             => 'Autenticação multifator',
        'trusted_device'  => 'Dispositivo confiável',
        'logout'          => 'Sair',
        'logout_confirm'  => 'Tem certeza de que deseja sair?',
        'badge_off'       => 'Desligado',
        'badge_on'        => 'Ligado',
        'preferences'     => 'Preferências',
    ],

    // Change-password modal
    'password_modal' => [
        'title'            => 'Alterar senha',
        'current_password' => 'Senha atual',
        'new_password'     => 'Nova senha',
        'confirm_password' => 'Confirmar nova senha',
        'submit'           => 'Alterar senha',
    ],

    // MFA modal
    'mfa_modal' => [
        'title' => 'Autenticação multifator',
    ],

    // Calendar primitives — months, weekdays, navigation.
    'calendar' => [
        'previous' => 'Anterior',
        'next'     => 'Próximo',
        'today'    => 'Hoje',

        'view_month' => 'Mês',
        'view_week'  => 'Semana',
        'view_day'   => 'Dia',

        'months' => [
            'january'   => 'janeiro',
            'february'  => 'fevereiro',
            'march'     => 'março',
            'april'     => 'abril',
            'may'       => 'maio',
            'june'      => 'junho',
            'july'      => 'julho',
            'august'    => 'agosto',
            'september' => 'setembro',
            'october'   => 'outubro',
            'november'  => 'novembro',
            'december'  => 'dezembro',
        ],

        'weekdays' => [
            'monday'    => 'segunda-feira',
            'tuesday'   => 'terça-feira',
            'wednesday' => 'quarta-feira',
            'thursday'  => 'quinta-feira',
            'friday'    => 'sexta-feira',
            'saturday'  => 'sábado',
            'sunday'    => 'domingo',
        ],
    ],
];
