# NI2-895 — investigação da equivalência de medidas caseiras

Data: 17/11/2026. Base: v54.76, HEAD 6dba333, branch feat/v54-76-ni2-875.

## Diagnóstico reproduzido

A execução original falhou com `Undefined array key measure_grams_equivalent`. O valor obtido era NULL (convertido em 0 pela asserção), não um JOIN retornando um peso divergente. A medida unidade média existia no catálogo com 100 g. O item era criado por INSERT direto sem weight_grams, que também ficou NULL. Valores completos em before.log.

Classificação: teste/fixture desatualizados, não defeito demonstrado do carregamento de planos. O contrato vigente persiste o peso TOTAL em meal_plan_items.weight_grams na criação/edição via MealPlanService. MealPlanPrintService usa esse peso total, evitando recalcular prescrições a partir de um catálogo alterado. O teste de reflexão também passava 100 g como peso total de duas unidades e esperava 200 g.

## Alterações

- Fixture determinística com alimento próprio e medida verificada de 100 g.
- Plano/refeição/item criados por createPlan/addMeal/addItem, sem números nutricionais fixados por INSERT.
- Asserções do retorno: amount=2, weight_grams=200, energy_kcal=196, weight_is_estimated=0.
- Impressão real com total de 200 g; peso e impressão preservados após catálogo passar para 150 g por unidade.
- Medida desconhecida rejeitada sem inserir item; gramas simples e medida legada com peso no nome continuam cobertas.
- Pré-condição APP_ENV=testing e SQLite para reduzir risco de execução indevida.
- Retirada a falha conhecida e acrescentada a suíte ao gate core isolado.

Nenhum código de produção, .env, banco operacional ou pacote de release foi alterado.

## Resultados e reprodução

- `bash bin/run-tests full assisted_navigation_audit`: PASS; 51/51 verificações (after.log, runner-after.log).
- `php tests/run_full_suite.php @docs/validation/NI2-895/regression-suites.txt`: quatro suítes PASS, zero falhas/conhecidas/não executadas (regressions.log).
- `php -l tests/assisted_navigation_audit_test.php` e `git diff --check`: PASS.
- Gate core amplo: 39 etapas OK e uma etapa reprovada (core_modules.txt). Dentro dela: 11 suítes PASS e 10 FAIL. assisted_navigation_audit PASS; as falhas restantes abrangem agenda, HSTS, dobras, backups e fixtures/autenticação (detalhes em core.log). Não se declara gate geral verde.

O runner original exige o executável coreutils `timeout`, ausente nesta máquina. A primeira tentativa parou antes de executar o teste. Para executar o runner sem alterar seu código foi usado adaptador temporário real de timeout com subprocess.run(timeout=...) (timeout-adapter.py); ele preserva o código de saída do comando e usa 124 ao exceder o limite. Para reproduzir em máquina sem coreutils, copie o adaptador para diretório temporário com nome timeout, dê permissão de execução e acrescente esse diretório ao PATH. Os bancos de cada suíte são isolados pelo runner.

A reprodução inicial usou explicitamente DB_CONNECTION=sqlite, APP_ENV=testing, DB_DATABASE/TEST_DB_DATABASE em /tmp/ni2-895-*/before.sqlite, chave clínica de teste e MAIL_DRIVER=log; executou bin/migrate.php, bin/seed.php, bin/seed.php --demo e tests/_full_suite_base_fixture.php antes da suíte. before.log contém o erro e a inspeção dos valores reais.

## Limites

Serviços e SQLite executados de verdade. Navegador, Apache/HostGator e MySQL real NOT TESTED. Suíte completa de todos os testes não executada nesta rodada; somente seleção e gate core. Não há deploy nem nova versão de runtime nesta correção de teste.
