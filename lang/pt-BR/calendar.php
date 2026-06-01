<?php
/**
 * Português (Brasil) (pt-BR) — Calendar module strings.
 * Missing keys fall back to lang/en/calendar.php per-key.
 */
return [
    'title' => 'Calendário',

    'nav' => [
        'calendar' => 'Calendário',
        'table'    => 'Tabela',
        'settings' => 'Configurações',
        'help'     => 'Ajuda',
    ],

    'sidebar' => [
        'new_event'   => 'Novo evento',
        'categories'  => 'Categorias',
        'none'        => 'Nenhuma categoria encontrada',
    ],

    'event' => [
        'modal_new'      => 'Novo evento',
        'modal_edit'     => 'Editar evento',
        'title'          => 'Título',
        'title_ph'       => 'Título do evento...',
        'category'       => 'Categoria',
        'category_none'  => '-- Selecionar categoria --',
        'start_date'     => 'Data de início',
        'start_time'     => 'Hora de início',
        'end_date'       => 'Data de término',
        'end_time'       => 'Hora de término',
        'all_day'        => 'Evento de dia inteiro',
        'location'       => 'Local',
        'location_ph'    => 'Local (opcional)',
        'description'    => 'Descrição',
        'description_ph' => 'Descrição (opcional)',
        'delete'         => 'Excluir',
        'cancel'         => 'Cancelar',
        'save'           => 'Salvar',
        'edit'           => 'Editar',
        'delete_confirm' => 'Tem certeza de que deseja excluir este evento?',
        'title_required' => 'Informe um título para o evento',
        'start_required' => 'Selecione uma data de início',
    ],

    'table' => [
        'start_required' => 'A data/hora de início é obrigatória',
        'save_failed'    => 'Falha ao salvar',
        'col_title'       => 'Título',
        'col_category'    => 'Categoria',
        'col_start'       => 'Início',
        'col_end'         => 'Término',
        'col_all_day'     => 'Dia inteiro',
        'col_location'    => 'Local',
        'col_description' => 'Descrição',
        'col_created_by'  => 'Criado por',
        'col_created'     => 'Criado em',
    ],

    'settings' => [
        'title'           => 'Configurações do calendário',
        'tab_categories'  => 'Categorias',
        'heading'         => 'Categorias de eventos',
        'add'             => 'Adicionar',
        'intro'           => 'Gerencie as categorias usadas para organizar os eventos do calendário. Cada categoria pode ter uma cor personalizada para fácil identificação.',
        'col_name'        => 'Nome',
        'col_description' => 'Descrição',
        'col_status'      => 'Status',
        'active'          => 'Ativa',
        'inactive'        => 'Inativa',
        'edit'            => 'Editar',
        'delete'          => 'Excluir',
        'empty'           => 'Nenhuma categoria ainda. Clique em <strong>Adicionar</strong> para criar uma.',
        'load_error'      => 'Erro ao carregar categorias',

        'modal_add'       => 'Adicionar categoria',
        'modal_edit'      => 'Editar categoria',
        'modal_name'      => 'Nome',
        'modal_name_ph'   => 'ex.: Expiração de certificado',
        'modal_description'    => 'Descrição',
        'modal_description_ph' => 'Descrição opcional...',
        'modal_colour'    => 'Cor',
        'modal_active'    => 'Ativa',
        'cancel'          => 'Cancelar',
        'save'            => 'Salvar',
        'name_required'   => 'Informe um nome para a categoria',

        'delete_title'    => 'Excluir categoria',
        'delete_confirm'  => 'Tem certeza de que deseja excluir "{name}"? Esta ação não pode ser desfeita.',
        'delete_this'     => 'esta categoria',
    ],

    'toast' => [
        'saved'         => 'Salvo',
        'deleted'       => 'Excluído',
        'save_failed'   => 'Falha ao salvar',
        'delete_failed' => 'Falha ao excluir',
    ],

    'help' => [
        'page_title'  => 'Guia do calendário',
        'guide'       => 'Guia',
        'hero_title'  => 'Guia do calendário',
        'hero_sub'    => 'Acompanhe certificados, contratos, janelas de manutenção e eventos recorrentes &mdash; tudo em um só lugar.',

        'nav_overview'  => 'Visão geral',
        'nav_views'     => 'Visualizações do calendário',
        'nav_creating'  => 'Criando eventos',
        'nav_categories'=> 'Categorias de eventos',
        'nav_settings'  => 'Configurações',
        'nav_tips'      => 'Dicas rápidas',

        // Section 1 — Overview
        'overview_heading' => 'Visão geral',
        'overview_intro'   => 'O módulo Calendário oferece à sua equipe de TI uma linha do tempo compartilhada para tudo o que importa. Em vez de depender de planilhas ou lembretes pessoais, você pode acompanhar datas de expiração de certificados, renovações de contratos, janelas de manutenção programada e eventos da equipe em um único calendário codificado por cores que todos na central de serviços podem ver.',
        'feature_tracking_title' => 'Acompanhamento de eventos',
        'feature_tracking_desc'  => 'Crie eventos com títulos, datas, horários, locais e descrições. Cada evento fica visível para a equipe, de modo que nada passe despercebido.',
        'feature_views_title'    => 'Múltiplas visualizações',
        'feature_views_desc'     => 'Alterne entre as visualizações de mês, semana e dia para obter o nível de detalhe necessário. A visualização de mês mostra um panorama geral; as de semana e dia mostram horários precisos.',
        'feature_categories_title' => 'Categorias',
        'feature_categories_desc'  => 'Organize os eventos em categorias codificadas por cores, como certificados, contratos, manutenção e reuniões. Filtre o calendário para mostrar apenas o que interessa a você.',
        'feature_scheduling_title' => 'Agendamento',
        'feature_scheduling_desc'  => 'Planeje janelas de manutenção, defina eventos de dia inteiro para prazos e agende trabalhos recorrentes. O calendário ajuda sua equipe a se coordenar e evitar conflitos.',

        // Section 2 — Views
        'views_heading' => 'Visualizações do calendário',
        'views_intro'   => 'O calendário oferece três visualizações para que você possa aproximar ou afastar a visão conforme a sua necessidade. Alterne entre elas usando os botões de alternância no canto superior direito do cabeçalho do calendário.',
        'views_month_title' => 'Visualização de mês',
        'views_month_desc'  => 'A visualização padrão. Mostra uma grade de mês inteiro com os eventos exibidos como barras coloridas em cada dia. Ideal para ter um panorama do que está por vir em toda a equipe.',
        'views_week_title'  => 'Visualização de semana',
        'views_week_desc'   => 'Exibe sete dias com horários por hora. Os eventos são posicionados de acordo com seus horários de início e término, facilitando a identificação de conflitos de agendamento.',
        'views_day_title'   => 'Visualização de dia',
        'views_day_desc'    => 'Concentra-se em um único dia com detalhamentos por hora. Use esta visualização quando precisar ver exatamente o que está acontecendo hora a hora durante um dia movimentado.',
        'views_nav'         => 'Use as setas de navegação ao lado do título de mês/semana/dia para avançar e retroceder no tempo. O botão <strong>Hoje</strong> leva você diretamente de volta à data atual, não importa o quão longe você tenha navegado.',
        'views_flow_today'  => 'Botão Hoje',
        'views_flow_nav'    => 'Navegar anterior/próximo',
        'views_flow_choose' => 'Escolher visualização',
        'views_flow_click'  => 'Clicar no evento',
        'views_tip'         => 'Clique em qualquer evento do calendário para abrir um pop-up de visualização rápida mostrando o título, o horário, o local e a descrição. A partir daí, você pode abrir o formulário completo de edição.',

        // Section 3 — Creating events
        'creating_heading' => 'Criando eventos',
        'creating_intro'   => 'Adicionar eventos ao calendário é simples. Clique no botão <strong>+ Novo evento</strong> na barra lateral para abrir o formulário de evento. Preencha os detalhes e salve &mdash; o evento aparece no calendário imediatamente.',
        'creating_step1'   => '<strong>Clique em + Novo evento</strong> &mdash; o botão fica na barra lateral do calendário, à esquerda. Isso abre o modal de criação de evento.',
        'creating_step2'   => '<strong>Informe um título</strong> &mdash; dê ao evento um nome claro e descritivo. Por exemplo: "Renovação de certificado SSL &mdash; webserver01" ou "Janela mensal de aplicação de patches".',
        'creating_step3'   => '<strong>Escolha uma categoria</strong> &mdash; selecione na lista suspensa para codificar o evento por cor. As categorias são configuradas nas Configurações e ajudam você a filtrar o calendário depois.',
        'creating_step4'   => '<strong>Defina as datas e os horários</strong> &mdash; escolha uma data de início e, opcionalmente, uma data de término. Adicione horários de início e término para eventos com hora marcada, ou marque "Evento de dia inteiro" para prazos e entradas de dia inteiro.',
        'creating_step5'   => '<strong>Adicione local e descrição</strong> &mdash; opcionalmente, especifique onde o evento ocorre e adicione notas. Esses detalhes são mostrados no pop-up de visualização rápida quando alguém clica no evento.',
        'creating_step6'   => '<strong>Salve</strong> &mdash; clique em Salvar e o evento é criado. Ele aparece no calendário imediatamente, codificado pela cor da sua categoria.',
        'creating_tip'     => 'Para editar um evento existente, clique nele no calendário para abrir o pop-up e, em seguida, clique em <strong>Editar</strong>. O mesmo formulário abre preenchido com os detalhes atuais do evento. Você também pode excluir eventos a partir do formulário de edição.',

        // Section 4 — Categories
        'categories_heading' => 'Categorias de eventos',
        'categories_intro'   => 'As categorias são a espinha dorsal da organização do calendário. Cada categoria tem um nome e uma cor, de modo que os eventos sejam reconhecíveis instantaneamente, num relance. A barra lateral mostra todas as categorias disponíveis com caixas de seleção &mdash; desmarque uma categoria para ocultar esses eventos do calendário.',
        'categories_certificates' => '<strong>Certificados</strong> &mdash; acompanhe datas de expiração de certificados SSL/TLS, certificados de assinatura de código e outras credenciais que precisam de renovação periódica',
        'categories_contracts'    => '<strong>Contratos</strong> &mdash; registre datas de renovação de contratos de fornecedores, expiração de licenças e marcos de revisão de SLA para que nada vença inesperadamente',
        'categories_maintenance'  => '<strong>Manutenção</strong> &mdash; agende janelas de manutenção planejada para servidores, equipamentos de rede e infraestrutura. Sua equipe e as partes interessadas podem ver exatamente quando a indisponibilidade é esperada',
        'categories_meetings'     => '<strong>Reuniões</strong> &mdash; registre stand-ups de equipe, reuniões do CAB, chamadas com fornecedores e outros compromissos recorrentes relevantes para as operações de TI',
        'categories_custom'       => '<strong>Categorias personalizadas</strong> &mdash; adicione suas próprias categorias nas Configurações para se adequar ao fluxo de trabalho da sua equipe. Adições comuns incluem "Implantações", "Auditorias" e "Treinamento"',
        'categories_filtering'    => 'A filtragem é aplicada em tempo real. Quando você desmarca uma categoria na barra lateral, os eventos dessa categoria são ocultados imediatamente, sem recarregar a página. Marque-a novamente para trazê-los de volta.',
        'categories_tip'          => 'A codificação por cores funciona em todas as três visualizações. Na visualização de mês, os eventos aparecem como barras coloridas. Nas visualizações de semana e dia, os eventos são exibidos como blocos coloridos posicionados no horário correto.',

        // Section 5 — Settings
        'settings_heading' => 'Configurações',
        'settings_intro'   => 'A página de Configurações permite que você configure como o calendário funciona para a sua equipe. Acesse-a clicando em <strong>Configurações</strong> na barra de navegação no topo do módulo de calendário.',
        'settings_step1'   => '<strong>Gerencie categorias</strong> &mdash; adicione, edite ou remova categorias de eventos. Cada categoria tem um nome e uma cor. As alterações entram em vigor imediatamente em todo o calendário para todos os usuários.',
        'settings_step2'   => '<strong>Defina as cores</strong> &mdash; escolha uma cor para cada categoria usando o seletor de cores. Escolha cores distintas para que os eventos sejam fáceis de diferenciar em um calendário movimentado.',
        'settings_step3'   => '<strong>Renomeie categorias</strong> &mdash; clique no nome de uma categoria para editá-lo. Os eventos existentes atribuídos a essa categoria são atualizados automaticamente.',
        'settings_step4'   => '<strong>Exclua categorias</strong> &mdash; remova as categorias de que você não precisa mais. Os eventos de uma categoria excluída não são removidos &mdash; eles permanecem no calendário sem atribuição de categoria.',
        'settings_tip'     => 'Mantenha sua lista de categorias enxuta. Ter categorias demais pode deixar a barra lateral desorganizada e a codificação por cores mais difícil de ler. Procure ter de 5 a 10 categorias bem definidas que atendam às necessidades da sua equipe.',

        // Section 6 — Quick tips
        'tips_heading'        => 'Dicas rápidas',
        'tips_maintenance_title' => 'Janelas de manutenção',
        'tips_maintenance_desc'  => 'Crie eventos de dia inteiro ou blocos com hora marcada para a manutenção planejada. Inclua na descrição os sistemas afetados para que os analistas possam verificar rapidamente se uma indisponibilidade é esperada.',
        'tips_certificates_title' => 'Renovações de certificados',
        'tips_certificates_desc'  => 'Adicione eventos 30 dias antes de cada certificado expirar. Isso dá à sua equipe tempo suficiente para renovar sem arriscar uma indisponibilidade por causa de um certificado expirado.',
        'tips_contracts_title'   => 'Acompanhamento de contratos',
        'tips_contracts_desc'    => 'Registre as datas de renovação de contratos como eventos de dia inteiro. Adicione o nome do fornecedor e o valor do contrato na descrição para que a informação esteja à mão na hora de negociar.',
        'tips_filters_title'     => 'Use os filtros de categoria',
        'tips_filters_desc'      => 'Quando o calendário ficar movimentado, desmarque as categorias de que você não precisa. Por exemplo, oculte as reuniões quando você estiver interessado apenas nas próximas janelas de manutenção.',
    ],
];
