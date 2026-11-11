# Correção do fluxo de horários e feriados

Data: 01/11/2026. Base: pacote v50 com a revisão visual dos feriados. Sem alteração em produção.

## Alterações

- Nova consulta e reagendamento passam a ter campos separados Data e Horário, com edição minuto a minuto. A composição é validada no servidor; não depende de JavaScript. O formato anterior scheduled_at continua aceito para compatibilidade.
- Falhas preservam a data e o horário informados. O erro de bloqueio identifica o feriado e seu período, em vez de uma mensagem genérica.
- O cadastro de feriado permite escolher Dia inteiro ou Definir horário (início e fim). É possível editar data, período, descrição e escopo do registro existente, preservando seu ID.
- Bloqueios e aberturas também podem ser editados. A autorização impede editar registros de outra clínica ou regras gerais da gestão com uma conta restrita à própria agenda.
- As três visões da agenda carregam as exceções persistidas do período, inclusive em dias sem consultas. O marcador informa descrição, período e escopo. Dias sem consultas não são mais apresentados como “Livre”.
- Na visão diária, o aviso aparece antes do formulário. Consultas já marcadas continuam visíveis; registrar um feriado não cancela atendimentos existentes.
- O marcador na agenda aponta para o registro nas Configurações, com o editor aberto. Cada registro também tem Ver na agenda. Gravações bem-sucedidas redirecionam para uma página GET, evitando repetir o cadastro ao atualizar o navegador.

## Banco e deploy

Não há migração adicional: os horários usam start_time/end_time já existentes na migração 69. Se a 69 foi aplicada e verificada, basta atualizar os arquivos. O pacote completo continua incluindo os três SQL corrigidos, com banco explícito maic6824_Clyonutri2, para instalações que ainda precisam aplicar a 69 sobre a v49.

Preservar .env e storage/. Fazer backup dos arquivos, pausar cron/operações durante a substituição conjunta e invalidar o cache PHP disponível. Validar na clínica de teste antes de liberar.

## Validação

31 verificações novas em tests/agenda_calendar_flow.php: horários de feriado parcial, edição e identidade do registro, alteração imediata da disponibilidade nas duas datas, troca para dia inteiro, isolamento/posse, entradas de data/hora e recuperação, renderização dos controladores reais diária/semanal/mensal e reagendamento. Os controladores usam a conexão SQLite sintética por Connection::setInstance; não são cartões fixos substituindo o serviço.

Regressões: 41 verificações de calendário/lista de espera, 28 de autoagendamento e 27 de confirmação. Total desta revisão: 127 verificações locais. Sintaxe PHP dos arquivos alterados verificada.

Conferência no Chrome: templates reais com o CSS do produto, marcadores nas três visões, campos de data/hora e mudança para período parcial habilitando os horários em viewport móvel de 390px. Capturas são de dados sintéticos, não da hospedagem. Não houve envio SMTP ou homologação completa de login/formulários em produção. Evidências MySQL anteriores continuam históricas; as consultas novas foram verificadas nesta revisão com SQLite, não com MySQL/HostGator.

## Rollback

Restaurar os arquivos do pacote anterior em conjunto (controladores, serviço e views), com backup e cron pausado. Não restaurar o banco inteiro sobre novas consultas. Os períodos salvos nos feriados permanecem nas colunas existentes; o pacote anterior já subtrai períodos persistidos no motor, mas sua tela de cadastro de feriado é de dia inteiro e pode ocultar a edição do período. Preservar os registros e não recriar feriados em duplicidade.
