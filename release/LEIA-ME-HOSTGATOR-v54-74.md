# ClyoNutri II — v54.74 — atualização completa para HostGator

Origem: codebase canônica `/Users/moliveira/Nutri_II`, com as alterações locais reconciliadas. Pacote completo de runtime para atualizar uma instalação existente v54.71, v54.72 ou v54.73. Não é um pacote parcial e não realiza deploy automático.

## Atualização no cPanel

1. Faça backup dos arquivos atuais e exporte o banco pelo phpMyAdmin. Preserve principalmente `.env`, `storage/`, uploads e sessões.
2. Abra uma janela de manutenção. No phpMyAdmin, execute **somente** `database/manual/ni2_878_subscription_activation_mysql57.sql` contra o banco correto. O SQL é idempotente e cria a tabela de pedidos de ativação manual de assinatura. Não execute `database/instalacao_mysql8.sql` em um banco de produção existente.
3. Envie `clyonutri-v54-74-hostgator.zip` para a mesma raiz da instalação atual (a pasta que contém `.env`, `public`, `src`, `vendor` e `storage`) e extraia por cima. Não apague a pasta atual. Inclua os arquivos ocultos `.htaccess` e a pasta `vendor` do pacote.
4. O pacote não contém `.env`, banco local, sessões ou uploads de pacientes. Preserve os arquivos existentes desses diretórios. Não importe dados locais nem substitua as chaves de criptografia, credenciais do banco, SMTP, Asaas ou integrações. `DB_QUERY_PROFILE` fica desligado por padrão e não precisa ser ativado em produção.
5. Abra `/login`, entre e confira **v54.74** no rodapé. Confira também `/configuracoes/versao`: integridade consistente. Se o cPanel mantiver opcache antigo, reinicie o PHP pela opção disponível na hospedagem antes de retestar.
6. Com pacientes exclusivamente de TESTE, confira: login e retorno à agenda; confirmação/cancelamento mantendo dia/visão; configuração da agenda; plano e publicação; prontuário e exames; portal do paciente e visibilidade de manipulados; ativação manual de assinatura.

## Conteúdo funcional

- NI2-868/870/871/872: retorno da agenda, reimportação após desfazer, telemetria sem texto clínico livre, rótulos, intervalo e alertas de alérgenos.
- NI2-881: manipulados ocultos quando não existe catálogo validado.
- NI2-888/889: configuração da agenda, botões, linguagem e títulos de planos.
- NI2-893: versão no console, remoção de cópia obsoleta de link de consentimento, comprovante sem código sequencial, cancelamento de exames restrito ao estado cancelado.
- NI2-894 reconciliada: um aviso de agendamento/confirmação e bloqueio de marcadores sem valor.
- Atendimento de hoje usa o caminho de início imediato e preserva o agendamento futuro, sem exigir jornada nem enviar novo aviso de agendamento.
- Instrumentação e otimizações iniciais da NI2-892. A refatoração completa dessa história **não** está concluída.

## Validação executada

- Gate core: **23 suítes aprovadas, 0 falhas**, com asserções PHP habilitadas.
- Pacote montado: front controller exercitado via PHP-CGI com login/sessão reais e **9 transições de status** mantendo visão, data e persistência. Sem bypass de autenticação; sessões de teste isoladas. Não é teste de navegador/Apache.
- Avisos de agendamento (NI2-894): **19 verificações aprovadas**; linguagem e títulos (NI2-889): **23 aprovadas**; cópia de link: **3 testes JavaScript aprovados**.
- Manifesto gerado e verificado sobre os arquivos efetivamente entregues. CRC, duplicidade de caminhos e ausência de dados locais verificados no ZIP final.

## Limites da validação

Consulte RELEASE_INFO.json para os resultados executados e SHA-256 do pacote para conferir o download. A checagem do pacote comprova consistência de arquivos, não homologação de produção. Navegação browser/Apache da HostGator e MySQL real continuam pendentes. SQL de atualização destinado a MySQL 5.7+, ainda sem execução nesse servidor nesta rodada. Não declarar essa homologação como aprovada.

## Voltar à versão anterior

Restaure o backup de arquivos da instalação anterior preservando `.env` e os dados de `storage`. A tabela `subscription_activation_requests` é aditiva: normalmente pode permanecer ao voltar, sem apagá-la. Se houve alteração de dados após a atualização, reconcilie antes de restaurar um backup de banco para não perder atendimentos. Não há rollback destrutivo automático.

Instalações anteriores à v54.71 precisam de plano específico de atualização das migrações e não estão cobertas por este procedimento. A instalação limpa usa o instalador completo, em banco vazio, como operação separada.
