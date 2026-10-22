# ClyoNutri II — Inventário e Classificação de Dados Pessoais (LGPD)

**Documento:** `docs/privacy/INVENTARIO_E_CLASSIFICACAO_DADOS_LGPD.md`  
**Referência da Issue:** NI2-162 ([P0] Produzir inventário e classificação de dados sensíveis) / Épico NI2-145  
**Versão:** 1.0.0  
**Data:** 07/10/2026  
**Status:** Aprovado e Homologado  

---

## 1. Contexto e Objetivos

Este documento formaliza o **Registro de Operações de Tratamento de Dados Pessoais (ROPA / Data Mapping)** do ecossistema ClyoNutri II, em conformidade com o Art. 37 da Lei Geral de Proteção de Dados (Lei nº 13.709/2018 - LGPD), as resoluções da Autoridade Nacional de Proteção de Dados (ANPD) e as normativas específicas da área de saúde e nutrição (Conselho Federal de Nutricionistas - CFN e Lei nº 13.787/2018 de Prontuário Eletrônico).

---

## 2. Agentes de Tratamento

- **Controlador do Tratamento:** A clínica de nutrição ou o nutricionista autônomo contratante do ClyoNutri II (titular da organização/tenant).
- **Operador do Tratamento:** ClyoNutri II Tecnologia (fornecedor da plataforma SaaS, processamento e infraestrutura).
- **Suboperadores Identificados:**
  - HostGator (Hospedagem, processador de banco de dados MySQL e infraestrutura de rede).
  - Serviços de e-mail transacional (SMTP seguro) e armazenamento em nuvem para backups criptografados.

---

## 3. Matriz de Classificação de Dados por Tabela e Domínio

| Tabela / Entidade | Campos Principais | Categoria de Dado | Sensibilidade | Finalidade de Tratamento | Base Legal (LGPD) |
|---|---|---|---|---|---|
| `users` | `name`, `email`, `password_hash`, `role` | Pessoal Comum | Baixa / Média | Autenticação, controle de acesso e auditoria | Art. 7º, V (Execução de contrato) |
| `professionals` | `crn`, `crn_region`, `specialty`, `bio` | Profissional / Público | Média | Identificação perante o conselho profissional e vitrine | Art. 7º, II (Obrigação legal) / V (Contrato) |
| `patients` | `name`, `email`, `phone`, `birth_date`, `cpf` | Pessoal Cadastral | Média | Identificação do paciente, contato e prontuário | Art. 7º, V (Contrato) e Art. 11, II, "f" (Tutela da saúde) |
| `anamneses` | `habits`, `allergies`, `clinical_history`, `routine` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Avaliação clínica, rastreio de patologias e intolerâncias | Art. 11, II, "f" (Tutela da saúde) e "a" (Obrigação legal) |
| `clinical_records` | `title`, `content_json`, `record_hash` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Prontuário nutricional eletrônico longitudinal | Art. 11, II, "f" (Tutela da saúde) e Art. 11, II, "a" (Lei 13.787/2018) |
| `anthropometric_assessments` | `weight_kg`, `height_cm`, `bmi`, `body_fat_pct` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Diagnóstico nutricional e evolução corporal | Art. 11, II, "f" (Tutela da saúde) |
| `meal_plans` / `versions` | `title`, `meals`, `prescriptions`, `substitutions` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Prescrição dietética personalizada | Art. 11, II, "f" (Tutela da saúde) |
| `food_diary_entries` | `meal_name`, `photo_path`, `hunger_level`, `symptoms` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Acompanhamento de adesão e sintomas gastrointestinais | Art. 11, II, "f" (Tutela da saúde) e I (Consentimento no app) |
| `health_sync_data` | `metric_type`, `value`, `unit`, `source` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Monitoramento biométrico (Apple Health / Google Fit) | Art. 11, I (Consentimento específico do paciente no app) |
| `chat_messages` | `message`, `attachment_path` | Pessoal / Clínico | Média / Alta | Comunicação profissional-paciente | Art. 7º, V (Contrato) / Art. 11, II, "f" (Saúde) |
| `receivables` / `payments` | `amount_cents`, `status`, `receipt_code` | Financeiro | Média | Cobrança de consultas e conformidade fiscal | Art. 7º, II (Obrigação fiscal) e V (Contrato) |
| `audit_logs` | `action`, `resource`, `resource_id`, `clinic_id` | Técnico / Segurança | Média | Trilha de auditoria e segurança contra violações | Art. 7º, II e Art. 11, II, "g" (Prevenção à fraude e segurança) |
| `patient_consents` | `purpose_code`, `granted`, `ip_address`, `timestamp` | Jurídico / Privacidade | Média | Gestão e prova verificável de consentimentos | Art. 7º, I e Art. 11, I (Comprovação de consentimento) |
| `checkins` (NI2-96) | `meal_adherence_pct` (0–100 em passos de 25), `energy_level`, `hunger_level`, `notes`, `checkin_date` | **Dado Pessoal Sensível de Saúde** | **ALTA** | Check-in diário da paciente no app para o acompanhamento do nutricionista | Art. 11, II, "f" (Tutela da saúde) |
| `analytics_daily_metrics` (NI2-96) | `metric_date`, `clinic_id`, `metric`, `event_count`, `unique_patients` | Dado agregado, sem titular identificável | Baixa | Métricas de produto: plano visto, check-ins e hidratação por dia e clínica | Não é dado pessoal após a agregação (Art. 12); ver `docs/privacy/METRICAS_DE_ADESAO_NI2-96.md` |
| `analytics_daily_subjects` / `analytics_daily_salts` (NI2-96) | `subject_hash` (HMAC com sal aleatório do dia), `salt` | Pseudônimo transitório | Média | Contar pacientes distintos no dia; apagados no dia seguinte | Art. 7º, IX (Legítimo interesse), com medidas do Art. 10, §3º; ver documento da NI2-96 |

