# Reprodução NI2-685

Use apenas bancos descartáveis sintéticos.

1. php tests/ni2_685.php executa a integração em SQLite em memória. Para MySQL, forneça ADAPTIVE_TEST_DSN com banco contendo test no nome e ADAPTIVE_TEST_USER.
2. Para fixture de navegador, execute a mesma suíte com ADAPTIVE_TEST_DSN=sqlite:/tmp/clyonutri-ni2685-test.sqlite e NI2685_FIXTURE_OUT=/tmp/clyonutri-ni2685-fixture.json. O teste cria pacientes de ausência/cadastro/rascunho, um alimento incompatível sintético e estados de versão publicada em um paciente separado.
3. Sirva public/ com estáticos diretos e demais rotas em public/index.php. Use APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE da fixture, APP_URL local e FORCE_HTTPS=false somente localmente.
4. Execute node tests/ni2_685_browser.cjs e node tests/ni2_685_accessibility.cjs. Defina ADAPTIVE_FIXTURE_FILE, UX_BASE_URL, PLAYWRIGHT_MODULE, CHROME_PATH e AXE_MODULE. UX_EVIDENCE_DIR define o destino de capturas.
5. Para comparação, sirva a v54.20 preservada em outra origem com a mesma fixture. Rode node tests/ni2_685_baseline.cjs com UX_BASELINE_URL. O roteiro é de leitura/captura, sem notas de usuário.
6. php tests/ux_workflows.php e php tests/adaptive_protocols.php executam as regressões, também reproduzíveis em MySQL.
7. php bin/ni2_685_inventory.php regenera o inventário de consumidores e contratos.

O navegador seleciona alimento incompatível, confirma que o alerta permanece e verifica que uma tentativa sem revisão é recusada. Não emite prescrição real. A alteração de anamnese é somente no paciente sintético em outra aba. Nenhum roteiro envia mensagens ou usa pacientes reais.

A auditoria automatizada não substitui avaliação assistiva, parecer clínico e aceite com profissionais.
