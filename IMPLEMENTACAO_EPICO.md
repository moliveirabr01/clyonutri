# IMPLEMENTACAO_EPICO.md — Épico NI2-844 (`v54.62`)

- **Épico Jira:** `NI2-844` — `[AUD-UX WEB][v54.61] Simplificação das jornadas do nutricionista — findings da auditoria UX 360°`
- **Histórias Filhas Implementadas:** `NI2-845`, `NI2-846`, `NI2-847`, `NI2-848`, `NI2-849`, `NI2-850`, `NI2-851`, `NI2-852`, `NI2-853`, `NI2-854`, `NI2-855` (cobrindo integralmente os achados `UX-001` a `UX-019`)
- **Codebase Canônica:** `/Users/moliveira/Nutri_II`
- **Versão Entregue:** `v54.62` (base `v54.61`)
- **Data da Execução:** 15/11/2026

---

## 1. Visão Geral da Implementação por História

### 1.1 `NI2-845` (`[UX-001][P1]`) — Inserir alimento dentro da refeição com medida caseira padrão
- **Banco de Dados / Catálogo (`database/migrations/2026_11_15_000094_ni2_845_seed_common_household_measures.php` & `src/Domain/Nutrition/HouseholdMeasureConverter.php`):**
  - `HouseholdMeasureConverter::ensureCommonMeasures(PDO $db)` semeia de forma idempotente medidas caseiras padrão (`unidade`, `fatia`, `colher de sopa`, `concha`, `filé`, `posta`, `bife`, `xícara`, `copo`, `porção`, `pegador`, `escumadeira`) para mais de 300 alimentos frequentes do catálogo TACO (`Pão, trigo, francês` = `unidade` 50 g / `fatia` 25 g; `Mamão, Formosa, cru` = `fatia` 100 g; `Ovo de galinha cozido` = `unidade` 50 g; `Arroz integral cozido` = `colher de sopa` 25 g; `Feijão carioca cozido` = `concha` 86 g; `Frango, peito, sem pele, grelhado` = `filé` 100 g; `Azeite de oliva extravirgem` = `colher de sopa` 13 g).
  - `HouseholdMeasureConverter::defaultPrescriptionForFood(int $foodId, ?array $food, ?int $clinicId)` retorna a sugestão imediata de prescrição priorizando: (1) última medida usada pela clínica para aquele alimento, (2) medida caseira primária do alimento (com quantidades usuais como 4 colheres de sopa para arroz, 2 colheres de sopa para aveia e 1 unidade/fatia/concha/filé para os demais), ou (3) fallback em `100 g`.
  - Quando a medida selecionada pertence a `food_household_measures` do alimento, o sistema **não exige** marcar `"Confirmo o peso estimado"`.
- **Frontend & Controller (`src/Application/Controllers/DashboardController.php`, `public/assets/js/food-picker.js`, `public/assets/js/plan-live.js`):**
  - Cada card de refeição em `#plan-live-meals` possui agora sua própria barra inline de busca e adição (`form#meal-inline-add-{mealId}`, `data-meal-inline-add`, `data-testid="meal-inline-add-{mealId}"`), eliminando a necessidade de rolar até o topo da página ou re-selecionar a refeição a cada alimento.
  - Ao escolher um alimento no dropdown inline, `food-picker.js` pré-preenche automaticamente a medida caseira padrão e a quantidade (`default_measure_unit`, `default_amount`) e foca a quantidade/botão para confirmação com `Enter`.

