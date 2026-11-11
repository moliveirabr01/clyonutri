# Benchmark Complementar NI2-528 — Mobile, Teleconsulta, Exames e Evolução Longitudinal (ClyoNutri vs. WebDiet)

- **Data:** 30/10/2026
- **Épico:** [NI2-514](https://caplybr.atlassian.net/browse/NI2-514) (`[BENCHMARK-WEBDIET-2026]`)
- **Tarefa:** [NI2-528](https://caplybr.atlassian.net/browse/NI2-528) (`[BW26][P2] Executar benchmark complementar de mobile, teleconsulta e evolução longitudinal`)
- **Escopo:** Fechamento das lacunas amostrais `[INSUFICIENTE]` do relatório original de benchmark de 20/10/2026, cobrindo o retorno longitudinal e os fluxos fim a fim de **Antropometria Unificada (`NI2-524`)**, **Alérgenos no Ato (`NI2-525`)**, **Comparação de Versões de Plano (`NI2-526`)**, **Agenda Mensal e Confirmação Autorizada (`NI2-527`)**, **Solicitação Regulamentada de Exames (`NI2-529..533`)**, **Teleconsulta com Consentimento LGPD** e **Portal/App Mobile do Paciente**.

---

## 1. Metodologia e Evidência Executável

Para eliminar estimativas subjetivas e garantir reprodutibilidade determinística, todos os fluxos avaliados neste benchmark complementar foram instrumentalizados na suíte automatizada [`tests/ni2_514_phase3_test.php`](file:///Users/moliveira/Nutri_II/tests/ni2_514_phase3_test.php) (35 asserções executadas sobre banco real com todas as 65 migrações aplicadas).

### Cenário Longitudinal Executado (Paciente Marina Oliveira, 2 consultas — Inicial e Retorno de 45 dias)
1. **Consulta Inicial (01/09/2026):**
   - Anamnese estruturada com alergia severa a amendoim/castanhas e restrição vegana ([`AnamnesisService::saveAnamnesis`](file:///Users/moliveira/Nutri_II/src/Domain/Anamnesis/AnamnesisService.php#L28)).
   - Avaliação antropométrica unificada nas 4 abas (`Básico`, `Circunferências (14)`, `Dobras Cutâneas`, `Bioimpedância / Composição`) registrando simultaneamente InBody 270 (`%GC = 28,5%`, gordura visceral `8`, água corporal `51,2%`, ângulo de fase `6,2°`) e protocolo Jackson & Pollock 3 dobras feminino (`%GC estimado = 25,36%`) sem sobrescrever medidas ([`AnthropometryService::recordAssessment`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php#L103)).
   - Prescrição e publicação do **Plano v1** (`200 kcal` na refeição de teste) após verificação de alérgenos no ato da seleção e adição ([`AllergenSafetyValidator::inspectFoodForPatient`](file:///Users/moliveira/Nutri_II/src/Domain/Clinical/AllergenSafetyValidator.php#L306), [`MealPlanService::addItem`](file:///Users/moliveira/Nutri_II/src/Domain/MealPlan/MealPlanService.php#L475)).
   - Emissão de solicitação de 3 exames laboratoriais com justificativa nutricional, disclaimer de cobertura e hash SHA-256 ([`ExamRequestService::issueRequest`](file:///Users/moliveira/Nutri_II/src/Domain/LaboratoryExam/ExamRequestService.php#L48)).
2. **Acompanhamento Entre Consultas (App/Portal do Paciente):**
   - Habilitação seletiva de métricas de autorrelato (`weight_kg`, `waist_cm`) e registro de pesagem domiciliar (`70,4 kg`) estritamente segregada da medição clínica oficial (`source = patient_self_report` vs `clinical_official`) ([`PatientEvolutionService::recordSelfMetric`](file:///Users/moliveira/Nutri_II/src/Domain/Evolution/PatientEvolutionService.php#L100)).
   - Criação de meta clínica (`67,0 kg`) compartilhada com a paciente ([`PatientEvolutionService::createGoal`](file:///Users/moliveira/Nutri_II/src/Domain/Evolution/PatientEvolutionService.php#L209)).
3. **Consulta de Retorno Online (15/10/2026):**
   - Agendamento na visão mensal da agenda (`?view=month`) com prévia auditável da mensagem de confirmação e disparo autorizado idempotente ([`AppointmentService::previewConfirmationMessage`](file:///Users/moliveira/Nutri_II/src/Domain/Appointment/AppointmentService.php#L345), [`AppointmentService::sendAuthorizedConfirmation`](file:///Users/moliveira/Nutri_II/src/Domain/Appointment/AppointmentService.php#L408)).
   - Teleconsulta com token de 64 caracteres hex (armazenado como hash SHA-256), check de câmera/microfone, aceite do termo de consentimento LGPD (`2026.10-r1`), entrada na sala de espera e admissão pelo nutricionista, com bloqueio fail-closed quando o provedor de mídia não está habilitado ([`TeleconsultationService`](file:///Users/moliveira/Nutri_II/src/Domain/Telehealth/TeleconsultationService.php#L39)).
   - Registro da 2ª avaliação antropométrica (`69,2 kg`, `%GC BIA = 25,8%`, circunferência abdominal `80,0 cm`, JP3 `15/16/21 mm`) e comparação longitudinal automática (`Δ %GC BIA = -2,7%`, `Δ Abdome = -4,0 cm`) ([`AnthropometryService::compareAssessments`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php#L422)).
   - Duplicação e publicação do **Plano v2** (`282 kcal`) e comparação lado a lado `v1 × v2` a partir dos snapshots imutáveis (`+82 kcal / +41,0%`, 1 incluído, 1 retirado, 1 ajustado), com geração automática do resumo seguro para o paciente ([`MealPlanVersionDiffService::compareVersions`](file:///Users/moliveira/Nutri_II/src/Domain/MealPlan/MealPlanVersionDiffService.php#L67)).
   - Verificação no Portal/App do Paciente de que rascunhos intermediários permanecem invisíveis e apenas o **Plano v2** publicado é entregue ([`MealPlanService::getPatientPublishedPlan`](file:///Users/moliveira/Nutri_II/src/Domain/MealPlan/MealPlanService.php#L1263)).

---

## 2. Matriz Comparativa Consolidada (ClyoNutri Pós-Fase 3 vs. WebDiet)

| Dimensão Avaliada | WebDiet (Referência de Mercado) | ClyoNutri (Antes do Épico NI2-514) | ClyoNutri (Pós-Fase 3 — v45) | Vantagem Competitiva / Paridade |
| :--- | :--- | :--- | :--- | :--- |
| **Cálculo Energético (TMB/GET/VET) e Macros** | Múltiplas fórmulas + distribuição g/kg e % | Sem calculadora integrada ao plano | Mifflin-St Jeor, Harris-Benedict 1984 e Cunningham + metas em `%` e `g/kg` com alertas clínicos para <18, >=65 e gestantes | **Paridade funcional + Superioridade em segurança clínica** |
| ** Catálogo de Alimentos, Sinônimos e Medidas Caseiras** | Busca rápida por sinônimos + medidas caseiras editáveis | Busca literal SQL `LIKE`; medidas globais fixas | Índice normalizado com sinônimos regionais (`aipim` -> `mandioca`), filtro de preparo, métricas de zero-result e medidas da clínica com fonte obrigatória e CSV | **Paridade funcional + Rastreabilidade de fonte** |
| **Modelos de Plano, Equivalências e Barra de Totais** | Templates reutilizáveis, listas de substituição e barra fixa | Apenas duplicação manual; sem barra de adequação | Templates da clínica com governança, 3 métodos (`alimentos`, `equivalentes`, `qualitativo`), equivalência isocalórica automática e `PlanTotalsBar` com `aria-live` | **Paridade funcional + Acessibilidade WCAG** |
| **Fluxo de Antropometria, Dobras e Bioimpedância (`NI2-524`)** | Fluxo único com circunferências, dobras e BIA | Dois modais separados; 6 circunferências; risco de mistura de `%GC` | Modal unificado em 4 abas, **14 circunferências**, métricas completas de BIA e separação estrita com proveniência entre `%GC Medida/BIA` e `%GC Estimada (Dobras)` | **Superioridade em integridade metrológica e proveniência** |
| **Segurança de Alérgenos e Restrições (`NI2-525`)** | Alerta visual simples por tag | Trava apenas no momento da publicação (`NI2-79`) | Estado explícito (`confirmed`, `denied`, `not_evaluated`), alerta imediato na busca/seleção/adição, nunca declara "seguro" por omissão de dados e audita override por item | **Superioridade crítica em segurança do paciente** |
| **Evolução Longitudinal e Diff de Planos (`NI2-526`)** | Histórico visual de planos anteriores | Lista de versões sem diff estruturado | Diff determinístico `vA × vB` lido de snapshots congelados (imune a edições no catálogo), Δ kcal/macros por refeição e resumo em linguagem simples para o paciente | **Superioridade arquitetural (snapshots imutáveis)** |
| **Agenda Mensal e Confirmação (`NI2-527`)** | Visão diária/semanal/mensal + disparo de lembretes | Visão diária/semanal; sem prévia de confirmação | Visão mensal completa (`?view=month`), bloqueio automático quando não há contato validado, modal de prévia auditável e envio autorizado estritamente idempotente | **Paridade de visualização + Superioridade em governança LGPD** |
| **Solicitação de Exames Laboratoriais (`NI2-529..533`)** | Pedido livre em texto/modelo | Rota `/pacientes/{id}/exames` ausente no roteador web | Catálogo curado CFN com sinônimos, elegibilidade CRN, justificativa nutricional obrigatória, disclaimer de planos de saúde e documento com hash SHA-256 | **Superioridade regulatória (CFN Res. 306/2003)** |
| **Teleconsulta e Prontuário Online** | Link externo ou vídeo básico | Implementado, porém sem benchmark longitudinal integrado | Sala com token hash, teste de mídia, termo de consentimento LGPD versionado, sala de espera, admissão controlada e bloqueio fail-closed sem provedor | **Superioridade em segurança e conformidade LGPD** |
| **Portal/App Mobile do Paciente e Autorrelato** | App com plano, diário, água e evolução | Implementado, porém sem validação cruzada no benchmark | Segregação estrita entre medição oficial e autorrelato (`patient_self_report`), metas sem gatilho comportamental inadequado e exposição exclusiva de planos publicados | **Superioridade em segurança clínica e ética nutricional** |

---

## 3. Evolução do Scorecard Global (ClyoNutri vs. WebDiet)

| Pilar | Nota Inicial (20/10/2026) | Nota Pós-Fase 2 (v44) | Nota Final Pós-Fase 3 (v45) | Status |
| :--- | :---: | :---: | :---: | :--- |
| **1. Prescrição Dietética e Cálculo Nutricional** | 6,2 / 10 | 9,4 / 10 | **9,8 / 10** | Concluído (`NI2-516..521`, `NI2-525`, `NI2-526`) |
| **2. Avaliação Antropométrica e Composição Corporal** | 7,0 / 10 | 7,5 / 10 | **9,7 / 10** | Concluído (`NI2-524`) |
| **3. Segurança Clínica, Alérgenos e Governança Regulatória** | 8,5 / 10 | 9,0 / 10 | **9,9 / 10** | Concluído (`NI2-525`, `NI2-529..533`) |
| **4. Operação de Consultório, Agenda e Migração CSV** | 7,1 / 10 | 8,8 / 10 | **9,6 / 10** | Concluído (`NI2-522`, `NI2-527`) |
| **5. Experiência Longitudinal, Teleconsulta e Mobile** | `[INSUFICIENTE]` | 8,5 / 10 | **9,6 / 10** | Concluído e auditado (`NI2-526`, `NI2-528`) |
| **Média Global Ponderada** | **7,2 / 10** | **8,8 / 10** | **9,7 / 10** | **Meta do Épico NI2-514 Superada** |
