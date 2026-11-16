# REGRESSAO.md — Épico NI2-844 (`v54.62`)

- **Épico:** `NI2-844` — `[AUD-UX WEB][v54.61] Simplificação das jornadas do nutricionista — findings da auditoria UX 360°`
- **Versão:** `v54.62`
- **Data:** 15/11/2026

---

## 1. Pontos Fortes Preservados (Requisito Explícito do Épico `NI2-844`)

O épico `NI2-844` determinou preservar sem regressão os controles de segurança clínica e usabilidade destacados na auditoria UX 360° (`AUDITORIA-UX-360-v54-61.md`). Todos foram verificados por execução real:

| Ponto Forte / Controle Clínico | Como foi preservado e validado | Suíte de Regressão Executada | Status |
|---|---|---|---|
| **1. Rascunho automático com retomada (evolução e anamnese)** | Mantido o versionamento otimista em `ConsultationDraftService` e `WorkflowWrites`, ampliado por `NI2-851` com backup em `sessionStorage` e reenvio automático em `online`. | `tests/v5462_ni2_844_ux360_test.php` (Seção 7)<br>`tests/ux_workflows.php` (30 PASS, 0 FAIL) | **PASS** |
| **2. Detecção de paciente duplicado** | Mantida a validação de duplicidade por e-mail/celular com link `"Conferir cadastro existente"`, complementada pelo atalho `"Cadastrar «{termo}»"` quando a busca retorna vazia (`NI2-855` / `UX-018`). | `tests/v5425_ni2_220_test.php` (82 PASS, 0 FAIL)<br>`tests/v5462_ni2_844_ux360_test.php` (Seção 9) | **PASS** |
| **3. Explicação de conflito de agenda mantendo dados preenchidos** | `takeAgendaForm('new')` e `views/agenda/_schedule_fields.php` preservados intactos; `NI2-853` (`UX-010`) só pré-preenche `nextValidSlot` quando não há recuperação de conflito (`!$agendaRecovered`). | `tests/v5410_ni2_603_604_605_611_test.php` (21 PASS, 0 FAIL)<br>`tests/v5462_ni2_844_ux360_test.php` (Seção 8) | **PASS** |
| **4. Revisão obrigatória de adequação antes de publicar o plano** | `MealPlanService::adequacyReview` e `publishPlan` mantêm o bloqueio de publicação sem revisão/justificativa; corrigido apenas o erro gramatical `"1 refeição(ões)"` $\rightarrow$ `"1 refeição"` (`NI2-855` / `UX-013`). | `tests/v5462_ni2_844_ux360_test.php` (Seção 4)<br>`tests/e2e/scenarios/ni2844.js` (Seção 7) | **PASS** |
| **5. TMB/GET pré-preenchidos a partir da avaliação** | Mantido o pré-preenchimento da meta energética a partir da última avaliação antropométrica do paciente, agora com normalização de vírgula decimal e altura em metros/cm (`NI2-847` / `UX-003`). | `tests/ni2_514_v42_units_test.php` (15 PASS, 0 FAIL)<br>`tests/v5462_ni2_844_ux360_test.php` (Seções 1 e 3) | **PASS** |
| **6. Cálculo exato de IMC e RCQ** | `AnthropometryService::recordAssessment` calcula IMC (`25.05` para `72,4` kg e `1,7` m) e RCQ (`0.78` para `78,0` cm e `100,0` cm) com exatidão determinística. | `tests/v5462_ni2_844_ux360_test.php` (Seção 1)<br>`tests/e2e/scenarios/ni2844.js` (Seção 6) | **PASS** |
| **7. Guarda contra unidade de contagem sem medida cadastrada (`NI2-609`)** | `HouseholdMeasureConverter::assertCountUnitHasMeasure` continua recusando `unidade`/`fatia` para alimentos sem medida caseira cadastrada; `ensureCommonMeasures` popula medidas oficiais para o catálogo TACO real sem afetar fixtures sintéticas (`category = 'TESTE'`). | `tests/v545_ni2_609_test.php` (15 PASS, 0 FAIL) | **PASS** |
| **8. Checklist de ativação conferido no banco (`NI2-625`)** | `ActivationChecklist::forUser` e `build` mantêm 100% de compatibilidade com as regras de negócio de ativação e agendamento online ligado/desligado. | `tests/v5412_ni2_625_activation_test.php` (13 PASS, 0 FAIL)<br>`tests/onboarding_guided_and_activation_e2e_test.php` (9 gates PASS) | **PASS** |
| **9. Consentimento verificável do paciente para transcrição (`NI2-830` / `v54.61`)** | Nenhuma regra de segurança de `NI2-830` foi alterada; `NI2-854` (`UX-012`) apenas recolheu o texto informativo longo de LGPD em `<details data-testid="stt-lgpd-details">` e posicionou o card abaixo da evolução. | `tests/v5461_ni2_830_consentimento_test.php` (61 PASS, 0 FAIL) | **PASS** |
| **10. Isolamento Multi-Tenant e Autorização Clínica** | Todas as novas rotas (`POST /planos/{id}/itens/{itemId}/somar`, `action=merge_duplicate`, `POST /consulta/{id}/retorno`) validam CSRF, papel clínico ativo com CRN e pertencimento ao `clinic_id` do tenant autenticado. | `tests/v5462_ni2_844_ux360_test.php` (Seção 3)<br>`tests/ni2_695_identity.php` (10 PASS, 0 FAIL)<br>`tests/ni2_695_integrity.php` (28 PASS, 0 FAIL) | **PASS** |

---

## 2. Resumo das Suítes de Regressão Executadas

| Suíte | Escopo | Resultado |
|---|---|---|
| `php tests/v5462_ni2_844_ux360_test.php` | Épico `NI2-844` completo (`NI2-845`..`NI2-855` / `UX-001`..`UX-019`) | **55 PASS, 0 FAIL** |
| `php tests/v5461_ni2_830_consentimento_test.php` | Consentimento verificável de transcrição (`v54.61`) | **61 PASS, 0 FAIL** |
| `php tests/v545_ni2_609_test.php` | Conversor de medidas caseiras e unidades de contagem (`NI2-609`) | **15 PASS, 0 FAIL** |
| `php tests/v5412_ni2_625_activation_test.php` | Checklist de ativação do consultório (`NI2-625`) | **13 PASS, 0 FAIL** |
| `php tests/onboarding_guided_and_activation_e2e_test.php` | Jornada completa de onboarding, retomada, CSRF e multi-tenant | **9 gates PASS, 0 FAIL** |
| `php tests/ni2_514_v42_units_test.php` | Energia, barras de totais, substituições e busca de alimentos | **15 PASS, 0 FAIL** |
| `php tests/ni2_519_520_v44_test.php` | Sinônimos regionais, intenção de preparo e paridade de rotas | **28 PASS, 0 FAIL** |
| `php tests/v5425_ni2_220_test.php` | Governança clínica, anamnese, auditoria e segurança | **82 PASS, 0 FAIL** |
| `php tests/v5426_consulta_contexto_test.php` | Contexto de atendimento e sala de consulta | **16 PASS, 0 FAIL** |
| `php tests/ux_workflows.php` | Workflows clínicos, concorrência otimista e idempotência | **30 PASS, 0 FAIL** |
| `php tests/ni2_703.php` | Governança clínica e comorbidades | **45 PASS, 0 FAIL** |
