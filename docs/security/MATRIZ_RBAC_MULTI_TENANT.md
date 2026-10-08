# ClyoNutri II — Matriz de Isolamento Multi-Tenant e RBAC

**Documento:** `docs/security/MATRIZ_RBAC_MULTI_TENANT.md`  
**Referência da Issue:** NI2-143 ([P0][Trust] Isolamento multi-tenant e autorização de ponta a ponta)  
**Versão:** 1.0.0  
**Data:** 07/10/2026  
**Status:** Aprovado e Implementado  

---

## 1. Princípios Fundamentais de Segurança

1. **Zero Trust Cross-Tenant:** Nenhuma requisição confia em `tenant_id`, `clinic_id`, `patient_id` ou `professional_id` enviados no corpo ou nos parâmetros da requisição sem validação prévia de ownership no banco de dados.
2. **Contexto Ativo Imutável:** O `TenantContext::getClinicId()` é a fonte de autoridade do locatário ativo. Toda query e comando são restritos a este escopo.
3. **Falha Atômica (Fail-Closed):** Qualquer tentativa de mutação ou leitura de recurso que não pertença à clínica do contexto ativo interrompe imediatamente a execução lançando `TenantSecurityException` (HTTP 403 Forbidden ou HTTP 404 Not Found), antes de qualquer escrita no banco de dados.
4. **Log de Auditoria Não-Sensível (Sem PII):** Todas as violações cross-tenant disparam eventos de log `SECURITY_CROSS_TENANT_VIOLATION_BLOCKED` na tabela `audit_logs`, registrando `clinic_id`, recurso, ID do recurso violado, timestamp e IP/Actor, sem vazar nomes de pacientes, dados clínicos ou segredos.

---

## 2. Matriz de Perfis de Acesso (RBAC)

O ClyoNutri II adota controle de acesso baseado em papéis (RBAC) combinado com controle de acesso baseado em atributos/posse (ABAC):

