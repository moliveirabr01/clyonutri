# CLYONUTRI II — RELATÓRIO DE AUDITORIA & ENTREGA VERIFICÁVEL
## Épico NI2-10: [P1] Agenda e Jornada de Consulta

**Data da Auditoria:** 08/10/2026  
**Ambiente:** Local / CI Multi-tenant (PHP 8.2+, SQLite/MySQL)  
**Status do Épico:** VERIFICADO & APROVADO (46 PASS, 0 FAIL)  
**Regra de Ouro:** Zero Mocks, Zero Placeholders, Persistência Relacional Estrita, Assinatura Criptográfica SHA-256 e Isolamento Multitenant Ativo.

---

### 1. Resumo Executivo das Entregas (Histórias do Épico NI2-10)

| Issue | Descrição | Status Verificável | Arquitetura / Evidência |
|---|---|---|---|
| **NI2-81** | `[Agenda] Criar e editar agendamento` | **PASS** | `AppointmentService::schedule()` com validação de duração (15–240 min), detecção de sobreposição/conflito com flag `allowConflict` para encaixes e auditoria inicial. Edição e reagendamento atômicos com recálculo de janelas temporais. |
| **NI2-82** | `[Agenda] Estados e ciclo da consulta` | **PASS** | Máquina de estados finita rigorosa (`scheduled` ➔ `confirmed` ➔ `in_progress` ➔ `completed` / `cancelled` / `no_show`). Estados terminais blindados contra reversão indevida. Trilha de auditoria persistida em `appointment_status_history` com usuário e justificativa. |
| **NI2-83** | `[Agenda] Agenda diária e semanal responsiva` | **PASS** | Visualização diária cronológica e grade semanal de 7 dias com agrupamento automático (`listByWeek()`). Controles de data (Hoje, Anterior, Próximo, Datepicker). Layout mobile com `@media (max-width: 768px)` prevenindo achatamento de sidebar. |
| **NI2-84** | `[Consulta] Tela de execução do atendimento` | **PASS** | Rota `/consulta/{id}` com prontuário integrado (Perfil 360, histórico de consultas, evolução antropométrica, plano ativo). Conclusão de consulta em `completeConsultation()` com assinatura e hash SHA-256 encadeado no `ClinicalRecordService`. |
| **NI2-85** | `[Dashboard] Agenda e pacientes que exigem atenção` | **PASS** | Seção "Pacientes & Prioridades que Exigem Atenção" calculada dinamicamente via consultas SQL reais (consultas de hoje não confirmadas, faltosos/no-show recentes, planos alimentares em rascunho pendentes). Contadores reais sem números fixos no código. |

---

### 2. Infraestrutura e Banco de Dados

- **Migration Criada:** `database/migrations/2026_10_08_000011_create_appointment_lifecycle_and_history_tables.php`
- **Tabelas e Índices Estruturados:**
  - `appointment_status_history`: Registro imutável de transições de status com colunas `appointment_id`, `clinic_id`, `from_status`, `to_status`, `notes`, `changed_by_user_id`, `created_at`.
  - Índices compostos de alta performance:
    - `idx_appointments_clinic_scheduled`: `(clinic_id, scheduled_at)`
    - `idx_appointments_clinic_prof`: `(clinic_id, professional_id)`
    - `idx_appointments_clinic_patient`: `(clinic_id, patient_id)`
    - `idx_appointments_clinic_status`: `(clinic_id, status)`
    - `idx_app_hist_lookup`: `(appointment_id, clinic_id)`

---

### 3. Matriz de Estados e Ciclo da Consulta (NI2-82)

```
                    ┌──────────────┐
                    │  scheduled   │
                    └──────┬───────┘
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
       ┌───────────┐ ┌───────────┐ ┌───────────┐
       │ confirmed │ │ cancelled │ │  no_show  │  (Terminais)
       └─────┬─────┘ └───────────┘ └───────────┘
             │
             ▼
      ┌─────────────┐
      │ in_progress │
      └──────┬──────┘
             │
             ▼
       ┌───────────┐
       │ completed │  (Terminal com Registro Criptográfico SHA-256)
       └───────────┘
```

**Regras de Governança Estrita:**
- Não é permitida a transição a partir de `completed`, `cancelled` ou `no_show` (`InvalidAppointmentTransitionException`).
- Toda mudança gera linha na tabela `appointment_status_history`.
- Paciente não pode confirmar consulta cancelada ou concluída.

---

### 4. Execução do Atendimento e Integridade Clínica (NI2-84)

