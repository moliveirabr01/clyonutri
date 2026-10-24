# NI2-132: correções do diário alimentar, evidências

- **Data:** 24/10/2026
- **Base:** `89bd009` (merge/v11-teleconsulta), ramo `fix/ni2-132`
- **Status no Jira:** não alterado. A revisão (CODE-REVIEW-NI2-132) concluiu que "Feito" não se justifica; estas correções cobrem só o que não muda o modelo de dados.

## Como reproduzir

```bash
node tests/ni2_132_food_diary_http_test.cjs                 # SQLite isolado
DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_USERNAME=root DB_PASSWORD= \
  node tests/ni2_132_food_diary_http_test.cjs               # MariaDB/MySQL descartável (cria e apaga bancos ni2_132_test_*)
node tests/ni2_132_app_diary_test.cjs                       # tela do app (fuso, paginação, erros)
APP_ROOT=/caminho/da/arvore/anterior node tests/ni2_132_food_diary_http_test.cjs   # "antes"
```

O teste sobe `public/index.php` em `php -S`, com banco isolado e dados fictícios (`tests/support/ni2_132_fixture.php`, que recusa qualquer banco fora de `/tmp/ni2-132-*` ou `ni2_132_test_*`).

## Resultado

| Execução | Resultado | Arquivos |
|---|---|---|
| Antes (`89bd009`) | 27 PASS, **33 FAIL** e interrompido na migração 53, que não existe lá | `antes-sqlite.log`, `antes-sqlite.json` |
| Depois, SQLite | **120 PASS, 0 FAIL** | `depois-sqlite.log`, `depois-sqlite.json` |
| Depois, MariaDB 11.8 | **120 PASS, 0 FAIL** (4 workers em paralelo) | `depois-mariadb.log`, `depois-mariadb.json` |

## O que o teste cobre
Validação (corpo vazio, `meal_name` em array, caractere de controle, limites exatos), horário (data inexistente, anterior a 2000, futuro, fuso do aparelho), foto (`/etc/passwd`, `../`), idempotência (201, 200 com o mesmo conteúdo, 409 com outro, paralelismo, registros antigos sem hash), migração 53 (`down` e `up`, incluindo banco sem a migração), paginação por cursor com empates, visão do profissional (`patient_id`, outra clínica → 404, sem campos internos), auditoria, feedback (422 e limite de 2.000), LGPD e limite de 30 por minuto (429 com `Retry-After`).

## Ressalvas
- Registros antigos têm `reviewed_at` em UTC; os novos, no horário da clínica.
- Limite inferior de data fixado em 2000-01-01; a revisão sugeria 30 a 90 dias (decisão de produto).
- No SQLite as requisições paralelas de escrita falham por bloqueio do próprio SQLite, por isso o teste usa 1 worker lá; o paralelismo é validado no MariaDB.
- Falhas que já existiam antes (não são desta mudança): `verifiable_multilateral_audit_suite_test`, `patient_portal_e2e_suite_test` (falta `patient_invitations`), `ni2_362_mobile_session_http_test`, `ni2_319_real_http_client_test`.
