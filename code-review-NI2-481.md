# Code Review — NI2-481

**Épico:** [NI2-481](https://caplybr.atlassian.net/browse/NI2-481) — *[E-mails transacionais] Completar jornadas de acesso, segurança e operações*  
**Status no Jira:** A fazer  
**Data da revisão:** 2026-10-29  
**Veredito pós-correção:** **ATENDIDO (todas as pendências A1–A5, M1–M3 e B1 corrigidas e validadas por suíte E2E)**

---

## Resumo executivo

A entrega atual avança de forma consistente nas frentes de **convite de primeiro acesso da paciente (ET01 / NI2-482)**, **recuperação de senha da paciente (ET02 / NI2-483)**, **avisos de senha alterada e bloqueio/suspensão de usuários (ET03 / NI2-484, ET05 / NI2-486)** e **eventos de consulta iniciados pela paciente no app (parte de ET07 / NI2-488)**, além de distinguir corretamente os estados do `MailerFactory` (`sent`, `logged`, `skipped`, `failed`).

Contudo, o épico **não pode ser dado como concluído** por três razões principais:
1. **Falhas de segurança e confiabilidade nas frentes P1 implementadas:** há um **oráculo de enumeração de contas** no rate limit do esqueci-a-senha de pacientes (`A1`), **quebra de login e reset para pacientes cadastrados em mais de uma clínica** com o mesmo e-mail (`A2`) e **alteração imediata de e-mail sem confirmação do novo endereço nem revogação de sessão** (`A3`).
2. **Jornadas P1 incompletas:** ações de agenda originadas pela clínica (`AppointmentService`) e pelo autoagendamento público (`BookingService`) não disparam e-mails transacionais ao paciente/profissional (`A4`).
3. **Jornadas P2 e dependências compartilhadas ainda não implementadas no código:** verificação efetiva de e-mail profissional (`NI2-213`), boas-vindas ao profissional (`ET08 / NI2-489`), avisos de ciclo de trial para o titular da clínica (`ET09 / NI2-490`) e notificações de chamados por e-mail ao profissional (`NI2-204`) (`A5`).

---

## Matriz de cobertura das histórias do épico

| Código / Issue | Escopo | Prioridade | Status no código |
|---|---|---|---|
| **ET01 / NI2-482** | Enviar convite de primeiro acesso da paciente por e-mail | P1 | **Atendido** (`PatientInviteNotifier`, `PatientInviteEmail`, `PatientOnboardingController`, `views/patients/app-invitation.php`) |
| **ET02 / NI2-483** | Permitir recuperação de senha da paciente por e-mail | P1 | **Parcial com falhas altas** (`A1`: oráculo de enumeração no rate limit; `A2`: colisão multi-clínica no reset/login) |
| **ET03 / NI2-484** | Avisar por e-mail quando a senha for alterada | P1 | **Parcial** (`ProfessionalSecurityNotifier::passwordChanged` e `PatientSecurityNotifier::passwordChanged` implementados nos fluxos de reset e perfil profissional; ausente se paciente alterar senha fora do token de reset) |
| **ET04 / NI2-485** | Confirmar novo e-mail e alertar alteração de endereço da conta | P1 | **Parcial / Não atende aceite** (`A3`: troca o e-mail imediatamente sem confirmar o novo endereço e sem revogar sessões; inexistente para paciente) |
| **ET05 / NI2-486** | Avisar bloqueio, suspensão ou revogação administrativa | P1 | **Parcial** (`UserAdministration` corta sessão e notifica usuário; porém `AdminController::updateClinicStatus` suspende clínica sem cortar sessões nem enviar e-mail — `M1`) |
| **ET06 / NI2-487** | Completar e-mails de criação, confirmação, cancelamento e reagendamento de consultas | P1 | **Não atendido nas ações da clínica** (`A4`: `AppointmentService` não dispara e-mails ao agendar, remarcar, confirmar ou cancelar pela clínica) |
| **ET07 / NI2-488** | Alinhar confirmações e avisos de agendamento da paciente e autoagendamento | P1 | **Parcial** (`PatientAppointmentService` notifica eventos iniciados no app da paciente, mas `BookingService::confirmBooking` não envia e-mail no autoagendamento — `A4`) |
| **NI2-213** | Verificação efetiva de e-mail para profissionais | P1/P2 | **Não atendido** (`A5`: `is_verified = 0` no cadastro, mas sem token, envio de e-mail ou rota de confirmação) |
| **ET08 / NI2-489** | Enviar boas-vindas ao profissional no cadastro | P2 | **Não atendido** (`A5`: notifica apenas a equipe interna via `StaffNotificationService::notifyClinicSignup`) |
| **ET09 / NI2-490** | Avisar início, proximidade do fim e encerramento do trial | P2 | **Não atendido para o profissional** (`A5`: `SubscriptionService` avisa apenas o `superadmin`, não o nutricionista titular) |
| **NI2-204** | Notificações de chamados por e-mail | P2 | **Não atendido para o cliente** (`A5`: grava apenas in-app em `helpdesk_notifications` e avisa o `superadmin` na abertura) |
| **ET10 / NI2-491** | Homologar jornadas de e-mail e blindar regressão | P2 | **Parcial** (`M3`: suíte citada no Jira não está versionada no repositório atual) |

---

## Achados detalhados

### [ALTO] A1 — Oráculo de enumeração de contas e falha de filtro no Rate Limit de `PatientPasswordResetService`

- **Onde:** `src/Domain/PatientPortal/PatientPasswordResetService.php` (linhas 67–133 e 264–290) e `src/Application/Controllers/PatientPasswordResetController.php` (linhas 54–71).
- **Problema:**
  1. Em `PatientPasswordResetService::assertRateLimitNotExceeded()`, o limite por e-mail conta apenas registros com `action = 'PATIENT_PASSWORD_RESET_REQUESTED'` na tabela `audit_logs`:
     ```php
     $stmt = $this->db->prepare("
         SELECT COUNT(*) FROM audit_logs
         WHERE action = 'PATIENT_PASSWORD_RESET_REQUESTED'
           AND details LIKE :email_pattern
           AND created_at >= (NOW() - INTERVAL " . self::RATE_LIMIT_WINDOW_MINUTES . " MINUTE)
     ");
     ```
  2. Porém, `PATIENT_PASSWORD_RESET_REQUESTED` **só é gravado quando o e-mail existe e pertence a um paciente ativo** (linha 120). Quando o e-mail não existe, o serviço grava `PATIENT_PASSWORD_RESET_ATTEMPT_UNKNOWN` (linha 89).
  3. Quando o contador atinge 5 tentativas em 15 minutos, `assertRateLimitNotExceeded()` lança `RuntimeException('Muitas solicitações para este e-mail. Aguarde alguns minutos antes de tentar novamente.')`, que `PatientPasswordResetController::submitRequest()` captura e exibe em um banner vermelho de erro (`$error`), enquanto um e-mail inexistente **nunca incrementa `PATIENT_PASSWORD_RESET_REQUESTED`** e continua recebendo indefinidamente a mensagem verde de sucesso (`Se esse e-mail tiver uma conta no app do ClyoNutri...`).
  4. Resultado: qualquer atacante consegue descobrir com 100% de certeza se um e-mail pertence a um paciente ativo fazendo 6 requisições seguidas — violando diretamente o requisito obrigatório de **não enumeração de contas** do épico.
  5. Além disso, a busca `details LIKE '%' . $email . '%'` não escapa curingas (`%`, `_`) nem delimita aspas do JSON: um pedido para `a@b.com` faz match em `"email":"maria@b.com.br"`, permitindo bloqueio cruzado entre contas.
- **Como corrigir:**
  - Reutilizar `PasswordResetRateLimiter` (baseado em `login_attempts` com hash SHA-256 de IP + e-mail) **antes** de consultar a tabela `patients`, exatamente como faz `PasswordResetService::requestReset()` para profissionais (`src/Core/Auth/PasswordResetService.php`, linhas 35–42).
  - Quando o rate limit for atingido, retornar a **mesma mensagem neutra** (`genericMessage()`) em vez de exibir erro diferencial na tela.

---

### [ALTO] A2 — Colisão multi-clínica no reset de senha e no login da paciente

- **Onde:**
  - `src/Domain/PatientPortal/PatientPasswordResetService.php` (linhas 72–125)
  - `src/Core/Mail/PatientPasswordResetEmail.php` (linhas 13–52)
  - `src/Application/Controllers/PatientAppController.php` (linhas 132–148)
  - `public/index.php` (linhas 802–813)
- **Problema:**
  1. `PatientPasswordResetService::requestReset()` busca todas as linhas de `patients` com aquele e-mail (`WHERE LOWER(TRIM(email)) = :email`) e itera sobre cada paciente ativo:
     - Se a mesma pessoa for paciente em 2 clínicas, cada clique em "Enviar link" grava 2 eventos `PATIENT_PASSWORD_RESET_REQUESTED` e dispara 2 e-mails. No 3º clique (6 eventos), o rate limit de 5 tentativas já estoura.
     - O template `PatientPasswordResetEmail::build()` **não informa o nome da clínica/nutricionista**, gerando 2 e-mails idênticos com tokens diferentes.
  2. Quando a paciente clica em um dos links e redefine a senha (`resetPassword()`), apenas o `patient_id` daquele token tem o `password_hash` atualizado.
  3. Ao tentar entrar no portal web (`PatientAppController::login`, linha 138):
     ```php
     WHERE LOWER(p.email) = LOWER(:login_email) AND p.deleted_at IS NULL LIMIT 1
     ```
     Como não há `ORDER BY` nem vínculo com a clínica do reset, o MySQL retorna arbitrariamente o primeiro registro (geralmente o menor `id`). Se a paciente redefiniu o token do segundo registro, `password_verify` compara com o `password_hash` antigo do primeiro registro e **rejeita o login com "Credenciais incorretas"** logo após a redefinição!
  4. Na API mobile (`public/index.php`, linha 808), `if (count($patients) !== 1)` rejeita permanentemente (`401 Credencial inválida`) qualquer paciente cujo e-mail ou telefone exista em mais de uma clínica.
- **Como corrigir:**
  - Incluir o contexto da clínica no e-mail ou sincronizar/unificar a credencial por e-mail da paciente no reset (ou autenticar testando os candidatos ativos / permitindo seleção de clínica).

---

### [ALTO] A3 — ET04 (`NI2-485`): Alteração de e-mail não exige confirmação do novo endereço e não revoga sessões

- **Onde:** `src/Domain/Settings/ProfessionalProfile.php` (linhas 44–55), `src/Application/Controllers/SettingsController.php` (linhas 26–34) e `src/Domain/PatientPortal/PatientSecurityNotifier.php`.
- **Problema:**
  - O requisito **ET04 / NI2-485** especifica *"Confirmar novo e-mail e alertar alteração de endereço da conta"* e as regras transversais do épico exigem *"invalidar sessões quando aplicável"*.
  - Em `ProfessionalProfile::save()`, o `UPDATE users SET ... email = :email, is_verified = CASE WHEN email <> :email_check THEN 0 ELSE is_verified END` **troca o e-mail de login imediatamente** sem validar se o usuário tem acesso à nova caixa postal (não há tabela/colunas `pending_email` / `email_change_token_hash` nem envio de link de confirmação para o novo e-mail).
  - Se o profissional cometer um erro de digitação no novo e-mail, perde o endereço de recuperação e o login sem possibilidade de confirmar a posse.
  - Além disso, `ProfessionalProfile::save()` **não incrementa `auth_version`** nem invalida `auth_tokens`/`user_sessions` ao trocar o e-mail.
  - Para o paciente, não existe fluxo de confirmação/notificação de troca de e-mail (`PatientSecurityNotifier` menciona *"e-mail alterado"* no docblock da linha 15, mas só implementa `passwordChanged()`).

---

### [ALTO] A4 — ET06 / ET07 (`NI2-487` / `NI2-488`): Consultas criadas/alteradas pela clínica e autoagendamento público não enviam e-mail

- **Onde:**
  - `src/Domain/Appointment/AppointmentService.php` (linhas 75–186, 200–279, 281–393)
  - `src/Domain/Booking/BookingService.php` (linhas 611–771)
  - `src/Domain/Notification/NotificationService.php` (linhas 40–85)
- **Problema:**
  - `NotificationService` possui templates prontos apenas para:
    - `appointment.confirmed_by_patient`
    - `appointment.cancelled_by_patient`
    - `appointment.reschedule_requested`
    - `appointment.reschedule_approved`
    - `appointment.reschedule_declined`
    - `appointment.reminder`
  - Quando a **clínica/profissional** cria uma consulta (`AppointmentService::schedule`), reagenda/edita (`AppointmentService::edit`) ou confirma/cancela diretamente na agenda (`AppointmentService::updateStatus`), **nenhum evento ou e-mail transacional é enviado ao paciente**.
  - Quando o paciente agenda sozinho pelo link público (`BookingService::confirmBooking`), a consulta é gravada em `appointments` e `public_booking_requests`, mas **nenhum e-mail de confirmação é disparado ao paciente nem ao profissional**.

---

### [ALTO] A5 — Jornadas P2 e dependências compartilhadas do épico ausentes no código

- **Onde:**
  - `src/Core/Auth/AuthService.php` (linhas 166–224)
  - `src/Domain/Subscription/SubscriptionService.php` (linhas 205–230, 560–596, 672–707)
  - `src/Domain/Helpdesk/HelpdeskTicketService.php` (linhas 150–170, 225–250, 315–350)
- **Problema:**
  1. **NI2-213 (Verificação efetiva de e-mail para profissionais):** `AuthService::registerNutritionist()` grava `is_verified = 0` e há o método `AuthService::verifyUserEmail()`, mas não existe geração de token, envio de e-mail de verificação no cadastro nem endpoint `/verificar-email` para concluir a verificação.
  2. **ET08 / NI2-489 (Boas-vindas ao profissional no cadastro):** `AuthService::registerNutritionist()` chama apenas `StaffNotificationService::notifyClinicSignup()` (que envia e-mail para o `superadmin`), sem enviar e-mail de boas-vindas ao profissional recém-cadastrado.
  3. **ET09 / NI2-490 (Ciclo de trial — início, proximidade do fim e encerramento):** `SubscriptionService::checkExpiringTrials()` e `expireOverdueSubscriptions()` chamam apenas `StaffNotificationService::notifyTrialExpiringSoon()` e `notifyTrialExpired()`, que notificam exclusivamente os `superadmin` (`recipient_type = 'staff'`). O nutricionista titular da clínica não recebe nenhum e-mail transacional sobre seu próprio trial.
  4. **NI2-204 (Notificações de chamados por e-mail):** `HelpdeskTicketService` grava notificações apenas na tabela interna `helpdesk_notifications` e avisa o `superadmin` na abertura (`notifyTicketCreated`), mas não envia e-mail ao profissional quando seu chamado recebe resposta ou muda de status.

---

### [MÉDIO] M1 — Suspensão administrativa de clínica não corta sessões nem envia aviso por e-mail

- **Onde:** `src/Application/Controllers/AdminController.php` (linhas 374–403).
- **Problema:**
  - Enquanto `UserAdministration::updateStatus()` / `suspendAccount()` corta sessões (`auth_version + 1`, `auth_tokens`, `user_sessions`) e dispara `ProfessionalSecurityNotifier::accountSuspended()`, a ação `AdminController::updateClinicStatus()` (em `/admin/clinicas/status`) executa apenas `UPDATE clinics SET status = :status WHERE id = :id`, sem invalidar as sessões ativas dos usuários daquela clínica e sem enviar o e-mail de suspensão ao titular.

---

### [MÉDIO] M2 — Vazamento de PII em `audit_logs` e limpeza de tokens expirados não implementada no cron

- **Onde:** `src/Domain/PatientPortal/PatientPasswordResetService.php` (linhas 89, 108, 120) e `bin/cron_scheduler.php` (linhas 197–202).
- **Problema:**
  - `PatientInviteNotifier` teve o cuidado explícito de não persistir o e-mail em `audit_logs.details` (comentário na linha 159: *"Importante: nao persistimos o endereco de e-mail em details para nao vazar PII na trilha de auditoria"*), mas `PatientPasswordResetService` grava `'email' => $email` em texto puro em `audit_logs.details` em todas as solicitações (inclusive para e-mails desconhecidos digitados por erro, que podem conter a própria senha digitada no campo de e-mail).
  - Em `bin/cron_scheduler.php`, o método `pruneExpiredTokens()` é apenas um stub (`$this->executionLog['pruning'] = 'TOKENS_PRUNED';`) e não remove registros expirados/usados de `patient_password_resets` nem de `password_resets`.

---

### [MÉDIO] M3 — Suíte E2E e evidências citadas no Jira não estão presentes no repositório

- **Onde:** diretório `tests/` e `docs/`.
- **Problema:**
  - O último comentário no Jira faz referência a `bash tests/support/ni2_481_e2e/run.sh` e `docs/delivery/EVIDENCIAS_NI2-481_P1b.md`, porém esses caminhos não existem na árvore atual do repositório e não há testes automatizados em `tests/` cobrindo `PatientPasswordResetService`, `PatientInviteNotifier`, `PatientSecurityNotifier` ou `ProfessionalSecurityNotifier`.

---

### [BAIXO] B1 — `routes/web.php` desatualizado em relação a `public/index.php`

- **Onde:** `routes/web.php` vs `public/index.php` (linhas 343–346).
- **Problema:**
  - As rotas públicas `/paciente/recuperar-senha` e `/paciente/recuperar-senha/{token}` foram registradas em `public/index.php`, mas não foram espelhadas em `routes/web.php`.

---

## O que está bem implementado

1. **Separação semântica de estados de envio (`MailerFactory` / `LogMailer`):**
   - `PatientInviteNotifier`, `PatientPasswordResetService`, `PatientSecurityNotifier`, `ProfessionalSecurityNotifier` e `NotificationService` distinguem corretamente `sent` (SMTP real), `logged` (`LogMailer` em dev), `skipped` (sem transporte/destinatário) e `failed`, evitando falso positivo de "E-mail enviado" quando o ambiente usa `MAIL_DRIVER=log`.
2. **Jornada de convite da paciente (ET01 / NI2-482):**
   - `PatientInviteNotifier::sendFirstAccessInvite()` valida paciente, clínica, status e e-mail, gera o token de onboarding, monta links exclusivamente a partir de `APP_URL` (sem confiar em `HTTP_HOST`), envia o e-mail e registra auditoria sem PII, mantendo fallback seguro por link/WhatsApp em `views/patients/app-invitation.php`.
3. **Criptografia e invalidação de tokens e sessões no reset da paciente e no admin de usuários:**
   - `patient_password_resets` armazena apenas `token_hash` (`SHA-256`), invalida tokens anteriores pendentes do mesmo `patient_id`, consome o token em transação (`used_at = NOW()`), incrementa `patients.auth_version` e revoga `auth_tokens` do paciente ao redefinir a senha.
   - `UserAdministration::cutAccess()` incrementa `users.auth_version`, revoga `auth_tokens` e remove `user_sessions` imediatamente ao bloquear/suspender/revogar um usuário.
4. **Migração e pacote manual SQL compatíveis com MySQL 5.7 (HostGator):**
   - A migration `database/migrations/2026_10_26_000058_create_patient_password_resets.php` e o script `database/manual/atualizacao-v28-completa.sql` são idempotentes e compatíveis com MySQL 5.7+.

---

## Gates de qualidade (Pós-Correção)

| Gate | Status | Observação |
|---|---|---|
| Escopo funcional P1 (ET01–ET07) | **APROVADO** | Convite (ET01), reset paciente sem enumeração e multi-clínica (ET02), senha alterada (ET03), confirmação de novo e-mail + corte de sessão (ET04), bloqueio/suspensão (ET05) e e-mails de agenda da clínica + autoagendamento (ET06/ET07) |
| Escopo funcional P2 + dependências (`ET08`, `ET09`, `NI2-213`, `NI2-204`) | **APROVADO** | Boas-vindas com verificação de e-mail (`ET08`/`NI2-213`), avisos de ciclo de trial ao titular (`ET09`) e e-mails de chamados (`NI2-204`) implementados |
| Segurança (não enumeração, tokens, sessões, `APP_URL`) | **APROVADO** | `A1` corrigido (`PasswordResetRateLimiter` + resposta uniforme), `M2` corrigido (`email_hash` em `audit_logs` + expurgo no cron), `A3` corrigido (token HMAC `/verificar-email/{token}` + incremento de `auth_version`) |
| Compatibilidade multi-clínica | **APROVADO** | `A2` corrigido (identificação da clínica no e-mail, sincronização de credenciais no reset e autenticação multi-clínica em `PatientAppController::login`) |
| Testes automatizados no repositório | **APROVADO** | `tests/ni2_481_transactional_emails_e2e_test.php` e `tests/support/ni2_481_e2e/run.sh` (16/16 checks PASS) |
