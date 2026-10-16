# Merge v11 (NI2-221 teleconsulta) na codebase local — 15/10/2026

## Onde está
- Backup completo (antes de tudo): `~/Nutri_II-backup-20261015-220436/`
  - `uncommitted.patch` (git diff --binary das 54 alterações), `status.txt`, `untracked/` (arquivos novos),
    `database.sqlite` (cópia via sqlite3 .backup, integrity_check = ok), bundles git usados no merge.
- Worktree: `~/Nutri_II-merge`, branch `merge/v11-teleconsulta` (a partir de 11caa9f).
- `~/Nutri_II` (branch ni2-409-gate-homologacao, 54 alterações) **não foi tocado**; o app da 8080 segue rodando.

## Commits
- `1c05e18` WIP Maicon — as 54 alterações não commitadas, como estavam.
- `638a4e7` Merge v11 (fix/ni2-221-teleconsulta, ce6a376) + resolução de conflitos.
- (este relatório) commit seguinte.

## Decisão principal
Os dois lados reescreveram a teleconsulta inteira, com desenhos diferentes. Mesclar linha a linha
daria um módulo incoerente. Escolhi **o Consultório Online do WIP como base** porque ele já cobre os
pontos de segurança do v11 (hash do convite, janela/TTL, revogação por geração, máquina de estados,
só o responsável, escopo de tenant, flag fail-closed, auditoria obrigatória) e ainda traz vídeo real
(WebRTC P2P), que no v11 era pendência (F02), mais convite por e-mail (F03), rascunho, métricas e impressão.
Do v11 foram portados os itens que o WIP não tinha (abaixo). Todo o resto do v11 (reauditoria, NI2-412,
NI2-426/HTTPS/CSP/HSTS, TACO, paginação, diagnóstico, rate limit de login) entrou integral.

## Conflitos (16) e resolução
| Arquivo | Resolução |
|---|---|
| TeleconsultationController, TeleconsultationService, DailyVideoProvider, teleconsulta.css, teleconsultation_online_clinic_e2e_test | WIP (desenho coerente com views/JS do WIP) |
| TeleconsultationService | + `ONLINE_TYPES`/`isOnlineType()` e **scheduleSession recusa consulta presencial** (v11 F19) |
| public/index.php, routes/web.php | rotas do WIP (token na URL, /midia, /sinal, /rascunho, /convite-email); resto do arquivo do v11 |
| Router.php | exceção CSRF do WIP para /teleconsulta/sala-espera/ e /sinal/ (o controller valida X-CSRF-Token / credencial de mídia); HEAD→GET do v11 mantido |
| AppointmentService | edição com linha completa (WIP) + v11 F04: trocar paciente ou deixar de ser online encerra a sessão; **concluir** a consulta também revoga |
| PatientPortalService | bilhete assinado do WIP + `is_online`/`video_status` do v11 |
| DashboardController | botão só em consulta online (v11 F19), rótulo "Consultório Online" (WIP) |
| TelehealthService, PlatformSettings, cron_scheduler, ni2_323, onda_4 | WIP; cron soma as duas linhas |
| Removidos (sem uso após o merge) | `Core/Security/TelehealthRateLimiter.php`, `Telehealth/TeleconsultationException.php` (v11) — o WIP tem `TeleconsultationRateLimiter` próprio |
| Ajuste de API | o pacote v6 transformou `Mailer` em interface: `TeleconsultationNotifier` usa `MailerFactory::fromEnv()`, `TeleconsultationInviteMail` usa a nova assinatura de `MailMessage`; teste adaptado |
| benchmark_360 | seção de teleconsulta reescrita para a API do WIP (presencial recusada, online cria sala) |

## Migrations
- v11 ocupa 000032–000035. As do WIP foram renumeradas:
  - 000032 → `2026_10_15_000036_add_teleconsultation_webrtc_and_join_window.php` (classe renomeada para não colidir com `HardenTeleconsultationSessions` da 35)
  - 000033 → `2026_10_15_000037_create_teleconsultation_note_drafts.php`
- 35 e 36 coexistem: são idempotentes (só adicionam o que falta; `external_room_name` comum às duas).
  Ficam colunas paralelas sem uso pelo código (`token_*`, `scheduled_at_snapshot`, `consent_term_id` da 35). Inofensivas.
- Na cópia do seu banco: 6 migrations aplicadas (32–37); a segunda execução não encontra pendências.

## Testes
- `php -l` sem erros em todos os .php alterados.
- Suíte `tests/*_test.php` (85), banco de teste limpo: **merge 68/85 = v11 68/85**. As mesmas 17 falhas existem no v11 e no WIP (são de ambiente: MySQL, servidor externo, etc.).
  `teleconsultation_online_clinic_e2e_test` passa; o áudio/vídeo real entre 2 aparelhos continua NOT TESTED (homologação NI2-254).
- Smoke em http://127.0.0.1:8092 (cópia do seu banco): `/` 200, `/login` 200, POST /login com CSRF → 302, `/health` 200 `{"status":"ok"}`,
  `/up` 200, convite inválido → 403 com página amigável, `/teleconsulta/sessao-agenda/1` sem login → 302 /login, CSP com nonce presente.

## Precisa da sua decisão
1. Trocar a 8080 por este merge (ou fazer o merge no seu ~/Nutri_II): não fiz.
2. Termo de consentimento: o WIP usa versão `2026.2` (texto da view); a migração 35 insere o termo `2026.10-r1` em consent_terms, que o WIP não usa. Escolher um.
3. Token na URL (WIP) vs. troca por cookie (v11 F05): mantive na URL, com Referrer-Policy e no-store. Se quiser a troca por cookie, dá para portar.
4. Base TACO: a migration 34 entrou, mas a importação completa (594) não foi rodada no SQLite local.
5. Os testes de navegador do WIP (tests/teleconsulta_navegador, impressão; Node) não foram rodados.
