# Code review — NI2-83 · [Agenda] Agenda diária e semanal responsiva

| | |
|---|---|
| **Épico** | NI2-10 · [P1] Agenda e jornada de consulta (Jira: *Feito*) |
| **Status da história no Jira** | *Feito* (atualizada em 07/10/2026 20:01 BRT) |
| **Snapshot revisado** | `/workspace/clyonutri/snap_0145/Nutri_II` (cópia de 01:45 BRT), HEAD `9178545` (NI2-178), 23 commits |
| **Execução** | Cópia de trabalho em `/workspace/scratch_ni2_83/app` (o snapshot não foi alterado). PHP 8.4.26 CLI, SQLite, Chrome headless |
| **Evidências** | `/workspace/clyonutri/evidencias-NI2-83/` (screenshots 390/768/1440 px e scripts de PoC) |
| **Data da revisão** | 08/10/2026, ~01:45–02:00 BRT |
| **Jira** | Só leitura (nada foi comentado, editado ou transicionado) |

> Observação sobre o título: no Jira o título é **"Agenda diária e semanal responsiva"**, e não "Calendário diário e semanal responsivo". O escopo é o mesmo.

---

## 1. Veredito

**REPROVADO. A história não atende ao aceite e o status *Feito* não se sustenta.**

- O que existe é uma **tabela com as consultas de hoje** (`GET /agenda` → `DashboardController::agenda`, `src/Application/Controllers/DashboardController.php:130-180`). **Não há visão semanal, nem navegação por data, nem layout móvel** (o CSS do app não tem nenhuma `@media`, e a 390 px a sidebar fixa de 256 px esmaga o conteúdo; veja o screenshot).
- A história foi marcada como *Feito* em **07/10 às 20:01 BRT**. A rota web `/agenda` com tela só passou a existir no commit `a80a486`, em **08/10 às 00:29 BRT**, umas 4h30 depois.
- O que está certo: o isolamento **de leitura** por clínica funciona (PoC: a consulta da clínica B não aparece para a clínica A) e o nome do paciente é escapado (PoC com `<img onerror>` renderizou como texto).
- Na revisão apareceram problemas que afetam os dados da agenda, nas histórias vizinhas (NI2-81/82, API do paciente): **login do paciente na API só com e-mail ou telefone, sem senha** (Crítico), **"hoje" calculado em UTC**, **nenhuma detecção de sobreposição**, **ambiguidade entre `professional_id` e `user_id` que permite gravar uma consulta da clínica A apontando para um profissional da clínica B** (PoC), e **um paciente consegue reconfirmar uma consulta cancelada** (PoC).
- Nenhum teste cobre a agenda (`listByDay`/`GET /agenda`). O padrão de testes com `assert()` continua (214 chamadas em 13 arquivos), e na configuração padrão de produção do PHP (`zend.assertions=-1`) essas chamadas não fazem nada.

**Contagem de achados:** Crítico **2** · Alto **6** · Médio **7** · Baixo **5** (total 20).

---

## 2. Onde está o código da NI2-83

| Camada | Arquivo:linha | Observação |
|---|---|---|
| Rota web | `public/index.php:146` | `GET /agenda` protegido só por `$requireAuth` (`index.php:121-129`), que verifica sessão e clínica, sem papel |
| Controller + view inline | `src/Application/Controllers/DashboardController.php:130-180` | HTML inline no controller; `listByDay(date('Y-m-d'))` fixo (`:133`) |
| Serviço / consulta | `src/Domain/Appointment/AppointmentService.php:75-88` | `listByDay()`: `WHERE a.clinic_id = :clinic_id AND date(a.scheduled_at) = date(:date)` |
| Bloco "Agenda de hoje" do dashboard (NI2-85) | `DashboardController.php:335`, `:389-415` | Reaproveita `listByDay` |
| Layout | `views/layouts/professional-shell.php:1-79` | Sidebar fixa, sem navegação móvel |
| CSS | `public/assets/css/clinical-botanical.css` (429 linhas) | **0** `@media`; `.app-sidebar` 256 px fixa (`:100-108`); badges `:328-340` |
| Schema | `database/migrations/2026_10_07_000004_create_operations_and_finance_tables.php:14-29` | `appointments` sem índices próprios, sem `no_show`, sem timezone |
| Tenancy | `src/Core/Security/TenantGuard.php:40-75` | Asserts de posse usados por `schedule`/`updateStatus` |
| API do paciente (lê/confirma consultas) | `public/index.php:188-221`, `:234-255`, `:277-287`; `src/Domain/PatientPortal/PatientPortalService.php:319-353` | Fora do escopo direto, mas expõe os mesmos dados |
| JS / drag-and-drop / reagendamento | — | **Não existe.** Não há nenhuma mutação na agenda web |
| Endpoint de API da agenda profissional | — | **Não existe** (`docs/api/openapi_v1.json` não tem rota de agenda) |
| Testes | — | **Nenhum** teste chama `listByDay` ou `GET /agenda` (veja a seção 6) |
| Commits | `39fd0bb` (serviço, 07/10 20:00), `2be3fe4`/`6a5ca9d`/`0a136f6` (shell/dashboard), `264a6aa` (asserts de tenant), **`a80a486` (rota e tela `/agenda`, 08/10 00:29)** | Nenhum commit cita NI2-83 |

