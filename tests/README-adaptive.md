# Testes v54.17 — NI2-651 / 653 / 654 / 656

Os testes usam apenas dados fictícios, migrations e serviços reais. Os protocolos sintéticos são exclusivos dos bancos de teste, sem conteúdo clínico homologado. Nenhum teste publica conteúdo em produção.

## Integração e regressão

`php tests/adaptive_protocols.php` cria SQLite em memória, aplica todas as migrations e retorna JSON.

Para MySQL, use um banco exclusivo com `test` no nome e o adaptador MySQL da aplicação:

```sh
ADAPTIVE_TEST_DSN='mysql:host=localhost;dbname=clyonutri_adaptive_test;charset=utf8mb4' ADAPTIVE_TEST_USER=root ADAPTIVE_FIXTURE_FILE=/tmp/adaptive-mysql-fixture.json php tests/adaptive_protocols.php
ADAPTIVE_TEST_DSN='mysql:host=localhost;dbname=clyonutri_adaptive_test;charset=utf8mb4' ADAPTIVE_TEST_USER=root ADAPTIVE_FIXTURE_FILE=/tmp/adaptive-mysql-fixture.json php tests/adaptive_concurrency.php
```

O teste de concorrência abre dois processos e conexões reais, liberados pela mesma barreira. Exige exatamente um salvamento e um conflito.

## Navegador autenticado

Instale Playwright (ou forneça `PLAYWRIGHT_MODULE` apontando para a instalação existente), com Chromium/Chrome disponível. Gere uma nova base SQLite exclusiva e o arquivo de fixture:

```sh
ADAPTIVE_TEST_DSN='sqlite:/tmp/adaptive-browser-test.sqlite' ADAPTIVE_FIXTURE_FILE=/tmp/adaptive-browser-fixture.json php tests/adaptive_protocols.php
```

Sirva `public/` na porta local escolhida com o encaminhamento normal para `public/index.php`. Use `APP_ENV=testing`, `FORCE_HTTPS=false`, `APP_URL=http://127.0.0.1:8767`, `DB_CONNECTION=sqlite`, `DB_DATABASE=/tmp/adaptive-browser-test.sqlite`. Arquivos estáticos precisam ser servidos diretamente. Não use dados ou credenciais reais.

```sh
ADAPTIVE_BASE_URL=http://127.0.0.1:8767 ADAPTIVE_FIXTURE_FILE=/tmp/adaptive-browser-fixture.json ADAPTIVE_EVIDENCE_DIR=/tmp/adaptive-evidence node tests/adaptive_browser.cjs
```

Se necessário, defina `CHROME_PATH` com o executável do navegador. O roteiro faz login pela interface e usa os endpoints reais, sem interceptar respostas. Cria convites usando **Só gerar o link**: não envia e-mail ou WhatsApp. Relatórios JSON e capturas ficam no diretório de evidências.

A passagem técnica não substitui revisão clínica, aprovação de QA/PM/UX ou auditoria integral WCAG 2.2 AA.
