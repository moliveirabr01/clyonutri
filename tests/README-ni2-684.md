# Reprodução NI2-684

Somente base sintética isolada. Preserve a v54.18 para comparação; não rode fixtures em banco de produção.

1. php tests/ni2_684.php: SQLite em memória. Para MySQL, forneça ADAPTIVE_TEST_DSN com banco contendo test no nome e ADAPTIVE_TEST_USER. Testa recibos por contexto/validade, recuperação, catálogo, rotas e perfil persistido/conflito.
2. php tests/ux_workflows.php: regressão dos workflows, em cada banco.
3. Crie fixture persistente com ADAPTIVE_TEST_DSN=sqlite:/tmp/clyonutri-ni2684-test.sqlite ADAPTIVE_FIXTURE_FILE=/tmp/clyonutri-ni2684-fixture.json php tests/ux_workflow_fixture.php.
4. Sirva public/ com arquivos estáticos diretos e as demais rotas em public/index.php, conforme README-ux-workflows. Use APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE do banco sintético, APP_URL local e FORCE_HTTPS=false somente localmente.
5. Execute node tests/ni2_684_browser.cjs e node tests/ni2_684_accessibility.cjs. Variáveis: ADAPTIVE_FIXTURE_FILE, UX_BASE_URL, PLAYWRIGHT_MODULE, CHROME_PATH, AXE_MODULE e UX_EVIDENCE_DIR. O primeiro teste grava apenas preferências sintéticas, não altera senha nem envia e-mails.
6. Para antes/depois, sirva a v54.18 preservada com a mesma base de testes em outra origem e rode node tests/ni2_684_baseline.cjs com UX_BASELINE_URL e UX_BASE_URL. O roteiro faz apenas leitura e captura contagens estruturais; não produz resultados humanos.
7. php bin/ni2_684_inventory.php regenera o inventário de domínios, tarefas, permissões e rotas.

Os testes de descoberta antigos da v54.19 usam seletores da busca por categoria daquela versão; para a nova busca por tarefa use a suíte específica NI2-684. As suítes de serviço/workflow anteriores continuam aplicáveis. Para pesquisa humana, seguir o protocolo NI2-688 existente, mantendo a taxa de descoberta e notas não testadas até a coleta.
