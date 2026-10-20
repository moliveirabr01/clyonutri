# Code review — NI2-9 · Plano alimentar e prescrição nutricional

**Data:** 19/10/2026 · **Base revisada:** `~/Nutri_II`, branch `merge/v11-teleconsulta`, commit `496f4ff` (HEAD). Também revisei as alterações ainda não commitadas em `MealPlanService.php` e `MealPlanPrintService.php`.
**Escopo:** `src/Domain/MealPlan/*`, `src/Domain/Nutrition/{NutritionCalculator,HouseholdMeasureConverter}.php`, `src/Domain/Clinical/AllergenSafetyValidator.php`, as ações de plano do `DashboardController` (linhas 3244–4170), `PatientApiController::getDailyMealPlan`, as rotas `/planos/*` em `public/index.php`, as migrações 03/05/13 e as suítes `meal_plan_*` e `clinical_safety_and_precision_test.php`.

## Resumo

A estrutura do épico está bem montada:
- o rascunho é separado da versão publicada;
- cada publicação grava um snapshot em `meal_plan_versions`;
- a duplicação guarda a linhagem em `parent_plan_id`;
- o cálculo de energia e macros é determinístico;
- todas as consultas filtram por clínica (`TenantGuard`);
- a edição de item (NI2-310) tem trava otimista.

Reproduzi o resultado do relatório no HEAD: **47/47 PASS** na suíte do ciclo de vida, 13/13 na de CSRF e a suíte clínica aprovada.

Os testes, porém, só cobrem o caminho feliz. A trava de alérgenos, apresentada como "bloqueio fail-closed", **na prática deixa passar** a maior parte da base TACO. A publicação e o override clínico não registram quem fez. Um usuário sem CRN consegue criar e publicar uma prescrição assinada pelo CRN de outra nutricionista. Além disso, o trabalho em andamento sobre o equivalente em gramas, ainda não commitado, quebra o editor no SQLite e mostra gramas errados no MySQL.

**Veredito: pedir alterações. NI2-9 continua em "Fazendo"; não mover para "Feito" antes de corrigir C1, A1–A3 e não commitar o WIP como está.**

Os achados marcados com **[prova]** foram reproduzidos com `tests/review_ni2_9_provas.php` contra o HEAD em SQLite. Os achados do WIP foram reproduzidos rodando as suítes na árvore de trabalho e com MariaDB 10.11 no `sql_mode` padrão. Os demais vêm da leitura do código.

## Status das correções (19/10/2026, mesmo dia)

Todos os achados foram corrigidos na árvore de trabalho (sem commit). Cobertura: `tests/ni2_9_code_review_regression_test.php` (42/42 no SQLite; o mesmo teste falha no HEAD antigo) e um teste de fumaça em MariaDB 10.11 (11/11), que inclui travamento real entre conexões.

| # | Status | O que mudou |
|---|---|---|
| C1 | Corrigido (código); **pendente (dados)** | Validador fail-closed: alimento com `allergens` NULL (TACO importada e alimentos da clínica) vira "não verificado"; alergia não reconhecida também exige confirmação. Taxonomia ampliada (celíaca, APLV, frutos do mar → crustáceos + moluscos, pecã, pinoli) e termos de produto no nome (pão, biscoito, bolo, macarrão…). `''` continua significando "verificado, sem alérgenos" (catálogo curado). **Falta a curadoria clínica dos alérgenos da TACO**: até lá, todo plano de paciente com alergia registrada pede confirmação com justificativa. |
| A1 | Corrigido | `prescriberProfessionalId()` (nutricionista ativo, membro ativo, com CRN) em todas as ações que alteram `/planos/*`; o autor do plano é sempre o profissional logado, sem fallback. |
| A2 | Corrigido | Publicação via `AuditLogger` (usuário e IP), justificativa obrigatória (≥ 10 caracteres) no override, gravada em `meal_plan_versions.allergen_override_reason` e `published_by_user_id`. |
| A3 | Corrigido | Subconsulta com `\|\|` removida; `weight_grams` e `weight_is_estimated` gravados no cálculo e exibidos no editor e na impressão. Migração 43 preenche as linhas antigas (resultado idêntico entre PHP e SQL manual no MariaDB). |
| M1 | Corrigido | API do paciente serve o `payload_json` da versão vigente, com valores da porção (antes vinha o valor por 100 g do catálogo). Leitura das tabelas mantida só para plano publicado sem versão (legado). |
| M2 | Corrigido | Só rascunho publica; troca `draft → published` é a primeira escrita da transação; `UNIQUE(clinic_id, patient_id, version_number)` (não criado se já houver repetição; ver preflight). |
| M3 | Corrigido | Medida caseira genérica marcada "peso estimado" no editor, na prévia e na impressão. |
| M4 | Corrigido | Mutações em transação com guarda de status (MySQL: `SELECT … FOR UPDATE`; SQLite: `UPDATE` guarda). |
| B1 | Corrigido | `is_finite` em todo o cálculo e teto de 5 kg por item. O teto revelou um bug: o rascunho automático (`MealPlanDraftAssistant`) prescrevia 100 unidades de banana (10 kg); corrigido para 1. |
| B2 | Corrigido | Teste de regressão novo; suítes ajustadas onde o comportamento mudou de propósito (justificativa no override). |

