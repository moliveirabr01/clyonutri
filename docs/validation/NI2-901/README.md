# NI2-901 — assunto da redefinição de senha

17/11/2026, base v54.76/HEAD 6dba333.

## Reprodução e diagnóstico

O runner reproduziu AssertionError assunto em pt-BR (before.log). A mensagem gerada por PasswordResetService/PasswordResetEmail, capturada no ArrayMailer, tem assunto efetivo **Crie uma nova senha no Clyo Nutri** (after.log). O template atual usa esse texto claro em pt-BR; a asserção ainda exigia Redefinição de senha. Classificação: teste desatualizado; assunto do produto preservado.

## Correção e cobertura

Asserção atualizada para o assunto efetivo e título HTML correspondente; subject registrado sem token/dados do titular. Pré-condição de testing/SQLite adicionada antes das operações de limpeza. Logs temporários gravados no diretório isolado do teste.

Após o assunto, a suíte revelou que sua sequência esgotava legitimamente as cinco solicitações de recuperação e bloqueava o cenário de envio adiado. Adicionado controle que comprova bloqueio real/ausência de envio/resposta genérica. A fase seguinte usa janela expirada só do bucket do e-mail TESTE, sem desativar ou relaxar o limitador de produção.

Resultado da suíte: **48 asserções PASS**. Cobre assunto/link canônico, hash do token, expiração UTC/fusos, senha persistida, uso único, contas desconhecida/inativa, envio falho, resposta genérica, não exposição de token, header injection, agendamento de envio e configuração Mailer. ArrayMailer é transporte em memória real do serviço de envio para teste; não prova SMTP externo.

Evidências: before.log, before-runner.log, intermediate.log (falha adicional de rate limit), after.log, regressions.log.

## Validação conjunta e limites

`php tests/run_full_suite.php @docs/validation/NI2-901/regression-suites.txt`: 3 PASS e 1 FAIL (regressions.log). Password reset, portal e patient_mobile_api_integration passaram. ni2_481_transactional_emails_e2e_test falhou por PDOException: no such table booking_holds na fixture própria legada (AppointmentService.php:1651). Não se atribui essa falha às alterações das NI2-901/902, mas ela permanece pendente e impede declarar a seleção inteiramente verde.

PHP syntax dos dois testes e git diff --check PASS. Gate core amplo e suíte inteira não repetidos. As duas suítes corrigidas foram retiradas de known_failures_full.tsv e incluídas em core_modules.txt. Alterações anteriores preservadas.

Coreutils timeout ausente no host: runner executado com adaptador temporário real ../NI2-895/timeout-adapter.py no PATH, que preserva o código do subprocesso e aplica timeout (124 em expiração). Runner original não alterado.

SMTP externo, HTTP/browser, app mobile real, upload HTTP de arquivo, MySQL e Apache/HostGator NOT TESTED. Nenhum código de produção ou .env alterado; sem pacote/deploy. Não foi declarado PASS de infraestrutura externa, WCAG integral ou homologação de todas as histórias do épico.
