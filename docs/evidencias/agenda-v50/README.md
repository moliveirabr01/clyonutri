# Calendário e lista de espera — candidato v50

Implementação de 01/11/2026 sobre a v49 com as correções NI2-433 e NI2-487. Sem implantação em produção.

## Como usar

Em Configurações, a seção “Feriados, exceções e intervalos” permite à gestão definir os buffers da clínica, habilitar a lista de espera e definir o prazo de oferta (5–60 minutos). A gestão registra feriados/bloqueios/aberturas para toda a clínica ou para um profissional da clínica. Cada nutricionista pode criar e remover suas próprias exceções; não pode alterar a política da clínica ou remover bloqueios da gestão.

Feriados são datas cadastradas explicitamente, inclusive municipais/locais. Não há importação automática ou calendário nacional presumido. É possível cadastrar um bloqueio de dia inteiro, um intervalo parcial ou uma abertura especial. Para mais de uma janela de abertura no dia, adicione uma abertura para cada janela.

Uma abertura especial substitui a jornada semanal e sua pausa naquele dia. Uma abertura específica do profissional tem prioridade sobre aberturas gerais da clínica. Feriados e bloqueios explícitos sempre prevalecem sobre aberturas. Consultas já persistidas são mantidas: a nova regra restringe novas marcações e conversões de hold, sem cancelar pacientes automaticamente.

Buffers são aplicados antes e depois de cada consulta e reserva. Entre duas consultas, somam-se o intervalo posterior da primeira e o anterior da seguinte. Eles também precisam caber no período de trabalho; nunca atravessam uma pausa, feriado ou bloqueio. O autoagendamento oferece inícios numa grade de 15 minutos. A agenda interna e o autoagendamento usam a mesma política; a agenda interna também respeita holds ativos. O mecanismo explícito de sobrescrever conflitos da agenda interna continua sendo uma operação excepcional já existente, não o fluxo normal.

## Lista de espera

O link aparece na página contextual de agendamento quando a clínica habilita a função. O paciente entra pelo Portal do Paciente e acessa `/paciente/lista-espera?prof=ID`. O portal da lista permite acompanhar e cancelar pedidos mesmo quando novas inscrições naquele link ficam indisponíveis.

O paciente informa profissional, modalidade, serviço, intervalo futuro de até 31 dias e janela de horário. Não se aceita identificação anônima por e-mail/telefone; somente a sessão autenticada pode selecionar o paciente. Uma solicitação ativa por paciente/profissional/modalidade impede duplicatas. Para alterar o período de um pedido ativo, o paciente cancela e entra novamente.

O cron existente procura vagas, respeitando a ordem de entrada entre pedidos compatíveis. Não há agenda paralela: cada oferta cria um hold real com destinatário e prazo, indisponibilizando a vaga para outras marcações. A oferta é avisada pela fila existente e fica no portal. O paciente aceita com o termo vigente; o mesmo BookingService converte a reserva em appointment e publica a comunicação transacional. Não se agenda automaticamente.

O hold de uma oferta exige autenticação do destinatário: outra pessoa, outra clínica ou a rota anônima não podem aceitá-lo. A disponibilidade e as regras são revalidadas na conversão. Ao aceitar, o pedido vira fulfilled; ao desistir, libera o hold. Uma oferta expirada libera a vaga e encerra aquele pedido, permitindo que os próximos recebam ofertas; o paciente pode entrar novamente.

Desabilitar a lista pausa os pedidos e libera ofertas pendentes, preservando a posição de entrada. Os avisos obsoletos são descartados antes do despacho, inclusive quando uma nova exceção elimina a disponibilidade. Ao reabilitar, o cron pode criar novas ofertas. Preferências de notificação e horário de silêncio continuam valendo; ofertas com prazo curto podem expirar sem e-mail, portanto o portal é a fonte do estado atual.

A gestão vê contagens reais por estado em Configurações. O cron registra offered/expired/failed sem contatos ou conteúdo clínico. Uma falha ao criar o aviso desfaz o hold e a oferta na mesma transação; o pedido permanece na fila para nova tentativa. O limite do cron é de ofertas por execução, não um corte permanente dos primeiros pedidos sem compatibilidade. Filas grandes sem disponibilidade exigem acompanhar o tempo do cron na hospedagem.

## API e segurança

