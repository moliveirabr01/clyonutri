# NI2-433: autoagendamento self-service, relatório de evidências

- **Data:** 20/10/2026
- **Base:** commit `7bc5198` (branch `merge/v11-teleconsulta`)
- **Status no Jira:** sem alteração (continua "A fazer"), aguardando validação independente.

## O que foi priorizado

Uma funcionalidade comprovadamente funcional: **link público do profissional → horários reais → reserva temporária → confirmação na agenda canônica**. Ela vem acompanhada da configuração pela clínica e da remarcação/cancelamento pelo paciente autenticado no app (API).

O código de autoagendamento já existia (commit `c7fd695`). A auditoria encontrou falhas que impediam declarar o fluxo como funcional:

| # | Problema encontrado no código existente | Correção |
|---|---|---|
| 1 | Toda clínica ficava pública por padrão (`is_enabled = true` sem configuração) | Opt-in: desligado até a clínica ligar em Configurações (só owner/admin) |
| 2 | `/agendar` listava **todos os profissionais de todas as clínicas**, com e-mail no HTML | Página contextual `/agendar/{id}`; 404 para profissional não apto; sem e-mail |
| 3 | Sem jornada configurada, inventava seg–sex 8–18 | Sem configuração, sem horários |
| 4 | Duas confirmações simultâneas do mesmo token criavam **duas consultas** (sem trava) | Trava do profissional + `UPDATE … WHERE status='held'` |
| 5 | Hold aceitava qualquer horário (madrugada, fora da grade), duração e tipo escolhidos pelo cliente | Horário precisa ser um slot oferecido; duração do cadastro; tipos e modalidades validados |
| 6 | Remarcação aceitava qualquer horário, sem trava e sem considerar holds | Mesmo motor de disponibilidade, trava e checagem de holds |
| 7 | Cancelar/remarcar pelo app quebrava sempre: `MobileWebSession::COOKIE_NAME` não existe (erro fatal → 500) | Autenticação igual à da API do paciente (Bearer ou cookie com checagem de origem) |
| 8 | Visitante anexava consulta à ficha de outro paciente só digitando o e-mail dele | Vínculo só com e-mail **e** nome iguais |
| 9 | Hold guardava nome, e-mail, telefone e CPF em claro; CPF ia para as notas do paciente | Hold sem dados pessoais; CPF não coletado |
| 10 | Sem consentimento registrado no servidor (checkbox só no navegador) | Aceite do termo `clinical_care` vigente obrigatório e gravado em `patient_consents` |
| 11 | Sem limite contra abuso (bloquear a agenda com reservas em massa) | Limite por IP (hash): slots 120, holds 10, confirmações 20 a cada 10 min |
| 12 | Página: trocar a data rápido misturava horários de dias diferentes (botões duplicados; reserva no dia errado) | Só a resposta mais recente é usada |
| 13 | Página: CSS inexistente (`design-system.css` 404) e 8 falhas de contraste (axe) | Link removido e cores ajustadas (axe sem violações) |
| 14 | Conflito comparava horários com `strtotime` (fuso do PHP) e varria todas as consultas do profissional | Fuso da aplicação e janela por data |

## Como reproduzir

```bash
# 1) SQLite isolado + navegador (Chromium) + axe
PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_433_self_service_booking_real_test.cjs

# 2) MySQL 8 real (banco ni2_433_test_* criado e apagado pelo teste)
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=… DB_PASSWORD=… \
PLAYWRIGHT_MODULE=… AXE_MODULE=… CHROMIUM_PATH=… node tests/ni2_433_self_service_booking_real_test.cjs

# 3) Teste de domínio (atualizado para opt-in e consentimento)
php -d zend.assertions=1 -d assert.exception=1 tests/self_service_booking_test.php
```

O teste real sobe `public/index.php` em `php -S` com `APP_ENV=production` e usa:
- **Banco:** real e isolado.
- **Logins:** reais (dona e equipe da clínica), com CSRF.
- **Recepção:** marcação pela agenda interna via `AppointmentService` real, num processo PHP separado.
- **Concorrência:** requisições HTTP concorrentes.
- **Navegador:** Chromium com a CSP ativa.

Uso de fixture, declarado:
- **Token do paciente:** é criado direto no banco, com o mesmo formato do primeiro acesso (só o hash é gravado).
- **Vencimento do hold:** o prazo é movido para o passado, porque o TTL real é de 15 minutos.

## Resultados

| Execução | Resultado | Log |
|---|---|---|
| SQLite + navegador + axe | **69 PASS, 0 FAIL** | `teste-real-sqlite.log` |
| MySQL 8.0.46 + navegador + axe | **69 PASS, 0 FAIL** | `teste-real-mysql8.log` |
| `tests/self_service_booking_test.php` | **48 PASS, 0 FAIL** | — |
| 57 suítes PHP (legadas + agenda + NI2-9/437/439), antes x depois | **44 = 44 PASS**, mesmas 13 falhas antigas, nenhuma nova | `regressao-suites.txt` |
| E2E NI2-437 / navegador NI2-444 | 48 PASS / 16 PASS | — |

