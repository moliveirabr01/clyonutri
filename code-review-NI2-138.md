# Code review: NI2-138, canal seguro de comunicação com o nutricionista

Data: 21/10/2026 (horário de Brasília)
Card: [NI2-138](https://caplybr.atlassian.net/browse/NI2-138), História, status **Feito** desde 08/10/2026 00:18 (no mesmo minuto da NI2-137). Não tem comentários, evidências anexadas nem commits que citem NI2-138. O código do chat veio pela NI2-189 (migration 019).
A revisão foi somente leitura. Nada foi alterado no Mac, no Jira nem em produção. Os testes e as sondas rodaram no box, com banco SQLite sintético e dados marcados como TESTE.

## Onde está o código

| Cópia | HEAD | Diferença no chat |
|---|---|---|
| `~/Nutri_II` | `3853660` (`merge/v11-teleconsulta`), com 12 alterações sem commit que não tocam no chat | É a referência das linhas abaixo. O OpenAPI documenta 4 rotas de chat da paciente. |
| `~/Nutri_II-v20` (v21) | `12bdb8e` (`fix/v20-auditoria-r2`) | Rotas, serviços e JavaScript **iguais** (conferido com diff). Só mudam os textos da curadoria v21. O `docs/api/openapi_v1.json` **não tem nenhuma rota de chat**. |

Peças:
- **Serviços:** `src/Domain/Chat/ChatService.php` e `src/Domain/Chat/ChatAttachmentService.php`.
- **API da paciente:** `public/index.php:1019-1199`
  - `GET /api/v1/patient/chat/messages`
  - `POST .../send`
  - `POST .../read`
  - `GET .../attachment/{id}`
- **API do profissional no app:** `public/index.php:1665-1742`.
- **Web do profissional:** `src/Application/Controllers/ChatController.php` (rotas em `public/index.php:471-477`, protegidas por `$requireChat`).
- **App/portal da paciente** (Expo, o mesmo código na web e no celular):
  - `mobile/src/screens/PatientHomeScreen.tsx:112-190` e `:800-930`
  - `mobile/src/services/api.ts:83-125` e `:531-575`
- **Tabelas:**
  - `database/migrations/2026_10_07_000006_*` (conversas e mensagens)
  - `2026_10_08_000019_*` (idempotência, anexos, registro de notificações, preferências)

## Veredito

**O status "Feito" não se justifica.** O básico funciona: a paciente manda e recebe mensagens, e o isolamento entre clínicas e entre pacientes está correto. Mas faltam ou falham regras centrais do card:
- O chat não é amarrado a um vínculo ativo.
- O bloqueio por vínculo inativo não existe.
- A paciente não envia anexos e não vê os anexos que recebe.
- Não há política de retenção.
- Não há tempo de resposta configurado.
- A notificação não usa o domínio da NI2-12 e não chega como push.
- A retentativa do app duplica mensagens.

Do lado do profissional, há injeção de HTML das mensagens da paciente na tela web, e o link de anexo da tela web está quebrado.

## Critérios de aceite (as duas cópias, salvo indicação)

| Critério | Situação | Observação |
|---|---|---|
| Mensagens assíncronas | Atende | Polling com cursor `after_id` e status de entrega |
| Associadas ao vínculo ativo | **Não atende** | Sem consulta nem plano, a conversa é aberta com o 1º profissional da clínica (A1) |
| Anexos opcionais protegidos | **Parcial** | Armazenamento e download bem protegidos, mas a paciente não envia nem vê anexos, e o link do profissional está quebrado (A3) |
| Status de envio e entrega | Parcial | Existem sent, delivered e read, mas o app mostra o mesmo ✓ para enviada e entregue, e não há estado "enviando" nem "falhou" |
| Políticas de retenção | **Não atende** | Não há rotina, configuração nem expurgo de arquivos (A5) |
| Bloqueio com vínculo inativo | **Não atende** | Profissional removida da clínica e conversa arquivada: a paciente continua enviando (A2) |
| Tempo de resposta configurado, sem SLA implícito | **Não atende** | Não existe a configuração; a interface mostra "Online" e "em tempo real" (M4) |
| Não apresentar como canal de emergência | Parcial | Só um selo pequeno, "Não substitui urgência"; o texto convida a "relatar sintomas… em tempo real" |
| Notificar pelo domínio NI2-12, sem conteúdo sensível no push | **Não atende** / Atende | Só grava `chat_notifications_log` com `channel='in_app'` e `status='sent'`; não há push nem e-mail. O texto é genérico, sem dado clínico |
| Isolamento entre clínicas e pacientes (IDOR) | Atende | Testado (H6 e suítes ofensivas) |
| CSRF | Atende | Na web o token é exigido; o app usa Bearer ou cookie com Origin e `X-ClyoNutri-Web` (ver NI2-137) |
| XSS | **Não atende** (web do profissional) | `innerHTML` com a mensagem crua; a CSP em modo `enforce` evita a execução de script, mas não a injeção de HTML (A4) |
| Envio duplicado | **Não atende** | O app gera uma chave nova a cada tentativa (A6) |
| Perda de conexão | Parcial | Há banner e "Reconectar" ao receber; ao enviar, só `Alert`, que não aparece na web |
| Limite de taxa e de tamanho | Não atende | 40 envios seguidos e uma mensagem de 300 KB foram aceitos (M2) |
| Auditoria | Não atende | Nada registrado para mensagens, leitura ou download de anexo (M6) |
| Erro, vazio e carregando | Parcial | Os três estados existem no app; falham o erro de envio na web e as mensagens de erro técnicas da API |
| Paridade web e celular | Parcial | `Alert.alert` não aparece na web; o profissional na web e no app têm recursos diferentes (o app não tem anexo) |
| API documentada | Parcial / Não atende (v20) | 4 rotas da paciente no OpenAPI de `~/Nutri_II`; as do profissional não estão documentadas; o v20 não tem nenhuma |
| Testado (DoD) | Parcial | As suítes de chat passam, mas não cobrem os pontos acima; o bloco NI2-138 da suíte do portal não roda |

## Achados

### Alto

**A1. A conversa não depende de vínculo e pode ir para o profissional errado.**
- `$getOrCreatePatientConversation` (`public/index.php:1020-1056`) procura, em ordem:
  1. o profissional da última consulta,
  2. o do último plano,
  3. **o primeiro profissional ativo da clínica** (`:1038-1046`, `ORDER BY p.id ASC`).
- A conversa é criada só porque a paciente abriu a aba Chat, sem nenhuma escolha dela.
- Na sonda H1, a paciente B, sem consulta nem plano, foi colocada numa conversa com o "Nutricionista Demo Local", o dono da clínica.
- Pelo lado web, qualquer profissional da clínica abre conversa com qualquer paciente ativo da clínica (`ChatController.php:96-139` e `ChatService::getOrCreateConversation`, `:29-62`), sem checar se a paciente é dele.
- Correção:
  - Criar a tabela de vínculo paciente-profissional (ou usar `patients.responsible_professional_id`, se existir na NI2-5) com status.
  - Abrir a conversa só com vínculo ativo.
  - Sem vínculo, devolver `conversation_id: null` e uma mensagem clara ("Seu consultório ainda não liberou o chat").

**A2. Vínculo inativo não bloqueia o envio.**
- `sendMessage` (`ChatService.php:96-110`) só confere a clínica e se quem envia participa da conversa. Não olha:
  - `chat_conversations.status` (o valor `archived` existe no schema, mas nunca é usado);
  - o status do profissional na clínica (`clinic_members.status`);
  - o vínculo.
- Na sonda H7, com a profissional 2 inativada na clínica e a conversa `archived`, a paciente enviou e recebeu `{"success":true}`. A mensagem vai para quem não tem mais acesso.
- Só existe bloqueio para paciente inativa, e ele é indireto: vem do token (`public/index.php:519`).
- Correção: em `sendMessage` e em `getMessagesWithCursor`, exigir conversa `active`, profissional ativo na clínica e vínculo ativo. Responder `403 LINK_INACTIVE`, e o app mostra a conversa só para leitura.

**A3. Anexos: a paciente não envia nem vê, e o link do profissional na web está quebrado.**
- A rota de envio da paciente passa `null` como anexo (`public/index.php:1121-1128`). Não existe rota de upload da paciente, e o app não mostra `attachment_*` (`PatientHomeScreen.tsx:850-893`).
- A rota `GET /api/v1/patient/chat/attachment/{id}` existe, mas nada no app a chama.
- Na web do profissional, o link usa o **id da mensagem** (`ChatController.php:644`, e o mesmo nos trechos de JavaScript `:736` e `:788`), mas o download procura pelo **id do anexo** (`ChatAttachmentService.php:143-150`).
  - Na sonda: mensagem 47 com anexo 1. `/chat/anexo/47` dá "Anexo não encontrado (403)".
  - Quando os ids coincidem com outro anexo de uma conversa do mesmo profissional, abre o arquivo errado.
- Correção:
  - Devolver `attachment_id` (de `chat_attachments`) na mensagem e usá-lo no link.
  - Criar `POST /api/v1/patient/chat/send` multipart, ou um upload separado com id temporário, e mostrar o anexo no app.

**A4. A mensagem da paciente entra como HTML na tela do profissional.**
- No envio e no polling, o JavaScript monta `'<div>' + m.message.replace(/\n/g,'<br>') + '</div>'` e usa `bubble.innerHTML` (`ChatController.php:734-740` e `:786-794`), sem escapar o texto. `attachment_name` também entra cru. A renderização no servidor escapa corretamente (`:640`).
- Na sonda H5, a API guarda e devolve `<a href="https://exemplo.invalid/remarcar">…</a><img src=x onerror=…>` sem alteração.
- Com `CSP_MODE=enforce` (o padrão; `SecurityHeaders.php:69-74` e `:81-82` com `script-src-attr 'none'`), o `onerror` não roda. Mesmo assim:
  - entram links, botões e textos falsos dentro do sistema do profissional, que servem para phishing;
  - com `CSP_MODE=report-only` ou `off`, opções que existem no `.env`, vira XSS completo na sessão do nutricionista.
- Correção:
  - Montar o balão com `textContent` e `document.createElement`, ou escapar `& < > " '` antes de trocar `\n` por `<br>`.
  - Fazer o mesmo com `attachment_name`.
  - Criar um teste que envia `<img onerror>` e confere o DOM.

**A5. Não há política de retenção.**
- Não existe rotina, comando, configuração ou campo de expiração para `chat_messages`, `chat_attachments` e `chat_notifications_log`. A busca por retention, purge ou expurgo no chat não encontra nada.
- O `ON DELETE CASCADE` (migration 019, `:89-90` e `:105-106`) apaga as linhas, mas **deixa os arquivos** em `storage/app/chat_attachments`.
- Correção:
  - Definir com o produto e o jurídico o prazo de guarda (prontuário: 20 anos, CFN; ou outro), com configuração por clínica.
  - Criar um job no `bin/` (cron da HostGator) que expurga ou anonimiza conforme a política e apaga os arquivos.
  - Ligar o pedido de exclusão da LGPD (NI2-91) aos anexos.

**A6. A retentativa duplica mensagens.**
- O app cria uma chave de idempotência nova em toda chamada: `` `mob_${Date.now()}_…` `` (`api.ts:545`).
- Se o envio passar do tempo limite de 15 s (`api.ts:297`) depois que o servidor já gravou, a paciente toca de novo e a mensagem sai duplicada. Na sonda H3, duas tentativas da mesma mensagem viraram as mensagens 1 e 2. Com a mesma chave (H4), a deduplicação funciona.
- No servidor também há corrida: a checagem é feita fora da transação (`ChatService.php:117-137`) e o índice `idx_chat_messages_idemp` **não é único** (migration 019, `:52-53`). Dois envios simultâneos com a mesma chave podem gravar duas vezes.
- Correção:
  - No app, gerar a chave quando a mensagem é criada, guardá-la junto do rascunho e reenviar com a mesma chave.
  - No banco, `UNIQUE (conversation_id, idempotency_key)` e tratar o `23000` devolvendo a mensagem que já existe.

### Médio

**M1. Notificação fora da NI2-12 e sem push.**
- `createSecureNotificationLog` (`ChatService.php:489-519`) só grava uma linha `in_app` com `status='sent'`, sem que nada tenha sido enviado.
- Não existe serviço de notificação nem push; `chat_preferences` (horário de silêncio, e-mail e push) foi criada e nunca é lida.
- O texto não tem conteúdo clínico (bom), mas diz "Seu nutricionista" e "Seu paciente" no masculino.
- Correção: publicar um evento `chat.message_created` no domínio NI2-12, com push e e-mail respeitando `chat_preferences`, um texto neutro sem nome nem conteúdo, e `status` real (`queued`, `sent`, `failed`).

**M2. Sem limite de taxa nem de tamanho.**
- As rotas de envio (`public/index.php:1087-1136` e `:1692-1726`) não têm limitador. Na sonda H9, 40 envios seguidos deram 40×200, e uma mensagem de 300 KB foi aceita. O `maxLength={1000}` existe só no app (`PatientHomeScreen.tsx`).
- Correção: limite de 2.000 caracteres no servidor (422), limitador por paciente e por conversa (por exemplo 20 por minuto, com o `TeleconsultationRateLimiter` ou o `LoginRateLimiter`) e 429 com `Retry-After`.

**M3. O desligamento do chat pela administração não vale para a API.**
- `$requireChat` (`public/index.php:287-300`) protege só as rotas web.
- Na sonda H8, com `feature_flag_chat=0`, a paciente enviou normalmente pela API, e o profissional no app também conseguiria.
- Correção: aplicar a mesma checagem nas rotas `/api/v1/patient/chat/*` e `/api/v1/professional/chat/*`, respondendo 503 com mensagem para o app.

**M4. A interface sugere atendimento imediato e não informa o tempo de resposta.**
- Não existe configuração de "tempo de resposta". O app mostra:
  - "Conectado ao consultório" (`:813`);
  - o estado vazio "relate sintomas ou compartilhe sua evolução **em tempo real**" (`:846`).
- A web do profissional mostra "**Online**" fixo (`ChatController.php:622`) e "Canal criptografado" (a conexão é TLS, não há criptografia de ponta a ponta).
- O aviso de emergência é só o selo "Não substitui urgência" (`:818`).
- Correção:
  - Criar a configuração por profissional ou clínica ("costumo responder em até 2 dias úteis").
  - Mostrar acima do campo de texto: "Respondo em até X. Em caso de urgência, ligue 192 ou procure um pronto-atendimento".
  - Tirar "tempo real", "Online" e "criptografado".

**M5. A API devolve dados internos.**
- `getMessagesWithCursor` faz `SELECT m.*` (`ChatService.php:278-283`) e devolve à paciente o `attachment_path` com o **caminho absoluto do servidor**, `sender_id`, `clinic_id` e `idempotency_key` (H10). `sendMessage` devolve o mesmo (`:214-228`).
- Os erros devolvem `$e->getMessage()` com status errado. Por exemplo, H6 dá `400` com "o paciente ID 2 não pertence…", quando o certo seria 403 ou 404 sem ids (`public/index.php:1132-1135`, `:1688`, `:1724` e `:1740`). Uma falha de banco expõe o texto do PDO.
- Correção: lista fechada de campos (`id`, `sender_type`, `message`, `status`, `created_at`, `attachment {id, name, mime, size}`) e um mapeamento de exceções para códigos (`403 FORBIDDEN`, `404`, `409`, `422`, `500` genérico).

**M6. Não há auditoria.**
- Envio, leitura, download de anexo e abertura de conversa não registram nada em `audit_logs` (H12 só mostra `USER_REGISTER` e a tentativa cross-tenant).
- Para um canal clínico, falta saber quem leu e quem baixou o quê.
- Correção: registrar `CHAT_MESSAGE_SENT`, `CHAT_ATTACHMENT_DOWNLOADED` e `CHAT_CONVERSATION_OPENED`, só com ids, sem conteúdo.

**M7. Na web, o erro de envio da paciente não aparece.**
- `handleSendMessage` usa `Alert.alert` (`PatientHomeScreen.tsx:188`), que não faz nada no `react-native-web`. No portal web, o envio falha sem aviso. O texto continua no campo (bom).
- Também não há "falhou, tocar para reenviar".
- Correção: mostrar a mensagem como pendente na lista (`status: 'sending' | 'failed'`), com reenvio que usa a mesma chave (A6) e aviso dentro da tela.

**M8. Os testes não cobrem os riscos.**
- `chat_secure_communication_and_engagement_e2e_test` (8/8) e `patient_chat_and_online_nutritionist_e2e_test` (52 verificações) passam nas duas cópias. Eles **não testam**:
  - XSS no DOM;
  - retentativa com chave nova;
  - envio com a mesma chave em paralelo;
  - vínculo inativo ou conversa arquivada;
  - chat desligado na API;
  - tamanho e taxa;
  - o link de anexo da web;
  - anexo da paciente;
  - retenção;
  - push.
- O bloco "[8/11] NI2-138" de `patient_portal_e2e_suite_test.php` nem chega a rodar: quebra antes, por falta da tabela `patient_invitations` (já registrado na NI2-137).
- Correção: criar `tests/ni2_138_secure_channel_test.php` pelas rotas reais, cobrindo cada linha da tabela, e um teste de DOM (Node/JSDOM) para a tela do profissional.

### Baixo

- **B1.** O PDF é aceito mesmo com JavaScript (`OpenAction /JavaScript`; sonda `js.pdf`) e servido `inline` (`public/index.php:1188` e `ChatController.php:257`). Os visualizadores rodam isolados, mas vale usar `Content-Disposition: attachment` para PDF. Não há antivírus (ClamAV costuma não existir na HostGator); vale pelo menos registrar o hash (já existe `sha256_hash`) e bloquear hashes conhecidos.
- **B2.** O anexo é gravado antes da mensagem, e `storeAttachment` pode deixar arquivo órfão se a transação falhar (o arquivo não é apagado no `rollBack`, `ChatService.php:229-233`).
- **B3.** O polling do app roda a cada 3 s fixos (`PatientHomeScreen.tsx:160-167`), ignora a `polling_policy` que o servidor manda, continua em segundo plano e chama `loadChat` dentro do updater de `setChatMessages`, um efeito colateral no lugar errado.
- **B4.** Enviada e entregue aparecem com o mesmo ✓ para a paciente (`:885-889`), e na web do profissional também (`ChatController.php:649-651`).
- **B5.** A hora da mensagem é cortada da string (`created_at.substring(11,16)`). No SQLite, `CURRENT_TIMESTAMP` é UTC; no MySQL de produção o fuso é ajustado na conexão. Vale devolver ISO com offset.
- **B6.** Os textos ainda têm Caixa De Título e emoji ("🔒 Canal Clínico Protegido…", "Seu Nutricionista"), fora do tom da curadoria v21.

## O que está bom

- **Isolamento:** toda leitura e escrita passa por `TenantGuard::assertConversationBelongsToClinic` e por `assertUploaderIsParticipant` (`ChatAttachmentService.php:176-201`). A marcação de leitura confere o dono da conversa (`public/index.php:1160-1166` e `ChatService.php:362-376`). Na sonda H6, a paciente A não conseguiu enviar nem marcar como lida a conversa da paciente B, e as suítes cross-tenant (27 e 20 ataques) passaram nas duas cópias.
- **Armazenamento dos anexos:**
  - Os arquivos ficam em `storage/app/chat_attachments`. No servidor, o `.htaccess` da raiz manda tudo para `public/`, e `storage/.htaccess` tem `Require all denied`, conferido nas duas cópias e no pacote v21.
  - O tipo real do arquivo é detectado com `finfo`, só PNG, JPG e PDF são aceitos, e a extensão vem do tipo detectado.
  - O nome gravado é aleatório e o original é sanitizado.
  - O limite é de 10 MB.
  - O download só funciona com autenticação, tem `nosniff` e `Cache-Control: private`.
  - Na sonda, PHP disfarçado de `.jpg`, SVG disfarçado de `.png` e HTML disfarçado de `.pdf` foram recusados.
- **Renderização segura:** a renderização no servidor escapa o texto (`ChatController.php:640`), e o app usa `<Text>`, sem HTML.
- **CSRF** na web do profissional (`ChatController.php:159-163`).
- **Notificação sem dado clínico** (`ChatService.php:497-499`).

## Testes executados (box, PHP 8.4, SQLite sintético por suíte)

| Suíte | `~/Nutri_II` | v20 |
|---|---|---|
| `chat_secure_communication_and_engagement_e2e_test` | 8/8 | 8/8 |
| `patient_chat_and_online_nutritionist_e2e_test` | 52, todas passaram | 52, todas passaram |
| `cross_tenant_end_to_end_security_test` | 27, passou | Passou |
| `cross_tenant_offensive_test` | 20/20 | 20/20 |
| `patient_mobile_http_contract_test` | 28/28 | 27/28 (data fixa já passou, ver NI2-137) |
| `patient_portal_e2e_suite_test` (bloco NI2-138) | Não roda (falta `patient_invitations`) | Não roda |
| Sonda HTTP real (`php -S` + `curl`, `/workspace/cr138/probe/http-orig.log`) | Confirmou A1, A2, A6, M2, M3, M5 e M6 | Mesmo código (diff idêntico) |
| Sonda de anexos (`/workspace/cr138/probe/att.php`) | Confirmou A3 e B1 e as recusas de arquivo disfarçado | Mesmo código |

## Ordem sugerida de correção

1. **A4:** escapar a mensagem no JavaScript do profissional. É pequeno e deveria entrar no próximo pacote.
2. **A6:** chave de idempotência estável no app e índice único no banco.
3. **A1 e A2:** vínculo ativo obrigatório para abrir, ler e enviar; respeitar `archived` e o profissional inativo.
4. **A3:** `attachment_id` no link; upload e visualização de anexo no app da paciente.
5. **M3, M2 e M5:** chave de desligar na API, limites de taxa e tamanho, resposta com lista fechada de campos e erros padronizados.
6. **M4 e M7:** tempo de resposta configurável, aviso de urgência, sem "Online" nem "tempo real", erro de envio visível na web.
7. **A5, M1 e M6:** política de retenção com job, notificação pela NI2-12 (push e e-mail) e auditoria.
8. **M8 e documentação:** teste `ni2_138_*` pelas rotas reais e o OpenAPI das rotas do profissional (e de todas no v20).


---

## Status das correções (2026-10-22)

Decisões do produto usadas nesta rodada:
- **Vínculo:** tabela própria `patient_professional_links` (ativo/encerrado, com histórico). A migração cria o vínculo para quem já tem consulta não cancelada, plano ou resposta da profissional no chat. A equipe libera e encerra no perfil da paciente.
- **Guarda:** 20 anos por padrão, de 5 a 50 configurável pela clínica. Rotina diária apaga o que venceu. O pedido de exclusão LGPD anonimiza a paciente, encerra os vínculos e arquiva as conversas; as mensagens ficam pela guarda legal.
- **Notificação:** no app e por e-mail. **Push é BLOCKED** (não há provedor configurado); o canal fica registrado como `blocked`.

Código em `~/Nutri_II` (branch `merge/v11-teleconsulta`). A4 também no v20 (`~/Nutri_II-v20`, commit `0c79ced`).

### Achados

| Achado | Status | O que mudou | Prova |
|---|---|---|---|
| A1 Conversa sem vínculo | Corrigido | Sem vínculo ativo não há conversa (`NO_ACTIVE_LINK`, 403), em qualquer lado. A paciente vê "Seu consultório ainda não liberou o chat". Na web, a busca de "Nova conversa" marca quem não tem vínculo. A liberação é feita no perfil da paciente: a nutricionista libera só o próprio vínculo, a dona e o admin liberam qualquer um, e a recepção não libera. Tudo é auditado. O agendamento e a confirmação de reserva criam o vínculo. | HTTP 8 verificações [A1]; app-web "Sem vínculo" |
| A2 Vínculo inativo não bloqueia | Corrigido | `ChatAccessPolicy` exige quatro coisas: conversa ativa, paciente ativa, profissional ativa na clínica e vínculo ativo. Sem isso, o envio dá `403 LINK_INACTIVE` com motivo e o histórico fica só para leitura, para os dois lados. | HTTP [A2] ×6; app-web "Vínculo encerrado" e "Conversa antiga sem vínculo" |
| A3 Anexos | Corrigido | Envio multipart da paciente (PNG, JPG e PDF de até 10 MB, conferidos pelo conteúdo). O app anexa pelo seletor de documentos e abre por um link assinado de 2 minutos. A web usa o id do anexo. | HTTP [A3] ×14; app-web "Paciente anexa foto" e "Abrir anexo" |
| A4 HTML na tela do profissional | Corrigido (Nutri_II e v20) | Balão montado com `createElement` e `textContent`. No v20, `esc()` nas duas montagens. | Nutri_II: HTTP [A4] ×3 e app-web com CSP off. v20: `ni2_138_chat_xss_dom_test.cjs`, antes 0/2 (`window.__xss=1`), depois 2/2 |
| A5 Retenção | Corrigido | `ChatRetentionService` e `bin/chat_retention.php` (com `--dry-run`). O cron roda 1 vez por dia e apaga mensagens, anexos (linha e arquivo), notificações e conversas vazias que passaram do prazo, além de arquivos órfãos com mais de 24 h. O expurgo é auditado. O prazo é configurado em Configurações > Mensagens. | HTTP [A5] ×5 |
| A6 Retentativa duplica | Corrigido | A chave é gerada uma vez por mensagem no app e reaproveitada em "Tentar de novo". `UNIQUE (conversation_id, idempotency_key)`; o erro 23000 devolve a mensagem que já existe. | HTTP [A6] ×4 (8 envios paralelos → 1 linha); app-web "Tentar de novo usa a mesma chave" |
| M1 Notificação fora da NI2-12 | Parcial | `ChatNotificationDispatcher` em `Domain/Notification` grava uma linha por canal com status real e motivo. Os canais: no app `sent`; e-mail `sent`/`logged`/`failed`/`skipped` (respeita preferência, horário de silêncio e no máximo 1 e-mail a cada 30 min por conversa); push `blocked`. O texto é neutro, sem nome nem conteúdo. | HTTP [M1] ×5 |
| M2 Taxa e tamanho | Corrigido | Até 2.000 caracteres (422) e 20 envios por minuto por remetente e conversa (429 com `retry_after`). | HTTP [M2] ×3 |
| M3 Chat desligado na API | Corrigido | 503 `CHAT_DISABLED` nas rotas da paciente e do profissional. | HTTP [M3] |
| M4 Tempo de resposta e "Online" | Corrigido | Configuração por clínica do tempo de resposta. O texto diz "costuma responder…", sem prazo garantido, com aviso de urgência 192. Saíram "Online", "tempo real" e "criptografado". | HTTP [M4] ×5; app-web aviso |
| M5 Dados internos e erros | Corrigido | Lista fechada de campos e erros com código (`CONVERSATION_NOT_FOUND`, `VALIDATION_ERROR`, `MESSAGE_TOO_LONG`, etc.), sem ids nem texto de PDO. | HTTP [M5] ×3 |
| M6 Auditoria | Corrigido | `CHAT_CONVERSATION_OPENED`, `CHAT_MESSAGE_SENT`, `CHAT_MESSAGES_READ`, `CHAT_ATTACHMENT_DOWNLOADED`, `CHAT_LINK_ACTIVATED`/`ENDED`, `CHAT_SETTINGS_UPDATED` e `CHAT_RETENTION_PURGED`, só com ids e contagens. | HTTP [M6] ×5 |
| M7 Erro de envio invisível na web | Corrigido | Mensagem pendente na lista ("Enviando…" ou "Não enviada"), com "Tentar de novo" e "Descartar" dentro da tela, sem `Alert`. | app-web "Falha de rede" |
| M8 Testes | Corrigido | `tests/ni2_138_secure_chat_real_test.cjs` (71 verificações, rotas reais, SQLite e MySQL) e `tests/ni2_138_app_web_real_test.cjs` (23, navegador). | ver abaixo |
| B1 PDF inline | Corrigido | O PDF sai como `attachment`, com `CSP sandbox; default-src 'none'`, `nosniff` e `no-store`. As imagens saem inline. | HTTP [B1] |
| B2 Arquivo órfão | Corrigido | O anexo é gravado antes da transação, e o rollback apaga a linha e o arquivo. A rotina também apaga órfãos. | HTTP [A5] órfão |
| B3 Polling fixo | Corrigido | O app segue a `polling_policy` e pausa em segundo plano (AppState). | app-web |
| B4 Mesmo ✓ | Corrigido | "Enviada", "Entregue" e "Lida" escritos, nos dois lados, com recibos no polling. | app-web "Lida" |
| B5 Hora cortada | Corrigido | ISO com o fuso do consultório. | HTTP [B5] |
| B6 Textos | Corrigido | Sem emoji nem Caixa De Título nos textos do chat. | app-web |

### Execução (2026-10-22)

| Prova | Resultado |
|---|---|
| `ni2_138_secure_chat_real_test.cjs` — SQLite | 71/71 (`docs/evidencias/ni2-138/depois-sqlite.log`) |
| `ni2_138_secure_chat_real_test.cjs` — MySQL 8.0.46 | 71/71 (`depois-mysql.log`) |
| Mesmo teste na árvore anterior (HEAD 58f074b) | 0 PASS, 5 FAIL e para na tabela ausente (`antes.log`) |
| `ni2_138_app_web_real_test.cjs` (app web 390 px, consultório 1280 px, axe) | 23/23 (`app-web-resultados.json` e PNGs) |
| v20 — `ni2_138_chat_xss_dom_test.cjs` | antes 0/2, depois 2/2; suítes de chat do v20 PASS antes e depois |
| SQL manual 48 (INT e BIGINT UNSIGNED, idempotente) | OK (`sql-manual-mysql8.txt`) |
| Schema gate MySQL 8 (instalação nova = upgrade) | ok (`schema-gate-mysql8.json`) |
| Regressão | 97 suítes legadas: 78 PASS e 19 FAIL, todas já falhando antes (2 adaptadas à regra do vínculo). HTTP/navegador: NI2-89 50/50, 91 34/34, 135 52+9+14, 137 85+31, 412 36/36 e 124 (1 BLOCKED antigo), 433 70, 437 48, 298 23/23, 294 34/34, cross-tenant e delivery PASS. As falhas restantes são iguais na árvore anterior (`regressao-suites.txt`) |

### Limites (não aprovado para release)

- **Push:** BLOCKED, sem provedor (Expo/FCM) configurado.
- **E-mail real (SMTP):** NOT TESTED. Só foi provado com o driver de log.
- **Seletor de anexo no celular nativo (iOS e Android):** NOT TESTED. Só foi provado no app web.
- **MySQL 5.7:** NOT TESTED.
- **Tela de preferências de notificação:** não foi construída. A tabela `chat_preferences` é respeitada, mas ainda não há onde a paciente mude.
- **Paciente sem e-mail:** o e-mail para ela fica `skipped/no_email`.
- **Conversas antigas sem vínculo, criadas pela regra anterior:** ficam só para leitura até a equipe liberar o vínculo. O preflight conta quantas são.
- **Status da issue no Jira:** não foi alterado.
