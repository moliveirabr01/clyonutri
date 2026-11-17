# NI2-889 — Linguagem de usuário

Implementado na codebase canônica. Sem deploy e sem alteração de versão de release. Alterações locais anteriores preservadas.

## Textos alterados

| Área | Antes | Depois |
| --- | --- | --- |
| Linha do tempo | Tipo · registro #N | Título e data; referência para suporte no title |
| Comparativos do painel | sem base anterior | Sem dados do período anterior para comparar |
| Agenda do painel | 0 confirmada(s) · 1 realizada(s) | Nenhuma confirmada · 1 realizada; plural para 2+ |
| Sala de consulta | Trilha de auditoria desta consulta | Histórico desta consulta |
| Sala de consulta | registrado na trilha de auditoria | registrado no histórico da consulta |
| Nova versão de plano | [Cópia] Título | Título preservado e versão em campo separado |
| Lista de planos | Derivado de #N | Nova versão do plano anterior; ID no title |
| Lista de planos | Título | Título · versão N (rascunho), quando aplicável |
| Publicação legada | Título com [Cópia] sem revisão | Campo obrigatório para confirmar título; validação no servidor e persistência junto da publicação |
| API do paciente e receitas vinculadas | [Cópia] Título | Título, inclusive prefixos repetidos em snapshots legados; histórico não é regravado |
| Prontuário | Retificação do registro #N | Correção de uma anotação anterior; ID no title |
| Modelos de plano | a partir do plano #N | Criado a partir de um plano alimentar; ID no title |
| Barra de atendimento e encerramento | Consulta #N / Voltar ao atendimento #N | Consulta / Consulta atual / Voltar ao atendimento; ID no title |
| Importação | linha(s), pronto(s), existente(s), cadastrado(s), removido(s), paciente(s) | Singular e plural por quantidade |
| Exames e laudos | exame(s), exame(s) selecionado(s), laudo(s) enviado(s) | Singular e plural por quantidade |
| Pré-consulta | Faltam N resposta(s) | Falta 1 resposta / Faltam N respostas |
| Hidratação, assinatura | dia(s), dia(s) restante(s) | dia/dias, dia restante/dias restantes |
| Privacidade e integridade | pedido(s), registro(s) antigo(s) | Singular e plural por quantidade |
| Administração | cancelamento(s) histórico(s), consultório(s), clínica(s) | Singular e plural por quantidade |
| Calendário | feriado(s), regra(s), Já existem 1 regra(s) | Singular e plural por quantidade, inclusive aviso dinâmico |
| Prescrição | vez(es)/dia | vez/dia ou vezes/dia |
| Anamnese | da(s) versão(ões) concluída(s) | da versão concluída / das versões concluídas |

Varredura em views por `(s)`, `(es)`, `#<?=`, registro, trilha, payload, slug e id. Campos de código, atributos, âncoras, textos jurídicos e telas administrativas de auditoria mantêm identificadores necessários. A varredura final por `(s)` e `(es)` não encontrou ocorrências nas views.

## Validação executada

- `php tests/ni2_889_user_language_test.php`: **23 PASS, 0 FAIL**. SQLite isolado, duplicação real, persistência, publicação confirmada, rollback, snapshot, leitura da API, isolamento entre clínicas, renderização da linha do tempo e textos para 0/1/2.
- `php tests/meal_plan_and_prescription_lifecycle_test.php`: **48 PASS, 0 FAIL**.
- `php tests/v5456_dashboard_overview_test.php`: **63 PASS, 1 FAIL**. Falha preexistente: teste espera ocultar sábado vazio mesmo quando há domingo visível; implementação atual mantém sábado nesse caso. Reproduzida com DashboardOverview do HEAD anterior às alterações (dashboard-baseline.log). Quatro expectativas de texto foram atualizadas para a nova mensagem.
- Sintaxe PHP verificada nos arquivos de implementação/views alterados.
- `python3 tests/ni2_889_http_test.py`: **BLOCKED**. Preparação do banco sintético executou, mas `./bin/serve-local.sh` não pôde abrir `127.0.0.1:18081`: `Operation not permitted`. O script usa banco temporário e encerra o servidor ao terminar.
- Navegação no browser: **NOT TESTED**, dependente do servidor local.

Não confundir os testes diretos do controlador com uma requisição HTTP completa. Não houve alteração de .env, banco da aplicação, uploads ou sessões existentes. Logs das suítes anexados nesta pasta.
