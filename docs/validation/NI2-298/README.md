# NI2-298 — consultas do dia no mesmo fuso

Issue: https://caplybr.atlassian.net/browse/NI2-298 — `[PX-05] Consultas do dia divergem entre indicador e tabela`.
Rastreabilidade: finding **PX-05**, `docs/audits/product-experience-2026-10-08/CLYONUTRI_UX_JOURNEY_FINDINGS.md` e `CLYONUTRI_UX_UI_IMPLEMENTATION_BACKLOG.md`.

**Implementação executada e validada localmente. Conclusão da issue: pendente de validação independente. Release: NOT APPROVED.** O status do Jira não foi alterado; nenhum comentário, deploy ou merge foi realizado.

## Critérios e evidências

| Critério transcrito do Jira | Evidência executada | Classificação |
| --- | --- | --- |
| Indicador, agenda e pendências concordam inclusive entre 21h e meia-noite de São Paulo. | HTTP real às **23:57:04 -03**, quando SQLite `CURRENT_DATE` já era **09/10**: indicador e agenda mostraram seis consultas de **08/10**. A consulta antiga baseada no UTC teria contado apenas uma. Relógio controlado executou os serviços de produção com SQLite em 20:59:59, 21:00, 22:30, 23:59:59, virada UTC e meia-noite local. | **PASS local SQLite**. Ambiente de produção: **NOT TESTED**. |
| Funciona nos papéis autorizados e recusa acesso indevido sem exposição de dados. | Login real de nutricionista com hash/sessão, senha inválida e acesso anônimo recusados; clínica B recebe apenas seus dados; tentativa de agendar paciente de outra clínica recusada sem persistência; mutação sem CSRF retorna 403; logout retira acesso. | **PASS** nos fluxos HTTP descritos, sem certificação integral dos demais papéis/rotas do SaaS. |
| Estados de vazio, carregamento aplicável, erro e sucesso distinguem ausência de dados de falha. | Clínica realmente vazia retorna zero e mensagem de ausência. Tabela de consultas indisponível no banco de teste retorna **503**, mensagem de falha e repetição, sem falso zero nem SQL exposto. Restaurar a tabela recupera a agenda. Data inválida/malformada retorna **400**. Capturas e testes HTTP comprovaram as telas. | **PASS** para sucesso/vazio/erro/repetição. Carregamento interno: **não aplicável**, renderização síncrona no servidor; não foi introduzido carregamento fictício. |
| Preserva funcionalidades e histórico existentes; alteração visual usa tokens ratificados. | Leitura preserva consultas/histórico; criação HTTP acrescenta consulta e evento sem reescrever eventos anteriores. Três suítes legadas executadas sem falhas. A tela de erro reutiliza `card`, `btn` e `professional-shell` existentes, sem novos valores de cor ou escala. | Persistência/histórico: **PASS nos testes reais**. Ratificação formal dos tokens: **NOT TESTED**. Critério integral aguarda essa evidência. |

“Pendências concordam” significa que consultas não confirmadas pertencem ao mesmo dia da agenda. O total de pendências não precisa ser igual ao total de consultas: consultas concluídas/canceladas não são pendências, e acompanhamento de faltosos tem janela própria de 14 dias. Essa regra foi preservada, passando a usar o mesmo calendário local.

## Correção no runtime de produção

- `src/Core/Time/ClinicCalendar.php`: resolve o dia no fuso `APP_TIMEZONE` e fornece intervalo **[00:00 do dia, 00:00 do dia seguinte)**. Rejeita datas inexistentes/malformadas. O relógio pode ser injetado por construtor em testes; não há parâmetro HTTP nem variável de ambiente para falsificar a hora de produção.
- `src/Domain/Appointment/AppointmentService.php`: listagem diária, indicadores e consultas não confirmadas usam parâmetros de início/fim do mesmo calendário, sem `CURRENT_DATE` nem função SQL de data nesses filtros. A janela de faltosos também recebe limite calculado na aplicação.
- `src/Application/Controllers/DashboardController.php`: resolve o dia uma vez por requisição e o passa aos três serviços, evitando divergência se a requisição atravessar meia-noite. A agenda usa o mesmo calendário para “hoje”. Falhas de consulta geram 503; datas inválidas geram 400.

O contrato de armazenamento existente foi preservado: `appointments.scheduled_at` guarda data/hora local sem offset. Não houve migração/conversão silenciosa para UTC, alteração de estados ou reescrita de histórico. O fuso neste checkout é `America/Sao_Paulo`, configurado por aplicação (`APP_TIMEZONE`); não foi adicionada configuração de fuso individual por tenant.

## O que foi executado

- **23/23 testes HTTP reais**: servidor PHP executando `public/index.php`, sessões reais, CSRF, SQLite relacional, login, agenda, dashboard, persistência, isolamento, falha e recuperação.
- **26/26 testes de calendário com banco real e relógio controlado**: serviços de produção, fronteiras inclusiva/exclusiva, estados, filtro de profissional, tenant, datas inválidas, ano e dia bissexto.
- Regressões legadas executadas com saída zero: `agenda_and_consultation_lifecycle` (**46** asserções), `agenda_operational_and_concurrency` (**32**), `canonical_first_consultation_e2e_journey` (**32**).

Os números das regressões são a saída original dessas suítes. Algumas asserções antigas verificam código/CSS; elas **não foram usadas para atribuir PASS funcional** aos critérios da NI2-298. A frase automática de aprovação emitida pela suíte antiga NI2-187 não aprova esta issue nem uma release. Os aceites funcionais acima se apoiam na suíte HTTP nova, execução do calendário contra banco real e interface observada.

