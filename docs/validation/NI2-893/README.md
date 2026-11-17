# NI2-893 — Correções do code review

Implementação na codebase canônica. Alterações anteriores preservadas. Sem deploy, migração ou alteração de versão de release.

- Rodapé administrativo lê `version` do `RELEASE_INFO.json` instalado e escapa o valor. Metadado ausente ou inválido mostra “Versão indisponível”. A divergência anterior entre RELEASE_INFO e BuildIdentity não é corrigida por esta história.
- Removidos botão, campo, QR, temporizador e JavaScript de cópia de link do consentimento na sala de consulta. Mensagem explica resposta pelo portal e envio direto ao paciente. Pedido e revogação preservados; backend de OTP/portal não alterado. Cache do JavaScript atualizado.
- Removida a apresentação do código sequencial no comprovante público. Data, hora e modalidade preservados. A opção permitida pela issue de não apresentar código dispensa coluna nova e SQL. Contrato de API e autorização não alterados.
- Mensagem de cancelamento dos exames condicionada explicitamente a `cancelled`, em vez de entrar no `else` para rascunhos.
- Nenhum link `/relatorios` encontrado em src, views, public e routes. Não houve link a remover nessa varredura.

## Evidências

- `php tests/ni2_893_finishing_test.php`: **12 PASS, 0 FAIL**; renderização real das views com SQLite isolado, quatro estados de exames, data/motivo no cancelado, sala sem copiar link, versão do rodapé, comprovante sem ID e sintaxe do JavaScript renderizado. Incluído no perfil core de `bin/run-tests`.
- `php -d zend.assertions=1 -d assert.exception=1 tests/v5461_ni2_830_consentimento_test.php`: **62 PASS, 0 FAIL**, incluindo OTP/portal, acesso e revogação.
- `php -d zend.assertions=1 -d assert.exception=1 tests/self_service_booking_test.php`: **FAIL** na limpeza da fixture, linha 113 (`DELETE FROM appointments` antes do histórico), com `FOREIGN KEY constraint failed`. Não classificamos a suíte completa de agendamento como aprovada. Nenhum arquivo de domínio ou banco foi alterado pela correção da NI2-893.
- `php -l` nas quatro views alteradas: sem erros.
- `node --check public/assets/js/realtime-transcription.js`: sem erros.
- `git diff --check`: sem erros.
- HTTP/browser e verificador E2E completo de links: **NOT TESTED**. A tentativa anterior de iniciar o servidor nesta sessão foi bloqueada pelo ambiente (`Operation not permitted` ao abrir porta local). Renderização direta não equivale a aprovação E2E.

Logs das suítes nesta pasta. Nenhuma entrega externa ou publicação realizada.
