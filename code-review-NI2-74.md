# Code review — NI2-74 · [Alimentos] Conversão de porções e cálculo nutricional

Código revisado: HEAD `b7316cf` (branch `merge/v11-teleconsulta`). Status no Jira: **Feito** (não alterado).
Aceite: cálculo determinístico; unidades documentadas; precisão/arredondamento testados; incompatibilidade de
unidade gera erro explícito.

## Veredito
**NÃO ATENDE o aceite.** O cálculo é determinístico e igual no SQLite e no MySQL, e unidade desconhecida dá erro
claro. Mas unidade incompatível com o alimento **não** dá erro (ml vale 1 g para qualquer alimento; "unidade",
"fatia" e "xícara" valem para arroz), a porção cadastrada do próprio alimento é ignorada, as unidades não estão
documentadas e o arredondamento não tem teste. Há ainda dois erros que a nutricionista e a paciente veem:
quantidade fracionada mostrada como inteira e sódio "0,0 mg" nas receitas.

## Como foi revisado
- Leitura de `HouseholdMeasureConverter`, `NutritionCalculator`, dos usos em `MealPlanService` e `RecipeService`,
  das telas do plano, da impressão, da API do app e da documentação (`docs/clinical/INTEGRIDADE_CLINICA_E_CALCULO_NUTRICIONAL.md`).
- Sonda real (`docs/evidencias/ni2-74/code-review/sonda-code-review.cjs`): `php -S`, login da nutricionista, TACO
  completa; 16 itens adicionados a um plano pelo formulário real, uma receita salva pelo POST real, e chamadas diretas ao
  calculador. Rodada em **SQLite e MySQL 8.0.46** (`sonda-sqlite.json`, `sonda-mysql.json`): mesmos números.

## Achados

### A1 — A tela do plano arredonda a quantidade para inteiro (Alto)
`DashboardController` mostra `number_format($it['amount'], 0)` para itens e substituições.
**Prova:** 0,5 xícara aparece como **"1 xícara"**; 1,5 colher de sopa como **"2 colher de sopa"**; 33,333 g como "33 g".
O cálculo usa o valor certo (0,5 xícara = 75 g, 93 kcal), mas quem lê a tela vê o dobro. A impressão
(`MealPlanPrintService::quantity`) está certa ("0,5"); o app mostra "0.5" (ponto, sem gramas).

### A2 — "unidade" ignora a porção cadastrada no próprio alimento (Alto)
O conversor só olha `food_household_measures` e a tabela genérica; não usa `foods.serving_unit`/`serving_size_g`.
**Prova:** "Pão de queijo da casa" está cadastrado com 1 unidade = 40 g. "1 unidade" vira **100 g genéricos →
335 kcal** em vez de 134 kcal (2,5×), marcado só como "peso estimado". ("1 unidade média" acerta 40 g porque existe
essa medida na tabela do alimento.)

### A3 — Unidade incompatível não gera erro (Alto — aceite)
- **ml = g para qualquer alimento, marcado como exato.** 100 ml de "Arroz, integral, cozido" → 100 g, "exato".
  15 ml de mel → 15 g e 46 kcal (com densidade ~1,4 g/ml seriam ~21 g e ~66 kcal, −30%); 15 ml de óleo → 15 g e
  132,6 kcal (densidade ~0,92: ~122 kcal, +9%).
- **Medidas genéricas valem para qualquer alimento.** 2 "unidade média" de arroz cozido = 200 g; "fatia" = 30 g e
  "xícara" = 150 g para tudo. Só aparece "peso estimado" — sem erro e sem pedir confirmação.
