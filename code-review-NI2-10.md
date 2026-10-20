# Code review — NI2-10: Agenda e jornada de consulta

Data: 19/10/2026. Resultado inicial: **pedir alterações**.

## Atualização: correções implementadas

Os cinco achados deste review foram corrigidos na árvore de trabalho:

- Conclusão de consulta encerrada é rejeitada antes de criar outra evolução; a leitura e a verificação do estado usam bloqueio dentro da transação.
- Edição só é aceita para consultas agendadas ou confirmadas, independentemente do campo `origin` do formulário.
- Detecção de conflitos considera intervalos completos entre dias, com SQL próprio para SQLite e MySQL. Leituras de conflito no MySQL usam bloqueio para consultar o estado atual.
- Criação, edição e transição de estado persistem o histórico na mesma transação. Falhas de auditoria são propagadas e revertidas, inclusive por savepoint quando há transação externa.
- Datas e horários são validados estritamente, incluindo os formatos enviados por `datetime-local`; datas inexistentes são rejeitadas.

Validação após as alterações:

| Suíte | Resultado |
|---|---|
| `tests/agenda_review_regression_test.php` (nova) | 43 asserções aprovadas |
| `tests/agenda_and_consultation_lifecycle_test.php` | 46/46 |
| `tests/agenda_operational_and_concurrency_test.php` | 32/32 |
| `tests/canonical_first_consultation_e2e_journey_test.php` | 32/32 |
| `tests/v14_ux_audit_test.php` | 10 cenários aprovados |

A nova suíte utiliza banco sintético em memória e um arquivo temporário exclusivo para concorrência. Dois processos PHP independentes tentam concluir a mesma consulta: exatamente um conclui, o outro é rejeitado e apenas uma evolução adicional é persistida. Também testa falha real de INSERT no histórico, rollback do prontuário e preservação da transação externa.

As suítes preexistentes foram executadas em cópia isolada. A jornada canônica precisou de banco novo na repetição, pois conserva um agendamento no mesmo horário entre execuções. Permanecem os avisos preexistentes de sessão e de ReflectionMethod no PHP 8.5. Não houve execução em MySQL nem validação visual por navegador. Não foi feita publicação, commit ou alteração do Jira.

Os achados e resultados abaixo registram a revisão original, antes das correções.

## Escopo e método

Issue: https://caplybr.atlassian.net/browse/NI2-10, histórias NI2-81 a NI2-85. Commit de entrega informado no Jira: `44ebc08`. Base examinada: HEAD `496f4ff`, branch `merge/v11-teleconsulta`, incluindo alterações locais rastreadas em AppointmentService, DashboardController e dependências. Os cinco padrões abaixo também existem no commit de entrega; as reproduções foram executadas na implementação atual.

Revisei agendamento/edição, máquina de estados, conclusão e prontuário, agenda diária/semanal, prioridades do dashboard, rotas, autorização e rascunhos. A execução ocorreu em `/tmp/ni2-10-review.xpgar5`, com banco SQLite sintético, migrações e seeds locais. Não utilizei o banco de desenvolvimento. Não alterei código de produção nem o Jira.

## Achados

### 1. [P1] Conclusão repetida acrescenta novos registros clínicos

Local: `src/Domain/Appointment/AppointmentService.php:354–394`.

`completeConsultation` não rejeita uma consulta já concluída antes de chamar `appendRecord`. Ao final, `updateStatus(completed)` retorna sucesso quando o estado já é completed. Assim, repetir o POST de conclusão, inclusive após uma resposta perdida, acrescenta outra evolução assinada em vez de retornar o resultado anterior ou rejeitar a operação. Na prova, a consulta 17 gerou os registros 4 e 5, ambos aceitos, mantendo completed.

Correção: verificar e reservar o estado permitido dentro da transação antes de acrescentar o registro, com proteção contra concorrência. Tornar a conclusão idempotente por consulta ou rejeitar novas conclusões; alterações posteriores devem seguir o fluxo de retificação.

