# Code review: NI2-94, visão do profissional de adesão e atenção

- **Card:** [NI2-94](https://caplybr.atlassian.net/browse/NI2-94), história do épico NI2-12. Status no Jira: **Feito**.
- **Pedido:** "Como nutricionista, quero identificar quem precisa de acompanhamento."
- **Aceite:**
  - pendências e respostas;
  - indicadores operacionais;
  - sem diagnóstico automático;
  - filtros;
  - link para o Perfil 360;
  - sem ranking opaco.
- **Data:** 22/10/2026.
- **Revisado em:**
  - `~/Nutri_II`, branch `merge/v11-teleconsulta`, HEAD `f88bca7`;
  - `~/Nutri_II-v20`, HEAD `0c79ced`.

## Onde está o código

Não há uma tela própria para a NI2-94. O que existe é o painel geral (`/dashboard`), o Perfil 360 e o painel do app do profissional.

| Parte | Arquivo |
|---|---|
| Lista "Pacientes e prioridades que exigem atenção" | `AppointmentService::getAttentionRequired` (`src/Domain/Appointment/AppointmentService.php:751`), exibida em `DashboardController::index` (`:4925-4963`) |
| Card "Adesão média" | `EngagementService::getClinicAdherenceMetrics` (`src/Domain/Engagement/EngagementService.php:144`), exibido em `DashboardController.php:4994-5008` |
| Check-ins no Perfil 360 | `views/patients/longitudinal.php:14-19` |
| Painel do app do profissional | `GET /api/v1/professional/dashboard` (`public/index.php:1412`), `GET /api/v1/professional/food-diaries` (`:1489`), `FoodDiaryService::countPendingDiaries` (`:212`) e `ProfessionalHomeScreen.tsx:316` |
| Resumo de adesão por paciente | `EngagementService::getPatientAdherenceSummary` (`:78`), que **não é chamado em lugar nenhum** |

No v20 o código é o mesmo, com uma diferença: lá não existe o item de pedidos de remarcação (que veio na NI2-137).

## Veredito

**O status "Feito" não se justifica.**
- O painel mostra só pendências de **agenda e de prescrição**: consulta não confirmada, falta, pedido de remarcação e plano em rascunho. **Não mostra nenhum sinal de engajamento da paciente**, como diário sem retorno, mensagem sem resposta, check-in com adesão baixa ou paciente sem registros.
- Na sonda, a paciente Ana tinha 3 refeições no diário sem retorno, uma mensagem no chat sem resposta e um check-in com 20% de adesão e o texto "muito difícil". O painel respondeu "**0 item(ns)… Todas as rotinas estão regulares**".
- O check-in, que é a única fonte do indicador de adesão, **não pode ser enviado**: `POST /api/v1/patient/checkin` dá 404, e o app não tem tela de check-in. Em produção, o card "Adesão média" fica sempre "Sem dados", ou mostra dados antigos.
- **Não há filtros.**
- Só o item de falta leva ao Perfil 360.

O que está certo: não há diagnóstico automático nem nota ou ranking. A ordem é fixa por tipo e o texto é descritivo.

## Critérios de aceite

| Critério | Situação | Observação |
|---|---|---|
| Pendências e respostas | **Não atende** | Diário sem retorno, chat sem resposta, pré-consulta respondida e autorrelato não entram no painel web (A1). No app, só há a contagem de diários pendentes de toda a clínica (M3) |
| Indicadores operacionais | Parcial | Há consultas de hoje e da semana, pacientes ativos, adesão média e planos a revisar. A adesão média não tem fonte de dados (A2) e mistura pacientes inativos (M1) |
| Sem diagnóstico automático | Atende | Os itens descrevem fatos ("não compareceu", "plano em rascunho") |
| Filtros | **Não atende** | O painel não tem filtro nenhum: nem por profissional, tipo, período ou paciente. A lista do app ignora `status` (A3) |
| Link para o Perfil 360 | Parcial | Só o item de falta leva a `/pacientes/{id}`. Consulta não confirmada vai para a agenda e rascunho vai para o plano. O card de adesão não leva a paciente nenhuma (A3) |
| Sem ranking opaco | Atende | Não há nota. Mas o corte em 5 itens segue a ordem dos tipos, sem dizer qual critério ficou de fora (B2) |
| Escopo do profissional | Não atende | A nutricionista vê as pendências de toda a clínica, inclusive de pacientes de outros profissionais. Hoje já existe vínculo paciente–profissional (NI2-138) para filtrar (M2) |
| Testes (DoD) | Parcial | `px_04_adherence…` e `dashboard_review_fixes` passam nas duas cópias, mas não cobrem engajamento, filtros nem paciente inativo (M5) |

## Achados

### Alto

**A1. O painel não mostra pendências de engajamento da paciente.**
- `getAttentionRequired` (`AppointmentService.php:751-848`) monta só quatro tipos de item: consulta de hoje sem confirmação, falta nos últimos 14 dias, pedido de remarcação e plano em rascunho.
- Não entram, embora os dados existam:
  - **Diário sem retorno:** `food_diary_entries.feedback_from_professional IS NULL`. Já existe `FoodDiaryService::countPendingDiaries`, mas só o app usa.
  - **Mensagem da paciente sem resposta:** `chat_messages.sender_type = 'patient' AND read_at IS NULL`, NI2-138.
  - **Pré-consulta respondida e não revisada:** `preconsultation_questionnaires.status = 'submitted'`, NI2-437.
  - **Check-in com adesão baixa ou observação da paciente.**
  - **Paciente ativa sem nenhum registro há N dias.** É o caso clássico de "quem precisa de acompanhamento".
- Na sonda, depois de a Ana registrar 3 refeições, enviar uma mensagem e ter um check-in de 20% com "muito difícil", o painel mostrou "0 item(ns) identificado(s). Nenhuma pendência crítica… Todas as rotinas estão regulares". O texto afirma que está tudo regular quando não está.
- No web, o profissional não tem tela nenhuma de diário alimentar. Só o app mostra.
- **Correção:**
  - Criar uma fonte de "atenção de acompanhamento" por paciente com sinais fatos, cada um com data e contagem:
    - refeições sem retorno;
    - mensagens sem resposta e há quanto tempo;
    - pré-consulta respondida;
    - último check-in, com adesão e observação;
    - dias desde o último registro no app.
  - Exibir esses sinais no painel e numa lista própria, como `/pacientes/acompanhamento`.
  - Trocar o texto do estado vazio por algo que diga só o que foi verificado.

**A2. O indicador de adesão não tem de onde vir.**
- `getClinicAdherenceMetrics` e o Perfil 360 leem só a tabela `checkins`.
- Em `public/index.php`, que é o roteador usado, não existe rota que grave check-in. `routes/api.php:20` declara `POST /api/v1/patient/checkin`, mas esse arquivo não é carregado. O app também não tem tela de check-in.
- Na sonda, nas duas cópias, `POST /api/v1/patient/checkin` dá **404**. Para testar o card, foi preciso gravar os check-ins direto no banco.
- Consequência: em produção, "Adesão média" mostra "Sem dados" para sempre. Pior, pode mostrar check-ins de demonstração ou de importação.
- **Correção:**
  - Ou criar o check-in no app, com rota real, validação de 0 a 100, idempotência e auditoria, seguindo NI2-12.
  - Ou derivar a adesão de dados que existem, como refeições do diário contra as refeições do plano publicado, e dizer na interface qual é a fonte.
  - Em qualquer caso, o card precisa dizer de onde vem o número.

**A3. Não há filtros, e quase nada leva ao Perfil 360.**
- O painel não tem `<form>` nem `<select>` (sonda: `has_filter_form: false`).
- O corte em 5 itens (`DASHBOARD_ATTENTION_LIMIT`, `DashboardController.php:29`) esconde o resto. O "Ver todos" só existe para agenda e rascunhos.
- No app, `GET /api/v1/professional/patients?status=inactive&adherence=low` devolve **todos** os pacientes (total 2): os parâmetros são ignorados (`public/index.php:1442-1486`).
- Links:
  - consulta não confirmada vai para `/agenda?id=…`;
  - rascunho vai para o plano;
  - o card de adesão não tem link;
  - no app, o diário não leva à paciente.
- **Correção:**
  - Filtros por profissional ("meus pacientes" usando `patient_professional_links`), tipo de sinal, período e status da paciente.
  - Paginação com total.
  - Todo item com link para `/pacientes/{id}`, abrindo na seção certa (`#patient-checkins`, chat ou diário).

### Médio

**M1. A adesão média mistura pacientes inativos e esconde quem está mal.**
- A população "elegível" é `SELECT COUNT(*) FROM patients WHERE clinic_id = :cid` (`EngagementService.php:151`), sem `status = 'active'` e sem considerar se a paciente usa o app.
- A média também inclui check-ins de pacientes inativos.
- Na sonda, com Bruno **inativado** (95%) e Ana em 20%, o card mostrou "**57,5%** · 2 de 2 paciente(s) elegíveis responderam".
- Uma média da clínica não responde "quem precisa de acompanhamento": a Ana em 20% some no meio.
- **Correção:**
  - Contar só pacientes ativas com acesso ao app.
  - Mostrar a distribuição: quantas abaixo de X%, com link para a lista filtrada, em vez de só a média.
  - Deixar claro quantas não responderam.

**M2. A nutricionista vê os pacientes de toda a clínica.**
- `getAttentionRequired`, o card de adesão, `countPendingDiaries` e `getClinicDiaries` filtram só por `clinic_id`.
- Não há nenhum filtro por profissional. Assim, a "Nutri Equipe" (nutricionista, não dona) recebe no app os diários e a contagem de toda a clínica, do mesmo jeito que a dona.
- Com o vínculo da NI2-138 já existente, o padrão para a nutricionista deveria ser "meus pacientes", com a opção "toda a clínica" para dona e admin.
- **Correção:** escopo por vínculo ativo, ou pelo `professional_id` da consulta ou do plano, nas consultas de atenção e de diário.

**M3. O "Diários pendentes" do app conta tudo, desde sempre, e a lista não prioriza os pendentes.**
- `countPendingDiaries` (`FoodDiaryService.php:212`) não tem janela de tempo nem filtro de paciente ativa. Uma refeição de 2 anos atrás conta igual.
- A lista (`getClinicDiaries`, `:192`) traz as 50 refeições mais recentes, com ou sem retorno. Assim, um pendente antigo nunca aparece para ser respondido, embora entre na contagem.
- A lista devolve `patient_email` e `photo_path` sem necessidade (sonda, campos de `food-diaries`).
- **Correção:** janela de 30 dias, opção de ver só os pendentes, paginação e campos fechados.

**M4. Uma falta já remarcada continua como pendência.**
- O comentário diz "no-show… sem remarcação" (`AppointmentService.php:779`), mas a consulta não confere se existe consulta futura para a paciente.
- Na sonda, a Ana faltou em 19/10, tem consulta **confirmada** para 27/10 e mesmo assim aparece "Não compareceu" com o botão "Remarcar consulta".
- O botão "Remarcar consulta" leva ao Perfil 360, não à remarcação.
- **Correção:** excluir pacientes com consulta futura `scheduled` ou `confirmed`, e trocar o rótulo para "Ver paciente", ou levar de fato à agenda com a paciente escolhida.

**M5. Os testes não cobrem o card.**
- `px_04_adherence_real_calculation_and_empty_state_test` e `dashboard_review_fixes_test` passam nas duas cópias, mas não testam:
  - diário, chat ou pré-consulta no painel;
  - paciente inativa na adesão;
  - filtros;
  - links para o Perfil 360;
  - escopo da nutricionista;
  - falta já remarcada;
  - a existência de uma rota de check-in.
- Os testes gravam check-ins direto no banco, o que esconde o A2.
- **Correção:** criar `tests/ni2_94_*` pelas rotas reais (app da paciente → painel web e app do profissional), no padrão das NI2-137 e NI2-138.

### Baixo

- **B1.** No Perfil 360 do `~/Nutri_II`, a seção "Check-ins recentes" termina com o link "**Ver todos os pedidos LGPD da clínica**" (`/privacidade/pedidos`, `views/patients/longitudinal.php:18`). Não tem relação com check-ins e parece ter vindo junto com a fila LGPD (NI2-91). No v20 isso não acontece.
- **B2.** O corte em 5 itens segue a ordem dos tipos: não confirmadas, faltas, remarcação e rascunhos. Com muitos itens de um tipo, os outros somem sem aviso de qual tipo ficou de fora. Vale mostrar a contagem por tipo.
- **B3.** `getClinicAdherenceMetrics` usa `date('-30 days')` (`:156`) no fuso do PHP, e não no dia da clínica (`ClinicCalendar`), como faz a agenda.
- **B4.** `getPatientAdherenceSummary` (`:78`) não é chamado em lugar nenhum. Se for usado, sem check-ins ele devolve **0%** (`round((float)null)`) em vez de "sem dados", e a água "de hoje" usa `date(logged_at)` em UTC no SQLite.
- **B5.** Um segundo retorno na mesma refeição troca o texto anterior sem histórico (`addProfessionalFeedback`, `:144`). A sonda devolveu 200 duas vezes. A auditoria registra só o tamanho do texto.
- **B6.** Texto: o título "Pacientes & Prioridades que Exigem Atenção" está em Caixa De Título e com "&" no `~/Nutri_II` (o v20 já usa "Pacientes e prioridades que exigem atenção"). "Diários Alimentares & Feedback Clínico" e "⏳ Pendente de feedback clínico" no app estão fora do tom da curadoria v21.

## O que está bom

- **Sem diagnóstico nem pontuação:** os itens são fatos, com data e nome, e não há nota de risco.
- **Adesão sem número inventado:** sem dados, o card mostra "Sem dados" e a contagem de respostas, sem valor fixo (PX-04, NI2-297).
- **Isolamento entre clínicas:** todas as consultas filtram por `clinic_id`. O app do profissional exige papel clínico (`ClinicalAuthorization`) e membro ativo.
- **Itens com ação:** cada item da lista tem um botão com destino.

## Testes executados (box, PHP 8, SQLite, app real em `php -S`)

| Prova | `~/Nutri_II` | v20 |
|---|---|---|
| Sonda HTTP (`/home/claude/cr94/probe.cjs`, fixture da NI2-138): Ana registra 3 refeições e água pelo app e manda uma mensagem pelo chat; check-ins de 20% (Ana) e 95% (Bruno, depois inativado) gravados no banco; login web da nutricionista; `/dashboard` e `/pacientes/1` | Painel: 0 itens, "Todas as rotinas estão regulares"; adesão "57,5% · 2 de 2 elegíveis"; check-in com link LGPD; sem diário no Perfil 360 | Igual: 0 itens e 57,5% (`probe20.cjs`) |
| `POST /api/v1/patient/checkin` | 404 | 404 |
| App do profissional: `/dashboard`, `/food-diaries`, `/patients?status=inactive` | 3 pendentes; lista com `patient_email` e `photo_path`; filtro ignorado (2 de 2) | Mesmo código |
| Falta remarcada (`getAttentionRequired` com falta em 19/10 e consulta confirmada em 27/10) | Continua "Não compareceu · Remarcar consulta" | Mesmo código |
| `px_04_adherence_real_calculation_and_empty_state_test` | PASS | PASS |
| `dashboard_review_fixes_test` | PASS | PASS |

## Ordem sugerida de correção

1. **A2.** Decidir a fonte da adesão: check-in no app (rota real e tela) ou derivação do diário contra o plano. Sem isso, o indicador não existe.
2. **A1.** Sinais de engajamento por paciente no painel e numa lista de acompanhamento: diário sem retorno, mensagem sem resposta, pré-consulta respondida, check-in baixo e dias sem registro. Trocar o texto "Todas as rotinas estão regulares".
3. **A3 e M2.** Filtros (meus pacientes ou clínica, tipo, período, status), paginação e links para o Perfil 360 na seção certa.
4. **M1 e M4.** Adesão só de pacientes ativas, com distribuição; falta já remarcada sai da lista.
5. **M3, B1 a B6.** Contagem e lista de diários do app, link LGPD fora do lugar, fuso, textos.
6. **M5.** Teste `ni2_94_*` pelas rotas reais, cobrindo cada linha da tabela de critérios.
