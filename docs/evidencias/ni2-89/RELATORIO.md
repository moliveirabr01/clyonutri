# NI2-89: API versionada do paciente, revisão e evidências

- **Data:** 21/10/2026
- **Base:** commit `78247b9` (branch `merge/v11-teleconsulta`)
- **Status no Jira:** não alterado. Continua "Feito" no Jira; a recomendação está no fim.

## Critérios da história

> versão /api/v1 ou equivalente; auth; autorização por recurso; erros padronizados; testes IDOR; documentação mínima.

## Como reproduzir

```bash
node tests/ni2_89_patient_api_real_test.cjs
# antes das correções (árvore anterior):
APP_ROOT=/caminho/da/arvore/78247b9 node tests/ni2_89_patient_api_real_test.cjs
```

O teste sobe `public/index.php` em `php -S` (`APP_ENV=production`) com SQLite isolado e dados fictícios (`tests/support/ni2_89_fixture.php`):
- duas clínicas;
- três pacientes (Ana e Bruno na clínica 1, Carla na clínica 2);
- consultas, conversa com anexo e uma receita privada da clínica 2.

Tudo por HTTP real, com tokens emitidos pelo login real.

## Resultado

| Execução | Resultado | Arquivos |
|---|---|---|
| Antes (`78247b9`) | 42 PASS, **8 FAIL** | `antes.log`, `antes-resultados.json` |
| Depois | **50 PASS, 0 FAIL** | `depois.log`, `depois-resultados.json` |

## Defeitos encontrados e corrigidos

| # | Defeito (reproduzido no antes) | Correção |
|---|---|---|
| 1 | `POST /patient/hydration` aceitava volume negativo: `-900` gravado e o total do dia ficava negativo | Volume de 1 a 5.000 ml; fora disso, 422 `VALIDATION_ERROR` (`EngagementService`, rota) |
| 2 | `POST /patient/food-diary` sem dados respondia **500** | Validação antes de gravar; resposta 422 com mensagem clara |
| 3 | Diário aceitava fome/saciedade fora de 1–5 e refeição em 2030 | Escalas 1–5; data inválida ou mais de 1 dia no futuro → 422; tamanho máximo dos textos |
| 4 | Diário gravava `photo_path` enviado pelo cliente (ex.: `/etc/passwd`) | A rota da API ignora caminho de foto vindo do cliente (o app não envia arquivo por ela) |
| 5 | `docs/api/openapi_v1.json` descrevia 8 rotas que **não existem** (`/auth/login`, `/patients`, `/meal-plans/{id}/publish`…) e nenhuma das 21 rotas reais do paciente | Contrato reescrito (v1.1.0) com as 21 rotas reais, autenticação (Bearer e cookie web), schema de erro e limites de validação |
| 6 | Dois testes (`delivery_e2e_and_contracts_test.php`, `verifiable_multilateral_audit_suite_test.php`) "validavam" o contrato conferindo justamente as rotas fictícias | Passam a conferir rotas reais |
| 7 | `patient_mobile_http_contract_test.php` falhava por data fixa (20/10/2026 virou passado), e não por defeito do produto | Consulta criada com data relativa; 28/28 PASS |

## Situação por critério

| Critério | Situação | Evidência |
|---|---|---|
| Versão `/api/v1` | **PASS** | Todas as rotas de paciente estão sob `/api/v1`. Toda rota chamada pelo app (`mobile/src/services/api.ts`) existe no servidor |
| Autenticação | **PASS** | As 18 rotas protegidas respondem 401 em JSON sem token. Também 401: token inexistente, expirado, de paciente inativada, de paciente anonimizada, e após logout. Senha errada e conta inexistente recebem a mesma resposta. Login só com identificador é recusado. O banco guarda só o hash do token. Cookie web com origem externa → 403 |
| Autorização por recurso (IDOR) | **PASS** | A paciente não consegue: confirmar nem cancelar consulta de outro paciente (mesma clínica ou outra); enviar mensagem nem marcar como lida a conversa de outro; baixar anexo de outro; ver ou favoritar receita não prescrita; ver diário de outro. O dono de cada recurso continua conseguindo. Em cada recusa, o banco foi conferido sem alteração |
| Erros padronizados | **PASS** (após correções 1–3) | Todas as 41 respostas de erro observadas são JSON `{error, code, request_id}` sem detalhe interno. Rota inexistente → 404 JSON; JSON malformado → 400; validação → 422 |
| Testes IDOR | **PASS** | `tests/ni2_89_patient_api_real_test.cjs` (HTTP real). Já existiam testes de serviço (`cross_tenant_*`, que passam) |
| Documentação mínima | **PASS** (após correção 5) | O teste confere que o OpenAPI documenta todas as rotas reais do paciente e nenhuma inexistente |

## Regressão

| Execução | Resultado |
|---|---|
| 97 suítes PHP, antes x depois | 75 → 77 PASS, nenhuma falha nova. As duas que passaram a passar: `dashboard_review_fixes_test` e `ni2_292_mobile…`. Não mexi nelas: o resultado depende de horário e data |
| `patient_mobile_http_contract_test.php` | 28/28 (antes falhava só pela data fixa) |
| Cliente do app contra o servidor real (`ni2_294_real_http_client_test.cjs`) | 34/34 |
| NI2-433, NI2-437, NI2-412 UX-03..13 (navegador) | 69, 48 e 124 PASS (1 BLOCKED já conhecido) |

Ajuste de teste da NI2-412: a checagem de "painel sem pendências" passou a considerar a consulta fictícia de 21/10, que hoje vira pendência real.

## Fora do escopo e limites

- **API do profissional** (`/api/v1/professional/*`, `/api/v1/auth/professional-*`): continua sem contrato publicado. Isso está dito no próprio OpenAPI.
- **Não executados aqui:**
  - `ni2_319_real_http_client_test.cjs`: precisa do build web do app (`mobile/dist`) e de um caminho fixo de Playwright de outra máquina;
  - `ni2_323_mobile_client_regression_test.cjs`: precisa do TypeScript instalado em `mobile/node_modules`.
- **MySQL:**
  - testado só em SQLite;
  - no MySQL, a expiração do token compara a hora UTC gravada com `CURRENT_TIMESTAMP` da sessão; o fuso dessa sessão na HostGator não foi verificado (**NOT TESTED**).
- **Validação independente:** não houve.

## Recomendação para o Jira

Antes desta revisão, a NI2-89 não cumpria o aceite: erros fora do padrão, entrada sem validação e documentação de outra API. Com estas correções, os seis critérios têm evidência executada.

Recomendação: mover para **Em validação**, e não manter "Feito" sem validação independente. A reabertura sugerida na revisão de 20/10 dependia em parte de um teste com data vencida (correção 7); este relatório substitui aquela avaliação.