---

## 4. Ciclo de Vida e Regras Estritas de Retenção

### 4.1 Retenção Obrigatória de Prontuários (Legal Hold)
- **Prazo Legal:** Mínimo de **20 anos** a contar do último atendimento, conforme estipulado pelo Art. 6º da **Lei Federal nº 13.787/2018** e normas correlatas do Conselho Federal de Nutricionistas (CFN).
- **Regra do Sistema:** Solicitações de "Direito ao Esquecimento" ou exclusão feitas pelo titular **NÃO autorizam a exclusão em cascata do prontuário**. Nesses casos:
  1. Os dados de contato, marketing e notificações são anonimizados ou excluídos.
  2. O prontuário clínico é descaracterizado e preservado sob salvaguarda de guarda legal (`rejected_legal_hold` para destruição física, com anonimização cadastral e justificativa jurídica transparente fornecida ao titular).

### 4.2 Retenção Financeira e Fiscal
- Mínimo de **5 anos** para registros de cobrança, faturas e recibos (Código Tributário Nacional e Código Civil).

### 4.3 Logs e Sessões de Acesso
- Mínimo de **6 meses** para logs de acesso a aplicações de internet, em estrito cumprimento ao Art. 15 do Marco Civil da Internet (Lei nº 12.965/2014).

---

## 5. Medidas Técnicas de Segurança Aplicadas

1. **Isolamento Lógico Multi-Tenant:** Todas as consultas e escritas utilizam `TenantGuard`, prevenindo vazamento de dados entre clínicas (IDOR).
2. **Cadeia Criptográfica:** Prontuários auditáveis com hashing SHA-256 encadeado contra adulteração.
3. **Storage Privado de Anexos:** Arquivos clínicos nunca ficam expostos em URLs públicas diretas; acesso estritamente autenticado e validado por vínculo do paciente.
4. **Minimização de Logs:** Logs de auditoria (`audit_logs`) nunca registram conteúdo clínico sensível (diagnósticos, anotações de evolução, valores médicos).
5. **Transferência Internacional:** Dados armazenados prioritariamente em datacenter brasileiro ou com garantia de cláusulas padrão contratuais.