### 1.2 `NI2-846` (`[UX-002][P1]`) — Ranking de busca de alimentos por relevância clínica
- **Motor de Busca (`src/Domain/Food/FoodSearchIndex.php`, `src/Application/Controllers/FoodSearchController.php`, `public/assets/js/food-picker.js`):**
  - Implementada expansão morfológica PT-BR (`cru`/`crua`, `cozido`/`cozida`, `grelhado`/`grelhada`, `assado`/`assada`, `frito`/`frita`, `desnatado`/`desnatada`), remoção de *stop words* (`de`, `do`, `da`, `com`, `sem`, `em`, `para`, `no`, `na`, `ao`, `a`, `o`, `e`) e ponderação clínica:
    - Penalização (`$secondaryPenalty = +20`) para miúdos, cortes secundários e preparações industriais (`coração`, `fígado`, `moela`, `língua`, `pé`, `asa`, `pescoço`, `sobrecoxa`, `pele`, `empanado`, `nuggets`, etc.) quando o nutricionista não digitou a parte secundária.
    - Bonificação (`$stapleBoost = -12`) para cortes primários e alimentos base (`peito`, `filé`, `sem pele`, `integral`, `polpa`) quando a busca é genérica (ex.: `"frango grelhado"`).
  - `FoodSearchIndex::detectPartHighlight(string $name)` identifica o corte/parte (`peito`, `coração`, `coxa`, `sobrecoxa`, `asa`, `fígado`, `moela`, `filé`, `lombo`, `acém`, `patinho`, `alcatra`, `mignon`, `clara`, `gema`) e o seletor renderiza o selo visual `<span class="food-result__badge food-result__badge--part" data-testid="food-part-badge">Parte: ...</span>`.
  - Validado benchmark de 12 buscas clínicas frequentes com **100% (12/12)** de acerto no top 3 e `"Frango, peito, sem pele, grelhado"` em **1º lugar** para `"frango grelhado"`.

### 1.3 `NI2-847` (`[UX-003][P1]`) — Campos numéricos aceitam vírgula decimal e mostram o valor interpretado
- **Domínio & Persistência (`src/Domain/Anthropometry/AnthropometryService.php`, `src/Domain/Nutrition/EnergyCalculator.php`, `src/Domain/MealPlan/EnergyTargetService.php`):**
  - `AnthropometryService::parseDecimalInput` e `parseOptionalDecimal` aceitam vírgula e ponto decimal (`"72,4"` $\rightarrow$ `72.4`, `"1,7"` $\rightarrow$ `1.7`), rejeitam entradas não numéricas (`"abc"`) lançando `InvalidAnthropometricDataException` (sem `TypeError` em `strict_types=1`), e `normalizeHeightCm` converte valores em metros (`0.50` a `2.50` m, como `"1,7"`, `"1,70"`, `"1.70"`) para centímetros (`170.0 cm`).
  - Mesma normalização aplicada em `EnergyCalculator::compute`, `EnergyTargetService::save`, `DashboardController::completeConsultation` e `DashboardController::recordPatientEvolution`.
- **Frontend (`public/assets/js/decimal-input.js`, `views/layouts/professional-shell.php`, `views/consultation/room.php`, `src/Application/Controllers/DashboardController.php`):**
  - Campos numéricos clínicos migrados para `type="text" inputmode="decimal"` com atributos `data-decimal-field`, `data-min` e `data-max`.
  - `decimal-input.js` exibe em tempo real o selo interpretado (`[data-decimal-echo]`, ex.: `Interpretado: 170 cm (1,70 m)` ou `Interpretado: 72,4 kg`) e alerta valores fora da faixa antes do envio.

### 1.4 `NI2-848` (`[UX-004][P1]`) — Workspace único de atendimento (Fase 1)
- **Barra Única de Etapas (`src/Application/View/AttendanceContext.php`, `src/Application/View/PatientHeader.php`, `views/consultation/room.php`):**
  - `AttendanceContext::stepsNav` consolida a navegação do atendimento em uma única barra (`[data-testid="attendance-steps"]`) com as etapas **Anamnese**, **Avaliação**, **Plano alimentar**, **Revisão e publicação**, **Evolução** e **Fechamento**, visível tanto em `/consulta/{id}` quanto nas telas do paciente durante o atendimento.
  - Removidos do cabeçalho do paciente os botões duplicados (`Voltar ao atendimento #N`, chips redundantes), mantendo 1 único CTA primário (`Continuar atendimento`) e a barra de etapas.
- **Painel Lateral Recolhível de Histórico Clínico (`views/consultation/room.php`):**
  - Adicionada gaveta lateral recolhível (`#consultation-history-drawer`, `data-testid="consultation-history-drawer"`, acionada por `[data-testid="toggle-history-drawer"]`) na sala de atendimento `/consulta/{id}`, exibindo últimas evoluções, última avaliação antropométrica e resumo do plano vigente sem sair da consulta.

