# NI2-73: busca eficiente no catálogo de alimentos

Data: 23/10/2026. Código em `~/Nutri_II`, branch `merge/v11-teleconsulta`.

## O que estava errado (medido no código anterior, `antes.log`: 4 PASS e 15 FAIL antes de parar)

- **Busca por termos não funcionava.** Os nomes da TACO têm vírgulas ("Arroz, integral, cozido"), e a busca procurava o texto inteiro. Por isso "arroz integral", "feijão carioca", "banana prata" e "frango peito" não achavam nada, e "pão francês" achava só "Torrada, pão francês".
- **Ordem alfabética, sem relevância.** "maçã" trazia "Macarrão" antes da maçã, e "ovos" mostrava "Macarrão… com ovos" primeiro.
- **A Base de Alimentos (`/alimentos`) quebrava com qualquer busca no SQLite (erro 500).** A causa era a cadeia de cerca de 40 `REPLACE` aninhados no SQL, que estourava a pilha do analisador.
- **O seletor do plano não mostrava a porção** nem quantos resultados havia no total.
- **Contraste insuficiente no selo "Catálogo TACO":** 4,2:1, abaixo do mínimo de 4,5:1. Uma regra de CSS mais específica pintava o selo de cinza claro.
- **Toda abertura da Base de Alimentos procurava 12 alimentos de referência pelo nome sem índice:** 83 ms no MySQL com 20 mil alimentos.

## O que mudou

- **`foods.search_text`** (migração 50): o nome sem acento, em minúsculas, com a pontuação virando espaço. O app preenche sozinho as linhas sem esse texto, inclusive as do SQL manual da TACO (`FoodSearchIndex::sync`).
- **Busca por termos** (`FoodSearchIndex`):
  - até 6 termos, todos obrigatórios, em qualquer ordem;
  - plural simples vira singular ("ovos" → "ovo");
  - `%`, `_` e `!` não funcionam como curinga.
- **Ordem com regra fixa e visível**, sem pontuação escondida:
  1. nome que começa com o termo inteiro como palavra;
  2. nome que começa com o termo;
  3. primeiro termo no começo do nome e os demais no começo de palavras;
  4. todos os termos no começo de palavras;
  5. os demais.

  Dentro de cada grupo vem primeiro o nome mais curto, depois a ordem alfabética.
- **Seletor do plano** (`/catalogo/alimentos/busca`):
  - devolve `total`, `terms` e `portion`;
  - a tela mostra a porção de referência e as medidas caseiras cadastradas ou, quando não há, "Sem medida caseira cadastrada (valores por 100 g)";
  - a contagem aparece como "Mostrando 25 de N alimentos";
  - sem resultado, sugere usar menos palavras.
- **Base de Alimentos:** usa a mesma busca por termos, mais a categoria. Ganhou um índice em `foods.name`.
- **Origem:** continua visível ("Catálogo TACO" e nº TACO, ou "Personalizado da sua clínica" e nº do cadastro).

## Execução

| Prova | Resultado |
|---|---|
| `tests/ni2_73_food_search_real_test.cjs`, SQLite, com 20.909 alimentos (TACO completa, 400 clínicas × 50, mais 300 da própria clínica) | 32/32. Seletor: p50 7,6 ms, p95 11,2 ms. `/alimentos`: p95 19,9 ms |
| Mesmo teste, MySQL 8.0.46 | 32/32. Seletor: p50 7,7 ms, p95 10 ms. `/alimentos`: p95 52 ms |
| Mesmo teste na árvore anterior (HEAD d73ce0b) | 4 PASS e 15 FAIL antes de parar (`antes.log`) |
| `tests/ni2_73_picker_browser_test.cjs`: seletor no plano, em navegador real, com CSP ativa e axe | 9/9 (`seletor-resultados.json`, `seletor-porcao-1280.png`) |
| SQL manual 50, com chaves INT e BIGINT, rodado duas vezes | OK (`sql-manual-mysql8.txt`) |
| Checagem do schema no MySQL 8 (instalação nova igual ao upgrade) | ok (`schema-gate-mysql8.json`) |
| Regressão | 97 suítes legadas: nenhuma falha nova causada pela mudança (food_catalog_pagination_test passou a passar); HTTP/navegador NI2-89, 91, 96, 135, 137, 138, 298, 412, 433, 437 todos PASS (`regressao-suites.txt`) |

Os tempos são medidos de ponta a ponta pelo HTTP no `php -S` local, por isso servem só como referência relativa. O limite usado no teste é p95 ≤ 300 ms no seletor e ≤ 600 ms na Base de Alimentos.

## Limites

- **Desempenho na HostGator (MySQL 5.7 ou 8 compartilhado):** NOT TESTED. A medição foi local.
- **Sinônimos:** a busca não entende sinônimos ("aipim" e "mandioca" são termos diferentes). Isso não estava no aceite.
- **Medidas caseiras da TACO:** a TACO não traz medidas caseiras. Só os alimentos de referência e os da clínica têm porção cadastrada; os demais aparecem como "valores por 100 g".
- **v20:** não foi alterado.
