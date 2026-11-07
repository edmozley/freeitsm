<?php
/**
 * FreeITSM — setup strings (pt-BR).
 *
 * Keys mirror lang/en/setup.php exactly. A key absent here falls back to
 * English at runtime, so this file may be incomplete without breaking
 * anything. Check coverage with: php scripts/i18n_audit.php pt-BR
 *
 * ⚠️ Placeholders like {name} and %d are substituted at runtime — printf
 * tokens substitute BY POSITION, so their order must match English.
 */

return [
    'title' => 'Instalação do FreeITSM',
    'heading' => 'Verificação da instalação',
    'storage' => [
        'heading' => 'Pare — os arquivos que você enviar serão excluídos a cada atualização',
        'explain' => 'O FreeITSM está sendo executado no Docker, e algumas das pastas onde ele armazena os arquivos enviados ficam dentro do contêiner, e não em um volume do Docker. Atualizar o FreeITSM reconstrói o contêiner, e tudo o que está dentro dele é descartado quando isso acontece. Seu banco de dados fica em um volume e sobreviverá, então depois seus anexos ainda apareceriam listados enquanto os arquivos em si teriam desaparecido. Dois minutos agora evitam isso permanentemente.',
        'encryption_key' => 'Isso inclui sua chave de criptografia. Se ela for perdida, as senhas de caixa postal e credenciais de integração salvas não são danificadas, mas nunca mais poderão ser lidas, e cada uma terá que ser digitada novamente à mão.',
        'existing_install' => 'Esta instalação já está em uso, então não basta adicionar isso e reconstruir. Primeiro copie as pastas para fora do contêiner em execução — adicionar um volume não resgata arquivos que já estão dentro dele. Sistema → Ferramentas de Depuração → D013 traz os comandos na ordem certa.',
        'step1' => '1. Crie um novo arquivo chamado docker-compose.override.yml, na mesma pasta que docker-compose.yml, contendo exatamente isto. Não edite o docker-compose.yml em si — esse arquivo é substituído quando você atualiza, e suas alterações seriam perdidas ou bloqueariam a atualização. O FreeITSM lê o arquivo de substituição automaticamente.',
        'step2' => '2. Aplique-o:',
        'foot' => 'Depois recarregue esta página — esta mensagem desaparecerá. Nada mais precisa mudar, e fazer isso agora não custa nada, porque ainda não há nada armazenado nessas pastas.',
        'foot_in_use' => 'Depois recarregue esta página — esta mensagem desaparecerá. Não pule a etapa de copiar os arquivos para fora, acima: já existem arquivos nessas pastas, e reconstruir sem copiá-los antes vai excluí-los definitivamente.',
    ],
    'summary' => [
        'passed' => '{n} aprovado(s)',
        'warning' => '{n} aviso',
        'warnings' => '{n} avisos',
        'failed' => '{n} falhou(falharam)',
    ],
    'checks' => [
        'config' => 'config.php',
        'db_config' => 'db_config.php',
        'db_connection' => 'Conexão com o banco de dados',
        'encryption_key' => 'Chave de criptografia',
        'ssl_verify' => 'Verificação de certificado HTTPS',
        'ca_bundle_ini' => 'Pacote CA no php.ini',
        'display_errors' => 'Exibição de erros',
        'php_version' => 'Versão do PHP',
        'php_extension' => 'Extensão do PHP: {ext}',
        'php_extension_optional' => 'Extensão do PHP: {ext} (opcional)',
        'storage_persistence' => 'Persistência de armazenamento (Docker)',
    ],
    'detail' => [
        'found' => 'Encontrado',
        'storage_persisted' => 'Todas as pastas que guardam arquivos enviados estão em um armazenamento que sobrevive a uma reconstrução',
        'storage_at_risk' => 'NÃO estão em um volume do Docker e são esvaziadas a cada reconstrução: {dirs} — adicione um volume para cada uma delas agora, antes que algo seja armazenado nelas',
        'storage_at_risk_masked' => '{n} pastas com arquivos enviados não sobreviveriam a uma atualização',
        'config_not_found' => 'Não encontrado — copie config.php para a raiz da aplicação',
        'db_config_not_found' => 'Não encontrado em: {path}',
        'db_config_path_unset' => 'Variável $db_config_path não definida em config.php',
        'db_connected' => 'Conectado (driver: {driver})',
        'db_constants_undefined' => 'Constantes do banco de dados não definidas — verifique db_config.php',
        'encryption_key_missing' => 'Não encontrada em: {path} — necessária para criptografar configurações sensíveis',
        'encryption_key_undefined' => 'ENCRYPTION_KEY_PATH não definida em includes/encryption.php',
        'ssl_enabled' => 'Ativada',
        'ssl_verified' => 'Ativada e funcionando — uma requisição HTTPS de teste teve o certificado verificado (pacote CA: {bundle})',
        'ssl_broken' => 'Ativada, mas o servidor não conseguiu verificar um certificado — chamadas HTTPS de saída (e-mail, IA, webhooks, login) irão falhar. Solução mais simples: coloque um arquivo cacert.pem na pasta includes/ da aplicação (baixe de https://curl.se/ca/cacert.pem) — sem alterar o php.ini. Erro: {error}',
        'ssl_untested' => 'Ativada, mas não foi possível concluir uma requisição de teste (sem rede de saída?), então a verificação não pôde ser confirmada. Erro: {error}',
        'ssl_bundle_system' => 'armazenamento do sistema',
        'help_link' => 'Como corrigir isto — guia de certificados HTTPS →',
        'ca_ini_status' => 'curl.cainfo: {curl} · openssl.cafile: {ossl}',
        'ca_ini_none' => 'não definido',
        'ca_ini_missing' => '{path} (arquivo ausente!)',
        'ca_ini_note_fix' => ' — corrija o caminho ou comente a configuração no php.ini.',
        'ca_ini_note_fallback' => ' — opcional: o FreeITSM usa sua lista CA integrada (Windows) ou o armazenamento de confiança do SO (Linux). Observação: isto reflete o PHP do servidor web; o worker em segundo plano usa um php.ini de CLI separado.',
        'ssl_disabled' => 'Desativada — ative para produção (defina SSL_VERIFY_PEER como true em config.php)',
        'ssl_undefined' => 'SSL_VERIFY_PEER não definida em config.php',
        'display_errors_enabled' => 'Ativada — desative para produção (defina display_errors como 0 em config.php)',
        'display_errors_disabled' => 'Desativada',
        'php_version_ok' => '{version}',
        'php_version_too_low' => '{version} — é necessário PHP 8.1 ou superior',
        'php_version_eol' => '{version} — ainda é compatível, mas esta versão não recebe atualizações de segurança desde que chegou ao fim da vida útil. Recomenda-se PHP 8.3 ou 8.4.',
        'extension_loaded' => 'Carregada',
        'extension_not_loaded' => 'Não carregada — ative em php.ini',
        'pdo_mysql_not_loaded' => 'Não carregada — ative pdo_mysql em php.ini',
        'db_config_not_found_masked' => 'Não encontrado no caminho definido em config.php',
        'ssl_verified_masked' => 'Ativada e funcionando — uma requisição HTTPS de teste teve o certificado verificado',
        'ssl_broken_masked' => 'Ativada, mas o servidor não conseguiu verificar um certificado — chamadas HTTPS de saída (e-mail, IA, webhooks, login) irão falhar. Entre como administrador para ver o erro.',
        'ssl_untested_masked' => 'Ativada, mas não foi possível concluir uma requisição de teste, então a verificação não pôde ser confirmada.',
        'db_error_masked' => 'Não foi possível conectar — entre como administrador para ver o erro completo',
        'encryption_key_missing_masked' => 'Não encontrada — necessária para criptografar configurações sensíveis',
        'ca_ini_masked_ok' => 'Configurado',
        'ca_ini_masked_broken' => 'Definido, mas apontando para um arquivo que não existe — corrija o caminho ou comente a configuração no php.ini.',
        'php_version_ok_masked' => 'Atende ao requisito',
        'php_version_too_low_masked' => 'Muito antiga — é necessário PHP 8.1 ou superior',
        'php_version_eol_masked' => 'Compatível, mas esta versão chegou ao fim do ciclo de vida e não recebe mais atualizações de segurança. Entre como administrador para ver a versão.',
    ],
    'locked' => [
        'notice' => 'A instalação já está concluída, portanto caminhos, erros de conexão e credenciais estão ocultos. Entre como administrador para ver os detalhes completos.',
    ],
    'db_verify' => [
        'heading' => 'Verificação do banco de dados',
        'intro' => 'Verifique e crie automaticamente tabelas ou colunas ausentes no banco de dados.',
        'run' => 'Executar',
    ],
    'login' => [
        'heading' => 'Login padrão',
        'intro' => 'Uma conta de administrador padrão é criada quando você executa a Verificação do banco de dados.',
        'username' => 'Usuário:',
        'password' => 'Senha:',
    ],
    'footer' => [
        'warning' => 'Quando o seu sistema estiver em produção, exclua a pasta {folder} por segurança.',
        'signature' => 'Verificação da instalação do FreeITSM',
    ],
    'js' => [
        'running' => 'Executando...',
        'run' => 'Executar',
        'tables_checked' => '{n} tabelas verificadas:',
        'ok' => '{n} OK',
        'created' => '{n} criada(s)',
        'updated' => '{n} atualizada(s)',
        'errors' => '{n} erro(s)',
        'unknown_error' => 'Erro desconhecido',
        'verify_failed' => 'Falha ao executar a verificação do BD: {error}',
    ],
];