### 1.5 `NI2-849` (`[UX-005][P1]`) — Fechamento da consulta (retorno em 1 clique + entrega do plano)
- **Serviço e Rotas (`src/Domain/Appointment/AppointmentService.php`, `src/Application/Controllers/DashboardController.php`, `public/index.php`, `routes/web.php`):**
  - `AppointmentService::suggestFollowUpSlot(int $professionalId, string $baseDateTime, int $durationMinutes, int $daysAhead = 30)` calcula automaticamente o próximo horário livre em ~30 dias respeitando dias úteis e conflitos de agenda.
  - Nova rota `POST /consulta/{id}/retorno` (`DashboardController::scheduleFollowUpFromClosing`) agenda o retorno em 1 clique a partir do painel de fechamento, com proteção CSRF e isolamento multi-tenant.
- **Painel de Fechamento (`views/consultation/_closing_panel.php`, `views/consultation/room.php`, `src/Application/Controllers/DashboardController.php`):**
  - Ao concluir a consulta (`POST /consulta/{id}/concluir` ou `?fechamento={id}`), o profissional recebe imediatamente o Painel de Fechamento (`#consultation-closing-panel`, `data-testid="consultation-closing-panel"`) contendo:
    1. **Agendar retorno sugerido em 1 clique** (`data-testid="closing-schedule-followup-btn"`, editável em data/hora/duração);
    2. **Entregar plano alimentar ao paciente** (download de PDF `data-testid="closing-plan-pdf"` e liberação/convite no app/portal);
    3. **Próximo atendimento do dia** (`data-testid="closing-next-patient"`) ou volta direta à agenda.

### 1.6 `NI2-850` (`[UX-006][P2]`) — Simplificar o onboarding para 3 passos sem cobrança forçada e sem duplicidade de CRN
- **`src/Domain/Onboarding/OnboardingService.php` & `src/Application/Controllers/OnboardingController.php`:**
  - Stepper visual unificado em **3 passos** (`1. Como você atende`, `2. Primeiro paciente`, `3. Pronto para atender`) via `OnboardingService::wizardSummary(array $progress)`.
  - Removida a seleção obrigatória de plano pago no Passo 1 durante o período de avaliação gratuita (*trial*).
  - Quando o CRN e a região já foram informados em `/register`, o Passo 1 exibe o resumo pré-preenchido (`data-testid="onboarding-crn-prefilled"`) com edição opcional em `<details>`, e ao salvar avança diretamente para o cadastro do primeiro paciente.
  - No Passo 3 (Ativação), itens não aplicáveis (horários e link público quando o agendamento online está desligado) ficam recolhidos em `<details data-testid="activation-optional-disabled">`, garantindo que `<strong data-testid="activation-count">X de Y</strong>` bata exatamente com os `Y` itens visíveis em `<ol data-testid="activation-checklist">`.

### 1.7 `NI2-851` (`[UX-008][P2]`) — Preservar rascunho em queda de rede e expiração de sessão com reenvio automático
- **`public/assets/js/consulta-rascunho.js` & `public/assets/js/workflow-drafts.js`:**
  - Persistência local em `sessionStorage` escopada por `clinic_id + user_id + resource_id` com TTL de 15 minutos (`CLYO_DRAFT_TTL_MS = 15 * 60 * 1000`).
  - Listener `window.addEventListener('online', ...)` reenvia automaticamente o rascunho pendente em $\le 5\text{ s}$ após o retorno da rede.
  - Em caso de `401`/`419` (sessão expirada), preserva o rascunho antes de redirecionar ao login e restaura-o ao reabrir a tela com o aviso `Rascunho recuperado automaticamente — última edição às HH:MM` (`data-testid="draft-recovered-banner"`).

### 1.8 `NI2-852` (`[UX-009][P2]`) — Unificar fuso horário dos selos de salvamento e eliminar estado contraditório na anamnese
- **`src/Domain/MealPlan/MealPlanService.php`, `public/assets/js/operational-ui.js`, `views/anamnesis/form_page.php`:**
  - `MealPlanService` grava `created_at` e `updated_at` no horário local da aplicação (`America/Sao_Paulo` via `date('Y-m-d H:i:s')`), eliminando a divergência de `+3h` UTC nos selos `"Salvo às HH:MM"` do editor de plano.
  - `operational-ui.js` dispara o evento `clyo:workflow-state` (`saving`, `saved`, `error`) e `views/anamnesis/form_page.php` mantém `#anamnesis-save-status` estritamente sincronizado com `[data-workflow-state]`, impedindo que rodapé e topo exibam estados contraditórios.

