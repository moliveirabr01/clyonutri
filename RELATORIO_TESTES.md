# Relatório de Testes — Épico NI2-844 (`v54.62`)

**Data de conclusão:** 15/11/2026 · **Base canônica:** `/Users/moliveira/Nutri_II` · **Versão:** `v54.62`

---

## 1. Suíte Dedicada do Épico (`NI2-844` — `NI2-845` a `NI2-855`)

```bash
php tests/v5462_ni2_844_ux360_test.php
```
- **Resultado:** **55 PASS, 0 FAIL** (executado após a última edição em `DashboardController.php`).
- **Escopo coberto:**
  - `NI2-847` (UX-003): `recordAssessment` com vírgula decimal (`72,4` kg, `1,7` m → `170.0 cm`, IMC `25.05`, RCQ `0.78`), normalização `1,70` / `1.70` / `170` / `170,0`, `EnergyCalculator::compute`, `EnergyTargetService::save`, negativos (`"abc"` em peso/cintura → `InvalidAnthropometricDataException`), limites (`500` kg, `0` cm).
  - `NI2-846` (UX-002): ranking clínico (`"frango grelhado"` → `Frango, peito, sem pele, grelhado` em 1º; `"coracao de frango"` → `Frango, coração, grelhado` em 1º; `detectPartHighlight`; 12/12 buscas frequentes no top 3).
  - `NI2-845` / `NI2-855` (UX-001, UX-019): 8 alimentos comuns com medida caseira padrão sem exigir confirmação manual, barra inline no card da refeição, aviso e soma de duplicados em 1 clique (`merge_duplicate`), isolamento multi-tenant na fusão de itens.
  - `NI2-852` / `NI2-855` (UX-009, UX-013): `meal_plans.updated_at` em `America/Sao_Paulo`, pluralização `"1 refeição"` (sem `"refeição(ões)"`), tradução de `"published"` para `"publicado"`.
  - `NI2-848`, `NI2-849`, `NI2-854`, `NI2-855` (UX-004, UX-005, UX-012, UX-016): barra de etapas única, painel recolhível *Histórico clínico*, resumo antropométrico sem duplicar formulário, LGPD em `<details>`, 1 CTA primário no cabeçalho, painel de fechamento com `suggestFollowUpSlot` (~30 dias) e entrega de PDF.
  - `NI2-850` (UX-006): `wizardSummary` em 3 passos (0% → 33% no Passo 1), sem cobrança forçada, contador de checklist `4 de 4`.
  - `NI2-851` (UX-008): `ConsultationDraftService` com persistência versionada, recuperação após reconexão e listeners `online` em `consulta-rascunho.js` / `workflow-drafts.js`.
  - `NI2-853` / `NI2-855` (UX-010, UX-011): combobox único na agenda, próximo horário comercial válido, botão *Iniciar* em 1 clique (`POST /agenda/{id}/status`).
  - `NI2-854` / `NI2-855` (UX-007, UX-013, UX-014, UX-015, UX-017, UX-018): remoção de prefixo `"Dra."`/`"Dr."` em `DashboardOverview::firstName`, card *Continuar de onde parou*, menu lateral com exatamente 7 itens no 1º nível, busca vazia em `/pacientes` com CTA de cadastro pré-preenchido, botão único `+ Novo lançamento financeiro` em `/financeiro`, `/register` sem truncamento e CTA >= 48px.

---

## 2. Teste E2E Real em Navegador (Playwright + Chromium + axe-core)

```bash
E2E_ONLY=ni2844 bash tests/run_release_gate.sh --browser
```
- **Relatório:** `/Users/moliveira/Nutri_II/storage/e2e-report/v54.62-20261115-205724`
- **Resultado:** **GO — 48 PASS, 0 FAIL** (`0` erros JS no console, `0` respostas HTTP 500).
- **Bug real detectado e corrigido durante o E2E:** Em [`DashboardController::scheduleFollowUpFromClosing`](file:///Users/moliveira/Nutri_II/src/Application/Controllers/DashboardController.php#L2799-L2809), os parâmetros `$type` e `$duration` da chamada `AppointmentService::schedule(...)` estavam invertidos, gerando `TypeError` em `POST /consulta/{id}/retorno`. Corrigido e validado em navegador real (`total de retornos: 1`).

### Comparação de Esforço: Baseline Auditoria UX 360° (`v54.61`) vs `v54.62`

| Métrica | Baseline `v54.61` | Medido em `v54.62` (`ni2844.js`) | Redução |
|---|---:|---:|---:|
| Ações totais na jornada do 1º atendimento | 104 | **61** | **-41,3%** |
| Ações no editor de plano alimentar (2 refeições, 6 alimentos + publicação) | 50 | **34** | **-32,0%** |
| Telas distintas navegadas | 16 | **8** | **-50,0%** |
| Itens errados no plano por ranking ("coração de frango") | 1 | **0** | **-100%** |

### Acessibilidade (`axe-core` WCAG 2.1 A/AA) e Responsividade

- **`axe-core` WCAG 2.1 A/AA (1440×900):** **0 violações** em `/cadastro`, `/dashboard`, `/pacientes/4` e `/planos/3`.
- **Sem rolagem horizontal (`scrollWidth <= clientWidth + 1`):** validado em `1440×900`, `820×1180` e `390×844` em `/dashboard`, `/pacientes/4` e `/planos/3`.
- **Isolamento multi-tenant em navegador:** acesso autenticado da Clínica A a paciente da Clínica B bloqueado.

---

## 3. Suítes de Regressão Executadas (PHP)

| Suíte | Comando | Resultado |
|---|---|---|
| NI2-609 (Catálogo & Medidas) | `php tests/v545_ni2_609_test.php` | **15 PASS, 0 FAIL** |
| NI2-625 (Ativação / Onboarding) | `php tests/v5412_ni2_625_activation_test.php` | **13 PASS, 0 FAIL** |
| Onboarding Guiado E2E | `php tests/onboarding_guided_and_activation_e2e_test.php` | **9 gates PASS, 0 FAIL** |
| NI2-514 (Unidades & Medidas) | `php tests/ni2_514_v42_units_test.php` | **15 PASS, 0 FAIL** |
| NI2-519 / NI2-520 (Modelos & Busca) | `php tests/ni2_519_520_v44_test.php` | **28 PASS, 0 FAIL** |
| NI2-830 (Consentimento LGPD) | `php tests/v5461_ni2_830_consentimento_test.php` | **61 PASS, 0 FAIL** |
| NI2-220 (Qualidade & Segurança) | `php tests/v5425_ni2_220_test.php` | **82 PASS, 0 FAIL** |
| NI2-706 (Contexto de Consulta) | `php tests/v5426_consulta_contexto_test.php` | **16 PASS, 0 FAIL** |
| UX Workflows | `php tests/ux_workflows.php` | **30 PASS, 0 FAIL** |
| NI2-703 | `php tests/ni2_703.php` | **45 PASS, 0 FAIL** |
