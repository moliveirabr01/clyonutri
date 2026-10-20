# Relatório de Homologação E2E — Autosave e Recuperação de Rascunhos (V17-02)

**Data:** 2026-10-16T04:57:57.196Z  
**Artefato auditado:** release v18 / staging HostGator  
**Status geral:** ✓ APROVADO (7/7 cenários E2E validados)

## Resumo dos Cenários Homologados

| ID | Cenário | Resultado | Evidência Principal |
|---|---|---|---|
| **V17-02-01** | Digitação seguida de perda de conexão antes da confirmação com recuperação automática | **PASS** | `{"backupKey":"cn_consult_draft:1:2:2","hasLocalBackupOnFailure":true,"errorStatusShown":true,"savedStatusAfterRecovery":true,"serverVersion":1,"evolutionSummaryLength":58}` |
| **V17-02-02** | Duas abas editando simultaneamente geram 409 Conflict sem perda de dados | **PASS** | `{"abaASaved":true,"abaBConflict":true,"abaBBackupPreserved":true,"serverEvolution":"Aba A: Conduta nutricional atualizada pela aba 1.","serverVersion":2}` |
| **V17-02-03** | Erro HTTP 503 com correlation_id e 401 JSON de sessão expirada preservam backup local | **PASS** | `{"shows503Correlation":true,"showsAuthExpired":true,"preservedOn401":true}` |
| **V17-02-04** | Submit do formulário aguarda gravação em voo e não perde texto digitado | **PASS** | `{"submitIntercepted":true,"submitCalls":1}` |
| **V17-02-05** | Isolamento estrito de rascunhos no navegador e expurgo de chaves estrangeiras/expiradas | **PASS** | `{"foreignClinicPurged":true,"foreignUserPurged":true,"stalePurged":true,"legacyPurged":true}` |
| **V17-02-06** | Limpeza de backup local após confirmação do servidor e cabeçalho Clear-Site-Data no logout | **PASS** | `{"backupClearedOnServerSave":true,"hasClearSiteData":true}` |
| **V17-02-07** | Rascunho e campos clínicos preservados na tela em caso de falha no envio de conclusão | **PASS** | `{"hasDraftForm":true,"hasSubmitErrorNotice":true}` |

## Conclusão da Auditoria V17-02
Todos os 7 critérios do gate de homologação da V17-02 foram executados de ponta a ponta através do script real do navegador (`public/assets/js/consulta-rascunho.js`) acoplado ao servidor real (`public/index.php`). Nenhum dado clínico foi perdido durante quedas simuladas de rede, conflitos de versão concorrente, indisponibilidade transitória (503), expiração de sessão (401 JSON) ou interrupção de submit em voo.