### 1.9 `NI2-853` (`[UX-010][P2]`) — Unificar seletor de paciente na agenda e pré-preencher próximo horário válido
- **`src/Application/Controllers/DashboardController.php` & `public/assets/js/agenda-form.js`:**
  - Unificados o campo de busca e o `<select>` de paciente em um único combobox acessível (`[data-testid="agenda-patient-combobox"]`, mantendo `#dc11-patient_id` como `visually-hidden tabindex="-1"` sincronizado).
  - Ao abrir `Nova Consulta` (`/agenda?novo=1`), data e hora são pré-preenchidas com `$this->nextValidSlot(...)` (próximo slot comercial livre em intervalos de 30 min), exibindo `[data-testid="agenda-suggested-slot"]`.

### 1.10 `NI2-854` (`[UX-012, UX-014, UX-015][P2]`) — Enxugar elementos de baixa frequência na consulta, dashboard e menu lateral
- **`UX-012` (`views/consultation/room.php`, `views/consultation/partials/realtime_transcription.php`):** bloco de transcrição em tempo real movido para abaixo de `#evolution_summary` e aviso longo de LGPD recolhido em `<details data-testid="stt-lgpd-details">`.
- **`UX-014` (`src/Application/Controllers/DashboardController.php`):** adicionado banner prioritário `"Continuar de onde parou"` (`data-testid="dash-continue-card"`) no topo de `/dashboard` quando há consulta em andamento.
- **`UX-015` (`views/layouts/_professional_navigation.php`, `src/Application/View/RoleNav.php`, `public/assets/js/navigation-v54-58.js`, `public/assets/css/workspace-v54-58.css`):** menu lateral enxugado para **7 itens visíveis no 1º nível** (`Início`, `Agenda`, `Pacientes`, `Planos alimentares`, `Conversas`, mais os grupos recolhíveis `<details data-testid="nav-group-biblioteca">Biblioteca nutricional ▾</details>` e `<details data-testid="nav-group-gestao">Gestão ▾</details>` com estado persistido em `sessionStorage`).

### 1.11 `NI2-855` (`[UX-007, UX-011, UX-013, UX-016, UX-017, UX-018, UX-019][P3]`) — Polimento de microcopy e atalhos
- **`UX-007` (`src/Domain/Dashboard/DashboardOverview.php`):** `DashboardOverview::firstName` remove prefixos honoríficos (`Dr.`, `Dra.`, `Doutor`, `Doutora`), exibindo `"Olá, Helena"` em vez de `"Olá, Dra."`.
- **`UX-011` (`src/Application/Controllers/DashboardController.php`):** botão `"Iniciar"` na agenda submete `POST /agenda/{id}/status` (`status=in_progress`, `data-testid="agenda-start-appointment"`) em 1 clique.
- **`UX-013` (`src/Domain/MealPlan/MealPlanService.php`, `views/patients/_operational_strip.php`, `src/Application/Controllers/DashboardController.php`):** corrigido `"1 refeição(ões)"` para `"1 refeição"`, traduzido `'published'` para `'publicado'` na faixa operacional e removido o botão duplicado `+ Novo Lançamento Financeiro` em `/financeiro`.
- **`UX-016` (`views/consultation/room.php`):** card de antropometria na sala de atendimento exibe o resumo da última avaliação (`data-testid="consult-latest-anthro-summary"`) e atalho para a aba Avaliação (`data-testid="consult-open-anthropometry"`).
- **`UX-017` (`views/auth/register.php`):** placeholder `"Mínimo de 8 caracteres"` no campo de senha, `grid-template-columns: 1fr 1.35fr` sem truncar `"CRN-3 (SP/MS)"`, e CTA `"Criar meu consultório"` com `min-height: 48px`.
- **`UX-018` (`src/Application/Controllers/DashboardController.php`, `public/assets/js/operational-ui.js`):** busca sem resultado em `/pacientes?q=...` oferece o botão `"Cadastrar «{termo}»"` (`data-testid="patients-empty-register-cta"`), abrindo `#patientModal` com `#p_name` pré-preenchido.
- **`UX-019` (`src/Application/Controllers/DashboardController.php`, `src/Application/Controllers/WorkflowController.php`, `public/assets/js/plan-live.js`):** aviso de alimento duplicado na mesma refeição (`data-testid="duplicate-food-notice"`) com botão de somar quantidades em 1 clique (`data-testid="merge-duplicate-food-btn"`, `POST /planos/{id}/itens/{itemId}/somar` e `action=merge_duplicate`).