As fixtures são **identidades e consultas sintéticas explicitamente nomeadas**, persistidas em bancos temporários separados. Não há substituição de respostas, interceptação de fetch, cópia das rotas da API ou contagens fixas no código de produção. O teste HTTP cria uma nova consulta pelo POST real `/agenda` e confere os registros e eventos no banco.

O primeiro preparo da regressão de agenda falhou porque ela pressupõe usuários/clínicas preexistentes. O erro foi preservado em `evidence/agenda-regression-initial-setup-failure.log`. O roteiro agora prepara essas contas com `AuthService::registerNutritionist`, em banco isolado, e executa o CLI no mesmo fuso do front controller.

## Reprodução automática

Pré-requisitos: PHP com PDO SQLite, dependências Composer do projeto e Node.js. Na raiz:

```sh
./tests/ni2_298_verify.sh
```

O roteiro aplica migrações oficiais, prepara bancos temporários, executa lint, calendário, HTTP e as três regressões, gravando saídas em `evidence/`. Cada teste usa banco separado e os remove ao terminar normalmente. Nenhum teste usa o banco clínico do usuário.

Comandos principais, com `DB_CONNECTION=sqlite`, `DB_DATABASE` temporário dedicado e `APP_TIMEZONE=America/Sao_Paulo` definidos pelo roteiro:

```sh
php tests/support/ni2_298_fixture.php prepare
php tests/ni2_298_calendar_integration_test.php
node tests/ni2_298_real_http_test.cjs
php -d date.timezone=America/Sao_Paulo tests/agenda_and_consultation_lifecycle_test.php
php -d date.timezone=America/Sao_Paulo tests/agenda_operational_and_concurrency_test.php
php -d date.timezone=America/Sao_Paulo tests/canonical_first_consultation_e2e_journey_test.php
```

O teste HTTP escolhe porta livre e diretório de sessão exclusivos, autentica pelo formulário real e não reutiliza cookies do usuário. `persistence.json` registra consultas e histórico antes/depois da mutação; `http-context.json` registra hora local/UTC, data do banco e contagem da consulta antiga; `http-results.json` enumera os resultados. `calendar-results.json` enumera os testes com relógio controlado. `source-sha256.json` identifica os arquivos validados.

A execução HTTP usa o relógio real; se ocorrer exatamente durante a mudança do dia entre preparação da fixture e leitura, deve ser repetida para o novo dia. Os testes de fronteira completos usam relógio injetado no serviço e não dependem da hora da máquina. Não se reivindica uma virada de relógio simulada no servidor HTTP.

## Reprodução visual

Em um terminal, na raiz, escolhendo um arquivo ainda inexistente:

```sh
export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/ni2-298-review.sqlite
export APP_TIMEZONE=America/Sao_Paulo
php tests/support/ni2_298_fixture.php prepare
mkdir -p /tmp/ni2-298-review-sessions
php -d session.name=NI298REVIEW -d session.save_path=/tmp/ni2-298-review-sessions -S 127.0.0.1:8085 -t public
```

Abrir `http://127.0.0.1:8085/login`. Credenciais exclusivamente da fixture temporária: `agenda1@example.test` / `AgendaTeste@2026`. A clínica B usa `agenda2@example.test`; clínica sem consultas usa `agenda3@example.test`, mesma senha de fixture.

1. Login da clínica A → dashboard: seis consultas e pendência do paciente `NI298 scheduled`.
2. Agenda → mesmo dia, seis registros, todos os estados preservados, dias adjacentes e clínica B excluídos.
3. Clínica B → apenas seu registro. Clínica C → ausência explícita.
4. Com a clínica A autenticada, em outro terminal usando o mesmo `DB_DATABASE`, executar `php tests/support/ni2_298_fixture.php break`: apenas a tabela no banco de teste é renomeada; dashboard/agenda retornam 503. Executar `php tests/support/ni2_298_fixture.php restore` e repetir: dados retornam.

Capturas observadas: `dashboard.png`, `agenda.png`, `empty-agenda.png`, `database-error.png`. O navegador integrado ficou com a agenda real de teste em `http://127.0.0.1:8084/agenda`; banco e cookie de sessão próprios. A preparação visual inicial tinha seis consultas; a suíte HTTP usa outro banco e prova o acréscimo de seis para sete.

O cartão de adesão 94% que aparece no dashboard é um achado anterior, fora desta correção (PX-04). Não foi introduzido nesta entrega, nem é evidência de resultado real ou de aprovação do dashboard completo.

## Gates não comprovados

- **NOT TESTED — ambiente/banco de produção:** o checkout configura SQLite e esse motor foi executado. Não houve execução em instância de produção, nem em MySQL. A verificação `docker info` retornou daemon indisponível; não se inferiu compatibilidade de outro banco apenas pela SQL.
- **NOT TESTED — validação independente:** implementação e testes têm o mesmo executor. Um revisor independente precisa repetir o roteiro e examinar os resultados antes de marcar a issue como Feito.
- **NOT TESTED — ratificação formal de design e matriz integral de release:** reuso de componentes existentes não equivale a aprovação formal. Não foi feita auditoria completa dos demais fluxos, papéis ou vulnerabilidades do produto.

A implementação do comportamento principal não depende de mocks ou de uma dependência ausente. A conclusão da issue e a aprovação de release continuam sem autorização/evidência suficiente pelos gates acima.
