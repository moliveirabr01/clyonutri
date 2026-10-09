# NI2-319 — PX-26: erros da API e estados locais no app

Implementado localmente em /Users/moliveira/Nutri_II em 09/10/2026. Alterações preexistentes preservadas.

## Resultado

ApiErrorBoundary é registrada antes da conexão com banco para rotas /api/. Trata exceções não capturadas, erros PHP e falhas fatais posteriores ao registro. Desativa display_errors e impede retorno de conteúdo parcial ou JSON inválido como sucesso. Falhas internas retornam 5xx JSON com error, code e request_id; X-Request-ID permite correlação. Erros 4xx mantêm seu contrato com identificador adicionado. Respostas bem-sucedidas e downloads mantêm o contrato.

Logs usam canal do servidor e identificador, classe da exceção, nome de arquivo e linha, sem credenciais, corpo da requisição ou dados clínicos. A evolução deixa de expor mensagem SQL; erros inesperados de autorrelato deixam de ser tratados como validação 400.

O cliente diferencia offline, erro do servidor, resposta inválida e autenticação expirada. Valida os formatos de home, diário e evolução. O app carrega recursos independentemente; diário/evolução têm atualização local, indicador de carregamento, alertas acessíveis e última atualização. Falhas mantêm dados já obtidos marcados como desatualizados, sem apresentar falso vazio. 401 encerra o acesso e retorna à entrada.

## Evidências

- 24/24 verificações aprovadas: [resultados](evidence/results.json).
- [Respostas reais 500 JSON](evidence/responses.json) e [logs correlacionados](evidence/server.log).
- [Persistência antes/depois](evidence/persistence.json): leituras com falha preservam registros, hidratação continua gravando.
- 34/34 verificações existentes de autenticação/persistência: [regressão](evidence/auth-regression.log).
- TypeScript e exportação Expo aprovados: [tipagem](evidence/typecheck.log), [build](evidence/web-build.log).
- Capturas inspecionadas: [falha parcial](evidence/diary-partial-390.png), [offline/desatualizado](evidence/offline-stale-390.png), [JSON inválido](evidence/invalid-json-390.png), [evolução desatualizada](evidence/evolution-stale-390.png).

SQL falha por indisponibilidade real de tabelas no banco temporário; servidor usa public/index.php e display_errors=1 para provar sanitização. Cliente TypeScript compilado usa TCP real. Interface React Native validada na exportação Expo web via Chrome a 390px. JSON inválido/schema inválido são injetados no transporte; offline do navegador bloqueia rede. Acessibilidade verificada por roles de alerta, labels de botões e live regions; não foi executado leitor de tela nativo em aparelho.

## Reprodução

1. `npm run typecheck --prefix mobile`
2. `npm run build:api-test --prefix mobile`
3. Em mobile: `EXPO_PUBLIC_API_URL=/ ./node_modules/.bin/expo export --platform web`
4. `node tests/ni2_319_real_http_client_test.cjs`

Requer PHP, Node, Chrome e Playwright (neste ambiente em /tmp/cfit276-browser/node_modules/playwright). Usa SQLite temporário e migrations oficiais. Dados reais não foram usados nem alterados nos testes. Nenhuma migração nova. Alterações locais, sem publicação remota.
