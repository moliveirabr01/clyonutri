# NI2-630 — validação local

As suítes são automação e não representam usuários, consentimentos ou certificação WCAG.

ni2_630_fixture.php reaproveita a fixture clínica existente com duas clínicas e acrescenta uma consulta futura para a agenda. Definir ADAPTIVE_TEST_DSN com SQLite/MySQL isolado de testes e ADAPTIVE_FIXTURE_FILE. Não usar produção. A suíte de interações cria consulta, edita plano e envia exame fictício para revisão: gerar uma fixture nova antes de repeti-la, para evitar conflitos intencionais de horário.

ni2_630_interactions.cjs: teclado, combobox, popovers, diálogos, alertas, persistência, rede e tenant.
ni2_630_matrix.cjs: quatro larguras, zoom nativo 200/400, axe, toque/cores/movimento emulados, calendários e âncoras.
ni2_630_components.cjs: ciclo de foco, diálogos sobrepostos/dinâmicos, inert original e remoção.
ni2_630_landmarks.cjs: salto ao conteúdo por teclado, idioma e região principal.
ni2_630_tables.cjs: árvore AX antes/depois e rolagem por teclado.
ni2_630_spacing.cjs: override de espaçamento em quatro jornadas.
ni2_630_session.cjs: ajuste exclusivo de last_activity da sessão sintética local, aviso e renovação pelo backend real. Exige UX_APP_ROOT terminando em release-v54-30 e host loopback; não apontar para outra instalação.

Variáveis dos scripts: PLAYWRIGHT_MODULE, AXE_MODULE, CHROME_PATH, ADAPTIVE_FIXTURE_FILE, UX_BASE_URL, UX_BASELINE_URL, UX_EVIDENCE_DIR ou UX_OUTPUT_FILE. O zoom usa perfis Chromium temporários, removidos ao fechar, sem afetar o perfil pessoal. Evidências clínicas reais e sessões reais não entram no pacote. Workflows e contexto herdados continuam testados em SQLite/MySQL com os scripts existentes.