O aceite pede erro explícito. Unidade **desconhecida** já dá erro ("pitada", "colheres de sopa": "Unidade de medida não
reconhecida…"), mas a incompatível passa.

### M1 — Receita mostra "Sódio 0,0 mg" e não avisa peso estimado (Médio)
O catálogo não tem sódio (o CSV da TACO importado não traz a coluna) e `RecipeService` soma `$totalSodium` que nunca
recebe valor. **Prova:** receita salva mostra **"Sódio 0,0 mg"** por porção — informação falsa para quem cuida de
hipertensão. A mesma receita usa 2 "xícara de chá" de arroz e 1 "colher de sopa" de óleo (pesos genéricos) e a
página não diz que o peso é estimado.

### M2 — Nutriente "não analisado" (NA na TACO) entra como zero, sem aviso (Médio)
`nutrient_flags` guarda `fiber_g=NA` (leite condensado, mel), `protein_g=NA;carbohydrate_g=NA` (óleo), mas o
calculador usa `?? 0` e os totais do plano não indicam que estão incompletos.
**Prova:** total de fibra do plano 11,64 g, sem nenhuma marcação, com três alimentos sem fibra analisada.

### M3 — Unidades e arredondamento não documentados (Médio — aceite)
A documentação diz só "peso = quantidade × fator". Não há a tabela de fatores (colher de sopa 15 g, xícara 150 g,
unidade 100 g…), a ordem de prioridade (medida do alimento → tabela), a hipótese ml = g, a regra de arredondamento
(2 casas, meio para cima, por item e depois no total) nem o que é "peso estimado".

### M4 — Precisão/arredondamento sem teste (Médio — aceite)
`clinical_safety_and_precision_test.php` cobre o caso canônico (30 g de aveia; 2 colheres de sopa) e unidade
desconhecida. Não testa limites de arredondamento, ml, incompatibilidade, medida do alimento nem totais.

### B1 — O seletor de unidade do plano não oferece as medidas do alimento (Baixo)
Opções fixas: g, ml, colher de sopa, colher de sobremesa, unidade média, xícara, fatia. "unidade pequena" (cadastrada
para o pão de queijo) só entra pelo campo livre da edição; falta "colher de chá" (existe na receita).

### B2 — MySQL guarda a quantidade com 2 casas, o cálculo usa o valor digitado (Baixo)
33,333 g é gravado como 33,33 no MySQL (33,333 no SQLite), com os nutrientes de 33,333. Um recálculo posterior a partir
do valor gravado pode dar outro número. Arredondar a quantidade antes de calcular resolve.

### B3 — Arredondamento duplo (Baixo)
Cada item é arredondado em 2 casas e o total soma os valores arredondados (3 × 0,005 kcal → 0,03; exato 0,015 → 0,02).
Determinístico e com diferença ≤ 0,005 por item; só precisa estar documentado.

### B4 — Variações de escrita viram erro (Baixo)
"colheres de sopa" e "xicara de chá" (sem um acento) dão "unidade não reconhecida". O erro é explícito (atende o
aceite), mas o campo livre da edição convida a esse tipo de escrita.

## O que está certo
- Uma única regra de cálculo para plano e receita (`NutritionCalculator`); nutrientes por 100 g × gramas/100.
- Mesma entrada → mesmo resultado; SQLite e MySQL dão os mesmos números; total do plano = soma dos itens.
- Arredondamento meio-para-cima correto nos casos de borda de ponto flutuante (1,005 → 1,01; 2,675 → 2,68).
- Quantidade zero, negativa, texto, infinito e unidade desconhecida → erro explícito na tela.
- Medida cadastrada do alimento tem prioridade ("1 unidade média" de pão de queijo = 40 g, não estimado).
- Peso estimado é gravado por item e aparece no plano, no alerta antes de publicar e na impressão; limite de 5 kg.

## Prioridade sugerida
1. A1, A2 e A3 (números errados ou enganosos na prescrição).
2. M1 e M2 (informação nutricional apresentada como completa sem ser).
3. M3 e M4 para fechar o aceite.
4. B1–B4.

## Gates
| Gate | Resultado |
|---|---|
| Cálculo determinístico (SQLite e MySQL) | PASS |
| Unidade desconhecida → erro explícito | PASS |
| Unidade incompatível → erro explícito | **FAIL** (A3) |
| Porção do alimento respeitada | **FAIL** (A2) |
| Quantidade exibida = prescrita | **FAIL** na tela do plano (A1) |
| Unidades e arredondamento documentados | **FAIL** (M3) |
| Precisão/arredondamento testados | **FAIL** (M4) |
| Produção HostGator | NOT TESTED |

## Status das correções (24/10/2026)

Decisão da usuária: medida sem peso cadastrado para o alimento **só é aceita se a nutricionista confirmar**.

| Achado | Status | O que mudou |
|---|---|---|
| A1 quantidade arredondada | **Corrigido** | Plano mostra a quantidade como digitada (`QuantityFormatter`: "0,5 xícara"); receita idem. |
| A2 porção do alimento | **Corrigido** | Nova regra 3 do conversor: `serving_unit`/`serving_size_g` do alimento (pão de queijo: 1 unidade = 40 g, exato). |
| A3 unidade incompatível | **Corrigido** | Peso só da tabela padrão (inclusive ml) exige confirmação; sem ela, erro explícito (`UnitConfirmationRequiredException`) com alimento, peso padrão e medidas exatas. Na tela, `unit-check.js` mostra o peso e torna a caixa "Confirmo o peso estimado" obrigatória (plano: item, edição, substituição; receita: cada ingrediente). Seletor ganhou o grupo "Medidas deste alimento". |
| M1 sódio / peso estimado na receita | **Corrigido** | Sódio "Não disponível no catálogo"; ingrediente e resumo avisam peso estimado. |
| M2 nutrientes NA | **Corrigido** | `unknown_nutrients` no cálculo; plano e receita mostram "Totais/Valores incompletos" com os alimentos. |
| M3 documentação | **Corrigido** | `docs/clinical/CONVERSAO_DE_PORCOES_NI2-74.md` (regras, tabela, precisão, erros); o teste falha se a tabela divergir do código. |
| M4 testes | **Corrigido** | `ni2_74_portion_conversion_test.php` (38), `ni2_74_portion_real_test.cjs` (30, SQLite e MySQL), `ni2_74_unit_check_browser_test.cjs` (16). |
| B1 seletor de unidade | **Corrigido** | + colher de chá e unidade; medidas do alimento entram no seletor. |
| B2 precisão gravada | **Corrigido** | Quantidade arredondada em 2 casas antes de calcular e gravar (`MealPlanService::normalizeAmount`). |
| B3 arredondamento duplo | Documentado | Regra mantida e descrita no documento. |
| B4 plural/acento | **Corrigido** | Unidade comparada sem acento, sem maiúsculas e no singular. |

Planos duplicados, modelos aplicados e receitas duplicadas/reeditadas sem trocar ingredientes herdam a confirmação da
origem (o item continua marcado como estimado). O rascunho automático usa "1 unidade" de banana só se o alimento tiver a
unidade cadastrada; senão 100 g. Nova rota `GET /catalogo/alimentos/{id}/conversao` (só nutricionista ativa; alimento de
outra clínica → 404; coberta pelo teste de rotas da NI2-24 e pelo auditor de acesso cruzado).

### Provas (`docs/evidencias/ni2-74/correcoes/`)
- Antes (código 89bd009 com os testes novos): HTTP MySQL **5 PASS / 13 FAIL**; navegador **0 / 1**; teste de conversão
  interrompido por erro ("Unidades Pequenas" não reconhecida).
- Depois: conversão **38/38**; HTTP **30/30 SQLite** e **30/30 MySQL 8**; navegador **16/16** (axe sem violações,
  390 px sem rolagem); NI2-24 **11/11** (112 ataques, rota nova coberta); NI2-73 43/43, receitas 16/16, seletor 9/9.
- Testes antigos ajustados à regra nova (agora confirmam o peso estimado): `clinical_safety_and_precision_test.php`,
  `meal_plan_and_prescription_lifecycle_test.php`, `ni2_9_code_review_regression_test.php` (o ovo "2 unidade" passa a
  100 g pela porção do alimento, não 200 g), `ni2_310_real_http_test.cjs` (parte HTTP; a captura de tela desse teste usa
  um Chrome com headless antigo e é BLOCKED neste ambiente, igual no código antigo).
