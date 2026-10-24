# Code review: NI2-24, isolamento multi-tenant e proteção contra IDOR

- **Card:** [NI2-24](https://caplybr.atlassian.net/browse/NI2-24), história do épico NI2-2. Status no Jira: **Feito**.
- **Pedido:** "Como responsável de segurança, quero impedir acesso cruzado entre clínicas/tenants."
- **Aceite:**
  1. toda leitura e escrita operacional filtra o tenant;
  2. IDs de outro tenant retornam acesso negado ou 404 seguro;
  3. testes automatizados cross-tenant;
  4. queries e repositórios recebem o contexto do tenant;
  5. a auditoria registra as tentativas relevantes.
- **Data:** 23/10/2026.
- **Revisado em:** `~/Nutri_II`, branch `merge/v11-teleconsulta`, HEAD `3b3cdf9`.

## Como foi revisado

1. **Inventário:** 227 rotas em `public/index.php`, 122 delas com parâmetro de caminho.
2. **Sonda dinâmica** (`/home/claude/cr24/probe.cjs`, app real em `php -S`, SQLite):
   - Montei a clínica 1 (vítima) com um registro de cada recurso, marcados com o texto "SEGREDO-C1": consulta, consulta online, plano com refeição, item e substituição, receita, cobrança, avaliação, dobras, meta, pré-consulta, prontuário, chamado, pedido LGPD, pedido de remarcação, sessão de teleconsulta, diário, vínculo do chat, anexo do chat e alimento personalizado.
   - Montei a clínica 2 (atacante) com recursos próprios.
   - A dona da clínica 2 fez **139 pedidos** usando ids da clínica 1: 42 GET e 77 POST na web, com CSRF válido e campos plausíveis, mais 20 chamadas às APIs do profissional e da paciente (Carla, da clínica 2).
   - Incluí **ids misturados**: rota de um recurso próprio com id filho de outra clínica, como `/planos/<plano C2>/itens/<item C1>/editar` ou `/pacientes/<paciente C2>/metas/<meta C1>/encerrar`.
   - **Após cada POST**, conferi a impressão digital de todas as tabelas da clínica 1 e dos filhos sem `clinic_id`.
   - **Em toda resposta**, procurei o texto "SEGREDO-C1", os nomes e os e-mails das pacientes da clínica 1.
   - **Oráculo de existência:** repeti cada pedido com um id inexistente e comparei status e redirecionamento.
   - **Auditoria:** contei as linhas `SECURITY_*` gravadas em cada pedido.
3. **Varredura estática:** comandos SQL em `src/` e `public/` sobre tabelas com `clinic_id` que não mencionam `clinic_id` (67 casos), revisados um a um nos pontos de escrita.

## Veredito

**O isolamento se sustenta nos pontos testados, mas o card não está completo.**
- Nos 139 pedidos, **nenhum vazou dado** da clínica 1 e **nenhum alterou** registro dela. Isso inclui os ids misturados e as duas APIs.
- O aceite não é atendido em três pontos:
  - a auditoria registra só 29 das tentativas;
  - um caminho distingue "existe em outra clínica" de "não existe";
  - não há teste automatizado que percorra as rotas HTTP.
- Há ainda um erro 500 e mensagens que expõem detalhes internos.

## Critérios de aceite

| Critério | Situação | Observação |
|---|---|---|
| Toda leitura e escrita filtra o tenant | Atende (amostra de 139 pedidos) | Nenhum vazamento nem escrita cruzada. Algumas escritas confiam só numa checagem anterior, sem `clinic_id` no próprio UPDATE (B3) |
| IDs de outro tenant → negado ou 404 seguro | Parcial | Sem vazamento, mas há um oráculo de existência (M2), um 500 (M3), mensagens com detalhes internos (M4) e respostas desiguais (B2) |
| Testes automatizados cross-tenant | Parcial | `cross_tenant_end_to_end_security_test` (27) e `cross_tenant_offensive_test` (20) passam, mas testam serviços. Nenhum teste percorre as rotas HTTP nem impede que uma rota nova fique sem teste (B4) |
| Queries e repositórios recebem o contexto do tenant | Atende, com ressalvas | `TenantContext` e `TenantGuard` são usados na maior parte do código. As exceções estão em B3 |
| Auditoria registra tentativas relevantes | **Não atende** | Só **29 de 139** tentativas gravaram `SECURITY_*`, as que passam por `TenantGuard`. Plano, receita, chat, teleconsulta, pré-consulta, metas, dobras, cobranças, pedidos LGPD e as duas APIs negam sem registrar (M1) |

## Achados

### Médio

**M1. A auditoria só registra as negações que passam pelo `TenantGuard`.**
- `TenantGuard::logSecurityViolation` grava `SECURITY_CROSS_TENANT_VIOLATION_BLOCKED` apenas nos asserts de paciente, profissional e consulta.
- Os demais caminhos (repositório que devolve `null`, `findById` com `clinic_id`, checagem própria do controller) respondem 403, 404 ou 302 sem gravar nada.
- Na sonda, **110 das 139 tentativas não deixaram rastro**. Exemplos:
  - `/planos/9010` (403);
  - `/receitas/9020` (404);
  - `/chat/33` (200);
  - `/teleconsulta/9100/atendimento` (403);
  - `/pacientes/3/metas/9050/encerrar`;
  - `/financeiro/recebiveis/9030/pagar`;
  - todas as chamadas das APIs do profissional e da paciente.
- **Correção:** um ponto único que, ao fim de cada pedido autenticado, confere os ids de rota e de formulário. Se algum pertence a outra clínica, registra a tentativa com rota, recurso, id, status e usuário, sem PII. Isso independe de cada controller lembrar de registrar.

**M2. Oráculo de existência na anamnese.**
- `POST /pacientes/{id}/anamnese` responde **403** quando o paciente existe em outra clínica e **404** quando não existe (`DashboardController.php:2174-2180`, que consulta `patients` sem `clinic_id` para decidir).
- Na sonda, foi o único pedido com resposta diferente entre id de outra clínica e id inexistente.
- **Correção:** responder 404 nos dois casos e registrar a tentativa.

**M3. Erro 500 ao encerrar o vínculo do chat de paciente de outra clínica.**
- `POST /pacientes/{id de outra clínica}/chat/vinculo/{linkId}/encerrar` → **500**.
- Causa: `ChatLinkController::end` chama `PatientLinkService::linksForPatient`, que lança `TenantSecurityException` sem tratamento. O código é da NI2-138, que eu escrevi nesta mesma sessão.
- **Correção:** tratar a exceção e responder 404.

**M4. Mensagens com detalhes internos.**
- O texto das exceções do `TenantGuard` vai para a URL e para a tela. Exemplo: `/agenda?error=falha&msg=Acesso negado: o agendamento ID 9001 não pertence à clínica ativa (403 Forbidden).`
- Isso confirma o id e expõe o jargão técnico ao usuário.
- **Correção:** mensagem neutra ("Registro não encontrado nesta clínica."). O detalhe fica só na auditoria.

### Baixo

- **B1.** `GET /teleconsulta/sessao-agenda/{consulta de outra clínica}` mostra a tela "Abrir o Consultório Online" (`TeleconsultationController::openFromAppointment`). O POST é barrado (403), mas a tela deveria ser 404.
- **B2.** Para o mesmo caso (id de outra clínica), as respostas variam: 302 para a lista sem aviso (`/pacientes/1`, `/consulta/9001`, `/planos/9010/itens…`), 400 (`/antropometria/comparar`, `/prontuario/exportar`), 403, 404 e 422 (`/consulta/9001/rascunho`). Não há vazamento, mas a experiência e o monitoramento ficam irregulares. Não corrigi tudo: padronizar todos os controllers foge do escopo. A auditoria única (M1) passa a registrar todos os casos.
- **B3.** Alguns UPDATEs por id não repetem `clinic_id`, embora haja uma checagem logo antes:
  - `FinanceService.php:111` e `:141` (status da cobrança);
  - `ChatService.php:267` e `:269`;
  - `MealPlanService.php:999` (totais do plano);
  - `EngagementService.php:97` (check-in da NI2-96, meu);
  - `SkinfoldService.php:182`;
  - `PreConsultationService.php:269–368` (fluxo da paciente pelo token).

  Uma mudança futura na checagem abriria a escrita cruzada. Vale repetir `clinic_id` no próprio UPDATE como defesa em profundidade.
- **B4.** Não existe teste que percorra as rotas HTTP com ids de outra clínica, nem trava contra rota nova sem cobertura. Os testes de isolamento atuais chamam serviços diretamente.

## O que está bom

- **`TenantContext` e `TenantGuard`** em quase todos os serviços clínicos. Repositórios como `PatientRepository::findById` filtram por `clinic_id`.
- **Ids misturados barrados:** plano próprio com item, refeição ou substituição de outra clínica; paciente próprio com meta, dobras, avaliação, pré-consulta, prontuário ou vínculo de outra clínica. Nada mudou.
- **APIs:** a do profissional (token Bearer com clínica do token) e a da paciente devolvem 404 para recursos de outra clínica.
- **Buscas e listagens** (pacientes, conversas, catálogo, financeiro, pedidos LGPD, painel) não mostram nada da outra clínica.
- **Rotas por token** (pré-consulta, sala de espera, sinalização da teleconsulta, anexo do chat) usam segredo, não id sequencial.

## Testes executados

| Prova | Resultado |
|---|---|
| Sonda HTTP com 139 pedidos (web, API do profissional e API da paciente) | 0 vazamentos e 0 escritas cruzadas; 1 erro 500 (M3); 1 oráculo (M2); 29 de 139 auditados (M1) |
| Varredura estática do SQL sem `clinic_id` | 67 casos revisados; os pontos de escrita estão em B3, e os demais são escopo de plataforma (admin, service desk) ou fluxos por token |
| `cross_tenant_end_to_end_security_test` e `cross_tenant_offensive_test` | PASS |

---

## Status das correções (23/10/2026)

| Achado | Status | O que mudou |
|---|---|---|
| M1. Auditoria parcial | Corrigido | Novo `CrossTenantAccessAuditor`, registrado no início de `public/index.php`. No fim de cada pedido com clínica no contexto (web, API do profissional, API da paciente), confere os ids de rota (31 padrões) e dos campos `patient_id`, `appointment_id`, `meal_id`, `item_id`, `meal_plan_id`, `recipe_id`, `food_id`, `conversation_id`, `receivable_id`, `?id=` da agenda e `a`/`b` da comparação. Se algum pertence a outra clínica, grava **uma** linha `SECURITY_CROSS_TENANT_ACCESS_ATTEMPT` com rota, método, status da resposta, autor (`user:N` ou `patient:N`) e alvos (recurso, id, clínica dona), sem PII. Não registra a própria clínica, os operadores da plataforma (service desk e admin atuam em várias clínicas), os recursos públicos nem o que o `TenantGuard` já registrou no mesmo pedido |
| M2. Oráculo na anamnese | Corrigido | Paciente de outra clínica e paciente inexistente → 404 "Paciente não encontrado." O teste encontrou outro oráculo, no service desk (chamado de outra clínica dava "Acesso negado…" e chamado inexistente dava "…não encontrado"); agora os dois dão "Chamado não encontrado." |
| M3. 500 no encerrar vínculo | Corrigido | `ChatLinkController::end` trata `TenantSecurityException` → 404 |
| M4. Mensagens com detalhe interno | Corrigido | `TenantGuard` (7 mensagens), `RecipeService` (2), `RecipeController`, `PracticeCrmService`, `SecureClinicalStorageService`, anexo do chat e diário da API: textos neutros ("…não encontrado nesta clínica."), sem id, "não pertence" nem "403 Forbidden". O detalhe fica na auditoria |
| B1. Tela da teleconsulta para consulta de outra clínica | Corrigido | `openFromAppointment` → 404 se a consulta não é da clínica |
| B2. Respostas desiguais | Mantido | Todas sem vazamento e todas auditadas, e o teste exige a mesma resposta para id de outra clínica e id inexistente. Padronizar o status de todos os controllers fica para depois |
| B3. UPDATE sem `clinic_id` | Parcial | `clinic_id` acrescentado no UPDATE de status da cobrança (pagar e cancelar) e no do check-in. Os demais operam sobre linhas recém-criadas no mesmo método (dobras, chat) ou rodam fora do contexto de clínica (totais do plano, fluxo da paciente por token); ficaram como estavam |
| B4. Sem teste de rotas | Corrigido | `tests/ni2_24_cross_tenant_routes_real_test.cjs`, descrito abaixo |

### O teste novo (`tests/ni2_24_cross_tenant_routes_real_test.cjs`)

- **Cobertura:** lê todas as rotas com parâmetro de `public/index.php` e **falha se alguma não estiver coberta** nem excluída com motivo. Hoje são 109 rotas: 94 cobertas e 15 excluídas (token ou segredo, público por desenho e console da plataforma).
- **Ataques:** 111 pedidos com ids da clínica 1, vindos da dona da clínica 2 (web e API do profissional) e da paciente Carla (API da paciente), incluindo ids misturados. Para cada pedido, o teste exige:
  - nenhum dado da clínica 1 na resposta;
  - nenhuma alteração nas tabelas da clínica 1 (impressão digital conferida pedido a pedido);
  - nenhum 5xx;
  - nenhuma mensagem com detalhe interno;
  - registro na auditoria;
  - mesma resposta que um id inexistente.
- **Listagens:** 14 listagens e buscas, sem nada da clínica 1.
- **Sem falso alerta:** a própria clínica acessando os mesmos ids não gera alerta.
- **Estado final:** a clínica 1 termina intacta.

### Execução

| Prova | Resultado |
|---|---|
| Teste novo, SQLite | 11/11 (`docs/evidencias/ni2-24/depois-sqlite.log`) |
| Teste novo, MySQL 8.0.46 | 11/11 (`depois-mysql.log`) |
| Mesmo teste no código anterior (HEAD 3b3cdf9) | 6 PASS, 5 FAIL: 500 no vínculo; mensagens internas em 7 rotas; auditoria em 24 de 111 pedidos; nenhum registro com autor e alvo; oráculos na anamnese e no service desk (`antes.log`) |
| Regressão | 97 suítes legadas sem falha nova; HTTP/navegador NI2-73, 89, 91, 96, 135, 137, 138, 298, 412, 433, 437 todos PASS (`regressao-suites.txt`) |

### Limites

- **Ambiente real:** o teste roda com dados fictícios em banco isolado. Não foi feito teste de intrusão no ambiente da HostGator (NOT TESTED).
- **Escopo da auditoria:** cobre ids de rota e os campos listados. Um id de recurso clínico em campo com outro nome não é conferido, mas a escrita continua bloqueada pela regra de cada serviço.
- **v20:** não foi alterado.
