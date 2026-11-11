# ClyoNutri v44 · Busca da Tabela de Alimentos com nomes regionais, acentos e a mesma ordem do editor de plano (29/10/2026)

**SQL novo na v44: NÃO.** A v44 é só código. Ela usa as colunas e tabelas que já estão no banco desde a v42 e a v43 (`foods.search_text`, `foods.is_current` e `food_favorites`, migrações 62 e 63). Não há migração nova e nenhum dado muda.

A v44 é a **v43** (sha256 `6c1803408659fd0e09d8c020a9ae9f0d3ef1d6f218e8123e67de1be0b790cc43`) com as mudanças listadas em `COMPARACAO-V43-V44.txt`. Nada da v43 foi removido. O pacote não traz `.env` nem chaves.

## O problema

Na Tabela de Alimentos (`/alimentos`), buscar "aipim" trazia só "Bolo, pronto, aipim" (TACO 15) e não trazia a Mandioca. No editor de plano a mesma busca já funcionava.

**Causa.** As duas telas usam o mesmo índice de busca (`FoodSearchIndex`), mas a Tabela de Alimentos chamava o índice sem ligar os sinônimos regionais. Também não usava os favoritos da clínica nem o desempate por uso, e listava as versões antigas de alimentos (`is_current = 0`). Por isso "aipim" só achava nomes que tinham a palavra "aipim".

## O que muda

1. **Tabela de Alimentos (`/alimentos`).** A busca segue as regras do editor de plano: nomes regionais (aipim, macaxeira e mandioca; jerimum e abóbora; mexerica, tangerina e bergamota), sem diferença de maiúscula, acento ou plural, palavras em qualquer ordem e a mesma ordem de relevância. O alimento base vem antes dos preparos que só têm o termo no nome: "aipim" mostra Mandioca cozida, Mandioca crua e as outras mandiocas, e o Bolo de aipim vem depois. Favoritos da clínica e alimentos já usados em planos da clínica desempatam, como no editor.
2. **Aviso de sinônimo.** Quando o alimento aparece pelo nome regional e não pelo termo digitado, a linha mostra "Por sinônimo: aipim = mandioca". Se o nome já tem a palavra buscada, não há aviso.
3. **Filtros mantidos.** Grupo TACO, fonte (TACO, Minha clínica), quantidade por página e paginação funcionam como antes, agora sobre a busca nova. A busca pelo nome do grupo continua valendo.
4. **Isolamento por clínica.** A lista mostra a base geral e os cadastros da própria clínica. A regra ficou mais rígida: um alimento ligado a outra clínica não aparece, mesmo que não esteja marcado como próprio. Versões antigas de alimentos (`is_current = 0`) saem da lista, como já acontecia no editor.
5. **Medidas caseiras da clínica (`/medidas-caseiras`).** A busca de alimento para cadastrar medida usava só parte do nome e ordem alfabética. Agora usa o mesmo índice (sinônimos, acentos, ordem) e mostra "por sinônimo" quando for o caso.
6. **Editor de plano e seletor de alimentos das receitas.** Já usavam o índice. Agora também mostram o aviso "Por sinônimo".

## Outras buscas conferidas

- **Ingredientes de receita:** usam o mesmo seletor do editor de plano. Já estavam alinhados; ganham só o aviso de sinônimo.
- **Lista de receitas:** busca título, resumo, modo de preparo e ingredientes juntos. Não é busca de alimento e ficou como está.
- **Lista de medidas caseiras da clínica:** o filtro procura no nome do alimento e no nome da medida ao mesmo tempo. É uma busca mista e ficou como está.
- **Diário alimentar e app do paciente (API):** não têm busca na base de alimentos. Nada a mudar.
- **Métricas de buscas sem resultado:** continuam sendo gravadas só a partir do editor de plano. A Tabela de Alimentos não grava.

## Como subir

1. **Backup** dos arquivos.
2. Envie `clyonutri-v44.zip` para `/home3/maic6824/clyonutri.com`, **Extract** (sobrescrever), apague o zip do servidor, limpe o cache e abra com Ctrl+F5. Não mexa em `.env` nem em `storage/`.
3. Não há SQL para rodar. A v44 precisa das migrações 62 e 63 já aplicadas, como está em produção.

## Conferência depois de subir

- `https://clyonutri.com/up` responde `{"status":"ok"}`.
- `/alimentos?q=aipim`: a primeira linha é "Mandioca, cozida" com "Por sinônimo: aipim = mandioca"; "Bolo, pronto, aipim" aparece depois das mandiocas, sem aviso.
- `/alimentos?q=jerimum` traz as abóboras; `/alimentos?q=mexerica` traz Mexerica e Tangerina, com aviso só na Tangerina; `/alimentos?q=macaxeira` traz Mandioca.
- Com "aipim", escolha o grupo "Cereais e derivados": fica só o Bolo. Troque a fonte e a quantidade por página e confira a paginação.

## Como voltar

Basta subir de novo o `clyonutri-v43.zip`. Não há mudança de banco para desfazer.

## Validação e limites

- Testes novos (não vão no zip): `v44_alimentos_busca_units_test.php` (10) e `v44_alimentos_busca_sinonimos_http_test.php` (33), todos passando. Suíte completa sem falha nova em relação à v43.
- Teste de fumaça em modo produção em MySQL 5.7.44 com o catálogo TACO e dados TESTE: `docs/evidencias/v44-busca-alimentos/SMOKE-V44-mysql57.txt`.
- **Não rodei nada em produção.** Sem homologação em produção.
