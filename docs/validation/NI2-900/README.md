# NI2-900 — peso persistido na impressão e pluralização

17/11/2026, base v54.76/HEAD 6dba333. Alterações anteriores preservadas.

## Diagnóstico reproduzido

Runner isolado reproduziu a asserção A3: valor real `2 unidades (~100g)` e `1 xícara (~150g, peso estimado)`. Peso e marcador de estimativa já estavam presentes; a expectativa antiga exigia `2 unidade (~100g)`. É divergência de texto do teste, não peso ausente. before.log e before-runner.log registram a reprodução.

Também ocorreram avisos session_start por /var/tmp bloqueado e cinco falhas A1 de status HTTP nessa execução. Ajustando o armazenamento de sessão ao diretório isolado do runner, esses avisos/falhas desapareceram sem modificar autorização de produto.

## Correção

- Expectativa plural corrigida para `2 unidades (~100g)`.
- Teste compara as quantidades impressas aos valores weight_grams persistidos dos itens (100 g para dois ovos e 150 g para a xícara estimada), não a um cálculo aproximado na leitura.
- Catálogo do ovo alterado para porção de 80 g: impressão permanece em 100 g e o item de xícara mantém peso/aviso de estimativa.
- Caso de uma unidade segue `1 unidade (~50g)`.
- Sessão da fixture usa o diretório fornecido por TEST_DB_DATABASE/DB_DATABASE. Pré-condição exige APP_ENV=testing e DB_CONNECTION=sqlite antes de ações destrutivas da fixture.
- Retirada a entrada da lista de falhas conhecidas e incluída a suíte em core_modules.txt.

MealPlanPrintService e demais arquivos de produção não foram alterados. Não se restaurou a antiga subconsulta de medida caseira; a impressão continua usando o peso total já gravado.

## Evidências executadas

- ni2_9_code_review_regression_test.php: **47 PASS, 0 FAIL**; preservadas as verificações de alérgenos, publicação, auditoria, imutabilidade, snapshot, limites de quantidade e autorização/CRN. after.log.
- `php tests/run_full_suite.php @docs/validation/NI2-900/regression-suites.txt`: **4 PASS**, zero falhas/conhecidas/não executadas. Inclui a suíte alvo, assisted_navigation_audit, ni2_74_portion_conversion e meal_plan_and_prescription_lifecycle; regressions.log.
- PHP syntax e git diff --check PASS.

Para rodar neste macOS sem coreutils timeout, foi usado o adaptador temporário real documentado em ../NI2-895/timeout-adapter.py no PATH, com códigos de saída preservados e timeout subprocess.run. O runner fornece banco isolado novo por suíte, migrations/seed e asserções habilitadas. Não se usa o banco operacional.

## Limites

Serviços, persistência SQLite e construção do documento de impressão executados de verdade. Impressão física/PDF no navegador, MySQL real e Apache/HostGator NOT TESTED. Suíte inteira e gate core amplo não repetidos nesta rodada; não se declara aprovação geral da versão. Sem novo pacote/deploy ou atualização do status do épico NI2-9.