| Recurso / Domínio | Ação | Paciente (`patient`) | Secretária / Assistente (`assistant`) | Nutricionista (`nutritionist`) | Administrador da Clínica (`admin`) | Validador no Backend |
|---|---|:---:|:---:|:---:|:---:|---|
| **Prontuário & Anamnese** | Visualizar | Próprio apenas | Negado | Permitido (vínculo clínico) | Permitido (auditoria clínica) | `TenantGuard::assertPatientBelongsToClinic` |
| **Prontuário & Anamnese** | Criar / Editar | Negado | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Antropometria & Bioimpedância** | Visualizar | Próprio apenas | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Antropometria & Bioimpedância** | Registrar | Negado | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Plano Alimentar** | Visualizar | Próprio (Publicado) | Negado | Permitido (Rascunho/Publ.) | Permitido | `TenantGuard::assertMealPlanBelongsToClinic` |
| **Plano Alimentar** | Criar / Modificar | Negado | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Plano Alimentar** | Publicar | Negado | Negado | Permitido | Permitido | `TenantGuard::assertMealPlanBelongsToClinic` |
| **Diário Alimentar** | Registrar Refeição | Próprio apenas | Negado | Negado | Negado | `TenantGuard::assertPatientBelongsToClinic` |
| **Diário Alimentar** | Visualizar Timeline | Próprio apenas | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Engajamento & Hidratação** | Registrar / Check-in | Próprio apenas | Negado | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Agendamento & Consultas** | Agendar / Reagendar | Próprio (se habilitado) | Permitido | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` + `assertProfessionalBelongsToClinic` |
| **Agendamento & Consultas** | Atualizar Status | Negado | Permitido | Permitido | Permitido | `TenantGuard::assertAppointmentBelongsToClinic` |
| **Financeiro & Recebíveis** | Criar Recebível | Negado | Permitido | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Financeiro & Recebíveis** | Liquidar / Baixa | Negado | Permitido | Permitido | Permitido | `TenantGuard::assertReceivableBelongsToClinic` |
| **Chat & Mensageria** | Enviar Mensagem | Próprio na conversa | Negado | Conversas próprias | Auditoria da clínica | `TenantGuard::assertConversationBelongsToClinic` |
| **Teleconsulta** | Gerar Sessão WebRTC | Negado | Negado | Permitido | Permitido | `TenantGuard::assertAppointmentBelongsToClinic` |
| **CRM & Planos / Pacotes** | Vincular ao Paciente | Negado | Permitido | Permitido | Permitido | `TenantGuard::assertPatientBelongsToClinic` |
| **Sincronização de Saúde (Wearables)** | Ingerir Métricas | Próprio apenas | Negado | Negado | Negado | `TenantGuard::assertPatientBelongsToClinic` |
| **Perfil Público / Vitrine** | Criar / Alterar Perfil | Negado | Negado | Permitido (Próprio) | Permitido | `TenantGuard::assertProfessionalBelongsToClinic` |

---

## 3. Diretrizes de HTTP Status Code (403 vs 404)

- **HTTP 403 Forbidden:** Utilizado em endpoints autenticados e operações internas onde a infraestrutura rejeita explicitamente a violação de tenancy (ex.: tentar anexar uma avaliação a um paciente de outra organização).
- **HTTP 404 Not Found:** Recomendado em rotas públicas ou endpoints diretos por ID (ex.: `GET /api/v1/patients/{id}`), para **evitar enumeração de recursos e vazamento de existência** de IDs pertencentes a outros tenants.
- **`TenantSecurityException`:** Suporta código configurável (`$code = 403` por padrão), lançando exceção rica capturável pelos handlers HTTP.

---

## 4. Arquitetura da Camada de Blindagem (`TenantGuard`)

Localização: `src/Core/Security/TenantGuard.php`  
Exceção: `src/Core/Security/TenantSecurityException.php`

### 4.1 Métodos Centrais de Defesa

1. `TenantGuard::assertPatientBelongsToClinic(int $clinicId, int $patientId): void`
2. `TenantGuard::assertProfessionalBelongsToClinic(int $clinicId, int $professionalId): void`
3. `TenantGuard::assertAppointmentBelongsToClinic(int $clinicId, int $appointmentId): void`
4. `TenantGuard::assertReceivableBelongsToClinic(int $clinicId, int $receivableId): void`
5. `TenantGuard::assertConversationBelongsToClinic(int $clinicId, int $conversationId): void`
6. `TenantGuard::assertMealPlanBelongsToClinic(int $clinicId, int $planId): void`
7. `TenantGuard::assertFoodBelongsToClinicOrGlobal(int $clinicId, int $foodId): void`

### 4.2 Mecanismo de Auditoria de Violações

Em caso de violação detectada:
1. `TenantGuard` intercepta a chamada antes do SQL execute/insert.
2. Registra na tabela `audit_logs`:
   - `clinic_id`: ID da clínica do contexto ativo
   - `action`: `'SECURITY_CROSS_TENANT_VIOLATION_BLOCKED'`
   - `details`: JSON contendo `{ "resource": "patient", "resource_id": 101, "timestamp": "...", "message": "Access denied..." }` (sem PII)
3. Dispara `TenantSecurityException` abortando a transação.

---

## 5. Serviços Auditados e Blindados (Cobertura 100% P0)

1. **`MealPlanService`:** Criação e publicação de planos protegidos contra pacientes e planos cross-tenant.
2. **`AnthropometryService`:** Ingestão de medidas antropométricas e relatórios evolutivos blindados por paciente.
3. **`FinanceService`:** Criação de títulos a receber e liquidação de parcelas isoladas por tenant.
4. **`AppointmentService`:** Agendamento de consultas e alteração de status com validação dupla (paciente + profissional + consulta).
5. **`FoodDiaryService`:** Registro de fotos/refeições e consulta de timeline estritamente associados ao paciente da clínica.
6. **`EngagementService`:** Registro de ingestão hídrica, metas e check-ins semanais protegidos.
7. **`ChatService`:** Criação de conversas, envio de mensagens e histórico isolados por canal e paciente da clínica.
8. **`PublicProfileService`:** Criação e alteração de perfis de atendimento validados contra a clínica do profissional.
9. **`TelehealthService`:** Geração de salas de teleatendimento WebRTC estritamente amarradas ao agendamento do tenant.
10. **`PracticeCrmService`:** Venda e associação de pacotes/programas a pacientes vinculados à clínica.
11. **`HealthSyncService`:** Ingestão de batimentos cardíacos, passos e sono (Apple Health / Google Fit) isolados por paciente.
12. **`AnamnesisService`:** Prontuário e histórico de patologias protegidos contra qualquer tentativa de escrita cross-tenant.