Ao finalizar a consulta através do formulário de evolução ou API:
1. Valida-se o pertencimento da consulta e do paciente à clínica via `TenantGuard`.
2. A consulta é transicionada para `completed`.
3. O serviço `ClinicalRecordService` cria um novo registro de prontuário eletrônico tipo append-only.
4. Gera-se o hash SHA-256 computado a partir do estado do prontuário:
   $$\text{Hash} = \text{SHA256}(\text{previous\_hash} \parallel \text{patient\_id} \parallel \text{content\_json} \parallel \text{timestamp})$$
5. O hash é persistido e indexado de forma imutável, garantindo inviolabilidade perante auditoria clínica.

---

### 5. Evidência Reproduzível dos Testes Automatizados

Comando de Execução:
```bash
php tests/agenda_and_consultation_lifecycle_test.php
```

Resultado Obtido:
```
====================================================================
   CLYONUTRI II — SUÍTE DE VERIFICAÇÃO INTEGRAL DO ÉPICO NI2-10
   Agenda, Ciclo da Consulta e Execução do Atendimento Clínico
====================================================================

[1] Testando NI2-81 — Criar e Editar Agendamento...
  [PASS] Consulta criada com status inicial 'scheduled'
  [PASS] Duração e horário salvos corretamente
  [PASS] Agendamento persistido fisicamente no banco relacional
  [PASS] Trilha de auditoria inicial registrada na criação
  [PASS] Conflito de horário detectado e bloqueado com AppointmentConflictException
  [PASS] Encaixe intencional criado com sucesso quando allowConflict = true
  [PASS] Consulta reagendada com sucesso para 14:00
  [PASS] Duração atualizada para 90 minutos
  [PASS] Tentativa de agendar paciente de outro tenant bloqueada (403)

[2] Testando NI2-82 — Estados e Ciclo da Consulta...
  [PASS] Transição scheduled ➔ confirmed aprovada
  [PASS] Transição confirmed ➔ in_progress aprovada
  [PASS] Transição in_progress ➔ completed aprovada
  [PASS] Transição inválida completed ➔ scheduled bloqueada com exceção
  [PASS] Transição scheduled ➔ no_show aprovada
  [PASS] Transição inválida no_show ➔ in_progress bloqueada (terminal)
  [PASS] Histórico de auditoria registrou todas as 4 etapas do ciclo
  [PASS] Etapa 1 é scheduled
  [PASS] Etapa 2 é confirmed com justificativa
  [PASS] Etapa 3 é in_progress
  [PASS] Etapa 4 é completed

[3] Testando NI2-83 — Visão Diária e Semanal da Agenda...
  [PASS] listByDay retornou consultas do dia atual
  [PASS] Consultas ordenadas cronologicamente
  [PASS] Contém dados do paciente (nome e telefone)
  [PASS] listByWeek retornou estrutura semanal de 7 dias
  [PASS] Total de consultas da semana calculado corretamente

[4] Testando NI2-84 — Execução do Atendimento e Conclusão Clínica...
  [PASS] Consulta finalizada com status 'completed'
  [PASS] Registro de prontuário gerado
  [PASS] Hash SHA-256 de 64 caracteres computado e encadeado
  [PASS] Prontuário armazenado append-only com hash imutável
  [PASS] Conteúdo clínico contém anotações reais da consulta

[5] Testando NI2-85 — Dashboard com Métricas Reais e Prioridades...
  [PASS] Métricas do dia calculadas dinamicamente
  [PASS] Contagem de confirmadas é numérica real
  [PASS] Contagem de completed reflete consultas concluídas
  [PASS] Contagem de no_show reflete faltosos
  [PASS] Lista de pendências que exigem atenção retornou array estruturado
  [PASS] Paciente faltoso identificado automaticamente na lista de atenção do Dashboard

[6] Testando Integração das Rotas HTTP Web (/agenda, /consulta/id)...
  [PASS] GET /agenda retornou HTML válido
  [PASS] Página contém controles de navegação (Dia, Semana, Hoje)
  [PASS] Página exibe consultas reais criadas no banco
  [PASS] Formulário de novo agendamento presente com CSRF
  [PASS] GET /consulta/{id} retornou HTML de atendimento clínico
  [PASS] Tela de consulta contém contexto do paciente
  [PASS] Tela contém campo de evolução clínica e botão de concluir
  [PASS] Tela exibe trilha de auditoria da consulta
  [PASS] GET /dashboard exibe seção de Pacientes & Prioridades que Exigem Atenção
  [PASS] Dashboard exibe métricas calculadas reais do dia

====================================================================
   RESULTADO DA SUÍTE DE TESTES NI2-10: 46 PASS, 0 FAIL
====================================================================
```

---

### 6. Conclusão

O Épico **NI2-10** cumpre 100% dos critérios de aceitação funcionais, técnicos, de segurança multitenant e de rastreabilidade clínica, estando apto para validação formal de release.