**Arquivos não commitados (landing):** `src/Application/Controllers/LandingController.php`, `public/assets/css/landing-premium.css`, `docs/delivery/LANDING_*.md`. **Nenhum toca a agenda.** O `LandingController` nem está roteado em `public/index.php`. Para comparar: `landing-premium.css` tem `@media` (linhas 1024, 1046, 1108), o CSS do app não tem. Também aparecem `storage/*/.gitkeep` removidos e dezenas de arquivos `._*` (AppleDouble do macOS), que deveriam ir para o `.gitignore`.

### 2.1 O projeto agora tem Laravel?

**Não. O projeto continua sendo o MVC próprio.** O `app/` (adicionado no commit `30514f0`, "replataforma Laravel 13", NI2-142) contém só **3 classes PHP puras** no namespace `App\`, que o `composer.json` mapeia por PSR-4:
`app/Support/Tenancy/TenantManager.php`, `app/Domain/Foods/NutritionCalculator.php` e `app/Domain/Anthropometry/AnthropometryCalculator.php`. Os outros 11 diretórios (`app/Domain/Scheduling`, `Identity`, `Patients`…) estão **vazios**.
Não há `laravel/framework` no `composer.json` (o único requisito é `"php": ">=8.1"`), nem `artisan`, `bootstrap/`, `routes/` ou `config/app.php`. O app em execução **não usa** `app/`: só `tests/replatform_infrastructure_test.php` e `bin/verify_quality_gate.php` o referenciam. Isso cria duplicação (por exemplo, há também `src/Domain/Nutrition/NutritionCalculator`) e confirma o bug **NI2-174** ("Laravel 13 ausente no release", *A fazer*).

---

## 3. Critérios de aceite (NI2-83: "dia/semana; navegação por data; hoje; paciente/consulta; estados; mobile utilizável")

| # | Critério | Status | Evidência |
|---|---|---|---|
| 1 | **Dia / semana** | **Parcial** (dia = só hoje; semana **inexistente**) | `DashboardController.php:133` chama só `listByDay(date('Y-m-d'))`; não há método de semana em `AppointmentService.php` (só `schedule`, `updateStatus`, `listByDay`). PoC: `?view=week` devolve HTML idêntico (mesmo md5) |
| 2 | **Navegação por data** | **Inexistente** | A data está fixa em `:133`; o controller não lê `$_GET`. PoC: `/agenda?date=2026-10-09` devolve exatamente o mesmo HTML de `/agenda` |
| 3 | **Hoje** | **Parcial** | Mostra "hoje", mas calculado no fuso padrão do PHP (UTC; não há `date_default_timezone_set`/`date.timezone` no código nem no `.env.example`). Entre 21:00 e 23:59 BRT, "hoje" vira o dia seguinte (PoC na seção 4, A1). O `<h1>` "Agenda do Dia" (`:143`) não informa qual é a data. Não há botão "Hoje" |
| 4 | **Paciente / consulta** | **Parcial** | Mostra horário, nome, telefone, tipo e status (`:165-169`). **Não tem link** para o paciente (`/pacientes/{id}`) nem para a consulta (NI2-84). Não mostra profissional, duração nem horário de término. Consultas com data inválida somem sem aviso (PoC) |
| 5 | **Estados** | **Parcial** | O status aparece como **código cru em inglês** (`scheduled`, `cancelled`), todos com a mesma cor `badge-info` (`:169`). Não existe o estado `no_show` (schema `…000004…php:21`; lista em `AppointmentService.php:57`). Não depende só de cor (há texto), mas a cor não é semântica |
| 6 | **Mobile utilizável** | **Inexistente** | Não há nenhuma `@media` em `clinical-botanical.css`; `.app-sidebar { width: 256px; flex-shrink: 0 }` (`:100-108`). Screenshot a 390 px: o conteúdo útil fica com ~100 px, e título e tabela aparecem cortados. A 768 px, as colunas Tipo e Status ficam fora da tela. Também falha o critério de reflow da WCAG 2.2 AA (1.4.10) |

**Resultado: 0 de 6 implementados · 4 parciais · 2 inexistentes.**

Expectativas mais novas que se somam (não fazem parte do aceite original da NI2-83, mas são o que o Jira espera hoje):
- **NI2-187** (*A fazer*, criada em 08/10 01:43): agenda diária/semanal, conflitos "inclusive em requisições concorrentes", testes em **390/768/1280/1440 px**, autorização e timezone, empty/erro/recuperação, CI em MySQL 8.
- **NI2-81** (aceite: "conflitos tratados conforme regra; tenant; timezone consistente") e **NI2-82** ("transições válidas; timestamps; histórico"; estados incluem **no-show**). As duas estão *Feito*, mas o código não atende (veja os achados A2, A3, A5).
- **NI2-152** (*A fazer*): matriz RBAC; "não usar apenas `assert()` do PHP".
- **NI2-175/178** (*Feito*): testes por camada, HTTP real, fail-closed. Não se reproduz para a agenda (seção 6).

---

## 4. Achados por severidade

### Crítico

**C1. O aceite central da NI2-83 não foi entregue (semana, navegação por data, mobile), e a história está *Feito*.**
- `DashboardController.php:130-180`, `AppointmentService.php:75-88`, `clinical-botanical.css` (0 `@media`, `.app-sidebar` `:100-108`), `professional-shell.php:15-54` (sidebar sem alternativa móvel).
- Impacto: a agenda é inutilizável no celular (screenshot de 390 px) e não serve para operar a semana. Viola WCAG 2.2 **1.4.10 Reflow (AA)**. O status no Jira dá falsa garantia.
- **Correção:**
  1. `GET /agenda?view=day|week&date=YYYY-MM-DD`, com validação por `DateTimeImmutable::createFromFormat('!Y-m-d', …)` e fallback para hoje no fuso da clínica.
  2. Botões "Anterior / Hoje / Próximo" como links (`<a>`), que funcionam sem JS.
  3. `AppointmentService::listByRange(DateTimeImmutable $from, DateTimeImmutable $to, ?int $professionalId)`.
  4. CSS mobile-first: abaixo de `md=768px`, a sidebar vira drawer ou bottom-nav, como o DS pede ("navegação simplificada no mobile priorizando agenda"); a visão diária vira lista de cartões e a semanal só aparece a partir de `lg=1024px` (no mobile, cai para dia com seletor de dia).
  5. Reabrir a NI2-83 (ou fechá-la como duplicada da NI2-187 com o aceite migrado). Eu **não** alterei o Jira.

**C2. A API do paciente emite token só com e-mail ou telefone, sem senha nem OTP, e com esse token qualquer pessoa lê e confirma as consultas do paciente.** *(Fora do escopo direto da NI2-83, mas expõe os dados da agenda.)*
- `public/index.php:188-221`: `SELECT * FROM patients WHERE (email = :email OR phone = :phone) AND status = 'active' LIMIT 1` gera o token na hora. O token é gravado sem `expires_at` (`:206-210`), então não expira nunca. A busca também não é por clínica: se o mesmo e-mail existir em duas clínicas, `LIMIT 1` escolhe uma arbitrariamente.
- **PoC (executada):** `POST /api/v1/auth/patient-login {"email":"bruno@poc.test"}` → token. `GET /api/v1/patient/home` devolve a próxima consulta com `professional_name`, `meeting_url` e **`notes`** (veja M6). `POST /appointment/confirm` funcionou.
- **Correção:** login do paciente por convite com senha ou OTP de uso único (o caminho Sanctum + Fortify do plano), `expires_at` obrigatório, rate limit, unicidade `(tenant_id, email)` e escolha explícita de clínica. Bloquear a release até isso estar resolvido (é o tema da NI2-176/NI2-89).

### Alto

**A1. Timezone: "hoje" em UTC, `scheduled_at` em hora local "ingênua", `created_at` em UTC e `date()` convertendo offset para UTC.**
- `DashboardController.php:133` e `:335` usam `date('Y-m-d')` com o fuso padrão do PHP (UTC: `php -r 'echo date_default_timezone_get();'` → `UTC`). Não há configuração de fuso no repositório.
- `appointments.scheduled_at` guarda a string recebida sem normalizar (`AppointmentService.php:31-39`), enquanto `created_at`/`updated_at` usam `CURRENT_TIMESTAMP`, que no SQLite é UTC (PoC: consulta criada às 01:49 BRT ficou com `created_at = 04:49`).
- **PoC (executada):** 22:30 BRT do dia 08/10 → com PHP em UTC, "hoje" = **09/10**. Uma consulta salva como `2026-10-08T23:30:00-03:00` (o serviço aceitou) **não aparece** na agenda do dia 08, porque `date()` do SQLite converte para 09/10.
- MySQL: `DATE()` não converte offset da mesma forma, e desde o 8.0.19 um literal com offset é convertido para o fuso da sessão. Ou seja, o comportamento **diverge entre SQLite (testes) e MySQL (produção)**. O fuso do servidor e do MySQL na HostGator **não foi verificado**.
- **Correção:** `APP_TIMEZONE=America/Sao_Paulo` aplicado no bootstrap e fuso por clínica (`clinics.timezone`). Gravar **UTC** (`scheduled_at_utc` e `ends_at_utc`). Converter o intervalo do dia/semana local para UTC antes da consulta. Validar a entrada com formato estrito. Exibir com `IntlDateFormatter` pt-BR. O Brasil não tem horário de verão desde 2019, mas gravar em UTC com fuso IANA protege contra mudança de regra e contra clínicas em outros fusos (AM/MT/AC).

**A2. Sem detecção de sobreposição: o mesmo profissional fica com consultas sobrepostas, e a agenda não sinaliza.**
- `AppointmentService::schedule` (`:20-50`) faz o INSERT direto. A tela não marca conflito.
- **PoC:** 10:00–11:00 e 10:30–11:30 para o profissional 1, as duas aceitas e listadas.
- Contraria NI2-81 ("conflitos tratados conforme regra") e NI2-187 ("inclusive em requisições concorrentes").
- **Correção:** em transação, `SELECT … FOR UPDATE` no intervalo do profissional (`starts_at < :new_end AND ends_at > :new_start AND status NOT IN ('cancelled','no_show')`). Guardar `ends_at`. No MySQL 8, serializar por profissional (lock de linha em `professionals` ou `GET_LOCK('appt:'||prof)`), porque MySQL não tem *exclusion constraint*. Na agenda, mostrar um badge "Conflito" com texto.

**A3. `TenantGuard::assertProfessionalBelongsToClinic` aceita `p.id = :id OR p.user_id = :id`, o que permite gravar consulta da clínica A apontando para profissional da clínica B.**
- `src/Core/Security/TenantGuard.php:42-49`.
- **PoC (executada, `prof_id_confusion.php`):** o usuário `user_id=4` é membro da clínica A, e `professionals.id=4` pertence à clínica B. `schedule(pacienteA, 4)` passou no guard (casou por `user_id`) e gravou `appointments.professional_id=4`. Resultado: *"Agendamento 8 na clínica 1 aponta para professional_id=4 (Profissional Beta 2) da clínica 2"*. O nome desse profissional depois aparece para o paciente via `getNextAppointment` (`PatientPortalService.php:319-336`).
- Os próprios testes passam `user_id` onde se espera `professional_id` (`tests/phases_4_5_6_test.php:42`, `tests/onda_4_benchmark_test.php:41`) e só passam porque os IDs coincidem.
- Hoje **não é explorável por HTTP**, porque não há rota web de criação de consulta, mas vira explorável assim que a NI2-81 ganhar UI.
- Há ainda um risco de MySQL: o placeholder nomeado `:id` aparece duas vezes, e `Connection.php:60` usa `ATTR_EMULATE_PREPARES => false`. O manual do PDO não permite repetir marcador nomeado sem emulação, o que deve gerar `HY093`, ou seja, `schedule()` provavelmente **falha em MySQL**. **Não verificado** (não havia MySQL disponível).
- **Correção:** aceitar só `professionals.id`, com consulta `JOIN clinic_members cm ON cm.user_id = p.user_id AND cm.clinic_id = :clinic_id WHERE p.id = :professional_id`, e usar placeholders distintos. No schema, FK composta `(clinic_id, professional_id)` ou coluna `tenant_id` em `professionals`. Corrigir os testes.

**A4. Paciente reconfirma consulta cancelada, e a confirmação de um ID inexistente devolve `success: true`.**
- `PatientPortalService.php:338-353`: `UPDATE … SET status='confirmed' WHERE id … AND patient_id … AND clinic_id …`, sem checar o estado atual, e retorna `execute()`, não `rowCount()`. `AppointmentService::updateStatus` (`:52-73`) também aceita qualquer transição.
- **PoC:** consulta 2 `cancelled` → `POST /appointment/confirm` → `confirmed`. `appointment_id=999999` → `{"success":true}`.
- Isso quebra os "estados" exibidos na agenda (NI2-82: "transições válidas; histórico").
- **Correção:** máquina de estados (`scheduled→confirmed→in_progress→completed`, `scheduled|confirmed→cancelled|no_show`) num único ponto, `WHERE status IN (...)` com checagem de `rowCount() === 1`, e tabela `appointment_status_history` (append-only, com `changed_by` e `changed_at` UTC).

**A5. Não há autorização por profissional ou papel: qualquer membro da clínica vê a agenda e os telefones de todos os profissionais.**
- `listByDay` filtra só `clinic_id` (`AppointmentService.php:82`). `$requireAuth` não olha papel (`index.php:121-129`). A sessão nem guarda o papel (`AuthController.php:59-63`). A matriz `docs/security/MATRIZ_RBAC_MULTI_TENANT.md:24-44` **não tem a linha "Visualizar agenda"**.
- **PoC:** criei uma 2ª profissional na clínica A com uma consulta, e a Dra. Camila a vê em `/agenda`.
- Pode ser aceitável para clínicas pequenas (a secretária precisa ver tudo), mas é uma **decisão de produto não tomada** (NI2-152 está *A fazer*).
- **Correção:** definir a regra na matriz (por exemplo, nutricionista vê a própria agenda e as compartilhadas; assistente e admin veem todas). Guardar `member_role` na sessão. Filtro `professional_id` com Policy. Seletor de profissional na UI, limitado ao permitido.

**A6. Os testes não cobrem a agenda, e o padrão `assert()` continua; o gate de CI não se reproduz.** Detalhes na seção 6.
- Nenhum teste de `listByDay` ou `GET /agenda`.
- 214 chamadas de `assert()` em 13 arquivos. Com `zend.assertions=-1` (valor do `php.ini` de produção e do PHP desta máquina) não executam, e `tests/phases_4_5_6_test.php:43-46` "valida a agenda" só com `assert()`.
- `bin/build_release.sh:69-74` roda `php "$test_file" > /dev/null` e conta "Passou" só pelo exit code.
- O CI define `DB_DATABASE=":memory:"` (`.github/workflows/ci.yml`), mas `Connection.php:33-36` prefixa caminhos que não começam com `/`. Resultado: cria um **arquivo** chamado `:memory:` na raiz. Reproduzido na cópia: o healthcheck dá `[FAIL] Database` e o build para na etapa 1.
- **Correção:** Pest + `RefreshDatabase` em MySQL 8, com testes de feature HTTP para `/agenda` (dia/semana/data, cross-tenant, papel, timezone na virada 23:59 BRT, empty state) e testes de browser (Dusk ou Playwright) em 390/768/1280/1440 px.

### Médio

**M1. Consulta que não usa índice, e nenhum índice em `appointments`.**
- `AppointmentService.php:83` usa `date(a.scheduled_at) = date(:date)`, que é non-sargable. A migration `…000004…php:14-29` não cria índices. No SQLite: `PRAGMA index_list('appointments')` vazio e `EXPLAIN` → `SCAN a` + `TEMP B-TREE FOR ORDER BY`. No MySQL/InnoDB as FKs criam índices simples em `clinic_id`, `patient_id` e `professional_id`, mas nenhum em `scheduled_at`.
- **PoC (200 mil linhas, 50 clínicas):** 14,3 ms por consulta hoje. Com intervalo `>= from AND < to` e índice `(clinic_id, scheduled_at)`: 0,04 ms. Mantendo `date()` mesmo com o índice: 0,6 ms. Os números são de SQLite e servem só como ordem de grandeza; **não medi no MySQL**.
- **Correção:** índices `(clinic_id, professional_id, scheduled_at)` e `(clinic_id, scheduled_at)` e consulta por intervalo meio-aberto em UTC.

**M2. Validação de entrada ausente em `schedule()`: data inválida e duração negativa são aceitas, e essas consultas somem da agenda.**
- `AppointmentService.php:20-39`. **PoC:** `scheduled_at='amanhã de manhã'` e `duration=-5` foram gravados (id 5) e nunca aparecem em nenhum dia.
- No MySQL em modo estrito, a data inválida seria rejeitada (erro 1292), mas a duração negativa passa (`INTEGER` com sinal).
- **Correção:** validação estrita (formato e fuso, `1 ≤ duration ≤ 480`, `type` numa lista) e `CHECK (duration_minutes > 0)`. MySQL 8.0.16+ aplica `CHECK`; MariaDB e MySQL 5.7, se a HostGator entregar isso, não aplicam (veja o inventário, seção 6).

**M3. Estados sem semântica visual nem rótulos em pt-BR, e falta o `no_show`.**
- `DashboardController.php:168-169` e `:410` mostram `type`/`status` crus com `badge-info` fixo. O schema (`…000004…php:21`) e a lista do serviço (`AppointmentService.php:57`) não têm `no_show`.
- **Correção:** um enum com rótulo pt-BR, ícone e token (agendada = info, confirmada = success, realizada = neutral/success, cancelada = error, falta = warning), sempre com texto (DS §3.2). Adicionar `no_show`.

**M4. Acessibilidade (WCAG 2.2 AA) além do reflow.**
- O `<h1>` não diz a data (`:143`; 2.4.6/1.3.1). A tabela não tem `<caption>` (`:149`).
- Item ativo da navegação só por cor e fundo, sem `aria-current="page"` (`professional-shell.php:25`; o DS pede "indicador não cromático").
- Sem *skip link*. A 768 px, Status fica fora da área visível com a barra de rolagem escondida (o conteúdo é rolável, mas sem pista visual).
- Não há grade de calendário, então não há navegação por teclado a avaliar. Quando a semana for criada: grade como `<table>` com `<th scope>` por dia e hora, eventos como links focáveis com `aria-label` (por exemplo, "10:00–11:00, Ana, Confirmada, Primeira consulta") e alvos de pelo menos 44 px (DS).
- O que está OK: `:focus-visible` existe (`clinical-botanical.css:89-92`); contraste do badge info 5,50:1, do `th` 4,56:1 e do contorno de foco sobre branco 5,00:1. Sobre o navy da sidebar o contorno dá 3,00:1, no limite.

**M5. Empty state sem próximo passo e nenhum estado de erro.**
- `DashboardController.php:161`: "Nenhuma consulta agendada para hoje." sem CTA (o DS §7 pede "explicação e próximo passo").
- Uma exceção em `listByDay` (por exemplo, falha de banco) não tem `try/catch` nem `set_exception_handler` no projeto (verificado por grep), o que dá erro fatal ou página em branco 500.
- Loading não se aplica (renderização no servidor).
- **Correção:** empty state com "Agendar consulta" (quando a NI2-81 tiver UI), página de erro amigável com ID de correlação e log sem PII.

**M6. `getNextAppointment` expõe `notes` (observações internas) ao paciente e pode devolver consultas passadas.**
- `PatientPortalService.php:324-331` faz `SELECT a.*`, sem filtro `scheduled_at >= agora`, e ordena por string (formatos misturados, PoC). A PoC mostrou `notes` no JSON de `/api/v1/patient/home`.
- **Correção:** lista explícita de colunas, `scheduled_at_utc >= UTC_TIMESTAMP()` e DTO de API.

**M7. O JOIN da agenda não reforça o tenant do paciente.**
- `AppointmentService.php:81` faz `JOIN patients p ON p.id = a.patient_id` sem `AND p.clinic_id = a.clinic_id`. Hoje é defesa em profundidade, mas combinado com referências cruzadas como a de A3, a agenda poderia mostrar nome e telefone de paciente de outra clínica.
- **Correção:** adicionar a condição, ou usar FK composta `(clinic_id, patient_id)`.

### Baixo

**B1.** `SELECT a.*` em `listByDay` (`AppointmentService.php:79`) traz `notes` e colunas que a view não usa, e não há limite. Selecionar só o necessário e limitar a semana, por exemplo a 500 itens com aviso.

**B2.** HTML e estilos inline no controller (`DashboardController.php:142-176`), fora dos componentes do DS. O botão "Ver agenda" tem `min-height: 36px` (`:389`), abaixo dos 44 px do DS (WCAG 2.5.8 AA exige 24 px, então não viola, mas viola o DS).

**B3.** Logout por `GET /logout` (`professional-shell.php:66`, `index.php:71`) permite CSRF de logout. Usar `POST` com token. **CSRF da agenda: não se aplica**, porque não há mutação (drag-and-drop/reagendar não existem). O `Router.php:37-45` já exige token em todo `POST` fora de `/api/`, o que cobre mutações futuras.

**B4.** Telefone exibido em toda linha da agenda (`:167`). Para minimização (LGPD) e para o mobile ("detalhes progressivos", DS §7), mostrar o telefone no detalhe.

**B5.** Primeiro dia da semana sem definição. O CLDR para `pt_BR` usa **domingo** e a ISO 8601 usa **segunda**. Decidir (sugestão: configurável por clínica, padrão segunda para agenda clínica) e calcular os limites com `IntlCalendar::getFirstDayOfWeek()` ou configuração, em UTC.

---

## 5. Checklist pedido

| Tema | Situação |
|---|---|
| Isolamento de tenant (leitura da agenda) | **OK.** `WHERE a.clinic_id` vem da sessão (PoC: a consulta da clínica B não aparece) |
| IDOR na agenda | Não há parâmetro de ID nem de data em `/agenda`, então não há IDOR. **Escrita:** A3 (referência cruzada de profissional) e C2 (token de paciente) |
| Profissional vendo agenda de outro | **Sim, qualquer membro vê tudo** (A5). Falta a regra |
| Authz por papel | Inexistente na web (A5) |
| CSRF | Não se aplica à agenda (sem mutações). Proteção global para `POST` web existe. Logout por GET (B3) |
| XSS | **OK.** `htmlspecialchars` em nome, telefone, tipo e status (PoC `<img onerror>` virou texto). O PHP 8.1+ usa `ENT_QUOTES` por padrão. Não há CSP (não verificado em servidor real) |
| Data/timezone | **Falha** (A1). Semana ISO ou domingo sem definição (B5) |
| Sobreposição | **Falha** (A2) |
| Performance / índices | **Falha** (M1) |
| Paginação / limites | Inexistente (B1). Fica crítico quando houver a semana |
| Responsividade | **Falha** (C1, screenshots) |
| Acessibilidade WCAG 2.2 AA | Falha em reflow (C1) e parcial no resto (M4) |
| Design System Premium | Tokens, Inter e `:focus-visible` OK; botões `.btn` com 44 px OK. Status sem semântica (M3), navegação móvel inexistente (C1), inline styles e 36 px (B2), empty state sem próximo passo (M5) |
| Estados empty/loading/error | Empty parcial, error inexistente, loading não se aplica (M5) |
| Qualidade dos testes | **Falha** (A6, seção 6) |

---

## 6. Testes: o que rodou e as lacunas

**`php -l`:** 84 arquivos `.php` (`src`, `app`, `tests`, `bin`, `views`, `public`, `database`) sem erro de sintaxe, no PHP 8.4.26. O alvo é 8.3; não testei com 8.3.

**Execução de cada arquivo de teste** (cópia de trabalho, com `zend.assertions=-1` e com `=1`, mesmo resultado nos dois):
- **12 de 17 terminam com exit 0:** `clinical_safety…`, `cross_tenant_offensive`, `delivery_e2e…`, `foundation`, `golden_path`, `onda_1..4`, `phases_4_5_6`, `privacy…`, `security_lgpd`.
- **5 falham com erro fatal** quando rodados isolados com `DB_DATABASE=":memory:"`:
  - `patient_mobile_api_integration` (`no such table: personal_access_tokens`)
  - `patient_portal_e2e_suite` (`no such table: patient_invitations`)
  - `verifiable_multilateral_audit_suite` (`no such table: patients`)
  - `release_gate_and_fail_closed` (`escapeshellarg(): … false given`, linha 97)
  - `replatform_infrastructure` ("Spike deve aprovar o ambiente", linha 40)

  Os três primeiros dependem de um banco já migrado e do bug do `:memory:` (A6).
- **`bin/build_release.sh` com as variáveis do CI:** para na etapa 1 (`[FAIL] Database`). Rodando de novo após `php bin/migrate.php` com o SQLite padrão, passa os 8 primeiros arquivos e para em `replatform_infrastructure_test.php` (o spike exige o ambiente HostGator). O gate "APPROVED" **não se reproduz** nesta máquina.
- O CI (`.github/workflows/ci.yml`) declara um serviço MySQL 8 mas roda os testes em SQLite, e o repositório remoto e o pipeline ainda não existem (NI2-148 *A fazer*). Então **não há evidência de CI verde**.

**Cobertura da agenda:**

| Lacuna | Situação |
|---|---|
| `listByDay` / `GET /agenda` | **Nenhum teste** |
| Isolamento cross-tenant da **leitura** da agenda | Nenhum teste. `cross_tenant_offensive_test.php:144-151` cobre só `schedule`/`updateStatus` |
| Filtro por profissional / papel | Nenhum teste |
| Virada de dia (23:59 BRT) e fuso | Nenhum teste |
| Sobreposição e concorrência | Nenhum teste |
| Transições de estado inválidas | Nenhum. `phases_4_5_6_test.php:45-46` testa `scheduled→completed`, que deveria ser **inválida**, e só com `assert()` |
| Empty/erro, responsividade e a11y | Nenhum. A camada "HTTP" da NI2-175 (`verifiable_multilateral_audit_suite_test.php:142-180`) recria um `Router` dentro do processo e só cobre `/pacientes`. Browser real aparece como `NOT_TESTED` em `docs/delivery/QUALITY_GATE_MATRIX.json` |
| Asserções | 214 `assert()` em 13 arquivos. Só `patient_mobile_api_integration_test.php` (`assertCondition`) e `release_gate…` usam verificação que não depende de `zend.assertions` |

**Testes mínimos sugeridos** (Pest, MySQL 8): `AgendaDayViewTest`, `AgendaWeekViewTest` (limites da semana conforme B5), `AgendaTimezoneBoundaryTest` (consulta às 23:30 BRT aparece no dia certo; servidor em UTC), `AgendaTenantIsolationTest` (A não vê B; 404 para ID de B), `AgendaProfessionalPolicyTest`, `AppointmentOverlapTest` (incluindo duas requisições concorrentes), `AppointmentStateMachineTest`, `AgendaEmptyAndErrorStateTest`, e browser em 390/768/1280/1440 px com axe-core.

---

## 7. Mapeamento para o plano Laravel 13 (inventário v1.1)

| Código atual | Decisão | Destino em Laravel |
|---|---|---|
| `AppointmentService::listByDay` (`:75-88`) | **REESCREVER** | `AppointmentQuery::forRange(CarbonImmutable $fromUtc, $toUtc, ?Professional)` com scope `BelongsToTenant`, colunas explícitas e índices compostos |
| `AppointmentService::schedule/updateStatus` | **MIGRAR** (regras novas) | `Models\Appointment` (`starts_at`/`ends_at` UTC, cast `immutable_datetime`), `enum AppointmentStatus` com `canTransitionTo()`, `ScheduleAppointment` action com lock por profissional, `appointment_status_history` |
| `DashboardController::agenda` + HTML inline | **REESCREVER** | `AgendaController@index` (`view`, `date`, `professional`) + `AgendaRequest` (validação) + Blade `x-ds.agenda.day` / `x-ds.agenda.week` / `x-ds.status-badge`; layout responsivo do DS (NI2-3) |
| Bloco de agenda do dashboard (`:335-415`) | **REESCREVER** | Componente Blade reaproveitando `AppointmentQuery` |
| `TenantGuard::assert*` | **SUBSTITUIR** | `TenantScope` + `exists` com `tenant_id` + `AppointmentPolicy` (corrigindo a ambiguidade de A3) |
| `$requireAuth` / sessão sem papel | **SUBSTITUIR** | Fortify + middleware `auth` + `SetCurrentTenant` + Policies |
| API do paciente (`index.php:188-287`) | **REESCREVER** | Sanctum com login por convite/OTP, `expires_at`, rate limit, `AppointmentResource` sem `notes` (C2, M6) |
| Migration `appointments` | **REESCREVER** | `tenant_id`, `professional_id` (FK composta), `starts_at`/`ends_at` UTC, `status` com `no_show`, índices `(tenant_id, professional_id, starts_at)` e `(tenant_id, starts_at)` |
| `clinical-botanical.css` (tokens) | **REAPROVEITAR tokens** | Portar para o build Vite (no CI; a HostGator não tem Node) e adicionar breakpoints do DS |
| `app/` atual (3 classes) | Reaproveitar só as calculadoras puras | Não tem relação com a agenda. `TenantManager` estático deve virar serviço com escopo de request |
| Testes `assert()` da agenda | **DESCARTAR** | Pest Feature e Browser (seção 6) |

Mesmo com essas mudanças, a estimativa do inventário para a NI2-83 continua **M**, porque é praticamente uma reescrita da UI. Dependências: NI2-3 (DS em Blade, layout responsivo), NI2-2 (Policies/tenancy) e a correção do modelo em NI2-81/82. Em termos de infraestrutura, a agenda não precisa de fila nem de websocket (lembretes ficam com a NI2-95, com cron de 15 min).

---

## 8. Lista de correções priorizada

| # | Ação | Achados | Esforço |
|---|---|---|---|
| 1 | Bloquear o login do paciente sem credencial (convite/OTP, `expires_at`, rate limit) e remover `notes` da API | C2, M6 | M |
| 2 | Corrigir `assertProfessionalBelongsToClinic` (só `professionals.id`, placeholders distintos) e os testes que passam `user_id` | A3 | P |
| 3 | Definir fuso (app e clínica), gravar em UTC, validar entrada e consultar por intervalo UTC | A1, M2 | M |
| 4 | Máquina de estados + `no_show` + histórico + `rowCount()` na confirmação | A4, M3 | M |
| 5 | Detecção de sobreposição transacional + `ends_at` + sinalização na agenda | A2 | M |
| 6 | Índices compostos + consulta por intervalo + colunas explícitas + JOIN com tenant | M1, M7, B1 | P |
| 7 | Agenda dia/semana com navegação (`date`, `view`, Hoje), links para paciente e consulta, data no título | C1, critérios 1-4 | M |
| 8 | Layout responsivo do shell (drawer/bottom-nav < 768 px; dia em cartões no mobile; semana ≥ 1024 px) | C1, critério 6 | M |
| 9 | A11y: `aria-current`, caption, skip link, grade semanal acessível, alvos de 44 px | M4, B2 | P |
| 10 | Empty state com CTA, página de erro e handler global | M5 | P |
| 11 | Regra de visibilidade por profissional/papel na matriz RBAC + Policy + filtro | A5 | P (depois da decisão) |
| 12 | Testes Pest/HTTP/browser da seção 6. Corrigir `:memory:` em `Connection.php:33-36` e trocar `assert()` | A6 | M |
| 13 | Logout por POST, telefone no detalhe, definição do 1º dia da semana | B3, B4, B5 | P |

Recomendação de processo (não executada, porque o Jira é só leitura): reabrir a NI2-83 ou vinculá-la à NI2-187 como aceite pendente. Rever também o *Feito* de NI2-81 e NI2-82.

---

## 9. O que não consegui verificar

- **MySQL 8:** não havia servidor MySQL disponível. Ficaram sem verificar o erro `HY093` do `:id` repetido com `EMULATE_PREPARES=false` (A3), o comportamento de `DATE()` e de literais com offset (A1), a rejeição de datas inválidas em modo estrito (M2) e os planos de execução e tempos (M1, medidos só no SQLite).
- **Fuso real do servidor e do MySQL na HostGator**, e se o `php.ini` de lá define `date.timezone`.
- **GitHub Actions:** não existe repositório remoto (NI2-148). Não sei se o `setup-php` do CI deixa `zend.assertions` em `-1` ou `1`. Localmente as duas opções dão o mesmo resultado, porque as falhas são fatais e não de asserção.
- **PHP 8.3:** lint e testes rodaram no 8.4.26.
- **Leitor de tela e dispositivos reais:** a11y avaliada por inspeção de código, contraste calculado e screenshots do Chrome headless. Não usei NVDA/VoiceOver nem axe.
- **CSP e headers de segurança em produção:** não foram avaliados em servidor real.
- **Comentários do Jira:** a ferramenta só informa a contagem (NI2-175 e NI2-178 têm 1 comentário cada) e eu não os li. Não li as histórias NI2-84 e NI2-85 além do aceite.
- **Se a HostGator entrega MySQL 8 ou MariaDB/5.7** (afeta `CHECK` e a estratégia de lock; continua "a confirmar no painel", conforme o inventário).

---

### Anexo: PoCs executadas (cópia de trabalho, SQLite)

| Script | Resultado |
|---|---|
| `seed.php` | Duas clínicas. Sobreposição aceita (`Overlaps aceitos: SIM`). Data inválida (`'amanhã de manhã'`, duração -5) aceita. ISO com offset aceito |
| `GET /agenda` (curl com login real da conta demo) | A clínica B não aparece. XSS escapado. `?date=` e `?view=week` ignorados (md5 idêntico). Consulta de 23:30 com offset não aparece no dia |
| `second_prof.php` | Consulta de outra profissional da mesma clínica aparece para a Dra. Camila |
| `prof_id_confusion.php` | Consulta da clínica 1 gravada com `professional_id` da clínica 2 |
| API do paciente (curl) | Token só com e-mail. `home` expõe `notes`. Consulta cancelada reconfirmada. ID inexistente → `success:true`. `expires_at` NULL |
| `perf.php` | 200 mil linhas: `SCAN` 14,3 ms vs. intervalo com índice 0,04 ms |
| Screenshots | `agenda_390.png` (conteúdo esmagado), `agenda_768.png` (colunas fora da tela), `agenda_1440.png` (desktop OK) |

Observação: a conta demo `camila@clyonutri.com.br` / `SenhaDemo123` é **criada automaticamente a cada request** em `public/index.php:27-39`, inclusive em produção. Foi o que permitiu o login nas PoCs. Isso é o bug NI2-172, que já existe.
