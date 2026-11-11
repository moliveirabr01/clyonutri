# Reprodução — NI2-626 / 627 / 628 / 630 / 631

Somente bases sintéticas e isoladas. Não há interceptação de respostas da aplicação ou mocks de banco/serviços. Os roteiros de navegador fazem login pela interface. O teste de falha de rede usa o modo offline real do navegador. Nenhum roteiro envia mensagens, publica plano ou confirma uma conduta clínica real.

## Integração

```sh
php tests/ux_workflows.php
```

Por padrão usa SQLite em memória e um arquivo temporário com IDs de fixture. Para MySQL, crie um banco descartável com `test` no nome e forneça `ADAPTIVE_TEST_DSN`, `ADAPTIVE_TEST_USER`, opcionalmente `ADAPTIVE_TEST_PASSWORD` e `ADAPTIVE_FIXTURE_FILE`. Exemplo:

```sh
ADAPTIVE_TEST_DSN='mysql:host=localhost;dbname=clyonutri_ux_test;charset=utf8mb4' ADAPTIVE_TEST_USER=root ADAPTIVE_FIXTURE_FILE=/tmp/ux-fixture.json php tests/ux_workflows.php
ADAPTIVE_TEST_DSN='mysql:host=localhost;dbname=clyonutri_ux_test;charset=utf8mb4' ADAPTIVE_TEST_USER=root ADAPTIVE_FIXTURE_FILE=/tmp/ux-fixture.json php tests/ux_concurrency.php
```

A integração exercita serviços e controller reais, dados canônicos e recibos de idempotência, rollback, revisões, medidas sem porção, API de cópia/edição/substituição, isolamento e paridade do motor de horários. A concorrência exige dois processos e conexões independentes, com barreira simultânea; o resultado obrigatório é um salvamento e um conflito.

A regressão da v54.17 também roda ao construir a fixture. Para um relatório próprio: `php tests/adaptive_protocols.php` (57 verificações).

## Base para navegador

```sh
ADAPTIVE_TEST_DSN=sqlite:/tmp/clyonutri-ux-test.sqlite ADAPTIVE_FIXTURE_FILE=/tmp/ux-fixture.json php tests/ux_workflow_fixture.php
```

Sirva `public/` com os arquivos estáticos diretamente e demais caminhos em `public/index.php`. Configure `APP_ENV=testing`, `FORCE_HTTPS=false` apenas no ambiente local, `DB_CONNECTION=sqlite`, `DB_DATABASE=/tmp/clyonutri-ux-test.sqlite` e `APP_URL` igual à origem local do servidor.

Instale Playwright/Chromium e axe-core no ambiente de testes. Os scripts aceitam `PLAYWRIGHT_MODULE`, `CHROME_PATH`, `AXE_MODULE`, `UX_BASE_URL` e `ADAPTIVE_FIXTURE_FILE`. Execute:

```sh
node tests/ux_browser.cjs
node tests/ux_keyboard.cjs
node tests/ux_accessibility.cjs
```

`ux_browser.cjs`: autosave, reload, duas abas, offline/retry, exames, cópia, sessões, paridade de horários e quatro larguras. `ux_keyboard.cjs`: consulta, edição do plano e revisão de exames usando Tab/Shift+Tab/Enter/Espaço/setas para a tarefa após o login. `ux_accessibility.cjs`: regras WCAG A/AA do axe em 24 combinações de tela e largura; exporta também verificações inconclusivas que exigem avaliação humana. Use `UX_EVIDENCE_DIR` para capturas. Os relatórios usam caminhos sintéticos; não contêm respostas de pacientes reais.

O roteiro de teclado pode agendar mais de uma consulta fictícia do mesmo paciente no mesmo dia; nesse caso confirma explicitamente o aviso de mesmo dia, sem liberar conflito de horário. Em um ambiente novo não é necessária essa confirmação.

## Comparação antes/depois

Sirva a base imutável v54.17 e a v54.18 usando a mesma base de testes. Crie dois planos vazios com `tests/ux_benchmark_reset.php`, fornecendo DSN e fixture. Execute `ux_plan_benchmark.cjs` uma vez com `UX_BENCH_MODE=baseline` na origem da v54.17 e outra com `UX_BENCH_MODE=quick` na origem da v54.18. Não execute novamente no mesmo plano já preenchido: crie novos planos vazios para cada par de medições.

O roteiro registra cliques e eventos de input/change, recarregamentos do documento, tempo automatizado e a contagem persistida após reload. Esses eventos não são uma contagem de cliques humanos, e o tempo automatizado não mede produtividade humana. Na execução entregue: 276 → 187 eventos, 6 → 0 recarregamentos, 24 itens persistidos em ambos.

## Limites

Axe e checagens de teclado/reflow não certificam conformidade integral WCAG. A avaliação manual com VoiceOver/NVDA, testes de touch com pessoas, a homologação no HostGator e os aceites QA/UX/PM continuam necessários. Nenhuma nota heurística foi recalculada a partir de inspeção estática.
