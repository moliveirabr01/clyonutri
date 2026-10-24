# Code review — NI2-73 · [Alimentos] Busca eficiente no catálogo

Commit revisado: `3b3cdf9` (branch `merge/v11-teleconsulta`). Status no Jira: **Feito** (não alterado).
Aceite: nome/termos; autocomplete/paginação; porção/origem visíveis; performance testada com base representativa.

## Veredito
**APROVADO COM RESSALVAS.** A busca do seletor do plano e da Base de Alimentos funciona, está isolada por clínica e
tem desempenho medido. Mas o aceite "encontrar alimento rapidamente" **não vale para receitas** (M1), plurais
comuns em português não acham nada (M2) e o primeiro pedido depois do deploy/importação pode ser lento (M3).

## Como foi revisado
- Leitura do diff completo (FoodSearchIndex, FoodSearchController, FoodCatalogService, food-picker.js, migration 50, SQL manual 50).
- Varredura de todos os pontos do sistema que listam ou buscam `foods`.
- Sonda real por HTTP (`docs/evidencias/ni2-73/code-review/sonda-code-review.cjs`): `php -S`, login real da
  nutricionista, TACO completa do CSV, em **SQLite e MySQL 8.0.46**. Resultados em `sonda-sqlite.json` e `sonda-mysql.json`.

## Achados

### M1 — Receitas: só os 300 primeiros alimentos em ordem alfabética podem virar ingrediente (Médio/Alto)
`RecipeService::listFoodsForClinic()` usa `ORDER BY name LIMIT 300` e o formulário (`/receitas/nova` e
`/receitas/{id}/editar`) é um `<select>` sem busca. A clínica enxerga 595 alimentos; a lista para em
"Frango, coxa, com pele, crua". **Prova (SQLite e MySQL):** "Tomate, com semente, cru", "Uva, Itália, crua",
"Ovo, de galinha, inteiro, cozido/10minutos" e o alimento da própria clínica "Pão de queijo da casa" não aparecem.
O problema é anterior à NI2-73, mas a história é "encontrar alimento" e a entrega cobriu só plano e Base.
**Correção sugerida:** usar o mesmo seletor (`food-picker.js` + `/catalogo/alimentos/busca`) nas linhas de ingrediente;
validar no servidor que o `food_id` é global ou da clínica.

### M2 — Plurais em -ões / -ães / -ãos não acham nada (Médio)
`FoodSearchIndex::terms()` só tira o "s" final. "feijões" vira "feijoe", "pães" vira "pae".
**Prova (os dois bancos):** `feijões`, `pães`, `limões`, `melões` → **0 resultados**; `ovos` e `maçãs` funcionam.
**Correção sugerida:** regras de singular para `oes→ao`, `aes→ao`/`ae`, `aos→ao` (ex.: feijões→feijao, pães→pao,
limões→limao) antes do corte do "s"; com teste para cada uma.

### M3 — Preencher `search_text` dentro do pedido de busca, sem transação (Médio)
O SQL manual 50 deixa `search_text` nulo e o app preenche na primeira busca (`FoodSearchIndex::sync`), um UPDATE
com autocommit por linha, até 20.000 por pedido. **Prova:** 5.000 linhas novas (como numa importação manual) →
primeira busca **5.083 ms no MySQL** (678 ms no SQLite); a seguinte, 15 ms. No HostGator, o primeiro pedido
depois do deploy indexa ~600 alimentos; pedidos simultâneos repetem o mesmo trabalho.
**Correção sugerida:** `sync` em transação (e lotes); ou preencher no próprio SQL manual 50 para as linhas
existentes; manter o `sync` só como rede de segurança.

### B1 — Ordem diferente entre MySQL e SQLite (Baixo)
O desempate usa `LENGTH(name)`: no MySQL conta **bytes**, no SQLite **caracteres**. Nomes com acento ficam "mais
longos" no MySQL. **Prova:** busca `pao` — "Pão, glúten, forma" é o 4º no SQLite e o 6º no MySQL; busca `queijo`
troca "Pão, de queijo, cru" e "Soja, queijo (tofu)". **Correção:** `CHAR_LENGTH` no MySQL (`LENGTH` no SQLite).

### B2 — O índice em `search_text` não acelera a busca (Baixo / informativo)
`LIKE '%termo%'` não usa índice; a busca é uma varredura de `foods`. Com 20.909 alimentos o p95 ficou em
10–11 ms, então atende ao aceite hoje; o índice serve ao `sync` (`IS NULL`). Vale registrar que o custo cresce
linearmente com o catálogo (se passar de ~200 mil linhas, considerar FULLTEXT ou tabela de termos).

