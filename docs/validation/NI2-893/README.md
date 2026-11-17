# NI2-893 — Correções do code review

Implementação na codebase canônica. Alterações anteriores preservadas. Sem deploy, migração ou alteração de versão de release.

- Rodapé administrativo lê `version` do `RELEASE_INFO.json` instalado e escapa o valor. Metadado ausente ou inválido mostra “Versão indisponível”. A divergência anterior entre RELEASE_INFO e BuildIdentity não é corrigida por esta história.
- Removidos botão, campo, QR, temporizador e JavaScript de cópia de link do consentimento na sala de consulta. Mensagem explica resposta pelo portal e envio direto ao paciente. Pedido e revogação preservados; backend de OTP/portal não alterado. Cache do JavaScript atualizado.
- Comprovante público e API usam agora o código não sequencial de oito caracteres derivado do hold_token aleatório (CR875-11, já integrado na v54.76). A resposta anônima não entrega appointment_id/patient_id. Não foi necessária nova coluna ou migração; o código não autoriza operações. Data, hora e modalidade preservados.
- Mensagem de cancelamento dos exames condicionada explicitamente a `cancelled`, em vez de entrar no `else` para rascunhos.
- Nenhum link `/relatorios` encontrado em src, views, public e routes. Não houve link a remover nessa varredura.

## Evidências anteriores

- `php tests/ni2_893_finishing_test.php`: **12 PASS, 0 FAIL**; renderização real das views com SQLite isolado, quatro estados de exames, data/motivo no cancelado, sala sem copiar link, versão do rodapé, comprovante sem ID e sintaxe do JavaScript renderizado. Incluído no perfil core de `bin/run-tests`.
- `php -d zend.assertions=1 -d assert.exception=1 tests/v5461_ni2_830_consentimento_test.php`: **62 PASS, 0 FAIL**, incluindo OTP/portal, acesso e revogação.
- `php -d zend.assertions=1 -d assert.exception=1 tests/self_service_booking_test.php`: **FAIL** na limpeza da fixture, linha 113 (`DELETE FROM appointments` antes do histórico), com `FOREIGN KEY constraint failed`. Não classificamos a suíte completa de agendamento como aprovada. Nenhum arquivo de domínio ou banco foi alterado pela correção da NI2-893.
- `php -l` nas quatro views alteradas: sem erros.
- `node --check public/assets/js/realtime-transcription.js`: sem erros.
- `git diff --check`: sem erros.
- HTTP/browser e verificador E2E completo de links: **NOT TESTED**. A tentativa anterior de iniciar o servidor nesta sessão foi bloqueada pelo ambiente (`Operation not permitted` ao abrir porta local). Renderização direta não equivale a aprovação E2E.

Logs das suítes nesta pasta. Nenhuma entrega externa ou publicação realizada.

## Revalidação em 17/11/2026 — execução atual

A implementação já estava presente. Não foram refeitas alterações no produto nem sobrescrito o trabalho local. Ampliado `tests/cr875_11_codigo_agendamento_test.php` para extrair links do menu lateral realmente renderizado no dashboard autenticado e requisitar cada um com a mesma sessão. Exige que haja links, não haja `/relatorios`, e cada destino responda HTTP 200. O teste continua incluído no perfil core.

Comandos e resultados (códigos de saída zero):

- `php tests/ni2_893_finishing_test.php`: **12 PASS, 0 FAIL**, renderização dos quatro estados de exames, data/motivo no cancelado, versão instalada, ausência do botão de cópia e sintaxe do JavaScript do comprovante. Log: `revalidation-views.log`.
- `php -d zend.assertions=1 -d assert.exception=1 tests/v5461_ni2_830_consentimento_test.php`: **62 PASS, 0 FAIL**, persistência/OTP/portal/revogação e isolamento de consentimento. Log: `revalidation-consent.log`.
- `php tests/cr875_11_codigo_agendamento_test.php`: **31 PASS**, servidor PHP real e SQLite migrado isolado em `/tmp/ni2-433-*`. Login, ativação pela configuração, **16 links reais de menu HTTP 200**, duas consultas persistidas com IDs consecutivos e códigos públicos distintos, ausência de IDs nas respostas, idempotência e rejeição do código como token de acesso/alteração. Log: `revalidation-booking-http.log`.
- Busca em src/views/public/routes: nenhum `/relatorios` encontrado. Não foi criada uma página de relatório fora do escopo.
- `php -l tests/cr875_11_codigo_agendamento_test.php` e `git diff --check`: PASS.

Total: **105 verificações aprovadas**. O host PHP 8.5 emite avisos Deprecated de curl_close no teste HTTP; não houve falha das verificações. A varredura HTTP cobre o menu do consultório da fixture autenticada; não é um crawler de todas as páginas, perfis ou links dinâmicos. Navegador visual, MySQL e Apache/HostGator NOT TESTED. A falha histórica na limpeza da suíte self_service_booking_test permanece documentada acima; essa suíte ampla não foi repetida nem declarada aprovada. Sem pacote, deploy ou mudança de versão.
