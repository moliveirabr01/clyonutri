# NI2-897 — investigação XSS em nome de alimento

17/11/2026, base v54.76/HEAD 6dba333. Trabalho anterior NI2-895/896 preservado.

## Reprodução e classificação

Executado com zend.assertions=1, assert.exception=1, display_errors=1 e error_reporting=-1. A exceção real foi AssertionError: ingredientes usam o seletor de alimentos. As quatro asserções anteriores passaram: não há catálogo/nome malicioso embutido no HTML, e as tags script permanecem balanceadas. O teste exigia src="/assets/js/food-picker.js" e o HTML usa src="/assets/js/food-picker.js?v=v54-64". Evidência: before.log.

Classificação da falha reproduzida: TESTE DESATUALIZADO. Não foi reproduzido XSS no HTML exercitado; não equivale a comprovação global de ausência de XSS no produto.

## Correção

Verificação de uma tag script real pelo caminho local, aceitando query de cache. Mantidas todas as verificações de segurança. Banco/sessão usam o diretório isolado fornecido pelo runner, aproveitando o parâmetro opcional de aud_fixture da NI2-896; sessão é destruída no final. Removida a entrada da lista de falhas conhecidas e acrescentada a suíte ao gate core isolado. Nenhum código de produção alterado.

## Resultados

- XSS: 11 verificações PASS em SQLite isolado, com alimento malicioso persistido e formulário gerado pelo RecipeController real (after.log).
- A verificação de food-picker.js e DashboardController é inspeção de código e round-trip de escape JSON/HTML em PHP, não execução de eventos no navegador.
- Runner selecionado: quatro suítes PASS, zero falhas/conhecidas/não executadas — XSS, F02, catálogo público de receitas e domínio/prescrição (regressions.log).
- PHP syntax e git diff --check PASS.
- Gate core amplo e suíte completa não repetidos nesta rodada; falhas fora desta história constam na NI2-895.

## Tentativa de navegador

Foi adicionado tests/ni2_897_food_picker_dom_test.py para executar o food-picker.js original em DOM real do Chromium com resposta JSON sintética. O teste verifica nome literal no resultado, Option, seleção, ausência de elementos/eventos injetados e ausência de alert/erro. Ele não testa servidor, autenticação ou API real.

Tentativa: python3 tests/ni2_897_food_picker_dom_test.py --chromium /Users/moliveira/Library/Caches/ms-playwright/chromium_headless_shell-1255/chrome-headless-shell-mac-arm64/chrome-headless-shell

Resultado BLOCKED: Chromium encerrou na criação da porta Mach (bootstrap_check_in Permission denied 1100), antes de carregar a página. dom.log contém o erro. Não foi declarado PASS de DOM/browser. O script permanece reproduzível em ambiente com Chromium autorizado.

## Reprodução PHP

APP_ENV=testing DB_CONNECTION=sqlite CLINICAL_ENCRYPTION_KEY=test-clinical-key-ni2897 MAIL_DRIVER=null php -d zend.assertions=1 -d assert.exception=1 -d display_errors=1 -d error_reporting=-1 tests/auditoria_2026_10_14_xss_food_name_js_test.php

php tests/run_full_suite.php @docs/validation/NI2-897/regression-suites.txt

Runner usa timeout externo. Como coreutils está ausente neste host, foi usado adaptador temporário real de ../NI2-895/timeout-adapter.py (subprocess.run(timeout=...), códigos preservados e 124 em expiração) no PATH. Não houve alteração no runner.

## Limites

Browser/DOM: BLOCKED. MySQL/Apache/HTTP autenticado: NOT TESTED. Não houve deploy nem nova versão de runtime. A investigação fecha a falha de teste; não certifica a segurança de todas as jornadas do produto.
