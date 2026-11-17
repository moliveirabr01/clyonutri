# ClyoNutri II · v54.73 · LEIA-ME para a HostGator

**Base:** v54.72 · **História:** NI2-894 · **Mudança de banco:** nenhuma

## O que muda para o paciente

| Situação | Antes | Agora |
|---|---|---|
| Consulta agendada | 2 avisos; um dizia "({timezone}), modalidade {modality}" | 1 aviso com data, hora, fuso e modalidade |
| Consulta confirmada pelo consultório | 2 avisos de confirmação | 1 aviso |
| Paciente remarca pelo app | "reagendada para {date} às {time}" (também para o nutricionista) | data e hora preenchidas |

Remarcar e cancelar pelo consultório continuam com um aviso cada. Paciente sem e-mail nem app continua sem receber nada. Avisos já enviados não são reenviados.

Proteção nova: se algum aviso ficar com um marcador sem valor (texto entre chaves), ele não é enviado. O bloqueio fica registrado na auditoria como `NOTIFICATION_BLOCKED_UNFILLED_PLACEHOLDER`, sem dados do paciente.

## Pacotes

| Arquivo | Uso |
|---|---|
| `clyonutri-v54-73-hostgator.zip` | Completo. Inclui também a v54.72 (NI2-879). |
| `clyonutri-v54-73-parcial-hostgator.zip` | Só os arquivos alterados sobre a **v54.72**. Use apenas se a produção já estiver na v54.72. |

Se a produção ainda estiver na v54.71, use o **completo** (ou suba o parcial da v54.72 e depois o da v54.73).

## Passo a passo (gerenciador de arquivos do cPanel)

1. Backup da pasta do site e do banco (phpMyAdmin).
2. Envie o zip e extraia por cima. **Não substitua o `.env`.**
3. Banco: nada a fazer.
4. Confira no rodapé do console: **v54.73**.

## Conferência rápida (paciente TESTE com e-mail)

1. Agende uma consulta para o paciente TESTE.
2. No phpMyAdmin: `SELECT event_type, body FROM notification_events WHERE recipient_type='patient' AND recipient_id=<id do paciente> ORDER BY id DESC LIMIT 3;`
3. Deve haver **um** `appointment.scheduled`, com o fuso e "modalidade presencial" (ou "online"), sem nada entre chaves.

## Se precisar voltar

Reenvie o pacote da v54.72 por cima. Não há mudança de banco.

## Arquivos alterados em relação à v54.72

- `src/Domain/Appointment/AppointmentService.php`: sem o segundo aviso ao agendar e ao confirmar
- `src/Domain/Notification/NotificationService.php`: trava de marcador sem valor
- `src/Domain/Booking/PatientSelfService.php`: data e hora no aviso da remarcação feita pelo paciente
- `src/Core/Release/BuildIdentity.php`, `RELEASE_INFO.json`, `config/build-manifest.json`: versão v54.73
- `bin/run-tests`: suíte nova no gate (o runner vai no pacote, os testes não)

Fora do pacote: `tests/ni2_894_aviso_agendamento_test.php` e o ajuste em `tests/ni2_481_transactional_emails_e2e_test.php`.
