# Conversão de porções e cálculo nutricional (NI2-74)

Código: `src/Domain/Nutrition/HouseholdMeasureConverter.php` e `NutritionCalculator.php`. A mesma regra vale para o
plano alimentar (itens e substituições), as receitas e a tela que confere a unidade
(`GET /catalogo/alimentos/{id}/conversao`). Teste que mantém este documento igual ao código:
`tests/ni2_74_portion_conversion_test.php`.

## 1. Da quantidade às gramas

A primeira regra que se aplica vale:

| Ordem | Situação | Peso usado | Precisa confirmar? |
|---|---|---|---|
| 1 | Unidade de massa (g, mg, kg) | exato | não |
| 2 | Medida cadastrada para o alimento (`food_household_measures`, ex.: arroz — 1 colher de sopa = 25 g) | exato | não |
| 3 | Porção do próprio alimento (`foods.serving_unit` / `serving_size_g`, ex.: pão de queijo — 1 unidade = 40 g), a mesma mostrada na Base de Alimentos | exato | não |
| 4 | Unidade da tabela padrão abaixo, sem peso cadastrado para o alimento | **estimado** (igual para qualquer alimento) | **sim** |
| 5 | Unidade desconhecida | — | erro: "Unidade «…» não reconhecida…" |

Na regra 4, sem a confirmação ("Confirmo o peso estimado") o sistema **não salva** e responde com erro explícito,
dizendo o peso que seria usado e as medidas exatas do alimento. Com a confirmação, o item é salvo com
`weight_is_estimated = 1` e aparece como "peso estimado" no plano, no aviso antes de publicar, na impressão e na receita.

Planos duplicados, modelos aplicados e receitas duplicadas ou reeditadas sem mudar ingredientes mantêm a
confirmação dada na origem (o peso continua marcado como estimado).

### Tabela padrão (gramas por 1 unidade)

<!-- tabela-unidades:inicio -->
| Unidade | Gramas | Tipo | Também aceita |
|---|---|---|---|
| g | 1 | massa (exata) | grama, gr |
| mg | 0,001 | massa (exata) | miligrama |
| kg | 1.000 | massa (exata) | quilo, quilograma |
| ml | 1 | volume (estimada) | mililitro |
| l | 1.000 | volume (estimada) | litro, lt |
| colher de sopa | 15 | utensílio (estimada) | colher sopa |
| colher de sobremesa | 10 | utensílio (estimada) | colher sobremesa |
| colher de chá | 5 | utensílio (estimada) | colher cha |
| colher de café | 2 | utensílio (estimada) | colher cafe |
| xícara (de chá) | 150 | utensílio (estimada) | xicara de cha, xicara cha |
| copo (americano) | 200 | utensílio (estimada) | copo americano |
| concha (média) | 130 | utensílio (estimada) | concha media |
| fatia (média) | 30 | contagem (estimada) | fatia media |
| unidade (média) | 100 | contagem (estimada) | unidade media, un, und |
| porção | 100 | contagem (estimada) | — |
<!-- tabela-unidades:fim -->

- Volume supõe a densidade da água (1 ml = 1 g). Óleo (~0,92 g/ml), mel (~1,4 g/ml) e outros líquidos pesam
  diferente: por isso ml também exige confirmação quando o alimento não tem a medida cadastrada.
- Unidades são comparadas sem acento, sem maiúsculas e no singular: "Colheres de Sopa", "xicara de chá" e
  "XÍCARA" são a mesma coisa que "colher de sopa" / "xícara".

## 2. Das gramas aos nutrientes

`nutriente = valor por 100 g (TACO ou cadastro da clínica) × gramas ÷ 100`, para energia (kcal), proteína,
carboidratos, lipídios e fibra.

## 3. Precisão e arredondamento

| Etapa | Regra |
|---|---|
| Quantidade digitada | arredondada para 2 casas antes de calcular e gravar (é o que o MySQL guarda: DECIMAL(…,2)) |
| Gramas | calculadas com 4 casas; gravadas com 2 |
| Nutrientes do item | 2 casas, meio para cima (`round` do PHP: 1,005 → 1,01; 2,675 → 2,68) |
| Total da refeição / do plano | soma dos itens já arredondados, arredondada em 2 casas (diferença máxima de 0,005 por item em relação ao cálculo sem arredondar) |
| Receita por porção | total da receita ÷ rendimento, 2 casas |
| Tela | quantidade como digitada ("0,5 xícara"); kcal sem casas; macronutrientes com 1 casa |

O cálculo é determinístico: a mesma entrada dá o mesmo resultado, no SQLite e no MySQL.

## 4. Nutrientes sem análise

Na TACO, "NA" (não analisado), "*" e células em branco não têm valor. Esses nutrientes entram como **0** no cálculo
e o total avisa ("Totais incompletos: … fibra para Leite, condensado…"). "Tr" (traços) é tratado como zero de fato.
O catálogo não traz sódio: a receita mostra "Não disponível no catálogo" em vez de 0 mg.

## 5. Erros

| Entrada | Resultado |
|---|---|
| Quantidade zero, negativa, texto ou infinita | "A quantidade prescrita deve ser maior que zero" |
| Unidade vazia | "Informe a unidade da quantidade" |
| Unidade desconhecida ("pitada") | "Unidade «pitada» não reconhecida. Use …" |
| Medida sem peso cadastrado para o alimento, sem confirmação | "Para «Arroz, integral, cozido», a unidade «xícara» não tem peso cadastrado. O cálculo usaria o peso padrão 1 xícara (de chá) = 150 g… marque «Confirmo o peso estimado»." |
| Item acima de 5 kg | "Quantidade acima do limite de 5 kg por alimento" |