## Problema crítico (bloqueia a homologação)

| # | Arquivo | Linha | Problema |
|---|---|---|---|
| C1 | AllergenSafetyValidator.php | 19–28, 99–117 | **[prova A1] A trava de alérgenos deixa passar quase tudo.** A detecção busca substrings no nome do alimento e no campo `foods.allergens`, mas só **6 dos 600** alimentos do catálogo global têm esse campo preenchido. Para uma paciente com glúten registrado na anamnese, 19 de 26 produtos de trigo não foram sinalizados (cream cracker, biscoitos maisena e recheados, bolos, macarrões etc.). Um plano com cream cracker foi **publicado sem alerta** com `override = false`. A taxonomia tem 8 grupos, mas o comentário diz "14 (RDC 26/2015)". "Frutos do mar" e "doença celíaca" na anamnese não viram grupo nenhum: só "glúten" foi detectado, e frutos do mar foram ignorados. Termos como "APLV" também não são reconhecidos. **Correção:** (1) preencher `foods.allergens` de forma estruturada para todo o catálogo global, com origem rastreável (rótulo/RDC 26 e revisão de nutricionista), e tornar o campo obrigatório nos alimentos da clínica; (2) guardar as alergias da anamnese como lista de grupos canônicos, não como texto livre; (3) quando o alimento não tem informação de alérgenos e a paciente tem alergia registrada, tratar como "não verificado" e exigir confirmação, que é o comportamento fail-closed prometido; (4) testes negativos com produtos reais do TACO. |

## Problemas altos

| # | Arquivo | Linha | Problema |
|---|---|---|---|
| A1 | DashboardController.php | 2203–2221, 3291, 3821–4000 | **[prova A7] Usuário sem CRN cria e publica uma prescrição assinada por outra nutricionista.** `getActiveProfessionalId()` cai no "primeiro profissional da clínica" (e, no limite, em `1`) quando o usuário não tem registro em `professionals`. No teste, o usuário 902 (`member_role = admin`, sem CRN) criou um plano gravado como da Dra. Ana, CRN-9001, que é a assinatura da impressão. A autorização também é inconsistente: o editor (GET) e `updatePlanItem`/`duplicate` exigem `canEditPlanItems`, mas `createMealPlan`, `addMeal`, `addItem`, `addSubstitution`, os `delete*` e `publishMealPlan` não exigem. O mesmo usuário recebe 403 no editor e mesmo assim **publicou com override de alérgeno** (`status = published`). **Correção:** um único `assertCanPrescribe()` (profissional com CRN ativo na clínica) em todas as rotas `/planos/*` que alteram dados, e `professional_id` sempre igual ao do usuário logado, sem fallback. |
| A2 | MealPlanService.php | 826–840 | **[prova A2] A publicação e o override de alérgeno não registram o autor.** O `INSERT INTO audit_logs` grava `user_id = NULL` e `ip_address = NULL`. O override é só um checkbox, sem justificativa. Uma decisão clínica de prescrever um alérgeno conhecido fica sem dono, o que contraria o critério de trilha de auditoria da NI2-79. **Correção:** usar o `AuditLogger` (que preenche usuário e IP), gravar `user_id`/`professional_id` do ator e exigir um texto de justificativa no override, guardado no log e no snapshot da versão. |
| A3 | MealPlanService.php / MealPlanPrintService.php | 617–631 (WIP) | **[prova] O WIP do equivalente em gramas não pode ser commitado como está.** (a) No **SQLite**, a subconsulta correlacionada com `ORDER BY CASE … i.measure_unit` gera `no such column: i.measure_unit`. Com o WIP, `meal_plan_and_prescription_lifecycle_test` cai depois do 7º teste e `clinical_safety_and_precision_test` também cai, ou seja, o editor e a publicação quebram no ambiente local. (b) No **MySQL/MariaDB**, `\|\|` é OR lógico, não concatenação (sem `PIPES_AS_CONCAT`). `x LIKE '%' \|\| y \|\| '%'` vira `(x LIKE '%') OR …`, que é sempre verdadeiro, então qualquer medida do alimento casa. No MariaDB, "2 xícara" exibiu o equivalente de "colher de sopa" (25 g/unidade). (c) Mesmo corrigida, a busca aproximada por `LIKE` diverge do conversor, que usa igualdade exata: o "(~X g)" mostrado na tela e na impressão pode não ser o peso usado no cálculo das calorias. **Correção:** gravar `weight_grams` em `meal_plan_items` e em `meal_plan_substitutions` no momento do cálculo (o `NutritionCalculator` já devolve esse valor) e exibir esse campo. Nada de subconsulta aproximada. |

## Sugestões (médias e baixas)

