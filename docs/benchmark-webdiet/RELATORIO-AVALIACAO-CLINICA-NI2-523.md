# Relatório de Homologação Técnica e Avaliação de Qualidade Clínica — NI2-523 (Fase 2 · P1)

- **História Jira:** `NI2-523` — *[BW26][P1] Criar avaliação de qualidade clínica de liberação da prescrição*
- **Épico:** `NI2-514`
- **Data da Execução:** 30/10/2026
- **Ambiente:** Clínica isolada `Clínica TESTE Avaliação` (`uuid: clinica-teste-ni2-523`), sem dados pessoais reais

---

## 1. Artefatos de Homologação Provisionados (`NI2-523`)

1. **Provisionador do Ambiente de Avaliação (`Clínica TESTE Avaliação`):**
   - Script CLI: [`bin/preparar_avaliacao_clinica_ni2_523.php`](file:///Users/moliveira/Nutri_II/bin/preparar_avaliacao_clinica_ni2_523.php) (`--dry-run` e `--apply`)
   - Cria de forma idempotente:
     - 5 perfis de nutricionistas avaliadores (`P1` a `P5`, contemplando consultório individual ex-WebDiet, clínica multiprofissional ex-WebDiet, nutrição esportiva, materno-infantil e gerontologia)
     - 1 perfil de recepção (`recepcao.teste@avaliacao.clyonutri.local`) para validação de bloqueio RBAC
     - 5 pacientes fictícios marcados `TESTE` com antropometria e anamnese finalizada:
       1. `TESTE Adulto Redução de Peso` (34 anos, masculino, 88 kg, 176 cm)
       2. `TESTE Adulto Alergia a Amendoim` (29 anos, feminino, 64 kg, 165 cm, alergia a amendoim)
       3. `TESTE Gestante 2º Trimestre` (28 anos, feminino, 68 kg, 163 cm, gestação de 20 semanas)
       4. `TESTE Idoso 72 Anos` (72 anos, masculino, 71 kg, 170 cm)
       5. `TESTE Adolescente Atleta 15 Anos` (15 anos, masculino, 62 kg, 174 cm, % gordura 12,9% -> MLG 54,0 kg)
2. **Planilha CSV TESTE Oficial (10 linhas: 8 válidas e 2 com erro proposital):**
   - Arquivo: [`docs/benchmark-webdiet/pacientes_teste_ni2_523.csv`](file:///Users/moliveira/Nutri_II/docs/benchmark-webdiet/pacientes_teste_ni2_523.csv)
   - Linha 6 sem nome (`Sem nome.`) e Linha 10 com data calendário inexistente (`31/02/1990` -> `Data de nascimento inválida (use dd/mm/aaaa).`).

---

## 2. Resultado da Suíte Clínica Automatizada por Cenário (`C1` a `C11`)

Executado via [`tests/ni2_514_v42_clinical_scenarios_test.php`](file:///Users/moliveira/Nutri_II/tests/ni2_514_v42_clinical_scenarios_test.php) (**25/25 PASS**):

| Cenário | População / Fluxo | Resultado Obtido | Status |
| :--- | :--- | :--- | :--- |
| **C1** | Adulto (34a, M, 88 kg, 176 cm) em redução de peso (-500 kcal) com 6 refeições | TMB = `1.815,0 kcal` (Mifflin-St Jeor 1990), GET = `2.541,0 kcal` (NAF 1,40), VET = `2.041,0 kcal`; plano soma `2.010,1 kcal` (`ok`, dentro da tolerância de 5%). | **APROVADO** |
| **C2** | Gestante (2º trimestre, 28a) e Adolescente atleta (15a, MLG 54 kg) | Gestante recebe alerta explícito sobre peso pré-gestacional e adicional energético do trimestre (FAO/OMS/UNU); adolescente recebe cálculo de MLG (`54,0 kg`, TMB Cunningham `1.688,0 kcal`) e alerta de equação desenvolvida em adultos (`< 18 anos`). | **APROVADO** |
| **C3** | Adulto com alergia a amendoim + prescrição contendo amendoim (30 g) | `AllergenSafetyValidator` identifica conflito no grupo `amendoim`; `publishPlan()` sem override bloqueia com `InvalidArgumentException`; publicação com override expresso e justificativa (`>= 10` caracteres) grava versão e trilha em `audit_logs`. | **APROVADO** |
| **C4** | Medida caseira da clínica (`colher de servir cheia = 45 g`) | `HouseholdMeasureConverter` converte `2 colheres de servir cheias` em `90,0 g` exatos (`origin = clinic`, `estimated = false`), sobrepondo medidas genéricas. | **APROVADO** |
| **C5** | Busca regional por `"aipim"` e `"macaxeira"` no seletor | `FoodSearchIndex` retorna `"Mandioca, cozida"` em 1º lugar (antes da versão crua), com selo de preparo `"Cozido"` e indicação `"aipim = mandioca"`. | **APROVADO** |
| **C6** | Salvar plano C1 como modelo e aplicar ao Idoso (72 anos) | Novo rascunho recebe as 6 refeições e itens sem herdar a meta individual do paciente anterior; ao calcular a meta do idoso (72 anos), `EnergyCalculator` emite alerta para `>= 65 anos` (avaliação de massa magra e aporte proteico). | **APROVADO** |
| **C7** | Substituição de 200 g de arroz integral (`248 kcal`) por mandioca cozida (`198,4 g`) | `SubstitutionComparison` calcula gramas de mesma energia (`198,4 g`) e diferença aritmética de macros; `FoodEquivalenceService` informa que não há equivalência automática entre grupos sem regra revisada da clínica. | **APROVADO** |
| **C8** | Publicação da versão 1, duplicação para nova versão e publicação da versão 2 | `meal_plan_versions` preserva ambos os snapshots imutáveis (`v1` e `v2`) com seus respectivos `energy_target` e itens. | **APROVADO** |
| **C9** | Impressão do plano (`MealPlanPrintService` + `views/meal_plans/print.php`) | Documento HTML/A4 renderiza clínica, paciente, título, 6 refeições, itens, substituições, orientações e assinatura com CRN sem avisos ou erros PHP. | **APROVADO** |
| **C10** | Importação da planilha `pacientes_teste_ni2_523.csv` (10 linhas) | Prévia classifica `8` prontos e `2` com erro (linhas 6 e 10); confirmação cria `8` pacientes e apaga `raw_json` (LGPD); desfazer remove os `8` pacientes sem vínculo clínico; reimportação pós-rollback total liberada. | **APROVADO** |
| **C11** | Privacidade e RBAC (Modelo sem PII + Recepção bloqueada) | `meal_plan_templates` não contém nome, peso, altura nem ID do paciente; perfil `receptionist` não possui vínculo em `professionals` e recebe HTTP 403 nas rotas de prescrição/modelos/importação. | **APROVADO** |

---

## 3. Conferência das Equações e Referências Bibliográficas (Gate 5)

| Componente | Regra / Equação no Código | Fonte Documentada | Verificação Automatizada |
| :--- | :--- | :--- | :--- |
| **Mifflin-St Jeor (1990)** | Homens: $10P + 6{,}25A - 5I + 5$; Mulheres: $10P + 6{,}25A - 5I - 161$ | Mifflin et al., *Am J Clin Nutr* 1990;51:241-247 | `ni2_514_phase1_test.php` + `ni2_514_v42_clinical_scenarios_test.php` |
| **Harris-Benedict Revisada (1984)** | Homens: $88{,}362 + 13{,}397P + 4{,}799A - 5{,}677I$; Mulheres: $447{,}593 + 9{,}247P + 3{,}098A - 4{,}330I$ | Roza & Shizgal, *Am J Clin Nutr* 1984;40:168-182 | `ni2_514_phase1_test.php` |
| **Cunningham (1980/1991)** | $\text{TMB} = 500 + 22 \times \text{MLG (kg)}$ | Cunningham, *Am J Clin Nutr* 1991;54:963-969 | `ni2_514_phase1_test.php` + `ni2_514_v42_units_test.php` |
| **Fatores de Atividade (NAF/PAL)** | Faixa `1,40` a `2,40` (presets `1,40`, `1,55`, `1,75`, `2,00`) | FAO/OMS/UNU 2001 (*Human Energy Requirements*, 2004) | `ni2_514_phase1_test.php` |
| **Conversão de Macronutrientes** | Atwater geral: Proteína `4 kcal/g`, Carboidrato `4 kcal/g`, Lipídio `9 kcal/g` | FAO Food and Nutrition Paper 77 (2003) | `ni2_514_phase1_test.php` |
| **Catálogo Base de Composição** | TACO 4ª ed. rev. e ampl. (2011) — 594 alimentos originais intactos | NEPA/Unicamp (2011) | `ni2_514_v43_test.php` |

---

## 4. Consolidação das Suítes Automatizadas (`NI2-514` Fases 1 e 2)

Todas as **7 suítes de teste** do épico encontram-se no diretório `tests/` e passam com **120 verificações aprovadas e 0 falhas**:

1. `php tests/ni2_514_phase1_test.php` — **17 PASS, 0 FAIL**
2. `php tests/ni2_514_phase2_test.php` — **13 PASS, 0 FAIL**
3. `php tests/ni2_514_v42_units_test.php` — **15 PASS, 0 FAIL**
4. `php tests/ni2_514_v42_phase2_http_test.php` — **12 PASS, 0 FAIL**
5. `php tests/ni2_514_v42_clinical_scenarios_test.php` — **25 PASS, 0 FAIL**
6. `php tests/ni2_514_v43_test.php` — **10 PASS, 0 FAIL**
7. `php tests/ni2_519_520_v44_test.php` — **28 PASS, 0 FAIL**