Concorrência medida (MySQL 8):

| Cenário | Resultado |
|---|---|
| 8 holds simultâneos no mesmo horário | 1× 201 e 7× 409 |
| 6 confirmações simultâneas do mesmo token | 6× 200 → **1 consulta** |
| Recepção (processo PHP) × paciente (HTTP) no mesmo horário, 3 rodadas | 1 consulta por horário; a outra parte recebe conflito |
| Limite de abuso, 11 holds do mesmo IP | a 11ª recebe 429 |

Captura do funil concluído no celular (390 px): `ni2-433-confirmado-390.png`.

## Critérios: situação

Legenda:
- **PASS:** executado com evidência.
- **FAIL:** executado e falhou.
- **NOT TESTED:** não executado.
- **BLOCKED:** depende de algo indisponível.
- **NÃO IMPLEMENTADO:** fora do que foi entregue agora.

### NI2-434: contrato de estados

| Critério | Situação | Evidência |
|---|---|---|
| 1a. Contrato OpenAPI e diagrama de transições | **PASS** (atualizados para o comportamento real, v1.1.0) | `docs/contracts/…openapi.yaml`, `docs/architecture/…STATE_MACHINE.md` |
| 1b. Revisados por backend/frontend/mobile | **BLOCKED**: precisa de revisão humana | — |
| 1c. Estados inválidos rejeitados no servidor | **PASS** | confirmar hold vencido → 410; cancelar cancelada → 409; remarcar para pausa/ocupado → 409 |
| 2. Confirmação gera o agendamento canônico e o histórico; sem agenda paralela | **PASS** | consulta em `appointments` + `appointment_status_history` (null → scheduled); horário some da agenda pública e da interna |
| 3a. Teleconsulta com estado distinto | **PASS** (domínio) | `self_service_booking_test.php` cria a sessão de teleconsulta na confirmação; a modalidade desligada pela clínica é recusada (HTTP) |
| 3b. Pagamento opcional | **NÃO IMPLEMENTADO** | só `payment_status = not_required` |
| 3c. Notificações (estado e envio) | **NÃO IMPLEMENTADO** | `notification_status` existe, mas nada é enviado |
| 4. Contract tests: sucesso, conflito, expiração, repetição, cancelamento e negação de autorização | **PASS** | teste real (HTTP) |
| Testes com MySQL 5.7 | **NOT TESTED**: não há MySQL 5.7 disponível neste ambiente (a HostGator usa 5.7.44). Não houve mudança de banco nesta entrega. | — |
| Evidência em CI | **NOT TESTED**: o projeto não tem CI | — |

### NI2-433: Definition of Done do épico

| Item | Situação |
|---|---|
| Fluxo ponta a ponta auditado no **web responsivo** | **PASS** (navegador 390 px, funil completo, auditoria sem dados pessoais) |
| Fluxo ponta a ponta no **app** | Remarcar e cancelar pela API do app: **PASS**. Tela de agendamento no app: **NÃO IMPLEMENTADO** (o app Expo não tem a funcionalidade). |
| Testes integrados e concorrentes com persistência real | **PASS** (SQLite e MySQL 8) |
| Mesmo horário não confirmado duas vezes | **PASS** |
| Autorização negativa entre clínicas | **PASS** (página, horários, hold, cancelar/remarcar de outro paciente) |
| Acessibilidade e estados de erro | **PASS** na página inicial (axe WCAG 2 A/AA, 0 violações), sem rolagem lateral e com estados de erro pela API e pela tela. As demais etapas do funil não passaram por varredura axe própria (**NOT TESTED**). |
| Métricas sem dados pessoais | Auditoria sem dados pessoais: **PASS**. Métricas de funil (conversão, tempo, no-show por canal): **NÃO IMPLEMENTADO**. |
| Rollback/backup e critérios de liberação | Nenhuma mudança de banco: o rollback é voltar os arquivos. Gate de release completo: **NOT TESTED** nesta entrega. |
| Documentação de APIs/migrações/operação | **PASS** (OpenAPI 1.1.0, documento de estados, este relatório) |
| Feriados, exceções, bloqueios, buffers | **NÃO IMPLEMENTADO** (só jornada semanal, pausa e consultas existentes) |
| Lista de espera, lembretes por fila/cron, pagamento antecipado | **NÃO IMPLEMENTADO** |
| Entrada pelo portal/app autenticado do paciente | **NÃO IMPLEMENTADO** (entrada só pelo link público) |

## Release

**Não aprovada.** Faltam:
- Validação independente.
- Testes em MySQL 5.7.
- Gate de homologação completo.
- Revisão do contrato por frontend e mobile.

Para usar em produção depois da aprovação: subir os arquivos (não há SQL novo). Em seguida, cada clínica liga em **Configurações → Agendamento online** e divulga o link `/agendar/{id}`.
