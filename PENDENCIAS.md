# PENDENCIAS.md — Épico NI2-844 (`v54.62`)

- **Épico:** `NI2-844` — `[AUD-UX WEB][v54.61] Simplificação das jornadas do nutricionista — findings da auditoria UX 360°`
- **Versão:** `v54.62`
- **Data:** 15/11/2026

---

## 1. Pendências de Escopo do Épico `NI2-844` (`NI2-845` a `NI2-855` / `UX-001` a `UX-019`)

**Nenhuma pendência de implementação ou validação no escopo do épico `NI2-844`.**
Todas as 11 histórias filhas (`NI2-845` a `NI2-855`) e todos os 19 achados (`UX-001` a `UX-019`) foram integralmente implementados, integrados ao banco de dados e validados por testes automatizados de domínio, controladores HTTP e navegador real (Playwright Chromium + `axe-core` WCAG 2.1 A/AA + responsividade 390px/820px/1440px).

---

## 2. Itens Fora do Escopo de Código desta Entrega (Operação / Próxima Fase)

| ID | Descrição | Classificação | Ação Recomendada |
|---|---|---|---|
| **OP-001** | Aplicação das migrations pendentes (`000091`, `000092`, `000093` e `000094`) no banco MySQL de produção (`HostGator`), que ainda opera na linha `v54.51`/`v54.60`. | Operação / Deploy | Executar janela de manutenção com backup prévio para aplicar as migrations `000091`..`000094` (a migration `000094` é puramente aditiva e idempotente via `INSERT IGNORE` em `food_household_measures` e `foods`). |
| **UX-FASE-2** | Workspace único de atendimento — Fase 2 (edição inline de todas as etapas dentro da mesma URL sem navegação entre abas do paciente). A `NI2-848` especificou e entregou a **Fase 1** (barra única de etapas eliminando os 5 mecanismos concorrentes + gaveta lateral recolhível de Histórico Clínico + resumo antropométrico inline). | Evolução futura (fora do escopo da Fase 1 da `NI2-848`) | Avaliar telemetria de uso pós-lançamento da `v54.62` antes de decidir se a Fase 2 é necessária. |
| **UX-PESQUISA** | Validação com nutricionistas humanos reais (SUS / NASA-TLX e teste em Safari/WebKit e Firefox mobile). | Pesquisa UX | Conduzir sessão moderada com 5 nutricionistas utilizando o fluxo simplificado da `v54.62`. |