| # | Arquivo | Linha | Sugestão | Categoria |
|---|---|---|---|---|
| M1 | PatientApiController.php | 26–80 | **[prova A6]** A API e o portal do paciente leem as tabelas vivas (`meal_plans`, `meal_plan_items` e `foods` com JOIN), não o snapshot de `meal_plan_versions`. Depois de publicar, renomear um alimento da clínica mudou o que o paciente vê ("Pão RENOMEADO…", com 999 kcal/100 g), enquanto o snapshot continuava com o original. O critério da NI2-79 diz que o paciente vê exatamente a versão publicada. Servir o `payload_json` da versão vigente (`getPatientPublishedPlan` já existe). | Correção clínica |
| M2 | MealPlanService.php | 712–720 | **[prova A3]** Só `published` é bloqueado na republicação. Um plano `superseded` (ou `archived`) é republicado e vira a versão vigente de novo (v3 apontando para o plano da v1). Isso pode ser intencional como "reverter", mas aí precisa de ação e auditoria próprias. A leitura da prévia e a checagem de status ficam fora da transação, e `meal_plan_versions` não tem índice nem `UNIQUE(clinic_id, patient_id, version_number)`, então duas publicações simultâneas podem gerar números repetidos ou duas versões. Fazer `UPDATE … SET status='published' WHERE id=? AND status='draft'` exigindo `rowCount() === 1`, criar o índice único e travar a linha do paciente no MySQL. | Concorrência / regra de negócio |
| M3 | HouseholdMeasureConverter.php | 44–48 | **[prova A4]** Sem medida específica cadastrada, "unidade" vale 100 g para qualquer alimento: 1 ovo foi calculado como 100 g e 146 kcal, o dobro de um ovo médio. O mesmo vale para "porção" (100 g) e "fatia" (30 g). O ovo não tem nenhuma medida caseira cadastrada. Para essas medidas genéricas, exigir medida específica do alimento ou mostrar um aviso de "peso estimado" na prescrição. | Precisão nutricional |
| M4 | MealPlanService.php | 235–330, 490–590 | `addMeal`, `deleteMeal`, `addItem`, `deleteItem`, `addSubstitution` e `deleteSubstitution` checam o status e alteram em comandos separados, sem transação e sem `status = 'draft'` no SQL (só `updateItem`, da NI2-310, faz isso). Uma publicação no meio desse intervalo deixa as tabelas vivas diferentes do snapshot. Junto com M1, isso muda o que o paciente vê sem gerar versão. Aplicar o mesmo padrão do `updateItem`. *(Por leitura, não reproduzido.)* | Concorrência |
| B1 | MealPlanService.php | 356, 514 | **[prova A5]** `addItem` e `addSubstitution` não usam `is_finite` (o `updateItem` usa). A quantidade `1e999` foi aceita, gravada como texto `INF`, e o total do plano ficou **0 kcal** em silêncio. Aplicar a mesma validação do `updateItem`, com um teto razoável (por exemplo, 5.000 g). | Validação |
| B2 | testes | — | As suítes não têm testes negativos para os cenários A1–A7 e não rodam em MySQL. Incluir `review_ni2_9_provas.php` como base de regressão, invertendo as asserções. | Testes |

## O que está bom

- Ciclo rascunho → publicado → `superseded`, com snapshot JSON imutável por versão.
- Edição bloqueada fora de rascunho (`assertPlanIsEditable`) e duplicação profunda com `parent_plan_id`, sem tocar no original.
- Isolamento por clínica em todas as leituras e escritas, incluindo os alimentos próprios de cada clínica.
- Substituições com macros próprias, sem inflar o total da refeição.
- Trava otimista com `revision` em `updateItem` (NI2-310), um bom modelo para as demais mutações.
- Impressão a partir do snapshot da versão publicada e CSRF em todos os formulários.

## Provas reproduzidas (`tests/review_ni2_9_provas.php`, HEAD em SQLite)

A1 19 de 26 produtos de trigo e frutos do mar sem alerta; plano com cream cracker publicado para paciente celíaca sem override · A2 log `MEAL_PLAN_PUBLISHED` com override e `user_id = null`, `ip_address = null` · A3 plano `superseded` republicado como v3; nenhum índice em `meal_plan_versions` · A4 1 unidade de ovo = 100 g / 146 kcal · A5 quantidade `1e999` aceita, item `INF`, total 0 kcal · A6 API do paciente mostra o alimento renomeado depois da publicação; snapshot mostra o original · A7 usuário sem CRN → plano assinado pelo CRN-9001 de outra profissional; editor 403, mas a publicação passou.
WIP: duas suítes caem no SQLite (`no such column: i.measure_unit`); no MariaDB 10.11, "xícara" recebe o equivalente de "colher de sopa".

## Ordem sugerida de correção

1. A3: tirar o WIP do equivalente em gramas da árvore e trocar por `weight_grams` persistido.
2. A1 + A2: autorização única de prescrição e autoria na auditoria, com justificativa no override.
3. C1: dados de alérgenos estruturados e comportamento fail-closed real (precisa de decisão clínica sobre a fonte dos dados).
4. M1 + M4: o paciente lê só o snapshot; mutações com guarda de status no SQL.
5. M2, M3, B1: concorrência na publicação, medidas genéricas e validação de quantidade.
6. B2: testes negativos e uma rodada em MySQL.
