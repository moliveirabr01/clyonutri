# ClyoNutri II — Arquitetura de Integridade Clínica e Precisão Nutricional

**Documento:** `docs/clinical/INTEGRIDADE_CLINICA_E_CALCULO_NUTRICIONAL.md`  
**Referência do Épico:** NI2-144 ([P0][Clinical Safety] Integridade do prontuário e precisão nutricional)  
**Histórias Vinculadas:** NI2-157, NI2-158, NI2-159, NI2-160, NI2-161  
**Versão:** 1.0.0  
**Data:** 07/10/2026  
**Status:** Implementado e Homologado com 100% de Testes Aprovados  

---

## 1. Princípios de Segurança Clínica

1. **Prontuário Append-Only:** Registros clínicos de pacientes (evolução, conduta, orientações e anamneses) jamais sofrem `UPDATE` ou `DELETE`. Qualquer retificação cria uma nova versão imutável (`version = n + 1`), registrando obrigatoriamente a justificativa clínica (`revision_reason`) e referenciando a versão superada (`supersedes_id`).
2. **Cadeia Criptográfica de Integridade (Tamper-Evident):** Cada registro clínico calcula um hash SHA-256 encadeado com o hash do registro anterior do paciente (`previous_hash` e `record_hash`), formando uma cadeia verificável. Qualquer adulteração manual no banco de dados é imediatamente detectada pela rotina `ClinicalRecordService::verifyIntegrity`.
3. **Precisão Nutricional por 100g (Padrão TACO/IBGE):** Fórmulas de cálculo calórico e macronutrientes são normalizadas de forma determinística por 100g.
4. **Conversão de Medidas Caseiras Controlada:** Suporte a conversões padrão culinárias e medidas caseiras específicas por alimento (cadastradas em `food_household_measures`). Unidades desconhecidas geram interrupção explícita com `InvalidArgumentException`.
5. **Publicação Transacional e Imutabilidade de Prescrições:** Planos alimentares nascem como rascunhos (`draft`). No momento da publicação, um snapshot integral em JSON é persistido em `meal_plan_versions`. O paciente tem acesso exclusivamente à versão publicada vigente; ajustes posteriores exigem criar um novo rascunho sem alterar o histórico prescrito.
6. **Prevenção de Simulações e Alerta de Alergênicos:** Eliminação total de dados e textos simulados (ex.: falsos resumos SOAP ou planos de IA inventados). Mapeamento dos 14 principais grupos alergênicos (RDC 26/2015 ANVISA) contra a anamnese do paciente com bloqueio de prescrições incompatíveis.

---

## 2. Componentes Arquiteturais

### 2.1 `ClinicalRecordService`
- **Arquivo:** `src/Domain/Clinical/ClinicalRecordService.php`
- **Responsabilidades:**
  - `appendRecord()`: Adição de novo registro com encadeamento de hash.
  - `reviseRecord()`: Retificação de registro marcando versão anterior como superada (`is_retracted = 1`) sem sobrescrever seu conteúdo histórico.
  - `verifyIntegrity()`: Validação matemática de integridade de toda a cadeia cronológica do paciente.
  - `getPatientTimeline()`: Consulta do histórico auditável em ordem cronológica.

### 2.2 `NutritionCalculator` & `HouseholdMeasureConverter`
- **Arquivos:** `src/Domain/Nutrition/NutritionCalculator.php`, `src/Domain/Nutrition/HouseholdMeasureConverter.php`
- **Fórmulas de Cálculo:**
  $$\text{peso (g)} = \text{quantidade} \times \text{fator de conversão da unidade}$$
  $$\text{kcal} = \frac{\text{peso (g)}}{100} \times \text{kcal}_{100\text{g}}$$
  $$\text{macronutrientes (g)} = \frac{\text{peso (g)}}{100} \times \text{macro}_{100\text{g}}$$
- **Caso Canônico Homologado:**
  - 30g de Aveia em flocos (TACO 394.0 kcal/100g): 118.20 kcal, 4.17g proteínas, 19.98g carboidratos, 2.55g lipídios.

### 2.3 `AllergenSafetyValidator`
- **Arquivo:** `src/Domain/Clinical/AllergenSafetyValidator.php`
- **Taxonomia:** Glúten/trigo/aveia, leite/lactose, ovos, amendoim, soja, peixes, crustáceos e castanhas/oleaginosas.
- **Comportamento:** Ao publicar um plano, se houver alimento conflitante com a anamnese, a operação é rejeitada a menos que haja confirmação/override explícito de supervisão clínica.

### 2.4 `MealPlanService`
- **Arquivo:** `src/Domain/MealPlan/MealPlanService.php`
- **Publicação Atômica:** Garante `meal_plan_versions` imutável, status `superseded` para versões antigas e disponibilização segura para o portal/app do paciente.

---

## 3. Matriz de Cobertura das Histórias NI2-144

| História | Título | Entregável Implementado | Arquivos Envolvidos |
|---|---|---|---|
| **NI2-157** | Prontuário append-only e versionamento | Tabela `clinical_records`, `appendRecord`, `reviseRecord` | `ClinicalRecordService.php`, Migration 08 |
| **NI2-158** | Trilha verificável de integridade | Hashing encadeado SHA-256 e detecção de adulteração | `ClinicalRecordService::verifyIntegrity` |
| **NI2-159** | Calculadora por 100g e medidas caseiras | `NutritionCalculator` e `HouseholdMeasureConverter` | `NutritionCalculator.php`, `HouseholdMeasureConverter.php` |
| **NI2-160** | Publicação transacional de planos | Snapshots imutáveis em `meal_plan_versions` | `MealPlanService.php` |
| **NI2-161** | Prevenção de simulações e alergênicos | `AllergenSafetyValidator`, limpeza de IA mock | `AllergenSafetyValidator.php`, `ConsultationSummaryAssistant.php` |
