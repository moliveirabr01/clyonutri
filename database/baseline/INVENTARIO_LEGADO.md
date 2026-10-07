# Baseline e Inventário de Dados — Legado ClyoMed vs. ClyoNutri

**Data:** 07/10/2026  
**Status:** Congelado e Inventariado  
**Diretiva:** O ClyoNutri é um SaaS 100% independente. O baseline ClyoMed é classificado sem exclusões prematuras/destrutivas até validação completa.

---

## 1. Classificação Estrutural de Tabelas (KEEP / ADAPT / MIGRATE / DROP-LATER)

| Tabela Legada / Domínio | Classificação | Destino no ClyoNutri | Justificativa / Ação |
|---|---|---|---|
| `users` | **ADAPT** | `users` | Adaptar campos de identidade, password hash (Argon2id/Bcrypt), MFA, status de e-mail e soft-delete. |
| `clinics` / `tenants` | **ADAPT** | `clinics` | Estabelecer como fronteira multi-tenant primária. Todas as tabelas clínicas herdam `clinic_id`. |
| `clinic_members` / `roles` | **ADAPT** | `clinic_members` | Mapear papéis (`owner`, `admin`, `nutritionist`, `receptionist`, `patient`). Eliminar papéis médicos (`doctor`, `psychologist`). |
| `professionals` | **ADAPT** | `professionals` | Especializar perfil para Nutricionista (CRN, UF_CRN, especialidade, bio, assinatura digital). |
| `patients` | **ADAPT** | `patients` | Isolar por `clinic_id`, adicionar campos de contato, responsável, consentimentos LGPD. |
| `patient_consents` | **KEEP** | `patient_consents` | Tabela nativa de consentimentos LGPD e termos de serviço. |
| `medical_records` (generalista) | **MIGRATE** | `nutrition_records` | Extrair apenas anotações nutricionais preexistentes; segregar do prontuário médico Med. |
| `anamnesis` (genérica) | **ADAPT** | `anamneses` | Reestruturar em formato nutricional: rotina alimentar, aversões, alergias, sintomas gastrointestinais, ingestão hídrica. |
| `anthropometry` | **ADAPT** | `anthropometric_assessments` | Padronizar medidas: peso, altura, circunferências (cintura, quadril, braço, etc.), dobras cutâneas, bioimpedância e cálculos derivados (IMC, RCQ). |
| `foods` / `taco` | **KEEP** | `foods` + `food_nutrients` | Base oficial TACO + alimentos cadastrados pelo próprio nutricionista/tenant (`is_custom`, `clinic_id`). |
| `diets` / `prescriptions` | **MIGRATE** | `meal_plans`, `meal_plan_meals`, `meal_plan_items`, `meal_plan_substitutions` | Migrar e estruturar modelo relacional flexível com suporte a versionamento (`draft`, `published`, `archived`) e substituições. |
| `appointments` | **ADAPT** | `appointments` | Agenda e consulta especializada em nutrição (status: agendada, confirmada, em atendimento, realizada, cancelada). |
| `prescriptions_med` (receituário fármacos) | **DROP-LATER** | *Fora de escopo* | Não carregar para o ClyoNutri. Manter em arquivo de backup/baseline. |
| `psychology_tests` (testes Psi) | **DROP-LATER** | *Fora de escopo* | Não carregar para o ClyoNutri. |
| `workout_plans` (treinos Fit) | **DROP-LATER** | *Fora de escopo* | Não carregar para o ClyoNutri. |
| `financial_receivables` | **ADAPT** | `receivables` + `payments` | Adaptar para financeiro essencial: cobrança por consulta/plano, status, meios de pagamento (PIX, dinheiro, cartão). |
| `audit_logs` | **KEEP** | `audit_logs` | Trilha de auditoria obrigatória de acessos e mutações com `clinic_id`, `user_id`, `action`, `resource`, `ip`, `user_agent`. |

---

## 2. Regras de Isolamento e Migração
1. **Nenhum DROP destrutivo** imediato na base histórica.
2. Todo dado importado para o ClyoNutri recebe `clinic_id` validado.
3. Dados fora do escopo (Med/Psi/Fit) são mantidos isolados e congelados no baseline sem qualquer acoplamento de código ou runtime.
