# Regressão de reflow

exam_reflow.cjs requer PLAYWRIGHT_MODULE, AXE_MODULE, CHROME_PATH, ADAPTIVE_FIXTURE_FILE, UX_BASE_URL, UX_BASELINE_URL e UX_EVIDENCE_DIR. Baseline v54.28 e candidata v54.29 usam instâncias locais; a fixture é sintética da NI2-688, com profissional autorizado.

O teste reproduz o transbordamento original, cobre seis telas nas quatro larguras, zoom CSS, teclado e zoom nativo Chromium por preferência per-host em perfis temporários. Cria um rascunho de exame sintético via autosave e reabre a edição; usar apenas banco de testes. Não emite PDF, não publica e não altera prontuário real. Os relatórios preservam inconclusivos do axe; a execução não substitui certificação assistiva integral ou pesquisa com participantes.
