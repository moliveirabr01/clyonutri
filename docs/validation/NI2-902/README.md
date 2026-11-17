# NI2-902 — autoload e contratos atuais do portal

17/11/2026, base v54.76/HEAD 6dba333.

## Reprodução e avaliação do restante

Reproduzido Class ClyoNutri\Core\Database\ProfiledPdo not found (before.log). O teste incluía manualmente um conjunto antigo de arquivos, sem Composer. Corrigido para vendor/autoload.php; a execução então revelou no such table patient_invitations (intermediate.log). Essa tabela e offline_sync_queue não pertencem às migrations atuais; não foram criadas estruturas artificiais para fazer a suíte passar. O cenário de evolução também usava weight, substituído pelo catálogo atual weight_kg (intermediate-metric.log).

## Correção

Bootstrap canônico via Composer e pré-condições de asserções habilitadas/testing/SQLite isolado. A suíte passou a criar duas clínicas/profissionais/paciente próprios pelo AuthService/PatientRepository, sem exclusões globais ou dependência de IDs do seed.

Fluxos atualizados para serviços de produção existentes: PatientOnboardingService com patient_app_invitations/hash/uso único/senha/aceite legal; FoodDiaryService; evolução com liberação explícita weight_kg; metadados de documentos; agenda/confirmação; chat; reenvio idempotente de refeição/hidratação pelas operações atuais (não pela fila de lote legada). Não houve mudança nos serviços de produção nem novas tabelas de teste.

Resultado: **27 verificações PASS**, incluindo autoload/instância ProfiledPdo, hash do convite, senha persistida, aceite, token consumido recusado, entradas inválidas do diário, métrica não liberada recusada, distinção clínica/autorrelato, metadados de documento, consulta, chat, não duplicação e barreira entre clínicas.

O cenário de receitas sem plano continua verificando ausência de substituições fictícias; não constitui teste de uma prescrição alimentar completa. Documentos exercitam somente metadados/listagem, sem upload de bytes. Inspeção de tokens/propriedades mobile não foi descrita como homologação WCAG. A saída antiga APROVADO 100%/todas histórias homologadas foi substituída por resultado local e limites explícitos.

Evidências: before.log, intermediate.log, intermediate-metric.log, after.log e regressions.log.

## Validação conjunta e limites

`php tests/run_full_suite.php @docs/validation/NI2-901/regression-suites.txt`: 3 PASS e 1 FAIL (regressions.log). Password reset, portal e patient_mobile_api_integration passaram. ni2_481_transactional_emails_e2e_test falhou por PDOException: no such table booking_holds na fixture própria legada (AppointmentService.php:1651). Não se atribui essa falha às alterações das NI2-901/902, mas ela permanece pendente e impede declarar a seleção inteiramente verde.

PHP syntax dos dois testes e git diff --check PASS. Gate core amplo e suíte inteira não repetidos. As duas suítes corrigidas foram retiradas de known_failures_full.tsv e incluídas em core_modules.txt. Alterações anteriores preservadas.

Coreutils timeout ausente no host: runner executado com adaptador temporário real ../NI2-895/timeout-adapter.py no PATH, que preserva o código do subprocesso e aplica timeout (124 em expiração). Runner original não alterado.

SMTP externo, HTTP/browser, app mobile real, upload HTTP de arquivo, MySQL e Apache/HostGator NOT TESTED. Nenhum código de produção ou .env alterado; sem pacote/deploy. Não foi declarado PASS de infraestrutura externa, WCAG integral ou homologação de todas as histórias do épico.