### 2. [P1] Edição permite trocar o paciente de uma consulta concluída

Local: `src/Domain/Appointment/AppointmentService.php:175–195,221–230`; entrada web em `DashboardController.php:2392–2412`.

`edit` não verifica o estado da consulta e permite alterar patient_id, professional_id e scheduled_at. A proteção do controller só é aplicada quando o próprio POST contém origin=reagendar; omitindo esse campo, o pedido segue para o método irrestrito. Na reprodução, uma consulta completed passou do paciente 6 para o paciente 7 e mudou de horário, enquanto a evolução assinada continuou vinculada ao paciente 6. Isso torna agenda, histórico e prontuário inconsistentes.

Correção: aplicar as restrições no serviço e no UPDATE, independentemente de origin; impedir troca dos participantes e do horário de atendimentos encerrados, com fluxo próprio para correções auditadas.

### 3. [P2] Sobreposição que atravessa a meia-noite não é detectada

Local: `src/Domain/Appointment/AppointmentService.php:794–804`.

`findConflict` consulta somente agendamentos cuja data de início é a mesma do novo agendamento. Na prova, aceitou para o mesmo profissional uma consulta em 15/01/2031 às 23:30 de 120 minutos e outra em 16/01/2031 às 00:15 de 60 minutos, ambas sem permitir encaixe. Há sobreposição de uma hora. O cenário é aplicável a profissionais sem disponibilidade semanal configurada, configuração que o serviço aceita.

Correção: buscar por interseção dos intervalos completos, incluindo consultas iniciadas no dia anterior e no dia seguinte quando necessário, sem limitar pela data de início.

### 4. [P2] Falha de auditoria é ignorada e a mudança de estado é confirmada

Local: `src/Domain/Appointment/AppointmentService.php:308–337,878–880`.

`recordStatusHistory` captura qualquer exceção e não a propaga. `updateStatus` também não agrupa atualização e histórico em uma transação própria. Injetei uma falha real no INSERT do histórico com um trigger SQLite: a confirmação retornou true e persistiu confirmed, mas o histórico permaneceu com apenas o evento de criação. A linha do tempo de execução depende desse histórico e pode perder os horários reais de início/conclusão.

Correção: persistir estado e histórico atomicamente, propagar falhas e reverter a mudança. A mesma garantia deve valer para o agendamento inicial e para a edição; ausência de migração não deve ser tratada como sucesso.

### 5. [P2] Datas inexistentes são aceitas e convertidas em outro dia

Local: `src/Domain/Appointment/AppointmentService.php:837–843`.

Construir DateTimeImmutable sem verificar os avisos não valida estritamente a data. O valor `2031-02-30 15:00` foi aceito e gravado como `2031-03-02 15:00:00`. O problema é alcançável por POST mesmo que o navegador normalmente limite o campo de data.

Correção: validar os formatos de entrada permitidos com parsing estrito, verificar erros/avisos e comparar a data normalizada com a entrada. Rejeitar datas inválidas em vez de ajustá-las.

## Verificação

- `agenda_and_consultation_lifecycle_test.php`: **46 PASS, 0 FAIL**. Preparação adicional: clínica sintética de ID 3, assumida pelo teste. A suíte emite avisos de sessão porque imprime antes de iniciar a sessão.
- `agenda_operational_and_concurrency_test.php`: **32 PASS, 0 FAIL**.
- Provas adicionais: cinco cenários acima reproduzidos em `tests/review_ni2_10_cases.php` na cópia temporária, sem alteração do código de produção.
- A suíte operacional chama sequencialmente o agendamento de um horário ocupado para verificar o que chama de concorrência; ela não comprova comportamento sob duas requisições simultâneas.
- Não executei MySQL, chamadas HTTP por navegador, nem validação visual nos tamanhos de tela. Os testes de renderização usam inclusão de `public/index.php` no processo PHP; os testes de responsividade verificam o CSS.

Os testes existentes demonstram os fluxos cobertos, mas não detectam os cinco casos negativos acima.
