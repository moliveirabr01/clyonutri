# NI2-688 — testes locais

php tests/ni2_688.php testa a calculadora com fichas geradas exclusivamente em memória: nunca usar esses dados como pesquisa. bin/ux_scorecard.php avalia a rodada JSON e --gate sinaliza NO-GO com exit 1.

adaptive_protocols.php, ux_workflow_fixture.php, ux_workflows.php e ni2_685.php foram reaproveitados da base local v54.23 para validar a candidata importada. ADAPTIVE_TEST_DSN deve ser um banco isolado de testes (SQLite ou MySQL com test no nome), nunca produção. A suíte de contexto permite NI2685_FIXTURE_OUT para exportar a fixture sintética.

ni2_688_portal_fixture.php só aceita os caminhos de teste NI2-688 em /tmp e acrescenta credenciais sintéticas. ni2_688_browser.cjs usa PLAYWRIGHT_MODULE, AXE_MODULE, CHROME_PATH, UX_BASE_URL, UX_BASELINE_URL, ADAPTIVE_FIXTURE_FILE, BASELINE_FIXTURE_FILE e UX_EVIDENCE_DIR. Requer duas instâncias locais com bancos separados; grava refeições sintéticas para testar offline/retry/reload. Resultados são automação, não cliques humanos. Violações/reflow são registrados mesmo sem interromper a caracterização técnica; conferir report.json. A execução original do fornecedor não foi reconstituída, pois seus testes não vieram no pacote.
