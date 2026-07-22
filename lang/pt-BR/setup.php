<?php
/**
 * Português (Brasil) (pt-BR) — Setup installer.
 * Falls back per-key to lang/en/setup.php for anything missing here.
 */
return [
    'title'   => 'Instalação do FreeITSM',
    'heading' => 'Verificação da instalação',

    'summary' => [
        'passed'   => '{n} aprovado(s)',
        'warning'  => '{n} aviso',
        'warnings' => '{n} avisos',
        'failed'   => '{n} falhou(falharam)',
    ],

    'checks' => [
        'config'         => 'config.php',
        'db_config'      => 'db_config.php',
        'db_connection'  => 'Conexão com o banco de dados',
        'encryption_key' => 'Chave de criptografia',
        'ssl_verify'     => 'Verificação de certificado HTTPS',
        'display_errors' => 'Exibição de erros',
        'php_version'    => 'Versão do PHP',
        'php_extension'  => 'Extensão do PHP: {ext}',
        'php_extension_optional' => 'Extensão do PHP: {ext} (opcional)',
    ],

    'detail' => [
        'found'                    => 'Encontrado',
        'config_not_found'         => 'Não encontrado — copie config.php para a raiz da aplicação',
        'db_config_not_found'      => 'Não encontrado em: {path}',
        'db_config_path_unset'     => 'Variável $db_config_path não definida em config.php',
        'db_connected'             => 'Conectado (driver: {driver})',
        'db_constants_undefined'   => 'Constantes do banco de dados não definidas — verifique db_config.php',
        'encryption_key_missing'   => 'Não encontrada em: {path} — necessária para criptografar configurações sensíveis',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH não definida em includes/encryption.php',
        'ssl_enabled'              => 'Ativada',
        'ssl_verified'             => 'Ativada e funcionando — uma requisição HTTPS de teste teve o certificado verificado (pacote CA: {bundle})',
        'ssl_broken'               => 'Ativada, mas o servidor não conseguiu verificar um certificado — chamadas HTTPS de saída (e-mail, IA, webhooks, login) irão falhar. Configure um pacote CA: consulte a página de ajuda Certificados HTTPS. Erro: {error}',
        'ssl_untested'             => 'Ativada, mas não foi possível concluir uma requisição de teste (sem rede de saída?), então a verificação não pôde ser confirmada. Erro: {error}',
        'ssl_bundle_system'        => 'armazenamento do sistema',
        'help_link'                => 'Como corrigir isto — guia de certificados HTTPS →',
        'ssl_disabled'             => 'Desativada — ative para produção (defina SSL_VERIFY_PEER como true em config.php)',
        'ssl_undefined'            => 'SSL_VERIFY_PEER não definida em config.php',
        'display_errors_enabled'   => 'Ativada — desative para produção (defina display_errors como 0 em config.php)',
        'display_errors_disabled'  => 'Desativada',
        'php_version_ok'           => '{version}',
        'php_version_too_low'      => '{version} — é necessário PHP 7.4 ou superior',
        'php_version_eol'          => '{version} — ainda é compatível, mas esta versão não recebe atualizações de segurança desde que chegou ao fim da vida útil. Recomenda-se PHP 8.3 ou 8.4.',
        'extension_loaded'         => 'Carregada',
        'extension_not_loaded'     => 'Não carregada — ative em php.ini',
        'pdo_mysql_not_loaded'     => 'Não carregada — ative pdo_mysql em php.ini',
        'imap_not_loaded'          => 'Não carregada — necessária apenas para caixas de correio IMAP/SMTP básicas. O PHP 8.4 não inclui mais esta extensão; instale-a via PECL se você usar uma.',
    ],

    'db_verify' => [
        'heading' => 'Verificação do banco de dados',
        'intro'   => 'Verifique e crie automaticamente tabelas ou colunas ausentes no banco de dados.',
        'run'     => 'Executar',
    ],

    'login' => [
        'heading'  => 'Login padrão',
        'intro'    => 'Uma conta de administrador padrão é criada quando você executa a Verificação do banco de dados.',
        'username' => 'Usuário:',
        'password' => 'Senha:',
    ],

    'footer' => [
        'warning'   => 'Quando o seu sistema estiver em produção, exclua a pasta {folder} por segurança.',
        'signature' => 'Verificação da instalação do FreeITSM',
    ],

    'js' => [
        'running'        => 'Executando...',
        'run'            => 'Executar',
        'tables_checked' => '{n} tabelas verificadas:',
        'ok'             => '{n} OK',
        'created'        => '{n} criada(s)',
        'updated'        => '{n} atualizada(s)',
        'errors'         => '{n} erro(s)',
        'unknown_error'  => 'Erro desconhecido',
        'verify_failed'  => 'Falha ao executar a verificação do BD: {error}',
    ],
];
