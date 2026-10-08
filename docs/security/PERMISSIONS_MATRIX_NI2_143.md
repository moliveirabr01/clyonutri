# Matriz de Permissões e Isolamento Multi-Tenant Ponta a Ponta (NI2-143)

**Data de Emissão:** 08/10/2026  
**Épico:** NI2-143 (`[P0][Trust] Isolamento multi-tenant e autorização de ponta a ponta`)  
**Status de Governança:** APROVADO • FAIL-CLOSED  
**Base Arquitetural:** Banco Relacional Compartilhado com Discriminação Rígida de `clinic_id`, Enforce via `TenantGuard` e Barreira de Sessão/Token.

---

## 1. Princípios de Segurança e Isolamento

1. **Fail-Closed por Padrão:** Nenhuma consulta ou mutação é realizada sem um contexto ativo de tenant (`clinic_id`). Na ausência de tenant ou em caso de discrepância, a operação é rejeitada imediatamente com `TenantSecurityException` (HTTP 403 Forbidden).
2. **Discriminação Rígida na Camada de Dados:** Todas as tabelas que contêm dados de clientes ou pacientes possuem a coluna `clinic_id` associada obrigatoriamente via Foreign Key e índices compostos.
3. **Auditoria Transparente sem PII:** Todas as tentativas de violação cross-tenant geram um registro imutável na tabela `audit_logs` com a ação `SECURITY_CROSS_TENANT_VIOLATION_BLOCKED`. É proibido registrar CPF, nomes completos, e-mails, dados clínicos ou senhas no payload do log de segurança.
4. **Isolamento de Token e Sessão:** Sessões web e Personal Access Tokens (Sanctum) são atrelados exclusivamente ao par `(user_id, clinic_id)`. Tokens emitidos para o paciente de uma clínica não autorizam acesso a recursos de outra clínica ou de outro paciente.

---

## 2. Matriz de Autorização por Perfil (RBAC/ABAC)

| Módulo / Recurso | CLINIC_OWNER | NUTRITIONIST | RECEPTIONIST | PATIENT (Mobile/Portal) | Política Cross-Tenant |
| :--- | :---: | :---: | :---: | :---: | :---: |
| **Pacientes & Perfil 360** | Leitura/Escrita Total | Leitura/Escrita Total | Básico (Nome/Contato) | Apenas Próprio Perfil | **BLOQUEADO (403)** |
| **Prontuário & Anamnese** | Leitura/Escrita Total | Leitura/Escrita Total | Bloqueado | Bloqueado (Ver Resumo Publicado) | **BLOQUEADO (403)** |
| **Antropometria & Exames** | Leitura/Escrita Total | Leitura/Escrita Total | Bloqueado | Apenas Avaliações Compartilhadas | **BLOQUEADO (403)** |
| **Planos Alimentares** | Leitura/Escrita Total | Leitura/Escrita Total | Bloqueado | Apenas Plano Ativo Publicado | **BLOQUEADO (403)** |
| **Agenda & Consultas** | Total da Clínica | Própria Agenda | Agendamentos da Clínica | Próprias Consultas | **BLOQUEADO (403)** |
| **Financeiro & Recibos** | Total da Clínica | Faturamento Próprio | Registrar Pagamentos | Próprios Recibos | **BLOQUEADO (403)** |
| **Chat & Mensagens** | Auditoria Clínica | Próprias Conversas | Bloqueado | Própria Conversa | **BLOQUEADO (403)** |
| **Uploads de Documentos** | Total da Clínica | Total da Clínica | Apenas Upload | Próprios Documentos | **BLOQUEADO (403)** |
| **Importação CSV** | Execução Própria | Execução Própria | Bloqueado | Bloqueado | **BLOQUEADO (403)** |
| **Jobs & Fila Assíncrona** | Gestão da Fila | Bloqueado | Bloqueado | Bloqueado | **BLOQUEADO (403)** |

---

## 3. Cobertura nos 5 Fluxos Críticos

1. **Fluxos Web (MVC/Controllers):** Requisições Web validam a sessão ativa (`$_SESSION['clinic_id']`). Requisições com IDs de outras clínicas são barradas antes da renderização de view.
2. **Fluxos API (Mobile/REST):** Cada token Sanctum resolve `patient_id` e `clinic_id`. Tentativas de manipulação de parâmetros (IDOR) são rejeitadas com 401/403.
3. **Fluxos de Jobs e Filas:** Processadores de fila (Cron/Scheduler) filtram tarefas estritamente com `WHERE clinic_id = :clinic_id`.
4. **Fluxos de Importação:** Arquivos CSV nunca definem o `clinic_id`. O `clinic_id` é forçado a partir do usuário autenticado no sistema.
5. **Fluxos de Arquivos e Uploads:** Acesso a laudos, exames e fotos exige validação simultânea de `clinic_id` e token de autorização.