### B3 — Plano sem JavaScript: só 250 alimentos (Baixo)
O `<select>` do plano vem do servidor com `LIMIT 250`; o seletor substitui a lista ao carregar, então só afeta
quem estiver sem JS.

### Observação fora do escopo
"Leite, de vaca, integral" (líquido) e "…desnatado, UHT" não estão no catálogo: na TACO 4ª ed. estão sem
energia e a importação os pula (`import=0`). Quem buscar "leite integral" acha só o pó. A decisão é da importação,
não da busca, mas é o tipo de coisa que a nutricionista vai estranhar.

## O que está certo
- Isolamento: contagem e listagem com `(clinic_id IS NULL OR clinic_id = :cid)`; alimento da clínica 2 não aparece.
- Parâmetros sempre ligados; curingas `%`, `_` e `!` escapados; nomes de parâmetro únicos (MySQL nativo).
- Validação 422 (80 caracteres, página 1–100), 403 sem vínculo ativo, 503 sem detalhe interno.
- Termos em qualquer ordem, sem acento/pontuação; ordem de relevância documentada e determinística (`id` no fim).
- Porção/medidas caseiras sem inventar valor ("Sem medida caseira cadastrada (valores por 100 g)"); origem TACO/clínica.
- "Mostrando 25 de N", "carregar mais", debounce de 250 ms e cancelamento de buscas antigas.
- Base de Alimentos deixou de dar 500 no SQLite; testes reais 32/32 nos dois bancos e 9/9 no navegador.

## Prioridade
1. M1 (receitas) e M2 (plurais): afetam o uso diário.
2. M3 antes do deploy no HostGator (ou aceitar a primeira busca lenta uma vez).
3. B1, B2, B3 quando houver oportunidade.

## Gates
| Gate | Resultado |
|---|---|
| Busca no plano e na Base (SQLite e MySQL) | PASS (teste 32/32 e sonda) |
| Busca em receitas | FAIL (M1) |
| Plurais irregulares | FAIL (M2) |
| Primeira busca após importação, MySQL | 5,1 s com 5.000 linhas (M3) |
| Mesma ordem nos dois bancos | FAIL em empates (B1) |
| Produção HostGator | NOT TESTED |

## Status das correções (23/10/2026)

| Achado | Status | O que mudou |
|---|---|---|
| M1 receitas | **Corrigido** | Cada ingrediente usa a busca do catálogo (`food-picker.js` + `/catalogo/alimentos/busca`); o formulário não embute mais os 300 alimentos. O servidor continua recusando `food_id` de outra clínica (F02). `food-picker.js` ganhou `ClyoFoodPicker.init()` para linhas criadas depois. |
| M2 plurais | **Corrigido** | `FoodSearchIndex::singular()`: -ões/-ães→-ão, -ais/-eis/-ois→-l, -ns→-m, -res/-zes/-ses→sem "es", -s. |
| M3 primeira busca | **Corrigido** | `sync()` grava cada lote de 500 numa transação. MySQL, 5.000 linhas novas: 6.590 ms → 1.169 ms. |
| B1 ordem entre bancos | **Corrigido** | `CHAR_LENGTH` no MySQL, `LENGTH` no SQLite. |
| B2 índice | Mantido | Informativo; p95 de 12–13 ms com 20.909 alimentos. |
| B3 plano sem JS | Mantido | Só afeta quem está sem JavaScript. |

Mudança de comportamento: o formulário de receita depende de JavaScript para escolher ingredientes (aviso em `<noscript>`).
A recepção já não abria `/receitas/nova`; o aviso "Seu perfil não tem acesso ao catálogo" (403) no seletor foi
acrescentado, mas nenhuma tela com recepção chega a ele hoje (NOT TESTED em uso real).

### Provas (`docs/evidencias/ni2-73/correcoes-code-review/`)
- Antes (código do commit 3b3cdf9, testes novos): HTTP MySQL **36 PASS / 7 FAIL**; receitas no navegador **0 PASS / 3 FAIL**.
- Depois: HTTP **43/43 SQLite** e **43/43 MySQL 8**; receitas no navegador **16/16** (salvar com Tomate, Uva e
  alimento da clínica, quantidade/medida, POST com alimento de outra clínica recusado, XSS com nome hostil, 390 px sem
  rolagem, axe sem violações); seletor do plano **9/9**.
- Testes antigos ajustados ao novo formulário: `auditoria_2026_10_14_xss_food_name_js_test.php` e
  `auditoria_2026_10_14_f02_recipe_food_scope_test.php` (ambos OK).
- Regressão: 77 suítes PASS; falhas iguais à linha de base; `ni2_292` e `px_13` dependem do horário e falham também
  no código antigo depois das 21h.
