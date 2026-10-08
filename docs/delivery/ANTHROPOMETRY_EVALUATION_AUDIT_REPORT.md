# CLYONUTRI II — RELATÓRIO DE AUDITORIA & ENTREGA VERIFICÁVEL
## Épico NI2-7: [P0] Antropometria, Avaliação e Evolução Clínica

**Data da Auditoria:** 08/10/2026  
**Ambiente:** Local / CI Multi-tenant (PHP 8.2+, SQLite/MySQL)  
**Status do Épico:** VERIFICADO & APROVADO (60 PASS, 0 FAIL)  
**Regra de Ouro:** Zero Mocks, Zero Placeholders, Persistência Relacional Estrita, Indicadores Clínicos Conformes OMS e Isolamento Multitenant Ativo.

---

### 1. Resumo Executivo das Entregas (Histórias do Épico NI2-7)

| Issue | Descrição | Status Verificável | Arquitetura / Evidência |
|---|---|---|---|
| **NI2-65** | `[Antropometria] Registrar avaliação` | **PASS** | Implementado em [`AnthropometryService::recordAssessment()`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php). Suporte a peso, altura, circunferências corporais (cintura, quadril, pescoço, tórax, braço, coxa, panturrilha) e composição corporal informada (% gordura, % massa muscular). Validações estritas de plausibilidade biológica (ex: peso 10–400 kg, altura 45–250 cm, gordura 1–75%) com lançamento de [`InvalidAnthropometricDataException`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/InvalidAnthropometricDataException.php). Data, avaliador e isolamento multitenant (`TenantGuard`). |
| **NI2-66** | `[Antropometria] Calcular IMC, RCQ e derivados` | **PASS** | Implementado em [`AnthropometryCalculator`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryCalculator.php). Fórmulas canônicas segundo padrões da OMS: IMC (com faixas de desnutrição a obesidade grau III), RCQ (com risco cardiovascular categorizado por sexo biológico), RCE (Relação Cintura-Estatura), Faixa de Peso Saudável (IMC 18.5 a 24.9) e massa gorda/massa magra absoluta em kg. **Princípio de dados ausentes:** ausência de métrica gera estritamente `null` (sem números fixos ou valores forjados). |
| **NI2-67** | `[Antropometria] Exibir evolução temporal` | **PASS** | Método [`AnthropometryService::getEvolution()`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php). Retorna série cronológica de medições com cálculo de deltas absolutos e percentuais ponto a ponto. Gera **alternativa textual acessível** para leitores de tela e clareza clínica (ex: *"Evolução clínica de 01/08/2026 (78.5) a 01/10/2026 (72.0): redução total de 6.5 (-8.28%) ao longo de 3 avaliações"*). Filtro por métrica e interface dedicada em `/pacientes/{id}/antropometria`. |
| **NI2-68** | `[Antropometria] Comparar avaliações` | **PASS** | Método [`AnthropometryService::compareAssessments()`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php). Permite comparar qualquer par de avaliações (ou baseline vs. follow-up padrão), calculando dias decorridos, deltas absolutos, deltas percentuais e tendências (*increase*, *decrease*, *stable*). Tratamento estrito de **missing data** (`missing_in_baseline`, `missing_in_followup`, `missing_both`), garantindo que métricas não medidas em uma das consultas não quebrem o cálculo nem inventem dados. |
| **NI2-69** | `[Antropometria] Compartilhar evolução selecionada` | **PASS** | Implementado com controle explícito pelo nutricionista (`is_shared = 1` vs `0`). Método [`AnthropometryService::shareAssessment()`](file:///Users/moliveira/Nutri_II/src/Domain/Anthropometry/AnthropometryService.php) grava na tabela relacional de auditoria `anthropometry_shares_audit`. No Portal do Paciente (`PatientPortalService`) e API Móvel (`/api/v1/patient/evolution`), o paciente enxerga estritamente medições autorizadas. A revogação tem efeito imediato e rastreável. |

---

### 2. Infraestrutura e Banco de Dados

- **Migration Criada:** [`database/migrations/2026_10_08_000012_enhance_anthropometry_and_sharing_tables.php`](file:///Users/moliveira/Nutri_II/database/migrations/2026_10_08_000012_enhance_anthropometry_and_sharing_tables.php)
- **Extensões de Schema:**
  - Adicionadas colunas em `anthropometric_assessments`:
    - `neck_cm`, `chest_cm`, `arm_cm`, `thigh_cm`, `calf_cm`
    - `is_shared` (BOOLEAN NOT NULL DEFAULT 0)
    - `shared_at` (DATETIME NULL)
    - `shared_by_user_id` (INTEGER NULL)
  - Criada tabela de auditoria `anthropometry_shares_audit`:
    - `id`, `clinic_id`, `assessment_id`, `action` (`shared` ou `revoked`), `changed_by_user_id`, `reason`, `created_at`.
  - Índices criados:
    - `idx_anthro_patient_date`: `(clinic_id, patient_id, assessed_at)`
    - `idx_anthro_shares_audit`: `(assessment_id, clinic_id)`

---

### 3. Fórmulas e Indicadores Clínicos (Padrão OMS)

1. **Índice de Massa Corporal (IMC):**
   $$\text{IMC} = \frac{\text{Peso (kg)}}{(\text{Altura (m)})^2}$$
   - $< 18.5$: Abaixo do peso
   - $18.5 - 24.99$: Eutrofia (Peso adequado)
   - $25.0 - 29.99$: Sobrepeso
   - $30.0 - 34.99$: Obesidade Grau I
   - $35.0 - 39.99$: Obesidade Grau II
   - $\ge 40.0$: Obesidade Grau III

2. **Relação Cintura-Quadril (RCQ) & Risco Cardiovascular OMS:**
   $$\text{RCQ} = \frac{\text{Cintura (cm)}}{\text{Quadril (cm)}}$$
   - *Mulheres:* $< 0.80$ (Baixo risco), $0.80 - 0.84$ (Risco moderado), $\ge 0.85$ (Alto risco).
   - *Homens:* $< 0.90$ (Baixo risco), $0.90 - 0.99$ (Risco moderado), $\ge 1.00$ (Alto risco).

3. **Relação Cintura-Estatura (RCE / WHtR):**
   $$\text{RCE} = \frac{\text{Cintura (cm)}}{\text{Altura (cm)}}$$
   - $< 0.50$: Adequado / Baixo risco
   - $\ge 0.50$: Risco cardiometabólico aumentado

4. **Composição Corporal Derivada:**
   $$\text{Massa Gorda (kg)} = \text{Peso} \times \frac{\text{\% Gordura}}{100}$$
   $$\text{Massa Magra (kg)} = \text{Peso} - \text{Massa Gorda (kg)}$$
   $$\text{Massa Muscular (kg)} = \text{Peso} \times \frac{\text{\% Massa Muscular}}{100}$$

---

### 4. Evidência Reproduzível dos Testes Automatizados

Comando de Execução:
```bash
php tests/anthropometry_lifecycle_and_evaluation_test.php
```

Resultado Obtido:
```text
====================================================================
   CLYONUTRI II — SUÍTE DE VERIFICAÇÃO INTEGRAL DO ÉPICO NI2-7
   Antropometria, Indicadores OMS e Evolução Clínica
====================================================================

[1] Testando NI2-65 — Registrar Avaliação e Validações Biológicas...
  [PASS] Avaliação #1 criada com ID persistido
  [PASS] Peso e altura persistidos exatamente
  [PASS] Avaliação criada inicialmente como privada (is_shared = false)
  [PASS] Registro persistido fisicamente na tabela relacional
  [PASS] Circunferências complementares persistidas (pescoço, braço)
  [PASS] Peso implausível (> 400kg) bloqueado com InvalidAnthropometricDataException
  [PASS] Altura implausível (< 45cm) bloqueada com InvalidAnthropometricDataException
  [PASS] Percentual de gordura implausível (> 75%) bloqueado
  [PASS] Tentativa cross-tenant de registrar avaliação em outro tenant bloqueada (403)

[2] Testando NI2-66 — Fórmulas e Indicadores OMS (IMC, RCQ, RCE)...
  [PASS] IMC canônico calculado exatamente (28.83 kg/m²)
  [PASS] Classificação OMS do IMC 28.83 é 'Sobrepeso'
  [PASS] IMC 17.5 é Abaixo do peso
  [PASS] IMC 22.0 é Eutrofia
  [PASS] IMC 27.5 é Sobrepeso
  [PASS] IMC 32.0 é Obesidade Grau I
  [PASS] IMC 37.0 é Obesidade Grau II
  [PASS] IMC 42.0 é Obesidade Grau III
  [PASS] RCQ calculado exatamente (0.82)
  [PASS] Mulher com RCQ 0.82 classificada como 'Risco cardiovascular moderado'
  [PASS] Homem com RCQ 0.88 classificado como 'Baixo risco cardiovascular'
  [PASS] RCE calculada exatamente (0.51)
  [PASS] RCE >= 0.50 classificada como 'Risco cardiometabólico aumentado'
  [PASS] Faixa saudável de peso calculada corretamente (50.37 a 67.79 kg)
  [PASS] Massa gorda derivada exatamente (23.16 kg)
  [PASS] Massa magra derivada exatamente (55.34 kg)
  [PASS] Ausência de quadril resulta estritamente em RCQ null (sem 0 ou números fixos)

[3] Testando NI2-67 — Exibir Evolução Temporal e Alternativa Textual...
  [PASS] getEvolution retornou 3 avaliações cronológicas
  [PASS] Ordenação cronológica correta (agosto -> setembro -> outubro)
  [PASS] Baseline não possui delta anterior (null)
  [PASS] Delta de peso entre avaliação 1 e 2 é -3.3 kg
  [PASS] Delta de peso entre avaliação 2 e 3 é -3.2 kg
  [PASS] Alternativa textual acessível gerada com sucesso
  [PASS] Resumo textual descreve redução total de 6.5 kg

[4] Testando NI2-68 — Comparar Avaliações com Deltas e Missing Data...
  [PASS] Comparador calculou período de 61 dias decorridos
  [PASS] Valor inicial de peso é 78.5 kg e final é 72 kg
  [PASS] Delta absoluto de peso é -6.5 kg
  [PASS] Delta percentual de peso é -8.28%
  [PASS] Tendência do peso classificada como 'decrease'
  [PASS] Métrica ausente no follow-up identificada como 'missing_in_followup'
  [PASS] Delta da métrica ausente é estritamente null sem quebrar cálculo

[5] Testando NI2-69 — Compartilhamento Explícito, Revogação e Auditoria...
  [PASS] Avaliação 1 (não compartilhada) NÃO aparece no portal do paciente
  [PASS] Avaliação 2 (compartilhada) aparece no portal do paciente
  [PASS] Avaliação 3 (compartilhada) aparece no portal do paciente
  [PASS] Avaliação 1 compartilhada com sucesso
  [PASS] Trilha de auditoria registrou ação 'shared'
  [PASS] Justificativa registrada na auditoria
  [PASS] Avaliação 1 agora está imediatamente visível no portal do paciente
  [PASS] Revogação executada com sucesso
  [PASS] Avaliação 1 deixa de ser visível no portal imediatamente após revogação
  [PASS] Auditoria registrou evento de revogação ('revoked')
  [PASS] getSharedAssessmentsForPatient retorna estritamente avaliações autorizadas

[6] Testando Rotas HTTP Web (/pacientes/{id}, /antropometria)...
  [PASS] GET /pacientes/{id} retornou HTML válido
  [PASS] Página contém card de Avaliações Antropométricas
  [PASS] Página contém link para Análise Completa
  [PASS] Página exibe status de liberação ao paciente
  [PASS] GET /pacientes/{id}/antropometria retornou HTML completo de evolução
  [PASS] Tela exibe KPIs de peso atual e IMC
  [PASS] Tela exibe resumo de acessibilidade
  [PASS] Tela exibe tabela com comparativo entre avaliações
  [PASS] Tela contém formulário de nova avaliação com CSRF

====================================================================
   RESULTADO DA SUÍTE DE TESTES NI2-7: 60 PASS, 0 FAIL
====================================================================
```

---

### 5. Conclusão

O Épico **NI2-7** foi completamente homologado em código de produção, com todas as fórmulas, limites clínicos e controles de privacidade do paciente 100% verificados e em conformidade estrita com o Protocolo Master Prompt.
