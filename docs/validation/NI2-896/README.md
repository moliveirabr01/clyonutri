# NI2-896 — escopo de alimentos e receitas por clínica

Investigação em 17/11/2026 sobre v54.76, base HEAD 6dba333. Alterações anteriores da NI2-895 preservadas.

## Causa reproduzida

Com zend.assertions=1, assert.exception=1, display_errors=1 e error_reporting=-1, a falha real foi AssertionError na condição do formulário create. O teste exigia o texto exato src="/assets/js/food-picker.js", mas o HTML usa /assets/js/food-picker.js?v=v54-64. Não houve evidência de vazamento de alimentos nessa reprodução. before.log contém exceção e stack trace.

Após corrigir a asserção, a execução pelo runner encontrou outra causa: a fixture usa sys_get_temp_dir(), que no processo filho sem ambiente herdado aponta para /var/tmp. O sandbox não permite banco/sessão nesse local: PDOException unable to open database file e session_start Operation not permitted. Logs runner-before-fixture-fix.log e runner-fixture-error.log.

Classificação: teste desatualizado e fixture incompatível com o ambiente. Não foi demonstrado defeito no isolamento de produto.

## Correções

- Verificação do src de uma tag script real, comparando o caminho local e aceitando query de cache; mantida a proibição de dados embutidos do catálogo e de alimento estrangeiro.
- aud_fixture recebe diretório temporário opcional; mantém o padrão para outros consumidores e valida gravabilidade. F02 usa o diretório do SQLite isolado fornecido pelo runner para banco e sessão, com sessão destruída ao término.
- Edição rejeitada confere igualdade de todos os campos da receita e dos ingredientes antes/depois.
- Removida a falha conhecida de F02 e incluída a suíte no core_modules.txt.
- Nenhuma alteração em RecipeService/RecipeController ou outro código de produção.

## Evidências executadas

13 verificações F02 PASS (runner-target.log): formulários create/edit não expõem B; busca assíncrona sem catálogo embutido; lista de A inclui global/próprio e exclui B; criação, edição e duplicação com alimento de B rejeitadas; edição preserva integralmente receita/ingredientes; duplicação de receita pública de B não importa alimento privado; operações legítimas funcionam.

`php tests/run_full_suite.php @docs/validation/NI2-896/regression-suites.txt`: 3 PASS, 0 FAIL, 0 falhas conhecidas, 0 não executadas (regressions.log). Asserções habilitadas pelo runner. Cobre F02, exemplo público de receitas e domínio/prescrição de receitas. Cada suíte em SQLite isolado; F02 cria duas clínicas e usa os controllers/serviços reais. Não é navegação HTTP/browser autenticada.

`php -l` nos dois PHP alterados e `git diff --check`: PASS.

Execução inicial direta: APP_ENV=testing DB_CONNECTION=sqlite CLINICAL_ENCRYPTION_KEY=test-clinical-key-ni2896 MAIL_DRIVER=null php -d zend.assertions=1 -d assert.exception=1 -d display_errors=1 -d error_reporting=-1 tests/auditoria_2026_10_14_f02_recipe_food_scope_test.php.

Coreutils timeout ausente neste host: o runner usou adaptador temporário real documentado em ../NI2-895/timeout-adapter.py, com subprocess.run(timeout=...), repassando códigos de saída e 124 em timeout. Para reprodução em macOS sem coreutils, copie-o como timeout para diretório temporário, chmod +x e acrescente ao PATH antes do comando do runner. Runner do projeto não alterado.

## Limites

Gate core completo não repetido nesta rodada: já reprovado na NI2-895 por outras suítes. Não se declara aprovação geral da versão. MySQL real, Apache e navegador NOT TESTED. Sem alteração em .env/dados operacionais, sem novo pacote ou deploy.