- GET `/api/v1/patient/waitlist`: pedidos ativos do próprio paciente e tokens somente de suas ofertas ainda no prazo.
- POST `/api/v1/patient/waitlist`: professional_id, date_from/date_to, time_from/time_to, modality e service_type; identidade vem da autenticação.
- POST `/api/v1/patient/waitlist/{id}/cancel`: retirada do próprio pedido.
- POST `/api/v1/patient/booking/confirm`: aceite com hold_token, terms_accepted e consent_term_id vigente; reutiliza a API da correção anterior.

As rotas reutilizam Bearer/cookie existentes, expiração e proteção de Origin + X-ClyoNutri-Web. Configurações exigem a sessão da equipe, papel autorizado e CSRF. Todas as operações filtram clinic_id; eventos e auditoria guardam IDs/estados, sem dados clínicos. Tokens de oferta não entram no e-mail nem no log. A interface nativa do app não está neste repositório e ainda precisa consumir essas rotas; há interface web completa.

## Banco e implantação HostGator

Nova migração: `2026_11_01_000069_create_booking_calendar_and_waitlist.php`. Cria três tabelas: booking_calendar_policies, booking_calendar_exceptions e booking_waitlist. Não altera nem apaga consultas, não insere feriados fictícios e não habilita a lista automaticamente. Buffers iniciam em zero.

1. Fazer backup dos arquivos e do banco. Confirmar base v49/migração 68 aplicada.
2. Pausar cron e operações de agenda durante a atualização.
3. Selecionar explicitamente o banco correto no phpMyAdmin. Rodar `database/manual/atualizacao-v50-agenda-preflight.sql`.
4. Rodar `atualizacao-v50-agenda-completa.sql` e depois `atualizacao-v50-agenda-verificacao.sql`. O SQL é idempotente e registra a migração 69. Alternativa com terminal: o migrador PHP existente aplica a 69, se não houver outras pendências.
5. Substituir os arquivos da aplicação em conjunto, preservando .env e storage/. Não rodar seed ou instalador limpo.
6. Conferir APP_TIMEZONE/APP_URL e cron_scheduler.php a cada minuto. Habilitar e configurar a lista apenas depois de validar o fluxo na clínica de teste.

SQL utiliza recursos disponíveis no MySQL 5.7. A execução real local foi em MySQL 8.0.46: isso não comprova homologação no MySQL 5.7/HostGator. Nenhum banco da hospedagem foi acessado.

## Evidências

- `php tests/booking_calendar_waitlist.php`: 37 verificações SQLite.
- `CLYO_TEST_MYSQL_SOCKET=... php tests/booking_calendar_waitlist.php`: 41 verificações MySQL 8.0.46, incluindo dois workers simultâneos e dois aceites concorrentes. O teste cria e remove apenas um banco próprio test_clyo_calendar_PID em um servidor isolado acessível por socket, com root sem senha de teste.
- `CLYO_TEST_MYSQL_SOCKET=... php tests/booking_calendar_sql_mysql.php`: 3 verificações de SQL manual repetido e compatibilidade com a migração PHP.
- Regressões anteriores: 27 verificações de confirmação e 28 de autoagendamento.
- Sintaxe PHP dos arquivos modificados e JavaScript renderizado das páginas verificada.

Os cenários MySQL repetem os cenários SQLite e acrescentam concorrência; não são todos casos distintos. Resultados em arquivos nesta pasta. Não houve SMTP real nem homologação completa no navegador, no app nativo ou na HostGator. Usar contas/caixas sintéticas autorizadas e validar esses pontos antes de liberar produção.

## Rollback

Pausar agenda e cron. Desabilitar a lista pela configuração nova para liberar as ofertas pendentes. Com os workers parados, marcar como skipped (motivo rollback_waitlist) somente as entregas queued/sending dos eventos booking.waitlist_offer; não apagar consultas ou eventos. Restaurar o pacote anterior NI2-433/487. Manter as novas tabelas e o histórico; não restaurar o banco inteiro sobre consultas novas. O código anterior não aplica feriados/exceções/buffers: manter o autoagendamento desabilitado até decidir como manter as restrições operacionais. E-mails já aceitos pelo provedor não podem ser recolhidos.

## Correção de contexto do phpMyAdmin

Os três scripts da migração 69 usam agora o banco explícito `maic6824_Clyonutri2`, inclusive depois de consultar information_schema. Para outro banco, substituir esse nome em todo o script antes de executar. O preflight continua somente leitura.
