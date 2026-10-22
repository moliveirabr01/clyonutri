# Code review: NI2-137, próxima consulta e ações permitidas de agenda

Data: 21/10/2026 (horário de Brasília)
Card: [NI2-137](https://caplybr.atlassian.net/browse/NI2-137), História, status **Feito** desde 08/10/2026 00:18, cerca de 2 horas depois de criado. Não há comentários, evidências anexadas nem commits que citem NI2-137.
Revisão somente leitura. Nada foi alterado no Mac, no Jira nem em produção.

## Status das correções (22/10/2026)

Todos os achados (C1 a B7) foram corrigidos em `~/Nutri_II`. O A5 foi levado ao `~/Nutri_II-v20`. Decisões do produto usadas:
- **Remarcação é um pedido.** A paciente escolhe um horário livre e o consultório aceita ou recusa na agenda.
- **Padrão por clínica:** confirmar presença ligado; cancelar e pedir remarcação desligados. Cada clínica muda isso em Configurações.

O card continua **não aprovado para release** até haver validação independente. Testes em aparelho real e em MySQL 5.7 não foram feitos (NOT TESTED).

### O que mudou

| Achado | Correção |
|---|---|
| C1, M4 | Confirmar e cancelar passam pelo motor da agenda (`AppointmentService::transitionByPatient`), com trava, `ALLOWED_TRANSITIONS`, ganchos da teleconsulta e histórico. Cancelar revoga o convite e cancela a sessão. Aceitar um pedido usa o mesmo reagendamento da agenda (`rescheduleFromPatientRequest`): a janela é recalculada e o convite antigo revogado. Consulta passada não pode ser confirmada. |
| A1 | Migração 47 cria em `booking_settings` os campos `patient_can_confirm` (1), `patient_can_cancel` (0), `patient_can_request_reschedule` (0) e `patient_show_address` (1). Eles aparecem em Configurações, em "Consulta no app da paciente", e só a gestão altera. O servidor responde `403 ACTION_NOT_ALLOWED` quando a ação está desligada. `next_appointment.allowed_actions` traz cada ação com o motivo do bloqueio. Os horários para remarcar não dependem mais do agendamento público. |
| A3 | Nova tabela `appointment_change_requests`, com os estados pendente, aceito, recusado, encerrado e expirado. A paciente vê os horários livres (`GET …/reschedule-options`), faz o pedido (`POST …/reschedule-requests`, resposta 202) e pode desistir. A consulta só muda quando a equipe clínica aceita em `/agenda/pedidos-remarcacao`, que também tem aviso na agenda e no painel "Atenção". Ao aceitar, a consulta confirmada volta para "agendada". A rota antiga `/reschedule` agora registra um pedido. A recepção vê o aviso, mas não aceita pedidos, assim como já não remarca pela agenda (`ClinicalAuthorization`). |
| A2, M6, B5, B6, B7 | Novo componente `NextAppointmentCard`, com os botões "Confirmar presença", "Pedir outro horário" (lista de horários) e "Cancelar consulta" (confirmação e motivo), conforme `allowed_actions`. As mensagens aparecem dentro do card (`role=alert`) e funcionam também no portal web. Os botões mostram carregamento e ficam desabilitados durante o envio. O bilhete da sala não aparece mais em mensagens. Os textos não têm emoji e usam caixa de frase. |
| A4 | Quando nada muda, o servidor responde `409 INVALID_STATE` com `current_status`, nunca 200 com `success:false`. O app confere `success` e recarrega a home depois de cada ação. |
| A5 | Porte do `4ff8864` no v20 (`fix/v20-auditoria-r2` @ `4a24f67`). Teste antes 6/9, depois 9/9. **O zip v21 em `release/` não foi refeito.** |
| M1 | A resposta traz `scheduled_at_iso` com offset, `timezone` e `timezone_label`. O app mostra "sex., 23/10 às 14:00 · horário de Brasília" e avisa quando o aparelho está em outro fuso. |
| M2 | A modalidade aparece sempre ("Presencial" ou "Teleconsulta (online)"). `location` vem do endereço do consultório e pode ser desligado em Configurações. |
| M3 | `meeting_url` só existe dentro da janela de entrada. Antes disso, `video_status=aguardando_horario` e `video_opens_at_iso` permitem mostrar "A sala online abre …". |
| M5 | `next_appointment` tem lista fechada de campos (schema `NextAppointment` no OpenAPI). |
| M7 | Auditoria em `audit_logs` com `resource=appointment`, `actor_type=patient`, `actor_id`, canal (`app` ou `portal_web`), status de/para e horário de/para. O motivo não é gravado, só se foi informado. O histórico grava `changed_by_patient_id` (coluna nova) no fuso da aplicação, e a agenda mostra "Paciente (app)". |
| M8 | Os testes estão na tabela abaixo. |
| B1 | Consulta ou pedido de outra paciente ou de outra clínica: `404` "Consulta não encontrada.", sem ids. |
| B2 | Só consultas agendadas ou confirmadas podem ser canceladas pela paciente. |
| B3 | Prazo mínimo de 1 hora no formulário e no servidor. A migração sobe prazos de 0 para 1. |
| B4 | OpenAPI 1.4.0 com schemas e códigos de erro. As rotas da paciente saíram de `self_service_booking_openapi.yaml`, que agora aponta para `openapi_v1.json`. |

### Testes

| Teste | Antes (3853660) | Depois |
|---|---|---|
| `tests/ni2_137_patient_next_appointment_actions_real_test.cjs` (HTTP real, SQLite) | 5 PASS, 27 FAIL (seções que não rodam no código antigo ficam como erro) | **85/85** |
| O mesmo em MySQL 8.0.46 | — | **85/85** |
| `tests/ni2_137_app_web_real_test.cjs` (build web do app, Chromium, axe) | — | **31/31** |
| SQL manual da migração 47 (instalação com chaves INT e banco com chaves BIGINT UNSIGNED, como na produção; reexecução idempotente) | — | OK |
| Schema gate MySQL 8 (fresh, upgrade e install_sql, 47 migrações) | — | fingerprints idênticos |
| Regressão: 97 suítes PHP | — | 78 PASS. As 19 falhas também falham na base, com a mesma assinatura. `v13_teleconsulta_app_link` foi ajustado ao novo serviço. |
| Regressão: NI2-89 50/50, NI2-91 34/34, NI2-135 52/52 + app 14/14 + notas 9/9, NI2-433 65, NI2-412 124 (1 BLOCKED, já existia), cliente do app 34/34, `self_service_booking` 50/50 | — | OK |
| `ni2_143_authorization_http_test` | Não roda (fixture quebra em `consent_terms.id`) | Não roda (mesma causa). Esperado atualizado para 404. |

Evidências em `docs/evidencias/ni2-137/`.

### Deploy (HostGator)

1. Fazer backup do banco.
2. No phpMyAdmin, rodar `database/manual/preflight-2026-10-21-consulta-app-ni2-137.sql`. O resultado deve ser "PRONTO PARA MIGRAR".
3. Rodar `database/manual/migracao-2026-10-21-consulta-app-ni2-137.sql`. O resultado deve ser "OK — migração 47 aplicada".
4. Subir os arquivos pelo cPanel.
5. Publicar o build web do app.
6. Em Configurações, cada clínica escolhe o que a paciente pode fazer.

Se o código subir antes do SQL, o app continua funcionando com o padrão (confirmar ligado, cancelar e remarcar desligados) e a agenda continua gravando o histórico. Isso foi conferido num banco sem a migração 47. Enquanto o SQL não for aplicado, a escolha feita em Configurações não é salva e não há pedidos de remarcação.

### Limites

- Os pedidos de remarcação não enviam e-mail nem push. O aviso aparece na agenda e no painel "Atenção", e a paciente vê a resposta no card.
- Ao aceitar uma teleconsulta remarcada, o convite antigo é revogado e a equipe precisa enviar um novo. A mensagem de confirmação lembra disso.
- O v20 recebeu só o A5. O restante desta correção existe apenas em `~/Nutri_II`.

## Onde está o código

Revisei as duas cópias do Mac, que seguiram caminhos diferentes a partir de `496f4ff`:

| Cópia | Branch / HEAD | O que tem da NI2-137 |
|---|---|---|
| `~/Nutri_II` (seu trabalho) | `merge/v11-teleconsulta` @ `4ff8864`, 26 commits à frente de `496f4ff`, mais 12 alterações sem commit (nenhuma nos arquivos desta história, exceto `PatientApiController.php` e `routes/api.php`, que não são as rotas usadas em produção) | Ver a próxima consulta, confirmar, e **cancelar/remarcar pela API** (`BookingController`, veio com a NI2-433). O app não tem botão para cancelar nem remarcar. |
| `~/Nutri_II-v20` (v20/v21) | `fix/v20-auditoria-r2` @ `12bdb8e` | Só ver e confirmar. **Não há** `BookingService`, nem rotas de cancelar ou remarcar. A observação interna da consulta (`notes`) **ainda vai para a paciente** (a correção NI2-135 só existe em `~/Nutri_II`). |

Peças da funcionalidade (linhas de `~/Nutri_II`):
- Card da próxima consulta: `src/Domain/PatientPortal/PatientPortalService.php:391-448` (`getNextAppointment`), exposto em `GET /api/v1/patient/home` (`public/index.php:870-893`).
- Confirmar: `PatientPortalService.php:450-489`, rota `POST /api/v1/patient/appointment/confirm` (`public/index.php:926-937`).
- Cancelar e remarcar: `public/index.php:323-324` → `src/Application/Controllers/BookingController.php:224-306` → `src/Domain/Booking/BookingService.php:800-976`.
- Motor da NI2-10 (agenda): `src/Domain/Appointment/AppointmentService.php` (estados em `:21-59`, `updateStatus` em `:262-333`, ganchos de teleconsulta em `:245-253` e `:313-316`).
- App/portal web (mesmo código Expo/react-native-web): `mobile/src/screens/PatientHomeScreen.tsx:273-287` e `:438-487`, `mobile/src/services/api.ts:177-188`, `:413-418`.
- Contrato: `docs/api/openapi_v1.json:351-560` e `docs/contracts/self_service_booking_openapi.yaml:265-350`.
- `routes/api.php` e `bootstrap/app.php` são da réplica Laravel e não entram no roteamento de produção, que fica todo em `public/index.php`.

## Veredito

**O status "Feito" não se justifica.** A paciente vê a consulta e consegue confirmar presença, mas três pontos centrais do card falham:
1. A paciente não tem como solicitar remarcação ou cancelar pelo app ou portal. A API existe só em `~/Nutri_II` e não está no v20/v21.
2. A regra "só quando o nutricionista permitir" não é garantida no servidor: o cancelamento funciona mesmo com o autoagendamento desligado.
3. Cancelar e remarcar não usam o motor da NI2-10. Por isso a teleconsulta fica com o convite válido depois de cancelada, e fica com o horário antigo depois de remarcada.

Faltam ainda fuso horário, modalidade e local no card, mensagens de sucesso e conflito confiáveis (no portal web elas nem aparecem), e testes que cubram o fluxo. Sugiro reabrir o card ou criar uma subtarefa de correção com os itens C1 a M8.

## Critérios de aceite

| Critério | `~/Nutri_II` | v20/v21 |
|---|---|---|
| Card com data e horário | Parcial: o texto cru do banco (`2026-10-28 14:00:00`) aparece sem formatação | Parcial (igual) |
| Fuso horário no card | Não atende | Não atende |
| Modalidade | Parcial: só aparece quando é online (botão ou aviso); consulta presencial não diz que é presencial | Parcial |
| Local / link quando habilitado | Parcial: não existe campo de local; o link de teleconsulta aparece dias antes da consulta | Parcial |
| Confirmar só quando permitido | Parcial: aceita só `scheduled`, mas confirma consulta passada e não há opção para o profissional desligar a confirmação | Parcial |
| Solicitar remarcação só quando permitido | Não atende: a API move a consulta direto, sem pedido; não há tela; não há permissão própria (depende do autoagendamento) | Não atende (não existe) |
| Cancelar só quando permitido | Não atende: a API não tem tela e ignora o autoagendamento desligado | Não atende (não existe) |
| Mensagens de sucesso | Parcial: o app mostra "confirmada com sucesso" mesmo quando o servidor recusa; no portal web nenhuma mensagem aparece | Parcial |
| Mensagens de conflito | Parcial: a API devolve 409/422 em pt-BR, mas o app não tem fluxo que as mostre | Não atende |
| Estados reais da NI2-10, sem segundo motor | Não atende: confirmar, cancelar e remarcar fazem UPDATE direto, fora do `AppointmentService` | Não atende (confirmar fora do motor) |
| Isolamento de clínica e paciente (IDOR) | Atende (testado) | Atende |
| CSRF nas ações | Atende | Atende |
| Auditoria | Parcial: o registro não diz quem fez nem o antes e depois; confirmar não grava no `audit_logs` | Parcial |
| Estados vazio, erro e carregando | Parcial: há vazio e carregando gerais; falta estado de carregamento no botão e erro do card | Parcial |
| Paridade web/mobile | Parcial: as mensagens com `Alert.alert` não aparecem na web | Parcial |
| API documentada | Parcial: rotas listadas, mas sem schema de resposta | Parcial |
| Testado (DoD) | Não atende: o único teste da NI2-137 nem roda; os outros só cobrem o caminho feliz | Não atende |

## Achados

### Crítico

**C1. Cancelar ou remarcar não atualiza a teleconsulta: o convite continua valendo depois do cancelamento, e a sala fica no horário antigo depois da remarcação.**
- Evidência: `BookingService.php:827-836` (cancelar) e `:926-955` (remarcar) fazem `UPDATE appointments` direto. O motor da agenda chama `TeleconsultationService::onAppointmentCancelled` e `onAppointmentRescheduled` (`AppointmentService.php:245-253` e `:313-316`); o `BookingService` não chama nenhum dos dois.
- Reproduzido no box (SQLite sintético, `/workspace/cr137/probe/probe-orig.log`):
  - Depois do cancelamento pela paciente, a sessão continuou `status=scheduled`, com `invite_revoked_at_utc=null`, e `issuePortalTicket` ainda emitiu bilhete. O convite enviado por e-mail segue valendo para uma consulta cancelada, e a sala do profissional também continua ativa.
  - Depois da remarcação de 27/10 10:00 para 28/10 14:00, a janela continuou `join_opens_at_utc=2026-10-27 12:45` e `invite_generation=1`. No novo horário a paciente não consegue entrar, porque a janela já fechou; e o convite antigo continua aceito.
- Correção: levar cancelar e remarcar da paciente para o `AppointmentService`, com métodos `cancelByPatient` e `rescheduleByPatient` que usem `lockAppointment`, `ALLOWED_TRANSITIONS`, os ganchos de teleconsulta e `recordStatusHistory`. O `BookingService` fica só com a política (janelas e permissões) e a checagem de horário livre.

### Alto

**A1. "Só quando o nutricionista permitir" não é garantido no servidor.**
- Evidência:
  - `cancelBookingByPatient` (`BookingService.php:800-860`) só confere status e antecedência. Não olha `is_enabled`, e sem configuração da clínica usa o padrão de 4 horas (`:73-82`). Na sonda (P2), com o autoagendamento desligado, o cancelamento passou: `{"success":true,"status":"cancelled"}`.
  - A remarcação só é barrada de forma indireta: com o autoagendamento desligado, `getAvailableSlots` (`:182-184`) devolve lista vazia e a resposta é um enganoso `409 "O novo horário não está disponível"`.
  - Confirmar não tem nenhum controle (`PatientPortalService.php:450-489`).
  - Em Configurações (`views/settings/profile.php:81`) só existem os campos "Cancelar/Remarcar pelo app até (horas antes)", de 0 a 168. Não há como desligar cada ação.
- Correção:
  - Criar em `booking_settings` (ou por profissional) as opções `patient_can_confirm`, `patient_can_cancel` e `patient_can_request_reschedule`, desligadas por padrão.
  - Validar cada opção no serviço e responder `403 ACTION_NOT_ALLOWED`.
  - Devolver em `next_appointment` um bloco `allowed_actions` com `confirm`, `cancel` e `reschedule`, cada um com o motivo quando estiver bloqueado, para o app mostrar ou esconder o botão com base no servidor.

**A2. A paciente não tem tela para cancelar nem solicitar remarcação.**
- Evidência: `mobile/src/services/api.ts` só tem `confirmAppointment` (`:413-418`), e `PatientHomeScreen.tsx:438-487` só mostra "Confirmar Presença". Com isso, as rotas `appointments/{id}/cancel` e `reschedule` não têm uso pelo app. No v20/v21 elas nem existem.
- Correção: no card, colocar os botões "Cancelar consulta" (com confirmação e motivo) e "Pedir remarcação" (lista de horários livres vindos de um endpoint autenticado da paciente), ambos seguindo `allowed_actions`.

**A3. A remarcação move a consulta direto, em vez de ser uma solicitação, e mantém o status "confirmada".**
- Evidência:
  - `BookingService.php:926-937` altera `scheduled_at` na hora.
  - `:949` grava no histórico `from_status = to_status` (na sonda P4: `confirmed→confirmed`). A consulta continua "confirmada" num horário que a paciente nunca confirmou.
  - O profissional não recebe notificação (não há nenhuma chamada de notificação em `BookingService`).
- Correção: decidir com o produto entre duas opções.
  - (a) Solicitação: criar a tabela `appointment_change_requests` com estados pendente, aceita e recusada, e o profissional aprova pela agenda.
  - (b) Remarcação direta: volta o status para `scheduled`, dispara `onAppointmentRescheduled` e avisa o profissional.
  - O card fala em "solicitar remarcação", o que aponta para a opção (a).

**A4. O app diz que confirmou mesmo quando o servidor recusa.**
- Evidência:
  - Quando não consegue confirmar, o servidor responde `200 {"success": false}` (`public/index.php:935-936`).
  - `request()` só considera erro quando a resposta não é 2xx (`api.ts:319-324`).
  - `handleConfirmAppointment` (`PatientHomeScreen.tsx:274-287`) ignora `success`, avisa "confirmada com sucesso" e marca `status: 'confirmed'` na tela.
  - Exemplo: a clínica cancelou e a paciente, com o app aberto, toca em confirmar. Ela vê "confirmado".
- Correção: no servidor, responder `409 {code: 'INVALID_STATE', status_atual}` quando nada mudar. No app, checar `success` e recarregar a home depois da ação.

**A5 (só v20/v21). A observação interna da consulta vai para a paciente.**
- Evidência: em `~/Nutri_II-v20`, `PatientPortalService.php:395` faz `SELECT a.*` e devolve tudo sem o `unset($row['notes'])`. Na sonda do v20, `notes=NOTA INTERNA TESTE` saiu em `next_appointment`.
- A correção existe em `~/Nutri_II` (`4ff8864`, NI2-135), mas não está no zip v21.
- Correção: levar `4ff8864` para o v20 antes de qualquer deploy do v21. O melhor é trocar `a.*` por uma lista fechada de campos (ver M5).

### Médio

**M1. Fuso horário e formato da data.**
- O card mostra `nextApp.scheduled_at` cru (`PatientHomeScreen.tsx:453`), sem fuso, e a API devolve hora local sem offset.
- O `displayDateTime` que já existe (`mobile/src/utils/presentation.ts`) supõe que a hora está em UTC. Usá-lo aqui atrasaria a consulta em 3 horas.
- Correção: a API devolve `scheduled_at_iso` com offset (`2026-10-28T14:00:00-03:00`) e `timezone: "America/Sao_Paulo"`. O app formata como "qua., 28/10 às 14:00 (horário de Brasília)" e avisa quando o fuso do aparelho for diferente.

**M2. Modalidade e local.**
- Consulta presencial não aparece como "Presencial", e não existe campo de endereço ou sala: a tabela `appointments` (migration 004) não tem `location`.
- Correção: mostrar a modalidade sempre e incluir `location` (endereço do consultório, vindo da clínica ou do profissional) com uma opção de exibição.

**M3. O link da teleconsulta aparece dias antes.**
- `getNextAppointment` gera bilhete sempre que existe sessão (`PatientPortalService.php:431-437`), e `issuePortalTicket` (`TeleconsultationService.php:287-309`) não olha `join_opens_at_utc`. Na sonda P3, `meeting_url` já estava presente 4 dias antes da consulta.
- A mídia só abre dentro da janela (`:560`), então não há vazamento de vídeo. Mas a sala de espera abre cedo, como a auditoria já tinha apontado, e cada carga da home gera um bilhete novo.
- Correção: só gerar `meeting_url` quando `now >= join_opens_at_utc - X` e devolver `video_opens_at` para o app mostrar "a sala abre às 13:45".

**M4. Confirmar está fora da máquina de estados e não tem janela de tempo.**
- `confirmAppointment` faz UPDATE próprio (`PatientPortalService.php:457-466`), sem `lockAppointment` nem `ALLOWED_TRANSITIONS`. Ele aceita consulta passada (na sonda P1, uma consulta de 2 dias atrás foi confirmada) e, se o histórico falhar, ignora o erro em silêncio (`:482-484`).
- Correção: usar `AppointmentService::updateStatus($id, 'confirmed', ..., actor paciente)` e recusar quando `scheduled_at < agora`.

**M5. A resposta devolve a linha inteira do banco.**
- `SELECT a.*` (`PatientPortalService.php:397`) entrega todos os campos de `appointments` à paciente, incluindo `created_at`, `updated_at` e `professional_id`. Hoje não há nada sensível além de `notes`, mas qualquer coluna nova vai vazar.
- Correção: lista fechada de campos (`id, scheduled_at_iso, duration_minutes, status, modality, professional_name, location, meeting_url, video_opens_at, allowed_actions`).

**M6. Mensagens não aparecem no portal web.**
- As mensagens de confirmação usam `Alert.alert` (`PatientHomeScreen.tsx:278` e `:284`), que no `react-native-web` (`mobile/package.json`: `~0.19.10`) não mostra nada. No navegador a paciente não recebe nem o sucesso nem o erro.
- Correção: usar mensagem dentro da tela (banner com `accessibilityRole="alert"`), como no fluxo de privacidade.

**M7. Auditoria incompleta.**
- `BookingService::audit` grava `user_id=NULL`, `details=NULL` (`:1015-1029`), sem o `patient_id` de quem fez, sem o horário antigo e o novo, e com `resource='booking'` em vez de `appointment`.
- O histórico de status também sai sem `changed_by` e com `created_at` em UTC (`CURRENT_TIMESTAMP`, `BookingService.php:843` e `PatientPortalService.php:475`), enquanto o `AppointmentService` grava no fuso da aplicação (UX-07). Na sonda, 22:41 de Brasília virou `01:41`.
- Confirmar não grava nada em `audit_logs`.
- Correção: registrar `actor_type=patient`, `actor_id`, `from`, `to`, `from_scheduled_at`, `to_scheduled_at` e o canal, e usar `recordStatusHistory`.

**M8. Testes não cobrem a história.**
- `tests/patient_portal_e2e_suite_test.php:175-192`, o bloco "NI2-137", nem chega a rodar: quebra antes, em `:56`, com `no such table: patient_invitations`. Mesmo que rodasse, só testa o caminho feliz pelo serviço.
- `tests/patient_mobile_http_contract_test.php:253-262` testa uma **cópia** da rota montada dentro do próprio teste, não o `public/index.php`. No v20 ele falha porque a consulta de exemplo está fixa em `2026-10-20 10:00`, que já passou.
- `tests/self_service_booking_test.php:383-410` cobre cancelar e remarcar, mas não cobre a teleconsulta, o autoagendamento desligado nem o status depois da remarcação.
- Nenhum teste cobre: confirmar consulta passada, `success:false`, ação desligada, link fora da janela, ou web contra app.
- Correção: um `tests/ni2_137_patient_next_appointment_actions_test.php` pelas rotas reais, cobrindo cada linha da tabela acima, com datas relativas a agora.

### Baixo

- **B1.** A mensagem de 403 diz o id da outra paciente: "o paciente ID 2 não pertence…" (`TenantGuard.php:31`, devolvida em `BookingController.php:250` e `:293`). Melhor responder "Consulta não encontrada" (404), sem ids.
- **B2.** O status `in_progress` pode ser cancelado pela paciente (`BookingService.php:813` só bloqueia `completed/cancelled/no_show`; sonda P7). Na prática a antecedência mínima evita isso, mas o certo é aceitar só `scheduled` e `confirmed`.
- **B3.** A antecedência mínima aceita 0 horas (`views/settings/profile.php:81`), o que permite cancelar no minuto da consulta. Vale um mínimo de 1 hora ou um aviso.
- **B4.** O OpenAPI das rotas de cancelar e remarcar devolve `200` com `schema: {type: object}` (`openapi_v1.json:428-436`), e `next_appointment` é só `object|null` (`:1601-1606`), sem campos, sem fuso e sem códigos de erro (`SLOT_CONFLICT`, `POLICY_VIOLATION`, `INVALID_STATE`). Os nomes das operações também diferem dos do `self_service_booking_openapi.yaml`: `patientCancelAppointment` contra `cancelPatientAppointment`.
- **B5.** O botão "Confirmar Presença" não fica desabilitado nem mostra carregamento durante o envio (`PatientHomeScreen.tsx:470-479`).
- **B6.** Quando o link não abre, o `Alert` mostra o bilhete de teleconsulta inteiro (`PatientHomeScreen.tsx:298`).
- **B7.** O card ainda tem título com emoji e Caixa De Título ("🗓️ Próximo Atendimento", "Confirmar Presença"), fora do tom da curadoria v21.

## O que está bom

- **Isolamento de clínica e paciente:** consulta de outra clínica ou de outra paciente da mesma clínica é recusada com 403 (`TenantGuard.php:22-81`, `BookingService.php:808-811`). Na sonda P6, confirmar, cancelar e remarcar a consulta da paciente B com a sessão da paciente A foram todos barrados, e a suíte cross-tenant passou.
- **CSRF:** pelo app, o acesso é por Bearer. Pela web, é um cookie `HttpOnly`, `SameSite=Strict` e `path=/api/v1`, e as ações exigem `Origin` permitido mais o cabeçalho `X-ClyoNutri-Web: 1` (`MobileWebSession.php:24-36`). Não encontrei como forjar uma ação.
- **Concorrência na remarcação:** confere a disponibilidade real, trava o profissional no MySQL, checa conflito com consultas e reservas, e o UPDATE só passa se o status não tiver mudado (`BookingService.php:908-939`). Na sonda P8, remarcar para um horário ocupado deu 409. Cancelar e confirmar também usam UPDATE condicional.
- **Janelas:** as antecedências de cancelar e remarcar são validadas no servidor (`:818-825` e `:885-892`).
- **Estados de tela:** o card tem estado vazio ("Nenhum atendimento agendado"), e a home tem carregamento e erro com "Tentar novamente".

## Testes executados (box, PHP 8.4, SQLite sintético isolado por suíte)

| Suíte | `~/Nutri_II` | v20 |
|---|---|---|
| `patient_portal_e2e_suite_test` (contém o bloco NI2-137) | **Não roda**: falta a tabela `patient_invitations` | **Não roda** (mesma causa) |
| `patient_mobile_http_contract_test` | 28/28 | 27/28: a próxima consulta #9551 está com data fixa que já passou |
| `patient_mobile_api_integration_test` | 22/22 | 22/22 |
| `cross_tenant_end_to_end_security_test` | 27 asserções, passou | Passou |
| `self_service_booking_test` | 48/48 | Não existe |
| `agenda_operational_and_concurrency_test` | 32/32 | 32/32 |
| `delivery_e2e_and_contracts_test` | Passou | Quebra (`i.measure_unit`, sem relação com a NI2-137) |
| Sonda de comportamento (`/workspace/cr137/probe/`) | Confirmou C1, A1, A3, M3, M4, M7 e B2 | Confirmou A5 e M4 |

Não rodei os testes HTTP reais em Node (`ni2_433_self_service_booking_real_test.cjs`, `ni2_89_patient_api_real_test.cjs`), porque eles precisam de servidor e MySQL. Os testes passarem não significa que a história está coberta: nenhuma suíte testa os casos dos achados C1, A1, A3 e A4.

## Ordem sugerida de correção

1. **A5:** levar a NI2-135 para o v20 antes de publicar o v21.
2. **C1, M4 e A3:** confirmar, cancelar e remarcar passam pelo `AppointmentService`, com os ganchos de teleconsulta e o histórico.
3. **A1:** permissões por ação, validadas no servidor, mais `allowed_actions` na resposta.
4. **A2, A4, M1, M2 e M6:** card completo (data com fuso, modalidade, local, botões conforme a permissão, mensagens dentro da tela).
5. **M3 e M5:** link só perto da janela de entrada e resposta com lista fechada de campos.
6. **M7, M8 e B4:** auditoria completa, teste `ni2_137_*` pelas rotas reais e OpenAPI com schemas.
