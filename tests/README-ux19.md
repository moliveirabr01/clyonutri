# Reprodução NI2-684 a NI2-688

Use somente bancos sintéticos/descartáveis. Herda os pré-requisitos e variáveis de `README-ux-workflows.md`. Sem rede externa, sem pacientes reais, sem envio de mensagens.

- `php tests/ux19.php`: progresso explicitamente associado, aplicabilidade, dispensa, política por clínica/tipo, idempotência, conflito, isolamento, contexto e gates de pesquisa. SQLite em memória por padrão. Para MySQL forneça `ADAPTIVE_TEST_DSN` com banco `test`, `ADAPTIVE_TEST_USER` e `ADAPTIVE_FIXTURE_FILE`.
- `php tests/ux_workflows.php` e `php tests/adaptive_protocols.php`: regressões reais da base, em cada banco.
- `php tests/ux19_concurrency.php`: dois processos/conexões MySQL independentes e barreira simultânea; exige fixture da execução MySQL e produz exatamente um salvamento e um conflito.
- Gere fixture persistente com `tests/ux_workflow_fixture.php` e sirva `public/` com rotas ao index, como na versão anterior. O plano da fixture já contém uma refeição/alimento para as jornadas de cópia e edição; o benchmark continua criando planos vazios próprios.
- `node tests/ux19_browser.cjs`: categorias/busca/deep links, contexto canônico, montagem inline, dispensa/reload, conflito/CSRF, duas clínicas, configuração por tipo e quatro larguras. `node tests/ux_browser.cjs` e `node tests/ux_keyboard.cjs` preservam as jornadas anteriores. Não executar dois roteiros que editam o mesmo plano simultaneamente.
- `node tests/ux19_accessibility.cjs`: 11 telas × 4 larguras, painéis clínicos/decisões abertos por interação, axe WCAG A/AA, reflow e capturas. Um problema detectado retorna código de falha. Aceita `UX_A11Y_RESULT`, `UX_EVIDENCE_DIR`, `PLAYWRIGHT_MODULE`, `AXE_MODULE` e `CHROME_PATH`. Axe não certifica WCAG integral.
- `php bin/ux_view_inventory.php`: inventário reproduzível das views e rotas, sem executar consultas clínicas.
- `php bin/ux_scorecard.php docs/delivery/ni2-684-688/round-template.json`: retorna notas nulas e NO-GO até coleta humana/aceites registrados. As notas sintéticas usadas pelo teste unitário do gate são somente asserts e não resultados de pesquisa.

As evidências de entrega identificam quais execuções são automatizadas. Para validação humana e antes/depois comparável, seguir `PROTOCOLO-PESQUISA.md`. Não inserir dados de participantes identificáveis no pacote.
