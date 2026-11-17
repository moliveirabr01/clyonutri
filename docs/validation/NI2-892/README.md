# NI2-892 — Etapa 1 parcial, não concluída

A issue exige cinco entregas independentes, com metas de performance e gate + jornadas E2E após cada uma. Esta execução implementa instrumentação e parte da redução de consultas da etapa 1. **Não é uma conclusão da NI2-892.**

## Implementado

- `ProfiledPdo`, `ProfiledStatement` e `QueryMetrics`: contagem de execuções SQL (inclui statements reutilizados, query, exec e falhas) e tempo acumulado. Não contam prepare como execução. Não retêm SQL, parâmetros ou identificadores de paciente; apenas hashes e contagens.
- Ativação opt-in com `DB_QUERY_PROFILE=1`, apenas em APP_ENV local/dev/development/testing. Produção permanece desativada mesmo com a flag ligada. A flag desligada é o padrão em `.env.example`; o `.env` existente não foi alterado.
- Conexões SQLite e MySqlCompatPdo instrumentadas; em requisições com perfil ativo, resumo em error_log no encerramento sob `[ClyoNutri][query-metrics]`. Cada conexão tem métricas próprias; testes diretos podem obter/resetar o snapshot.
- Metadados de colunas carregados em lote por tabela e catálogo de tabelas carregado uma vez por conexão. WeakMap evita confusão entre conexões descartadas. Reset explícito após DDL; Migrator já o executa após cada migration.
- AnamnesisService reutiliza o leitor de metadados, em vez de fazer introspecções independentes repetidas.
- `NextAvailableSlot`: busca de 60 dias carrega exceções do período em lote. Dias sem janela são descartados em memória; dias potencialmente disponíveis continuam passando por availabilityPreview e pelas validações canônicas de conflito. Profissional é validado no tenant antes da busca. Nenhum cache global de autorizações ou dados clínicos.
- A agenda resolve o profissional uma vez para suas leituras locais de preferências/disponibilidade/aviso.
- Gate core inclui a suíte nova e força `zend.assertions=1` e `assert.exception=1`; uma sentinela exige que assert(false) realmente lance AssertionError.

## Medição reproduzível

Execute separadamente:

```sh
php tests/ni2_892_page_queries.php profile
php tests/ni2_892_page_queries.php plan
php tests/ni2_892_page_queries.php agenda
php tests/ni2_892_page_queries.php dashboard
```

Cada execução cria SQLite temporário com schema real e dados sintéticos: uma clínica, um profissional, um paciente, um plano rascunho com uma refeição/alimento e nenhuma jornada semanal. As quatro actions reais do DashboardController são renderizadas. Dados temporários são removidos ao terminar.

**Escopo: renderização direta do controller, sem middleware/autenticação HTTP. Não é o mesmo roteiro da auditoria original, cujo perf.json não foi localizado na codebase. Esses números não certificam o critério de aceite de produção ou a auditoria original.**

| Página | Antes | Depois | Meta | Resultado no cenário sintético |
| --- | ---: | ---: | ---: | --- |
| Ficha | 100 | 97 | ≤40 | Pendente |
| Editor de plano | 97 | 94 | ≤40 | Pendente |
| Agenda | 512 | 29 | ≤30 | Atingida neste cenário |
| Painel | 41 | 38 | ≤30 | Pendente |

A agenda representa o caso de busca de horário durante 60 dias sem jornada. Não extrapolar o ganho para agendas ocupadas/configuradas. Consultas de autenticação e middleware não estão incluídas. Antes/depois completos em `perf.json`; a instrumentação foi a mesma nas duas medições. Tempos locais não são benchmark de latência de produção.

## Testes executados

- `php tests/ni2_892_query_metrics_test.php`: **20 PASS, 0 FAIL**. Contagem, fetch mode, erros preservados, ausência de SQL/valores nas métricas, flag/produção, isolamento de cache, DDL/reset, jornada ausente, abertura excepcional, bloqueio dentro de abertura e profissional de outra clínica.
- `./bin/run-tests core`: **19 suítes OK, 0 falhas**, com asserções armadas. Log completo em `core.log`.
- Sintaxe PHP, `bash -n bin/run-tests` e `git diff --check`: sem erros.
- HTTP/browser e jornadas E2E: **BLOCKED/NOT TESTED**, pois a tentativa de servidor nesta sessão foi recusada pelo ambiente (`Operation not permitted` ao abrir porta local).
- MySQL real: **NOT TESTED**; os testes executados usaram SQLite. A nova consulta de metadados MySQL precisa ser validada nesse banco antes de publicar.

## Trabalho restante e gates

| Etapa | Estado |
| --- | --- |
| 1 — Consultas por página | Parcial. Ficha/editor/painel ainda acima das metas; falta o mesmo cenário da auditoria e E2E/HTTP/MySQL. |
| 2 — Rotas por domínio e controllers de API | Não executada. Necessita inventário completo de método+caminho+destino antes/depois e gate da etapa. |
| 3 — Fatiar DashboardController | Não executada. Nenhum fatiamento amplo declarado concluído. |
| 4 — SQL fora dos controllers | Não executada. Extraída apenas a busca de próximo horário como parte da otimização. |
| 5 — catches e estilos das telas tocadas | Não executada como etapa de limpeza. |

Sem migração de banco, alteração de rotas, release versionada, pacote ou deploy. Alterações locais anteriores preservadas. Versões e rollback por etapa continuam pendentes; não foi emitido pacote sem os gates da etapa 1. Não avançar como se as metas/E2E já estivessem aprovados.
